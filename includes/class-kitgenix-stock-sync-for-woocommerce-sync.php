<?php
defined('ABSPATH') || exit;

final class Kitgenix_Stock_Sync_For_WooCommerce_Sync {

	private Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings;
	private Kitgenix_Stock_Sync_For_WooCommerce_Security $security;

	private static bool $suppress_outbound = false;
	private static bool $in_order_stock_change = false;

	public const META_GID = '_kitgenix_stock_sync_for_woocommerce_gid';

	private const SEEN_EVENT_TTL = 2 * HOUR_IN_SECONDS;
	private const DEBOUNCE_TTL = 2; // seconds

	public function __construct(
		Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings,
		Kitgenix_Stock_Sync_For_WooCommerce_Security $security
	) {
		$this->settings = $settings;
		$this->security = $security;
	}

	public function hooks(): void {

		add_action('woocommerce_product_set_stock', [$this, 'on_product_set_stock'], 10, 1);
		add_action('woocommerce_variation_set_stock', [$this, 'on_product_set_stock'], 10, 1);
		add_action('woocommerce_product_set_stock_status', [$this, 'on_product_set_stock_status'], 10, 3);
		add_action('woocommerce_variation_set_stock_status', [$this, 'on_product_set_stock_status'], 10, 3);

		add_action('woocommerce_product_object_updated_props', [$this, 'on_product_object_updated_props'], 10, 2);

		add_action('woocommerce_reduce_order_stock', [$this, 'on_reduce_order_stock'], 1, 1);
		add_action('woocommerce_reduce_order_stock', [$this, 'on_reduce_order_stock_after'], 999, 1);
		add_action('woocommerce_restore_order_stock', [$this, 'on_restore_order_stock'], 1, 1);
		add_action('woocommerce_restore_order_stock', [$this, 'on_restore_order_stock_after'], 999, 1);

		add_action('woocommerce_order_status_processing', [$this, 'on_order_status_processing'], 10, 1);

		add_action('updated_post_meta', [$this, 'on_updated_post_meta'], 10, 4);

		add_filter('update_post_metadata', [$this, 'capture_old_sku_before_update'], 10, 5);
		add_action('updated_post_meta', [$this, 'on_sku_updated_post_meta'], 10, 4);

		add_action('woocommerce_after_checkout_validation', [$this, 'child_checkout_validation'], 10, 2);
	}

	private function logger(): WC_Logger {
		return wc_get_logger();
	}

	private function log(string $level, string $message, array $context = []): void {
		$ctx = array_merge(['source' => 'kitgenix-stock-sync-for-woocommerce'], $context);
		$this->logger()->log($level, $message, $ctx);
		$this->settings->add_event_log($level, $message, $context);
	}

	private function debounce_key(string $key): string {
		return 'kitgenix_stock_sync_for_woocommerce_kss_debounce_' . md5($key);
	}

	private function debounce_allow(string $key, int $seconds = self::DEBOUNCE_TTL): bool {
		$k = $this->debounce_key($key);
		if (get_transient($k)) return false;
		set_transient($k, 1, max(1, $seconds));
		return true;
	}

	private function mark_outbound_success(): void {
		$this->settings->set_health([
			'last_outbound_success' => time(),
			'last_error_message' => '',
		]);
	}

	private function mark_outbound_error(string $message): void {
		$this->settings->set_health([
			'last_outbound_error' => time(),
			'last_error_message' => $message,
		]);
	}

	private function mark_inbound(): void {
		$this->settings->set_health_value('last_inbound_event', time());
	}

	/** -----------------------------
	 * Outbound capture
	 * ----------------------------- */

	public function on_product_set_stock($product): void {
		if (self::$suppress_outbound) return;
		if (self::$in_order_stock_change) return;
		if (!($product instanceof WC_Product)) return;

		$sku = (string)$product->get_sku();
		if ($sku !== '' && !$this->debounce_allow('stock_set|' . $sku)) return;

		$fresh = $this->get_fresh_product((int)$product->get_id());
		if ($fresh instanceof WC_Product) {
			$this->maybe_send_stock_state([$fresh], 'stock_set');
		} else {
			$this->maybe_send_stock_state([$product], 'stock_set');
		}
	}

	public function on_product_set_stock_status($product_id, $status, $product): void {
		if (self::$suppress_outbound) return;
		if (self::$in_order_stock_change) return;
		if (!($product instanceof WC_Product)) return;

		$sku = (string)$product->get_sku();
		if ($sku !== '' && !$this->debounce_allow('stock_status|' . $sku)) return;

		$fresh = $this->get_fresh_product((int)$product->get_id());
		if ($fresh instanceof WC_Product) {
			$this->maybe_send_stock_state([$fresh], 'stock_status');
		} else {
			$this->maybe_send_stock_state([$product], 'stock_status');
		}
	}

