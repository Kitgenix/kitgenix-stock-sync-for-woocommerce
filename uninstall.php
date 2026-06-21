<?php
/**
 * Uninstall handler for Kitgenix Stock Sync for WooCommerce.
 *
 * Removes plugin settings stored in the database. Does not remove WooCommerce
 * product/order meta or Action Scheduler records.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete all transients (and their timeout rows) with a given key prefix.
 *
 * This cleans up dynamic transient keys (e.g. per-user/per-request) without
 * needing to know the exact transient names.
 */
/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
function kitgenix_stock_sync_for_woocommerce_delete_transients_by_prefix( string $prefix ): void {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
		return;
	}

	$transient_like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
	$timeout_like   = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	// Reason: No WP API exists to list option names by prefix. We SELECT option
	// names and then remove them via WP API to ensure cache invalidation.
	$option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$transient_like,
			$timeout_like
		)
	);

	if ( ! empty( $option_names ) ) {
		foreach ( $option_names as $option_name ) {
			if ( strpos( $option_name, '_transient_timeout_' ) === 0 ) {
				$transient = substr( $option_name, strlen( '_transient_timeout_' ) );
			} elseif ( strpos( $option_name, '_transient_' ) === 0 ) {
				$transient = substr( $option_name, strlen( '_transient_' ) );
			} else {
				delete_option( $option_name );
				continue;
			}

			delete_transient( $transient );
		}
	}
	/* phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching */
}

// Plugin settings.
delete_option( 'kitgenix_stock_sync_for_woocommerce_settings' );
delete_site_option( 'kitgenix_stock_sync_for_woocommerce_settings' );

// Plugin-only transients (including dynamic keys).
kitgenix_stock_sync_for_woocommerce_delete_transients_by_prefix( 'kitgenix_stock_sync_for_woocommerce_' );
kitgenix_stock_sync_for_woocommerce_delete_transients_by_prefix( 'kss_' );

// Multisite: also remove per-site options.
if ( is_multisite() && function_exists( 'get_sites' ) ) {
	$kitgenix_sites = get_sites( [ 'fields' => 'ids' ] );
	foreach ( (array) $kitgenix_sites as $kitgenix_site_id ) {
		switch_to_blog( (int) $kitgenix_site_id );
		delete_option( 'kitgenix_stock_sync_for_woocommerce_settings' );
		kitgenix_stock_sync_for_woocommerce_delete_transients_by_prefix( 'kitgenix_stock_sync_for_woocommerce_' );
		kitgenix_stock_sync_for_woocommerce_delete_transients_by_prefix( 'kss_' );
		restore_current_blog();
	}
}
