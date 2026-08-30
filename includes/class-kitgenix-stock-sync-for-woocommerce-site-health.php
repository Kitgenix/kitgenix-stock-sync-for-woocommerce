<?php
defined('ABSPATH') || exit;

/**
 * WordPress Site Health integration: a non-secret diagnostic "Info" section
 * plus a handful of "direct" Status tests (role/connection configured, stale
 * sync, failed backlog, Action Scheduler availability). Never outputs a
 * shared secret or a full Master/Child URL's credentials – only booleans,
 * counts, and timestamps.
 */
final class Kitgenix_Stock_Sync_For_WooCommerce_Site_Health {

	private Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings;

	public function __construct(Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings) {
		$this->settings = $settings;
	}

	public function hooks(): void {
		add_filter('debug_information', [$this, 'debug_information']);
		add_filter('site_status_tests', [$this, 'site_status_tests']);
	}

	private function fmt(int $ts): string {
		if ($ts <= 0) return __('Never', 'kitgenix-stock-sync-for-woocommerce');
		return sprintf(
			/* translators: %s: human-readable time difference, e.g. "3 hours" */
			__('%s ago', 'kitgenix-stock-sync-for-woocommerce'),
			human_time_diff($ts, time())
		);
	}

	public function debug_information(array $info): array {
		$opt = $this->settings->get_all();
		$role = $this->settings->role();
		$health = $this->settings->get_health();
		$backlog = $this->settings->get_backlog();

		$exhausted = 0;
		foreach ($backlog as $row) {
			if (is_array($row) && ($row['status'] ?? '') === 'exhausted') $exhausted++;
		}

		$children = $this->settings->children();
		$enabled_children = 0;
		foreach ($children as $c) {
			if (is_array($c) && ($c['enabled'] ?? true)) $enabled_children++;
		}

		$master_configured = __('N/A (Master role)', 'kitgenix-stock-sync-for-woocommerce');
		if ($role === 'child') {
			$master = $this->settings->master_config();
			$master_configured = (!empty($master['url']) && !empty($master['store_id']) && !empty($master['secret']))
				? __('Yes', 'kitgenix-stock-sync-for-woocommerce')
				: __('No – incomplete', 'kitgenix-stock-sync-for-woocommerce');
		}

		$info['kitgenix-stock-sync'] = [
			'label' => __('Kitgenix Stock Sync', 'kitgenix-stock-sync-for-woocommerce'),
			'description' => __('Diagnostic information for the Kitgenix Stock Sync for WooCommerce plugin. No shared secrets are included.', 'kitgenix-stock-sync-for-woocommerce'),
			'fields' => [
				'role' => ['label' => __('Role', 'kitgenix-stock-sync-for-woocommerce'), 'value' => ucfirst($role)],
				'store_id' => ['label' => __('This store ID', 'kitgenix-stock-sync-for-woocommerce'), 'value' => $this->settings->this_store_id()],
				'plugin_version' => ['label' => __('Plugin version', 'kitgenix-stock-sync-for-woocommerce'), 'value' => defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION') ? KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION : ''],
				'schema_version' => ['label' => __('Settings schema version', 'kitgenix-stock-sync-for-woocommerce'), 'value' => (string) ($opt['schema_version'] ?? '1')],
				'master_configured' => ['label' => __('Master configured', 'kitgenix-stock-sync-for-woocommerce'), 'value' => $master_configured],
				'children_configured' => ['label' => __('Configured Child stores', 'kitgenix-stock-sync-for-woocommerce'), 'value' => sprintf('%d (%d %s)', count($children), $enabled_children, __('enabled', 'kitgenix-stock-sync-for-woocommerce'))],
				'strict_checkout' => ['label' => __('Strict checkout validation', 'kitgenix-stock-sync-for-woocommerce'), 'value' => $this->settings->strict_checkout_validation() ? ucfirst(str_replace('_', ' ', $this->settings->checkout_validation_failure_strategy())) : __('Disabled', 'kitgenix-stock-sync-for-woocommerce')],
				'last_inbound' => ['label' => __('Last inbound event', 'kitgenix-stock-sync-for-woocommerce'), 'value' => $this->fmt((int) ($health['last_inbound_event'] ?? 0))],
				'last_outbound_success' => ['label' => __('Last outbound success', 'kitgenix-stock-sync-for-woocommerce'), 'value' => $this->fmt((int) ($health['last_outbound_success'] ?? 0))],
				'last_outbound_error' => ['label' => __('Last outbound error', 'kitgenix-stock-sync-for-woocommerce'), 'value' => $this->fmt((int) ($health['last_outbound_error'] ?? 0))],
				'last_reconcile' => ['label' => __('Last reconcile completed', 'kitgenix-stock-sync-for-woocommerce'), 'value' => $this->fmt((int) ($health['last_reconcile_complete'] ?? 0))],
				'backlog_size' => ['label' => __('Backlog items', 'kitgenix-stock-sync-for-woocommerce'), 'value' => (string) count($backlog)],
				'backlog_exhausted' => ['label' => __('Backlog items needing manual attention', 'kitgenix-stock-sync-for-woocommerce'), 'value' => (string) $exhausted],
				'action_scheduler' => ['label' => __('Action Scheduler available', 'kitgenix-stock-sync-for-woocommerce'), 'value' => function_exists('as_schedule_single_action') ? __('Yes', 'kitgenix-stock-sync-for-woocommerce') : __('No', 'kitgenix-stock-sync-for-woocommerce')],
			],
		];

		return $info;
	}

	public function site_status_tests(array $tests): array {
		$tests['direct']['kitgenix_stock_sync_role'] = [
			'label' => __('Kitgenix Stock Sync: role configured', 'kitgenix-stock-sync-for-woocommerce'),
			'test' => [$this, 'test_role_configured'],
		];
		$tests['direct']['kitgenix_stock_sync_connection'] = [
			'label' => __('Kitgenix Stock Sync: connection health', 'kitgenix-stock-sync-for-woocommerce'),
			'test' => [$this, 'test_connection_health'],
		];
		$tests['direct']['kitgenix_stock_sync_backlog'] = [
			'label' => __('Kitgenix Stock Sync: backlog', 'kitgenix-stock-sync-for-woocommerce'),
			'test' => [$this, 'test_backlog'],
		];
		$tests['direct']['kitgenix_stock_sync_action_scheduler'] = [
			'label' => __('Kitgenix Stock Sync: Action Scheduler', 'kitgenix-stock-sync-for-woocommerce'),
			'test' => [$this, 'test_action_scheduler'],
		];
		return $tests;
	}

	private function base_result(string $label): array {
		return [
			'label' => $label,
			'status' => 'good',
			'badge' => ['label' => __('Kitgenix Stock Sync', 'kitgenix-stock-sync-for-woocommerce'), 'color' => 'blue'],
			'description' => '',
			'actions' => '',
			'test' => 'kitgenix_stock_sync',
		];
	}

	public function test_role_configured(): array {
		$result = $this->base_result(__('Kitgenix Stock Sync role is configured', 'kitgenix-stock-sync-for-woocommerce'));

		if ($this->settings->is_child()) {
			$master = $this->settings->master_config();
			if (empty($master['url']) || empty($master['store_id']) || empty($master['secret'])) {
				$result['status'] = 'recommended';
				$result['label'] = __('Kitgenix Stock Sync: Child store has no Master configured', 'kitgenix-stock-sync-for-woocommerce');
				$result['description'] = '<p>' . esc_html__('This store is set to Child role but its Master URL, Store ID, or Secret is missing. Stock will not sync until this is completed.', 'kitgenix-stock-sync-for-woocommerce') . '</p>';
			}
			return $result;
		}

		$enabled = array_filter($this->settings->children(), static fn($c) => is_array($c) && ($c['enabled'] ?? true));
		if (empty($enabled)) {
			$result['status'] = 'recommended';
			$result['label'] = __('Kitgenix Stock Sync: Master has no enabled Child stores', 'kitgenix-stock-sync-for-woocommerce');
			$result['description'] = '<p>' . esc_html__('This store is set to Master role but has no enabled Child stores configured, so there is nowhere to sync stock to.', 'kitgenix-stock-sync-for-woocommerce') . '</p>';
		}

		return $result;
	}

	public function test_connection_health(): array {
		$result = $this->base_result(__('Kitgenix Stock Sync connections are healthy', 'kitgenix-stock-sync-for-woocommerce'));

		$stale = [];
		if ($this->settings->is_child()) {
			if (Kitgenix_Stock_Sync_For_WooCommerce_Settings::derive_status($this->settings->get_master_health()) === 'error') {
				$stale[] = __('Master', 'kitgenix-stock-sync-for-woocommerce');
			}
		} else {
			foreach ($this->settings->children() as $c) {
				if (!is_array($c) || !($c['enabled'] ?? true)) continue;
				$h = is_array($c['health'] ?? null) ? $c['health'] : [];
				if (Kitgenix_Stock_Sync_For_WooCommerce_Settings::derive_status($h) === 'error') {
					$stale[] = (string) ($c['name'] ?? $c['id'] ?? '');
				}
			}
		}

		if (!empty($stale)) {
			$result['status'] = 'recommended';
			$result['label'] = __('Kitgenix Stock Sync: a connection is reporting errors', 'kitgenix-stock-sync-for-woocommerce');
			$result['description'] = '<p>' . sprintf(
				/* translators: %s: comma-separated list of store names */
				esc_html__('These stores last reported a connection error: %s. Check the Logs tab.', 'kitgenix-stock-sync-for-woocommerce'),
				esc_html(implode(', ', $stale))
			) . '</p>';
		}

		return $result;
	}

	public function test_backlog(): array {
		$result = $this->base_result(__('Kitgenix Stock Sync backlog is clear', 'kitgenix-stock-sync-for-woocommerce'));

		$backlog = $this->settings->get_backlog();
		$exhausted = array_filter($backlog, static fn($r) => is_array($r) && ($r['status'] ?? '') === 'exhausted');

		if (!empty($exhausted)) {
			$result['status'] = 'recommended';
			$result['label'] = __('Kitgenix Stock Sync: backlog has items needing attention', 'kitgenix-stock-sync-for-woocommerce');
			$result['description'] = '<p>' . sprintf(
				/* translators: %d: number of exhausted backlog items */
				esc_html__('%d backlog item(s) exhausted their automatic retry budget and need a manual retry or discard on the Logs tab.', 'kitgenix-stock-sync-for-woocommerce'),
				count($exhausted)
			) . '</p>';
		} elseif (count($backlog) > 20) {
			$result['status'] = 'recommended';
			$result['label'] = __('Kitgenix Stock Sync: backlog is growing', 'kitgenix-stock-sync-for-woocommerce');
			$result['description'] = '<p>' . esc_html__('There are a number of items in the backlog. Review the Logs tab to see whether a store is persistently unreachable.', 'kitgenix-stock-sync-for-woocommerce') . '</p>';
		}

		return $result;
	}

	public function test_action_scheduler(): array {
		$result = $this->base_result(__('Kitgenix Stock Sync: Action Scheduler is available', 'kitgenix-stock-sync-for-woocommerce'));

		if (!function_exists('as_schedule_single_action')) {
			$result['status'] = 'critical';
			$result['label'] = __('Kitgenix Stock Sync: Action Scheduler is unavailable', 'kitgenix-stock-sync-for-woocommerce');
			$result['description'] = '<p>' . esc_html__('Action Scheduler (bundled with WooCommerce) was not found. Retries, reconcile batching, and async pushes will not run.', 'kitgenix-stock-sync-for-woocommerce') . '</p>';
		}

		return $result;
	}
}
