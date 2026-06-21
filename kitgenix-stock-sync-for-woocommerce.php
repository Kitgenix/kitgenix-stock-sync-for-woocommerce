<?php
/**
 * Plugin Name:       Kitgenix Stock Sync for WooCommerce
 * Plugin URI:        https://wordpress.org/plugins/kitgenix-stock-sync-for-woocommerce/
 * Author Plugin URI: https://kitgenix.com/plugins/kitgenix-stock-sync-for-woocommerce
 * Documentation URI: https://kitgenix.com/plugins/kitgenix-stock-sync-for-woocommerce/documentation
 * Support URI:       https://wordpress.org/support/plugin/kitgenix-stock-sync-for-woocommerce/
 * Author Support URI: https://kitgenix.com/plugins/kitgenix-stock-sync-for-woocommerce/support
 * Feature Request URI: https://kitgenix.com/plugins/kitgenix-stock-sync-for-woocommerce/feature-request
 * Description:       Sync WooCommerce stock between stores with secure master-child inventory updates and signed REST requests.
 * Version:           1.0.2
 * Requires at least: 6.0
 * Tested up to:      7.0
 * Requires PHP:      8.1
 * Author:            Kitgenix
 * Author URI:        https://kitgenix.com/
 * Donate link:       https://donate.stripe.com/9B65kDgG3fTQ2Kzcmwf7i00
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   10.0
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       kitgenix-stock-sync-for-woocommerce
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION', '1.0.2');
define('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_FILE', __FILE__);
define('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_DIR', plugin_dir_path(__FILE__));
define('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL', plugin_dir_url(__FILE__));

/**
 * Kitgenix top-level admin menu.
 * Important: must run on admin_menu (not earlier).
 */
if (!function_exists('kitgenix_get_admin_menu_icon')) {
	function kitgenix_get_admin_menu_icon(string $plugin_file): string {
		$plugin_dir = dirname($plugin_file) . '/';
		$icon_paths = [
			$plugin_dir . 'assets/images/logos/kitgenix-wordpress-admin-icon.svg',
			$plugin_dir . 'assets/images/logos/kitgenix-custom-wordpress-admin-icon.svg',
		];

		foreach ($icon_paths as $icon_path) {
			if (!is_readable($icon_path)) {
				continue;
			}

			$svg = file_get_contents($icon_path);
			if (false !== $svg && '' !== trim($svg)) {
				return 'data:image/svg+xml;base64,' . base64_encode($svg);
			}
		}

		return 'dashicons-admin-generic';
	}
}


if (!function_exists('kitgenix_ensure_admin_menu')) {
	function kitgenix_ensure_admin_menu(): void {
		if (!is_admin()) return;

		global $admin_page_hooks;
		$slug = 'kitgenix';

		if (isset($admin_page_hooks[$slug])) {
			return; // already registered by another Kitgenix plugin
		}

		$capability = class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options';
		if (!current_user_can($capability) && current_user_can('manage_options')) {
			$capability = 'manage_options';
		}

		$icon_url = kitgenix_get_admin_menu_icon(__FILE__);

		add_menu_page(
			__('Kitgenix', 'kitgenix-stock-sync-for-woocommerce'),
			__('Kitgenix', 'kitgenix-stock-sync-for-woocommerce'),
			$capability,
			$slug,
			'kitgenix_render_admin_page',
			$icon_url,
			58
		);
	}
}
add_action('admin_menu', 'kitgenix_ensure_admin_menu', 5);

