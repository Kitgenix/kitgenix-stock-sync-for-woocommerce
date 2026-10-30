<?php
defined('ABSPATH') || exit;

/**
 * WP-CLI commands for server administrators: `wp kitgenix-stock-sync <sub-command>`.
 * Only registered when running under WP-CLI (see the main plugin file).
 */
final class Kitgenix_Stock_Sync_For_WooCommerce_CLI {

	private Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings;
	private Kitgenix_Stock_Sync_For_WooCommerce_Sync $sync;
	private Kitgenix_Stock_Sync_For_WooCommerce_Identity $identity;
	private Kitgenix_Stock_Sync_For_WooCommerce_Failover $failover;

	public static function register(
		Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings,
		Kitgenix_Stock_Sync_For_WooCommerce_Sync $sync,
		Kitgenix_Stock_Sync_For_WooCommerce_Identity $identity,
		Kitgenix_Stock_Sync_For_WooCommerce_Failover $failover
	): void {
		$instance = new self($settings, $sync, $identity, $failover);
		\WP_CLI::add_command('kitgenix-stock-sync', $instance);
	}

	public function __construct(
		Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings,
		Kitgenix_Stock_Sync_For_WooCommerce_Sync $sync,
		Kitgenix_Stock_Sync_For_WooCommerce_Identity $identity,
		Kitgenix_Stock_Sync_For_WooCommerce_Failover $failover
	) {
		$this->settings = $settings;
		$this->sync = $sync;
		$this->identity = $identity;
		$this->failover = $failover;
	}

	/**
	 * Show this store's role, connection status, and health summary.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp kitgenix-stock-sync status
	 */
	public function status(array $args, array $assoc_args): void {
		$format = (string) ($assoc_args['format'] ?? 'table');

		\WP_CLI::log(sprintf('Role: %s | Store ID: %s', $this->settings->role(), $this->settings->this_store_id()));

		$rows = [];
		if ($this->settings->is_child()) {
			$h = $this->settings->get_master_health();
			$rows[] = [
				'store' => 'master',
				'status' => Kitgenix_Stock_Sync_For_WooCommerce_Settings::derive_status($h),
				'last_inbound' => $this->fmt($h['last_inbound']),
				'last_outbound_success' => $this->fmt($h['last_outbound_success']),
				'last_error' => $h['last_error_message'],
				'remote_wc_version' => $h['remote_wc_version'],
				'remote_plugin_version' => $h['remote_plugin_version'],
			];
		} else {
			foreach ($this->settings->children() as $c) {
				if (!is_array($c)) continue;
				$h = is_array($c['health'] ?? null) ? $c['health'] : Kitgenix_Stock_Sync_For_WooCommerce_Settings::default_health();
				$rows[] = [
					'store' => (string) ($c['name'] ?? $c['id'] ?? ''),
					'status' => Kitgenix_Stock_Sync_For_WooCommerce_Settings::derive_status($h),
					'last_inbound' => $this->fmt($h['last_inbound']),
					'last_outbound_success' => $this->fmt($h['last_outbound_success']),
					'last_error' => $h['last_error_message'],
					'remote_wc_version' => $h['remote_wc_version'],
					'remote_plugin_version' => $h['remote_plugin_version'],
				];
			}
		}

		\WP_CLI\Utils\format_items($format, $rows, ['store', 'status', 'last_inbound', 'last_outbound_success', 'last_error', 'remote_wc_version', 'remote_plugin_version']);
	}

	/**
	 * Compare stock state against configured Child stores (Master only).
	 *
	 * ## OPTIONS
	 *
	 * [--skus=<skus>]
	 * : Comma-separated list of SKUs to audit.
	 *
	 * [--format=<format>]
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp kitgenix-stock-sync audit --skus=SKU1,SKU2
	 */
	public function audit(array $args, array $assoc_args): void {
		if (!$this->settings->is_master()) {
			\WP_CLI::error('Audit is only available on the Master store.');
		}

		$skus = isset($assoc_args['skus']) ? array_map('trim', explode(',', (string) $assoc_args['skus'])) : [];
		if (empty($skus)) {
			\WP_CLI::error('Provide --skus=SKU1,SKU2');
		}

		$result = $this->sync->master_audit_children_stock($skus);
		$rows = [];
		foreach ($result['children'] as $cid => $cres) {
			if (!empty($cres['error'])) {
				$rows[] = ['store' => $cres['name'], 'sku' => '', 'field' => '', 'master' => '', 'child' => '', 'note' => $cres['error']];
				continue;
			}
			foreach ($cres['mismatches'] as $sku => $fields) {
				foreach ($fields as $field => $pair) {
					$rows[] = ['store' => $cres['name'], 'sku' => $sku, 'field' => $field, 'master' => $pair['master'], 'child' => $pair['child'], 'note' => ''];
				}
			}
		}

		if (empty($rows)) {
			\WP_CLI::success('No mismatches found for the audited SKUs.');
			return;
		}

		\WP_CLI\Utils\format_items((string) ($assoc_args['format'] ?? 'table'), $rows, ['store', 'sku', 'field', 'master', 'child', 'note']);
	}

