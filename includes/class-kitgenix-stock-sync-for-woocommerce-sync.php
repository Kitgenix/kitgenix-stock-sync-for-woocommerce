<?php
defined('ABSPATH') || exit;

final class Kitgenix_Stock_Sync_For_WooCommerce_Sync {

	private Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings;
	private Kitgenix_Stock_Sync_For_WooCommerce_Security $security;

	private static bool $suppress_outbound = false;

	/** @var array<int,array<int,string>> product_id => context tags touched during this request */
	private static array $dirty = [];

	/**
	 * Order-driven stock deltas captured this request, keyed by idempotency
	 * key (store:order:item:op), each: ['product_id'=>int,'op'=>'increase'|'decrease','qty'=>int,'key'=>string].
	 * Flushed into the matching product's outbound item in flush_dirty_products()
	 * (see S1 in ROADMAP.md): these ride alongside the existing absolute
	 * stock_quantity snapshot so a receiving store that doesn't yet understand
	 * deltas still gets a correct value, while one that does applies the
	 * delta atomically instead of trusting a (possibly already stale) absolute
	 * number.
	 *
	 * @var array<string,array{product_id:int,op:string,qty:int,key:string}>
	 */
	private static array $pending_deltas = [];

	public const META_GID = '_kitgenix_stock_sync_for_woocommerce_gid';

	/** Master-authoritative monotonic version counter, bumped every authoritative capture. */
	public const META_VERSION = '_kitgenix_stock_sync_for_woocommerce_version';

	/** Last version a receiving store actually applied, for staleness fencing. */
	public const META_APPLIED_VERSION = '_kitgenix_stock_sync_for_woocommerce_applied_version';

	private const SEEN_EVENT_TTL = 24 * HOUR_IN_SECONDS;
	private const DEBOUNCE_TTL = 2; // seconds
	private const DIRTY_FLUSH_CHUNK = 500;
	private const RETRY_DELAYS = [60, 300, 900, 3600, 21600];
	private const MAX_DELIVERY_ATTEMPTS = 8;

	/**
	 * Index of last-known-good stock snapshots (sku => cached_at), so the
	 * Failover Readiness Report can show their age. The snapshots themselves
	 * are transients, which cannot be listed when an object cache is in use.
	 */
	public const SNAPSHOT_INDEX_OPTION = 'kitgenix_stock_sync_for_woocommerce_snapshot_index';
	private const SNAPSHOT_INDEX_CAP = 1000;
	private const SNAPSHOT_TTL = DAY_IN_SECONDS;

	/** @var array<string,int> snapshot index updates buffered for this request */
	private static array $snapshot_index_pending = [];

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

		// Order stock reduction and restoration (including WooCommerce 11.0's failed-order
		// restoration via wc_maybe_increase_stock_levels()) both write final stock through
		// wc_update_product_stock(), which already fires the hooks above. These two handlers
		// are defence-in-depth / observability only – they tag the coalesced event with an
		// 'order_processing'/'order_restore' context even if a future WC change ever bypassed
		// the granular per-item hooks for some product type.
		add_action('woocommerce_order_status_processing', [$this, 'on_order_status_processing'], 10, 1);
		add_action('woocommerce_restore_order_stock', [$this, 'on_restore_order_stock_after'], 10, 1);

		// The actual delta source for S1 (oversell fix): these fire once per
		// order, whatever status triggered the reduction/restoration (processing,
		// on-hold, a pending order's held stock expiring, a failed-order
		// restock...), after WooCommerce has already written the real local
		// change. Capture the per-line quantity so the event we send carries a
		// delta the Master can apply atomically, not just the resulting
		// absolute number.
		add_action('woocommerce_reduce_order_stock', [$this, 'on_reduce_order_stock'], 20, 1);
		add_action('woocommerce_restore_order_stock', [$this, 'on_capture_restore_order_stock_delta'], 5, 1);

		add_action('updated_post_meta', [$this, 'on_updated_post_meta'], 10, 4);

		add_filter('update_post_metadata', [$this, 'capture_old_sku_before_update'], 10, 5);
		add_action('updated_post_meta', [$this, 'on_sku_updated_post_meta'], 10, 4);