	public function on_product_object_updated_props($product, $updated_props): void {
		if (self::$suppress_outbound) return;
		if (self::$in_order_stock_change) return;
		if (!($product instanceof WC_Product)) return;
		if (!is_array($updated_props)) return;

		$watched = [
			'stock_quantity',
			'stock_status',
			'manage_stock',
			'backorders',
			'low_stock_amount',
		];

		$hit = array_intersect($watched, $updated_props);
		if (empty($hit)) return;

		$sku = (string)$product->get_sku();
		if ($sku !== '' && !$this->debounce_allow('props|' . $sku)) return;

		$fresh = $this->get_fresh_product((int)$product->get_id());
		$use = $fresh instanceof WC_Product ? $fresh : $product;

		$this->maybe_send_stock_state([$use], 'props_update', ['props' => array_values($hit)]);
	}

	public function on_reduce_order_stock($order): void {
		self::$in_order_stock_change = true;
	}

	public function on_reduce_order_stock_after($order): void {
		self::$in_order_stock_change = false;
	}

	public function on_order_status_processing($order_id): void {
		if (self::$suppress_outbound) return;
		$this->as_process_order_processing((int)$order_id);
	}

	public function as_process_order_processing(int $order_id): void {
		if (self::$suppress_outbound) return;

		$order = wc_get_order($order_id);
		if (!($order instanceof WC_Order)) return;
		if ($order->get_status() !== 'processing') return;

		$products = [];
		foreach ($order->get_items() as $item) {
			if (!($item instanceof WC_Order_Item_Product)) continue;
			$product = $item->get_product();
			if ($product instanceof WC_Product) $products[] = $product;
		}

		$this->maybe_send_stock_state($products, 'order_processing', ['order_id' => $order_id]);
	}

	public function on_restore_order_stock($order_id): void {
		self::$in_order_stock_change = true;
	}

	public function on_restore_order_stock_after($order_id): void {
		try {
			if (self::$suppress_outbound) return;

			$order = wc_get_order($order_id);
			if (!($order instanceof WC_Order)) return;

			$products = [];
			foreach ($order->get_items() as $item) {
				if (!($item instanceof WC_Order_Item_Product)) continue;
				$product = $item->get_product();
				if ($product instanceof WC_Product) $products[] = $product;
			}

			$this->maybe_send_stock_state($products, 'order_restore', ['order_id' => $order->get_id()]);
		} finally {
			self::$in_order_stock_change = false;
		}
	}

	public function on_updated_post_meta($meta_id, $post_id, $meta_key, $meta_value): void {
		if (self::$suppress_outbound) return;
		if (self::$in_order_stock_change) return;

		$post_type = get_post_type($post_id);
		if (!in_array($post_type, ['product', 'product_variation'], true)) return;

		$whitelist = ['_stock', '_stock_status', '_backorders', '_manage_stock', '_low_stock_amount'];
		if (!in_array($meta_key, $whitelist, true)) return;

		$product = $this->get_fresh_product((int)$post_id);
		if (!($product instanceof WC_Product)) return;

		$sku = (string)$product->get_sku();
		if ($sku !== '' && !$this->debounce_allow('meta|' . $meta_key . '|' . $sku)) return;

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Not a DB query; passing meta key as context for outbound payload.
		$this->maybe_send_stock_state([$product], 'meta_update', ['meta_key' => (string)$meta_key]);
	}

	public function capture_old_sku_before_update($check, $object_id, $meta_key, $meta_value, $prev_value) {
		if (!$this->settings->is_master()) return $check;
		if ($meta_key !== '_sku') return $check;

		$post_type = get_post_type($object_id);
		if (!in_array($post_type, ['product', 'product_variation'], true)) return $check;

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- retrieving a single meta value by post ID is acceptable here
		$old = (string) get_post_meta((int)$object_id, '_sku', true);
		if ($old !== '') {
			set_transient('kitgenix_stock_sync_for_woocommerce_kss_old_sku_' . (int)$object_id, $old, 60);
		}
		return $check;
	}

	public function on_sku_updated_post_meta($meta_id, $post_id, $meta_key, $meta_value): void {
		if (self::$suppress_outbound) return;
		if (!$this->settings->is_master()) return;
		if ($meta_key !== '_sku') return;

		$post_type = get_post_type($post_id);
		if (!in_array($post_type, ['product', 'product_variation'], true)) return;

		$new_sku = trim((string)$meta_value);
		if ($new_sku === '') return;

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single meta lookup by post ID is acceptable here
		$gid = (string) get_post_meta((int)$post_id, self::META_GID, true);
		if ($gid === '') {
			$gid = wp_generate_uuid4();
			update_post_meta((int)$post_id, self::META_GID, $gid);
		}

		$old_sku = (string) get_transient('kitgenix_stock_sync_for_woocommerce_kss_old_sku_' . (int)$post_id);
		delete_transient('kitgenix_stock_sync_for_woocommerce_kss_old_sku_' . (int)$post_id);

		$this->send_event([
			'event_type' => 'sku_rename',
			'origin'     => 'master',
			'time'       => time(),
			'context'    => 'sku_rename',
			'items'      => [[
				'gid' => $gid,
				'sku' => $new_sku,
				'old_sku' => $old_sku,
			]],
		]);
	}