	/**
	 * Run a reconcile (Master only): push authoritative stock state to
	 * children, or compare only, in batches.
	 *
	 * ## OPTIONS
	 *
	 * [--skus=<skus>]
	 * : Comma-separated SKUs. Omit to reconcile the whole catalogue.
	 *
	 * [--dry-run]
	 * : Compare only; never mutate a Child's stock.
	 *
	 * [--differences-only]
	 * : Only push to a Child when its state actually differs from the Master.
	 *
	 * [--batch=<n>]
	 * : Batch size. Default 200.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kitgenix-stock-sync reconcile --dry-run
	 *     wp kitgenix-stock-sync reconcile --skus=SKU1,SKU2
	 */
	public function reconcile(array $args, array $assoc_args): void {
		if (!$this->settings->is_master()) {
			\WP_CLI::error('Reconcile is only available on the Master store.');
		}

		$skus = isset($assoc_args['skus']) ? array_map('trim', explode(',', (string) $assoc_args['skus'])) : [];
		$options = [
			'per_page' => (int) ($assoc_args['batch'] ?? 200),
			'mode' => empty($skus) ? 'all' : 'selected',
			'skus' => $skus,
			'dry_run' => isset($assoc_args['dry-run']),
			'differences_only' => isset($assoc_args['differences-only']),
		];

		$this->sync->start_reconcile($options);

		// CLI can run long-lived; drain the batch chain synchronously here rather than
		// relying on Action Scheduler's own timing, so the command reports a real result.
		$progress = \WP_CLI\Utils\make_progress_bar('Reconciling', 100);
		$iterations = 0;
		do {
			$state = $this->settings->reconcile_state();
			$running = !empty($state['running']);
			if ($running) {
				$this->sync->as_reconcile_batch((int) $state['page'] ?: 1, (int) $state['per_page'] ?: 200);
			}
			$progress->tick();
			$iterations++;
		} while ($running && $iterations < 100000);
		$progress->finish();

		$state = $this->settings->reconcile_state();
		\WP_CLI::success(sprintf(
			'Reconcile finished. Processed=%d Differences=%d Pushed=%d',
			(int) ($state['processed'] ?? 0), (int) ($state['differences_found'] ?? 0), (int) ($state['pushed_count'] ?? 0)
		));
	}

	/**
	 * Push specific SKUs to all configured Child stores (Master only).
	 *
	 * ## OPTIONS
	 *
	 * <skus>...
	 * : One or more SKUs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kitgenix-stock-sync sku push SKU1 SKU2
	 */
	public function sku(array $args, array $assoc_args): void {
		$sub = array_shift($args);
		if ($sub !== 'push') {
			\WP_CLI::error('Usage: wp kitgenix-stock-sync sku push <sku>...');
		}
		if (!$this->settings->is_master()) {
			\WP_CLI::error('SKU push is only available on the Master store.');
		}
		if (empty($args)) {
			\WP_CLI::error('Provide at least one SKU.');
		}

		$this->sync->master_push_skus($args);
		\WP_CLI::success(sprintf('Pushed %d SKU(s) to children.', count($args)));
	}