if (!function_exists('kitgenix_hub_get_wporg_active_installs')) {
	/**
	 * Fetch WP.org active install counts for a set of plugin slugs.
	 * Cached to avoid repeated network calls.
	 *
	 * @param string[] $slugs
	 * @return array<string,int> Map of slug => active_installs
	 */
	function kitgenix_hub_get_wporg_active_installs(array $slugs): array {
		if (!function_exists('get_transient') || !function_exists('set_transient')) {
			return [];
		}

		$clean_slugs = [];
		foreach ($slugs as $slug) {
			$slug = is_string($slug) ? $slug : '';
			$slug = function_exists('sanitize_key') ? sanitize_key($slug) : strtolower(preg_replace('/[^a-z0-9_\-]/', '', $slug));
			if ($slug) {
				$clean_slugs[] = $slug;
			}
		}

		$clean_slugs = array_values(array_unique($clean_slugs));
		if (empty($clean_slugs)) {
			return [];
		}

		$cache_key = 'kitgenix_hub_wporg_active_installs_v1';
		$cached = get_transient($cache_key);
		$cached = is_array($cached) ? $cached : [];

		$missing = [];
		foreach ($clean_slugs as $slug) {
			if (!array_key_exists($slug, $cached)) {
				$missing[] = $slug;
			}
		}

		if (!empty($missing)) {
			if (!function_exists('plugins_api')) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}

			foreach ($missing as $slug) {
				$info = plugins_api(
					'plugin_information',
					[
						'slug' => $slug,
						'fields' => [
							'active_installs' => true,
							'short_description' => false,
							'description' => false,
							'sections' => false,
							'versions' => false,
							'banners' => false,
							'rating' => false,
							'ratings' => false,
							'downloaded' => false,
							'last_updated' => false,
							'added' => false,
							'tags' => false,
							'requires' => false,
							'requires_php' => false,
							'tested' => false,
							'homepage' => false,
							'donate_link' => false,
						],
					]
				);

				if (function_exists('is_wp_error') && is_wp_error($info)) {
					continue;
				}

				if (is_object($info) && isset($info->active_installs)) {
					$cached[$slug] = (int) $info->active_installs;
				}
			}

			$ttl = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;
			set_transient($cache_key, $cached, $ttl);
		}

		$result = [];
		foreach ($clean_slugs as $slug) {
			if (isset($cached[$slug]) && (int) $cached[$slug] > 0) {
				$result[$slug] = (int) $cached[$slug];
			}
		}

		return $result;
	}
}

if (!function_exists('kitgenix_hub_get_wporg_ratings')) {
	/**
	 * Fetch WP.org ratings (percentage) for a set of plugin slugs.
	 *
	 * @param array<int,string> $slugs Plugin slugs.
	 * @return array<string,int> Map of slug => rating percentage (0-100)
	 */
	function kitgenix_hub_get_wporg_ratings(array $slugs): array {
		$slugs = array_values(array_unique(array_filter(array_map('strval', $slugs))));
		if (empty($slugs)) {
			return [];
		}

		$cache_key = 'kitgenix_hub_wporg_ratings_v1';
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			$missing = array_diff($slugs, array_keys($cached));
			if (empty($missing)) {
				return $cached;
			}
		} else {
			$cached = [];
			$missing = $slugs;
		}

		if (!function_exists('plugins_api')) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		foreach ($missing as $slug) {
			$info = plugins_api(
				'plugin_information',
				[
					'slug' => $slug,
					'fields' => [
						'rating' => true,
					],
				]
			);

			if (is_object($info) && isset($info->rating)) {
				$cached[$slug] = (int) $info->rating;
			}
		}

		set_transient($cache_key, $cached, DAY_IN_SECONDS);

		return $cached;
	}
}

