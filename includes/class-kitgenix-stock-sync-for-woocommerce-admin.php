<?php
defined('ABSPATH') || exit;

final class Kitgenix_Stock_Sync_For_WooCommerce_Admin {

	private Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings;
	private Kitgenix_Stock_Sync_For_WooCommerce_Security $security;
	private Kitgenix_Stock_Sync_For_WooCommerce_Sync $sync;

	/** @var string|null */
	private ?string $page_hook = null;

	public function __construct(
		Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings,
		Kitgenix_Stock_Sync_For_WooCommerce_Security $security,
		Kitgenix_Stock_Sync_For_WooCommerce_Sync $sync
	) {
		$this->settings = $settings;
		$this->security = $security;
		$this->sync     = $sync;
	}

	public function hooks(): void {
		if (function_exists('\kitgenix_ensure_admin_menu')) {
			\kitgenix_ensure_admin_menu();
		}
		add_action('admin_menu', [$this, 'admin_menu'], 50);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
		add_action('admin_notices', [$this, 'admin_notices']);
	}

	public function admin_menu(): void {
		$parent = Kitgenix_Stock_Sync_For_WooCommerce_Settings::parent_menu_slug();

		$this->page_hook = (string) add_submenu_page(
			$parent,
			'Stock Sync',
			'Stock Sync',
			'manage_woocommerce',
			'kitgenix-stock-sync-for-woocommerce',
			[$this, 'render_page']
		);
	}

	/**
	 * Enqueue admin styles only on our settings page.
	 */
	public function enqueue_assets(string $hook_suffix = ''): void {
		// Prefer checking the `page` query arg (robust across environments).
		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
		if ($page !== 'kitgenix-stock-sync-for-woocommerce') {
			if (!($this->page_hook && $hook_suffix === $this->page_hook)) {
				return;
			}
		}

		$css_file = defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_DIR')
			? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_DIR . 'assets/css/admin.css'
			: '';
		$ver = (defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION') ? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION : '1.0.1');
		if ($css_file && file_exists($css_file)) {
			$ver = (string) filemtime($css_file);
		}

		$base_url = (defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL') ? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL : plugin_dir_url(__FILE__));

		wp_enqueue_style('kitgenix-stock-sync-for-woocommerce-admin-ui');

		wp_enqueue_style(
			'kitgenix-stock-sync-for-woocommerce-admin',
			$base_url . 'assets/css/admin.css',
			[ 'kitgenix-stock-sync-for-woocommerce-admin-ui' ],
			$ver
		);

		$js_file = defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_DIR')
			? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_DIR . 'assets/js/admin.js'
			: '';
		$js_ver = (defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION') ? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION : '1.0.1');
		if ($js_file && file_exists($js_file)) {
			$js_ver = (string) filemtime($js_file);
			wp_enqueue_script(
				'kitgenix-stock-sync-for-woocommerce-admin',
				$base_url . 'assets/js/admin.js',
				[],
				$js_ver,
				true
			);
		}

		$kitgenix_tabs_js_file = defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_DIR')
			? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_DIR . 'assets/js/kitgenix-admin-tabs.js'
			: '';
		$kitgenix_tabs_js_ver = $js_ver;
		if ($kitgenix_tabs_js_file && file_exists($kitgenix_tabs_js_file)) {
			$kitgenix_tabs_js_ver = (string) filemtime($kitgenix_tabs_js_file);
			wp_enqueue_script(
				'kitgenix-admin-tabs',
				$base_url . 'assets/js/kitgenix-admin-tabs.js',
				[],
				$kitgenix_tabs_js_ver,
				true
			);
		}

		$kitgenix_components_js_file = defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_DIR')
			? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_DIR . 'assets/js/kitgenix-admin-components.js'
			: '';
		$kitgenix_components_js_ver = $js_ver;
		if ($kitgenix_components_js_file && file_exists($kitgenix_components_js_file)) {
			$kitgenix_components_js_ver = (string) filemtime($kitgenix_components_js_file);
			wp_enqueue_script(
				'kitgenix-admin-components',
				$base_url . 'assets/js/kitgenix-admin-components.js',
				[],
				$kitgenix_components_js_ver,
				true
			);
		}
	}

	public function admin_notices(): void {
		if (!current_user_can('manage_woocommerce')) return;

		$notices = $this->settings->pop_notices();
		foreach ($notices as $n) {
			$type = $n['type'] ?? 'info';
			$msg  = $n['message'] ?? '';
			if ($msg === '') continue;
			echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($msg) . '</p></div>';
		}
	}

	private function fmt_time(int $ts): string {
		if ($ts <= 0) return '–';
		return gmdate('Y-m-d H:i:s', $ts) . ' UTC';
	}

	/**
	 * Small inline icon set used inside card heads / empty states on this
	 * plugin's admin screens. Output is static trusted markup (no user
	 * input), safe to echo unescaped.
	 */
	private static function icon(string $name): string {
		$icons = [
			'pulse'     => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2.5 10h3l2-5 3 10 2-5h5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
			'warning'   => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 3.5 17.5 16h-15L10 3.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 8.25v3.25M10 14h.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
			'gear'      => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 12.75a2.75 2.75 0 1 0 0-5.5 2.75 2.75 0 0 0 0 5.5Z" stroke="currentColor" stroke-width="1.5"/><path d="M16.5 10c0 .38-.03.75-.09 1.11l1.6 1.24-1.5 2.6-1.88-.63a6.4 6.4 0 0 1-1.92 1.11l-.29 1.97h-3l-.29-1.97a6.4 6.4 0 0 1-1.92-1.11l-1.88.63-1.5-2.6 1.6-1.24A5.9 5.9 0 0 1 3.5 10c0-.38.03-.75.09-1.11L2 7.65l1.5-2.6 1.88.63A6.4 6.4 0 0 1 7.3 4.57L7.59 2.6h3l.29 1.97c.7.24 1.35.62 1.92 1.11l1.88-.63 1.5 2.6-1.6 1.24c.06.36.09.73.09 1.11Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>',
			'exclude'   => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M5.5 5.5l9 9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',  // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- icon-library array key ('exclude' icon name), not a get_posts()/WP_Query parameter.
			'stores'    => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 8.5V16h14V8.5M2.5 8.5l1.4-4.5h12.2l1.4 4.5H2.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M8 16v-4h4v4" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>',
			'link'      => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8.5 11.5 12 8M9 6l.7-.7a3 3 0 0 1 4.24 4.24L13.2 10M11 14l-.7.7A3 3 0 0 1 6.06 10.5L6.8 9.8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
			'add'       => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
			'list'      => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 5.5h9M7 10h9M7 14.5h9M3.5 5.5h.01M3.5 10h.01M3.5 14.5h.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
			'edit'      => '<svg width="15" height="15" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M13.5 3.5 16.5 6.5 7 16H4v-3L13.5 3.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>',
			'trash'     => '<svg width="15" height="15" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 6h12M8 6V4.5A1.5 1.5 0 0 1 9.5 3h1A1.5 1.5 0 0 1 12 4.5V6M6 6l.6 9.4A1.5 1.5 0 0 0 8.1 17h3.8a1.5 1.5 0 0 0 1.5-1.6L14 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
			'tools'     => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12.5 4.5a3 3 0 0 0-3.9 3.9l-6.1 6.1 2 2 6.1-6.1a3 3 0 0 0 3.9-3.9l-2.1 2.1-2-2 2.1-2.1Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>',
			'reconcile' => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 6.5h9l-2-2M16 13.5H7l2 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
			'sync'      => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M16 10a6 6 0 0 1-10.6 3.8M4 10a6 6 0 0 1 10.6-3.8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M4 13.8V17h3.2M16 6.2V3h-3.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
			'audit'     => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 3.5h7l3 3V16a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M7 9h6M7 12h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
			'check'     => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 10.5 8 14.5 16 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
			'logs'      => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3.5" y="3" width="13" height="14" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M6.5 7h7M6.5 10h7M6.5 13h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
			'backlog'   => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 6v4.5l3 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.5"/></svg>',
			'search'    => '<svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.5"/><path d="M17 17l-3.5-3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
			'heart'     => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 17s-6.5-4.06-6.5-8.4A3.6 3.6 0 0 1 10 6.2a3.6 3.6 0 0 1 6.5 2.4C16.5 12.94 10 17 10 17Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>',
			'chat'      => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.5 5.5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H9l-3.5 3v-3a2 2 0 0 1-2-2v-6Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>',
			'star'      => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 3.2l2.02 4.1 4.53.66-3.28 3.2.78 4.52L10 13.5l-4.05 2.13.78-4.52-3.28-3.2 4.53-.66L10 3.2Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>',
			'copy'      => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="7" y="7" width="9.5" height="9.5" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M13 7V4.5A1.5 1.5 0 0 0 11.5 3h-7A1.5 1.5 0 0 0 3 4.5v7A1.5 1.5 0 0 0 4.5 13H7" stroke="currentColor" stroke-width="1.5"/></svg>',
			'users'     => '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="7" cy="7" r="2.5" stroke="currentColor" stroke-width="1.5"/><path d="M2.5 16c.5-3 2.2-4.5 4.5-4.5s4 1.5 4.5 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="14" cy="7.5" r="2" stroke="currentColor" stroke-width="1.5"/><path d="M12.5 11.3c1.8.2 3 1.5 3.5 4.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
			'shield'    => '<svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M10 2.5l6 2.2v4.4c0 4.2-2.6 7.4-6 8.4-3.4-1-6-4.2-6-8.4V4.7l6-2.2Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M7.3 10.2l1.9 1.9 3.5-3.9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
			'paypal'    => '<svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6.7 15.5H4.9a.3.3 0 0 1-.3-.35L6.5 3.8a.5.5 0 0 1 .5-.4h4.4c2 0 3.4 1.2 3.1 3.2-.3 2.4-2.1 3.7-4.3 3.7H8.6a.5.5 0 0 0-.5.4l-.7 3.9a.5.5 0 0 1-.5.4Z" fill="currentColor" opacity=".55"/><path d="M9.2 15.5H7.4a.3.3 0 0 1-.3-.35L9 3.8a.5.5 0 0 1 .5-.4h4.4c2 0 3.4 1.2 3.1 3.2-.3 2.4-2.1 3.7-4.3 3.7h-1.6a.5.5 0 0 0-.5.4l-.7 3.9a.5.5 0 0 1-.5.4Z" fill="currentColor"/></svg>',
		];
		return $icons[$name] ?? '';
	}

	/**
	 * Read a single POST field without processing the whole input stack.
	 *
	 * @phpcsSuppress WordPress.Security.NonceVerification.Missing
	 * @phpcsSuppress WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	 */
	private function post_value(string $key): mixed {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each helper sanitizes/validates per field
		if (!isset($_POST[$key])) {
			return null;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized/validated by caller
		return wp_unslash($_POST[$key]);
	}

	/**
	 * Read a text POST value (unslashed + sanitized).
	 *
	 * @phpcsSuppress WordPress.Security.NonceVerification.Missing
	 * @phpcsSuppress WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	 */
	private function read_post_text(string $key, string $default = ''): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized --
		// helper unslashes and sanitizes returned value; callers are expected to verify nonces where appropriate
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- helper unslashes+sanitizes
		$raw = $this->post_value($key);
		if ($raw === null) {
			return $default;
		}
		if (is_array($raw)) {
			return $default;
		}
		return sanitize_text_field(is_string($raw) ? $raw : (string) $raw);
	}

	/**
	 * Read a URL POST value (unslashed + raw-escaped).
	 *
	 * @phpcsSuppress WordPress.Security.NonceVerification.Missing
	 * @phpcsSuppress WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	 */
	private function read_post_url(string $key, string $default = ''): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized --
		// helper unslashes and sanitizes returned value; callers are expected to verify nonces where appropriate
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- helper unslashes+sanitizes
		$raw = $this->post_value($key);
		if ($raw === null) {
			return $default;
		}
		if (is_array($raw)) {
			return $default;
		}
		return esc_url_raw(trim(is_string($raw) ? $raw : (string) $raw));
	}

	/**
	 * Read an int POST value (unslashed + cast).
	 *
	 * @phpcsSuppress WordPress.Security.NonceVerification.Missing
	 * @phpcsSuppress WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	 */
	private function read_post_int(string $key, int $default = 0): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized --
		// helper unslashes and sanitizes returned value; callers are expected to verify nonces where appropriate
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- helper unslashes+sanitizes
		$raw = $this->post_value($key);
		if ($raw === null) {
			return $default;
		}
		if (is_array($raw)) {
			return $default;
		}
		return absint($raw);
	}

	/**
	 * Read a boolean POST value (presence-only checkbox).
	 *
	 * @phpcsSuppress WordPress.Security.NonceVerification.Missing
	 * @phpcsSuppress WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	 */
	private function read_post_bool(string $key): bool {
		// Checkbox fields are present only when checked.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- presence-only boolean helper
		return $this->post_value($key) !== null;
	}

	/**
	 * Read a newline/comma-separated SKU list from POST (unslashed).
	 *
	 * @phpcsSuppress WordPress.Security.NonceVerification.Missing
	 * @phpcsSuppress WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	 */
	private function read_post_sku_list(string $key): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized --
		// unslash then parse list; callers must verify nonces where appropriate
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- unslash then parse list
		$raw = $this->post_value($key);
		if ($raw === null) {
			return [];
		}
		if (is_array($raw)) {
			return [];
		}
		$raw = is_string($raw) ? $raw : (string) $raw;
		$parts = preg_split('/\r\n|\r|\n|,/', $raw);
		$skus = is_array($parts) ? array_map('trim', $parts) : [];
		$skus = array_values(array_filter($skus, fn($s) => $s !== ''));
		$skus = array_values(array_map(static fn($s) => sanitize_text_field((string) $s), $skus));
		return $skus;
	}

