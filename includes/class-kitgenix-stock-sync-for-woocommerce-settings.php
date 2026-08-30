<?php
defined('ABSPATH') || exit;

final class Kitgenix_Stock_Sync_For_WooCommerce_Settings {

	public const OPTION_KEY = 'kitgenix_stock_sync_for_woocommerce_settings';

	/** Current option schema version. Bump when ensure_defaults() adds new keys. */
	public const SCHEMA_VERSION = 2;

	/** Default overlap window for a rotated secret before the old one stops working. */
	private const SECRET_ROTATION_OVERLAP_DEFAULT = 86400; // 24 hours (HOUR_IN_SECONDS not yet available pre-init in all contexts)

	public static function default_health(): array {
		return [
			'last_inbound'          => 0,
			'last_outbound_success' => 0,
			'last_outbound_error'   => 0,
			'last_error_message'    => '',
			'last_error_code'       => '',
			'last_ping'             => 0,
			'remote_wc_version'     => '',
			'remote_plugin_version' => '',
		];
	}

	/**
	 * Derive a coarse status label from a health array for admin/CLI/Site Health display.
	 * Never throws; always returns one of: never, ok, stale, error.
	 */
	public static function derive_status(array $health, int $stale_after = 86400): string {
		$last_error = (int) ($health['last_outbound_error'] ?? 0);
		$last_success = max((int) ($health['last_outbound_success'] ?? 0), (int) ($health['last_ping'] ?? 0));
		$last_inbound = (int) ($health['last_inbound'] ?? 0);

		if ($last_success === 0 && $last_inbound === 0 && $last_error === 0) {
			return 'never';
		}

		if ($last_error > 0 && $last_error > $last_success) {
			return 'error';
		}

		$most_recent = max($last_success, $last_inbound);
		if ($most_recent > 0 && (time() - $most_recent) > $stale_after) {
			return 'stale';
		}

		return 'ok';
	}

	public static function ensure_defaults(): void {
		$opt = get_option(self::OPTION_KEY, null);
		if (!is_array($opt)) $opt = [];

		$changed = false;

		if (empty($opt['this_store_id'])) {
			$opt['this_store_id'] = wp_generate_uuid4();
			$changed = true;
		}

		if (empty($opt['this_store_name'])) {
			$opt['this_store_name'] = get_bloginfo('name') ?: 'Woo Store';
			$changed = true;
		}

		if (empty($opt['role']) || !in_array($opt['role'], ['master', 'child'], true)) {
			$opt['role'] = 'child';
			$changed = true;
		}

		if (!isset($opt['strict_checkout_validation'])) {
			$opt['strict_checkout_validation'] = true;
			$changed = true;
		}

		if (!isset($opt['master']) || !is_array($opt['master'])) {
			$opt['master'] = [
				'url'      => '',
				'store_id' => '',
				'secret'   => '',
			];
			$changed = true;
		}

		if (!isset($opt['children']) || !is_array($opt['children'])) {
			$opt['children'] = [];
			$changed = true;
		}

		if (!isset($opt['exclusions']) || !is_array($opt['exclusions'])) {
			$opt['exclusions'] = ['skus' => []];
			$changed = true;
		}

		if (!isset($opt['notices']) || !is_array($opt['notices'])) {
			$opt['notices'] = [];
			$changed = true;
		}

		if (!isset($opt['event_log']) || !is_array($opt['event_log'])) {
			$opt['event_log'] = [];
			$changed = true;
		}

		if (!isset($opt['backlog']) || !is_array($opt['backlog'])) {
			$opt['backlog'] = [];
			$changed = true;
		}

		if (!isset($opt['reconcile']) || !is_array($opt['reconcile'])) {
			$opt['reconcile'] = [
				'running' => false,
				'page' => 0,
				'per_page' => 200,
				'last_run' => 0,
			];
			$changed = true;
		}
		{
			$reconcile_defaults = [
				'mode' => 'all',
				'dry_run' => false,
				'differences_only' => false,
				'selected_skus' => [],
				'processed' => 0,
				'total_estimate' => 0,
				'differences_found' => 0,
				'pushed_count' => 0,
				'started_at' => 0,
				'finished_at' => 0,
			];
			foreach ($reconcile_defaults as $rk => $rv) {
				if (!array_key_exists($rk, $opt['reconcile'])) {
					$opt['reconcile'][$rk] = $rv;
					$changed = true;
				}
			}
		}

		if (!isset($opt['checkout_validation_failure_strategy']) || !in_array($opt['checkout_validation_failure_strategy'], ['fail_open', 'fail_closed', 'stale_cache'], true)) {
			$opt['checkout_validation_failure_strategy'] = 'fail_open';
			$changed = true;
		}

		if (!isset($opt['checkout_stale_cache_minutes']) || (int) $opt['checkout_stale_cache_minutes'] <= 0) {
			$opt['checkout_stale_cache_minutes'] = 30;
			$changed = true;
		}

		if (!isset($opt['conflicts_report']) || !is_array($opt['conflicts_report'])) {
			$opt['conflicts_report'] = ['generated_at' => 0, 'items' => []];
			$changed = true;
		}

		if (!isset($opt['master']['health']) || !is_array($opt['master']['health'])) {
			$opt['master']['health'] = self::default_health();
			$changed = true;
		}

		if (isset($opt['children']) && is_array($opt['children'])) {
			foreach ($opt['children'] as $ci => $child) {
				if (!is_array($child)) continue;
				if (!isset($child['health']) || !is_array($child['health'])) {
					$opt['children'][$ci]['health'] = self::default_health();
					$changed = true;
				}
			}
		}

		if (!isset($opt['health']) || !is_array($opt['health'])) {
			$opt['health'] = [
				'last_inbound_event' => 0,
				'last_outbound_success' => 0,
				'last_outbound_error' => 0,
				'last_error_message' => '',
				'last_error_code' => '',
				'last_reconcile_start' => 0,
				'last_reconcile_complete' => 0,
			];
			$changed = true;
		} else {
			// ensure keys exist
			$defaults = [
				'last_inbound_event' => 0,
				'last_outbound_success' => 0,
				'last_outbound_error' => 0,
				'last_error_message' => '',
				'last_error_code' => '',
				'last_reconcile_start' => 0,
				'last_reconcile_complete' => 0,
			];
			foreach ($defaults as $k => $v) {
				if (!array_key_exists($k, $opt['health'])) {
					$opt['health'][$k] = $v;
					$changed = true;
				}
			}
		}

		if (!isset($opt['schema_version']) || (int) $opt['schema_version'] < self::SCHEMA_VERSION) {
			$opt['schema_version'] = self::SCHEMA_VERSION;
			$changed = true;
		}

		if ($changed) {
			update_option(self::OPTION_KEY, $opt, false);
		}
	}