if (!function_exists('kitgenix_hub_get_wporg_media')) {
	/**
	 * Fetch WP.org banner or icon artwork for a set of plugin slugs.
	 *
	 * @param array<int,string> $slugs Plugin slugs.
	 * @return array<string,array{url:string,type:string}> Map of slug => media payload.
	 */
	function kitgenix_hub_get_wporg_media(array $slugs): array {
		if (!function_exists('get_transient') || !function_exists('set_transient')) {
			return [];
		}

		$slugs = array_values(array_unique(array_filter(array_map('strval', $slugs))));
		if (empty($slugs)) {
			return [];
		}

		$cache_key = 'kitgenix_hub_wporg_media_v1';
		$cached = get_transient($cache_key);
		$cached = is_array($cached) ? $cached : [];
		$missing = array_diff($slugs, array_keys($cached));

		if (!empty($missing)) {
			if (!function_exists('plugins_api')) {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			}

			foreach ($missing as $slug) {
				$info = plugins_api(
					'plugin_information',
					[
						'slug' => $slug,
						'fields' => [
							'icons' => true,
							'banners' => true,
							'active_installs' => false,
							'rating' => false,
							'ratings' => false,
							'short_description' => false,
							'description' => false,
							'sections' => false,
							'versions' => false,
							'downloaded' => false,
							'last_updated' => false,
							'added' => false,
							'tags' => false,
							'requires' => false,
							'requires_php' => false,
							'tested' => false,
							'homepage' => false,
							'donate_link' => false,
						],
					]
				);

				if (function_exists('is_wp_error') && is_wp_error($info)) {
					continue;
				}

				$media_url = '';
				$media_type = '';

				if (is_object($info) && isset($info->banners)) {
					$banners = is_object($info->banners) ? get_object_vars($info->banners) : (is_array($info->banners) ? $info->banners : []);
					foreach (['high', 'low'] as $key) {
						if (!empty($banners[$key]) && is_string($banners[$key])) {
							$media_url = $banners[$key];
							$media_type = 'banner';
							break;
						}
					}
				}

				if ('' === $media_url && is_object($info) && isset($info->icons)) {
					$icons = is_object($info->icons) ? get_object_vars($info->icons) : (is_array($info->icons) ? $info->icons : []);
					foreach (['svg', '2x', '1x', 'default'] as $key) {
						if (!empty($icons[$key]) && is_string($icons[$key])) {
							$media_url = $icons[$key];
							$media_type = 'icon';
							break;
						}
					}
				}

				$cached[$slug] = $media_url ? [
					'url' => $media_url,
					'type' => $media_type,
				] : [];
			}

			$ttl = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;
			set_transient($cache_key, $cached, $ttl);
		}

		$result = [];
		foreach ($slugs as $slug) {
			if (!empty($cached[$slug]['url'])) {
				$result[$slug] = [
					'url' => (string) $cached[$slug]['url'],
					'type' => !empty($cached[$slug]['type']) ? (string) $cached[$slug]['type'] : 'icon',
				];
			}
		}

		return $result;
	}
}