	private function get_active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : '';
		$allowed = ['status', 'configuration', 'stores', 'tools', 'conflicts', 'logs', 'support'];
		return in_array($tab, $allowed, true) ? $tab : 'status';
	}

	private function redirect_to_tab(string $tab): void {
		$tab = sanitize_key($tab);
		$allowed = ['status', 'configuration', 'stores', 'tools', 'conflicts', 'logs', 'support'];
		if (!in_array($tab, $allowed, true)) {
			$tab = 'status';
		}
		$url = add_query_arg(
			[
				'page' => 'kitgenix-stock-sync-for-woocommerce',
				'tab' => $tab,
			],
			admin_url('admin.php')
		);
		wp_safe_redirect($url);
		exit;
	}

	private function audit_transient_key(): string {
		$user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
		return 'kitgenix_stock_sync_for_woocommerce_kss_audit_result_' . max(0, $user_id);
	}

	private function get_last_audit_result(): ?array {
		$val = get_transient($this->audit_transient_key());
		return is_array($val) ? $val : null;
	}

	/**
	 * Render the admin settings page.
	 *
	 * Individual POST handlers perform nonce verification with `check_admin_referer()`.
	 *
	 * @phpcsSuppress WordPress.Security.NonceVerification.Missing
	 */
	public function render_page(): void {
		if (!current_user_can('manage_woocommerce')) return;

		$opt = $this->settings->get_all();
		$active_tab = $this->get_active_tab();
		$audit_result = $this->get_last_audit_result();

		// POST handlers (use PRG redirects to avoid double-submit)
		// Configuration form: role/store settings + exclusions.
		if (isset($_POST['kss_save_config']) && check_admin_referer('kss_save_config')) {
			$role = ($this->read_post_text('role', 'child') === 'master') ? 'master' : 'child';
			$opt['role'] = $role;

			$opt['this_store_name'] = $this->read_post_text('this_store_name', (string) ($opt['this_store_name'] ?? ''));
			$opt['strict_checkout_validation'] = $this->read_post_bool('strict_checkout_validation');
			$opt['exclusions']['skus'] = $this->read_post_sku_list('excluded_skus');

			$strategy = $this->read_post_text('checkout_validation_failure_strategy', 'fail_open');
			$opt['checkout_validation_failure_strategy'] = in_array($strategy, ['fail_open', 'fail_closed', 'stale_cache'], true) ? $strategy : 'fail_open';
			$opt['checkout_stale_cache_minutes'] = max(1, $this->read_post_int('checkout_stale_cache_minutes', 30));

			$this->settings->update_all($opt);
			$this->settings->set_notice('success', 'Kitgenix Stock Sync settings saved.');
			$this->redirect_to_tab('configuration');
		}

		// Stores/connection form: child → master credentials only.
		if (isset($_POST['kss_save_connection']) && check_admin_referer('kss_save_connection')) {
			$opt = $this->settings->get_all();
			$role = (string) ($opt['role'] ?? 'child');
			if ($role !== 'child') {
				$this->settings->set_notice('error', 'Master Connection can only be set when this store role is Child.');
				$this->redirect_to_tab('stores');
			}

			$new_master_url = $this->read_post_url('master_url', (string) ($opt['master']['url'] ?? ''));
			if (!Kitgenix_Stock_Sync_For_WooCommerce_Security::is_acceptable_remote_url($new_master_url)) {
				$this->settings->set_notice('error', 'Master URL must use HTTPS. Connection not saved.');
				$this->redirect_to_tab('stores');
			}
			$opt['master']['url'] = $new_master_url;
			$opt['master']['store_id'] = $this->read_post_text('master_store_id', (string) ($opt['master']['store_id'] ?? ''));
			$opt['master']['secret'] = $this->read_post_text('master_secret', (string) ($opt['master']['secret'] ?? ''));

			$this->settings->update_all($opt);
			$this->settings->set_notice('success', 'Master connection saved.');
			$this->redirect_to_tab('stores');
		}

		if ($this->settings->is_child() && isset($_POST['kss_rotate_master_secret']) && check_admin_referer('kss_save_connection')) {
			$new_secret = wp_generate_password(32, true, true);
			$this->settings->rotate_master_secret($new_secret);
			$this->settings->set_notice('success', sprintf(
				/* translators: %s: newly generated shared secret */
				'New Master secret generated: %s – update this value on the Master (Stores tab) within the overlap window shown there.',
				$new_secret
			));
			$this->redirect_to_tab('stores');
		}

		if ($this->settings->is_master() && isset($_POST['kss_add_child']) && check_admin_referer('kss_save_children')) {
			$children = is_array($opt['children'] ?? null) ? $opt['children'] : [];

			$child_id = $this->read_post_text('child_id', wp_generate_uuid4());
			$child_name = $this->read_post_text('child_name', 'Child Store');
			$child_url = $this->read_post_url('child_url', '');
			if (!Kitgenix_Stock_Sync_For_WooCommerce_Security::is_acceptable_remote_url($child_url)) {
				$this->settings->set_notice('error', 'Child URL must use HTTPS. Child store not added.');
				$this->redirect_to_tab('stores');
			}
			$child_secret = $this->read_post_text('child_secret', '');
			if ($child_secret === '') {
				$child_secret = wp_generate_password(32, true, true);
			}

			$children[] = [
				'id' => trim($child_id),
				'name' => trim($child_name),
				'url' => trim($child_url),
				'secret' => trim($child_secret),
				'enabled' => true,
			];

			$opt['children'] = $children;
			$this->settings->update_all($opt);
			$this->settings->set_notice('success', 'Child store added.');
			$this->redirect_to_tab('stores');
		}

		if ($this->settings->is_master() && isset($_POST['kss_update_child']) && check_admin_referer('kss_save_children')) {
			$cid = $this->read_post_text('edit_child_id', '');
			$edit_url = $this->read_post_url('edit_child_url', '');
			if (!Kitgenix_Stock_Sync_For_WooCommerce_Security::is_acceptable_remote_url($edit_url)) {
				$this->settings->set_notice('error', 'Child URL must use HTTPS. Child store not updated.');
				$this->redirect_to_tab('stores');
			}
			$patch = [
				'enabled' => $this->read_post_bool('edit_child_enabled'),
				'name' => $this->read_post_text('edit_child_name', ''),
				'url' => $edit_url,
				'secret' => $this->read_post_text('edit_child_secret', ''),
			];

			$ok = $this->settings->update_child($cid, $patch);
			$this->settings->set_notice($ok ? 'success' : 'error', $ok ? 'Child store updated.' : 'Could not update child store.');
			$this->redirect_to_tab('stores');
		}

		if ($this->settings->is_master() && isset($_POST['kss_rotate_child_secret']) && check_admin_referer('kss_save_children')) {
			$cid = $this->read_post_text('rotate_child_id', '');
			$new_secret = wp_generate_password(32, true, true);
			$ok = $this->settings->rotate_child_secret($cid, $new_secret);
			$this->settings->set_notice(
				$ok ? 'success' : 'error',
				$ok ? sprintf(
					/* translators: %s: newly generated shared secret */
					'New secret generated: %s – update this value on the Child store within the overlap window.',
					$new_secret
				) : 'Could not rotate secret for that child store.'
			);
			$this->redirect_to_tab('stores');
		}

		if ($this->settings->is_master() && isset($_POST['kss_remove_child']) && check_admin_referer('kss_save_children')) {
			$remove_id = $this->read_post_text('remove_id', '');
			$children = is_array($opt['children'] ?? null) ? $opt['children'] : [];
			$children = array_values(array_filter($children, fn($c) => is_array($c) && ((string)($c['id'] ?? '') !== $remove_id)));
			$opt['children'] = $children;
			$this->settings->update_all($opt);
			$this->settings->set_notice('success', 'Child store removed.');
			$this->redirect_to_tab('stores');
		}

		if (isset($_POST['kss_test']) && check_admin_referer('kss_test_connection')) {
			$ok = $this->test_connection();
			$this->settings->set_notice($ok ? 'success' : 'error', $ok ? 'Connection test successful.' : 'Connection test failed. Check WooCommerce Logs for details.');
			$this->redirect_to_tab('tools');
		}

		if ($this->settings->is_master() && isset($_POST['kss_reconcile']) && check_admin_referer('kss_tools')) {
			$mode = $this->read_post_text('reconcile_mode', 'all') === 'selected' ? 'selected' : 'all';
			$this->sync->start_reconcile([
				'per_page' => $this->read_post_int('reconcile_per_page', 200),
				'mode' => $mode,
				'skus' => $mode === 'selected' ? $this->read_post_sku_list('reconcile_skus') : [],
				'dry_run' => $this->read_post_bool('reconcile_dry_run'),
				'differences_only' => $this->read_post_bool('reconcile_differences_only'),
			]);
			$this->redirect_to_tab('tools');
		}

		if ($this->settings->is_master() && isset($_POST['kss_reconcile_resume']) && check_admin_referer('kss_tools')) {
			$this->sync->resume_reconcile();
			$this->settings->set_notice('success', 'Reconcile resumed.');
			$this->redirect_to_tab('tools');
		}

		if ($this->settings->is_master() && isset($_POST['kss_reconcile_cancel']) && check_admin_referer('kss_tools')) {
			$this->sync->cancel_reconcile();
			$this->settings->set_notice('success', 'Reconcile cancelled.');
			$this->redirect_to_tab('tools');
		}

		if ($this->settings->is_master() && isset($_POST['kss_push_skus']) && check_admin_referer('kss_tools')) {
			$skus = $this->read_post_sku_list('manual_skus');
			$this->sync->master_push_skus($skus);
			$this->settings->set_notice('success', 'Manual SKU sync queued to children.');
			$this->redirect_to_tab('tools');
		}

		if ($this->settings->is_master() && isset($_POST['kss_audit']) && check_admin_referer('kss_tools')) {
			$skus = $this->read_post_sku_list('audit_skus');
			$audit_result = $this->sync->master_audit_children_stock($skus);
			set_transient($this->audit_transient_key(), $audit_result, 10 * MINUTE_IN_SECONDS);
			$this->settings->set_notice('success', 'Audit completed. See results below and on the Conflicts tab.');
			$this->redirect_to_tab('tools');
		}

		if ($this->settings->is_master() && isset($_POST['kss_scan_conflicts']) && check_admin_referer('kss_conflicts')) {
			$this->sync->scan_for_conflicts();
			$this->settings->set_notice('success', 'Duplicate-SKU scan completed.');
			$this->redirect_to_tab('conflicts');
		}

		if (isset($_POST['kss_clear_conflicts']) && check_admin_referer('kss_conflicts')) {
			$this->settings->set_conflicts_report([]);
			$this->settings->set_notice('success', 'Conflict report cleared.');
			$this->redirect_to_tab('conflicts');
		}

		if (isset($_POST['kss_backlog_retry']) && check_admin_referer('kss_logs')) {
			$id = $this->read_post_text('backlog_id', '');
			$ok = $this->sync->retry_backlog_item($id);
			$this->settings->set_notice($ok ? 'success' : 'error', $ok ? 'Retry attempted – check the Status/Logs tab for the result.' : 'Could not retry that item (not found, or the original payload was too large to retain).');
			$this->redirect_to_tab('logs');
		}

		if (isset($_POST['kss_backlog_discard']) && check_admin_referer('kss_logs')) {
			$id = $this->read_post_text('backlog_id', '');
			$ok = $this->sync->discard_backlog_item($id);
			$this->settings->set_notice($ok ? 'success' : 'error', $ok ? 'Backlog item discarded.' : 'Backlog item not found.');
			$this->redirect_to_tab('logs');
		}

		if (isset($_POST['kss_clear_event_log']) && check_admin_referer('kss_logs')) {
			$this->settings->clear_event_log();
			$this->settings->set_notice('success', 'Event log cleared.');
			$this->redirect_to_tab('logs');
		}
		if (isset($_POST['kss_clear_backlog']) && check_admin_referer('kss_logs')) {
			$this->settings->clear_backlog();
			$this->settings->set_notice('success', 'Backlog cleared.');
			$this->redirect_to_tab('logs');
		}

		// (re-)load options after any redirects are skipped (e.g., GET requests).
		$opt = $this->settings->get_all();
		$role = (string) ($opt['role'] ?? 'child');
		$reconcile = $this->settings->reconcile_state();
		$event_log = array_reverse($this->settings->get_event_log());
		$backlog = array_reverse($this->settings->get_backlog());
		$health = $this->settings->get_health();
		$conflicts = $this->settings->get_conflicts_report();

		$action_scheduler_url = admin_url('admin.php?page=wc-status&tab=action-scheduler');

		$ver = defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION') ? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION : '1.0.0';
		$logo_url = (defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL') ? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL : plugin_dir_url(__FILE__)) . 'assets/images/logos/kitgenix-primary-favicon.svg';
		$social_base = (defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL') ? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL : plugin_dir_url(__FILE__)) . 'assets/images/social-media/';
		$social_base = (defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL') ? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL : plugin_dir_url(__FILE__)) . 'assets/images/social-media/';

		?>
		<div class="wrap kitgenix-admin-app kitgenix-stock-sync-for-woocommerce-admin" data-kitgenix-tabs data-kitgenix-default-tab="<?php echo esc_attr($active_tab); ?>" id="kitgenix-stock-sync-for-woocommerce-admin-app">

			<div class="kitgenix-topbar">
				<div class="kitgenix-topbar-left">
					<a class="kitgenix-topbar-brand" href="<?php echo esc_url(admin_url('admin.php?page=kitgenix')); ?>" title="Kitgenix">
						<img class="kitgenix-topbar-logo" src="<?php echo esc_url($logo_url); ?>" alt="Kitgenix" width="28" height="28" />
					</a>
					<span class="kitgenix-topbar-divider" aria-hidden="true"></span>
					<div class="kitgenix-topbar-plugin-info">
						<span class="kitgenix-topbar-title"><?php echo esc_html__('Stock Sync for WooCommerce', 'kitgenix-stock-sync-for-woocommerce'); ?></span>
						<span class="kitgenix-topbar-version">v<?php echo esc_html($ver); ?></span>
					</div>
				</div>
				<div class="kitgenix-topbar-center">
					<ul class="kitgenix-topbar-menu" role="tablist" aria-label="<?php echo esc_attr__('Navigation', 'kitgenix-stock-sync-for-woocommerce'); ?>">
						<?php
						$tabs = [
							'status' => __('Status', 'kitgenix-stock-sync-for-woocommerce'),
							'configuration' => __('Configuration', 'kitgenix-stock-sync-for-woocommerce'),
							'stores' => __('Stores', 'kitgenix-stock-sync-for-woocommerce'),
							'tools' => __('Tools', 'kitgenix-stock-sync-for-woocommerce'),
							'conflicts' => __('Conflicts', 'kitgenix-stock-sync-for-woocommerce'),
							'logs' => __('Logs', 'kitgenix-stock-sync-for-woocommerce'),
							'support' => __('Support', 'kitgenix-stock-sync-for-woocommerce'),
						];
						foreach ($tabs as $key => $label) {
							$url = add_query_arg([
								'page' => 'kitgenix-stock-sync-for-woocommerce',
								'tab' => $key,
							], admin_url('admin.php'));
							$url .= '#kitgenix-tab-' . $key;
							$active = ($active_tab === $key);
							echo '<li class="kitgenix-menu-item ' . ($active ? 'kitgenix-active' : '') . '"><a class="kitgenix-menu-link kitgenix-tab-trigger' . ($active ? ' kitgenix-active' : '') . '" href="' . esc_url($url) . '" data-kitgenix-tab="' . esc_attr($key) . '"' . ($active ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a></li>';
						}
						?>
					</ul>
				</div>
				<div class="kitgenix-topbar-right" aria-label="Topbar actions">
					<div class="kitgenix-topbar-search">
						<button type="button" class="kitgenix-topbar-icon-btn kitgenix-search-toggle" aria-label="<?php echo esc_attr__('Search settings', 'kitgenix-stock-sync-for-woocommerce'); ?>" aria-expanded="false"><svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14.5232 13.4627L17.7355 16.6742L16.6742 17.7355L13.4627 14.5232C12.2678 15.4812 10.7815 16.0022 9.25 16C5.524 16 2.5 12.976 2.5 9.25C2.5 5.524 5.524 2.5 9.25 2.5C12.976 2.5 16 5.524 16 9.25C16.0022 10.7815 15.4812 12.2678 14.5232 13.4627ZM13.0187 12.9062C13.9706 11.9274 14.5021 10.6153 14.5 9.25C14.5 6.349 12.1502 4 9.25 4C6.349 4 4 6.349 4 9.25C4 12.1502 6.349 14.5 9.25 14.5C10.6153 14.5021 11.9274 13.9706 12.9062 13.0187L13.0187 12.9062V12.9062Z" fill="currentColor"></path></svg></button>
						<div class="kitgenix-search-panel">
							<span class="kitgenix-search-icon" aria-hidden="true"><svg width="15" height="15" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14.5232 13.4627L17.7355 16.6742L16.6742 17.7355L13.4627 14.5232C12.2678 15.4812 10.7815 16.0022 9.25 16C5.524 16 2.5 12.976 2.5 9.25C2.5 5.524 5.524 2.5 9.25 2.5C12.976 2.5 16 5.524 16 9.25C16.0022 10.7815 15.4812 12.2678 14.5232 13.4627ZM13.0187 12.9062C13.9706 11.9274 14.5021 10.6153 14.5 9.25C14.5 6.349 12.1502 4 9.25 4C6.349 4 4 6.349 4 9.25C4 12.1502 6.349 14.5 9.25 14.5C10.6153 14.5021 11.9274 13.9706 12.9062 13.0187L13.0187 12.9062V12.9062Z" fill="currentColor"></path></svg></span>
							<input type="search" class="kitgenix-topbar-search-input" placeholder="<?php echo esc_attr__('Search settings…', 'kitgenix-stock-sync-for-woocommerce'); ?>" aria-label="<?php echo esc_attr__('Search settings', 'kitgenix-stock-sync-for-woocommerce'); ?>" autocomplete="off" />
							<kbd class="kitgenix-search-kbd" title="Press / or ⌘K to search">/</kbd>
							<button type="button" class="kitgenix-search-clear" aria-label="<?php echo esc_attr__('Clear search', 'kitgenix-stock-sync-for-woocommerce'); ?>" style="display:none;">&times;</button>
						</div>
					</div>
					<button type="button" class="kitgenix-topbar-icon-btn kitgenix-theme-toggle" aria-label="<?php echo esc_attr__('Toggle color theme', 'kitgenix-stock-sync-for-woocommerce'); ?>" title="<?php echo esc_attr__('Toggle color theme', 'kitgenix-stock-sync-for-woocommerce'); ?>">
						<svg class="kitgenix-theme-icon-light" width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="3.25" stroke="currentColor" stroke-width="1.3"></circle><path d="M10 2.5v2M10 15.5v2M17.5 10h-2M4.5 10h-2M15.3 4.7l-1.4 1.4M6.1 13.9l-1.4 1.4M15.3 15.3l-1.4-1.4M6.1 6.1 4.7 4.7" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"></path></svg>
						<svg class="kitgenix-theme-icon-dark" width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M16.5 12.3A6.5 6.5 0 0 1 7.7 3.5a6.5 6.5 0 1 0 8.8 8.8Z" fill="currentColor"></path></svg>
					</button>
					<a class="kitgenix-topbar-icon-btn" href="<?php echo esc_url(admin_url('admin.php?page=kitgenix')); ?>" title="<?php echo esc_attr__('Kitgenix Hub', 'kitgenix-stock-sync-for-woocommerce'); ?>" aria-label="Kitgenix Hub"><svg width="15" height="15" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.25 3.25H9.25V9.25H3.25V3.25ZM10.75 3.25H16.75V9.25H10.75V3.25ZM3.25 10.75H9.25V16.75H3.25V10.75ZM10.75 10.75H16.75V16.75H10.75V10.75Z" fill="currentColor"></path></svg></a>
					<button type="button" class="kitgenix-topbar-hamburger" aria-label="<?php echo esc_attr__('Toggle navigation', 'kitgenix-stock-sync-for-woocommerce'); ?>"><span></span><span></span><span></span></button>
				</div>
			</div>

			<div class="kitgenix-settings-layout">
				<div class="kitgenix-settings-content" id="kitgenix-settings-content" tabindex="-1">

			<div<?php echo $active_tab === 'status' ? '' : ' hidden="hidden"'; ?> class="kitgenix-panel-stack" data-kitgenix-tab-panel="status">

				<?php
				$lem = (string) ($health['last_error_message'] ?? '');
				$lec = (string) ($health['last_error_code'] ?? '');
				$last_error_ts = (int) ($health['last_outbound_error'] ?? 0);
				$last_error_recoverable = $lec !== '' && Kitgenix_Stock_Sync_For_WooCommerce_Settings::is_recoverable_code($lec);
				?>

				<div class="kitgenix-card">
					<div class="kitgenix-card-head">
						<div class="kitgenix-card-head-main">
							<span class="kitgenix-card-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('pulse'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
							<div class="kitgenix-card-head-text">
								<h2><?php echo esc_html__('Store status', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
								<p><?php echo esc_html__('Live connection and sync health for this store.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
							</div>
						</div>
					</div>
					<div class="kitgenix-card-body">
						<div class="kitgenix-stat-grid">
							<div class="kitgenix-stat-tile">
								<div class="kitgenix-stat-tile-head">
									<span class="kitgenix-stat-tile-label"><?php echo esc_html__('Role', 'kitgenix-stock-sync-for-woocommerce'); ?></span>
								</div>
								<div class="kitgenix-stat-tile-value" style="font-size:18px;"><?php echo esc_html(ucfirst($this->settings->role())); ?></div>
							</div>
							<div class="kitgenix-stat-tile">
								<div class="kitgenix-stat-tile-head">
									<span class="kitgenix-stat-tile-label"><?php echo esc_html__('Last inbound event', 'kitgenix-stock-sync-for-woocommerce'); ?></span>
								</div>
								<div class="kitgenix-stat-tile-value" style="font-size:15px;"><?php echo esc_html($this->fmt_time((int)($health['last_inbound_event'] ?? 0))); ?></div>
							</div>
							<div class="kitgenix-stat-tile">
								<div class="kitgenix-stat-tile-head">
									<span class="kitgenix-stat-tile-label"><?php echo esc_html__('Last outbound success', 'kitgenix-stock-sync-for-woocommerce'); ?></span>
								</div>
								<div class="kitgenix-stat-tile-value" style="font-size:15px;"><?php echo esc_html($this->fmt_time((int)($health['last_outbound_success'] ?? 0))); ?></div>
							</div>
							<div class="kitgenix-stat-tile">
								<div class="kitgenix-stat-tile-head">
									<span class="kitgenix-stat-tile-label"><?php echo esc_html__('Last outbound error', 'kitgenix-stock-sync-for-woocommerce'); ?></span>
								</div>
								<div class="kitgenix-stat-tile-value" style="font-size:15px;"><?php echo esc_html($this->fmt_time($last_error_ts)); ?></div>
							</div>
						</div>

						<?php if ($lem !== ''): ?>
							<?php
							// Recoverable codes (clock drift, replay, timeout, connection error, 5xx) are
							// shown as a warning rather than an error so this card doesn't read as "broken"
							// for routine transient friction. See Settings::is_recoverable_code().
							$lem_notice_class = $last_error_recoverable ? 'kitgenix-notice-warning' : 'kitgenix-notice-error';
							?>
							<div class="kitgenix-notice <?php echo esc_attr($lem_notice_class); ?>" role="status" style="margin-top:16px;">
								<span class="kitgenix-notice-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('warning'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
								<div class="kitgenix-notice-body">
									<p class="kitgenix-notice-title"><?php echo esc_html__('Last outbound error message', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
									<p class="kitgenix-notice-text"><code><?php echo esc_html($lem); ?></code></p>
									<?php if ($lec !== ''): ?>
										<?php $lec_info = Kitgenix_Stock_Sync_For_WooCommerce_Settings::get_category_and_note($lec); ?>
										<p class="kitgenix-notice-text"><span class="kitgenix-badge <?php echo esc_attr($last_error_recoverable ? 'warning' : 'danger'); ?>"><?php echo esc_html($lec_info['category']); ?></span> <?php echo esc_html($lec_info['note']); ?></p>
									<?php endif; ?>
								</div>
							</div>
						<?php endif; ?>

						<div class="kitgenix-settings-group" style="margin-top:18px;">
							<div class="kitgenix-setting-row">
								<div class="kitgenix-setting-row-label">
									<label><?php echo esc_html__('This Store ID', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
									<p class="kitgenix-setting-row-desc"><?php echo esc_html__('The unique identifier for this store, used when pairing with other stores.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
								</div>
								<div class="kitgenix-setting-row-control">
									<code><?php echo esc_html($opt['this_store_id'] ?? ''); ?></code>
								</div>
							</div>
							<div class="kitgenix-setting-row">
								<div class="kitgenix-setting-row-label">
									<label><?php echo esc_html__('Action Scheduler', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
									<p class="kitgenix-setting-row-desc"><?php echo esc_html__('Review queued and completed background sync tasks.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
								</div>
								<div class="kitgenix-setting-row-control">
									<a class="button" href="<?php echo esc_url($action_scheduler_url); ?>"><?php echo esc_html__('Open Scheduled Actions', 'kitgenix-stock-sync-for-woocommerce'); ?></a>
								</div>
							</div>
						</div>
					</div>
				</div>

				<?php
					$connection_rows = [];
					if ($this->settings->is_child()) {
						$connection_rows[] = ['name' => __('Master', 'kitgenix-stock-sync-for-woocommerce'), 'health' => $this->settings->get_master_health()];
					} else {
						foreach ($this->settings->children() as $c) {
							if (!is_array($c)) continue;
							$connection_rows[] = [
								'name' => (string) (($c['name'] ?? '') !== '' ? $c['name'] : ($c['id'] ?? '')),
								'health' => is_array($c['health'] ?? null) ? $c['health'] : Kitgenix_Stock_Sync_For_WooCommerce_Settings::default_health(),
								'enabled' => (bool) ($c['enabled'] ?? true),
							];
						}
					}
					$status_badge_class = ['ok' => 'success', 'never' => 'neutral', 'stale' => 'warning', 'error' => 'danger'];
					?>
					<div class="kitgenix-card">
						<div class="kitgenix-card-head">
							<div class="kitgenix-card-head-main">
								<span class="kitgenix-card-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
								<div class="kitgenix-card-head-text">
									<h2><?php echo esc_html__('Connection health', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
									<p><?php echo esc_html__('Per-store inbound/outbound activity, remote versions, and status.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
								</div>
							</div>
						</div>
						<div class="kitgenix-card-body">
							<?php if (empty($connection_rows)): ?>
								<div class="kitgenix-empty-state">
									<span class="kitgenix-empty-state-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
									<h3 class="kitgenix-empty-state-title"><?php echo esc_html__('No counterpart stores configured yet', 'kitgenix-stock-sync-for-woocommerce'); ?></h3>
									<p class="kitgenix-empty-state-desc"><?php echo esc_html__('Configure the Master connection or add Child stores on the Stores tab.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
								</div>
							<?php else: ?>
								<div class="kitgenix-table-wrap">
									<table class="kitgenix-table">
										<thead>
											<tr>
												<th><?php echo esc_html__('Store', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
												<th><?php echo esc_html__('Status', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
												<th><?php echo esc_html__('Last inbound', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
												<th><?php echo esc_html__('Last outbound success', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
												<th><?php echo esc_html__('Last error', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
												<th><?php echo esc_html__('Remote WC', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
												<th><?php echo esc_html__('Remote plugin', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ($connection_rows as $row): $h = $row['health']; $st = Kitgenix_Stock_Sync_For_WooCommerce_Settings::derive_status($h); ?>
												<tr>
													<td><?php echo esc_html($row['name']); ?><?php if (isset($row['enabled']) && !$row['enabled']): ?> <span class="kitgenix-badge muted"><?php echo esc_html__('Disabled', 'kitgenix-stock-sync-for-woocommerce'); ?></span><?php endif; ?></td>
													<td><span class="kitgenix-badge <?php echo esc_attr($status_badge_class[$st] ?? 'neutral'); ?>"><?php echo esc_html(ucfirst($st)); ?></span></td>
													<td><?php echo esc_html($this->fmt_time((int) ($h['last_inbound'] ?? 0))); ?></td>
													<td><?php echo esc_html($this->fmt_time((int) ($h['last_outbound_success'] ?? 0))); ?></td>
													<td><?php echo $h['last_error_message'] !== '' ? '<code>' . esc_html((string) $h['last_error_message']) . '</code>' : '–'; ?></td>
													<td><?php echo esc_html((string) ($h['remote_wc_version'] !== '' ? $h['remote_wc_version'] : '–')); ?></td>
													<td><?php echo esc_html((string) ($h['remote_plugin_version'] !== '' ? $h['remote_plugin_version'] : '–')); ?></td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</div>

			<div<?php echo $active_tab === 'configuration' ? '' : ' hidden="hidden"'; ?> class="kitgenix-panel-stack" data-kitgenix-tab-panel="configuration">
				<form method="post">
					<?php wp_nonce_field('kss_save_config'); ?>

					<div class="kitgenix-card">
						<div class="kitgenix-card-head">
							<div class="kitgenix-card-head-main">
								<span class="kitgenix-card-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('gear'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
								<div class="kitgenix-card-head-text">
									<h2><?php echo esc_html__('Configuration', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
									<p><?php echo esc_html__('Core settings that define how this store participates in stock sync.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
								</div>
							</div>
						</div>
						<div class="kitgenix-card-body">
							<div class="kitgenix-settings-group">
								<div class="kitgenix-setting-row">
									<div class="kitgenix-setting-row-label">
										<label for="kss_this_store_name"><?php echo esc_html__('This Store Name', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										<p class="kitgenix-setting-row-desc"><?php echo esc_html__('A friendly label for this store, shown in logs and audits.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
									</div>
									<div class="kitgenix-setting-row-control">
										<input type="text" id="kss_this_store_name" class="regular-text" name="this_store_name" value="<?php echo esc_attr($opt['this_store_name'] ?? ''); ?>">
									</div>
								</div>

								<div class="kitgenix-setting-row">
									<div class="kitgenix-setting-row-label">
										<label><?php echo esc_html__('Role', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										<p class="kitgenix-setting-row-desc"><?php echo esc_html__('Master pushes stock to children; Child receives stock from a master.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
									</div>
									<div class="kitgenix-setting-row-control">
										<label style="display:block; margin-bottom:6px;"><input type="radio" name="role" value="master" <?php checked($role, 'master'); ?>> <?php echo esc_html__('Master', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										<label style="display:block;"><input type="radio" name="role" value="child" <?php checked($role, 'child'); ?>> <?php echo esc_html__('Child', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
									</div>
								</div>

								<div class="kitgenix-setting-row">
									<div class="kitgenix-setting-row-label">
										<label for="kss_strict_checkout"><?php echo esc_html__('Strict checkout validation (Child)', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										<p class="kitgenix-setting-row-desc"><?php echo esc_html__('Check Master stock at checkout to reduce oversells (fail-open if Master unreachable). Master connection details are configured under the Stores tab.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
									</div>
									<div class="kitgenix-setting-row-control">
										<label class="kitgenix-toggle">
											<input type="checkbox" id="kss_strict_checkout" class="kitgenix-toggle-input" name="strict_checkout_validation" <?php checked((bool) ($opt['strict_checkout_validation'] ?? true)); ?>>
											<span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span>
											<span class="kitgenix-toggle-label"><?php echo esc_html__('Enabled', 'kitgenix-stock-sync-for-woocommerce'); ?></span>
										</label>
									</div>
								</div>

								<?php $strategy = $this->settings->checkout_validation_failure_strategy(); ?>
								<div class="kitgenix-setting-row">
									<div class="kitgenix-setting-row-label">
										<label for="kss_checkout_strategy"><?php echo esc_html__('If Master cannot be verified at checkout', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										<p class="kitgenix-setting-row-desc"><?php echo esc_html__('Only applies when Master is unreachable, times out, or fails to respond – a definite "out of stock" from a reachable Master always blocks checkout, in every mode.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
									</div>
									<div class="kitgenix-setting-row-control">
										<select id="kss_checkout_strategy" name="checkout_validation_failure_strategy">
											<option value="fail_open" <?php selected($strategy, 'fail_open'); ?>><?php echo esc_html__('Fail open – allow checkout (default)', 'kitgenix-stock-sync-for-woocommerce'); ?></option>
											<option value="fail_closed" <?php selected($strategy, 'fail_closed'); ?>><?php echo esc_html__('Fail closed – block checkout', 'kitgenix-stock-sync-for-woocommerce'); ?></option>
											<option value="stale_cache" <?php selected($strategy, 'stale_cache'); ?>><?php echo esc_html__('Use last-known stock (if fresh enough)', 'kitgenix-stock-sync-for-woocommerce'); ?></option>
										</select>
									</div>
								</div>
								<div class="kitgenix-setting-row">
									<div class="kitgenix-setting-row-label">
										<label for="kss_checkout_stale_minutes"><?php echo esc_html__('Last-known stock max age (minutes)', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										<p class="kitgenix-setting-row-desc"><?php echo esc_html__('Only used in "Use last-known stock" mode above.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
									</div>
									<div class="kitgenix-setting-row-control">
										<input type="number" id="kss_checkout_stale_minutes" min="1" max="1440" name="checkout_stale_cache_minutes" value="<?php echo esc_attr((string) $this->settings->checkout_stale_cache_minutes()); ?>">
									</div>
								</div>
							</div>
						</div>
					</div>

					<div class="kitgenix-card">
						<div class="kitgenix-card-head">
							<div class="kitgenix-card-head-main">
								<span class="kitgenix-card-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('exclude'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
								<div class="kitgenix-card-head-text">
									<h2><?php echo esc_html__('Exclusions', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
									<p><?php echo esc_html__('SKUs to exclude from syncing (comma or new line separated).', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
								</div>
							</div>
						</div>
						<div class="kitgenix-card-body">
							<div class="kitgenix-settings-group">
								<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
									<div class="kitgenix-setting-row-label">
										<label for="kss_excluded_skus"><?php echo esc_html__('Excluded SKUs', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										<p class="kitgenix-setting-row-desc"><?php echo esc_html__('These SKUs will be ignored by inbound and outbound stock sync.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
									</div>
									<div class="kitgenix-setting-row-control">
										<textarea id="kss_excluded_skus" name="excluded_skus" class="large-text" rows="5"><?php echo esc_textarea(implode("\n", $this->settings->excluded_skus())); ?></textarea>
									</div>
								</div>
							</div>
						</div>
					</div>

					<div class="kitgenix-button-group kitgenix-button-group-end">
						<button type="submit" class="button button-primary" name="kss_save_config" value="1"><?php echo esc_html__('Save Settings', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
					</div>
				</form>
			</div>

			<div<?php echo $active_tab === 'stores' ? '' : ' hidden="hidden"'; ?> class="kitgenix-panel-stack" data-kitgenix-tab-panel="stores">

				<div class="kitgenix-card">
					<div class="kitgenix-card-head">
						<div class="kitgenix-card-head-main">
							<span class="kitgenix-card-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('stores'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
							<div class="kitgenix-card-head-text">
								<h2><?php echo esc_html__('Stores', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
								<p><?php echo esc_html__('Use this tab to connect the Child store to the Master, or to manage Child stores on the Master.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
							</div>
						</div>
					</div>
					<div class="kitgenix-card-body">
						<div class="kitgenix-settings-group">
							<div class="kitgenix-setting-row">
								<div class="kitgenix-setting-row-label">
									<label><?php echo esc_html__('This Store ID', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
								</div>
								<div class="kitgenix-setting-row-control">
									<code><?php echo esc_html($opt['this_store_id'] ?? ''); ?></code>
								</div>
							</div>
							<div class="kitgenix-setting-row">
								<div class="kitgenix-setting-row-label">
									<label><?php echo esc_html__('Role', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
								</div>
								<div class="kitgenix-setting-row-control">
									<span class="kitgenix-badge brand"><?php echo esc_html(ucfirst($this->settings->role())); ?></span>
								</div>
							</div>
						</div>
					</div>
				</div>

				<?php if ($role === 'child'): ?>
					<div class="kitgenix-card">
						<div class="kitgenix-card-head">
							<div class="kitgenix-card-head-main">
								<span class="kitgenix-card-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
								<div class="kitgenix-card-head-text">
									<h2><?php echo esc_html__('Master Connection (Child)', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
									<p><?php echo esc_html__('Credentials used to reach the Master store.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
								</div>
							</div>
						</div>
						<div class="kitgenix-card-body">
							<form method="post">
								<?php wp_nonce_field('kss_save_connection'); ?>
								<div class="kitgenix-settings-group">
									<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
										<div class="kitgenix-setting-row-label">
											<label for="kss_master_url"><?php echo esc_html__('Master URL', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										</div>
										<div class="kitgenix-setting-row-control">
											<input type="url" id="kss_master_url" class="regular-text" name="master_url" value="<?php echo esc_attr($opt['master']['url'] ?? ''); ?>" placeholder="https://masterstore.com">
										</div>
									</div>
									<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
										<div class="kitgenix-setting-row-label">
											<label for="kss_master_store_id"><?php echo esc_html__('Master Store ID', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										</div>
										<div class="kitgenix-setting-row-control">
											<input type="text" id="kss_master_store_id" class="regular-text" name="master_store_id" value="<?php echo esc_attr($opt['master']['store_id'] ?? ''); ?>">
										</div>
									</div>
									<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
										<div class="kitgenix-setting-row-label">
											<label for="kss_master_secret"><?php echo esc_html__('Shared Secret', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										</div>
										<div class="kitgenix-setting-row-control">
											<input type="text" id="kss_master_secret" class="regular-text" name="master_secret" value="<?php echo esc_attr($opt['master']['secret'] ?? ''); ?>">
										</div>
									</div>
								</div>
								<div class="kitgenix-button-group kitgenix-button-group-end" style="margin-top:18px;">
									<button type="submit" class="button button-primary" name="kss_save_connection" value="1"><?php echo esc_html__('Save Master Connection', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
									<button type="submit" class="button" name="kss_rotate_master_secret" value="1" onclick="return confirm('<?php echo esc_js(__('Generate a new secret? The old one keeps working for a short overlap window so you can update the Master too.', 'kitgenix-stock-sync-for-woocommerce')); ?>');"><?php echo esc_html__('Rotate Secret', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
								</div>
							</form>
						</div>
					</div>
				<?php else: ?>
					<div class="kitgenix-card">
						<div class="kitgenix-card-head">
							<div class="kitgenix-card-head-main">
								<span class="kitgenix-card-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('add'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
								<div class="kitgenix-card-head-text">
									<h2><?php echo esc_html__('Add Child Store (Master)', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
									<p><?php echo esc_html__('Add each child store and share a secret. The same secret must be configured on the Child under “Master Connection”.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
								</div>
							</div>
						</div>
						<div class="kitgenix-card-body">
							<form method="post">
								<?php wp_nonce_field('kss_save_children'); ?>
								<div class="kitgenix-settings-group">
									<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
										<div class="kitgenix-setting-row-label">
											<label for="kss_child_name"><?php echo esc_html__('Child Name', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										</div>
										<div class="kitgenix-setting-row-control">
											<input type="text" id="kss_child_name" class="regular-text" name="child_name" value="">
										</div>
									</div>
									<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
										<div class="kitgenix-setting-row-label">
											<label for="kss_child_url"><?php echo esc_html__('Child URL', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										</div>
										<div class="kitgenix-setting-row-control">
											<input type="url" id="kss_child_url" class="regular-text" name="child_url" placeholder="https://childstore.com">
										</div>
									</div>
									<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
										<div class="kitgenix-setting-row-label">
											<label for="kss_child_id"><?php echo esc_html__('Child Store ID', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										</div>
										<div class="kitgenix-setting-row-control">
											<input type="text" id="kss_child_id" class="regular-text" name="child_id" placeholder="<?php echo esc_attr__('paste the Child\'s This Store ID', 'kitgenix-stock-sync-for-woocommerce'); ?>">
										</div>
									</div>
									<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
										<div class="kitgenix-setting-row-label">
											<label for="kss_child_secret"><?php echo esc_html__('Shared Secret', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										</div>
										<div class="kitgenix-setting-row-control">
											<input type="text" id="kss_child_secret" class="regular-text" name="child_secret" placeholder="<?php echo esc_attr__('leave blank to auto-generate', 'kitgenix-stock-sync-for-woocommerce'); ?>">
										</div>
									</div>
								</div>
								<div class="kitgenix-button-group kitgenix-button-group-end" style="margin-top:18px;">
									<button type="submit" class="button button-primary" name="kss_add_child" value="1"><?php echo esc_html__('Add Child Store', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
								</div>
							</form>
						</div>
					</div>

					<?php
					$children = is_array($opt['children'] ?? null) ? $opt['children'] : [];
					?>
					<div class="kitgenix-card">
						<div class="kitgenix-card-head">
							<div class="kitgenix-card-head-main">
								<span class="kitgenix-card-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('list'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
								<div class="kitgenix-card-head-text">
									<h2><?php echo esc_html__('Configured Children', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
									<p><?php echo esc_html__('Child stores currently receiving stock updates from this Master.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
								</div>
							</div>
						</div>
						<div class="kitgenix-card-body">
							<?php if (empty($children)): ?>
								<div class="kitgenix-empty-state">
									<span class="kitgenix-empty-state-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('stores'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
									<h3 class="kitgenix-empty-state-title"><?php echo esc_html__('No child stores yet', 'kitgenix-stock-sync-for-woocommerce'); ?></h3>
									<p class="kitgenix-empty-state-desc"><?php echo esc_html__('Add a child store above to start pushing stock updates to it.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
								</div>
							<?php else: ?>
								<div class="kitgenix-table-wrap">
									<table class="kitgenix-table">
										<thead>
											<tr>
												<th style="width:70px;"><?php echo esc_html__('Enabled', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
												<th><?php echo esc_html__('Name', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
												<th><?php echo esc_html__('URL', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
												<th><?php echo esc_html__('Store ID', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
												<th><?php echo esc_html__('Secret', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
												<th class="kitgenix-table-actions-col"><?php echo esc_html__('Actions', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ($children as $child):
												if (!is_array($child)) continue;
												$child_id_val = (string) ($child['id'] ?? '');
												$child_uid = substr(md5($child_id_val !== '' ? $child_id_val : (string) wp_rand()), 0, 12);
												$edit_modal_id = 'kss-edit-child-' . $child_uid;
												$remove_modal_id = 'kss-remove-child-' . $child_uid;
											?>
												<tr data-kitgenix-table-row>
													<td>
														<form method="post" style="display:inline;">
															<?php wp_nonce_field('kss_save_children'); ?>
															<input type="hidden" name="edit_child_id" value="<?php echo esc_attr($child_id_val); ?>">
															<input type="hidden" name="edit_child_name" value="<?php echo esc_attr((string)($child['name'] ?? '')); ?>">
															<input type="hidden" name="edit_child_url" value="<?php echo esc_attr((string)($child['url'] ?? '')); ?>">
															<input type="hidden" name="edit_child_secret" value="<?php echo esc_attr((string)($child['secret'] ?? '')); ?>">
															<input type="hidden" name="kss_update_child" value="1">
															<label class="kitgenix-toggle">
																<input type="checkbox" class="kitgenix-toggle-input" name="edit_child_enabled" <?php checked((bool)($child['enabled'] ?? true)); ?> onchange="this.form.submit();">
																<span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span>
															</label>
														</form>
													</td>
													<td><?php echo esc_html((string)($child['name'] ?? '')); ?></td>
													<td><code><?php echo esc_html((string)($child['url'] ?? '')); ?></code></td>
													<td><code><?php echo esc_html($child_id_val); ?></code></td>
													<td><code><?php echo esc_html((string)($child['secret'] ?? '')); ?></code></td>
													<td>
														<div class="kitgenix-table-actions">
															<button type="button" class="kitgenix-table-action-btn" title="<?php echo esc_attr__('Edit', 'kitgenix-stock-sync-for-woocommerce'); ?>" data-kitgenix-modal-target="#<?php echo esc_attr($edit_modal_id); ?>"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('edit'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></button>
															<button type="button" class="kitgenix-table-action-btn kitgenix-table-action-danger" title="<?php echo esc_attr__('Remove', 'kitgenix-stock-sync-for-woocommerce'); ?>" data-kitgenix-modal-target="#<?php echo esc_attr($remove_modal_id); ?>"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('trash'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></button>
														</div>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>

								<?php foreach ($children as $child):
									if (!is_array($child)) continue;
									$child_id_val = (string) ($child['id'] ?? '');
									$child_uid = substr(md5($child_id_val !== '' ? $child_id_val : (string) wp_rand()), 0, 12);
									$edit_modal_id = 'kss-edit-child-' . $child_uid;
									$remove_modal_id = 'kss-remove-child-' . $child_uid;
								?>
									<div class="kitgenix-modal" id="<?php echo esc_attr($edit_modal_id); ?>" hidden>
										<div class="kitgenix-modal-backdrop" data-kitgenix-modal-close></div>
										<div class="kitgenix-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($edit_modal_id); ?>-title">
											<form method="post">
												<?php wp_nonce_field('kss_save_children'); ?>
												<input type="hidden" name="edit_child_id" value="<?php echo esc_attr($child_id_val); ?>">
												<div class="kitgenix-modal-header">
													<h2 class="kitgenix-modal-title" id="<?php echo esc_attr($edit_modal_id); ?>-title"><?php echo esc_html__('Edit child store', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
													<button type="button" class="kitgenix-modal-close" data-kitgenix-modal-close aria-label="<?php echo esc_attr__('Close', 'kitgenix-stock-sync-for-woocommerce'); ?>">&times;</button>
												</div>
												<div class="kitgenix-modal-body">
													<div class="kitgenix-settings-group">
														<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
															<div class="kitgenix-setting-row-label"><label><?php echo esc_html__('Name', 'kitgenix-stock-sync-for-woocommerce'); ?></label></div>
															<div class="kitgenix-setting-row-control"><input type="text" class="regular-text" name="edit_child_name" value="<?php echo esc_attr((string)($child['name'] ?? '')); ?>"></div>
														</div>
														<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
															<div class="kitgenix-setting-row-label"><label><?php echo esc_html__('URL', 'kitgenix-stock-sync-for-woocommerce'); ?></label></div>
															<div class="kitgenix-setting-row-control"><input type="url" class="regular-text" name="edit_child_url" value="<?php echo esc_attr((string)($child['url'] ?? '')); ?>"></div>
														</div>
														<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
															<div class="kitgenix-setting-row-label"><label><?php echo esc_html__('Secret', 'kitgenix-stock-sync-for-woocommerce'); ?></label></div>
															<div class="kitgenix-setting-row-control"><input type="text" class="regular-text" name="edit_child_secret" value="<?php echo esc_attr((string)($child['secret'] ?? '')); ?>"></div>
														</div>
														<div class="kitgenix-setting-row">
															<div class="kitgenix-setting-row-label"><label><?php echo esc_html__('Enabled', 'kitgenix-stock-sync-for-woocommerce'); ?></label></div>
															<div class="kitgenix-setting-row-control">
																<label class="kitgenix-toggle">
																	<input type="checkbox" class="kitgenix-toggle-input" name="edit_child_enabled" <?php checked((bool)($child['enabled'] ?? true)); ?>>
																	<span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span>
																</label>
															</div>
														</div>
													</div>
												</div>
												<div class="kitgenix-modal-footer">
													<button type="button" class="button" data-kitgenix-modal-close><?php echo esc_html__('Cancel', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
													<button type="submit" formaction="" name="kss_rotate_child_secret" value="1" formnovalidate class="button" onclick="this.form.rotate_child_id.value='<?php echo esc_js($child_id_val); ?>'; return confirm('<?php echo esc_js(__('Generate a new secret for this child? The old one keeps working for a short overlap window so you can update the Child too.', 'kitgenix-stock-sync-for-woocommerce')); ?>');"><?php echo esc_html__('Rotate Secret', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
													<input type="hidden" name="rotate_child_id" value="">
													<button type="submit" class="button button-primary" name="kss_update_child" value="1"><?php echo esc_html__('Save', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
												</div>
											</form>
										</div>
									</div>

									<div class="kitgenix-modal" id="<?php echo esc_attr($remove_modal_id); ?>" hidden>
										<div class="kitgenix-modal-backdrop" data-kitgenix-modal-close></div>
										<div class="kitgenix-modal-dialog kitgenix-modal-dialog-sm" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($remove_modal_id); ?>-title">
											<div class="kitgenix-modal-header">
												<h2 class="kitgenix-modal-title" id="<?php echo esc_attr($remove_modal_id); ?>-title"><?php echo esc_html__('Remove this child store?', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
												<button type="button" class="kitgenix-modal-close" data-kitgenix-modal-close aria-label="<?php echo esc_attr__('Close', 'kitgenix-stock-sync-for-woocommerce'); ?>">&times;</button>
											</div>
											<div class="kitgenix-modal-body">
												<p><?php echo esc_html(sprintf(/* translators: %s: child store name */ __('“%s” will stop receiving stock updates from this Master.', 'kitgenix-stock-sync-for-woocommerce'), (string)($child['name'] ?? ''))); ?></p>
											</div>
											<div class="kitgenix-modal-footer">
												<button type="button" class="button" data-kitgenix-modal-close><?php echo esc_html__('Cancel', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
												<form method="post">
													<?php wp_nonce_field('kss_save_children'); ?>
													<input type="hidden" name="remove_id" value="<?php echo esc_attr($child_id_val); ?>">
													<button type="submit" class="button button-primary" name="kss_remove_child" value="1"><?php echo esc_html__('Remove', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
												</form>
											</div>
										</div>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<div<?php echo $active_tab === 'tools' ? '' : ' hidden="hidden"'; ?> class="kitgenix-panel-stack" data-kitgenix-tab-panel="tools">

				<div class="kitgenix-card">
					<div class="kitgenix-card-head">
						<div class="kitgenix-card-head-main">
							<span class="kitgenix-card-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('tools'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
							<div class="kitgenix-card-head-text">
								<h2><?php echo esc_html__('Connection', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
								<p><?php echo esc_html__('Ping the counterpart store to confirm your credentials work.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
							</div>
						</div>
						<div class="kitgenix-card-head-actions">
							<form method="post">
								<?php wp_nonce_field('kss_test_connection'); ?>
								<button type="submit" class="button" name="kss_test" value="1"><?php echo esc_html__('Test Connection', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
							</form>
						</div>
					</div>
				</div>

				<?php if ($this->settings->is_master()): ?>
					<div class="kitgenix-card">
						<div class="kitgenix-card-head">
							<div class="kitgenix-card-head-main">
								<span class="kitgenix-card-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('reconcile'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
								<div class="kitgenix-card-head-text">
									<h2><?php echo esc_html__('Reconcile (Master)', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
									<p><?php echo esc_html__('Pushes authoritative stock state to all children in batches and establishes stable GIDs (needed for SKU rename sync).', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
								</div>
							</div>
							<div class="kitgenix-card-head-actions">
								<?php if (!empty($reconcile['running'])): ?>
									<span class="kitgenix-badge info kitgenix-badge-dot"><?php echo esc_html__('Running', 'kitgenix-stock-sync-for-woocommerce'); ?> &middot; <?php echo esc_html__('page', 'kitgenix-stock-sync-for-woocommerce'); ?> <?php echo esc_html((string)($reconcile['page'] ?? 0)); ?></span>
								<?php else: ?>
									<span class="kitgenix-badge neutral"><?php echo esc_html__('Idle', 'kitgenix-stock-sync-for-woocommerce'); ?></span>
								<?php endif; ?>
							</div>
						</div>
						<div class="kitgenix-card-body">
							<?php if (!empty($reconcile['started_at'])): ?>
								<div class="kitgenix-notice kitgenix-notice-info" role="status" style="margin-bottom:16px;">
									<div class="kitgenix-notice-body">
										<p class="kitgenix-notice-text">
											<?php
											printf(
												/* translators: 1: processed count, 2: total estimate, 3: differences found, 4: items pushed */
												esc_html__('Last run: processed %1$d of ~%2$d, %3$d difference(s) found, %4$d item(s) pushed.', 'kitgenix-stock-sync-for-woocommerce'),
												(int) ($reconcile['processed'] ?? 0),
												(int) ($reconcile['total_estimate'] ?? 0),
												(int) ($reconcile['differences_found'] ?? 0),
												(int) ($reconcile['pushed_count'] ?? 0)
											);
											?>
										</p>
									</div>
								</div>
							<?php endif; ?>

							<form method="post">
								<?php wp_nonce_field('kss_tools'); ?>
								<div class="kitgenix-settings-group">
									<div class="kitgenix-setting-row">
										<div class="kitgenix-setting-row-label">
											<label><?php echo esc_html__('Scope', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										</div>
										<div class="kitgenix-setting-row-control">
											<label style="display:block; margin-bottom:6px;"><input type="radio" name="reconcile_mode" value="all" checked> <?php echo esc_html__('All products', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
											<label style="display:block;"><input type="radio" name="reconcile_mode" value="selected"> <?php echo esc_html__('Selected SKUs (below)', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										</div>
									</div>
									<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
										<div class="kitgenix-setting-row-label">
											<label for="kss_reconcile_skus"><?php echo esc_html__('Selected SKUs', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										</div>
										<div class="kitgenix-setting-row-control">
											<textarea id="kss_reconcile_skus" name="reconcile_skus" class="large-text" rows="3" placeholder="SKU1&#10;SKU2"></textarea>
										</div>
									</div>
									<div class="kitgenix-setting-row">
										<div class="kitgenix-setting-row-label">
											<label for="kss_reconcile_per_page"><?php echo esc_html__('Batch size', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										</div>
										<div class="kitgenix-setting-row-control">
											<input type="number" id="kss_reconcile_per_page" min="50" max="500" name="reconcile_per_page" value="<?php echo esc_attr((string)($reconcile['per_page'] ?? 200)); ?>">
										</div>
									</div>
									<div class="kitgenix-setting-row">
										<div class="kitgenix-setting-row-label">
											<label for="kss_reconcile_dry_run"><?php echo esc_html__('Dry run', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
											<p class="kitgenix-setting-row-desc"><?php echo esc_html__('Compare only – never pushes to children. Populates the Conflicts tab.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
										</div>
										<div class="kitgenix-setting-row-control">
											<label class="kitgenix-toggle">
												<input type="checkbox" id="kss_reconcile_dry_run" class="kitgenix-toggle-input" name="reconcile_dry_run">
												<span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span>
											</label>
										</div>
									</div>
									<div class="kitgenix-setting-row">
										<div class="kitgenix-setting-row-label">
											<label for="kss_reconcile_differences_only"><?php echo esc_html__('Push differences only', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
											<p class="kitgenix-setting-row-desc"><?php echo esc_html__('When not a dry run: only push to a child when its state actually differs from Master.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
										</div>
										<div class="kitgenix-setting-row-control">
											<label class="kitgenix-toggle">
												<input type="checkbox" id="kss_reconcile_differences_only" class="kitgenix-toggle-input" name="reconcile_differences_only">
												<span class="kitgenix-toggle-track"><span class="kitgenix-toggle-thumb"></span></span>
											</label>
										</div>
									</div>
								</div>
								<div class="kitgenix-button-group" style="margin-top:16px;">
									<button type="submit" class="button button-primary" name="kss_reconcile" value="1"><?php echo esc_html__('Start Reconcile', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
									<?php if (!empty($reconcile['running'])): ?>
										<button type="submit" class="button" name="kss_reconcile_resume" value="1"><?php echo esc_html__('Resume', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
										<button type="submit" class="button" name="kss_reconcile_cancel" value="1"><?php echo esc_html__('Cancel', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
									<?php endif; ?>
								</div>
							</form>
						</div>
					</div>

					<div class="kitgenix-card">
						<div class="kitgenix-card-head">
							<div class="kitgenix-card-head-main">
								<span class="kitgenix-card-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('sync'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
								<div class="kitgenix-card-head-text">
									<h2><?php echo esc_html__('Manual SKU Sync (Master)', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
									<p><?php echo esc_html__('Paste SKUs (comma or new-line separated) to push stock state to all children.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
								</div>
							</div>
						</div>
						<div class="kitgenix-card-body">
							<form method="post">
								<?php wp_nonce_field('kss_tools'); ?>
								<div class="kitgenix-settings-group">
									<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
										<div class="kitgenix-setting-row-label">
											<label for="kss_manual_skus"><?php echo esc_html__('SKUs', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										</div>
										<div class="kitgenix-setting-row-control">
											<textarea id="kss_manual_skus" name="manual_skus" class="large-text" rows="3" placeholder="SKU1&#10;SKU2"></textarea>
										</div>
									</div>
								</div>
								<div class="kitgenix-button-group" style="margin-top:16px;">
									<button type="submit" class="button" name="kss_push_skus" value="1"><?php echo esc_html__('Push SKUs', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
								</div>
							</form>
						</div>
					</div>

					<div class="kitgenix-card">
						<div class="kitgenix-card-head">
							<div class="kitgenix-card-head-main">
								<span class="kitgenix-card-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('audit'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
								<div class="kitgenix-card-head-text">
									<h2><?php echo esc_html__('Audit Children (Master)', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
									<p><?php echo esc_html__('Paste SKUs (comma or new-line separated). This will query each child’s local stock fields and compare to Master.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
								</div>
							</div>
						</div>
						<div class="kitgenix-card-body">
							<form method="post">
								<?php wp_nonce_field('kss_tools'); ?>
								<div class="kitgenix-settings-group">
									<div class="kitgenix-setting-row kitgenix-setting-row-stacked">
										<div class="kitgenix-setting-row-label">
											<label for="kss_audit_skus"><?php echo esc_html__('SKUs', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
										</div>
										<div class="kitgenix-setting-row-control">
											<textarea id="kss_audit_skus" name="audit_skus" class="large-text" rows="3" placeholder="SKU1&#10;SKU2"></textarea>
										</div>
									</div>
								</div>
								<div class="kitgenix-button-group" style="margin-top:16px;">
									<button type="submit" class="button" name="kss_audit" value="1"><?php echo esc_html__('Run Audit', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
								</div>
							</form>
						</div>
					</div>
				<?php endif; ?>

				<?php if (is_array($audit_result) && $this->settings->is_master()):
					$children_res = $audit_result['children'] ?? [];
					$mismatched = $audit_result['mismatched_skus'] ?? [];
				?>
					<div class="kitgenix-card">
						<div class="kitgenix-card-head">
							<div class="kitgenix-card-head-main">
								<span class="kitgenix-card-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('audit'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
								<div class="kitgenix-card-head-text">
									<h2><?php echo esc_html__('Audit Results', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
									<p><?php echo esc_html__('Mismatched SKUs:', 'kitgenix-stock-sync-for-woocommerce'); ?> <code><?php echo esc_html(implode(', ', (array)$mismatched)); ?></code></p>
								</div>
							</div>
						</div>
						<div class="kitgenix-card-body">
							<div class="kitgenix-panel-stack">
								<?php foreach ($children_res as $cid => $cres): ?>
									<div class="kitgenix-card" data-kitgenix-collapsible>
										<button type="button" class="kitgenix-collapsible-trigger" aria-expanded="false">
											<span class="kitgenix-card-head-main">
												<span class="kitgenix-card-head-text">
													<span class="kitgenix-collapsible-title"><?php echo esc_html((string)($cres['name'] ?? $cid)); ?></span>
													<span class="kitgenix-collapsible-desc"><?php echo esc_html((string)$cid); ?></span>
												</span>
											</span>
											<svg class="kitgenix-collapsible-chevron" aria-hidden="true" width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
										</button>
										<div class="kitgenix-collapsible-panel" hidden>
											<div class="kitgenix-card-body">
												<?php if (!empty($cres['error'])): ?>
													<div class="kitgenix-notice kitgenix-notice-error" role="status">
														<span class="kitgenix-notice-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('warning'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
														<div class="kitgenix-notice-body">
															<p class="kitgenix-notice-title"><?php echo esc_html__('Error', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
															<p class="kitgenix-notice-text"><code><?php echo esc_html((string)$cres['error']); ?></code></p>
														</div>
													</div>
												<?php else:
													$mm = $cres['mismatches'] ?? [];
												?>
													<?php if (empty($mm)): ?>
														<div class="kitgenix-notice kitgenix-notice-success" role="status">
															<span class="kitgenix-notice-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('check'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
															<div class="kitgenix-notice-body">
																<p class="kitgenix-notice-text"><?php echo esc_html__('No mismatches found for audited SKUs.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
															</div>
														</div>
													<?php else: ?>
														<div class="kitgenix-table-wrap">
															<table class="kitgenix-table">
																<thead>
																	<tr>
																		<th><?php echo esc_html__('SKU', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
																		<th><?php echo esc_html__('Field', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
																		<th><?php echo esc_html__('Master', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
																		<th><?php echo esc_html__('Child', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
																	</tr>
																</thead>
																<tbody>
																	<?php foreach ($mm as $sku => $fields): ?>
																		<?php foreach ($fields as $field => $pair): ?>
																			<tr>
																				<td><code><?php echo esc_html((string)$sku); ?></code></td>
																				<td><?php echo esc_html((string)$field); ?></td>
																				<td><code><?php echo esc_html((string)($pair['master'] ?? '')); ?></code></td>
																				<td><code><?php echo esc_html((string)($pair['child'] ?? '')); ?></code></td>
																			</tr>
																		<?php endforeach; ?>
																	<?php endforeach; ?>
																</tbody>
															</table>
														</div>
													<?php endif; ?>
												<?php endif; ?>
											</div>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<div<?php echo $active_tab === 'conflicts' ? '' : ' hidden="hidden"'; ?> class="kitgenix-panel-stack" data-kitgenix-tab-panel="conflicts">

				<div class="kitgenix-card">
					<div class="kitgenix-card-head">
						<div class="kitgenix-card-head-main">
							<span class="kitgenix-card-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('warning'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
							<div class="kitgenix-card-head-text">
								<h2><?php echo esc_html__('Conflict Dashboard', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
								<p><?php echo esc_html__('Missing products, duplicate/mismatched SKUs, GID mismatches, quantity/backorder/status mismatches, offline children, and auth errors – collected from Audit, Reconcile (dry-run/differences), and duplicate-SKU scans.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
							</div>
						</div>
						<div class="kitgenix-card-head-actions">
							<?php if ($this->settings->is_master()): ?>
								<form method="post" style="display:inline;">
									<?php wp_nonce_field('kss_conflicts'); ?>
									<button type="submit" class="button" name="kss_scan_conflicts" value="1"><?php echo esc_html__('Scan Duplicate SKUs', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
								</form>
							<?php endif; ?>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field('kss_conflicts'); ?>
								<button type="submit" class="button" name="kss_clear_conflicts" value="1"><?php echo esc_html__('Clear Report', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
							</form>
						</div>
					</div>
					<div class="kitgenix-card-body">
						<?php if (empty($conflicts['items'])): ?>
							<div class="kitgenix-empty-state">
								<span class="kitgenix-empty-state-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('check'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
								<h3 class="kitgenix-empty-state-title"><?php echo esc_html__('No conflicts recorded', 'kitgenix-stock-sync-for-woocommerce'); ?></h3>
								<p class="kitgenix-empty-state-desc"><?php echo esc_html__('Run Audit or Reconcile (dry-run/differences) on the Tools tab, or Scan Duplicate SKUs above, to populate this report.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
							</div>
						<?php else: ?>
							<?php
							$type_labels = [
								'missing_product' => __('Missing product', 'kitgenix-stock-sync-for-woocommerce'),
								'duplicate_sku' => __('Duplicate SKU', 'kitgenix-stock-sync-for-woocommerce'),
								'gid_mismatch' => __('GID mismatch', 'kitgenix-stock-sync-for-woocommerce'),
								'quantity_mismatch' => __('Quantity mismatch', 'kitgenix-stock-sync-for-woocommerce'),
								'backorder_mismatch' => __('Backorder mismatch', 'kitgenix-stock-sync-for-woocommerce'),
								'stock_status_mismatch' => __('Stock-status mismatch', 'kitgenix-stock-sync-for-woocommerce'),
								'child_offline' => __('Child offline', 'kitgenix-stock-sync-for-woocommerce'),
								'auth_error' => __('Authentication error', 'kitgenix-stock-sync-for-woocommerce'),
							];
							$type_badge = [
								'missing_product' => 'danger', 'duplicate_sku' => 'danger', 'child_offline' => 'danger', 'auth_error' => 'danger',
								'gid_mismatch' => 'warning', 'quantity_mismatch' => 'warning', 'backorder_mismatch' => 'warning', 'stock_status_mismatch' => 'warning',
							];
							?>
							<p class="description" style="margin-bottom:12px;"><?php echo esc_html(sprintf(
								/* translators: %s: date/time the report was generated */
								__('Report generated: %s', 'kitgenix-stock-sync-for-woocommerce'),
								$this->fmt_time((int) ($conflicts['generated_at'] ?? 0))
							)); ?></p>
							<div class="kitgenix-search-bar">
								<div class="kitgenix-search-bar-input">
									<span class="kitgenix-search-bar-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('search'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
									<input type="search" placeholder="<?php echo esc_attr__('Search conflicts…', 'kitgenix-stock-sync-for-woocommerce'); ?>" data-kitgenix-table-search data-kitgenix-table-search-target="#kss-conflicts-table" />
								</div>
							</div>
							<div class="kitgenix-table-wrap" id="kss-conflicts-table" data-kitgenix-table-paginate="25">
								<table class="kitgenix-table">
									<thead>
										<tr>
											<th><?php echo esc_html__('Type', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											<th><?php echo esc_html__('SKU', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											<th><?php echo esc_html__('Child', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											<th><?php echo esc_html__('Master', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											<th><?php echo esc_html__('Child value', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											<th><?php echo esc_html__('Detail', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach (array_reverse((array) $conflicts['items']) as $row): if (!is_array($row)) continue; $type = (string) ($row['type'] ?? ''); ?>
											<tr data-kitgenix-table-row>
												<td><span class="kitgenix-badge <?php echo esc_attr($type_badge[$type] ?? 'neutral'); ?>"><?php echo esc_html($type_labels[$type] ?? $type); ?></span></td>
												<td><?php echo $row['sku'] !== '' ? '<code>' . esc_html((string) $row['sku']) . '</code>' : '–'; ?></td>
												<td><?php echo esc_html((string) ($row['child_name'] ?? '') ?: '–'); ?></td>
												<td><code><?php echo esc_html((string) ($row['master_value'] ?? '')); ?></code></td>
												<td><code><?php echo esc_html((string) ($row['child_value'] ?? '')); ?></code></td>
												<td><?php echo esc_html((string) ($row['detail'] ?? '')); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
								<div class="kitgenix-empty-state" data-kitgenix-table-empty style="display:none;">
									<p class="kitgenix-empty-state-title"><?php echo esc_html__('No matching conflicts', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
								</div>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div<?php echo $active_tab === 'logs' ? '' : ' hidden="hidden"'; ?> class="kitgenix-panel-stack" data-kitgenix-tab-panel="logs">

				<?php
				$log_level_badge = static function (string $level): string {
					$level_l = strtolower($level);
					if ($level_l === 'error') return 'danger';
					if (strpos($level_l, 'warn') === 0) return 'warning';
					if ($level_l === 'success' || $level_l === 'ok') return 'success';
					return 'info';
				};
				?>

				<div class="kitgenix-card">
					<div class="kitgenix-card-head">
						<div>
							<h2 class="kitgenix-support-subheading"><?php echo esc_html__('Event Log (Plugin)', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
							<p class="description"><?php echo esc_html__('The most recent synchronization events recorded by this store.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
						</div>
						<div class="kitgenix-card-head-actions">
							<form method="post">
								<?php wp_nonce_field('kss_logs'); ?>
								<div class="kitgenix-button-group">
									<button type="submit" class="button" name="kss_clear_event_log" value="1"><?php echo esc_html__('Clear Event Log', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
									<button type="submit" class="button" name="kss_clear_backlog" value="1"><?php echo esc_html__('Clear Backlog', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
								</div>
							</form>
						</div>
					</div>
					<div class="kitgenix-card-body">
						<?php if (empty($event_log)): ?>
							<div class="kitgenix-empty-state">
								<span class="kitgenix-empty-state-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('logs'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
								<h3 class="kitgenix-empty-state-title"><?php echo esc_html__('No events logged yet', 'kitgenix-stock-sync-for-woocommerce'); ?></h3>
								<p class="kitgenix-empty-state-desc"><?php echo esc_html__('Sync activity will appear here as it happens.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
							</div>
						<?php else: ?>
							<div class="kitgenix-search-bar">
								<div class="kitgenix-search-bar-input">
									<span class="kitgenix-search-bar-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('search'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
									<input type="search" placeholder="<?php echo esc_attr__('Search events…', 'kitgenix-stock-sync-for-woocommerce'); ?>" data-kitgenix-table-search data-kitgenix-table-search-target="#kss-event-log-table" />
								</div>
							</div>
							<div class="kitgenix-table-wrap" id="kss-event-log-table" data-kitgenix-table-paginate="25">
								<table class="kitgenix-table">
									<thead>
										<tr>
											<th><?php echo esc_html__('Time', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											<th><?php echo esc_html__('Level', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											<th><?php echo esc_html__('Code', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											<th><?php echo esc_html__('Message', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											<th><?php echo esc_html__('Context', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach (array_slice($event_log, 0, 100) as $row): ?>
											<tr data-kitgenix-table-row>
												<td><?php echo esc_html(gmdate('Y-m-d H:i:s', (int)($row['time'] ?? 0))); ?> UTC</td>
												<td><span class="kitgenix-badge <?php echo esc_attr($log_level_badge((string)($row['level'] ?? ''))); ?>"><?php echo esc_html((string)($row['level'] ?? '')); ?></span></td>
												<td><?php echo ($row['code'] ?? '') !== '' ? '<code>' . esc_html((string)$row['code']) . '</code>' : '–'; ?></td>
												<td><?php echo esc_html((string)($row['message'] ?? '')); ?></td>
												<td><code><?php echo esc_html(wp_json_encode($row['context'] ?? [])); ?></code></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
								<div class="kitgenix-empty-state" data-kitgenix-table-empty style="display:none;">
									<p class="kitgenix-empty-state-title"><?php echo esc_html__('No matching events', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
								</div>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="kitgenix-card">
					<div class="kitgenix-card-head">
						<div>
							<h2 class="kitgenix-support-subheading"><?php echo esc_html__('Diagnostic code reference', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
							<p class="description"><?php echo esc_html__('What each Code in the Event Log above means, and whether it needs action.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
						</div>
					</div>
					<div class="kitgenix-card-body">
						<div class="kitgenix-table-wrap">
							<table class="kitgenix-table">
								<thead>
									<tr>
										<th><?php echo esc_html__('Category', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
										<th><?php echo esc_html__('What it means', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
										<th><?php echo esc_html__('Action needed?', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									$kss_code_reference = [
										'kss_auth_missing' => __("Check the sending store's connection settings – make sure it is a paired Kitgenix Stock Sync store.", 'kitgenix-stock-sync-for-woocommerce'),
										'kss_auth_bad_ts' => __('Usually a broken or tampered request. If it comes from a known store, check for a proxy altering headers.', 'kitgenix-stock-sync-for-woocommerce'),
										'kss_auth_skew' => __("Check that both servers have automatic time sync (NTP) enabled. Not necessarily a problem on its own.", 'kitgenix-stock-sync-for-woocommerce'),
										'kss_auth_replay' => __('None, unless it repeats constantly from the same store – then check for a webhook retry loop.', 'kitgenix-stock-sync-for-woocommerce'),
										'kss_auth_sig' => __('Re-check and re-save the shared secret on both stores so they match exactly.', 'kitgenix-stock-sync-for-woocommerce'),
										'kss_auth_secret' => __('Go to Stores and fill in the secret for this store pairing.', 'kitgenix-stock-sync-for-woocommerce'),
										'kss_auth_sender' => __('Check the Stores tab – confirm the store ID matches exactly on both ends.', 'kitgenix-stock-sync-for-woocommerce'),
										'http_timeout' => __('None – this will retry automatically. Investigate only if it happens repeatedly.', 'kitgenix-stock-sync-for-woocommerce'),
										'http_connection_error' => __('None – this will retry automatically. If persistent, confirm the remote store is online and reachable.', 'kitgenix-stock-sync-for-woocommerce'),
										'http_5xx' => __('None – this will retry automatically. Check the remote store if it continues for a while.', 'kitgenix-stock-sync-for-woocommerce'),
										'http_4xx_rejected' => __('Check the WooCommerce/Kitgenix Logs on the remote store for the exact rejection reason.', 'kitgenix-stock-sync-for-woocommerce'),
										'config_error' => __('Go to Configuration/Stores and complete the missing fields, or check the Event Log above for the item that failed.', 'kitgenix-stock-sync-for-woocommerce'),
									];
									foreach ($kss_code_reference as $kss_ref_code => $kss_ref_action):
										$kss_ref_info = Kitgenix_Stock_Sync_For_WooCommerce_Settings::get_category_and_note($kss_ref_code);
									?>
										<tr>
											<td><code><?php echo esc_html($kss_ref_code); ?></code><br /><?php echo esc_html($kss_ref_info['category']); ?></td>
											<td><?php echo esc_html($kss_ref_info['note']); ?></td>
											<td><?php echo esc_html($kss_ref_action); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<div class="kitgenix-card">
					<div class="kitgenix-card-head">
						<div>
							<h2 class="kitgenix-support-subheading"><?php echo esc_html__('Backlog (Failures)', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
							<p class="description"><?php echo esc_html__('Sync operations that failed and are waiting to be retried or reviewed.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
						</div>
					</div>
					<div class="kitgenix-card-body">
						<?php if (empty($backlog)): ?>
							<div class="kitgenix-empty-state">
								<span class="kitgenix-empty-state-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('check'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
								<h3 class="kitgenix-empty-state-title"><?php echo esc_html__('No backlog items', 'kitgenix-stock-sync-for-woocommerce'); ?></h3>
								<p class="kitgenix-empty-state-desc"><?php echo esc_html__('Everything is syncing cleanly.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
							</div>
						<?php else: ?>
							<div class="kitgenix-search-bar">
								<div class="kitgenix-search-bar-input">
									<span class="kitgenix-search-bar-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('search'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
									<input type="search" placeholder="<?php echo esc_attr__('Search backlog…', 'kitgenix-stock-sync-for-woocommerce'); ?>" data-kitgenix-table-search data-kitgenix-table-search-target="#kss-backlog-table" />
								</div>
							</div>
							<div class="kitgenix-table-wrap" id="kss-backlog-table" data-kitgenix-table-paginate="25">
								<table class="kitgenix-table">
									<thead>
										<tr>
											<th><?php echo esc_html__('Time', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											<th><?php echo esc_html__('Type', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											<th><?php echo esc_html__('Store ID', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											<th><?php echo esc_html__('Status', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											<th><?php echo esc_html__('Attempt', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											<th><?php echo esc_html__('Next retry', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											<th><?php echo esc_html__('Reason', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
											<th class="kitgenix-table-actions-col"><?php echo esc_html__('Actions', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach (array_slice($backlog, 0, 100) as $row):
											$row_id = (string) ($row['id'] ?? '');
											$row_status = (string) ($row['status'] ?? 'pending');
											$has_payload = !empty($row['payload']);
											$next_retry = (int) ($row['next_retry_at'] ?? 0);
										?>
											<tr data-kitgenix-table-row>
												<td><?php echo esc_html(gmdate('Y-m-d H:i:s', (int)($row['time'] ?? 0))); ?> UTC</td>
												<td><span class="kitgenix-badge neutral"><?php echo esc_html((string)($row['type'] ?? '')); ?></span></td>
												<td><code><?php echo esc_html((string)($row['store_id'] ?? '')); ?></code></td>
												<td><span class="kitgenix-badge <?php echo esc_attr($row_status === 'exhausted' ? 'danger' : 'warning'); ?>"><?php echo esc_html(ucfirst($row_status)); ?></span></td>
												<td><?php echo esc_html((string)($row['attempt'] ?? '')); ?></td>
												<td><?php echo $next_retry > 0 && $row_status !== 'exhausted' ? esc_html(gmdate('Y-m-d H:i:s', $next_retry)) . ' UTC' : '–'; ?></td>
												<td><?php echo esc_html((string)($row['error'] ?? '')); ?></td>
												<td>
													<?php if ($row_id !== '' && $has_payload): ?>
														<div class="kitgenix-table-actions">
															<form method="post" style="display:inline;">
																<?php wp_nonce_field('kss_logs'); ?>
																<input type="hidden" name="backlog_id" value="<?php echo esc_attr($row_id); ?>">
																<button type="submit" class="kitgenix-table-action-btn" name="kss_backlog_retry" value="1" title="<?php echo esc_attr__('Retry now', 'kitgenix-stock-sync-for-woocommerce'); ?>"><?php echo esc_html__('Retry', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
															</form>
															<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Discard this backlog item permanently? It will not be retried again.', 'kitgenix-stock-sync-for-woocommerce')); ?>');">
																<?php wp_nonce_field('kss_logs'); ?>
																<input type="hidden" name="backlog_id" value="<?php echo esc_attr($row_id); ?>">
																<button type="submit" class="kitgenix-table-action-btn kitgenix-table-action-danger" name="kss_backlog_discard" value="1" title="<?php echo esc_attr__('Discard', 'kitgenix-stock-sync-for-woocommerce'); ?>"><?php echo esc_html__('Discard', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
															</form>
														</div>
													<?php elseif ($row_id !== ''): ?>
														<span class="kitgenix-badge muted" title="<?php echo esc_attr__('Original payload was too large to retain for retry.', 'kitgenix-stock-sync-for-woocommerce'); ?>"><?php echo esc_html__('No payload', 'kitgenix-stock-sync-for-woocommerce'); ?></span>
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
								<div class="kitgenix-empty-state" data-kitgenix-table-empty style="display:none;">
									<p class="kitgenix-empty-state-title"><?php echo esc_html__('No matching backlog items', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
								</div>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div<?php echo $active_tab === 'support' ? '' : ' hidden="hidden"'; ?> class="kitgenix-panel-stack kitgenix-stock-sync-for-woocommerce-support-page kitgenix-support-page" data-kitgenix-tab-panel="support">
				<?php
				$donate_url = 'https://www.paypal.com/donate/?hosted_button_id=KALF36K6JJ9B2';
				$plugin_page_url = 'https://kitgenix.com/plugins/kitgenix-stock-sync-for-woocommerce/';
				$review_url = 'https://wordpress.org/support/plugin/kitgenix-stock-sync-for-woocommerce/reviews/#new-post';
				$docs_url = 'https://kitgenix.com/plugins/kitgenix-stock-sync-for-woocommerce/documentation/';
				$copy_onclick = "if(window.navigator&&navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(" . wp_json_encode( $plugin_page_url ) . ");}else{window.prompt(" . wp_json_encode( __( 'Copy plugin link:', 'kitgenix-stock-sync-for-woocommerce' ) ) . ", " . wp_json_encode( $plugin_page_url ) . ");}return false;";
				?>

				<div class="kitgenix-card kitgenix-support-hero">
					<div class="kitgenix-support-hero-inner">
						<span class="kitgenix-support-hero-icon" aria-hidden="true"><?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('heart'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?></span>
						<p class="kitgenix-support-hero-eyebrow"><?php echo esc_html__('Support Kitgenix', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
						<h2><?php echo esc_html__('Help us keep building', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
						<p class="kitgenix-support-hero-body"><?php echo esc_html__('Every sync this plugin runs is a check against your real stock levels, so customers never order something you no longer have in the warehouse. Keeping that check accurate across every new WooCommerce and WordPress release is ongoing, unpaid work, and donations are what keep it going.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
						<a class="kitgenix-support-hero-button" href="<?php echo esc_url($donate_url); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('paypal'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?>
							<?php echo esc_html__('Donate with PayPal', 'kitgenix-stock-sync-for-woocommerce'); ?>
						</a>
						<p class="kitgenix-support-hero-caption">
							<?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('shield'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?>
							<?php echo esc_html__('Donations are entirely optional. Kitgenix plugins keep working whether you donate or not.', 'kitgenix-stock-sync-for-woocommerce'); ?>
						</p>
					</div>

					<div class="kitgenix-support-links">
						<a class="kitgenix-support-link" href="<?php echo esc_url($review_url); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('star'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?>
							<?php echo esc_html__('Leave a review', 'kitgenix-stock-sync-for-woocommerce'); ?>
						</a>
						<a class="kitgenix-support-link" href="<?php echo esc_url($docs_url); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('logs'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?>
							<?php echo esc_html__('Read the docs', 'kitgenix-stock-sync-for-woocommerce'); ?>
						</a>
						<button type="button" class="kitgenix-support-link" onclick="<?php echo esc_attr($copy_onclick); ?>">
							<?php echo Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon('copy'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped, static trusted SVG markup from Kitgenix_Stock_Sync_For_WooCommerce_Admin::icon(). ?>
							<?php echo esc_html__('Copy plugin link', 'kitgenix-stock-sync-for-woocommerce'); ?>
						</button>
					</div>
				</div>
			</div>
					</div>
					<?php $this->render_sidebar(); ?>
		</div>
		</div>
		<?php
	}

	private function render_sidebar(): void {
		$social_base = (defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL') ? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL : plugin_dir_url(__FILE__)) . 'assets/images/social-media/';
		$social_links = [
			[ 'label' => 'Website',   'icon' => 'globe-solid.svg',     'url' => 'https://kitgenix.com' ],
			[ 'label' => 'Facebook',  'icon' => 'facebook-solid.svg',  'url' => 'https://www.facebook.com/kitgenix' ],
			[ 'label' => 'Instagram', 'icon' => 'instagram-solid.svg', 'url' => 'https://www.instagram.com/kitgenix/' ],
			[ 'label' => 'YouTube',   'icon' => 'youtube-solid.svg',   'url' => 'https://www.youtube.com/@Kitgenix' ],
			[ 'label' => 'Reddit',    'icon' => 'reddit-solid.svg',    'url' => 'https://www.reddit.com/r/Kitgenix/' ],
			[ 'label' => 'LinkedIn',  'icon' => 'linkedin-solid.svg',  'url' => 'https://www.linkedin.com/company/kitgenix' ],
			[ 'label' => 'X',         'icon' => 'x-solid.svg',         'url' => 'https://x.com/kitgenix' ],
			[ 'label' => 'TikTok',    'icon' => 'tiktok-solid.svg',    'url' => 'https://www.tiktok.com/@kitgenix' ],
			[ 'label' => 'GitHub',    'icon' => 'github-solid.svg',    'url' => 'https://github.com/kitgenix' ],
		];
		?>
		<aside class="kitgenix-settings-sidebar" aria-label="<?php echo esc_attr__('Help and links', 'kitgenix-stock-sync-for-woocommerce'); ?>">
			<div class="kitgenix-card">
				<div class="kitgenix-card-body">
					<h2><?php echo esc_html__('Need Help?', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
					<p><?php echo esc_html__('Open the documentation for setup guidance or send us a support request if you need help configuring the plugin.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
					<div class="kitgenix-button-group kitgenix-button-group-stack">
						<a class="button button-secondary" href="<?php echo esc_url('https://kitgenix.com/plugins/kitgenix-stock-sync-for-woocommerce/documentation/'); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Documentation', 'kitgenix-stock-sync-for-woocommerce'); ?></a>
						<a class="button button-primary" href="<?php echo esc_url('https://kitgenix.com/plugins/kitgenix-stock-sync-for-woocommerce/support'); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Request Support', 'kitgenix-stock-sync-for-woocommerce'); ?></a>
					</div>
				</div>
			</div>

			<div class="kitgenix-card">
				<div class="kitgenix-card-body">
					<h2><?php echo esc_html__('Visit Our Official Facebook Group', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
					<p><?php echo esc_html__('Join the Kitgenix community to ask questions, share feedback, and keep up with product updates.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
					<div class="kitgenix-button-group kitgenix-button-group-stack">
						<a class="button button-secondary" href="<?php echo esc_url('https://www.facebook.com/groups/kitgenix'); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Join Group', 'kitgenix-stock-sync-for-woocommerce'); ?></a>
					</div>
				</div>
			</div>

			<div class="kitgenix-card">
				<div class="kitgenix-card-body">
					<h2><?php echo esc_html__('Follow Us', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
					<p><?php echo esc_html__('Keep up with new releases, tutorials, and product news across our channels.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
					<div class="kitgenix-sidebar-social-grid">
						<?php foreach ($social_links as $social_link) : ?>
							<a class="kitgenix-sidebar-social-link" href="<?php echo esc_url($social_link['url']); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr($social_link['label']); ?>" title="<?php echo esc_attr($social_link['label']); ?>"><img src="<?php echo esc_url($social_base . $social_link['icon']); ?>" alt="" width="16" height="16" aria-hidden="true" /></a>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</aside>
		<?php
	}

	private function test_connection(): bool {
		$log = wc_get_logger();

		if ($this->settings->is_child()) {
			$master = $this->settings->master_config();
			$url    = rtrim((string) ($master['url'] ?? ''), '/');
			$secret = (string) ($master['secret'] ?? '');
			$mid    = (string) ($master['store_id'] ?? '');
			if ($url === '' || $secret === '' || $mid === '') return false;

			$endpoint = $url . '/wp-json/kitgenix-stock-sync/v1/ping';
			$body = wp_json_encode(['ping' => true]);

			$headers = $this->security->sign_headers($secret, $this->settings->this_store_id(), $body);

			$res = wp_remote_post($endpoint, [
				'timeout' => 15,
				'headers' => $headers,
				'body'    => $body,
			]);

			if (is_wp_error($res)) {
				$log->error('KSS ping to master failed: ' . $res->get_error_message(), ['source' => 'kitgenix-stock-sync-for-woocommerce']);
				return false;
			}
			return (int) wp_remote_retrieve_response_code($res) === 200;
		}

		$children = $this->settings->children();
		$child = $children[0] ?? null;
		if (!is_array($child)) return true;

		$url = rtrim((string) ($child['url'] ?? ''), '/');
		$secret = (string) ($child['secret'] ?? '');
		if ($url === '' || $secret === '') return false;

		$endpoint = $url . '/wp-json/kitgenix-stock-sync/v1/ping';
		$body = wp_json_encode(['ping' => true]);
		$headers = $this->security->sign_headers($secret, $this->settings->this_store_id(), $body);

		$res = wp_remote_post($endpoint, [
			'timeout' => 15,
			'headers' => $headers,
			'body'    => $body,
		]);

		if (is_wp_error($res)) {
			$log->error('KSS ping to child failed: ' . $res->get_error_message(), ['source' => 'kitgenix-stock-sync-for-woocommerce']);
			return false;
		}

		return (int) wp_remote_retrieve_response_code($res) === 200;
	}
}