	/**
	 * List, retry, or discard backlog items (failed sync pushes/sends).
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : One of: list, retry, discard, retry-all, discard-all
	 *
	 * [<id>]
	 * : Backlog item id (for retry/discard).
	 *
	 * [--yes]
	 * : Skip the confirmation prompt for discard/discard-all.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kitgenix-stock-sync backlog list
	 *     wp kitgenix-stock-sync backlog retry <id>
	 *     wp kitgenix-stock-sync backlog discard <id> --yes
	 */
	public function backlog(array $args, array $assoc_args): void {
		$action = array_shift($args);
		$id = array_shift($args);

		if ($action === 'list') {
			$rows = [];
			foreach ($this->settings->get_backlog() as $row) {
				$rows[] = [
					'id' => $row['id'] ?? '',
					'type' => $row['type'] ?? '',
					'store_id' => $row['store_id'] ?? '',
					'status' => $row['status'] ?? 'pending',
					'attempt' => $row['attempt'] ?? '',
					'error' => $row['error'] ?? '',
					'next_retry_at' => $this->fmt((int) ($row['next_retry_at'] ?? 0), true),
				];
			}
			if (empty($rows)) {
				\WP_CLI::success('Backlog is empty.');
				return;
			}
			\WP_CLI\Utils\format_items((string) ($assoc_args['format'] ?? 'table'), $rows, ['id', 'type', 'store_id', 'status', 'attempt', 'error', 'next_retry_at']);
			return;
		}

		if ($action === 'retry') {
			if (!$id) \WP_CLI::error('Provide a backlog item id.');
			$ok = $this->sync->retry_backlog_item((string) $id);
			$ok ? \WP_CLI::success('Retry attempted.') : \WP_CLI::error('Could not retry that backlog item (not found, or payload was not retained).');
			return;
		}

		if ($action === 'discard') {
			if (!$id) \WP_CLI::error('Provide a backlog item id.');
			if (!isset($assoc_args['yes'])) {
				\WP_CLI::confirm('Discard this backlog item permanently?');
			}
			$ok = $this->sync->discard_backlog_item((string) $id);
			$ok ? \WP_CLI::success('Discarded.') : \WP_CLI::error('Backlog item not found.');
			return;
		}

		if ($action === 'retry-all') {
			$count = 0;
			foreach ($this->settings->get_backlog() as $row) {
				if (($row['status'] ?? '') === 'exhausted') continue;
				if ($this->sync->retry_backlog_item((string) ($row['id'] ?? ''))) $count++;
			}
			\WP_CLI::success(sprintf('Retried %d backlog item(s).', $count));
			return;
		}

		if ($action === 'discard-all') {
			if (!isset($assoc_args['yes'])) {
				\WP_CLI::confirm('Discard ALL backlog items permanently?');
			}
			$this->settings->clear_backlog();
			\WP_CLI::success('Backlog cleared.');
			return;
		}

		\WP_CLI::error('Usage: wp kitgenix-stock-sync backlog <list|retry|discard|retry-all|discard-all> [<id>]');
	}

	/**
	 * Show the current Conflict Dashboard report.
	 *
	 * ## OPTIONS
	 *
	 * [--rescan]
	 * : Run a fresh duplicate-SKU scan before printing the report (Master only).
	 *
	 * [--format=<format>]
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp kitgenix-stock-sync conflicts --rescan
	 */
	public function conflicts(array $args, array $assoc_args): void {
		if (isset($assoc_args['rescan'])) {
			if (!$this->settings->is_master()) {
				\WP_CLI::error('Conflict scanning is only available on the Master store.');
			}
			$this->sync->scan_for_conflicts();
		}

		$report = $this->settings->get_conflicts_report();
		if (empty($report['items'])) {
			\WP_CLI::success('No conflicts recorded.');
			return;
		}

		\WP_CLI\Utils\format_items((string) ($assoc_args['format'] ?? 'table'), $report['items'], ['type', 'sku', 'child_name', 'master_value', 'child_value', 'detail']);
	}

	/**
	 * Show the versioned stock history for one SKU on this store and on connected stores.
	 *
	 * ## OPTIONS
	 *
	 * <sku>
	 * : The SKU to look up.
	 *
	 * [--local]
	 * : Only show this store's history (no requests to other stores).
	 *
	 * [--format=<format>]
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp kitgenix-stock-sync history SKU1
	 *     wp kitgenix-stock-sync history SKU1 --local --format=json
	 */
	public function history(array $args, array $assoc_args): void {
		$sku = trim((string) ($args[0] ?? ''));
		if ($sku === '') {
			\WP_CLI::error('Provide a SKU.');
		}

		if (isset($assoc_args['local'])) {
			$state = $this->sync->local_stock_lookup([['sku' => $sku]], true)[$sku] ?? ['exists' => false];
			$result = ['stores' => [['name' => $this->settings->this_store_name(), 'error' => '', 'state' => $state]], 'timeline' => []];
			foreach ((array) ($state['history'] ?? []) as $entry) {
				$result['timeline'][] = $entry + ['at_store' => $this->settings->this_store_name()];
			}
			$result['timeline'] = array_reverse($result['timeline']);
		} else {
			$result = $this->sync->stock_history_across_stores($sku);
		}

		foreach ($result['stores'] as $store) {
			if (($store['error'] ?? '') !== '') {
				\WP_CLI::warning(sprintf('%s: %s', $store['name'], $store['error']));
			} elseif (empty($store['state']['exists'])) {
				\WP_CLI::warning(sprintf('%s: SKU not found.', $store['name']));
			}
		}

		if (empty($result['timeline'])) {
			\WP_CLI::success('No stock events recorded for this SKU.');
			return;
		}

		$rows = array_map(fn($e) => [
			'time' => $this->fmt((int) ($e['time'] ?? 0)),
			'store' => (string) ($e['at_store'] ?? ''),
			'event' => (string) ($e['type'] ?? ''),
			'version' => (int) ($e['version'] ?? 0),
			'stock' => ($e['stock_quantity'] ?? null) === null ? '' : (int) $e['stock_quantity'],
			'status' => (string) ($e['stock_status'] ?? ''),
			'backorders' => (string) ($e['backorders'] ?? ''),
			'context' => (string) ($e['context'] ?? ''),
			'origin_store_id' => (string) ($e['store_id'] ?? ''),
			'event_id' => (string) ($e['event_id'] ?? ''),
			'note' => (string) ($e['note'] ?? ''),
		], $result['timeline']);

		\WP_CLI\Utils\format_items((string) ($assoc_args['format'] ?? 'table'), $rows, ['time', 'store', 'event', 'version', 'stock', 'status', 'backorders', 'context', 'origin_store_id', 'event_id', 'note']);
	}

