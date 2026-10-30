<?php
defined('ABSPATH') || exit;

/**
 * SKU Mapping Repair Tool: finds products whose sync identity is ambiguous
 * or missing, holds the affected SKUs out of sync, and provides the repairs
 * that let sync resume.
 *
 * Local issues (either role):
 * - duplicate_sku: one SKU on more than one product/variation. SKU matching
 *   (wc_get_product_id_by_sku) would pick one arbitrarily. Blocking.
 * - duplicate_gid: one GID on more than one product/variation (usually from
 *   a product duplicate or import that copied postmeta). GID matching would
 *   route updates to the wrong product. Blocking.
 * - missing_sku: a stock-managed product/variation with no SKU. It can never
 *   sync, but there is nothing to mis-route, so it is not held.
 *
 * Blocking SKUs are put on a sync hold (Settings::sku_holds()) by scan().
 * Every inbound/outbound sync path skips held SKUs. A later scan, or a repair
 * (which rescans), releases holds that are no longer ambiguous; on the
 * Master, released SKUs are re-pushed to every Child so sync resumes from
 * authoritative state.
 */
final class Kitgenix_Stock_Sync_For_WooCommerce_Identity {

	private const SCAN_LIMIT = 200;

	private Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings;
	private Kitgenix_Stock_Sync_For_WooCommerce_Sync $sync;

	public function __construct(
		Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings,
		Kitgenix_Stock_Sync_For_WooCommerce_Sync $sync
	) {
		$this->settings = $settings;
		$this->sync = $sync;
	}

	private function log(string $level, string $message, array $context = [], string $code = ''): void {
		wc_get_logger()->log($level, $message, array_merge(['source' => 'kitgenix-stock-sync-for-woocommerce'], $context));
		$this->settings->add_event_log($level, $message, $context, $code);
	}

	/**
	 * Scan this store, store the report, and recompute sync holds.
	 *
	 * @return array{issues: int, held: int, newly_held: string[], released: string[]}
	 */
	public function scan(): array {
		$items = array_merge(
			$this->scan_duplicate_skus(),
			$this->scan_duplicate_gids(),
			$this->scan_missing_skus()
		);
		$this->settings->set_identity_report($items);

		$previous = $this->settings->sku_holds();
		$holds = [];
		foreach ($items as $item) {
			if (empty($item['blocking'])) continue;
			foreach ($item['hold_skus'] as $sku) {
				$sku = (string) $sku;
				if ($sku === '' || isset($holds[$sku])) continue;
				$holds[$sku] = [
					'reason' => (string) $item['type'],
					'since' => (int) ($previous[$sku]['since'] ?? time()),
					'detail' => (string) $item['detail'],
				];
			}
		}

		$newly_held = array_values(array_map('strval', array_diff(array_keys($holds), array_keys($previous))));
		$released = array_values(array_map('strval', array_diff(array_keys($previous), array_keys($holds))));
		$this->settings->set_sku_holds($holds);

		if (!empty($newly_held)) {
			$this->log('warning', 'SKU Mapping Repair: SKUs put on a sync hold until their identity is repaired.', ['skus' => implode(', ', $newly_held)], 'kss_sku_held');
		}
		if (!empty($released)) {
			$this->log('info', 'SKU Mapping Repair: sync holds released – identity is no longer ambiguous.', ['skus' => implode(', ', $released)]);
			$this->resume($released);
		}

		$this->log('info', 'SKU Mapping Repair scan completed.', ['issues' => count($items), 'held' => count($holds)]);

		return [
			'issues' => count($items),
			'held' => count($holds),
			'newly_held' => $newly_held,
			'released' => $released,
		];
	}

	/**
	 * Resume sync for released SKUs. The Master re-pushes authoritative state
	 * to every Child. A Child does nothing: sending its own (possibly stale)
	 * state would overwrite the Master, so it waits for the Master instead.
	 */
	private function resume(array $skus): void {
		if (empty($skus) || !$this->settings->is_master()) return;
		$this->sync->master_push_skus($skus);
	}

	/** Manually release a hold. The next scan holds it again if it is still ambiguous. */
	public function release(string $sku): bool {
		$sku = trim($sku);
		if (!$this->settings->release_sku_hold($sku)) return false;
		$this->log('warning', 'SKU Mapping Repair: sync hold released manually.', ['sku' => $sku], 'kss_sku_held');
		$this->resume([$sku]);
		return true;
	}