		add_action('woocommerce_after_checkout_validation', [$this, 'child_checkout_validation'], 10, 2);
		// The Checkout block bypasses woocommerce_after_checkout_validation, so run the same check on Store API checkout.
		add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'child_blocks_checkout_validation'], 10, 2);

		// Coalesce every product touched during this request into one event, sent once
		// the request is otherwise finished. See flush_dirty_products().
		add_action('shutdown', [$this, 'flush_dirty_products'], 20);
		add_action('shutdown', [$this, 'flush_snapshot_index'], 30);

		// A product made with WooCommerce's "Duplicate" action must get its own
		// sync identity, not a copy of the original's (a shared GID routes
		// updates to the wrong product – see the SKU Mapping Repair Tool).
		add_filter('woocommerce_duplicate_product_exclude_meta', [$this, 'exclude_identity_meta_from_duplicate']);
	}

	public function exclude_identity_meta_from_duplicate($meta_keys): array {
		$meta_keys = is_array($meta_keys) ? $meta_keys : [];
		return array_merge($meta_keys, [
			self::META_GID,
			self::META_VERSION,
			self::META_APPLIED_VERSION,
			Kitgenix_Stock_Sync_For_WooCommerce_History::META_KEY,
		]);
	}

	/**
	 * Run $fn with outbound capture suppressed, so writes it makes (e.g. a
	 * SKU Mapping Repair) are not broadcast as stock or SKU-rename events.
	 */
	public function with_outbound_suppressed(callable $fn): mixed {
		$previous = self::$suppress_outbound;
		self::$suppress_outbound = true;
		try {
			return $fn();
		} finally {
			self::$suppress_outbound = $previous;
		}
	}

	/**
	 * Delays (seconds) before each automatic retry of a failed delivery. The
	 * first attempt is immediate, so retries are attempts 2..MAX_DELIVERY_ATTEMPTS.
	 *
	 * @return int[]
	 */
	public static function retry_schedule(): array {
		$delays = [];
		for ($attempt = 2; $attempt <= self::MAX_DELIVERY_ATTEMPTS; $attempt++) {
			$delays[] = (int) self::retry_delay_for_attempt($attempt);
		}
		return $delays;
	}

	/** Total seconds a failed delivery keeps retrying before it is marked exhausted. */
	public static function retry_window_seconds(): int {
		return array_sum(self::retry_schedule());
	}


	private function logger(): WC_Logger {
		return wc_get_logger();
	}

	private function log(string $level, string $message, array $context = [], string $code = ''): void {
		$ctx = array_merge(['source' => 'kitgenix-stock-sync-for-woocommerce'], $context);
		$this->logger()->log($level, $message, $ctx);
		$this->settings->add_event_log($level, $message, $context, $code);
	}

	/**
	 * Level to log a coded event at: recoverable/transient codes are logged as
	 * 'warning' so they don't read as errors needing action. See
	 * Settings::is_recoverable_code().
	 */
	private function level_for_code(string $code, string $fallback = 'error'): string {
		return Kitgenix_Stock_Sync_For_WooCommerce_Settings::is_recoverable_code($code) ? 'warning' : $fallback;
	}

	/** Classify a failed wp_remote_* call as a timeout or a connection failure. */
	private function classify_wp_error(\WP_Error $err): string {
		$msg = strtolower($err->get_error_message());
		return (strpos($msg, 'timed out') !== false || strpos($msg, 'timeout') !== false) ? 'http_timeout' : 'http_connection_error';
	}

	/** Classify a non-2xx HTTP response: 5xx is treated as transient, 4xx as a genuine rejection. */
	private function classify_http_status(int $status): string {
		return $status >= 500 ? 'http_5xx' : 'http_4xx_rejected';
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
			'last_error_code' => '',
		]);
	}

	private function mark_outbound_error(string $message, string $code = ''): void {
		$this->settings->set_health([
			'last_outbound_error' => time(),
			'last_error_message' => $message,
			'last_error_code' => $code,
		]);
	}

	private function mark_inbound(): void {
		$this->settings->set_health_value('last_inbound_event', time());
	}

	/** -----------------------------
	 * Outbound capture: mark products dirty; flush_dirty_products() coalesces
	 * and sends once per request. See hooks() for why suppression here is
	 * enough (no separate "in order" flag needed – buffer dedup makes the
	 * ordering of woocommerce_reduce_order_stock/product_set_stock irrelevant).
	 * ----------------------------- */

	public function on_product_set_stock($product): void {
		if (self::$suppress_outbound) return;
		if (!($product instanceof WC_Product)) return;

		$sku = (string) $product->get_sku();
		if ($sku !== '' && !$this->debounce_allow('stock_set|' . $sku)) return;

		$this->mark_dirty((int) $product->get_id(), 'stock_set');
	}

	public function on_product_set_stock_status($product_id, $status, $product): void {
		if (self::$suppress_outbound) return;
		if (!($product instanceof WC_Product)) return;

		$sku = (string) $product->get_sku();
		if ($sku !== '' && !$this->debounce_allow('stock_status|' . $sku)) return;

		$this->mark_dirty((int) $product->get_id(), 'stock_status');
	}

	public function on_product_object_updated_props($product, $updated_props): void {
		if (self::$suppress_outbound) return;
		if (!($product instanceof WC_Product)) return;
		if (!is_array($updated_props)) return;

		$watched = ['stock_quantity', 'stock_status', 'manage_stock', 'backorders', 'low_stock_amount'];
		if (empty(array_intersect($watched, $updated_props))) return;

		$this->mark_dirty((int) $product->get_id(), 'props_update');
	}

	public function on_order_status_processing($order_id): void {
		if (self::$suppress_outbound) return;
		$this->as_process_order_processing((int) $order_id);
	}

	public function as_process_order_processing(int $order_id): void {
		if (self::$suppress_outbound) return;

		$order = wc_get_order($order_id);
		if (!($order instanceof WC_Order)) return;
		if ($order->get_status() !== 'processing') return;

		foreach ($order->get_items() as $item) {
			if (!($item instanceof WC_Order_Item_Product)) continue;
			$product = $item->get_product();
			if ($product instanceof WC_Product) {
				$this->mark_dirty((int) $product->get_id(), 'order_processing');
			}
		}
	}

	public function on_restore_order_stock_after($order_id): void {
		if (self::$suppress_outbound) return;

		$order = wc_get_order($order_id);
		if (!($order instanceof WC_Order)) return;

		foreach ($order->get_items() as $item) {
			if (!($item instanceof WC_Order_Item_Product)) continue;
			$product = $item->get_product();
			if ($product instanceof WC_Product) {
				$this->mark_dirty((int) $product->get_id(), 'order_restore');
			}
		}
	}

	/**
	 * S1 delta capture (decrease side). Fires once per order, after
	 * WooCommerce has reduced stock for every line item via its own atomic
	 * wc_update_product_stock(..., 'decrease'), whatever order status
	 * triggered the reduction. `_reduced_stock` is the exact quantity
	 * WooCommerce itself just deducted for that item (it sets this meta
	 * specifically so a later restore knows how much to put back), so it is
	 * the correct delta to propagate – not a value we recompute ourselves.
	 */
	public function on_reduce_order_stock($order): void {
		if (self::$suppress_outbound) return;
		if (!($order instanceof WC_Order)) return;

		$order_id = (int) $order->get_id();

		foreach ($order->get_items() as $item) {
			if (!($item instanceof WC_Order_Item_Product)) continue;

			$product = $item->get_product();
			if (!($product instanceof WC_Product)) continue;
			if (!$this->is_stock_syncable_product($product)) continue;
			// Only for a product/variation that manages its own stock directly.
			// A variation inheriting from its parent ('parent') actually has
			// its parent's stock reduced, not the line item's own product
			// object, and attributing the delta to the wrong post would be
			// worse than the bug this fixes – effective_manage_stock() is the
			// same helper this class already uses to handle that case faithfully.
			if ($this->effective_manage_stock($product) !== true) continue;

			$reduced = (int) $item->get_meta('_reduced_stock');
			if ($reduced <= 0) continue; // nothing was actually reduced for this line

			$this->queue_delta((int) $product->get_id(), $order_id, (int) $item->get_id(), 'decrease', $reduced);
			$this->mark_dirty((int) $product->get_id(), 'order_processing');
		}
	}

	/**
	 * S1 delta capture (increase side). WooCommerce clears each item's
	 * `_reduced_stock` meta as part of the same restore pass that fires this
	 * action, so by the time it reaches us that meta may already read zero –
	 * unlike the reduce side, we use the order line's own quantity, which is
	 * exactly what wc_maybe_increase_stock_levels() itself restores by.
	 */
	public function on_capture_restore_order_stock_delta($order): void {
		if (self::$suppress_outbound) return;
		if (!($order instanceof WC_Order)) return;

		$order_id = (int) $order->get_id();

		foreach ($order->get_items() as $item) {
			if (!($item instanceof WC_Order_Item_Product)) continue;

			$product = $item->get_product();
			if (!($product instanceof WC_Product)) continue;
			if (!$this->is_stock_syncable_product($product)) continue;
			if ($this->effective_manage_stock($product) !== true) continue;

			$qty = (int) apply_filters('woocommerce_order_item_quantity', $item->get_quantity(), $order, $item);
			if ($qty <= 0) continue;

			$this->queue_delta((int) $product->get_id(), $order_id, (int) $item->get_id(), 'increase', $qty);
		}
	}

	/** Buffer one order-line delta for this request; merged into the matching product's outbound item in maybe_send_stock_state(). */
	private function queue_delta(int $product_id, int $order_id, int $item_id, string $op, int $qty): void {
		if ($product_id <= 0 || $qty <= 0) return;
		if (!in_array($op, ['increase', 'decrease'], true)) return;

		$key = $this->settings->this_store_id() . ':' . $order_id . ':' . $item_id . ':' . $op;
		self::$pending_deltas[$key] = [
			'product_id' => $product_id,
			'op'         => $op,
			'qty'        => $qty,
			'key'        => $key,
		];
	}

	/** Every pending delta captured this request for one product, in wire-item shape. */
	private function collect_pending_deltas(int $product_id): array {
		$out = [];
		foreach (self::$pending_deltas as $d) {
			if ((int) ($d['product_id'] ?? 0) !== $product_id) continue;
			$out[] = ['key' => (string) $d['key'], 'op' => (string) $d['op'], 'qty' => (int) $d['qty']];
		}
		return $out;
	}

	private function delta_seen_key(string $key): string {
		return 'kitgenix_stock_sync_for_woocommerce_kss_delta_' . md5($key);
	}

	/** Has this exact (store, order, item, op) delta already been applied here? Guards against a retried/duplicate delivery double-decrementing or double-incrementing stock. */
	private function is_duplicate_delta(string $key): bool {
		if ($key === '') return false;
		return (bool) get_transient($this->delta_seen_key($key));
	}

	private function mark_delta_seen(string $key): void {
		if ($key === '') return;
		// Comfortably longer than the full retry window (~7.4h today - see
		// RETRY_DELAYS/retry_window_seconds()), so a delta stays protected for
		// every automatic retry attempt and any reasonable manual retry.
		set_transient($this->delta_seen_key($key), 1, 7 * DAY_IN_SECONDS);
	}

	public function on_updated_post_meta($meta_id, $post_id, $meta_key, $meta_value): void {
		if (self::$suppress_outbound) return;

		$post_type = get_post_type($post_id);
		if (!in_array($post_type, ['product', 'product_variation'], true)) return;

		$whitelist = ['_stock', '_stock_status', '_backorders', '_manage_stock', '_low_stock_amount'];
		if (!in_array($meta_key, $whitelist, true)) return;

		$this->mark_dirty((int) $post_id, 'meta_update');
	}

	public function capture_old_sku_before_update($check, $object_id, $meta_key, $meta_value, $prev_value) {
		if (!$this->settings->is_master()) return $check;
		if ($meta_key !== '_sku') return $check;

		$post_type = get_post_type($object_id);
		if (!in_array($post_type, ['product', 'product_variation'], true)) return $check;

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- retrieving a single meta value by post ID is acceptable here
		$old = (string) get_post_meta((int) $object_id, '_sku', true);
		if ($old !== '') {
			set_transient('kitgenix_stock_sync_for_woocommerce_kss_old_sku_' . (int) $object_id, $old, 60);
		}
		return $check;
	}

	public function on_sku_updated_post_meta($meta_id, $post_id, $meta_key, $meta_value): void {
		if (self::$suppress_outbound) return;
		if (!$this->settings->is_master()) return;
		if ($meta_key !== '_sku') return;

		$post_type = get_post_type($post_id);
		if (!in_array($post_type, ['product', 'product_variation'], true)) return;

		$new_sku = trim((string) $meta_value);
		if ($new_sku === '') return;

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single meta lookup by post ID is acceptable here
		$gid = (string) get_post_meta((int) $post_id, self::META_GID, true);
		if ($gid === '') {
			$gid = wp_generate_uuid4();
			update_post_meta((int) $post_id, self::META_GID, $gid);
		}

		$old_sku = (string) get_transient('kitgenix_stock_sync_for_woocommerce_kss_old_sku_' . (int) $post_id);
		delete_transient('kitgenix_stock_sync_for_woocommerce_kss_old_sku_' . (int) $post_id);

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

	/** -----------------------------
	 * Coalescing buffer
	 * ----------------------------- */

	private function mark_dirty(int $product_id, string $context): void {
		if ($product_id <= 0) return;
		if (self::$suppress_outbound) return; // never buffer writes made by our own apply_to_local_store()

		if (!isset(self::$dirty[$product_id])) {
			self::$dirty[$product_id] = [];
		}
		if (!in_array($context, self::$dirty[$product_id], true)) {
			self::$dirty[$product_id][] = $context;
		}

		if (count(self::$dirty) >= self::DIRTY_FLUSH_CHUNK) {
			$this->flush_dirty_products();
		}
	}

	/**
	 * Send one coalesced stock-state event for every product touched during
	 * this request, instead of one per hook firing. Runs on `shutdown` so it
	 * fires for admin, front-end, REST, WP-CLI, and Action Scheduler-run
	 * requests alike, and captures each product's truly final state (not an
	 * intermediate snapshot from mid-request).
	 */
	public function flush_dirty_products(): void {
		if (empty(self::$dirty)) return;
		if (self::$suppress_outbound) {
			self::$dirty = [];
			return;
		}

		$ids = array_keys(self::$dirty);
		$contexts = [];
		foreach (self::$dirty as $ctx_list) {
			foreach ($ctx_list as $c) $contexts[$c] = true;
		}
		self::$dirty = [];

		$products = [];
		foreach ($ids as $id) {
			$product = $this->get_fresh_product((int) $id);
			if ($product instanceof WC_Product) $products[] = $product;
		}

		if (empty($products)) {
			self::$pending_deltas = [];
			return;
		}

		$this->maybe_send_stock_state($products, implode('+', array_keys($contexts)) ?: 'stock_change');
		self::$pending_deltas = [];
	}

	private function maybe_send_stock_state(array $products, string $context, array $extra = []): void {
		$items = [];
		$product_ids = [];
		$held = [];

		foreach ($products as $product) {
			if (!($product instanceof WC_Product)) continue;

			$sku = (string) $product->get_sku();
			if ($sku === '') continue;
			if ($this->settings->is_excluded_sku($sku)) continue;
			if ($this->settings->is_held_sku($sku)) {
				$held[] = $sku;
				continue;
			}

			$item = $this->product_to_state($product, false);
			if ($item) {
				$pid = (int) $product->get_id();
				$deltas = $this->collect_pending_deltas($pid);
				if (!empty($deltas)) {
					$item['deltas'] = $deltas;
				}
				$items[] = $item;
				$product_ids[] = $pid;
			}
		}

		if (!empty($held)) {
			$this->log('warning', 'Local stock change not sent: SKU is on a sync hold until its identity is repaired.', ['skus' => implode(', ', $held), 'context' => $context], 'kss_sku_held');
		}

		if (empty($items)) return;

		$payload = $this->build_stock_state_payload($items, $context, $extra);

		// On the Master the authoritative entry (stamped in master_process_event)
		// already records this change, so only a Child records the raw capture.
		if ($this->settings->is_child()) {
			foreach ($items as $i => $item) {
				Kitgenix_Stock_Sync_For_WooCommerce_History::record($product_ids[$i], 'captured', $item, [
					'context' => $context,
					'store_id' => $this->settings->this_store_id(),
					'event_id' => (string) $payload['event_id'],
				]);
			}
		}

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

	/**
	 * Real manage_stock value for state purposes. A variation can inherit
	 * stock management from its parent – WC's get_manage_stock() returns the
	 * string 'parent' in that case. We must record and apply that faithfully;
	 * forcing independent management onto an inheriting variation would
	 * silently change its behaviour on both ends of the sync.
	 *
	 * @return bool|string
	 */
	private function effective_manage_stock(WC_Product $product) {
		if (method_exists($product, 'get_manage_stock')) {
			$ms = $product->get_manage_stock();
			if ($ms === 'parent') return 'parent';
			return (bool) $ms;
		}
		return true;
	}

	/**
	 * Atomically bump this product's authoritative version counter and return
	 * the new value. Race-safe for the common case: an existing counter row
	 * is incremented by a single UPDATE statement under normal InnoDB row
	 * locking (mirrors WooCommerce core's own direct-SQL stock-locking
	 * pattern; no new table). The first-ever bump for a product relies on
	 * add_post_meta()'s own unique-insert guard – a true concurrent
	 * first-bump race can in the worst case leave one harmless orphaned meta
	 * row (get_post_meta(..., true) deterministically reads the other, lowest
	 * meta_id, row), never an incorrect or regressed version number.
	 */
	private function bump_and_get_version(int $product_id): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$affected = $wpdb->query($wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
			$product_id,
			self::META_VERSION
		));
		// phpcs:enable

		if ((int) $affected < 1) {
			// First authoritative stamp on this store. Continue from the last
			// version it applied (if it was a Child promoted to Master after a
			// failover); starting again at 1 would make every Child ignore its
			// updates as stale.
			add_post_meta($product_id, self::META_VERSION, $this->get_applied_version($product_id) + 1, true);
		}

		wp_cache_delete($product_id, 'post_meta');
		$version = (int) get_post_meta($product_id, self::META_VERSION, true);
		return $version > 0 ? $version : 1;
	}

	/**
	 * Pure staleness decision: should an incoming item carrying $incoming
	 * version be skipped because $applied is already the same or newer?
	 * Extracted as a public static method so apply_to_local_store() has a
	 * single source of truth for the production-readiness verdict for
	 * delayed/duplicate/replayed events.
	 */
	public static function is_stale_version(int $incoming, int $applied): bool {
		return $incoming > 0 && $incoming <= $applied;
	}

	/**
	 * Pure retry-policy decision: the delay (seconds) before the next attempt,
	 * or null once the bounded retry budget is exhausted. Extracted for the
	 * same reason as is_stale_version() above.
	 */
	public static function retry_delay_for_attempt(int $attempt): ?int {
		$attempt = max(1, $attempt);
		if ($attempt > self::MAX_DELIVERY_ATTEMPTS) {
			return null;
		}
		$idx = min($attempt - 1, count(self::RETRY_DELAYS) - 1);
		return self::RETRY_DELAYS[$idx];
	}

	private function get_applied_version(int $product_id): int {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single meta lookup by post ID
		return (int) get_post_meta($product_id, self::META_APPLIED_VERSION, true);
	}

	private function set_applied_version(int $product_id, int $version): void {
		update_post_meta($product_id, self::META_APPLIED_VERSION, $version);
	}

	/**
	 * Build a stock-state item for one product.
	 *
	 * $authoritative = true means "this is the value about to be broadcast as
	 * the authoritative Master state" – only then is the version counter
	 * bumped (Master only). Any other caller (a Child's own outbound capture,
	 * an audit comparison) gets the current stored version for reference
	 * without bumping it – Master always re-derives and stamps the real
	 * authoritative version centrally in master_rebuild_authoritative_stock_payload().
	 *
	 * $history (context/store_id/event_id) records the stamped version in
	 * the product's Stock History when the state is authoritative.
	 */
	private function product_to_state(WC_Product $product, bool $authoritative = false, ?array $history = null): ?array {
		$sku = (string) $product->get_sku();
		if ($sku === '') return null;

		if (!$this->is_stock_syncable_product($product)) return null;

		$gid = $this->settings->is_master()
			? $this->ensure_gid($product)
			: (string) $product->get_meta(self::META_GID);

		$state = [
			'gid'              => $gid,
			'sku'              => $sku,
			'manage_stock'     => $this->effective_manage_stock($product),
			'stock_quantity'   => (int) ($product->get_stock_quantity() ?? 0),
			'stock_status'     => (string) $product->get_stock_status(),
			'backorders'       => (string) $product->get_backorders(),
			'low_stock_amount' => (int) ($product->get_low_stock_amount() ?? 0),
		];

		if ($authoritative && $this->settings->is_master()) {
			$state['version'] = $this->bump_and_get_version((int) $product->get_id());
			$state['updated_at'] = time();
			if ($history !== null) {
				Kitgenix_Stock_Sync_For_WooCommerce_History::record((int) $product->get_id(), 'authoritative', $state, $history);
			}
		} else {
			$existing_version = (int) $product->get_meta(self::META_VERSION);
			if ($existing_version > 0) {
				$state['version'] = $existing_version;
			}
		}

		return $state;
	}

	/** -----------------------------
	 * Event envelope
	 * ----------------------------- */

	private function ensure_event_id(array &$payload, string $sender_store_id): void {
		if (empty($payload['event_id'])) {
			$payload['event_id'] = wp_generate_uuid4();
		}
		if (empty($payload['origin_store_id'])) {
			$payload['origin_store_id'] = $sender_store_id;
		}
		if (empty($payload['created_at'])) {
			$payload['created_at'] = time();
		}
		if (!isset($payload['delivery_attempt'])) {
			$payload['delivery_attempt'] = 1;
		}
		// Bumped to 3 for S1 (delta-based stock events, see ROADMAP.md): a
		// stock_state item may now carry a 'deltas' array. A store still on
		// schema 2 simply ignores that key and applies the absolute
		// 'stock_quantity' field exactly as before, so a mixed-version
		// network keeps syncing (with 2's oversell risk) until every store
		// upgrades.
		$payload['schema_version'] = 3;
	}

	private function build_stock_state_payload(array $items, string $context, array $extra = []): array {
		$payload = array_merge([
			'event_type' => 'stock_state',
			'origin'     => $this->settings->is_master() ? 'master_local' : 'child_local',
			'time'       => time(),
			'context'    => $context,
			'items'      => $items,
		], $extra);

		$this->ensure_event_id($payload, $this->settings->this_store_id());

		return $payload;
	}

	private function seen_key(string $event_id): string {
		return 'kitgenix_stock_sync_for_woocommerce_kss_seen_' . md5($event_id);
	}

	private function is_duplicate_event(array $payload): bool {
		$event_id = (string) ($payload['event_id'] ?? '');
		if ($event_id === '') return false;

		$key = $this->seen_key($event_id);
		if (get_transient($key)) return true;

		set_transient($key, 1, self::SEEN_EVENT_TTL);
		return false;
	}

	/** -----------------------------
	 * Dispatch (async via Action Scheduler; see main plugin file for the
	 * hook wiring of kitgenix_stock_sync_for_woocommerce_push_to_store and
	 * kitgenix_stock_sync_for_woocommerce_send_to_master).
	 * ----------------------------- */

	private function send_event(array $payload): void {
		if (self::$suppress_outbound) return;

		$this->ensure_event_id($payload, $this->settings->this_store_id());

		if ($this->settings->is_child()) {
			$this->enqueue_send_to_master($payload);
			return;
		}

		$this->enqueue_incoming_event($this->settings->this_store_id(), $payload, true);
	}

	private function enqueue_send_to_master(array $payload): void {
		if (function_exists('as_enqueue_async_action')) {
			as_enqueue_async_action('kitgenix_stock_sync_for_woocommerce_send_to_master', [$payload, 1, ''], 'kitgenix-stock-sync');
		} else {
			$this->child_send_event_to_master($payload, 1, '');
		}
	}

	private function enqueue_push_to_store(string $store_id, array $payload, string $backlog_id = ''): void {
		if (function_exists('as_enqueue_async_action')) {
			as_enqueue_async_action('kitgenix_stock_sync_for_woocommerce_push_to_store', [$store_id, $payload, 1, $backlog_id], 'kitgenix-stock-sync');
		} else {
			$this->as_push_to_store($store_id, $payload, 1, $backlog_id);
		}
	}

	private function record_backlog_failure(string $type, string $store_id, array $payload, int $attempt, string $error, string $code, string $existing_id): string {
		if ($existing_id !== '') {
			$this->settings->update_backlog_item($existing_id, ['attempt' => $attempt, 'error' => $error, 'code' => $code, 'status' => 'pending']);
			return $existing_id;
		}
		return $this->settings->add_backlog($type, $store_id, $payload, $attempt, $error, $code);
	}

	private function child_send_event_to_master(array $payload, int $attempt, string $backlog_id = ''): void {
		$master = $this->settings->master_config();
		$url    = rtrim((string) ($master['url'] ?? ''), '/');
		$secret = (string) ($master['secret'] ?? '');
		$mid    = (string) ($master['store_id'] ?? '');

		if ($url === '' || $secret === '' || $mid === '') {
			$this->settings->set_notice('error', 'Kitgenix Stock Sync: Master store is not configured (URL/Store ID/Secret).');
			$this->log('error', 'Child cannot send event: master config missing.', [], 'config_error');
			return;
		}

		$payload['delivery_attempt'] = $attempt;

		$endpoint = $url . '/wp-json/kitgenix-stock-sync/v1/event';
		$body     = wp_json_encode($payload);
		$headers  = $this->security->sign_headers($secret, $this->settings->this_store_id(), $body, $endpoint);

		$response = wp_remote_post($endpoint, [
			'timeout' => 8,
			'headers' => $headers,
			'body'    => $body,
			'user-agent' => 'KitgenixStockSync/' . (defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION') ? KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION : '2.2.0'),
		]);

		if (is_wp_error($response)) {
			$err = $response->get_error_message();
			$err_code = $this->classify_wp_error($response);
			$this->settings->set_notice('error', 'Kitgenix Stock Sync: Could not reach Master. Changes queued for retry.');
			$this->log($this->level_for_code($err_code), 'Child send event failed: ' . $err, [], $err_code);
			$bid = $this->record_backlog_failure('send', $mid, $payload, $attempt, $err, $err_code, $backlog_id);
			$this->mark_outbound_error($err, $err_code);
			$this->settings->set_master_health(['last_outbound_error' => time(), 'last_error_message' => $err, 'last_error_code' => $err_code]);
			$this->schedule_retry_send_to_master($payload, $attempt + 1, $bid);
			return;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			$bodyr = (string) wp_remote_retrieve_body($response);
			$err = 'HTTP ' . $code;
			$err_code = $this->classify_http_status($code);
			$this->settings->set_notice('error', 'Kitgenix Stock Sync: Master rejected update. Changes queued for retry.');
			$this->log($this->level_for_code($err_code), 'Child send event rejected: HTTP ' . $code . ' body=' . $bodyr, [], $err_code);
			$bid = $this->record_backlog_failure('send', $mid, $payload, $attempt, $err . ' ' . $bodyr, $err_code, $backlog_id);
			$this->mark_outbound_error($err . ' ' . $bodyr, $err_code);
			$this->settings->set_master_health(['last_outbound_error' => time(), 'last_error_message' => $err, 'last_error_code' => $err_code]);
			$this->schedule_retry_send_to_master($payload, $attempt + 1, $bid);
			return;
		}

		$this->mark_outbound_success();
		$this->settings->set_master_health(['last_outbound_success' => time(), 'last_error_message' => '', 'last_error_code' => '']);
		if ($backlog_id !== '') $this->settings->remove_backlog_item($backlog_id);
	}

	private function schedule_retry_send_to_master(array $payload, int $attempt, string $backlog_id): void {
		$attempt = max(1, $attempt);
		$delay = self::retry_delay_for_attempt($attempt);

		if ($delay === null) {
			if ($backlog_id !== '') {
				$this->settings->update_backlog_item($backlog_id, ['status' => 'exhausted']);
			}
			$this->log('error', 'Send-to-master exhausted its retry budget and will not be retried automatically.', ['backlog_id' => $backlog_id], 'config_error');
			return;
		}

		if ($backlog_id !== '') {
			// 'attempt' here must match the args just scheduled below (not the attempt
			// that just failed) so discard_backlog_item()'s best-effort as_unschedule_action()
			// call can find and cancel this exact pending action by matching args.
			$this->settings->update_backlog_item($backlog_id, ['next_retry_at' => time() + $delay, 'attempt' => $attempt]);
		}

		if (function_exists('as_schedule_single_action')) {
			as_schedule_single_action(time() + $delay, 'kitgenix_stock_sync_for_woocommerce_send_to_master', [$payload, $attempt, $backlog_id], 'kitgenix-stock-sync');
		}
	}

	public function as_send_to_master(array $payload, int $attempt, string $backlog_id = ''): void {
		if (!$this->settings->is_child()) return;
		$this->child_send_event_to_master($payload, $attempt, $backlog_id);
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
		if (!$is_local_master) {
			$this->settings->set_child_health($sender_store_id, ['last_inbound' => time()]);
			$this->settings->set_master_health(['last_inbound' => time()]);
		}

		$this->as_process_event($sender_store_id, $payload);

		return true;
	}

	public function as_process_event(string $sender_store_id, array $payload): void {
		if ($this->is_duplicate_event($payload)) {
			$this->log('info', 'Duplicate event ignored.', ['event_id' => (string) ($payload['event_id'] ?? ''), 'sender' => $sender_store_id]);
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
		$event_type = (string) ($payload['event_type'] ?? '');
		$items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
		if (empty($items)) return;

		// Apply incoming to Master. A self-originated event (the Master's own
		// local change, looped back through the same pipeline a Child uses)
		// has already been written to the DB by WooCommerce itself before our
		// hooks even ran, so re-applying its 'deltas' here a second time would
		// double-decrement/double-increment stock. Absolute fields are still
		// safe to re-apply (setting a value to itself is a no-op), so only the
		// deltas sub-step is skipped for a self-originated event.
		$is_self_originated = ($sender_store_id === $this->settings->this_store_id());
		$this->apply_to_local_store($payload, $is_self_originated);

		// CRITICAL: Master must push AUTHORITATIVE state rebuilt from its own DB.
		// This guarantees backorders notify + low stock threshold + GIDs + version
		// counters propagate correctly.
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

			// Don't echo the event straight back to the child that just sent it – it
			// already has this state, and skipping it is what prevents a feedback loop
			// for an integration (e.g. WooCommerce Square) driving a child's local stock.
			if ($child_id === $sender_store_id) continue;

			$this->enqueue_push_to_store($child_id, $payload);
		}
	}

	private function master_rebuild_authoritative_stock_payload(array $payload): array {
		$items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
		$auth_items = [];
		$history = [
			'context' => (string) ($payload['context'] ?? ''),
			'store_id' => (string) ($payload['origin_store_id'] ?? ''),
			'event_id' => (string) ($payload['event_id'] ?? ''),
		];

		foreach ($items as $it) {
			if (!is_array($it)) continue;

			$gid = trim((string) ($it['gid'] ?? ''));
			$sku = trim((string) ($it['sku'] ?? ''));
			$old_sku = trim((string) ($it['old_sku'] ?? ''));

			$product_id = 0;
			if ($gid !== '') $product_id = $this->find_product_id_by_gid($gid);
			if ($product_id <= 0 && $old_sku !== '') $product_id = (int) wc_get_product_id_by_sku($old_sku);
			if ($product_id <= 0 && $sku !== '') $product_id = (int) wc_get_product_id_by_sku($sku);
			if ($product_id <= 0) continue;

			$product = $this->get_fresh_product($product_id);
			if (!($product instanceof WC_Product)) continue;
			if ($this->settings->is_held_sku((string) $product->get_sku())) continue;

			$state = $this->product_to_state($product, true, $history); // authoritative: bumps version, ensures GID
			if ($state) $auth_items[] = $state;
		}

		$payload['origin'] = 'master_authoritative';
		$payload['context'] = (string) ($payload['context'] ?? 'authoritative');
		$payload['items'] = $auth_items;

		return $payload;
	}

	public function as_push_to_store(string $store_id, array $payload, int $attempt, string $backlog_id = ''): void {
		$child = $this->settings->get_child_by_id($store_id);
		if (!$child) return;

		$store_url = (string) ($child['url'] ?? '');
		$secret    = (string) ($child['secret'] ?? '');
		if ($store_url === '' || $secret === '') return;

		$payload['delivery_attempt'] = $attempt;

		$endpoint = rtrim($store_url, '/') . '/wp-json/kitgenix-stock-sync/v1/event';
		$body     = wp_json_encode($payload);
		$headers  = $this->security->sign_headers($secret, $this->settings->this_store_id(), $body, $endpoint);

		$response = wp_remote_post($endpoint, [
			'timeout' => 8,
			'headers' => $headers,
			'body'    => $body,
			'user-agent' => 'KitgenixStockSync/' . (defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION') ? KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION : '2.2.0'),
		]);

		if (is_wp_error($response)) {
			$err = $response->get_error_message();
			$err_code = $this->classify_wp_error($response);
			$this->settings->set_notice('error', 'Kitgenix Stock Sync: Some stores are offline. Backlog will retry automatically.');
			$this->log($this->level_for_code($err_code), 'Master push failed to ' . $store_url . ': ' . $err, ['store_id' => $store_id], $err_code);
			$bid = $this->record_backlog_failure('push', $store_id, $payload, $attempt, $err, $err_code, $backlog_id);
			$this->mark_outbound_error($err, $err_code);
			$this->settings->set_child_health($store_id, ['last_outbound_error' => time(), 'last_error_message' => $err, 'last_error_code' => $err_code]);
			$this->schedule_retry_push_to_store($store_id, $payload, $attempt + 1, $bid);
			return;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			$bodyr = (string) wp_remote_retrieve_body($response);
			$err = 'HTTP ' . $code;
			$err_code = $this->classify_http_status($code);
			$this->settings->set_notice('error', 'Kitgenix Stock Sync: Some stores rejected updates. Check Logs.');
			$this->log($this->level_for_code($err_code), 'Master push rejected by ' . $store_url . ': ' . $err . ' body=' . $bodyr, ['store_id' => $store_id], $err_code);
			$bid = $this->record_backlog_failure('push', $store_id, $payload, $attempt, $err . ' ' . $bodyr, $err_code, $backlog_id);
			$this->mark_outbound_error($err . ' ' . $bodyr, $err_code);
			$this->settings->set_child_health($store_id, ['last_outbound_error' => time(), 'last_error_message' => $err, 'last_error_code' => $err_code]);
			$this->schedule_retry_push_to_store($store_id, $payload, $attempt + 1, $bid);
			return;
		}

		$this->mark_outbound_success();
		$this->settings->set_child_health($store_id, ['last_outbound_success' => time(), 'last_error_message' => '', 'last_error_code' => '']);
		if ($backlog_id !== '') $this->settings->remove_backlog_item($backlog_id);
	}

	private function schedule_retry_push_to_store(string $store_id, array $payload, int $attempt, string $backlog_id): void {
		$attempt = max(1, $attempt);
		$delay = self::retry_delay_for_attempt($attempt);

		if ($delay === null) {
			if ($backlog_id !== '') {
				$this->settings->update_backlog_item($backlog_id, ['status' => 'exhausted']);
			}
			$this->log('error', 'Push to store exhausted its retry budget and will not be retried automatically.', ['store_id' => $store_id, 'backlog_id' => $backlog_id], 'config_error');
			return;
		}

		if ($backlog_id !== '') {
			// See the matching comment in schedule_retry_send_to_master(): 'attempt' must
			// match the args scheduled below so discard can find and cancel this action.
			$this->settings->update_backlog_item($backlog_id, ['next_retry_at' => time() + $delay, 'attempt' => $attempt]);
		}

		if (function_exists('as_schedule_single_action')) {
			as_schedule_single_action(time() + $delay, 'kitgenix_stock_sync_for_woocommerce_push_to_store', [$store_id, $payload, $attempt, $backlog_id], 'kitgenix-stock-sync');
		}
	}

	/** -----------------------------
	 * Backlog: manual retry / discard (admin + WP-CLI)
	 * ----------------------------- */

	public function retry_backlog_item(string $id): bool {
		$row = $this->settings->get_backlog_item($id);
		if (!$row) return false;

		$payload = $row['payload'] ?? null;
		if (!is_array($payload)) {
			$this->log('error', 'Cannot retry backlog item: the original payload was too large to retain.', ['id' => $id], 'config_error');
			return false;
		}

		$type = (string) ($row['type'] ?? '');
		$store_id = (string) ($row['store_id'] ?? '');

		$this->settings->update_backlog_item($id, ['status' => 'pending']);

		if ($type === 'push' && $this->settings->is_master()) {
			$this->as_push_to_store($store_id, $payload, 1, $id);
			return true;
		}
		if ($type === 'send' && $this->settings->is_child()) {
			$this->child_send_event_to_master($payload, 1, $id);
			return true;
		}

		return false;
	}

	/** Discard a backlog row and best-effort cancel its pending Action Scheduler retry. */
	public function discard_backlog_item(string $id): bool {
		$row = $this->settings->get_backlog_item($id);
		if (!$row) return false;

		if (function_exists('as_unschedule_action')) {
			$type = (string) ($row['type'] ?? '');
			$hook = 'kitgenix_stock_sync_for_woocommerce_' . ($type === 'push' ? 'push_to_store' : 'send_to_master');
			$args = $type === 'push'
				? [(string) ($row['store_id'] ?? ''), $row['payload'] ?? [], (int) ($row['attempt'] ?? 1), $id]
				: [$row['payload'] ?? [], (int) ($row['attempt'] ?? 1), $id];
			as_unschedule_action($hook, $args, 'kitgenix-stock-sync');
		}

		return $this->settings->remove_backlog_item($id);
	}

	/** -----------------------------
	 * Apply stock/SKU locally
	 * ----------------------------- */

	private function apply_to_local_store(array $payload, bool $skip_deltas = false): void {
		$event_type = (string) ($payload['event_type'] ?? '');
		$items      = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
		if (empty($items)) return;

		$history_meta = [
			'context' => (string) ($payload['context'] ?? ''),
			'store_id' => (string) ($payload['origin_store_id'] ?? ''),
			'event_id' => (string) ($payload['event_id'] ?? ''),
		];
		// The Master re-applies its own local changes to itself (a no-op); that
		// is already recorded as the authoritative entry, so don't record it twice.
		$record_history = !($this->settings->is_master() && $history_meta['store_id'] === $this->settings->this_store_id());

		self::$suppress_outbound = true;

		try {
			foreach ($items as $it) {
				if (!is_array($it)) continue;

				$gid     = trim((string) ($it['gid'] ?? ''));
				$sku     = trim((string) ($it['sku'] ?? ''));
				$old_sku = trim((string) ($it['old_sku'] ?? ''));

				if ($sku !== '' && $this->settings->is_excluded_sku($sku)) continue;

				if ($this->settings->is_held_sku($sku) || $this->settings->is_held_sku($old_sku)) {
					$this->log('warning', 'Incoming update not applied: SKU is on a sync hold until its identity is repaired.', [
						'sku' => $sku, 'old_sku' => $old_sku, 'event_type' => $event_type,
					], 'kss_sku_held');
					continue;
				}

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
					], 'config_error');
					continue;
				}

				// Matched by SKU although the product holds a different GID: the
				// mapping is being re-established (e.g. after a SKU Mapping Repair).
				// Its applied version belongs to the old identity, so reset it –
				// otherwise genuine updates could be skipped as stale forever.
				if ($gid !== '' && !$this->settings->is_master()) {
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single meta lookup by post ID
					$existing_gid = (string) get_post_meta($product_id, self::META_GID, true);
					if ($existing_gid !== '' && $existing_gid !== $gid) {
						$this->set_applied_version($product_id, 0);
						$this->forget_gid_lookup($existing_gid);
						$this->log('info', 'Product re-mapped to the Master GID; applied version reset.', [
							'sku' => $sku, 'old_gid' => $existing_gid, 'gid' => $gid,
						]);
					}
				}

				// Versioned staleness fencing: an item carrying a version that is <= what
				// we've already applied for this product is a delayed, duplicate, replayed,
				// or out-of-order event. Skip it rather than regress already-current stock.
				// Items with no version field (an older peer plugin version, or a non-
				// authoritative capture) are applied without this check.
				if ($event_type === 'stock_state' && array_key_exists('version', $it)) {
					$incoming_version = (int) $it['version'];
					$applied_version = $this->get_applied_version($product_id);
					if (self::is_stale_version($incoming_version, $applied_version)) {
						$this->log('info', 'Stale stock update ignored (a newer or equal version was already applied).', [
							'sku' => $sku, 'gid' => $gid, 'incoming_version' => $incoming_version, 'applied_version' => $applied_version,
						], 'kss_stale_version');
						if ($record_history) {
							Kitgenix_Stock_Sync_For_WooCommerce_History::record($product_id, 'skipped_stale', $it, $history_meta + [
								'note' => sprintf('Already applied version %d.', $applied_version),
							]);
						}
						continue;
					}
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
							if ($record_history) {
								Kitgenix_Stock_Sync_For_WooCommerce_History::record($product_id, 'sku_rename', [], $history_meta + [
									'note' => $old_sku . ' → ' . $sku,
								]);
							}
						} catch (\Throwable $e) {
							$this->log('error', 'SKU rename failed. Skipping item.', [
								'sku' => $sku, 'old_sku' => $old_sku, 'product_id' => $product_id, 'err' => $e->getMessage()
							], 'config_error');
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
					], 'config_error');
					continue;
				}

				$incoming_manage_stock = array_key_exists('manage_stock', $it) ? $it['manage_stock'] : true;

				try {
					if ($incoming_manage_stock === 'parent') {
						// Variation inherits stock management from its parent – never force
						// independent management onto it. The parent owns real stock in this
						// mode; the parent's own event carries the authoritative numbers.
						if (method_exists($product, 'set_manage_stock')) {
							$product->set_manage_stock('parent');
						}
						$product->save();
					} else {
						$product->set_manage_stock((bool) $incoming_manage_stock);
						if (isset($it['backorders'])) $product->set_backorders((string) $it['backorders']);
						if (isset($it['low_stock_amount'])) $product->set_low_stock_amount((int) $it['low_stock_amount']);

						// S1 (oversell fix): an item carrying 'deltas' came from a
						// store sending order-driven reduce/restore amounts instead
						// of an absolute snapshot. Apply each one atomically via
						// WooCommerce's own increase/decrease stock function – the
						// same direct, row-locked write WooCommerce itself uses –
						// instead of overwriting stock_quantity outright, so a
						// second concurrent sale (from this store, another Child, or
						// the Master itself) in the same sync window is never lost.
						$deltas = (!$skip_deltas && isset($it['deltas']) && is_array($it['deltas'])) ? $it['deltas'] : [];
						$applied_delta = false;

						if (!empty($deltas)) {
							// Persist manage_stock/backorders/low_stock_amount first;
							// wc_update_product_stock() re-fetches the product below.
							$product->save();

							foreach ($deltas as $d) {
								if (!is_array($d)) continue;
								$dkey = (string) ($d['key'] ?? '');
								$dop  = (string) ($d['op'] ?? '');
								$dqty = (int) ($d['qty'] ?? 0);
								if ($dkey === '' || $dqty <= 0 || !in_array($dop, ['increase', 'decrease'], true)) continue;

								if ($this->is_duplicate_delta($dkey)) {
									$this->log('info', 'Duplicate stock delta ignored (already applied).', [
										'sku' => $sku, 'key' => $dkey,
									], 'kss_duplicate_delta');
									continue;
								}

								wc_update_product_stock($product, $dqty, $dop);
								$this->mark_delta_seen($dkey);
								$applied_delta = true;
							}

							if ($applied_delta) {
								$fresh = $this->get_fresh_product($product_id);
								if ($fresh instanceof WC_Product) $product = $fresh;
							}

							// Recompute status from the product's own fresh, post-delta
							// quantity rather than trusting the sender's $it['stock_status']
							// – that value was computed before the sender knew about this
							// (or any other concurrent) delta, so trusting it here could
							// reintroduce the same staleness S1 fixes for quantity.
							if ($incoming_manage_stock !== 'parent') {
								$qty = (int) ($product->get_stock_quantity() ?? 0);
								$backorders = (string) $product->get_backorders();
								$product->set_stock_status($qty > 0 ? 'instock' : ($backorders !== 'no' ? 'onbackorder' : 'outofstock'));
							}
							$product->save();
						} else {
							if (isset($it['stock_status'])) $product->set_stock_status((string) $it['stock_status']);
							if (array_key_exists('stock_quantity', $it)) $product->set_stock_quantity((int) $it['stock_quantity']);
							$product->save();
						}

						if (!$this->settings->is_master()) {
							$this->cache_stock_snapshot([$sku => [
								'exists' => true,
								'stock_quantity' => (int) $product->get_stock_quantity(),
								'stock_status' => (string) $product->get_stock_status(),
								'backorders' => (string) $product->get_backorders(),
							]]);
						}
					}

					if ($event_type === 'stock_state' && array_key_exists('version', $it)) {
						$this->set_applied_version($product_id, (int) $it['version']);
					}

					if ($record_history) {
						Kitgenix_Stock_Sync_For_WooCommerce_History::record($product_id, 'applied', [
							'version' => (int) ($it['version'] ?? 0),
							'stock_quantity' => $product->get_stock_quantity(),
							'stock_status' => (string) $product->get_stock_status(),
							'backorders' => (string) $product->get_backorders(),
						], $history_meta + [
							'note' => $incoming_manage_stock === 'parent' ? 'Stock managed by parent product.' : '',
						]);
					}
				} catch (\Throwable $e) {
					$this->log('error', 'Stock apply failed. Skipping item.', [
						'sku' => $sku,
						'product_id' => $product_id,
						'type' => method_exists($product, 'get_type') ? $product->get_type() : get_class($product),
						'err' => $e->getMessage(),
					], 'config_error');
					continue;
				}
			}
		} finally {
			self::$suppress_outbound = false;
		}
	}

	private function gid_cache_key(string $gid): string {
		return 'kitgenix_stock_sync_for_woocommerce_kss_gid_' . md5(trim($gid));
	}

	/** Drop the cached GID → product lookup, e.g. after a GID is reissued. */
	public function forget_gid_lookup(string $gid): void {
		if (trim($gid) === '') return;
		wp_cache_delete($this->gid_cache_key($gid), 'kitgenix_stock_sync');
	}

	private function find_product_id_by_gid(string $gid): int {
		global $wpdb;
		/* phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.DB.SlowDBQuery.slow_db_query_meta_query */
		$gid = trim($gid);
		if ($gid === '') return 0;

		$cache_key = $this->gid_cache_key($gid);
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

		$master = $this->settings->master_config();
		$url    = rtrim((string) ($master['url'] ?? ''), '/');
		$secret = (string) ($master['secret'] ?? '');
		$mid    = (string) ($master['store_id'] ?? '');
		$strategy = $this->settings->checkout_validation_failure_strategy();

		$decoded = null;

		if ($url !== '' && $secret !== '' && $mid !== '') {
			$endpoint = $url . '/wp-json/kitgenix-stock-sync/v1/stock';
			$body = wp_json_encode([
				'items' => array_map(fn($sku) => ['sku' => $sku], array_keys($needs)),
			]);
			$headers = $this->security->sign_headers($secret, $this->settings->this_store_id(), $body, $endpoint);

			$response = wp_remote_post($endpoint, [
				'timeout' => 15,
				'headers' => $headers,
				'body'    => $body,
			]);

			if (is_wp_error($response)) {
				$err = $response->get_error_message();
				$this->log('warning', 'Checkout validation failed to reach master: ' . $err);
				$this->settings->set_master_health(['last_outbound_error' => time(), 'last_error_message' => $err, 'last_error_code' => $this->classify_wp_error($response)]);
			} elseif ((int) wp_remote_retrieve_response_code($response) === 200) {
				$body_decoded = json_decode((string) wp_remote_retrieve_body($response), true);
				if (is_array($body_decoded) && isset($body_decoded['items']) && is_array($body_decoded['items'])) {
					$decoded = $body_decoded;
					$this->cache_stock_snapshot($decoded['items']);
					$this->settings->set_master_health(['last_outbound_success' => time(), 'last_error_message' => '', 'last_error_code' => '']);
				}
			} else {
				$err = 'HTTP ' . (int) wp_remote_retrieve_response_code($response);
				$this->settings->set_master_health(['last_outbound_error' => time(), 'last_error_message' => $err, 'last_error_code' => $this->classify_http_status((int) wp_remote_retrieve_response_code($response))]);
			}
		}

		if ($decoded === null) {
			// Master unreachable, not configured, or gave a bad response – apply the
			// merchant-configured strategy. A definitive rejection from a reachable
			// Master (handled below once $decoded is set) is never overridden by this.
			if ($strategy === 'fail_closed') {
				$errors->add('kss_master_unreachable', __('Stock could not be verified right now. Please try again shortly.', 'kitgenix-stock-sync-for-woocommerce'));
				return;
			}

			if ($strategy === 'stale_cache') {
				$cached_items = $this->stale_cache_snapshot(array_keys($needs));
				if (empty($cached_items)) return; // nothing usable cached either – fail open
				$decoded = ['items' => $cached_items];
			} else {
				return; // fail_open (default)
			}
		}

		foreach ($needs as $sku => $qty_needed) {
			$info = $decoded['items'][$sku] ?? null;
			if (!is_array($info) || empty($info['exists'])) continue;

			$qty_available = (int) ($info['stock_quantity'] ?? 0);
			$status        = (string) ($info['stock_status'] ?? '');
			$backorders    = (string) ($info['backorders'] ?? 'no');

			if ($status !== 'instock' && $backorders === 'no') {
				$errors->add('kss_oos_' . sanitize_key($sku), sprintf(
					/* translators: %s: product SKU */
					__('"%s" is out of stock.', 'kitgenix-stock-sync-for-woocommerce'),
					esc_html($sku)
				));
				continue;
			}

			if ($backorders === 'no' && $qty_needed > $qty_available) {
				$errors->add('kss_insufficient_' . sanitize_key($sku), sprintf(
					/* translators: 1: product SKU, 2: quantity available */
					__('Insufficient stock for "%1$s". Available: %2$d.', 'kitgenix-stock-sync-for-woocommerce'),
					esc_html($sku),
					$qty_available
				));
			}
		}
	}

	/**
	 * Strict checkout validation for the Checkout block (Store API). Reuses
	 * child_checkout_validation() and rejects the request with a RouteException,
	 * which the Store API returns to the block as a checkout error notice.
	 */
	public function child_blocks_checkout_validation($order, $request): void {
		if (!($request instanceof WP_REST_Request) || $request->get_method() !== 'POST') return;
		if (!class_exists('\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException')) return;

		$errors = new WP_Error();
		$this->child_checkout_validation([], $errors);
		if (!$errors->has_errors()) return;

		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
			'kitgenix_stock_sync_checkout_validation',
			implode(' ', $errors->get_error_messages()), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Store API JSON error message; SKUs are already esc_html()'d by child_checkout_validation().
			409
		);
	}

	/** Cache a last-known-good stock snapshot per SKU for the `stale_cache` checkout strategy. */
	private function cache_stock_snapshot(array $items_by_sku): void {
		foreach ($items_by_sku as $sku => $info) {
			if (!is_array($info) || $sku === '') continue;
			set_transient(
				'kitgenix_stock_sync_for_woocommerce_kss_stockcache_' . md5((string) $sku),
				array_merge($info, ['cached_at' => time()]),
				self::SNAPSHOT_TTL
			);
			self::$snapshot_index_pending[(string) $sku] = time();
		}
	}

	/**
	 * Persist this request's snapshot index updates in one option write
	 * (on shutdown), rather than one write per applied item.
	 */
	public function flush_snapshot_index(): void {
		if (empty(self::$snapshot_index_pending)) return;

		$index = get_option(self::SNAPSHOT_INDEX_OPTION, []);
		$index = is_array($index) ? $index : [];
		foreach (self::$snapshot_index_pending as $sku => $time) {
			unset($index[$sku]); // re-append so the newest stay at the end
			$index[$sku] = $time;
		}
		self::$snapshot_index_pending = [];

		$cutoff = time() - self::SNAPSHOT_TTL;
		$index = array_filter($index, static fn($t) => (int) $t >= $cutoff);
		$index = array_slice($index, -self::SNAPSHOT_INDEX_CAP, null, true);

		update_option(self::SNAPSHOT_INDEX_OPTION, $index, false);
	}

	/**
	 * Age summary of the last-known-good stock snapshots, for the Failover
	 * Readiness Report. A snapshot is "usable" by the stale_cache checkout
	 * strategy while it is younger than checkout_stale_cache_minutes.
	 */
	public function stock_snapshot_summary(int $list_limit = 10): array {
		$index = get_option(self::SNAPSHOT_INDEX_OPTION, []);
		$index = is_array($index) ? $index : [];

		$now = time();
		$max_age = $this->settings->checkout_stale_cache_minutes() * 60;
		$ages = [];
		foreach ($index as $sku => $cached_at) {
			$age = $now - (int) $cached_at;
			if ($age > self::SNAPSHOT_TTL) continue; // transient has expired
			$ages[(string) $sku] = max(0, $age);
		}
		arsort($ages);

		$usable = count(array_filter($ages, static fn($a) => $a <= $max_age));
		$oldest = [];
		foreach (array_slice($ages, 0, max(0, $list_limit), true) as $sku => $age) {
			$oldest[] = ['sku' => (string) $sku, 'age' => $age, 'usable' => $age <= $max_age];
		}

		return [
			'count' => count($ages),
			'usable' => $usable,
			'expired_for_checkout' => count($ages) - $usable,
			'max_age' => $max_age,
			'newest_age' => empty($ages) ? null : min($ages),
			'oldest_age' => empty($ages) ? null : max($ages),
			'oldest' => $oldest,
		];
	}

	private function stale_cache_snapshot(array $skus): array {
		$max_age = $this->settings->checkout_stale_cache_minutes() * 60;
		$out = [];
		foreach ($skus as $sku) {
			$cached = get_transient('kitgenix_stock_sync_for_woocommerce_kss_stockcache_' . md5((string) $sku));
			if (!is_array($cached)) continue;
			$age = time() - (int) ($cached['cached_at'] ?? 0);
			if ($age > $max_age) continue;
			$out[$sku] = $cached;
		}
		return $out;
	}

	/** -----------------------------
	 * Master stock lookup (used by strict checkout validation)
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
	 * Local stock lookup (any store) for audit/conflict comparisons
	 * ----------------------------- */
	public function local_stock_lookup(array $items, bool $include_history = false): array {
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
				'exists' => true,
				'gid' => (string) $product->get_meta(self::META_GID),
				'stock_quantity' => (int) ($product->get_stock_quantity() ?? 0),
				'stock_status' => (string) $product->get_stock_status(),
				'backorders' => (string) $product->get_backorders(),
				'low_stock_amount' => (int) ($product->get_low_stock_amount() ?? 0),
				'manage_stock' => $this->effective_manage_stock($product),
			];

			if ($include_history) {
				$out[$sku]['version'] = (int) $product->get_meta(self::META_VERSION);
				$out[$sku]['applied_version'] = $this->get_applied_version($id);
				$out[$sku]['history'] = Kitgenix_Stock_Sync_For_WooCommerce_History::get($id);
			}
		}

		return $out;
	}

	/**
	 * Stock History per SKU across connected stores: this store's own
	 * history and current state, plus the same from every counterpart this
	 * store talks to (each enabled Child from the Master; the Master from a
	 * Child), fetched over the signed /stock-state route. Stores running a
	 * plugin version without history support return state only.
	 */
	public function stock_history_across_stores(string $sku): array {
		$sku = trim($sku);
		$stores = [];

		$local = $this->local_stock_lookup([['sku' => $sku]], true)[$sku] ?? ['exists' => false];
		$stores[] = [
			'store_id' => $this->settings->this_store_id(),
			'name' => $this->settings->this_store_name(),
			'role' => $this->settings->role(),
			'local' => true,
			'error' => '',
			'state' => $local,
			'history_supported' => true,
		];

		$targets = [];
		if ($this->settings->is_master()) {
			foreach ($this->settings->children() as $child) {
				if (!is_array($child) || !($child['enabled'] ?? true)) continue;
				$targets[] = ['config' => $child, 'store_id' => (string) ($child['id'] ?? ''), 'name' => (string) ($child['name'] ?? ''), 'role' => 'child'];
			}
		} else {
			$master = $this->settings->master_config();
			if (!empty($master['url'])) {
				$targets[] = ['config' => $master, 'store_id' => (string) ($master['store_id'] ?? ''), 'name' => __('Master', 'kitgenix-stock-sync-for-woocommerce'), 'role' => 'master'];
			}
		}

		foreach ($targets as $t) {
			[$items, $error] = $this->fetch_child_stock_state($t['config'], [$sku], ['include_history' => true]);
			$state = $error === '' ? ($items[$sku] ?? ['exists' => false]) : [];
			$stores[] = [
				'store_id' => $t['store_id'],
				'name' => $t['name'] !== '' ? $t['name'] : $t['store_id'],
				'role' => $t['role'],
				'local' => false,
				'error' => $error,
				'state' => $state,
				'history_supported' => is_array($state['history'] ?? null),
			];
		}

		// One merged timeline, newest first, so the same event_id can be followed
		// from the store that captured it to every store that applied it.
		$timeline = [];
		foreach ($stores as $store) {
			foreach ((array) ($store['state']['history'] ?? []) as $entry) {
				if (!is_array($entry)) continue;
				$entry['at_store'] = $store['name'];
				$entry['at_store_id'] = $store['store_id'];
				$timeline[] = $entry;
			}
		}
		usort($timeline, static fn($a, $b) => ((int) ($b['time'] ?? 0)) <=> ((int) ($a['time'] ?? 0)));

		return [
			'sku' => $sku,
			'generated_at' => time(),
			'stores' => $stores,
			'timeline' => $timeline,
		];
	}

	/** -----------------------------
	 * Conflict Dashboard: comparisons, classification, duplicate-SKU scan
	 * ----------------------------- */

	/**
	 * Query one child's live stock state for a batch of SKUs.
	 *
	 * @return array{0: array<string,array>, 1: string} [items_by_sku, error_message]
	 */
	private function fetch_child_stock_state(array $child, array $skus, array $extra = []): array {
		$url = rtrim((string) ($child['url'] ?? ''), '/');
		$secret = (string) ($child['secret'] ?? '');
		$cid = (string) ($child['id'] ?? '');
		if ($url === '' || $secret === '') return [[], 'Store not fully configured.'];

		$endpoint = $url . '/wp-json/kitgenix-stock-sync/v1/stock-state';
		$body = wp_json_encode(array_merge($extra, ['items' => array_map(fn($s) => ['sku' => $s], array_values(array_unique($skus)))]));
		$headers = $this->security->sign_headers($secret, $this->settings->this_store_id(), $body, $endpoint);

		$res = wp_remote_post($endpoint, ['timeout' => 10, 'headers' => $headers, 'body' => $body]);

		if (is_wp_error($res)) {
			$err = $res->get_error_message();
			if ($cid !== '') $this->settings->set_child_health($cid, ['last_outbound_error' => time(), 'last_error_message' => $err, 'last_error_code' => $this->classify_wp_error($res)]);
			return [[], $err];
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		if ($code !== 200) {
			$err = $code === 401 ? 'Authentication error (HTTP 401).' : ('HTTP ' . $code);
			if ($cid !== '') $this->settings->set_child_health($cid, ['last_outbound_error' => time(), 'last_error_message' => $err, 'last_error_code' => $this->classify_http_status($code)]);
			return [[], $err];
		}

		if ($cid !== '') $this->settings->set_child_health($cid, ['last_outbound_success' => time()]);

		$decoded = json_decode((string) wp_remote_retrieve_body($res), true);
		$items = is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
		return [$items, ''];
	}

	/**
	 * Classify differences between Master's items and one child's reported
	 * items into normalized conflict rows: missing_product, gid_mismatch,
	 * quantity_mismatch, backorder_mismatch, stock_status_mismatch,
	 * child_offline, auth_error.
	 */
	private function classify_conflicts(array $master_items, array $child_items_by_sku, string $child_error, array $child): array {
		$cid = (string) ($child['id'] ?? '');
		$cname = (string) ($child['name'] ?? $cid);
		$rows = [];

		if ($child_error !== '') {
			$type = (stripos($child_error, 'auth') !== false || strpos($child_error, '401') !== false) ? 'auth_error' : 'child_offline';
			$rows[] = ['type' => $type, 'sku' => '', 'gid' => '', 'child_id' => $cid, 'child_name' => $cname, 'master_value' => '', 'child_value' => '', 'detail' => $child_error];
			return $rows;
		}

		foreach ($master_items as $mi) {
			$sku = (string) ($mi['sku'] ?? '');
			if ($sku === '') continue;
			$ci = $child_items_by_sku[$sku] ?? ['exists' => false];

			if (empty($ci['exists'])) {
				$rows[] = ['type' => 'missing_product', 'sku' => $sku, 'gid' => (string) ($mi['gid'] ?? ''), 'child_id' => $cid, 'child_name' => $cname, 'master_value' => 'present', 'child_value' => 'missing', 'detail' => 'Not found on child by SKU.'];
				continue;
			}

			$child_gid = (string) ($ci['gid'] ?? '');
			$master_gid = (string) ($mi['gid'] ?? '');
			if ($child_gid !== '' && $master_gid !== '' && $child_gid !== $master_gid) {
				$rows[] = ['type' => 'gid_mismatch', 'sku' => $sku, 'gid' => $master_gid, 'child_id' => $cid, 'child_name' => $cname, 'master_value' => $master_gid, 'child_value' => $child_gid, 'detail' => 'GID differs between stores.'];
			}

			$fields = ['stock_quantity' => 'quantity_mismatch', 'backorders' => 'backorder_mismatch', 'stock_status' => 'stock_status_mismatch'];
			foreach ($fields as $f => $type) {
				$mv = $mi[$f] ?? '';
				$cv = $ci[$f] ?? '';
				if ((string) $mv !== (string) $cv) {
					$rows[] = ['type' => $type, 'sku' => $sku, 'gid' => $master_gid, 'child_id' => $cid, 'child_name' => $cname, 'master_value' => (string) $mv, 'child_value' => (string) $cv, 'detail' => ''];
				}
			}
		}

		return $rows;
	}

	/** Products/variations sharing the same SKU: a single bounded, indexed GROUP BY query. */
	public function find_duplicate_skus(int $limit = 200): array {
		global $wpdb;
		/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key */
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT pm.meta_value AS sku, COUNT(*) AS cnt
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_sku' AND pm.meta_value <> '' AND p.post_status <> 'trash'
			   AND p.post_type IN ('product','product_variation')
			 GROUP BY pm.meta_value
			 HAVING COUNT(*) > 1
			 ORDER BY cnt DESC
			 LIMIT %d",
			$limit
		), ARRAY_A);
		/* phpcs:enable */
		return is_array($rows) ? $rows : [];
	}

	/**
	 * GIDs carried by more than one product/variation – typically left behind
	 * by WooCommerce's "Duplicate" product action or an import, both of which
	 * copy postmeta. Same bounded GROUP BY shape as find_duplicate_skus().
	 */
	public function find_duplicate_gids(int $limit = 200): array {
		global $wpdb;
		/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key */
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT pm.meta_value AS gid, COUNT(*) AS cnt
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s AND pm.meta_value <> '' AND p.post_status <> 'trash'
			   AND p.post_type IN ('product','product_variation')
			 GROUP BY pm.meta_value
			 HAVING COUNT(*) > 1
			 ORDER BY cnt DESC
			 LIMIT %d",
			self::META_GID,
			$limit
		), ARRAY_A);
		/* phpcs:enable */
		return is_array($rows) ? $rows : [];
	}

	/** Fast conflict scan: duplicate SKUs on the Master. Feeds the Conflict Dashboard. */
	public function scan_for_conflicts(): void {
		if (!$this->settings->is_master()) return;

		$rows = [];
		foreach ($this->find_duplicate_skus() as $dup) {
			$rows[] = [
				'type' => 'duplicate_sku', 'sku' => (string) ($dup['sku'] ?? ''), 'gid' => '',
				'child_id' => '', 'child_name' => '', 'master_value' => (string) ($dup['cnt'] ?? ''), 'child_value' => '',
				'detail' => 'This SKU is used by more than one product/variation on the Master.',
			];
		}

		if (!empty($rows)) {
			$existing = $this->settings->get_conflicts_report();
			$this->settings->set_conflicts_report(array_slice(array_merge($existing['items'], $rows), -500));
		}

		$this->log('info', 'Conflict scan (duplicate SKUs) completed.', ['found' => count($rows)]);
	}

	/** -----------------------------
	 * Audit children (Master) – quick, synchronous, SKU-scoped
	 * ----------------------------- */
	public function master_audit_children_stock(array $skus): array {
		if (!$this->settings->is_master()) return [];

		$skus = array_values(array_filter(array_map('trim', $skus)));
		$skus = array_values(array_filter($skus, fn($s) => $s !== '' && !$this->settings->is_excluded_sku($s)));

		$master_items = [];
		foreach ($skus as $sku) {
			$id = (int) wc_get_product_id_by_sku($sku);
			if ($id <= 0) continue;
			$product = $this->get_fresh_product($id);
			if (!($product instanceof WC_Product)) continue;
			$st = $this->product_to_state($product, false);
			if ($st) $master_items[] = $st;
		}

		$master_state = [];
		foreach ($master_items as $st) {
			$master_state[$st['sku']] = array_merge(['exists' => true], $st);
		}
		foreach ($skus as $sku) {
			if (!isset($master_state[$sku])) $master_state[$sku] = ['exists' => false];
		}

		$children_results = [];
		$mismatched_skus = [];
		$all_conflicts = [];

		foreach ($this->settings->children() as $child) {
			if (!is_array($child)) continue;

			$cid = (string) ($child['id'] ?? '');
			$url = rtrim((string) ($child['url'] ?? ''), '/');
			$name = (string) ($child['name'] ?? $cid);

			if ($cid === '' || $url === '') continue;

			[$child_items, $error] = $this->fetch_child_stock_state($child, $skus);
			$rows = $this->classify_conflicts($master_items, $child_items, $error, $child);
			$all_conflicts = array_merge($all_conflicts, $rows);

			$cres = ['name' => $name, 'url' => $url, 'error' => $error, 'mismatches' => []];
			foreach ($rows as $r) {
				if ($r['sku'] === '') continue;
				$cres['mismatches'][$r['sku']][$r['type']] = ['master' => $r['master_value'], 'child' => $r['child_value']];
				$mismatched_skus[$r['sku']] = true;
			}
			$children_results[$cid] = $cres;
		}

		if (!empty($all_conflicts)) {
			$existing = $this->settings->get_conflicts_report();
			$this->settings->set_conflicts_report(array_slice(array_merge($existing['items'], $all_conflicts), -500));
		}

		return [
			'master' => $master_state,
			'children' => $children_results,
			'mismatched_skus' => array_values(array_keys($mismatched_skus)),
		];
	}

	/** -----------------------------
	 * Reconcile (Master only): all products or selected SKUs, optionally
	 * dry-run and/or differences-only, paginated/resumable.
	 * ----------------------------- */

	private function count_syncable_products(): int {
		$q = new WP_Query([
			'post_type' => ['product', 'product_variation'],
			'post_status' => 'publish',
			'fields' => 'ids',
			'posts_per_page' => 1,
		]);
		return (int) $q->found_posts;
	}

	public function start_reconcile(array $options = []): void {
		if (!$this->settings->is_master()) return;

		$per_page = max(50, min(500, (int) ($options['per_page'] ?? 200)));
		$mode = in_array(($options['mode'] ?? 'all'), ['all', 'selected'], true) ? $options['mode'] : 'all';
		$dry_run = !empty($options['dry_run']);
		$differences_only = !empty($options['differences_only']);
		$selected_skus = is_array($options['skus'] ?? null) ? array_values(array_filter(array_map('trim', $options['skus']))) : [];

		if ($mode === 'selected' && empty($selected_skus)) {
			$this->settings->set_notice('error', 'Kitgenix Stock Sync: Reconcile (Selected SKUs) needs at least one SKU.');
			return;
		}

		$state = $this->settings->reconcile_state();
		$state['running'] = true;
		$state['page'] = 1;
		$state['per_page'] = $per_page;
		$state['mode'] = $mode;
		$state['dry_run'] = $dry_run;
		$state['differences_only'] = $differences_only;
		$state['selected_skus'] = $selected_skus;
		$state['processed'] = 0;
		$state['total_estimate'] = $mode === 'selected' ? count($selected_skus) : $this->count_syncable_products();
		$state['differences_found'] = 0;
		$state['pushed_count'] = 0;
		$state['started_at'] = time();
		$state['finished_at'] = 0;
		$this->settings->set_reconcile_state($state);

		$this->settings->set_health_value('last_reconcile_start', time());

		$this->settings->set_notice('success', 'Kitgenix Stock Sync: Reconcile started. First batch runs immediately; remaining batches run via Action Scheduler.');
		$this->log('info', 'Reconcile started.', ['mode' => $mode, 'dry_run' => $dry_run, 'differences_only' => $differences_only, 'per_page' => $per_page]);

		$this->as_reconcile_batch(1, $per_page);
	}

	public function resume_reconcile(): void {
		if (!$this->settings->is_master()) return;
		$state = $this->settings->reconcile_state();
		if (empty($state['running'])) return;

		$page = max(1, (int) ($state['page'] ?? 1));
		$per_page = max(50, min(500, (int) ($state['per_page'] ?? 200)));
		$this->log('info', 'Reconcile resumed by admin.', ['page' => $page]);
		$this->as_reconcile_batch($page, $per_page);
	}

	public function cancel_reconcile(): void {
		if (!$this->settings->is_master()) return;
		$state = $this->settings->reconcile_state();
		$state['running'] = false;
		$state['finished_at'] = time();
		$this->settings->set_reconcile_state($state);
		$this->log('info', 'Reconcile cancelled by admin.');
	}

	public function as_reconcile_batch(int $page, int $per_page): void {
		if (!$this->settings->is_master()) return;

		$state = $this->settings->reconcile_state();
		if (empty($state['running'])) return;

		$page = max(1, $page);
		$per_page = max(1, min(500, $per_page));
		$mode = (string) ($state['mode'] ?? 'all');
		$dry_run = !empty($state['dry_run']);
		$differences_only = !empty($state['differences_only']);
		$selected_skus = is_array($state['selected_skus'] ?? null) ? $state['selected_skus'] : [];

		$ids = [];
		$batch_skus = [];
		if ($mode === 'selected') {
			$batch_skus = array_slice($selected_skus, ($page - 1) * $per_page, $per_page);
			$done = empty($batch_skus);
		} else {
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
			$done = empty($ids);
		}

		if ($done) {
			$state['running'] = false;
			$state['last_run'] = time();
			$state['finished_at'] = time();
			$state['page'] = 0;
			$this->settings->set_reconcile_state($state);

			$this->settings->set_health_value('last_reconcile_complete', time());

			$summary = sprintf(
				'Reconcile completed. Processed %d, %d differences found, %d items pushed.',
				(int) $state['processed'], (int) $state['differences_found'], (int) $state['pushed_count']
			);
			$this->settings->set_notice('success', 'Kitgenix Stock Sync: ' . $summary);
			$this->log('info', $summary);
			return;
		}

		$items = [];
		$history = ['context' => $dry_run ? 'reconcile_dry_run' : 'reconcile', 'store_id' => $this->settings->this_store_id()];
		if ($mode === 'selected') {
			foreach ($batch_skus as $sku) {
				if ($sku === '' || $this->settings->is_excluded_sku($sku) || $this->settings->is_held_sku($sku)) continue;
				$id = (int) wc_get_product_id_by_sku($sku);
				if ($id <= 0) continue;
				$product = $this->get_fresh_product($id);
				if (!($product instanceof WC_Product)) continue;
				$st = $this->product_to_state($product, true, $history);
				if ($st) $items[] = $st;
			}
			$processed_count = count($batch_skus);
		} else {
			foreach ($ids as $id) {
				$product = wc_get_product((int) $id);
				if (!($product instanceof WC_Product)) continue;
				$sku = (string) $product->get_sku();
				if ($sku === '' || $this->settings->is_excluded_sku($sku) || $this->settings->is_held_sku($sku)) continue;
				$st = $this->product_to_state($product, true, $history);
				if ($st) $items[] = $st;
			}
			$processed_count = count($ids);
		}

		$state['processed'] = (int) $state['processed'] + $processed_count;

		if (!empty($items)) {
			if ($dry_run || $differences_only) {
				$batch_conflicts = [];
				foreach ($this->settings->children() as $child) {
					if (!is_array($child) || !($child['enabled'] ?? true)) continue;
					$cid = (string) ($child['id'] ?? '');
					if ($cid === '') continue;

					[$child_items, $error] = $this->fetch_child_stock_state($child, array_column($items, 'sku'));
					$rows = $this->classify_conflicts($items, $child_items, $error, $child);
					$batch_conflicts = array_merge($batch_conflicts, $rows);

					if (!$dry_run && !empty($rows)) {
						$diff_skus = array_values(array_unique(array_column($rows, 'sku')));
						$to_push = array_values(array_filter($items, fn($it) => in_array($it['sku'], $diff_skus, true)));
						if (!empty($to_push)) {
							$push_payload = $this->build_stock_state_payload($to_push, 'reconcile');
							$this->enqueue_push_to_store($cid, $push_payload);
							$state['pushed_count'] = (int) $state['pushed_count'] + count($to_push);
						}
					}
				}

				if (!empty($batch_conflicts)) {
					$existing = $this->settings->get_conflicts_report();
					$this->settings->set_conflicts_report(array_slice(array_merge($existing['items'], $batch_conflicts), -500));
					$state['differences_found'] = (int) $state['differences_found'] + count($batch_conflicts);
				}
			} else {
				$payload = $this->build_stock_state_payload($items, 'reconcile');
				$children_count = 0;
				foreach ($this->settings->children() as $child) {
					if (!is_array($child) || !($child['enabled'] ?? true)) continue;
					$cid = (string) ($child['id'] ?? '');
					if ($cid === '') continue;
					$this->enqueue_push_to_store($cid, $payload);
					$children_count++;
				}
				$state['pushed_count'] = (int) $state['pushed_count'] + (count($items) * $children_count);
			}
		}

		$state['page'] = $page;
		$state['per_page'] = $per_page;
		$state['last_batch_at'] = time();
		$this->settings->set_reconcile_state($state);

		$this->log('info', 'Reconcile batch processed.', ['page' => $page, 'count' => count($items), 'mode' => $mode, 'dry_run' => $dry_run]);

		if (function_exists('as_schedule_single_action')) {
			as_schedule_single_action(time() + 5, 'kitgenix_stock_sync_for_woocommerce_reconcile_batch', [$page + 1, $per_page], 'kitgenix-stock-sync');
		}
	}

	/** -----------------------------
	 * Manual SKU sync (Master)
	 * ----------------------------- */
	public function master_push_skus(array $skus): void {
		if (!$this->settings->is_master()) return;

		$skus = array_values(array_filter(array_map('trim', $skus)));
		if (empty($skus)) return;

		$items = [];
		$history = ['context' => 'manual_sku_sync', 'store_id' => $this->settings->this_store_id()];
		foreach ($skus as $sku) {
			if ($sku === '' || $this->settings->is_excluded_sku($sku)) continue;
			if ($this->settings->is_held_sku($sku)) {
				$this->log('warning', 'Manual sync skipped: SKU is on a sync hold until its identity is repaired.', ['sku' => $sku], 'kss_sku_held');
				continue;
			}

			$id = (int) wc_get_product_id_by_sku($sku);
			if ($id <= 0) {
				$this->log('warning', 'Manual sync SKU not found.', ['sku' => $sku]);
				continue;
			}

			$product = $this->get_fresh_product($id);
			if (!($product instanceof WC_Product)) continue;

			$st = $this->product_to_state($product, true, $history);
			if ($st) $items[] = $st;
		}

		if (empty($items)) return;

		$payload = $this->build_stock_state_payload($items, 'manual_sku_sync');

		foreach ($this->settings->children() as $child) {
			if (!is_array($child)) continue;
			if (!($child['enabled'] ?? true)) continue;
			$child_id = (string) ($child['id'] ?? '');
			if ($child_id === '') continue;

			$this->enqueue_push_to_store($child_id, $payload);
		}

		$this->log('info', 'Manual SKU sync pushed.', ['count' => count($items)]);
	}

	/** -----------------------------
	 * Connection health: recurring ping (Master pings children; Child pings Master)
	 * ----------------------------- */
	public function as_health_ping(): void {
		if ($this->settings->is_master()) {
			foreach ($this->settings->children() as $child) {
				if (!is_array($child) || !($child['enabled'] ?? true)) continue;
				$this->ping_and_record_health($child, false);
			}
			return;
		}

		$master = $this->settings->master_config();
		if (empty($master['url']) || empty($master['secret'])) return;
		$this->ping_and_record_health($master, true);
	}

	private function ping_and_record_health(array $store, bool $is_master_target): void {
		$cid = (string) ($store['id'] ?? '');
		$url = rtrim((string) ($store['url'] ?? ''), '/');
		$secret = (string) ($store['secret'] ?? '');
		if ($url === '' || $secret === '') return;

		$endpoint = $url . '/wp-json/kitgenix-stock-sync/v1/ping';
		$body = wp_json_encode(['ping' => true]);
		$headers = $this->security->sign_headers($secret, $this->settings->this_store_id(), $body, $endpoint);

		$res = wp_remote_post($endpoint, ['timeout' => 8, 'headers' => $headers, 'body' => $body]);

		if (is_wp_error($res)) {
			$patch = ['last_outbound_error' => time(), 'last_error_message' => $res->get_error_message(), 'last_error_code' => $this->classify_wp_error($res)];
			$is_master_target ? $this->settings->set_master_health($patch) : $this->settings->set_child_health($cid, $patch);
			return;
		}

		$code = (int) wp_remote_retrieve_response_code($res);
		if ($code !== 200) {
			$patch = ['last_outbound_error' => time(), 'last_error_message' => 'HTTP ' . $code, 'last_error_code' => $this->classify_http_status($code)];
			$is_master_target ? $this->settings->set_master_health($patch) : $this->settings->set_child_health($cid, $patch);
			return;
		}

		$decoded = json_decode((string) wp_remote_retrieve_body($res), true);
		$patch = [
			'last_ping' => time(),
			'remote_wc_version' => is_array($decoded) ? (string) ($decoded['wc_version'] ?? '') : '',
			'remote_plugin_version' => is_array($decoded) ? (string) ($decoded['plugin_version'] ?? '') : '',
			// Child's failover readiness summary (2.2.0+), for the Master's report.
			'remote_failover' => is_array($decoded) && is_array($decoded['failover'] ?? null) ? $decoded['failover'] : null,
		];
		$is_master_target ? $this->settings->set_master_health($patch) : $this->settings->set_child_health($cid, $patch);
	}
}
