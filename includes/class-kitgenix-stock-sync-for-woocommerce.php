<?php
defined('ABSPATH') || exit;

final class Kitgenix_Stock_Sync_For_WooCommerce {

	private static ?self $instance = null;

	public Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings;
	public Kitgenix_Stock_Sync_For_WooCommerce_Security $security;
	public Kitgenix_Stock_Sync_For_WooCommerce_REST $rest;
	public Kitgenix_Stock_Sync_For_WooCommerce_Sync $sync;
	public Kitgenix_Stock_Sync_For_WooCommerce_Admin $admin;

	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		Kitgenix_Stock_Sync_For_WooCommerce_Settings::ensure_defaults();

		$this->settings = new Kitgenix_Stock_Sync_For_WooCommerce_Settings();
		$this->security = new Kitgenix_Stock_Sync_For_WooCommerce_Security($this->settings);
		$this->sync     = new Kitgenix_Stock_Sync_For_WooCommerce_Sync($this->settings, $this->security);
		$this->rest     = new Kitgenix_Stock_Sync_For_WooCommerce_REST($this->settings, $this->security, $this->sync);
		$this->admin    = new Kitgenix_Stock_Sync_For_WooCommerce_Admin($this->settings, $this->security, $this->sync);

		$this->rest->hooks();
		$this->sync->hooks();
		$this->admin->hooks();

		if (class_exists('Kitgenix_Stock_Sync_For_WooCommerce_Site_Health')) {
			(new Kitgenix_Stock_Sync_For_WooCommerce_Site_Health($this->settings))->hooks();
		}

		if (defined('WP_CLI') && WP_CLI && class_exists('Kitgenix_Stock_Sync_For_WooCommerce_CLI')) {
			Kitgenix_Stock_Sync_For_WooCommerce_CLI::register($this->settings, $this->sync);
		}

		// Action Scheduler hooks. push_to_store / send_to_master are used both for the
		// initial async dispatch (attempt=1) and for scheduled retries (attempt>1) of the
		// same logical delivery – see Sync::schedule_retry_*().
		add_action('kitgenix_stock_sync_for_woocommerce_process_event', [$this->sync, 'as_process_event'], 10, 2);
		add_action('kitgenix_stock_sync_for_woocommerce_push_to_store', [$this->sync, 'as_push_to_store'], 10, 4);
		add_action('kitgenix_stock_sync_for_woocommerce_send_to_master', [$this->sync, 'as_send_to_master'], 10, 3);
		add_action('kitgenix_stock_sync_for_woocommerce_reconcile_batch', [$this->sync, 'as_reconcile_batch'], 10, 2);
		add_action('kitgenix_stock_sync_for_woocommerce_process_order_processing', [$this->sync, 'as_process_order_processing'], 10, 1);
		add_action('kitgenix_stock_sync_for_woocommerce_health_ping', [$this->sync, 'as_health_ping'], 10, 0);

		$this->maybe_schedule_health_ping();
	}

	/** Recurring per-store connection-health ping (~15 min), scheduled once. */
	private function maybe_schedule_health_ping(): void {
		if (!function_exists('as_next_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
			return;
		}
		if (as_next_scheduled_action('kitgenix_stock_sync_for_woocommerce_health_ping', [], 'kitgenix-stock-sync')) {
			return;
		}
		as_schedule_recurring_action(time() + 300, 15 * MINUTE_IN_SECONDS, 'kitgenix_stock_sync_for_woocommerce_health_ping', [], 'kitgenix-stock-sync');
	}
}