	/**
	 * Give one product/variation a new SKU, then rescan. The write is not
	 * broadcast as a SKU rename: with a duplicate SKU it is not knowable which
	 * Child product corresponds to the renamed one, so a rename event could
	 * rename the wrong product on a Child.
	 *
	 * @return array{0: bool, 1: string} [ok, message]
	 */
	public function set_sku(int $product_id, string $new_sku): array {
		$new_sku = trim($new_sku);
		$product = $product_id > 0 ? wc_get_product($product_id) : null;
		if (!($product instanceof WC_Product)) {
			return [false, __('Product not found.', 'kitgenix-stock-sync-for-woocommerce')];
		}
		if ($new_sku === '') {
			return [false, __('Enter a SKU.', 'kitgenix-stock-sync-for-woocommerce')];
		}
		if (function_exists('wc_product_has_unique_sku') && !wc_product_has_unique_sku($product_id, $new_sku)) {
			return [false, __('That SKU is already used by another product. Choose a unique SKU.', 'kitgenix-stock-sync-for-woocommerce')];
		}

		$old_sku = (string) $product->get_sku();
		try {
			$this->sync->with_outbound_suppressed(static function () use ($product, $new_sku) {
				$product->set_sku($new_sku);
				$product->save();
			});
		} catch (\Throwable $e) {
			return [false, $e->getMessage()];
		}

		$this->log('info', 'SKU Mapping Repair: SKU assigned.', ['product_id' => $product_id, 'old_sku' => $old_sku, 'new_sku' => $new_sku]);
		$this->scan();

		return [true, sprintf(
			/* translators: 1: product ID, 2: new SKU */
			__('Product #%1$d now has SKU "%2$s".', 'kitgenix-stock-sync-for-woocommerce'),
			$product_id,
			$new_sku
		)];
	}

	/**
	 * Separate one product from a shared GID, then rescan.
	 *
	 * Master: issue a fresh GID (Children re-learn it by SKU on the next push).
	 * Child: clear the GID and applied version, so the product is matched by
	 * SKU again and adopts the Master's GID on the next update. The applied
	 * version is cleared too because it was copied from the other product and
	 * would otherwise make genuine updates look stale.
	 *
	 * @return array{0: bool, 1: string} [ok, message]
	 */
	public function reissue_gid(int $product_id): array {
		$product = $product_id > 0 ? wc_get_product($product_id) : null;
		if (!($product instanceof WC_Product)) {
			return [false, __('Product not found.', 'kitgenix-stock-sync-for-woocommerce')];
		}

		$old_gid = (string) $product->get_meta(Kitgenix_Stock_Sync_For_WooCommerce_Sync::META_GID);
		$new_gid = $this->settings->is_master() ? wp_generate_uuid4() : '';

		$this->sync->with_outbound_suppressed(static function () use ($product, $new_gid) {
			if ($new_gid !== '') {
				$product->update_meta_data(Kitgenix_Stock_Sync_For_WooCommerce_Sync::META_GID, $new_gid);
			} else {
				$product->delete_meta_data(Kitgenix_Stock_Sync_For_WooCommerce_Sync::META_GID);
				$product->delete_meta_data(Kitgenix_Stock_Sync_For_WooCommerce_Sync::META_APPLIED_VERSION);
			}
			$product->save();
		});
		$this->sync->forget_gid_lookup($old_gid);

		$this->log('info', 'SKU Mapping Repair: GID reissued.', ['product_id' => $product_id, 'old_gid' => $old_gid, 'new_gid' => $new_gid]);
		$this->scan();

		return [true, $new_gid !== ''
			? sprintf(/* translators: %d: product ID */ __('Product #%d has a new GID.', 'kitgenix-stock-sync-for-woocommerce'), $product_id)
			: sprintf(/* translators: %d: product ID */ __('Product #%d GID cleared. It will adopt the Master\'s GID on the next update.', 'kitgenix-stock-sync-for-woocommerce'), $product_id)];
	}

	/**
	 * Counterpart-store identity issues (Master only) from the Conflicts
	 * report: a Child holding a different GID for a SKU, or missing the SKU.
	 * Not stored separately – always read from the latest Audit/Reconcile.
	 */
	public function remote_issues(): array {
		if (!$this->settings->is_master()) return [];

		$out = [];
		foreach ($this->settings->get_conflicts_report()['items'] as $row) {
			if (!is_array($row)) continue;
			$type = (string) ($row['type'] ?? '');
			if (!in_array($type, ['gid_mismatch', 'missing_product'], true)) continue;
			$key = $type . '|' . ($row['sku'] ?? '') . '|' . ($row['child_id'] ?? '');
			$out[$key] = $row; // latest row wins
		}
		return array_values($out);
	}