	/**
	 * SKU Mapping Repair: scan for duplicate/missing SKU identities, list holds, or release a hold.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : One of: scan, list, holds, release
	 *
	 * [<sku>]
	 * : SKU to release (for release).
	 *
	 * [--format=<format>]
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp kitgenix-stock-sync identity scan
	 *     wp kitgenix-stock-sync identity holds
	 *     wp kitgenix-stock-sync identity release SKU1
	 */
	public function identity(array $args, array $assoc_args): void {
		$action = (string) array_shift($args);
		$format = (string) ($assoc_args['format'] ?? 'table');

		if ($action === 'scan') {
			$result = $this->identity->scan();
			\WP_CLI::success(sprintf('%d issue(s) found, %d SKU(s) on sync hold, %d hold(s) released.', $result['issues'], $result['held'], count($result['released'])));
			$action = 'list';
		}

		if ($action === 'list') {
			$rows = [];
			foreach ($this->settings->get_identity_report()['items'] as $item) {
				foreach ((array) ($item['products'] ?? []) as $p) {
					$rows[] = [
						'type' => (string) ($item['type'] ?? ''),
						'blocking' => !empty($item['blocking']) ? 'yes' : 'no',
						'product_id' => (int) ($p['id'] ?? 0),
						'name' => (string) ($p['name'] ?? ''),
						'sku' => (string) ($p['sku'] ?? ''),
						'gid' => (string) ($p['gid'] ?? ''),
					];
				}
			}
			if (empty($rows)) {
				\WP_CLI::success('No SKU identity problems in the last scan.');
				return;
			}
			\WP_CLI\Utils\format_items($format, $rows, ['type', 'blocking', 'product_id', 'name', 'sku', 'gid']);
			return;
		}

		if ($action === 'holds') {
			$rows = [];
			foreach ($this->settings->sku_holds() as $sku => $hold) {
				$rows[] = ['sku' => (string) $sku, 'reason' => (string) ($hold['reason'] ?? ''), 'since' => $this->fmt((int) ($hold['since'] ?? 0)), 'detail' => (string) ($hold['detail'] ?? '')];
			}
			if (empty($rows)) {
				\WP_CLI::success('No SKUs are on a sync hold.');
				return;
			}
			\WP_CLI\Utils\format_items($format, $rows, ['sku', 'reason', 'since', 'detail']);
			return;
		}

		if ($action === 'release') {
			$sku = trim((string) array_shift($args));
			if ($sku === '') \WP_CLI::error('Provide the SKU to release.');
			$this->identity->release($sku) ? \WP_CLI::success('Sync hold released.') : \WP_CLI::error('That SKU is not on a sync hold.');
			return;
		}

		\WP_CLI::error('Usage: wp kitgenix-stock-sync identity <scan|list|holds|release> [<sku>]');
	}

	/**
	 * Print the Failover Readiness Report: what happens if the Master is unreachable.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * ---
	 * default: markdown
	 * options:
	 *   - markdown
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp kitgenix-stock-sync failover-report
	 *     wp kitgenix-stock-sync failover-report --format=json > failover.json
	 *
	 * @subcommand failover-report
	 */
	public function failover_report(array $args, array $assoc_args): void {
		$report = $this->failover->build();
		if (($assoc_args['format'] ?? 'markdown') === 'json') {
			\WP_CLI::line((string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			return;
		}
		\WP_CLI::line($this->failover->to_markdown($report));
	}

	private function fmt($ts, bool $future = false): string {
		$ts = (int) $ts;
		if ($ts <= 0) return $future ? '-' : 'never';
		return gmdate('Y-m-d H:i:s', $ts) . ' UTC';
	}
}
