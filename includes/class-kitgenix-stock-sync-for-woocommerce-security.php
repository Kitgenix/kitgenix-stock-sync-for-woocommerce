<?php
defined('ABSPATH') || exit;

final class Kitgenix_Stock_Sync_For_WooCommerce_Security {

	private Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings;

	public function __construct(Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings) {
		$this->settings = $settings;
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

	public function verify_request(\WP_REST_Request $request, string $expected_secret): bool|\WP_Error {
		$store_id = (string) $request->get_header('x_kitgenix_store_id');
		$ts       = (string) $request->get_header('x_kitgenix_timestamp');
		$nonce    = (string) $request->get_header('x_kitgenix_nonce');
		$sig      = (string) $request->get_header('x_kitgenix_signature');

		if ($store_id === '' || $ts === '' || $nonce === '' || $sig === '') {
			return new \WP_Error('kss_auth_missing', 'Missing auth headers', ['status' => 401]);
		}

		if (!ctype_digit($ts)) {
			return new \WP_Error('kss_auth_bad_ts', 'Bad timestamp', ['status' => 401]);
		}

		$ts_int = (int) $ts;
		if (abs(time() - $ts_int) > 300) {
			return new \WP_Error('kss_auth_skew', 'Timestamp skew too large', ['status' => 401]);
		}

		$nonce_key = 'kitgenix_stock_sync_for_woocommerce_kss_nonce_' . md5($store_id . '|' . $nonce);
		if (get_transient($nonce_key)) {
			return new \WP_Error('kss_auth_replay', 'Replay detected', ['status' => 401]);
		}
		set_transient($nonce_key, 1, 10 * MINUTE_IN_SECONDS);

		$body = $request->get_body();
		$base = $ts . "\n" . $nonce . "\n" . $body;
		$calc = hash_hmac('sha256', $base, $expected_secret);

		if (!hash_equals($calc, $sig)) {
			return new \WP_Error('kss_auth_sig', 'Invalid signature', ['status' => 401]);
		}

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

	public function is_sender_allowed(string $sender_store_id): bool {
		return $sender_store_id !== '' && $this->secret_for_sender($sender_store_id) !== '';
	}
}