	public function get_all(): array {
		$opt = get_option(self::OPTION_KEY, []);
		return is_array($opt) ? $opt : [];
	}

	public function update_all(array $new): void {
		update_option(self::OPTION_KEY, $new, false);
	}

	public function role(): string {
		$opt = $this->get_all();
		return isset($opt['role']) ? (string) $opt['role'] : 'child';
	}

	public function is_master(): bool {
		return $this->role() === 'master';
	}

	public function is_child(): bool {
		return $this->role() === 'child';
	}

	public function this_store_id(): string {
		$opt = $this->get_all();
		return (string) ($opt['this_store_id'] ?? '');
	}

	public function this_store_name(): string {
		$opt = $this->get_all();
		return (string) ($opt['this_store_name'] ?? '');
	}

	public function strict_checkout_validation(): bool {
		$opt = $this->get_all();
		return (bool) ($opt['strict_checkout_validation'] ?? true);
	}

	public function master_config(): array {
		$opt = $this->get_all();
		return is_array($opt['master'] ?? null) ? $opt['master'] : ['url' => '', 'store_id' => '', 'secret' => ''];
	}

	public function children(): array {
		$opt = $this->get_all();
		return is_array($opt['children'] ?? null) ? $opt['children'] : [];
	}

	public function get_child_by_id(string $store_id): ?array {
		$store_id = trim($store_id);
		if ($store_id === '') return null;
		foreach ($this->children() as $child) {
			if (!is_array($child)) continue;
			if ((string)($child['id'] ?? '') === $store_id) return $child;
		}
		return null;
	}

	public function update_child(string $store_id, array $patch): bool {
		$store_id = trim($store_id);
		if ($store_id === '') return false;

		$opt = $this->get_all();
		$children = is_array($opt['children'] ?? null) ? $opt['children'] : [];

		$updated = false;
		foreach ($children as $i => $child) {
			if (!is_array($child)) continue;
			if ((string)($child['id'] ?? '') !== $store_id) continue;

			foreach ($patch as $k => $v) {
				$key = (string)$k;
				if ($key === 'enabled') {
					$child['enabled'] = (bool)$v;
				} elseif (in_array($key, ['name','url','secret'], true)) {
					$child[$key] = (string)$v;
				}
			}

			$children[$i] = $child;
			$updated = true;
			break;
		}

		if ($updated) {
			$opt['children'] = $children;
			$this->update_all($opt);
		}

		return $updated;
	}