	private function get_fresh_product(int $post_id): ?WC_Product {
		if (function_exists('wc_delete_product_transients')) {
			wc_delete_product_transients($post_id);
		}
		clean_post_cache($post_id);
		wp_cache_delete($post_id, 'post_meta');

		$product = wc_get_product($post_id);
		if ($product instanceof WC_Product && method_exists($product, 'read_meta_data')) {
			$product->read_meta_data(true);
		}
		return $product instanceof WC_Product ? $product : null;
	}

	private function maybe_send_stock_state(array $products, string $context, array $extra = []): void {
		$items = [];

		foreach ($products as $product) {
			if (!($product instanceof WC_Product)) continue;

			$sku = (string) $product->get_sku();
			if ($sku === '') continue;
			if ($this->settings->is_excluded_sku($sku)) continue;

			$item = $this->product_to_state($product);
			if ($item) $items[] = $item;
		}

		if (empty($items)) return;

		$payload = array_merge([
			'event_type' => 'stock_state',
			'origin'     => $this->settings->is_master() ? 'master_local' : 'child_local',
			'time'       => time(),
			'context'    => $context,
			'items'      => $items,
		], $extra);

		$this->send_event($payload);
	}

	private function ensure_gid(WC_Product $product): string {
		$gid = (string) $product->get_meta(self::META_GID);

		if ($gid === '' && $this->settings->is_master()) {
			$gid = wp_generate_uuid4();
			self::$suppress_outbound = true;
			$product->update_meta_data(self::META_GID, $gid);
			$product->save();
			self::$suppress_outbound = false;
		}

		return $gid;
	}

	private function is_stock_syncable_product(WC_Product $product): bool {
		// External/Affiliate and Grouped products cannot be stock managed in WooCommerce.
		if (method_exists($product, 'is_type') && $product->is_type(['external', 'grouped'])) {
			return false;
		}
		return true;
	}

	private function product_to_state(WC_Product $product): ?array {
		$sku = (string) $product->get_sku();
		if ($sku === '') return null;

		if (!$this->is_stock_syncable_product($product)) return null;

		$gid = $this->settings->is_master()
			? $this->ensure_gid($product)
			: (string)$product->get_meta(self::META_GID);

		return [
			'gid'              => $gid,
			'sku'              => $sku,
			'manage_stock'     => true,
			'stock_quantity'   => (int) ($product->get_stock_quantity() ?? 0),
			'stock_status'     => (string) $product->get_stock_status(),
			'backorders'       => (string) $product->get_backorders(),
			'low_stock_amount' => (int) ($product->get_low_stock_amount() ?? 0),
		];
	}

	private function ensure_event_id(array &$payload, string $sender_store_id): void {
		if (!empty($payload['event_id'])) return;
		$tmp = $payload;
		unset($tmp['event_id']);
		$payload['event_id'] = hash('sha256', $sender_store_id . '|' . wp_json_encode($tmp));
	}

	private function seen_key(string $event_id): string {
		return 'kitgenix_stock_sync_for_woocommerce_kss_seen_' . md5($event_id);
	}

	private function is_duplicate_event(array $payload): bool {
		$event_id = (string)($payload['event_id'] ?? '');
		if ($event_id === '') return false;

		$key = $this->seen_key($event_id);
		if (get_transient($key)) return true;

		set_transient($key, 1, self::SEEN_EVENT_TTL);
		return false;
	}

	private function send_event(array $payload): void {
		if (self::$suppress_outbound) return;

		$this->ensure_event_id($payload, $this->settings->this_store_id());

		if ($this->settings->is_child()) {
			$this->child_send_event_to_master($payload, 1);
			return;
		}

		$this->enqueue_incoming_event($this->settings->this_store_id(), $payload, true);
	}