if (!function_exists('kitgenix_render_admin_page')) {
	function kitgenix_render_admin_page(): void {
		$allowed = current_user_can('manage_options') || (class_exists('WooCommerce') && current_user_can('manage_woocommerce'));
		if (!$allowed) {
			wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'kitgenix-stock-sync-for-woocommerce'));
		}

		if (!function_exists('is_plugin_active')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins_data = function_exists('get_plugins') ? get_plugins() : [];

		$plugins = [
			[
				'id'       => 'turnstile',
				'name'     => __('CAPTCHA for Cloudflare Turnstile', 'kitgenix-stock-sync-for-woocommerce'),
				'slug'     => 'kitgenix-captcha-for-cloudflare-turnstile',
				'file'     => 'kitgenix-captcha-for-cloudflare-turnstile/kitgenix-captcha-for-cloudflare-turnstile.php',
				'page'     => 'kitgenix-captcha-for-cloudflare-turnstile',
				'requires' => __('Add Cloudflare Turnstile CAPTCHA to WordPress, WooCommerce, Elementor, and popular form plugins.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			[
				'id'       => 'custom_tabs',
				'name'     => __('Custom Tabs for WooCommerce', 'kitgenix-stock-sync-for-woocommerce'),
				'slug'     => 'kitgenix-custom-tabs-for-woocommerce',
				'file'     => 'kitgenix-custom-tabs-for-woocommerce/kitgenix-custom-tabs-for-woocommerce.php',
				'page'     => 'kitgenix-custom-tabs-for-woocommerce',
				'requires' => __('Add custom WooCommerce product tabs with per-product content, global tabs, and lightweight controls.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			[
				'id'       => 'documents',
				'name'     => __('Document Manager', 'kitgenix-stock-sync-for-woocommerce'),
				'slug'     => 'kitgenix-document-manager',
				'file'     => 'kitgenix-document-manager/kitgenix-document-manager.php',
				'page'     => 'kitgenix-document-manager',
				'requires' => __('Manage document downloads with stable links, version history, and private file access.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			[
				'id'       => 'tracking',
				'name'     => __('Order Tracking for WooCommerce', 'kitgenix-stock-sync-for-woocommerce'),
				'slug'     => 'kitgenix-order-tracking-for-woocommerce',
				'file'     => 'kitgenix-order-tracking-for-woocommerce/kitgenix-order-tracking-for-woocommerce.php',
				'page'     => 'kitgenix-order-tracking-for-woocommerce-analytics',
				'requires' => __('Add WooCommerce order tracking, multi-shipment support, email tracking links, and a public customer tracking page.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			[
				'id'       => 'pdf',
				'name'     => __('PDF Invoicing for WooCommerce', 'kitgenix-stock-sync-for-woocommerce'),
				'slug'     => 'kitgenix-pdf-invoicing-for-woocommerce',
				'file'     => 'kitgenix-pdf-invoicing-for-woocommerce/kitgenix-pdf-invoicing-for-woocommerce.php',
				'page'     => 'kitgenix-pdf-invoicing-settings',
				'requires' => __('Generate WooCommerce PDF invoices, receipts, packing slips, and credit notes with secure downloads and configurable email attachments.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			[
				'id'       => 'stock',
				'name'     => __('Stock Sync for WooCommerce', 'kitgenix-stock-sync-for-woocommerce'),
				'slug'     => 'kitgenix-stock-sync-for-woocommerce',
				'file'     => 'kitgenix-stock-sync-for-woocommerce/kitgenix-stock-sync-for-woocommerce.php',
				'page'     => 'kitgenix-stock-sync-for-woocommerce',
				'requires' => __('Sync WooCommerce stock between stores with secure master-child inventory updates and signed REST requests.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			[
				'id'       => 'multistore',
				'name'     => __('MultiStore Sync', 'kitgenix-stock-sync-for-woocommerce'),
				'slug'     => 'kitgenix-multistore-sync',
				'file'     => 'kitgenix-multistore-sync/kitgenix-multistore-sync.php',
				'page'     => 'kitgenix-multistore-sync',
				'requires' => __('Sync WooCommerce products, prices, media, and metadata between multiple stores with a secure master-child architecture.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			[
				'id'       => 'affiliate',
				'name'     => __('Affiliate Link Manager', 'kitgenix-stock-sync-for-woocommerce'),
				'slug'     => 'kitgenix-affiliate-link-manager',
				'file'     => 'kitgenix-affiliate-link-manager/kitgenix-affiliate-link-manager.php',
				'page'     => 'kitgenix-affiliate-link-manager',
				'requires' => __('Manage affiliate short links, branded redirects, and click tracking from one WordPress dashboard.', 'kitgenix-stock-sync-for-woocommerce'),
			],
		];

		$slugs = [];
		foreach ($plugins as $plugin) {
			if (!empty($plugin['slug'])) {
				$slugs[] = (string) $plugin['slug'];
			}
		}
		$wporg_active_installs = kitgenix_hub_get_wporg_active_installs($slugs);
		$wporg_ratings = kitgenix_hub_get_wporg_ratings($slugs);
		$wporg_media = kitgenix_hub_get_wporg_media($slugs);
		$logo_url = plugins_url( 'assets/images/logos/kitgenix-favicon-purple.svg', __FILE__ );

		echo '<div class="wrap plugin-install-php kitgenix-hub-wrap">'
			. '<div class="kitgenix-hub">'
			. '<div class="kitgenix-hub-header">'
			. '<div class="kitgenix-hub-brand">'
			. '<img class="kitgenix-hub-logo" src="' . esc_url($logo_url) . '" alt="' . esc_attr__('Kitgenix', 'kitgenix-stock-sync-for-woocommerce') . '" />'
			. '<div class="kitgenix-hub-brand-copy">'
			. '<h1 class="kitgenix-hub-title">' . esc_html__('Discover and manage every Kitgenix plugin from one screen.', 'kitgenix-stock-sync-for-woocommerce') . '</h1>'
			. '<p class="kitgenix-hub-description">' . esc_html__('Install, activate, open, and review Kitgenix plugins.', 'kitgenix-stock-sync-for-woocommerce') . '</p>'
			. '</div>'
			. '</div>'
			. '<div class="kitgenix-hub-social-links">'
			. '<a href="https://kitgenix.com" target="_blank" rel="noopener noreferrer" aria-label="Website" title="Website"><img src="' . esc_url(plugins_url('assets/images/social-media/globe-solid.svg', __FILE__)) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Website</span></a>'
			. '<a href="https://www.facebook.com/groups/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook Community" title="Facebook Community"><img src="' . esc_url(plugins_url('assets/images/social-media/facebook-solid.svg', __FILE__)) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Facebook Community</span></a>'
			. '<a href="https://www.facebook.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook"><img src="' . esc_url(plugins_url('assets/images/social-media/facebook-solid.svg', __FILE__)) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Facebook</span></a>'
			. '<a href="https://www.instagram.com/kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram"><img src="' . esc_url(plugins_url('assets/images/social-media/instagram-solid.svg', __FILE__)) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Instagram</span></a>'
			. '<a href="https://www.youtube.com/@Kitgenix" target="_blank" rel="noopener noreferrer" aria-label="YouTube" title="YouTube"><img src="' . esc_url(plugins_url('assets/images/social-media/youtube-solid.svg', __FILE__)) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">YouTube</span></a>'
			. '<a href="https://www.reddit.com/r/Kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Reddit" title="Reddit"><img src="' . esc_url(plugins_url('assets/images/social-media/reddit-solid.svg', __FILE__)) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Reddit</span></a>'
			. '<a href="https://www.linkedin.com/company/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn"><img src="' . esc_url(plugins_url('assets/images/social-media/linkedin-solid.svg', __FILE__)) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">LinkedIn</span></a>'
			. '<a href="https://x.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="X" title="X"><img src="' . esc_url(plugins_url('assets/images/social-media/x-solid.svg', __FILE__)) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">X</span></a>'
			. '<a href="https://www.tiktok.com/@kitgenix" target="_blank" rel="noopener noreferrer" aria-label="TikTok" title="TikTok"><img src="' . esc_url(plugins_url('assets/images/social-media/tiktok-solid.svg', __FILE__)) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">TikTok</span></a>'
			. '<a href="https://github.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="GitHub" title="GitHub"><img src="' . esc_url(plugins_url('assets/images/social-media/github-solid.svg', __FILE__)) . '" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">GitHub</span></a>'
			. '</div>'
			. '</div>'
			. '<div class="kitgenix-hub-grid">';
		foreach ($plugins as $p) {
			$id = (string) $p['id'];
			$file = (string) $p['file'];
			$installed = isset($plugins_data[$file]);
			$active = false;
			if ($installed && function_exists('is_plugin_active')) {
				$active = is_plugin_active($file) || (function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($file));
			}

			$version_badge = '';
			if ($installed && !empty($plugins_data[$file]['Version'])) {
				$version_badge = '<span class="kitgenix-badge muted">v' . esc_html((string) $plugins_data[$file]['Version']) . '</span>';
			}

			$installs_badge = '';
			$slug = !empty($p['slug']) ? (string) $p['slug'] : '';
			if ($slug && !empty($wporg_active_installs[$slug])) {
				$count = (int) $wporg_active_installs[$slug];
				$count_text = function_exists('number_format_i18n') ? number_format_i18n($count) : (string) $count;
				/* translators: %s is the number of active installs and may include a thousands separator, e.g. "1,234". The "+" suffix is literal. */
				// translators: %s is the number of active installs and may include a thousands separator, e.g. "1,234". The "+" suffix is literal.
				$installs_badge = '<span class="kitgenix-badge muted">' . esc_html(sprintf(__('%s+ installs', 'kitgenix-stock-sync-for-woocommerce'), $count_text)) . '</span>';
			}

			$rating_badge = '';
			if ($slug && isset($wporg_ratings[$slug]) && (int) $wporg_ratings[$slug] > 0) {
				$rating_percent = (int) $wporg_ratings[$slug];
				$stars = ($rating_percent / 100) * 5;
				$stars_text = function_exists('number_format_i18n') ? number_format_i18n($stars, 1) : number_format($stars, 1);
				/* translators: %s is the average rating out of 5 with one decimal place, e.g. "4.5". The star symbol (★) precedes the number. */
				// translators: %s is the average rating out of 5 with one decimal place, e.g. "4.5". The star symbol (★) precedes the number.
				$rating_badge = '<span class="kitgenix-badge muted">' . esc_html(sprintf(__('★ %s/5', 'kitgenix-stock-sync-for-woocommerce'), $stars_text)) . '</span>';
			}

			$status_badge = '';
			if (!$installed) {
				$status_badge = '<span class="kitgenix-badge muted">' . esc_html__('Not installed', 'kitgenix-stock-sync-for-woocommerce') . '</span>';
			} elseif ($active) {
				$status_badge = '<span class="kitgenix-badge ok">' . esc_html__('Active', 'kitgenix-stock-sync-for-woocommerce') . '</span>';
			} else {
				$status_badge = '<span class="kitgenix-badge warn">' . esc_html__('Installed (Inactive)', 'kitgenix-stock-sync-for-woocommerce') . '</span>';
			}

			$card_media = '';
			if ($slug && !empty($wporg_media[$slug]['url'])) {
				$media_type = (!empty($wporg_media[$slug]['type']) && 'banner' === (string) $wporg_media[$slug]['type']) ? 'banner' : 'icon';
				$card_media = '<div class="kitgenix-card-media kitgenix-card-media-' . esc_attr($media_type) . '"><img class="kitgenix-card-media-image" src="' . esc_url((string) $wporg_media[$slug]['url']) . '" alt="" loading="lazy" /></div>';
			}

			$actions = '';
			if (!$installed) {
				if (current_user_can('install_plugins')) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- wp_nonce_url() generates a nonce-protected URL
					$install_url = wp_nonce_url(
						admin_url('update.php?action=install-plugin&plugin=' . rawurlencode((string) $p['slug'])),
						'install-plugin_' . (string) $p['slug']
					);
					$actions .= '<a class="button button-primary" href="' . esc_url($install_url) . '">' . esc_html__('Install', 'kitgenix-stock-sync-for-woocommerce') . '</a>';
				} else {
					$actions .= '<a class="button button-primary" href="' . esc_url(admin_url('plugin-install.php?s=' . rawurlencode('kitgenix') . '&tab=search&type=term')) . '">' . esc_html__('Install', 'kitgenix-stock-sync-for-woocommerce') . '</a>';
				}
			} elseif (!$active) {
				if (current_user_can('activate_plugins')) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- wp_nonce_url() generates a nonce-protected URL
					$activate_url = wp_nonce_url(
						admin_url('plugins.php?action=activate&plugin=' . rawurlencode($file) . '&plugin_status=all&paged=1&s='),
						'activate-plugin_' . $file
					);
					$actions .= '<a class="button button-primary" href="' . esc_url($activate_url) . '">' . esc_html__('Activate', 'kitgenix-stock-sync-for-woocommerce') . '</a>';
				} else {
					$actions .= '<span class="description">' . esc_html__('You do not have permission to activate plugins.', 'kitgenix-stock-sync-for-woocommerce') . '</span>';
				}
			} else {
				$open_url = !empty($p['page']) ? admin_url('admin.php?page=' . rawurlencode((string) $p['page'])) : '';
				if ($open_url) {
					$actions .= '<a class="button button-primary" href="' . esc_url($open_url) . '">' . esc_html__('Open', 'kitgenix-stock-sync-for-woocommerce') . '</a>';
				}
			}

			$info_url = admin_url('plugin-install.php?tab=plugin-information&plugin=' . rawurlencode((string) $p['slug']) . '&TB_iframe=true&width=600&height=550');
			$actions .= ' <a class="button button-secondary thickbox open-plugin-details-modal" href="' . esc_url($info_url) . '">' . esc_html__('Details', 'kitgenix-stock-sync-for-woocommerce') . '</a>';
			if ($slug) {
				$review_url = 'https://wordpress.org/support/plugin/' . rawurlencode($slug) . '/reviews/#new-post';
				$support_url = 'https://wordpress.org/support/plugin/' . rawurlencode($slug) . '/';
				$actions .= ' <a class="button button-secondary" href="' . esc_url($review_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Review', 'kitgenix-stock-sync-for-woocommerce') . '</a>';
				$actions .= ' <a class="button button-secondary" href="' . esc_url($support_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Support Forum', 'kitgenix-stock-sync-for-woocommerce') . '</a>';
			}

			$allowed_kitgenix_html = [
				'a' => ['href' => true, 'class' => true, 'target' => true, 'rel' => true],
				'span' => ['class' => true, 'aria-label' => true],
				'strong' => [],
			];
			$card_media_allowed_html = [
				'div' => ['class' => true],
				'img' => ['class' => true, 'src' => true, 'alt' => true, 'loading' => true],
			];

			echo '<div class="kitgenix-card" data-kitgenix-plugin="' . esc_attr(sanitize_key($id)) . '">'
				. wp_kses($card_media, $card_media_allowed_html)
				. '<div class="kitgenix-card-body">'
				. '<div class="kitgenix-card-badges">' . wp_kses(trim($status_badge . ' ' . $version_badge . ' ' . $rating_badge . ' ' . $installs_badge), $allowed_kitgenix_html) . '</div>'
				. '<p class="kitgenix-card-title">' . esc_html((string) $p['name']) . '</p>'
				. '<p class="kitgenix-card-desc">' . esc_html((string) $p['requires']) . '</p>'
				. '</div>'
				. '<div class="kitgenix-card-actions">' . wp_kses($actions, $allowed_kitgenix_html) . '</div>'
				. '</div>';
		}

		echo '</div></div></div>';
	}
}

if (!function_exists('kitgenix_stock_sync_for_woocommerce_register_admin_ui_style')) {
	function kitgenix_stock_sync_for_woocommerce_register_admin_ui_style(): void {
		if (!is_admin()) {
			return;
		}

		if (function_exists('wp_style_is') && wp_style_is('kitgenix-admin-ui', 'registered')) {
			return;
		}

		$ver = defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION') ? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION : '1.0.2';
		$css_file = plugin_dir_path(__FILE__) . 'assets/css/kitgenix-admin-ui.css';
		$css_ver = file_exists($css_file) ? (string) filemtime($css_file) : $ver;

		wp_register_style('kitgenix-admin-ui', plugins_url('assets/css/kitgenix-admin-ui.css', __FILE__), [], $css_ver);
	}
}
add_action('admin_enqueue_scripts', 'kitgenix_stock_sync_for_woocommerce_register_admin_ui_style', 5);

/**
 * Enqueue Kitgenix hub styles on the top-level Kitgenix page.
 */
function kitgenix_stock_sync_for_woocommerce_enqueue_hub_assets(string $hook_suffix): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a harmless `page` query arg for asset enqueue checks
	$page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
	if ('kitgenix' !== $page && 'toplevel_page_kitgenix' !== $hook_suffix) {
		return;
	}

	add_thickbox();
	wp_enqueue_style( 'plugin-install' );

	if (function_exists('wp_style_is') && (wp_style_is('kitgenix-hub', 'enqueued') || wp_style_is('kitgenix-hub', 'registered'))) {
		return;
	}

	$css_file = KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_DIR . 'assets/css/kitgenix-hub.css';
	if (!is_readable($css_file)) {
		return;
	}
	$ver = (string) filemtime($css_file);

	wp_register_style(
		'kitgenix-hub',
		plugins_url('assets/css/kitgenix-hub.css', __FILE__),
		[],
		$ver
	);
	wp_enqueue_style( 'kitgenix-hub' );

	wp_register_style(
		'kitgenix-admin-ui',
		plugins_url('assets/css/kitgenix-admin-ui.css', __FILE__),
		[],
		$ver
	);
	wp_enqueue_style( 'kitgenix-admin-ui' );
}
add_action('admin_enqueue_scripts', 'kitgenix_stock_sync_for_woocommerce_enqueue_hub_assets');

/**
 * Includes
 */
require_once KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_DIR . 'includes/class-kitgenix-stock-sync-for-woocommerce.php';
require_once KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_DIR . 'includes/class-kitgenix-stock-sync-for-woocommerce-settings.php';
require_once KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_DIR . 'includes/class-kitgenix-stock-sync-for-woocommerce-security.php';
require_once KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_DIR . 'includes/class-kitgenix-stock-sync-for-woocommerce-rest.php';
require_once KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_DIR . 'includes/class-kitgenix-stock-sync-for-woocommerce-sync.php';
require_once KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_DIR . 'includes/class-kitgenix-stock-sync-for-woocommerce-admin.php';

register_activation_hook(__FILE__, function () {
	if (!class_exists('WooCommerce')) {
		if (!function_exists('deactivate_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins(plugin_basename(__FILE__));
		wp_die(esc_html__('Kitgenix Stock Sync for WooCommerce requires WooCommerce to be installed and active. The plugin has been deactivated.', 'kitgenix-stock-sync-for-woocommerce'));
	}

	Kitgenix_Stock_Sync_For_WooCommerce_Settings::ensure_defaults();
	set_transient('kitgenix_stock_sync_for_woocommerce_do_activation_redirect', 1, 30);
});

/**
 * Perform the activation redirect once.
 */
add_action('admin_init', function () {
	if (!get_transient('kitgenix_stock_sync_for_woocommerce_do_activation_redirect')) {
		return;
	}
	delete_transient('kitgenix_stock_sync_for_woocommerce_do_activation_redirect');

	// If bulk-activated, don't redirect.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if (isset($_GET['activate-multi'])) {
		return;
	}

	$target = admin_url('admin.php?page=kitgenix-stock-sync-for-woocommerce');
	wp_safe_redirect(esc_url_raw($target));
	exit;
});

register_deactivation_hook(__FILE__, function () {
	delete_transient('kitgenix_stock_sync_for_woocommerce_do_activation_redirect');
});

/**
 * "Settings" link on Plugins screen.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function (array $links): array {
	$url = admin_url('admin.php?page=kitgenix-stock-sync-for-woocommerce');
	$links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'kitgenix-stock-sync-for-woocommerce') . '</a>';
	return $links;
});

add_action('before_woocommerce_init', function () {
	if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

/**
 * Boot only when WooCommerce is active.
 */
add_action('plugins_loaded', function () {
	if (!class_exists('WooCommerce')) {
		return;
	}
	Kitgenix_Stock_Sync_For_WooCommerce::instance()->init();
}, 20);