	/**
	 * Seconds a rotated secret keeps working after being superseded, so the two
	 * sides of a pairing (which are updated independently) can overlap safely.
	 * Filterable; clamped to [1 hour, 7 days].
	 */
	public function secret_rotation_overlap_seconds(): int {
		$default = self::SECRET_ROTATION_OVERLAP_DEFAULT;
		$seconds = (int) apply_filters('kitgenix_stock_sync_for_woocommerce_secret_rotation_overlap', $default);
		return max(3600, min(7 * 86400, $seconds));
	}

	/**
	 * Rotate the secret used to authenticate to the Master (Child role).
	 * The current secret becomes valid as a fallback for the overlap window.
	 */
	public function rotate_master_secret(string $new_secret): void {
		$opt = $this->get_all();
		$master = is_array($opt['master'] ?? null) ? $opt['master'] : [];

		$current = (string) ($master['secret'] ?? '');
		if ($current !== '') {
			$master['secret_previous'] = $current;
			$master['secret_previous_expires_at'] = time() + $this->secret_rotation_overlap_seconds();
		}
		$master['secret'] = $new_secret;

		$opt['master'] = $master;
		$this->update_all($opt);
	}

	/** Rotate the secret for one configured Child store (Master role). */
	public function rotate_child_secret(string $store_id, string $new_secret): bool {
		$store_id = trim($store_id);
		if ($store_id === '') return false;

		$opt = $this->get_all();
		$children = is_array($opt['children'] ?? null) ? $opt['children'] : [];

		$updated = false;
		foreach ($children as $i => $child) {
			if (!is_array($child)) continue;
			if ((string) ($child['id'] ?? '') !== $store_id) continue;

			$current = (string) ($child['secret'] ?? '');
			if ($current !== '') {
				$child['secret_previous'] = $current;
				$child['secret_previous_expires_at'] = time() + $this->secret_rotation_overlap_seconds();
			}
			$child['secret'] = $new_secret;

			$children[$i] = $child;
			$updated = true;
			break;
		}

		if ($updated) {
			$opt['children'] = $children;
			$this->update_all($opt);
		}

		return $updated;
	}

	/** Previous secret for a store config, if set and not yet expired. Empty string otherwise. */
	public static function previous_secret_if_valid(array $store_config): string {
		$prev = (string) ($store_config['secret_previous'] ?? '');
		if ($prev === '') return '';

		$expires = (int) ($store_config['secret_previous_expires_at'] ?? 0);
		if ($expires > 0 && time() > $expires) return '';

		return $prev;
	}

	public function checkout_validation_failure_strategy(): string {
		$opt = $this->get_all();
		$v = (string) ($opt['checkout_validation_failure_strategy'] ?? 'fail_open');
		return in_array($v, ['fail_open', 'fail_closed', 'stale_cache'], true) ? $v : 'fail_open';
	}

	public function checkout_stale_cache_minutes(): int {
		$opt = $this->get_all();
		$m = (int) ($opt['checkout_stale_cache_minutes'] ?? 30);
		return max(1, $m);
	}

	/** Per-store health for the Master (as seen from a Child). */
	public function get_master_health(): array {
		$opt = $this->get_all();
		$h = is_array($opt['master']['health'] ?? null) ? $opt['master']['health'] : [];
		return array_merge(self::default_health(), $h);
	}

	public function set_master_health(array $patch): void {
		$opt = $this->get_all();
		$h = is_array($opt['master']['health'] ?? null) ? $opt['master']['health'] : self::default_health();
		foreach ($patch as $k => $v) {
			$h[(string) $k] = $v;
		}
		$opt['master']['health'] = $h;
		$this->update_all($opt);
	}

	/** Per-store health for one Child (as seen from the Master). */
	public function get_child_health(string $store_id): array {
		$child = $this->get_child_by_id($store_id);
		$h = is_array($child['health'] ?? null) ? $child['health'] : [];
		return array_merge(self::default_health(), $h);
	}