	private function child_send_event_to_master(array $payload, int $attempt): void {
		$master = $this->settings->master_config();
		$url    = rtrim((string) ($master['url'] ?? ''), '/');
		$secret = (string) ($master['secret'] ?? '');
		$mid    = (string) ($master['store_id'] ?? '');

		if ($url === '' || $secret === '' || $mid === '') {
			$this->settings->set_notice('error', 'Kitgenix Stock Sync: Master store is not configured (URL/Store ID/Secret).');
			$this->log('error', 'Child cannot send event: master config missing.');
			return;
		}

		$endpoint = $url . '/wp-json/kitgenix-stock-sync/v1/event';
		$body     = wp_json_encode($payload);
		$headers  = $this->security->sign_headers($secret, $this->settings->this_store_id(), $body);

		$response = wp_remote_post($endpoint, [
			'timeout' => 8,
			'headers' => $headers,
			'body'    => $body,
			'user-agent' => 'KitgenixStockSync/' . (defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION') ? KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION : '1.0.1'),
		]);

		if (is_wp_error($response)) {
			$err = $response->get_error_message();
			$this->settings->set_notice('error', 'Kitgenix Stock Sync: Could not reach Master. Changes queued for retry.');
			$this->log('error', 'Child send event failed: ' . $err);
			$this->mark_outbound_error($err);
			$this->schedule_retry_send_to_master($payload, $attempt + 1);
			return;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			$bodyr = (string) wp_remote_retrieve_body($response);
			$err = 'HTTP ' . $code;
			$this->settings->set_notice('error', 'Kitgenix Stock Sync: Master rejected update. Changes queued for retry.');
			$this->log('error', 'Child send event rejected: HTTP ' . $code . ' body=' . $bodyr);
			$this->mark_outbound_error($err . ' ' . $bodyr);
			$this->schedule_retry_send_to_master($payload, $attempt + 1);
			return;
		}

		$this->mark_outbound_success();
	}

	private function schedule_retry_send_to_master(array $payload, int $attempt): void {
		$attempt = max(1, $attempt);

		$delays = [60, 300, 900, 3600, 21600];
		$idx = min($attempt - 1, count($delays) - 1);
		$delay = $delays[$idx];

		if (function_exists('as_schedule_single_action')) {
			as_schedule_single_action(time() + $delay, 'kitgenix_stock_sync_for_woocommerce_retry_send_to_master', [$payload, $attempt], 'kitgenix-stock-sync');
		}
	}

	public function as_retry_send_to_master(array $payload, int $attempt): void {
		if (!$this->settings->is_child()) return;
		$this->child_send_event_to_master($payload, $attempt);
	}

	/** -----------------------------
	 * Incoming events
	 * ----------------------------- */

	public function enqueue_incoming_event(string $sender_store_id, array $payload, bool $is_local_master = false): bool|\WP_Error {
		if (!isset($payload['event_type']) || !is_string($payload['event_type'])) {
			return new WP_Error('kss_event_type', 'Missing event_type', ['status' => 400]);
		}

		$this->ensure_event_id($payload, $sender_store_id);
		$this->mark_inbound();

		$this->as_process_event($sender_store_id, $payload);

		return true;
	}

	public function as_process_event(string $sender_store_id, array $payload): void {
		if ($this->is_duplicate_event($payload)) {
			$this->log('info', 'Duplicate event ignored.', ['event_id' => (string)($payload['event_id'] ?? ''), 'sender' => $sender_store_id]);
			return;
		}

		$event_type = (string) ($payload['event_type'] ?? '');

		if ($this->settings->is_master()) {
			$this->master_process_event($sender_store_id, $payload);
			return;
		}

		if ($event_type === 'stock_state' || $event_type === 'sku_rename') {
			$this->apply_to_local_store($payload);
		}
	}

	private function master_process_event(string $sender_store_id, array $payload): void {
		$event_type = (string)($payload['event_type'] ?? '');
		$items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
		if (empty($items)) return;

		// Apply incoming to Master
		$this->apply_to_local_store($payload);

		// CRITICAL: Master must push AUTHORITATIVE state rebuilt from its own DB.
		// This guarantees backorders notify + low stock threshold + GIDs propagate correctly.
		if ($event_type === 'stock_state') {
			$payload = $this->master_rebuild_authoritative_stock_payload($payload);
		}

		foreach ($this->settings->children() as $child) {
			if (!is_array($child)) continue;
			if (!($child['enabled'] ?? true)) continue;

			$child_id  = (string) ($child['id'] ?? '');
			$child_url = (string) ($child['url'] ?? '');
			$secret    = (string) ($child['secret'] ?? '');

			if ($child_id === '' || $child_url === '' || $secret === '') continue;

			$this->as_push_to_store($child_id, $payload, 1);
		}
	}

	private function master_rebuild_authoritative_stock_payload(array $payload): array {
		$items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
		$auth_items = [];

		foreach ($items as $it) {
			if (!is_array($it)) continue;

			$gid = trim((string)($it['gid'] ?? ''));
			$sku = trim((string)($it['sku'] ?? ''));
			$old_sku = trim((string)($it['old_sku'] ?? ''));

			$product_id = 0;
			if ($gid !== '') $product_id = $this->find_product_id_by_gid($gid);
			if ($product_id <= 0 && $old_sku !== '') $product_id = (int) wc_get_product_id_by_sku($old_sku);
			if ($product_id <= 0 && $sku !== '') $product_id = (int) wc_get_product_id_by_sku($sku);
			if ($product_id <= 0) continue;

			$product = $this->get_fresh_product($product_id);
			if (!($product instanceof WC_Product)) continue;

			$state = $this->product_to_state($product); // ensures GID on master
			if ($state) $auth_items[] = $state;
		}

		$payload['origin'] = 'master_authoritative';
		$payload['context'] = (string)($payload['context'] ?? 'authoritative');
		$payload['items'] = $auth_items;

		return $payload;
	}

	private function enqueue_push_to_store(string $store_id, array $payload): void {
		if (function_exists('as_enqueue_async_action')) {
			as_enqueue_async_action('kitgenix_stock_sync_for_woocommerce_push_to_store', [$store_id, $payload, 1], 'kitgenix-stock-sync');
		} else {
			$this->as_push_to_store($store_id, $payload, 1);
		}
	}

	public function as_push_to_store(string $store_id, array $payload, int $attempt): void {
		$child = $this->settings->get_child_by_id($store_id);
		if (!$child) return;

		$store_url = (string) ($child['url'] ?? '');
		$secret    = (string) ($child['secret'] ?? '');
		if ($store_url === '' || $secret === '') return;

		$endpoint = rtrim($store_url, '/') . '/wp-json/kitgenix-stock-sync/v1/event';
		$body     = wp_json_encode($payload);
		$headers  = $this->security->sign_headers($secret, $this->settings->this_store_id(), $body);

		$response = wp_remote_post($endpoint, [
			'timeout' => 8,
			'headers' => $headers,
			'body'    => $body,
			'user-agent' => 'KitgenixStockSync/' . (defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION') ? KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION : '1.0.1'),
		]);

		if (is_wp_error($response)) {
			$err = $response->get_error_message();
			$this->log('error', 'Master push failed to ' . $store_url . ': ' . $err, ['store_id' => $store_id]);
			$this->settings->set_notice('error', 'Kitgenix Stock Sync: Some stores are offline. Backlog will retry automatically.');
			$this->settings->add_backlog('push', $store_id, $payload, $attempt, $err);
			$this->mark_outbound_error($err);
			$this->schedule_retry_push_to_store($store_id, $payload, $attempt + 1);
			return;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			$bodyr = (string) wp_remote_retrieve_body($response);
			$err = 'HTTP ' . $code;
			$this->log('error', 'Master push rejected by ' . $store_url . ': ' . $err . ' body=' . $bodyr, ['store_id' => $store_id]);
			$this->settings->set_notice('error', 'Kitgenix Stock Sync: Some stores rejected updates. Check Logs.');
			$this->settings->add_backlog('push', $store_id, $payload, $attempt, $err);
			$this->mark_outbound_error($err . ' ' . $bodyr);
			$this->schedule_retry_push_to_store($store_id, $payload, $attempt + 1);
			return;
		}

		$this->mark_outbound_success();
	}

	private function schedule_retry_push_to_store(string $store_id, array $payload, int $attempt): void {
		$attempt = max(1, $attempt);

		$delays = [60, 300, 900, 3600, 21600];
		$idx = min($attempt - 1, count($delays) - 1);
		$delay = $delays[$idx];

		if (function_exists('as_schedule_single_action')) {
			as_schedule_single_action(time() + $delay, 'kitgenix_stock_sync_for_woocommerce_retry_push_to_store', [$store_id, $payload, $attempt], 'kitgenix-stock-sync');
		}
	}

	public function as_retry_push_to_store(string $store_id, array $payload, int $attempt): void {
		if (!$this->settings->is_master()) return;
		$this->as_push_to_store($store_id, $payload, $attempt);
	}

	/** -----------------------------
	 * Apply stock/SKU locally
	 * ----------------------------- */

	private function apply_to_local_store(array $payload): void {
		$event_type = (string) ($payload['event_type'] ?? '');
		$items      = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
		if (empty($items)) return;

		self::$suppress_outbound = true;

		try {
			foreach ($items as $it) {
				if (!is_array($it)) continue;

				$gid     = trim((string) ($it['gid'] ?? ''));
				$sku     = trim((string) ($it['sku'] ?? ''));
				$old_sku = trim((string) ($it['old_sku'] ?? ''));

				if ($sku !== '' && $this->settings->is_excluded_sku($sku)) continue;

				$product_id = 0;

				if ($gid !== '') {
					$product_id = $this->find_product_id_by_gid($gid);
				}

				if ($product_id <= 0 && $event_type === 'sku_rename' && $old_sku !== '') {
					$product_id = (int) wc_get_product_id_by_sku($old_sku);
				}

				if ($product_id <= 0 && $sku !== '') {
					$product_id = (int) wc_get_product_id_by_sku($sku);
				}

				if ($product_id <= 0) {
					$this->log('warning', 'Missing product for incoming update. Skipping.', [
						'sku' => $sku, 'gid' => $gid, 'old_sku' => $old_sku
					]);
					continue;
				}

				$product = $this->get_fresh_product($product_id);
				if (!($product instanceof WC_Product)) continue;

				// Always persist gid if provided / or ensure master creates one.
				if ($this->settings->is_master() && $gid === '') {
					$gid = $this->ensure_gid($product);
				}
				if ($gid !== '') {
					$product->update_meta_data(self::META_GID, $gid);
				}

				// SKU rename is allowed for all types, so do it first and bail.
				if ($event_type === 'sku_rename') {
					if ($sku !== '') {
						try {
							$product->set_sku($sku);
							$product->save();
						} catch (\Throwable $e) {
							$this->log('error', 'SKU rename failed. Skipping item.', [
								'sku' => $sku, 'old_sku' => $old_sku, 'product_id' => $product_id, 'err' => $e->getMessage()
							]);
						}
					}
					continue;
				}

				// Stock updates: skip unsupported product types (external/grouped).
				if (!$this->is_stock_syncable_product($product)) {
					$this->log('warning', 'Incoming stock update skipped for unsupported product type.', [
						'sku' => $sku,
						'product_id' => $product_id,
						'type' => method_exists($product, 'get_type') ? $product->get_type() : get_class($product),
					]);
					continue;
				}

				// Stock fields (force manage_stock) - guard against WC exceptions.
				try {
					$product->set_manage_stock(true);

					if (isset($it['backorders'])) $product->set_backorders((string) $it['backorders']);
					if (isset($it['stock_status'])) $product->set_stock_status((string) $it['stock_status']);
					if (array_key_exists('stock_quantity', $it)) $product->set_stock_quantity((int) $it['stock_quantity']);
					if (isset($it['low_stock_amount'])) $product->set_low_stock_amount((int) $it['low_stock_amount']);

					$product->save();
				} catch (\Throwable $e) {
					$this->log('error', 'Stock apply failed. Skipping item.', [
						'sku' => $sku,
						'product_id' => $product_id,
						'type' => method_exists($product, 'get_type') ? $product->get_type() : get_class($product),
						'err' => $e->getMessage(),
					]);
					continue;
				}
			}
		} finally {
			self::$suppress_outbound = false;
		}
	}

	private function find_product_id_by_gid(string $gid): int {
		global $wpdb;
		/* phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.DB.SlowDBQuery.slow_db_query_meta_query */
		$gid = trim($gid);
		if ($gid === '') return 0;

		$cache_key = 'kitgenix_stock_sync_for_woocommerce_kss_gid_' . md5($gid);
		$cached = wp_cache_get($cache_key, 'kitgenix_stock_sync');
		if ($cached !== false) {
			return (int) $cached;
		}

		// Use WP_Query with `meta_key`/`meta_value` for an exact indexed lookup.
		$post_id = 0;
		// Exact lookup using indexed meta_key/meta_value. This is intentional and performant.
		$q = new WP_Query([
			'post_type' => ['product', 'product_variation'],
			'post_status' => 'any',
			'meta_key' => self::META_GID,
			'meta_value' => $gid,
			'fields' => 'ids',
			'posts_per_page' => 1,
			'no_found_rows' => true,
		]);
		if (!empty($q->posts) && is_array($q->posts)) {
			$post_id = (int) $q->posts[0];
		}

		wp_cache_set($cache_key, $post_id, 'kitgenix_stock_sync', HOUR_IN_SECONDS);

		return $post_id;
		/* phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.DB.SlowDBQuery.slow_db_query_meta_query */
	}

	/** -----------------------------
	 * Strict checkout validation (Child)
	 * ----------------------------- */

	public function child_checkout_validation($data, $errors): void {
		if (!($errors instanceof WP_Error)) return;

		if (!$this->settings->is_child()) return;
		if (!$this->settings->strict_checkout_validation()) return;

		$master = $this->settings->master_config();
		$url    = rtrim((string) ($master['url'] ?? ''), '/');
		$secret = (string) ($master['secret'] ?? '');
		$mid    = (string) ($master['store_id'] ?? '');
		if ($url === '' || $secret === '' || $mid === '') return;

		if (!WC()->cart) return;

		$needs = [];
		foreach (WC()->cart->get_cart() as $cart_item) {
			$product = $cart_item['data'] ?? null;
			if (!($product instanceof WC_Product)) continue;

			$sku = (string) $product->get_sku();
			if ($sku === '') continue;
			if ($this->settings->is_excluded_sku($sku)) continue;

			$qty = (int) ($cart_item['quantity'] ?? 0);
			if ($qty <= 0) continue;

			$needs[$sku] = ($needs[$sku] ?? 0) + $qty;
		}

		if (empty($needs)) return;

		$endpoint = $url . '/wp-json/kitgenix-stock-sync/v1/stock';
		$body = wp_json_encode([
			'items' => array_map(fn($sku) => ['sku' => $sku], array_keys($needs)),
		]);
		$headers = $this->security->sign_headers($secret, $this->settings->this_store_id(), $body);

		$response = wp_remote_post($endpoint, [
			'timeout' => 15,
			'headers' => $headers,
			'body'    => $body,
		]);

		if (is_wp_error($response)) {
			$this->log('warning', 'Checkout validation failed to reach master: ' . $response->get_error_message());
			return;
		}

		if ((int) wp_remote_retrieve_response_code($response) !== 200) return;

		$decoded = json_decode((string) wp_remote_retrieve_body($response), true);
		if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) return;

		foreach ($needs as $sku => $qty_needed) {
			$info = $decoded['items'][$sku] ?? null;
			if (!is_array($info) || empty($info['exists'])) continue;

			$qty_available = (int) ($info['stock_quantity'] ?? 0);
			$status        = (string) ($info['stock_status'] ?? '');
			$backorders    = (string) ($info['backorders'] ?? 'no');

			if ($status !== 'instock' && $backorders === 'no') {
				$errors->add('kss_oos_' . sanitize_key($sku), sprintf('“%s” is out of stock.', esc_html($sku)));
				continue;
			}

			if ($backorders === 'no' && $qty_needed > $qty_available) {
				$errors->add('kss_insufficient_' . sanitize_key($sku), sprintf('Insufficient stock for “%s”. Available: %d.', esc_html($sku), $qty_available));
			}
		}
	}

	/** -----------------------------
	 * Master stock lookup
	 * ----------------------------- */
	public function master_stock_lookup(array $items): array {
		$out = [];

		foreach ($items as $it) {
			if (!is_array($it)) continue;
			$sku = trim((string) ($it['sku'] ?? ''));
			if ($sku === '') continue;

			$id = (int) wc_get_product_id_by_sku($sku);
			if ($id <= 0) {
				$out[$sku] = ['exists' => false];
				continue;
			}

			$product = wc_get_product($id);
			if (!($product instanceof WC_Product)) {
				$out[$sku] = ['exists' => false];
				continue;
			}

			$out[$sku] = [
				'exists'         => true,
				'stock_quantity' => (int) ($product->get_stock_quantity() ?? 0),
				'stock_status'   => (string) $product->get_stock_status(),
				'backorders'     => (string) $product->get_backorders(),
			];
		}

		return $out;
	}

	/** -----------------------------
	 * Local stock lookup (any store) for audit endpoint
	 * ----------------------------- */
	public function local_stock_lookup(array $items): array {
		$out = [];

		foreach ($items as $it) {
			if (!is_array($it)) continue;
			$sku = trim((string)($it['sku'] ?? ''));
			if ($sku === '') continue;

			$id = (int) wc_get_product_id_by_sku($sku);
			if ($id <= 0) {
				$out[$sku] = ['exists' => false];
				continue;
			}

			$product = wc_get_product($id);
			if (!($product instanceof WC_Product)) {
				$out[$sku] = ['exists' => false];
				continue;
			}

			$out[$sku] = [
				'exists' => true,
				'stock_quantity' => (int)($product->get_stock_quantity() ?? 0),
				'stock_status' => (string)$product->get_stock_status(),
				'backorders' => (string)$product->get_backorders(),
				'low_stock_amount' => (int)($product->get_low_stock_amount() ?? 0),
				'manage_stock' => (bool)$product->get_manage_stock(),
			];
		}

		return $out;
	}

	/** -----------------------------
	 * Audit children (Master)
	 * ----------------------------- */
	public function master_audit_children_stock(array $skus): array {
		if (!$this->settings->is_master()) return [];

		$skus = array_values(array_filter(array_map('trim', $skus)));
		$skus = array_values(array_filter($skus, fn($s) => $s !== '' && !$this->settings->is_excluded_sku($s)));

		$master_state = [];
		foreach ($skus as $sku) {
			$id = (int) wc_get_product_id_by_sku($sku);
			if ($id <= 0) {
				$master_state[$sku] = ['exists' => false];
				continue;
			}
			$product = $this->get_fresh_product($id);
			if (!($product instanceof WC_Product)) {
				$master_state[$sku] = ['exists' => false];
				continue;
			}
			$st = $this->product_to_state($product); // ensures GID on master
			$master_state[$sku] = $st ? array_merge(['exists' => true], $st) : ['exists' => false];
		}

		$children_results = [];
		$mismatched_skus = [];

		foreach ($this->settings->children() as $child) {
			if (!is_array($child)) continue;

			$cid = (string)($child['id'] ?? '');
			$url = rtrim((string)($child['url'] ?? ''), '/');
			$secret = (string)($child['secret'] ?? '');
			$name = (string)($child['name'] ?? $cid);

			if ($cid === '' || $url === '' || $secret === '') continue;

			$endpoint = $url . '/wp-json/kitgenix-stock-sync/v1/stock-state';
			$body = wp_json_encode(['items' => array_map(fn($s) => ['sku' => $s], $skus)]);
			$headers = $this->security->sign_headers($secret, $this->settings->this_store_id(), $body);

			$res = wp_remote_post($endpoint, [
				'timeout' => 10,
				'headers' => $headers,
				'body' => $body,
			]);

			$cres = [
				'name' => $name,
				'url' => $url,
				'error' => '',
				'mismatches' => [],
			];

			if (is_wp_error($res)) {
				$cres['error'] = $res->get_error_message();
				$children_results[$cid] = $cres;
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code($res);
			if ($code !== 200) {
				$cres['error'] = 'HTTP ' . $code . ' ' . (string)wp_remote_retrieve_body($res);
				$children_results[$cid] = $cres;
				continue;
			}

			$decoded = json_decode((string)wp_remote_retrieve_body($res), true);
			$items = is_array($decoded['items'] ?? null) ? $decoded['items'] : [];

			foreach ($skus as $sku) {
				$ms = $master_state[$sku] ?? ['exists' => false];
				$cs = is_array($items[$sku] ?? null) ? $items[$sku] : ['exists' => false];

				if (empty($ms['exists']) && empty($cs['exists'])) continue;

				$fields = ['stock_quantity','stock_status','backorders','low_stock_amount','manage_stock'];
				foreach ($fields as $f) {
					$mv = $ms[$f] ?? '';
					$cv = $cs[$f] ?? '';
					if ((string)$mv !== (string)$cv) {
						$cres['mismatches'][$sku][$f] = ['master' => $mv, 'child' => $cv];
						$mismatched_skus[$sku] = true;
					}
				}
			}

			$children_results[$cid] = $cres;
		}

		return [
			'master' => $master_state,
			'children' => $children_results,
			'mismatched_skus' => array_values(array_keys($mismatched_skus)),
		];
	}

	/** -----------------------------
	 * Reconcile (Master only)
	 * ----------------------------- */

	public function start_reconcile(int $per_page = 200): void {
		if (!$this->settings->is_master()) return;

		$state = $this->settings->reconcile_state();
		$state['running'] = true;
		$state['page'] = 1;
		$state['per_page'] = max(50, min(500, $per_page));
		$this->settings->set_reconcile_state($state);

		$this->settings->set_health_value('last_reconcile_start', time());

		$this->settings->set_notice('success', 'Kitgenix Stock Sync: Reconcile started. First batch runs immediately; remaining batches run via Action Scheduler.');
		$this->log('info', 'Reconcile started.', ['per_page' => $state['per_page']]);

		$this->as_reconcile_batch(1, (int)$state['per_page']);
	}

	public function as_reconcile_batch(int $page, int $per_page): void {
		if (!$this->settings->is_master()) return;

		$state = $this->settings->reconcile_state();
		if (empty($state['running'])) return;

		$page = max(1, $page);
		$per_page = max(50, min(500, $per_page));

		$q = new WP_Query([
			'post_type' => ['product', 'product_variation'],
			'post_status' => 'publish',
			'fields' => 'ids',
			'posts_per_page' => $per_page,
			'paged' => $page,
			'orderby' => 'ID',
			'order' => 'ASC',
			'no_found_rows' => true,
		]);

		$ids = is_array($q->posts) ? $q->posts : [];
		if (empty($ids)) {
			$state['running'] = false;
			$state['last_run'] = time();
			$state['page'] = 0;
			$this->settings->set_reconcile_state($state);

			$this->settings->set_health_value('last_reconcile_complete', time());

			$this->settings->set_notice('success', 'Kitgenix Stock Sync: Reconcile completed.');
			$this->log('info', 'Reconcile completed.');
			return;
		}

		$items = [];
		foreach ($ids as $id) {
			$product = wc_get_product((int)$id);
			if (!($product instanceof WC_Product)) continue;

			$sku = (string) $product->get_sku();
			if ($sku === '') continue;
			if ($this->settings->is_excluded_sku($sku)) continue;

			$items[] = $this->product_to_state($product);
		}

		$payload = [
			'event_type' => 'stock_state',
			'origin' => 'master_reconcile',
			'time' => time(),
			'context' => 'reconcile',
			'items' => array_values(array_filter($items)),
		];

		$this->ensure_event_id($payload, $this->settings->this_store_id());

		foreach ($this->settings->children() as $child) {
			if (!is_array($child)) continue;
			if (!($child['enabled'] ?? true)) continue;
			$child_id = (string)($child['id'] ?? '');
			if ($child_id === '') continue;

			$this->enqueue_push_to_store($child_id, $payload);
		}

		$state['page'] = $page;
		$state['per_page'] = $per_page;
		$this->settings->set_reconcile_state($state);

		$this->log('info', 'Reconcile batch pushed.', ['page' => $page, 'count' => count($payload['items'])]);

		if (function_exists('as_schedule_single_action')) {
			as_schedule_single_action(time() + 5, 'kitgenix_stock_sync_for_woocommerce_reconcile_batch', [$page + 1, $per_page], 'kitgenix-stock-sync');
		}
	}

	public function master_push_skus(array $skus): void {
		if (!$this->settings->is_master()) return;

		$skus = array_values(array_filter(array_map('trim', $skus)));
		if (empty($skus)) return;

		$items = [];
		foreach ($skus as $sku) {
			if ($sku === '') continue;
			if ($this->settings->is_excluded_sku($sku)) continue;

			$id = (int) wc_get_product_id_by_sku($sku);
			if ($id <= 0) {
				$this->log('warning', 'Manual sync SKU not found.', ['sku' => $sku]);
				continue;
			}

			$product = wc_get_product($id);
			if (!($product instanceof WC_Product)) continue;

			$items[] = $this->product_to_state($product);
		}

		if (empty($items)) return;

		$payload = [
			'event_type' => 'stock_state',
			'origin' => 'master_manual',
			'time' => time(),
			'context' => 'manual_sku_sync',
			'items' => array_values(array_filter($items)),
		];

		$this->ensure_event_id($payload, $this->settings->this_store_id());

		foreach ($this->settings->children() as $child) {
			if (!is_array($child)) continue;
			if (!($child['enabled'] ?? true)) continue;
			$child_id = (string)($child['id'] ?? '');
			if ($child_id === '') continue;

			$this->enqueue_push_to_store($child_id, $payload);
		}

		$this->log('info', 'Manual SKU sync pushed.', ['count' => count($payload['items'])]);
	}
}
