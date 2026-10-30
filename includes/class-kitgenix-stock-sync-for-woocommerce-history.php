<?php
defined('ABSPATH') || exit;

/**
 * Stock History per SKU: a short, capped, versioned record of the stock
 * events this store captured, stamped as authoritative, applied, or skipped
 * for one product/variation.
 *
 * Stored as a single postmeta row per product (no custom table), oldest
 * first. Entries carry the event_id, so the same event can be lined up
 * across the Master and every Child that applied it.
 */
final class Kitgenix_Stock_Sync_For_WooCommerce_History {

	public const META_KEY = '_kitgenix_stock_sync_for_woocommerce_history';

	private const DEFAULT_LIMIT = 20;
	private const MAX_LIMIT = 100;

	/** Entry types, in the order they normally occur for one change. */
	public const TYPES = ['captured', 'authoritative', 'applied', 'sku_rename', 'skipped_stale'];

	/** Entries kept per product. Filterable; clamped to [0, 100]; 0 disables recording. */
	public static function limit(): int {
		$limit = (int) apply_filters('kitgenix_stock_sync_for_woocommerce_history_limit', self::DEFAULT_LIMIT);
		return max(0, min(self::MAX_LIMIT, $limit));
	}

	/**
	 * Append one entry to a product's history.
	 *
	 * @param array $state Stock fields: version, stock_quantity, stock_status, backorders.
	 * @param array $meta  context, store_id (originating store), event_id, note.
	 */
	public static function record(int $product_id, string $type, array $state, array $meta = []): void {
		if ($product_id <= 0) return;
		if (!in_array($type, self::TYPES, true)) return;

		$limit = self::limit();
		if ($limit === 0) return;

		$qty = $state['stock_quantity'] ?? null;

		$history = self::get($product_id);
		$history[] = [
			'time'           => time(),
			'type'           => $type,
			'version'        => (int) ($state['version'] ?? 0),
			'stock_quantity' => $qty === null ? null : (int) $qty,
			'stock_status'   => (string) ($state['stock_status'] ?? ''),
			'backorders'     => (string) ($state['backorders'] ?? ''),
			'context'        => substr((string) ($meta['context'] ?? ''), 0, 80),
			'store_id'       => (string) ($meta['store_id'] ?? ''),
			'event_id'       => (string) ($meta['event_id'] ?? ''),
			'note'           => substr((string) ($meta['note'] ?? ''), 0, 160),
		];

		update_post_meta($product_id, self::META_KEY, array_slice($history, -$limit));
	}

	/** A product's history, oldest first. */
	public static function get(int $product_id): array {
		if ($product_id <= 0) return [];
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single meta lookup by post ID
		$raw = get_post_meta($product_id, self::META_KEY, true);
		return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
	}

	/** Plain-English label for an entry type. */
	public static function type_label(string $type): string {
		$labels = [
			'captured'      => __('Local change captured', 'kitgenix-stock-sync-for-woocommerce'),
			'authoritative' => __('Authoritative version stamped', 'kitgenix-stock-sync-for-woocommerce'),
			'applied'       => __('Update applied', 'kitgenix-stock-sync-for-woocommerce'),
			'sku_rename'    => __('SKU renamed', 'kitgenix-stock-sync-for-woocommerce'),
			'skipped_stale' => __('Stale update ignored', 'kitgenix-stock-sync-for-woocommerce'),
		];
		return $labels[$type] ?? $type;
	}
}