	public function set_child_health(string $store_id, array $patch): void {
		$store_id = trim($store_id);
		if ($store_id === '') return;

		$opt = $this->get_all();
		$children = is_array($opt['children'] ?? null) ? $opt['children'] : [];

		foreach ($children as $i => $child) {
			if (!is_array($child)) continue;
			if ((string) ($child['id'] ?? '') !== $store_id) continue;

			$h = is_array($child['health'] ?? null) ? $child['health'] : self::default_health();
			foreach ($patch as $k => $v) {
				$h[(string) $k] = $v;
			}
			$children[$i]['health'] = $h;
			break;
		}

		$opt['children'] = $children;
		$this->update_all($opt);
	}

	public function excluded_skus(): array {
		$opt = $this->get_all();
		$list = $opt['exclusions']['skus'] ?? [];
		return is_array($list) ? array_values(array_filter(array_map('strval', $list))) : [];
	}

	public function is_excluded_sku(string $sku): bool {
		$sku = trim($sku);
		if ($sku === '') return false;
		return in_array($sku, $this->excluded_skus(), true);
	}

	public function set_notice(string $type, string $message): void {
		$opt = $this->get_all();
		$opt['notices'] = is_array($opt['notices'] ?? null) ? $opt['notices'] : [];
		$opt['notices'][] = [
			'time'    => time(),
			'type'    => $type,
			'message' => $message,
		];
		$opt['notices'] = array_slice($opt['notices'], -20);
		$this->update_all($opt);
	}

	public function pop_notices(): array {
		$opt = $this->get_all();
		$notices = is_array($opt['notices'] ?? null) ? $opt['notices'] : [];
		$opt['notices'] = [];
		$this->update_all($opt);
		return $notices;
	}

	public function add_event_log(string $level, string $message, array $context = [], string $code = ''): void {
		$opt = $this->get_all();
		$opt['event_log'] = is_array($opt['event_log'] ?? null) ? $opt['event_log'] : [];
		$opt['event_log'][] = [
			'time' => time(),
			'level' => $level,
			'message' => $message,
			'context' => $this->sanitize_context($context),
			'code' => $code,
		];
		$opt['event_log'] = array_slice($opt['event_log'], -250);
		$this->update_all($opt);
	}

	public function get_event_log(): array {
		$opt = $this->get_all();
		return is_array($opt['event_log'] ?? null) ? $opt['event_log'] : [];
	}

	public function clear_event_log(): void {
		$opt = $this->get_all();
		$opt['event_log'] = [];
		$this->update_all($opt);
	}

	/**
	 * Codes that represent transient/recoverable friction (clock drift, duplicate
	 * webhook delivery, network blips) rather than a genuine problem needing action.
	 * Mirrors the Turnstile plugin's is_retry_codes() split.
	 */
	public static function is_recoverable_code(string $code): bool {
		$recoverable = [
			'kss_auth_replay',
			'kss_auth_skew',
			'http_timeout',
			'http_connection_error',
			'http_5xx',
			'kss_stale_version',
		];

		return in_array($code, $recoverable, true);
	}

