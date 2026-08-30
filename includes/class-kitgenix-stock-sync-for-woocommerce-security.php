<?php
defined('ABSPATH') || exit;

final class Kitgenix_Stock_Sync_For_WooCommerce_Security {

	private Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings;

	/** Reject request bodies larger than this (bytes). Stock event payloads are small JSON. */
	public const MAX_BODY_BYTES = 2 * 1024 * 1024; // 2MB

	/** Auth-failure throttle: how many failures within the window trigger a lockout. */
	private const THROTTLE_MAX_FAILURES = 20;
	private const THROTTLE_WINDOW = 600; // 10 minutes
	private const THROTTLE_LOCKOUT = 900; // 15 minutes

	public function __construct(Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings) {
		$this->settings = $settings;
	}

	private function throttle_key(string $identity): string {
		return 'kitgenix_stock_sync_for_woocommerce_kss_authfail_' . md5($identity);
	}

	private function lockout_key(string $identity): string {
		return 'kitgenix_stock_sync_for_woocommerce_kss_lockout_' . md5($identity);
	}

	/**
	 * True if this sender identity is currently locked out due to repeated
	 * authentication failures. Identity is store_id (falls back to remote IP
	 * when store_id is empty/unknown) so an attacker probing random store IDs
	 * from one address is still throttled.
	 */
	public function is_locked_out(string $identity): bool {
		return (bool) get_transient($this->lockout_key($identity));
	}

	/** Record one authentication failure for this identity; lock out if the threshold is hit. */
	public function record_auth_failure(string $identity): void {
		$key = $this->throttle_key($identity);
		$count = (int) get_transient($key);
		$count++;
		set_transient($key, $count, self::THROTTLE_WINDOW);

		if ($count >= self::THROTTLE_MAX_FAILURES) {
			set_transient($this->lockout_key($identity), 1, self::THROTTLE_LOCKOUT);
			$this->settings->add_event_log('error', 'Sender temporarily locked out after repeated authentication failures.', ['identity' => $identity, 'failures' => $count], 'kss_rate_limited');
		}
	}

	/** Clear the failure counter for this identity after a successful auth. */
	public function clear_auth_failures(string $identity): void {
		delete_transient($this->throttle_key($identity));
	}

	/**
	 * Best-effort caller identity for throttling: the remote IP as WordPress
	 * sees it. Not used for authentication decisions, only rate limiting.
	 */
	public function remote_identity(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only, used only as a throttle bucket key (hashed before storage), never echoed or used in a query.
		$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return $ip !== '' ? $ip : 'unknown';
	}

	/** Reject a request body that is larger than we ever expect a stock event to be. */
	public function body_too_large(string $body): bool {
		return strlen($body) > self::MAX_BODY_BYTES;
	}

	/**
	 * Require https:// (or a local dev override) for a Master/Child URL before
	 * it is saved. Prevents credentials and stock data travelling in plaintext,
	 * and blocks non-http(s) schemes (file://, gopher://, etc.) outright.
	 */
	public static function is_acceptable_remote_url(string $url): bool {
		$url = trim($url);
		if ($url === '') return true; // empty is allowed (not yet configured); required-ness is checked elsewhere

		$parts = wp_parse_url($url);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			return false;
		}

		$scheme = strtolower((string) $parts['scheme']);
		if ($scheme === 'https') return true;

		// Allow http:// only for loopback/local dev hosts, and only when explicitly opted in.
		if ($scheme === 'http') {
			$host = strtolower((string) $parts['host']);
			$is_local_host = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.local') || str_ends_with($host, '.test');
			$allow_http = (bool) apply_filters('kitgenix_stock_sync_for_woocommerce_allow_insecure_url', false);
			return $is_local_host && $allow_http;
		}