	/* -----------------------------
	 * Scans
	 * ----------------------------- */

	private function scan_duplicate_skus(): array {
		$dups = $this->sync->find_duplicate_skus(self::SCAN_LIMIT);
		$skus = array_values(array_filter(array_map(static fn($r) => (string) ($r['sku'] ?? ''), $dups)));
		$by_sku = $this->product_ids_by_meta('_sku', $skus);

		$items = [];
		foreach ($skus as $sku) {
			$ids = $by_sku[$sku] ?? [];
			$items[] = [
				'type' => 'duplicate_sku',
				'sku' => $sku,
				'gid' => '',
				'blocking' => true,
				'hold_skus' => [$sku],
				'products' => array_map([$this, 'describe_product'], $ids),
				'detail' => sprintf('SKU is used by %d products/variations.', count($ids)),
			];
		}
		return $items;
	}

	private function scan_duplicate_gids(): array {
		$dups = $this->sync->find_duplicate_gids(self::SCAN_LIMIT);
		$gids = array_values(array_filter(array_map(static fn($r) => (string) ($r['gid'] ?? ''), $dups)));
		$by_gid = $this->product_ids_by_meta(Kitgenix_Stock_Sync_For_WooCommerce_Sync::META_GID, $gids);

		$items = [];
		foreach ($gids as $gid) {
			$products = array_map([$this, 'describe_product'], $by_gid[$gid] ?? []);
			$skus = array_values(array_unique(array_filter(array_column($products, 'sku'))));
			$items[] = [
				'type' => 'duplicate_gid',
				'sku' => implode(', ', $skus),
				'gid' => $gid,
				'blocking' => true,
				'hold_skus' => $skus,
				'products' => $products,
				'detail' => sprintf('GID is shared by %d products/variations.', count($products)),
			];
		}
		return $items;
	}

	private function scan_missing_skus(): array {
		global $wpdb;
		/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key */
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_manage_stock' AND ms.meta_value = 'yes'
			 LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_sku'
			 WHERE p.post_type IN ('product','product_variation')
			   AND p.post_status NOT IN ('trash','auto-draft')
			   AND (s.meta_value IS NULL OR s.meta_value = '')
			 ORDER BY p.ID ASC
			 LIMIT %d",
			self::SCAN_LIMIT
		));
		/* phpcs:enable */

		$items = [];
		foreach ((array) $ids as $id) {
			$items[] = [
				'type' => 'missing_sku',
				'sku' => '',
				'gid' => '',
				'blocking' => false,
				'hold_skus' => [],
				'products' => [$this->describe_product((int) $id)],
				'detail' => 'Stock is managed but there is no SKU, so this product can never sync.',
			];
		}
		return $items;
	}

	/** @return array<string, int[]> meta_value => product IDs (oldest first) */
	private function product_ids_by_meta(string $meta_key, array $values): array {
		if (empty($values)) return [];
		global $wpdb;

		$placeholders = implode(',', array_fill(0, count($values), '%s'));
		/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared */
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT pm.post_id, pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s AND pm.meta_value IN ($placeholders) AND p.post_status <> 'trash'
			   AND p.post_type IN ('product','product_variation')
			 ORDER BY pm.post_id ASC",
			array_merge([$meta_key], $values)
		), ARRAY_A);
		/* phpcs:enable */

		$out = [];
		foreach ((array) $rows as $row) {
			$out[(string) $row['meta_value']][] = (int) $row['post_id'];
		}
		return $out;
	}

	private function describe_product(int $id): array {
		$product = wc_get_product($id);
		if (!($product instanceof WC_Product)) {
			return ['id' => $id, 'name' => '#' . $id, 'type' => '', 'sku' => '', 'gid' => '', 'edit_url' => ''];
		}

		$edit_id = $product->is_type('variation') ? (int) $product->get_parent_id() : $id;
		return [
			'id' => $id,
			'name' => wp_strip_all_tags((string) $product->get_formatted_name()),
			'type' => (string) $product->get_type(),
			'sku' => (string) $product->get_sku(),
			'gid' => (string) $product->get_meta(Kitgenix_Stock_Sync_For_WooCommerce_Sync::META_GID),
			'edit_url' => (string) get_edit_post_link($edit_id, 'raw'),
		];
	}
}