	/**
	 * Map a diagnostic code to a plain-English category and note for a
	 * non-technical site owner. Covers the auth rejection codes from
	 * Security::verify_request()/REST::authenticate() and the outbound
	 * request codes from Sync.
	 *
	 * @return array{category: string, note: string}
	 */
	public static function get_category_and_note(string $code): array {
		$map = [
			'kss_auth_missing' => [
				'category' => __('Missing signature headers', 'kitgenix-stock-sync-for-woocommerce'),
				'note'     => __('The request had no store ID, timestamp, nonce, or signature – it is not a properly signed Kitgenix Stock Sync request.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			'kss_auth_bad_ts' => [
				'category' => __('Malformed timestamp', 'kitgenix-stock-sync-for-woocommerce'),
				'note'     => __('The timestamp header was not a valid number, so the request could not be checked for freshness.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			'kss_auth_skew' => [
				'category' => __('Clock drift between stores', 'kitgenix-stock-sync-for-woocommerce'),
				'note'     => __('The two stores\' clocks are more than 5 minutes apart, so the timestamp fell outside the allowed window. Usually clock drift, not an attack.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			'kss_auth_replay' => [
				'category' => __('Duplicate request (replay)', 'kitgenix-stock-sync-for-woocommerce'),
				'note'     => __('The same nonce was already used. Usually a duplicate webhook delivery or a retried request, not an attack.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			'kss_auth_sig' => [
				'category' => __('Signature mismatch', 'kitgenix-stock-sync-for-woocommerce'),
				'note'     => __('The request signature did not match. The shared secret configured for this store pairing likely does not match on both ends.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			'kss_auth_secret' => [
				'category' => __('Secret not configured', 'kitgenix-stock-sync-for-woocommerce'),
				'note'     => __('This store has no secret stored for that sender, so the request could not be verified.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			'kss_auth_sender' => [
				'category' => __('Unknown sender store', 'kitgenix-stock-sync-for-woocommerce'),
				'note'     => __('The sending store ID is not recognised as a configured Master or Child on this store.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			'http_timeout' => [
				'category' => __('Connection timed out', 'kitgenix-stock-sync-for-woocommerce'),
				'note'     => __('The remote store took too long to respond. Usually a transient network or server-load issue.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			'http_connection_error' => [
				'category' => __('Connection failed', 'kitgenix-stock-sync-for-woocommerce'),
				'note'     => __('This store could not reach the remote store at all (DNS, SSL, or network failure). Often a temporary blip.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			'http_5xx' => [
				'category' => __('Remote server error', 'kitgenix-stock-sync-for-woocommerce'),
				'note'     => __('The remote store responded with a server error (5xx). Usually temporary – maintenance, a PHP error, or high load on the other store.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			'http_4xx_rejected' => [
				'category' => __('Request rejected', 'kitgenix-stock-sync-for-woocommerce'),
				'note'     => __('The remote store rejected the request with a 4xx response that was not an authentication failure.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			'config_error' => [
				'category' => __('Configuration problem', 'kitgenix-stock-sync-for-woocommerce'),
				'note'     => __('This store is missing required configuration (Master/Child URL, store ID, or secret), or failed to apply an incoming update locally.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			'kss_bad_json' => [
				'category' => __('Malformed request body', 'kitgenix-stock-sync-for-woocommerce'),
				'note'     => __('The request body was not valid JSON, so it could not be processed.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			'kss_payload_too_large' => [
				'category' => __('Request too large', 'kitgenix-stock-sync-for-woocommerce'),
				'note'     => __('The request body exceeded the maximum accepted size and was rejected before processing.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			'kss_rate_limited' => [
				'category' => __('Too many failed attempts', 'kitgenix-stock-sync-for-woocommerce'),
				'note'     => __('Repeated authentication failures from this sender triggered a temporary lockout. If this was not expected, check for a misconfigured secret or an unexpected caller.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			'kss_stale_version' => [
				'category' => __('Superseded update (ignored)', 'kitgenix-stock-sync-for-woocommerce'),
				'note'     => __('An incoming stock update was older than the state already applied locally (a delayed, duplicate, or replayed event) and was safely ignored.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			'kss_insecure_url' => [
				'category' => __('Insecure URL rejected', 'kitgenix-stock-sync-for-woocommerce'),
				'note'     => __('A Master/Child URL must use HTTPS. HTTP URLs are rejected to prevent credentials and stock data travelling in plain text.', 'kitgenix-stock-sync-for-woocommerce'),
			],
		];

		if (isset($map[$code])) {
			return $map[$code];
		}

		return [
			'category' => __('Unclassified', 'kitgenix-stock-sync-for-woocommerce'),
			'note'     => __('This event was not tagged with a recognised diagnostic code.', 'kitgenix-stock-sync-for-woocommerce'),
		];
	}

	/** Maximum backlog rows retained. Oldest are dropped first. */
	private const BACKLOG_CAP = 200;

	/** Full payloads larger than this (bytes, JSON-encoded) are not stored – only payload_meta. */
	private const BACKLOG_PAYLOAD_MAX_BYTES = 200000;

	/**
	 * Record a failed push/send. Returns the new row's stable id so it can later
	 * be retried or discarded individually. Stores the full payload (bounded)
	 * so a manual retry can actually resend it – payload_meta alone (the old
	 * behaviour) is not enough to reconstruct a retry.
	 */
	public function add_backlog(string $type, string $store_id, array $payload, int $attempt, string $error, string $code = '', int $next_retry_at = 0): string {
		$opt = $this->get_all();
		$opt['backlog'] = is_array($opt['backlog'] ?? null) ? $opt['backlog'] : [];

		$id = wp_generate_uuid4();
		$encoded = wp_json_encode($payload);
		$store_payload = (is_string($encoded) && strlen($encoded) <= self::BACKLOG_PAYLOAD_MAX_BYTES) ? $payload : null;

		$opt['backlog'][] = [
			'id' => $id,
			'time' => time(),
			'type' => $type,
			'store_id' => $store_id,
			'attempt' => $attempt,
			'error' => $error,
			'code' => $code,
			'status' => 'pending',
			'next_retry_at' => $next_retry_at,
			'payload' => $store_payload,
			'payload_meta' => [
				'event_type' => (string) ($payload['event_type'] ?? ''),
				'context' => (string) ($payload['context'] ?? ''),
				'items_count' => isset($payload['items']) && is_array($payload['items']) ? count($payload['items']) : 0,
			],
		];
		$opt['backlog'] = array_slice($opt['backlog'], -self::BACKLOG_CAP);
		$this->update_all($opt);

		return $id;
	}

	public function get_backlog(): array {
		$opt = $this->get_all();
		return is_array($opt['backlog'] ?? null) ? $opt['backlog'] : [];
	}

	public function get_backlog_item(string $id): ?array {
		foreach ($this->get_backlog() as $row) {
			if (is_array($row) && (string) ($row['id'] ?? '') === $id) return $row;
		}
		return null;
	}

	/** Merge $patch into the backlog row with this id. Returns false if not found. */
	public function update_backlog_item(string $id, array $patch): bool {
		if ($id === '') return false;

		$opt = $this->get_all();
		$backlog = is_array($opt['backlog'] ?? null) ? $opt['backlog'] : [];

		$updated = false;
		foreach ($backlog as $i => $row) {
			if (!is_array($row) || (string) ($row['id'] ?? '') !== $id) continue;
			$backlog[$i] = array_merge($row, $patch);
			$updated = true;
			break;
		}

		if ($updated) {
			$opt['backlog'] = $backlog;
			$this->update_all($opt);
		}

		return $updated;
	}

	/** Discard a single backlog row (explicit action; does not resend it). */
	public function remove_backlog_item(string $id): bool {
		if ($id === '') return false;

		$opt = $this->get_all();
		$backlog = is_array($opt['backlog'] ?? null) ? $opt['backlog'] : [];
		$before = count($backlog);

		$backlog = array_values(array_filter($backlog, static fn($row) => !(is_array($row) && (string) ($row['id'] ?? '') === $id)));

		if (count($backlog) === $before) return false;

		$opt['backlog'] = $backlog;
		$this->update_all($opt);
		return true;
	}

	public function clear_backlog(): void {
		$opt = $this->get_all();
		$opt['backlog'] = [];
		$this->update_all($opt);
	}

	public function get_conflicts_report(): array {
		$opt = $this->get_all();
		$r = is_array($opt['conflicts_report'] ?? null) ? $opt['conflicts_report'] : [];
		return [
			'generated_at' => (int) ($r['generated_at'] ?? 0),
			'items' => is_array($r['items'] ?? null) ? $r['items'] : [],
		];
	}

	/** Replace the stored conflict/discrepancy report (bounded). */
	public function set_conflicts_report(array $items): void {
		$opt = $this->get_all();
		$opt['conflicts_report'] = [
			'generated_at' => time(),
			'items' => array_slice(array_values($items), 0, 500),
		];
		$this->update_all($opt);
	}

	public function reconcile_state(): array {
		$opt = $this->get_all();
		return is_array($opt['reconcile'] ?? null) ? $opt['reconcile'] : ['running'=>false,'page'=>0,'per_page'=>200,'last_run'=>0];
	}

	public function set_reconcile_state(array $state): void {
		$opt = $this->get_all();
		$opt['reconcile'] = $state;
		$this->update_all($opt);
	}

	public function get_health(): array {
		$opt = $this->get_all();
		$h = $opt['health'] ?? [];
		return is_array($h) ? $h : [];
	}

	public function set_health(array $patch): void {
		$opt = $this->get_all();
		$h = is_array($opt['health'] ?? null) ? $opt['health'] : [];
		foreach ($patch as $k => $v) {
			$h[(string)$k] = $v;
		}
		$opt['health'] = $h;
		$this->update_all($opt);
	}

	public function set_health_value(string $key, mixed $value): void {
		$this->set_health([$key => $value]);
	}

	public function health_value(string $key, mixed $default = null): mixed {
		$h = $this->get_health();
		return array_key_exists($key, $h) ? $h[$key] : $default;
	}

	private function sanitize_context(array $context): array {
		$allowed = [];
		foreach ($context as $k => $v) {
			$key = is_string($k) ? $k : (string)$k;
			if (is_scalar($v) || $v === null) {
				$allowed[$key] = $v;
			} else {
				$allowed[$key] = wp_json_encode($v);
			}
		}
		return $allowed;
	}

	public static function parent_menu_slug(): string {
		return (string) apply_filters('kitgenix_stock_sync_for_woocommerce_parent_menu_slug', 'kitgenix');
	}
}
