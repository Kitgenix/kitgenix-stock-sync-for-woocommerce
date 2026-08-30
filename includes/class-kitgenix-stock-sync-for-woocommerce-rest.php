<?php
defined('ABSPATH') || exit;

final class Kitgenix_Stock_Sync_For_WooCommerce_REST {

	private Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings;
	private Kitgenix_Stock_Sync_For_WooCommerce_Security $security;
	private Kitgenix_Stock_Sync_For_WooCommerce_Sync $sync;

	public function __construct(
		Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings,
		Kitgenix_Stock_Sync_For_WooCommerce_Security $security,
		Kitgenix_Stock_Sync_For_WooCommerce_Sync $sync
	) {
		$this->settings = $settings;
		$this->security = $security;
		$this->sync     = $sync;
	}

	public function hooks(): void {
		add_action('rest_api_init', [$this, 'register_routes']);
	}

	public function register_routes(): void {
		register_rest_route('kitgenix-stock-sync/v1', '/ping', [
			'methods'  => 'POST',
			'callback' => [$this, 'ping'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route('kitgenix-stock-sync/v1', '/event', [
			'methods'  => 'POST',
			'callback' => [$this, 'event'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route('kitgenix-stock-sync/v1', '/stock', [
			'methods'  => 'POST',
			'callback' => [$this, 'stock_query'],
			'permission_callback' => '__return_true',
		]);

		// NEW: query the local store's stock fields (for auditing/mismatch reports)
		register_rest_route('kitgenix-stock-sync/v1', '/stock-state', [
			'methods'  => 'POST',
			'callback' => [$this, 'stock_state_query'],
			'permission_callback' => '__return_true',
		]);
	}

	/**
	 * Authenticate an inbound request. Uses a generic, non-enumerable error for
	 * every rejection reason (unknown sender, missing secret, bad signature) –
	 * see Security::verify_request() docblock – and throttles/locks out on
	 * repeated failures before spending any HMAC compute on them.
	 *
	 * Throttling keys on BOTH the remote IP and the claimed store_id (not
	 * store_id alone): keying only on the attacker-supplied store_id header
	 * would let an attacker bypass the lockout entirely by rotating that
	 * header on every request.
	 */
	private function authenticate(\WP_REST_Request $request): bool|\WP_Error {
		$sender_id = (string) $request->get_header('x_kitgenix_store_id');
		$ip        = $this->security->remote_identity();
		$identities = $sender_id !== '' ? [$ip, $sender_id] : [$ip];
		$generic   = static fn() => new \WP_Error('kss_auth_failed', 'Authentication failed', ['status' => 401]);

		foreach ($identities as $identity) {
			if ($this->security->is_locked_out($identity)) {
				$this->settings->add_event_log('error', 'Rejected inbound request: sender temporarily locked out.', ['store_id' => $sender_id], 'kss_rate_limited');
				return new \WP_Error('kss_rate_limited', 'Too many failed attempts', ['status' => 429]);
			}
		}

		$body = $request->get_body();
		if ($this->security->body_too_large((string) $body)) {
			$this->settings->add_event_log('error', 'Rejected inbound request: body too large.', ['store_id' => $sender_id, 'bytes' => strlen((string) $body)], 'kss_payload_too_large');
			return new \WP_Error('kss_payload_too_large', 'Request body too large', ['status' => 413]);
		}

		$candidates = $sender_id !== '' ? $this->security->candidate_secrets_for_sender($sender_id) : [];
		if (empty($candidates)) {
			$this->settings->add_event_log('error', 'Rejected inbound request: sender store not configured.', ['store_id' => $sender_id], 'kss_auth_sender');
			foreach ($identities as $identity) $this->security->record_auth_failure($identity);
			return $generic();
		}

		$result = $this->security->verify_request($request, $candidates);
		if (is_wp_error($result)) {
			foreach ($identities as $identity) $this->security->record_auth_failure($identity);
			return $result;
		}

		foreach ($identities as $identity) $this->security->clear_auth_failures($identity);
		return true;
	}

	/** Decode a JSON body, returning a clean 400 WP_Error on malformed JSON instead of a silent empty array. */
	private function decode_json_body(\WP_REST_Request $request): array|\WP_Error {
		$body = (string) $request->get_body();
		$decoded = json_decode($body, true);

		if ($body !== '' && json_last_error() !== JSON_ERROR_NONE) {
			return new \WP_Error('kss_bad_json', 'Invalid JSON: ' . json_last_error_msg(), ['status' => 400]);
		}

		if (!is_array($decoded)) {
			return new \WP_Error('kss_bad_json', 'Invalid JSON payload', ['status' => 400]);
		}

		return $decoded;
	}

	public function ping(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$auth = $this->authenticate($request);
		if (is_wp_error($auth)) return $auth;

		return new \WP_REST_Response([
			'ok' => true,
			'store_id' => $this->settings->this_store_id(),
			'store_name' => $this->settings->this_store_name(),
			'role' => $this->settings->role(),
			'time' => time(),
			'wc_version' => defined('WC_VERSION') ? WC_VERSION : '',
			'plugin_version' => defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION') ? KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION : '',
		], 200);
	}

	public function event(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$auth = $this->authenticate($request);
		if (is_wp_error($auth)) return $auth;

		$sender_id = (string) $request->get_header('x_kitgenix_store_id');
		$payload   = $this->decode_json_body($request);
		if (is_wp_error($payload)) return $payload;

		$queued = $this->sync->enqueue_incoming_event($sender_id, $payload);
		if (is_wp_error($queued)) return $queued;

		return new \WP_REST_Response(['ok' => true, 'processed' => true], 200);
	}

	public function stock_query(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$auth = $this->authenticate($request);
		if (is_wp_error($auth)) return $auth;

		if (!$this->settings->is_master()) {
			return new \WP_Error('kss_not_master', 'Stock query is only supported on master', ['status' => 403]);
		}

		$payload = $this->decode_json_body($request);
		if (is_wp_error($payload)) return $payload;
		if (!isset($payload['items']) || !is_array($payload['items'])) {
			return new \WP_Error('kss_bad_payload', 'Missing items', ['status' => 400]);
		}

		$result = $this->sync->master_stock_lookup($payload['items']);

		return new \WP_REST_Response(['ok' => true, 'items' => $result], 200);
	}

	public function stock_state_query(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$auth = $this->authenticate($request);
		if (is_wp_error($auth)) return $auth;

		$payload = $this->decode_json_body($request);
		if (is_wp_error($payload)) return $payload;
		if (!isset($payload['items']) || !is_array($payload['items'])) {
			return new \WP_Error('kss_bad_payload', 'Missing items', ['status' => 400]);
		}

		$result = $this->sync->local_stock_lookup($payload['items']);

		return new \WP_REST_Response(['ok' => true, 'items' => $result], 200);
	}
}