		return false;
	}

	public function sign_headers(string $secret, string $store_id, string $body): array {
		$ts    = (string) time();
		$nonce = wp_generate_password(16, false, false);
		$base  = $ts . "\n" . $nonce . "\n" . $body;
		$sig   = hash_hmac('sha256', $base, $secret);

		return [
			'X-Kitgenix-Store-Id'  => $store_id,
			'X-Kitgenix-Timestamp' => $ts,
			'X-Kitgenix-Nonce'     => $nonce,
			'X-Kitgenix-Signature' => $sig,
			'Content-Type'         => 'application/json',
		];
	}

	/**
	 * Verify a signed inbound request against every currently-valid secret for
	 * the sender (current + a not-yet-expired rotated-out previous secret, so
	 * rotation can overlap safely – see Settings::rotate_*_secret()).
	 *
	 * All rejection reasons are logged in full detail internally, but the
	 * response returned to the caller is intentionally generic (single code/
	 * message) so an unauthenticated caller cannot distinguish "unknown store
	 * ID" from "known store, wrong secret" from "bad signature" – that
	 * distinction would let an attacker enumerate configured store pairings.
	 */
	public function verify_request(\WP_REST_Request $request, array $candidate_secrets): bool|\WP_Error {
		$store_id = (string) $request->get_header('x_kitgenix_store_id');
		$ts       = (string) $request->get_header('x_kitgenix_timestamp');
		$nonce    = (string) $request->get_header('x_kitgenix_nonce');
		$sig      = (string) $request->get_header('x_kitgenix_signature');

		$generic = static fn() => new \WP_Error('kss_auth_failed', 'Authentication failed', ['status' => 401]);

		if ($store_id === '' || $ts === '' || $nonce === '' || $sig === '') {
			$this->settings->add_event_log('error', 'Rejected inbound request: missing auth headers.', ['store_id' => $store_id], 'kss_auth_missing');
			return $generic();
		}

		if (!ctype_digit($ts)) {
			$this->settings->add_event_log('error', 'Rejected inbound request: malformed timestamp.', ['store_id' => $store_id], 'kss_auth_bad_ts');
			return $generic();
		}

		$ts_int = (int) $ts;
		if (abs(time() - $ts_int) > 300) {
			// Clock drift, not necessarily an attack – see Settings::is_recoverable_code().
			$this->settings->add_event_log('warning', 'Rejected inbound request: timestamp outside allowed window.', ['store_id' => $store_id], 'kss_auth_skew');
			return $generic();
		}

		$nonce_key = 'kitgenix_stock_sync_for_woocommerce_kss_nonce_' . md5($store_id . '|' . $nonce);
		if (get_transient($nonce_key)) {
			// Usually a duplicate webhook delivery, not necessarily an attack – see Settings::is_recoverable_code().
			$this->settings->add_event_log('warning', 'Rejected inbound request: nonce already used (possible replay).', ['store_id' => $store_id], 'kss_auth_replay');
			return $generic();
		}

		$candidate_secrets = array_values(array_filter(array_map('strval', $candidate_secrets), static fn($s) => $s !== ''));
		if (empty($candidate_secrets)) {
			$this->settings->add_event_log('error', 'Rejected inbound request: no secret configured for sender.', ['store_id' => $store_id], 'kss_auth_secret');
			return $generic();
		}

		$body = $request->get_body();
		$base = $ts . "\n" . $nonce . "\n" . $body;

		$verified = false;
		foreach ($candidate_secrets as $secret) {
			$calc = hash_hmac('sha256', $base, $secret);
			if (hash_equals($calc, $sig)) {
				$verified = true;
				break;
			}
		}

		if (!$verified) {
			$this->settings->add_event_log('error', 'Rejected inbound request: signature verification failed.', ['store_id' => $store_id], 'kss_auth_sig');
			return $generic();
		}

		// Nonce is only consumed once we know the request is genuinely authenticated,
		// so a replayed-but-invalid signature doesn't burn a legitimate nonce slot.
		set_transient($nonce_key, 1, 10 * MINUTE_IN_SECONDS);

		return true;
	}

	public function secret_for_sender(string $sender_store_id): string {
		$opt = $this->settings->get_all();

		if ($this->settings->is_child()) {
			$mid = (string) ($opt['master']['store_id'] ?? '');
			if ($sender_store_id !== $mid) return '';
			return (string) ($opt['master']['secret'] ?? '');
		}

		$children = is_array($opt['children'] ?? null) ? $opt['children'] : [];
		foreach ($children as $child) {
			if (is_array($child) && ($child['id'] ?? '') === $sender_store_id) {
				return (string) ($child['secret'] ?? '');
			}
		}
		return '';
	}

	/**
	 * Every secret currently valid for this sender: the active secret plus a
	 * rotated-out previous secret if it hasn't expired yet.
	 *
	 * @return string[]
	 */
	public function candidate_secrets_for_sender(string $sender_store_id): array {
		$opt = $this->settings->get_all();
		$config = null;

		if ($this->settings->is_child()) {
			$mid = (string) ($opt['master']['store_id'] ?? '');
			if ($sender_store_id === $mid) {
				$config = is_array($opt['master'] ?? null) ? $opt['master'] : [];
			}
		} else {
			$children = is_array($opt['children'] ?? null) ? $opt['children'] : [];
			foreach ($children as $child) {
				if (is_array($child) && ($child['id'] ?? '') === $sender_store_id) {
					$config = $child;
					break;
				}
			}
		}

		if (!is_array($config)) return [];

		$secrets = [(string) ($config['secret'] ?? '')];
		$prev = Kitgenix_Stock_Sync_For_WooCommerce_Settings::previous_secret_if_valid($config);
		if ($prev !== '') $secrets[] = $prev;

		return array_values(array_filter($secrets, static fn($s) => $s !== ''));
	}

	public function is_sender_allowed(string $sender_store_id): bool {
		return $sender_store_id !== '' && $this->secret_for_sender($sender_store_id) !== '';
	}
}
