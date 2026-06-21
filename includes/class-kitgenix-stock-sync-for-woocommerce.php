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

		// Action Scheduler hooks
		add_action('kitgenix_stock_sync_for_woocommerce_process_event', [$this->sync, 'as_process_event'], 10, 2);
		add_action('kitgenix_stock_sync_for_woocommerce_push_to_store', [$this->sync, 'as_push_to_store'], 10, 3);
		add_action('kitgenix_stock_sync_for_woocommerce_retry_send_to_master', [$this->sync, 'as_retry_send_to_master'], 10, 2);
		add_action('kitgenix_stock_sync_for_woocommerce_retry_push_to_store', [$this->sync, 'as_retry_push_to_store'], 10, 3);
		add_action('kitgenix_stock_sync_for_woocommerce_reconcile_batch', [$this->sync, 'as_reconcile_batch'], 10, 2);

		// NEW: order processing sync (async)
		add_action('kitgenix_stock_sync_for_woocommerce_process_order_processing', [$this->sync, 'as_process_order_processing'], 10, 1);
	}
}
