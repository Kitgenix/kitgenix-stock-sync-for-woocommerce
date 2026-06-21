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

		wp_enqueue_style('kitgenix-admin-ui');

		wp_enqueue_style(
			'kitgenix-stock-sync-for-woocommerce-admin',
			$base_url . 'assets/css/admin.css',
			[ 'kitgenix-admin-ui' ],
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
		if ($ts <= 0) return '—';
		return gmdate('Y-m-d H:i:s', $ts) . ' UTC';
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
		$allowed = ['status', 'configuration', 'stores', 'tools', 'logs', 'support'];
		return in_array($tab, $allowed, true) ? $tab : 'status';
	}

	private function redirect_to_tab(string $tab): void {
		$tab = sanitize_key($tab);
		$allowed = ['status', 'configuration', 'stores', 'tools', 'logs', 'support'];
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

			$opt['master']['url'] = $this->read_post_url('master_url', (string) ($opt['master']['url'] ?? ''));
			$opt['master']['store_id'] = $this->read_post_text('master_store_id', (string) ($opt['master']['store_id'] ?? ''));
			$opt['master']['secret'] = $this->read_post_text('master_secret', (string) ($opt['master']['secret'] ?? ''));

			$this->settings->update_all($opt);
			$this->settings->set_notice('success', 'Master connection saved.');
			$this->redirect_to_tab('stores');
		}

		if ($this->settings->is_master() && isset($_POST['kss_add_child']) && check_admin_referer('kss_save_children')) {
			$children = is_array($opt['children'] ?? null) ? $opt['children'] : [];

			$child_id = $this->read_post_text('child_id', wp_generate_uuid4());
			$child_name = $this->read_post_text('child_name', 'Child Store');
			$child_url = $this->read_post_url('child_url', '');
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
			$patch = [
				'enabled' => $this->read_post_bool('edit_child_enabled'),
				'name' => $this->read_post_text('edit_child_name', ''),
				'url' => $this->read_post_url('edit_child_url', ''),
				'secret' => $this->read_post_text('edit_child_secret', ''),
			];

			$ok = $this->settings->update_child($cid, $patch);
			$this->settings->set_notice($ok ? 'success' : 'error', $ok ? 'Child store updated.' : 'Could not update child store.');
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
			$per_page = $this->read_post_int('reconcile_per_page', 200);
			$this->sync->start_reconcile($per_page);
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
			$this->settings->set_notice('success', 'Audit completed. See results below.');
			$this->redirect_to_tab('tools');
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

		$action_scheduler_url = admin_url('admin.php?page=wc-status&tab=action-scheduler');

		$ver = defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION') ? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION : '1.0.2';
		$logo_url = (defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL') ? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL : plugin_dir_url(__FILE__)) . 'assets/images/logos/kitgenix-favicon-purple.svg';
		$social_base = (defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL') ? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL : plugin_dir_url(__FILE__)) . 'assets/images/social-media/';
		$social_base = (defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL') ? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL : plugin_dir_url(__FILE__)) . 'assets/images/social-media/';

		?>
		<div class="wrap kitgenix-admin-app kitgenix-stock-sync-for-woocommerce-admin" data-kitgenix-tabs data-kitgenix-default-tab="<?php echo esc_attr($active_tab); ?>" id="kitgenix-stock-sync-for-woocommerce-admin-app">

			<div class="kitgenix-stock-sync-for-woocommerce-settings-intro kitgenix-settings-header">
				<div class="kitgenix-settings-header-row">
					<div class="kitgenix-settings-header-main">
						<div class="kitgenix-settings-brand">
							<img class="kitgenix-settings-logo" src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr__('Kitgenix', 'kitgenix-stock-sync-for-woocommerce'); ?>" />
							<h1 class="kitgenix-stock-sync-for-woocommerce-admin-title"><?php echo esc_html__('Kitgenix Stock Sync for WooCommerce', 'kitgenix-stock-sync-for-woocommerce'); ?></h1>
						</div>
						<p><?php echo esc_html__('Securely sync WooCommerce product stock between multiple stores using a master + child setup.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>

						<div class="kitgenix-settings-meta">
							<span class="kitgenix-settings-version" aria-label="Plugin version">v<?php echo esc_html($ver); ?></span>
						</div>
					</div>

					<div class="kitgenix-settings-header-actions">
						<div class="kitgenix-intro-links kitgenix-stock-sync-for-woocommerce-intro-links">
							<a href="<?php echo esc_url('https://kitgenix.com/plugins/kitgenix-stock-sync-for-woocommerce/documentation/'); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Documentation', 'kitgenix-stock-sync-for-woocommerce'); ?></a>
							<a href="<?php echo esc_url('https://wordpress.org/support/plugin/kitgenix-stock-sync-for-woocommerce/reviews/#new-post'); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Review Plugin', 'kitgenix-stock-sync-for-woocommerce'); ?></a>
							<a href="<?php echo esc_url('https://wordpress.org/support/plugin/kitgenix-stock-sync-for-woocommerce/'); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Support Request', 'kitgenix-stock-sync-for-woocommerce'); ?></a>
							<a href="<?php echo esc_url('https://donate.stripe.com/9B65kDgG3fTQ2Kzcmwf7i00'); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Support Kitgenix', 'kitgenix-stock-sync-for-woocommerce'); ?></a>
						</div>

						<?php if ( ! empty( $social_base ) ) : ?>
							<div class="kitgenix-social-links kitgenix-social-links--icons">
								<a href="https://kitgenix.com" target="_blank" rel="noopener noreferrer" aria-label="Website" title="Website"><img src="<?php echo esc_url( $social_base . 'globe-solid.svg' ); ?>" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Website</span></a>
								<a href="https://www.facebook.com/groups/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook Community" title="Facebook Community"><img src="<?php echo esc_url( $social_base . 'facebook-solid.svg' ); ?>" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Facebook Community</span></a>
								<a href="https://www.facebook.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook"><img src="<?php echo esc_url( $social_base . 'facebook-solid.svg' ); ?>" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Facebook</span></a>
								<a href="https://www.instagram.com/kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram"><img src="<?php echo esc_url( $social_base . 'instagram-solid.svg' ); ?>" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Instagram</span></a>
								<a href="https://www.youtube.com/@Kitgenix" target="_blank" rel="noopener noreferrer" aria-label="YouTube" title="YouTube"><img src="<?php echo esc_url( $social_base . 'youtube-solid.svg' ); ?>" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">YouTube</span></a>
								<a href="https://www.reddit.com/r/Kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Reddit" title="Reddit"><img src="<?php echo esc_url( $social_base . 'reddit-solid.svg' ); ?>" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">Reddit</span></a>
								<a href="https://www.linkedin.com/company/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn"><img src="<?php echo esc_url( $social_base . 'linkedin-solid.svg' ); ?>" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">LinkedIn</span></a>
								<a href="https://x.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="X" title="X"><img src="<?php echo esc_url( $social_base . 'x-solid.svg' ); ?>" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">X</span></a>
								<a href="https://www.tiktok.com/@kitgenix" target="_blank" rel="noopener noreferrer" aria-label="TikTok" title="TikTok"><img src="<?php echo esc_url( $social_base . 'tiktok-solid.svg' ); ?>" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">TikTok</span></a>
								<a href="https://github.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="GitHub" title="GitHub"><img src="<?php echo esc_url( $social_base . 'github-solid.svg' ); ?>" alt="" width="13" height="13" aria-hidden="true" /><span class="screen-reader-text">GitHub</span></a>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<h2 class="nav-tab-wrapper kitgenix-nav-tabs" aria-label="Settings navigation">
				<?php
				$tabs = [
					'status' => __('Status', 'kitgenix-stock-sync-for-woocommerce'),
					'configuration' => __('Configuration', 'kitgenix-stock-sync-for-woocommerce'),
					'stores' => __('Stores', 'kitgenix-stock-sync-for-woocommerce'),
					'tools' => __('Tools', 'kitgenix-stock-sync-for-woocommerce'),
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
					echo '<a class="nav-tab kitgenix-tab-trigger' . ($active ? ' nav-tab-active' : '') . '" href="' . esc_url($url) . '" data-kitgenix-tab="' . esc_attr($key) . '"' . ($active ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
				}
				?>
			</h2>

			<div class="kitgenix-settings-layout">
				<div class="kitgenix-settings-content" id="kitgenix-settings-content" tabindex="-1">

			<div<?php echo $active_tab === 'status' ? '' : ' hidden="hidden"'; ?> class="kitgenix-stock-sync-for-woocommerce-section-card" data-kitgenix-tab-panel="status">
				<h2><?php echo esc_html__('Status', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
				<table class="widefat striped" style="max-width: 1100px;">
					<tbody>
						<tr>
							<th style="width:240px;"><?php echo esc_html__('Role', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
							<td><strong><?php echo esc_html(ucfirst($this->settings->role())); ?></strong></td>
						</tr>
						<tr>
							<th><?php echo esc_html__('This Store ID', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
							<td><code><?php echo esc_html($opt['this_store_id'] ?? ''); ?></code></td>
						</tr>
						<tr>
							<th><?php echo esc_html__('Last inbound event', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
							<td><?php echo esc_html($this->fmt_time((int)($health['last_inbound_event'] ?? 0))); ?></td>
						</tr>
						<tr>
							<th><?php echo esc_html__('Last outbound success', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
							<td><?php echo esc_html($this->fmt_time((int)($health['last_outbound_success'] ?? 0))); ?></td>
						</tr>
						<tr>
							<th><?php echo esc_html__('Last outbound error', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
							<td>
								<?php echo esc_html($this->fmt_time((int)($health['last_outbound_error'] ?? 0))); ?>
								<?php
								$lem = (string)($health['last_error_message'] ?? '');
								if ($lem !== '') {
									echo '<br><code>' . esc_html($lem) . '</code>';
								}
								?>
							</td>
						</tr>
						<tr>
							<th><?php echo esc_html__('Action Scheduler', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
							<td><a href="<?php echo esc_url($action_scheduler_url); ?>"><?php echo esc_html__('Open Scheduled Actions', 'kitgenix-stock-sync-for-woocommerce'); ?></a></td>
						</tr>
					</tbody>
				</table>
			</div>

			<div<?php echo $active_tab === 'configuration' ? '' : ' hidden="hidden"'; ?> class="kitgenix-stock-sync-for-woocommerce-section-card" data-kitgenix-tab-panel="configuration">
				<form method="post">
					<?php wp_nonce_field('kss_save_config'); ?>
					<h2><?php echo esc_html__('Configuration', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php echo esc_html__('This Store Name', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
							<td><input type="text" class="regular-text" name="this_store_name" value="<?php echo esc_attr($opt['this_store_name'] ?? ''); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__('Role', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
							<td>
								<label><input type="radio" name="role" value="master" <?php checked($role, 'master'); ?>> <?php echo esc_html__('Master', 'kitgenix-stock-sync-for-woocommerce'); ?></label><br>
								<label><input type="radio" name="role" value="child" <?php checked($role, 'child'); ?>> <?php echo esc_html__('Child', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__('Strict checkout validation (Child)', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
							<td>
								<label>
									<input type="checkbox" name="strict_checkout_validation" <?php checked((bool) ($opt['strict_checkout_validation'] ?? true)); ?>>
									<?php echo esc_html__('Check Master stock at checkout to reduce oversells (fail-open if Master unreachable).', 'kitgenix-stock-sync-for-woocommerce'); ?>
								</label>
								<p class="description"><?php echo esc_html__('Master connection details are configured under the Stores tab.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
							</td>
						</tr>
					</table>

					<h2><?php echo esc_html__('Exclusions', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
					<p><?php echo esc_html__('SKUs to exclude from syncing (comma or new line separated).', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
					<textarea name="excluded_skus" class="large-text" rows="5"><?php echo esc_textarea(implode("\n", $this->settings->excluded_skus())); ?></textarea>

					<p>
						<button type="submit" class="button button-primary" name="kss_save_config" value="1"><?php echo esc_html__('Save Settings', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
					</p>
				</form>
			</div>

			<div<?php echo $active_tab === 'stores' ? '' : ' hidden="hidden"'; ?> class="kitgenix-stock-sync-for-woocommerce-section-card" data-kitgenix-tab-panel="stores">
				<h2><?php echo esc_html__('Stores', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
				<p class="description"><?php echo esc_html__('Use this tab to connect the Child store to the Master, or to manage Child stores on the Master.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>

				<table class="widefat striped" style="max-width: 1100px;">
					<tbody>
						<tr>
							<th style="width:240px;"><?php echo esc_html__('This Store ID', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
							<td><code><?php echo esc_html($opt['this_store_id'] ?? ''); ?></code></td>
						</tr>
						<tr>
							<th><?php echo esc_html__('Role', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
							<td><strong><?php echo esc_html(ucfirst($this->settings->role())); ?></strong></td>
						</tr>
					</tbody>
				</table>

				<?php if ($role === 'child'): ?>
					<div style="margin-top: 18px;"></div>
					<h2><?php echo esc_html__('Master Connection (Child)', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
					<form method="post">
						<?php wp_nonce_field('kss_save_connection'); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php echo esc_html__('Master URL', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
								<td><input type="url" class="regular-text" name="master_url" value="<?php echo esc_attr($opt['master']['url'] ?? ''); ?>" placeholder="https://masterstore.com"></td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__('Master Store ID', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
								<td><input type="text" class="regular-text" name="master_store_id" value="<?php echo esc_attr($opt['master']['store_id'] ?? ''); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__('Shared Secret', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
								<td><input type="text" class="regular-text" name="master_secret" value="<?php echo esc_attr($opt['master']['secret'] ?? ''); ?>"></td>
							</tr>
						</table>
						<p>
							<button type="submit" class="button button-primary" name="kss_save_connection" value="1"><?php echo esc_html__('Save Master Connection', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
						</p>
					</form>
				<?php else: ?>
					<div style="margin-top: 18px;"></div>
					<h2><?php echo esc_html__('Child Stores (Master)', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
					<p class="description"><?php echo esc_html__('Add each child store and share a secret. The same secret must be configured on the Child under “Master Connection”.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>

					<form method="post">
						<?php wp_nonce_field('kss_save_children'); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php echo esc_html__('Child Name', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
								<td><input type="text" class="regular-text" name="child_name" value=""></td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__('Child URL', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
								<td><input type="url" class="regular-text" name="child_url" placeholder="https://childstore.com"></td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__('Child Store ID', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
								<td><input type="text" class="regular-text" name="child_id" placeholder="<?php echo esc_attr__('paste the Child\'s This Store ID', 'kitgenix-stock-sync-for-woocommerce'); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><?php echo esc_html__('Shared Secret', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
								<td><input type="text" class="regular-text" name="child_secret" placeholder="<?php echo esc_attr__('leave blank to auto-generate', 'kitgenix-stock-sync-for-woocommerce'); ?>"></td>
							</tr>
						</table>
						<p><button type="submit" class="button button-primary" name="kss_add_child" value="1"><?php echo esc_html__('Add Child Store', 'kitgenix-stock-sync-for-woocommerce'); ?></button></p>
					</form>

					<?php
					$children = is_array($opt['children'] ?? null) ? $opt['children'] : [];
					if (!empty($children)):
					?>
						<h3><?php echo esc_html__('Configured Children', 'kitgenix-stock-sync-for-woocommerce'); ?></h3>
						<table class="widefat striped">
							<thead>
								<tr>
									<th style="width:70px;"><?php echo esc_html__('Enabled', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
									<th><?php echo esc_html__('Name', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
									<th><?php echo esc_html__('URL', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
									<th style="width:290px;"><?php echo esc_html__('Store ID', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
									<th><?php echo esc_html__('Secret', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
									<th style="width:160px;"><?php echo esc_html__('Actions', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($children as $child): if (!is_array($child)) continue; ?>
									<tr>
										<td>
											<form method="post" style="display:inline;">
												<?php wp_nonce_field('kss_save_children'); ?>
												<input type="hidden" name="edit_child_id" value="<?php echo esc_attr((string)($child['id'] ?? '')); ?>">
												<input type="hidden" name="edit_child_name" value="<?php echo esc_attr((string)($child['name'] ?? '')); ?>">
												<input type="hidden" name="edit_child_url" value="<?php echo esc_attr((string)($child['url'] ?? '')); ?>">
												<input type="hidden" name="edit_child_secret" value="<?php echo esc_attr((string)($child['secret'] ?? '')); ?>">
												<label>
													<input type="checkbox" name="edit_child_enabled" <?php checked((bool)($child['enabled'] ?? true)); ?> onchange="this.form.submit();">
												</label>
												<input type="hidden" name="kss_update_child" value="1">
											</form>
										</td>
										<td><?php echo esc_html((string)($child['name'] ?? '')); ?></td>
										<td><code><?php echo esc_html((string)($child['url'] ?? '')); ?></code></td>
										<td><code><?php echo esc_html((string)($child['id'] ?? '')); ?></code></td>
										<td><code><?php echo esc_html((string)($child['secret'] ?? '')); ?></code></td>
										<td>
											<form method="post" style="display:inline;">
												<?php wp_nonce_field('kss_save_children'); ?>
												<input type="hidden" name="remove_id" value="<?php echo esc_attr((string)($child['id'] ?? '')); ?>">
												<button type="submit" class="button" name="kss_remove_child" value="1" onclick="return confirm('<?php echo esc_js(__('Remove this child store?', 'kitgenix-stock-sync-for-woocommerce')); ?>')"><?php echo esc_html__('Remove', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
											</form>
										</td>
									</tr>
									<tr>
										<td colspan="6">
											<form method="post" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
												<?php wp_nonce_field('kss_save_children'); ?>
												<input type="hidden" name="edit_child_id" value="<?php echo esc_attr((string)($child['id'] ?? '')); ?>">
												<label><?php echo esc_html__('Name', 'kitgenix-stock-sync-for-woocommerce'); ?> <input type="text" name="edit_child_name" value="<?php echo esc_attr((string)($child['name'] ?? '')); ?>"></label>
												<label><?php echo esc_html__('URL', 'kitgenix-stock-sync-for-woocommerce'); ?> <input type="url" size="32" name="edit_child_url" value="<?php echo esc_attr((string)($child['url'] ?? '')); ?>"></label>
												<label><?php echo esc_html__('Secret', 'kitgenix-stock-sync-for-woocommerce'); ?> <input type="text" size="36" name="edit_child_secret" value="<?php echo esc_attr((string)($child['secret'] ?? '')); ?>"></label>
												<label><input type="checkbox" name="edit_child_enabled" <?php checked((bool)($child['enabled'] ?? true)); ?>> <?php echo esc_html__('Enabled', 'kitgenix-stock-sync-for-woocommerce'); ?></label>
												<button type="submit" class="button" name="kss_update_child" value="1"><?php echo esc_html__('Save', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<div<?php echo $active_tab === 'tools' ? '' : ' hidden="hidden"'; ?> class="kitgenix-stock-sync-for-woocommerce-section-card" data-kitgenix-tab-panel="tools">
				<h2><?php echo esc_html__('Tools', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
				<form method="post" style="margin-top: 10px;">
					<?php wp_nonce_field('kss_test_connection'); ?>
					<p><button type="submit" class="button" name="kss_test" value="1"><?php echo esc_html__('Test Connection', 'kitgenix-stock-sync-for-woocommerce'); ?></button></p>
				</form>

				<?php if ($this->settings->is_master()): ?>
					<form method="post" style="margin-top: 16px;">
						<?php wp_nonce_field('kss_tools'); ?>

						<h3><?php echo esc_html__('Reconcile (Master)', 'kitgenix-stock-sync-for-woocommerce'); ?></h3>
						<p><?php echo esc_html__('Reconcile pushes authoritative stock state to all children in batches and establishes stable GIDs (needed for SKU rename sync).', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
						<p>
							<label><?php echo esc_html__('Batch size:', 'kitgenix-stock-sync-for-woocommerce'); ?>
								<input type="number" min="50" max="500" name="reconcile_per_page" value="<?php echo esc_attr((string)($reconcile['per_page'] ?? 200)); ?>">
							</label>
						</p>
						<p>
							<button type="submit" class="button button-primary" name="kss_reconcile" value="1"><?php echo esc_html__('Start Reconcile', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
							<?php if (!empty($reconcile['running'])): ?>
								<span style="margin-left:10px;"><?php echo esc_html__('Status:', 'kitgenix-stock-sync-for-woocommerce'); ?> <strong><?php echo esc_html__('Running', 'kitgenix-stock-sync-for-woocommerce'); ?></strong> (<?php echo esc_html__('last page', 'kitgenix-stock-sync-for-woocommerce'); ?>: <?php echo esc_html((string)($reconcile['page'] ?? 0)); ?>)</span>
							<?php else: ?>
								<span style="margin-left:10px;"><?php echo esc_html__('Status:', 'kitgenix-stock-sync-for-woocommerce'); ?> <strong><?php echo esc_html__('Idle', 'kitgenix-stock-sync-for-woocommerce'); ?></strong></span>
							<?php endif; ?>
						</p>

						<h3><?php echo esc_html__('Manual SKU Sync (Master)', 'kitgenix-stock-sync-for-woocommerce'); ?></h3>
						<p><?php echo esc_html__('Paste SKUs (comma or new-line separated) to push stock state to all children.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
						<textarea name="manual_skus" class="large-text" rows="3" placeholder="SKU1&#10;SKU2"></textarea>
						<p><button type="submit" class="button" name="kss_push_skus" value="1"><?php echo esc_html__('Push SKUs', 'kitgenix-stock-sync-for-woocommerce'); ?></button></p>

						<h3><?php echo esc_html__('Audit Children (Master)', 'kitgenix-stock-sync-for-woocommerce'); ?></h3>
						<p><?php echo esc_html__('Paste SKUs (comma or new-line separated). This will query each child’s local stock fields and compare to Master.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
						<textarea name="audit_skus" class="large-text" rows="3" placeholder="SKU1&#10;SKU2"></textarea>
						<p><button type="submit" class="button" name="kss_audit" value="1"><?php echo esc_html__('Run Audit', 'kitgenix-stock-sync-for-woocommerce'); ?></button></p>
					</form>
				<?php endif; ?>

				<?php if (is_array($audit_result) && $this->settings->is_master()): ?>
					<div style="margin-top: 18px;"></div>
					<h2><?php echo esc_html__('Audit Results', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
					<?php
					$children_res = $audit_result['children'] ?? [];
					$mismatched = $audit_result['mismatched_skus'] ?? [];
					?>
					<p><?php echo esc_html__('Mismatched SKUs:', 'kitgenix-stock-sync-for-woocommerce'); ?> <code><?php echo esc_html(implode(', ', (array)$mismatched)); ?></code></p>

					<?php foreach ($children_res as $cid => $cres): ?>
						<h3><?php echo esc_html((string)($cres['name'] ?? $cid)); ?> <small>(<?php echo esc_html((string)$cid); ?>)</small></h3>
						<?php if (!empty($cres['error'])): ?>
							<p><strong><?php echo esc_html__('Error:', 'kitgenix-stock-sync-for-woocommerce'); ?></strong> <code><?php echo esc_html((string)$cres['error']); ?></code></p>
							<?php continue; ?>
						<?php endif; ?>

						<?php $mm = $cres['mismatches'] ?? []; ?>
						<?php if (empty($mm)): ?>
							<p><?php echo esc_html__('No mismatches found for audited SKUs.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
						<?php else: ?>
							<table class="widefat striped">
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
						<?php endif; ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<div<?php echo $active_tab === 'logs' ? '' : ' hidden="hidden"'; ?> class="kitgenix-stock-sync-for-woocommerce-section-card" data-kitgenix-tab-panel="logs">
				<h2><?php echo esc_html__('Event Log (Plugin)', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
				<form method="post" style="margin-bottom: 10px;">
					<?php wp_nonce_field('kss_logs'); ?>
					<button type="submit" class="button" name="kss_clear_event_log" value="1"><?php echo esc_html__('Clear Event Log', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
					<button type="submit" class="button" name="kss_clear_backlog" value="1"><?php echo esc_html__('Clear Backlog', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
				</form>

				<?php if (empty($event_log)): ?>
					<p><?php echo esc_html__('No events logged yet.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
				<?php else: ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th style="width:180px;"><?php echo esc_html__('Time', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
								<th style="width:80px;"><?php echo esc_html__('Level', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
								<th><?php echo esc_html__('Message', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
								<th style="width:35%;"><?php echo esc_html__('Context', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach (array_slice($event_log, 0, 100) as $row): ?>
								<tr>
									<td><?php echo esc_html(gmdate('Y-m-d H:i:s', (int)($row['time'] ?? 0))); ?> UTC</td>
									<td><?php echo esc_html((string)($row['level'] ?? '')); ?></td>
									<td><?php echo esc_html((string)($row['message'] ?? '')); ?></td>
									<td><code><?php echo esc_html(wp_json_encode($row['context'] ?? [])); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<h2 style="margin-top:18px;"><?php echo esc_html__('Backlog (Failures)', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
				<?php if (empty($backlog)): ?>
					<p><?php echo esc_html__('No backlog items.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
				<?php else: ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th style="width:180px;"><?php echo esc_html__('Time', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
								<th style="width:80px;"><?php echo esc_html__('Type', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
								<th style="width:260px;"><?php echo esc_html__('Store ID', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
								<th style="width:80px;"><?php echo esc_html__('Attempt', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
								<th><?php echo esc_html__('Error', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
								<th style="width:35%;"><?php echo esc_html__('Payload', 'kitgenix-stock-sync-for-woocommerce'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach (array_slice($backlog, 0, 100) as $row): ?>
								<tr>
									<td><?php echo esc_html(gmdate('Y-m-d H:i:s', (int)($row['time'] ?? 0))); ?> UTC</td>
									<td><?php echo esc_html((string)($row['type'] ?? '')); ?></td>
									<td><code><?php echo esc_html((string)($row['store_id'] ?? '')); ?></code></td>
									<td><?php echo esc_html((string)($row['attempt'] ?? '')); ?></td>
									<td><?php echo esc_html((string)($row['error'] ?? '')); ?></td>
									<td><code><?php echo esc_html(wp_json_encode($row['payload_meta'] ?? [])); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div<?php echo $active_tab === 'support' ? '' : ' hidden="hidden"'; ?> class="kitgenix-stock-sync-for-woocommerce-section-card kitgenix-stock-sync-for-woocommerce-support-page kitgenix-support-page" data-kitgenix-tab-panel="support">
				<?php
				$donate_once_url = 'https://donate.stripe.com/9B65kDgG3fTQ2Kzcmwf7i00';
				$monthly_support_url = 'https://donate.stripe.com/cNibJ1dtRfTQfxlcmwf7i01';
				$plugin_page_url = 'https://kitgenix.com/plugins/kitgenix-stock-sync-for-woocommerce/';
				$review_url = 'https://wordpress.org/support/plugin/kitgenix-stock-sync-for-woocommerce/reviews/#new-post';
				$support_request_url = 'https://kitgenix.com/plugins/kitgenix-stock-sync-for-woocommerce/support';
				$copy_onclick = "if(window.navigator&&navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(" . wp_json_encode( $plugin_page_url ) . ");}else{window.prompt(" . wp_json_encode( __( 'Copy plugin link:', 'kitgenix-stock-sync-for-woocommerce' ) ) . ", " . wp_json_encode( $plugin_page_url ) . ");}return false;";
				$monthly_options = [
					[ 'label' => __( '£5.00 per month', 'kitgenix-stock-sync-for-woocommerce' ), 'url' => 'https://donate.stripe.com/cNibJ1dtRfTQfxlcmwf7i01' ],
					[ 'label' => __( '£10.00 per month', 'kitgenix-stock-sync-for-woocommerce' ), 'url' => 'https://donate.stripe.com/bJeeVd0H54b85WL3Q0f7i02' ],
					[ 'label' => __( '£30.00 per month', 'kitgenix-stock-sync-for-woocommerce' ), 'url' => 'https://donate.stripe.com/14A7sL4Xl0YWfxl3Q0f7i03' ],
					[ 'label' => __( '£50.00 per month', 'kitgenix-stock-sync-for-woocommerce' ), 'url' => 'https://donate.stripe.com/cNi4gz75t37498Xaeof7i04' ],
					[ 'label' => __( '£100.00 per month', 'kitgenix-stock-sync-for-woocommerce' ), 'url' => 'https://donate.stripe.com/6oUcN575t9vsethdqAf7i05' ],
					[ 'label' => __( '£250.00 per month', 'kitgenix-stock-sync-for-woocommerce' ), 'url' => 'https://donate.stripe.com/5kQ6oH0H5230bh5aeof7i06' ],
				];
				$children = $this->settings->children();
				$child_count = is_array($children) ? count($children) : 0;
				$master_cfg = $this->settings->master_config();
				$master_configured = !empty($master_cfg['url']) && !empty($master_cfg['store_id']) && !empty($master_cfg['secret']);
				$event_count = is_array($event_log) ? count($event_log) : 0;
				$backlog_count = is_array($backlog) ? count($backlog) : 0;
				$strict_status = $this->settings->strict_checkout_validation() ? __('On', 'kitgenix-stock-sync-for-woocommerce') : __('Off', 'kitgenix-stock-sync-for-woocommerce');
				$impact_cards = [
					[
						'label' => __('Role', 'kitgenix-stock-sync-for-woocommerce'),
						'value' => ucfirst($this->settings->role()),
						'meta'  => __('Whether this store is acting as the master or a connected child.', 'kitgenix-stock-sync-for-woocommerce'),
					],
					[
						'label' => $this->settings->is_master() ? __('Child stores', 'kitgenix-stock-sync-for-woocommerce') : __('Master connected', 'kitgenix-stock-sync-for-woocommerce'),
						'value' => $this->settings->is_master() ? number_format_i18n($child_count) : ($master_configured ? __('Yes', 'kitgenix-stock-sync-for-woocommerce') : __('No', 'kitgenix-stock-sync-for-woocommerce')),
						'meta'  => $this->settings->is_master() ? __('Connected child stores currently configured to receive updates.', 'kitgenix-stock-sync-for-woocommerce') : __('Whether this child store has a valid upstream master configuration.', 'kitgenix-stock-sync-for-woocommerce'),
					],
					[
						'label' => __('Events in logs', 'kitgenix-stock-sync-for-woocommerce'),
						'value' => number_format_i18n($event_count),
						'meta'  => __('Recorded synchronization events currently visible in the log view.', 'kitgenix-stock-sync-for-woocommerce'),
					],
					[
						'label' => __('Backlog items', 'kitgenix-stock-sync-for-woocommerce'),
						'value' => number_format_i18n($backlog_count),
						'meta'  => __('Queued inventory updates still waiting to be processed.', 'kitgenix-stock-sync-for-woocommerce'),
					],
					[
						'label' => __('Strict checkout', 'kitgenix-stock-sync-for-woocommerce'),
						'value' => $strict_status,
						'meta'  => __('Whether checkout validation is actively protecting against stale stock.', 'kitgenix-stock-sync-for-woocommerce'),
					],
					[
						'label' => __('Last inbound event', 'kitgenix-stock-sync-for-woocommerce'),
						'value' => $this->fmt_time((int)($health['last_inbound_event'] ?? 0)),
						'meta'  => __('The latest inbound sync timestamp seen by this store.', 'kitgenix-stock-sync-for-woocommerce'),
					],
				];
				$meaning_points = [
					__('Your store is already participating in a live stock-sync role, either as a master or connected child.', 'kitgenix-stock-sync-for-woocommerce'),
					__('Event and backlog data show whether synchronization is actively flowing or needs attention.', 'kitgenix-stock-sync-for-woocommerce'),
					__('Validation and inbound-event status help show how safely inventory changes are being handled.', 'kitgenix-stock-sync-for-woocommerce'),
				];
				$support_points = [
					__('Compatibility updates for new WordPress / WooCommerce releases', 'kitgenix-stock-sync-for-woocommerce'),
					__('Bug fixes, edge-case testing, and better multi-store coverage', 'kitgenix-stock-sync-for-woocommerce'),
					__('Security hardening and ongoing performance improvements', 'kitgenix-stock-sync-for-woocommerce'),
					__('Documentation upgrades and faster, clearer support responses', 'kitgenix-stock-sync-for-woocommerce'),
				];
				$trust_points = [
					__('No paid features locked behind donations', 'kitgenix-stock-sync-for-woocommerce'),
					__('No tracking or invasive upsells', 'kitgenix-stock-sync-for-woocommerce'),
					__('Support is always optional, and genuinely appreciated.', 'kitgenix-stock-sync-for-woocommerce'),
				];
				?>

				<div class="kitgenix-support-shell">
					<section class="kitgenix-support-hero">
						<div class="kitgenix-support-hero__copy">
							<span class="kitgenix-support-eyebrow"><?php echo esc_html__('Help keep Kitgenix independent', 'kitgenix-stock-sync-for-woocommerce'); ?></span>
							<h2 class="kitgenix-support-heading"><?php echo esc_html__('Support Kitgenix', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
							<p class="description kitgenix-support-intro"><?php echo esc_html__('We try to keep Kitgenix plugins lightweight, privacy-friendly, and free for everyone. If Stock Sync saves you admin time or helps prevent oversells across multiple stores, please consider supporting Kitgenix. Your support directly funds ongoing development, testing, and maintenance so we can keep features open and updates frequent.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
						</div>
						<div class="kitgenix-support-hero__aside">
							<p class="kitgenix-support-kicker"><?php echo esc_html__('Support this plugin', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
							<div class="kitgenix-support-actions">
								<a class="button button-primary" href="<?php echo esc_url($donate_once_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Donate once', 'kitgenix-stock-sync-for-woocommerce'); ?></a>
								<a class="button button-secondary" href="<?php echo esc_url($monthly_support_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Support monthly', 'kitgenix-stock-sync-for-woocommerce'); ?></a>
							</div>
							<p class="kitgenix-support-note"><?php echo esc_html__('Secure checkout. Powered by Stripe. Cancel anytime.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
						</div>
					</section>

					<section class="kitgenix-support-section kitgenix-support-section--feature">
						<div class="kitgenix-support-section__header">
							<h3 class="kitgenix-support-subheading"><?php echo esc_html__('Your site impact', 'kitgenix-stock-sync-for-woocommerce'); ?></h3>
							<p class="description"><?php echo esc_html__('These stats show how Stock Sync for WooCommerce is currently working on your site:', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
						</div>
						<div class="kitgenix-support-metric-grid">
							<?php foreach ($impact_cards as $impact_card) : ?>
								<div class="kitgenix-support-stat">
									<span class="kitgenix-support-stat__label"><?php echo esc_html($impact_card['label']); ?></span>
									<strong class="kitgenix-support-stat__value"><?php echo esc_html($impact_card['value']); ?></strong>
									<span class="kitgenix-support-stat__meta"><?php echo esc_html($impact_card['meta']); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
					</section>

					<div class="kitgenix-support-grid">
						<section class="kitgenix-support-section">
							<h3 class="kitgenix-support-subheading"><?php echo esc_html__('Support options & how it helps', 'kitgenix-stock-sync-for-woocommerce'); ?></h3>
							<p class="description"><?php echo esc_html__('One-off donation: A quick way to say thanks and help fund the next round of improvements.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
							<p class="description"><?php echo esc_html__('Monthly support helps keep development consistent if Stock Sync is part of your day-to-day multi-store workflow.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
							<div class="kitgenix-support-chip-list">
								<?php foreach ($monthly_options as $monthly_option) : ?>
									<a class="kitgenix-support-chip" href="<?php echo esc_url($monthly_option['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($monthly_option['label']); ?></a>
								<?php endforeach; ?>
							</div>
						</section>

						<section class="kitgenix-support-section">
							<h3 class="kitgenix-support-subheading"><?php echo esc_html__('What this means', 'kitgenix-stock-sync-for-woocommerce'); ?></h3>
							<ul class="kitgenix-support-list">
								<?php foreach ($meaning_points as $meaning_point) : ?>
									<li><?php echo esc_html($meaning_point); ?></li>
								<?php endforeach; ?>
							</ul>
						</section>

						<section class="kitgenix-support-section kitgenix-support-section--soft">
							<h3 class="kitgenix-support-subheading"><?php echo esc_html__('What your support helps with', 'kitgenix-stock-sync-for-woocommerce'); ?></h3>
							<ul class="kitgenix-support-list">
								<?php foreach ($support_points as $support_point) : ?>
									<li><?php echo esc_html($support_point); ?></li>
								<?php endforeach; ?>
							</ul>
						</section>

						<section class="kitgenix-support-section">
							<h3 class="kitgenix-support-subheading"><?php echo esc_html__('Not in a position to donate?', 'kitgenix-stock-sync-for-woocommerce'); ?></h3>
							<p class="description"><?php echo esc_html__('No worries - you can still massively help:', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
							<p class="description"><?php echo esc_html__('Reviews help others discover the plugin and keep the project sustainable. Sharing the plugin with merchants managing stock across multiple WooCommerce stores, and sending clear issue reports, both help improve reliability faster.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
							<div class="kitgenix-support-actions">
								<a class="button button-secondary" href="<?php echo esc_url($review_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Leave a WordPress.org review', 'kitgenix-stock-sync-for-woocommerce'); ?></a>
								<button type="button" class="button button-secondary" onclick="<?php echo esc_attr($copy_onclick); ?>"><?php echo esc_html__('Copy plugin link', 'kitgenix-stock-sync-for-woocommerce'); ?></button>
								<a class="button button-secondary" href="<?php echo esc_url($support_request_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open support / feature request', 'kitgenix-stock-sync-for-woocommerce'); ?></a>
							</div>
						</section>

						<section class="kitgenix-support-section kitgenix-support-section--full">
							<h3 class="kitgenix-support-subheading"><?php echo esc_html__('A small note on trust & privacy', 'kitgenix-stock-sync-for-woocommerce'); ?></h3>
							<ul class="kitgenix-support-list">
								<?php foreach ($trust_points as $trust_point) : ?>
									<li><?php echo esc_html($trust_point); ?></li>
								<?php endforeach; ?>
							</ul>
							<p class="kitgenix-support-footer-note"><?php echo esc_html__('Thank you for supporting Kitgenix.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
						</section>
					</div>
				</div>
			</div>
					</div>
			<?php $this->render_sidebar(); ?>
		</div>
		<?php
	}

	private function render_sidebar(): void {
		$social_base = (defined('KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL') ? (string) KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_URL : plugin_dir_url(__FILE__)) . 'assets/images/social-media/';
		?>
		<aside class="kitgenix-settings-sidebar" aria-label="<?php echo esc_attr__('Help and links', 'kitgenix-stock-sync-for-woocommerce'); ?>">
			<div class="kitgenix-sidebar-card">
				<h2><?php echo esc_html__('Need Help?', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
				<p><?php echo esc_html__('Open the documentation for setup guidance or send us a support request if you need help configuring the plugin.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
				<div class="kitgenix-sidebar-actions">
					<a class="button button-secondary" href="<?php echo esc_url('https://kitgenix.com/plugins/kitgenix-stock-sync-for-woocommerce/documentation/'); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Documentation', 'kitgenix-stock-sync-for-woocommerce'); ?></a>
					<a class="button button-primary" href="<?php echo esc_url('https://kitgenix.com/plugins/kitgenix-stock-sync-for-woocommerce/support'); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Request Support', 'kitgenix-stock-sync-for-woocommerce'); ?></a>
				</div>
			</div>

			<div class="kitgenix-sidebar-card">
				<h2><?php echo esc_html__('Visit Our Official Facebook Group', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
				<p><?php echo esc_html__('Join the Kitgenix community to ask questions, share feedback, and keep up with product updates.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
				<div class="kitgenix-sidebar-actions">
					<a class="button button-secondary" href="<?php echo esc_url('https://www.facebook.com/groups/kitgenix'); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Join Group', 'kitgenix-stock-sync-for-woocommerce'); ?></a>
				</div>
			</div>

			<div class="kitgenix-sidebar-card">
				<h2><?php echo esc_html__('Follow Us', 'kitgenix-stock-sync-for-woocommerce'); ?></h2>
				<p><?php echo esc_html__('Keep up with new releases, tutorials, and product news across our channels.', 'kitgenix-stock-sync-for-woocommerce'); ?></p>
				<div class="kitgenix-sidebar-social-grid">
					<a class="kitgenix-sidebar-social-link" href="https://kitgenix.com" target="_blank" rel="noopener noreferrer" aria-label="Website" title="Website"><img src="<?php echo esc_url($social_base . 'globe-solid.svg'); ?>" alt="" width="16" height="16" aria-hidden="true" /></a>
					<a class="kitgenix-sidebar-social-link" href="https://www.facebook.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="Facebook" title="Facebook"><img src="<?php echo esc_url($social_base . 'facebook-solid.svg'); ?>" alt="" width="16" height="16" aria-hidden="true" /></a>
					<a class="kitgenix-sidebar-social-link" href="https://www.instagram.com/kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram"><img src="<?php echo esc_url($social_base . 'instagram-solid.svg'); ?>" alt="" width="16" height="16" aria-hidden="true" /></a>
					<a class="kitgenix-sidebar-social-link" href="https://www.youtube.com/@Kitgenix" target="_blank" rel="noopener noreferrer" aria-label="YouTube" title="YouTube"><img src="<?php echo esc_url($social_base . 'youtube-solid.svg'); ?>" alt="" width="16" height="16" aria-hidden="true" /></a>
					<a class="kitgenix-sidebar-social-link" href="https://www.reddit.com/r/Kitgenix/" target="_blank" rel="noopener noreferrer" aria-label="Reddit" title="Reddit"><img src="<?php echo esc_url($social_base . 'reddit-solid.svg'); ?>" alt="" width="16" height="16" aria-hidden="true" /></a>
					<a class="kitgenix-sidebar-social-link" href="https://www.linkedin.com/company/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn" title="LinkedIn"><img src="<?php echo esc_url($social_base . 'linkedin-solid.svg'); ?>" alt="" width="16" height="16" aria-hidden="true" /></a>
					<a class="kitgenix-sidebar-social-link" href="https://x.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="X" title="X"><img src="<?php echo esc_url($social_base . 'x-solid.svg'); ?>" alt="" width="16" height="16" aria-hidden="true" /></a>
					<a class="kitgenix-sidebar-social-link" href="https://www.tiktok.com/@kitgenix" target="_blank" rel="noopener noreferrer" aria-label="TikTok" title="TikTok"><img src="<?php echo esc_url($social_base . 'tiktok-solid.svg'); ?>" alt="" width="16" height="16" aria-hidden="true" /></a>
					<a class="kitgenix-sidebar-social-link" href="https://github.com/kitgenix" target="_blank" rel="noopener noreferrer" aria-label="GitHub" title="GitHub"><img src="<?php echo esc_url($social_base . 'github-solid.svg'); ?>" alt="" width="16" height="16" aria-hidden="true" /></a>
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
