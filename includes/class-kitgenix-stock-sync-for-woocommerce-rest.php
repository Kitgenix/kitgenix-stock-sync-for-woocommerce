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

	private function authenticate(\WP_REST_Request $request): bool|\WP_Error {
		$sender_id = (string) $request->get_header('x_kitgenix_store_id');

		if (!$this->security->is_sender_allowed($sender_id)) {
			return new \WP_Error('kss_auth_sender', 'Sender not configured', ['status' => 401]);
		}

		$secret = $this->security->secret_for_sender($sender_id);
		if ($secret === '') {
			return new \WP_Error('kss_auth_secret', 'Missing secret', ['status' => 401]);
		}

		return $this->security->verify_request($request, $secret);
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
		], 200);
	}

	public function event(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$auth = $this->authenticate($request);
		if (is_wp_error($auth)) return $auth;

		$sender_id = (string) $request->get_header('x_kitgenix_store_id');
		$payload   = json_decode($request->get_body(), true);

		if (!is_array($payload)) {
			return new \WP_Error('kss_bad_json', 'Invalid JSON', ['status' => 400]);
		}

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

		$payload = json_decode($request->get_body(), true);
		if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
			return new \WP_Error('kss_bad_payload', 'Missing items', ['status' => 400]);
		}

		$result = $this->sync->master_stock_lookup($payload['items']);

		return new \WP_REST_Response(['ok' => true, 'items' => $result], 200);
	}

	public function stock_state_query(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
		$auth = $this->authenticate($request);
		if (is_wp_error($auth)) return $auth;

		$payload = json_decode($request->get_body(), true);
		if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
			return new \WP_Error('kss_bad_payload', 'Missing items', ['status' => 400]);
		}

		$result = $this->sync->local_stock_lookup($payload['items']);

		return new \WP_REST_Response(['ok' => true, 'items' => $result], 200);
	}
}
