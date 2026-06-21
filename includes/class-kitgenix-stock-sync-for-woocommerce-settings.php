<?php
defined('ABSPATH') || exit;

final class Kitgenix_Stock_Sync_For_WooCommerce_Settings {

	public const OPTION_KEY = 'kitgenix_stock_sync_for_woocommerce_settings';

	public static function ensure_defaults(): void {
		$opt = get_option(self::OPTION_KEY, null);
		if (!is_array($opt)) $opt = [];

		$changed = false;

		if (empty($opt['this_store_id'])) {
			$opt['this_store_id'] = wp_generate_uuid4();
			$changed = true;
		}

		if (empty($opt['this_store_name'])) {
			$opt['this_store_name'] = get_bloginfo('name') ?: 'Woo Store';
			$changed = true;
		}

		if (empty($opt['role']) || !in_array($opt['role'], ['master', 'child'], true)) {
			$opt['role'] = 'child';
			$changed = true;
		}

		if (!isset($opt['strict_checkout_validation'])) {
			$opt['strict_checkout_validation'] = true;
			$changed = true;
		}

		if (!isset($opt['master']) || !is_array($opt['master'])) {
			$opt['master'] = [
				'url'      => '',
				'store_id' => '',
				'secret'   => '',
			];
			$changed = true;
		}

		if (!isset($opt['children']) || !is_array($opt['children'])) {
			$opt['children'] = [];
			$changed = true;
		}

		if (!isset($opt['exclusions']) || !is_array($opt['exclusions'])) {
			$opt['exclusions'] = ['skus' => []];
			$changed = true;
		}

		if (!isset($opt['notices']) || !is_array($opt['notices'])) {
			$opt['notices'] = [];
			$changed = true;
		}

		if (!isset($opt['event_log']) || !is_array($opt['event_log'])) {
			$opt['event_log'] = [];
			$changed = true;
		}

		if (!isset($opt['backlog']) || !is_array($opt['backlog'])) {
			$opt['backlog'] = [];
			$changed = true;
		}

		if (!isset($opt['reconcile']) || !is_array($opt['reconcile'])) {
			$opt['reconcile'] = [
				'running' => false,
				'page' => 0,
				'per_page' => 200,
				'last_run' => 0,
			];
			$changed = true;
		}

		if (!isset($opt['health']) || !is_array($opt['health'])) {
			$opt['health'] = [
				'last_inbound_event' => 0,
				'last_outbound_success' => 0,
				'last_outbound_error' => 0,
				'last_error_message' => '',
				'last_reconcile_start' => 0,
				'last_reconcile_complete' => 0,
			];
			$changed = true;
		} else {
			// ensure keys exist
			$defaults = [
				'last_inbound_event' => 0,
				'last_outbound_success' => 0,
				'last_outbound_error' => 0,
				'last_error_message' => '',
				'last_reconcile_start' => 0,
				'last_reconcile_complete' => 0,
			];
			foreach ($defaults as $k => $v) {
				if (!array_key_exists($k, $opt['health'])) {
					$opt['health'][$k] = $v;
					$changed = true;
				}
			}
		}

		if ($changed) {
			update_option(self::OPTION_KEY, $opt, false);
		}
	}

	public function get_all(): array {
		$opt = get_option(self::OPTION_KEY, []);
		return is_array($opt) ? $opt : [];
	}

	public function update_all(array $new): void {
		update_option(self::OPTION_KEY, $new, false);
	}

	public function role(): string {
		$opt = $this->get_all();
		return isset($opt['role']) ? (string) $opt['role'] : 'child';
	}

	public function is_master(): bool {
		return $this->role() === 'master';
	}

	public function is_child(): bool {
		return $this->role() === 'child';
	}

	public function this_store_id(): string {
		$opt = $this->get_all();
		return (string) ($opt['this_store_id'] ?? '');
	}

	public function this_store_name(): string {
		$opt = $this->get_all();
		return (string) ($opt['this_store_name'] ?? '');
	}

	public function strict_checkout_validation(): bool {
		$opt = $this->get_all();
		return (bool) ($opt['strict_checkout_validation'] ?? true);
	}

	public function master_config(): array {
		$opt = $this->get_all();
		return is_array($opt['master'] ?? null) ? $opt['master'] : ['url' => '', 'store_id' => '', 'secret' => ''];
	}

	public function children(): array {
		$opt = $this->get_all();
		return is_array($opt['children'] ?? null) ? $opt['children'] : [];
	}

	public function get_child_by_id(string $store_id): ?array {
		$store_id = trim($store_id);
		if ($store_id === '') return null;
		foreach ($this->children() as $child) {
			if (!is_array($child)) continue;
			if ((string)($child['id'] ?? '') === $store_id) return $child;
		}
		return null;
	}

	public function update_child(string $store_id, array $patch): bool {
		$store_id = trim($store_id);
		if ($store_id === '') return false;

		$opt = $this->get_all();
		$children = is_array($opt['children'] ?? null) ? $opt['children'] : [];

		$updated = false;
		foreach ($children as $i => $child) {
			if (!is_array($child)) continue;
			if ((string)($child['id'] ?? '') !== $store_id) continue;

			foreach ($patch as $k => $v) {
				$key = (string)$k;
				if ($key === 'enabled') {
					$child['enabled'] = (bool)$v;
				} elseif (in_array($key, ['name','url','secret'], true)) {
					$child[$key] = (string)$v;
				}
			}

			$children[$i] = $child;
			$updated = true;
			break;
		}

		if ($updated) {
			$opt['children'] = $children;
			$this->update_all($opt);
		}

		return $updated;
	}

	public function excluded_skus(): array {
		$opt = $this->get_all();
		$list = $opt['exclusions']['skus'] ?? [];
		return is_array($list) ? array_values(array_filter(array_map('strval', $list))) : [];
	}

	public function is_excluded_sku(string $sku): bool {
		$sku = trim($sku);
		if ($sku === '') return false;
		return in_array($sku, $this->excluded_skus(), true);
	}

	public function set_notice(string $type, string $message): void {
		$opt = $this->get_all();
		$opt['notices'] = is_array($opt['notices'] ?? null) ? $opt['notices'] : [];
		$opt['notices'][] = [
			'time'    => time(),
			'type'    => $type,
			'message' => $message,
		];
		$opt['notices'] = array_slice($opt['notices'], -20);
		$this->update_all($opt);
	}

	public function pop_notices(): array {
		$opt = $this->get_all();
		$notices = is_array($opt['notices'] ?? null) ? $opt['notices'] : [];
		$opt['notices'] = [];
		$this->update_all($opt);
		return $notices;
	}

	public function add_event_log(string $level, string $message, array $context = []): void {
		$opt = $this->get_all();
		$opt['event_log'] = is_array($opt['event_log'] ?? null) ? $opt['event_log'] : [];
		$opt['event_log'][] = [
			'time' => time(),
			'level' => $level,
			'message' => $message,
			'context' => $this->sanitize_context($context),
		];
		$opt['event_log'] = array_slice($opt['event_log'], -250);
		$this->update_all($opt);
	}

	public function get_event_log(): array {
		$opt = $this->get_all();
		return is_array($opt['event_log'] ?? null) ? $opt['event_log'] : [];
	}

	public function clear_event_log(): void {
		$opt = $this->get_all();
		$opt['event_log'] = [];
		$this->update_all($opt);
	}

	public function add_backlog(string $type, string $store_id, array $payload, int $attempt, string $error): void {
		$opt = $this->get_all();
		$opt['backlog'] = is_array($opt['backlog'] ?? null) ? $opt['backlog'] : [];
		$opt['backlog'][] = [
			'time' => time(),
			'type' => $type,
			'store_id' => $store_id,
			'attempt' => $attempt,
			'error' => $error,
			'payload_meta' => [
				'event_type' => (string)($payload['event_type'] ?? ''),
				'context' => (string)($payload['context'] ?? ''),
				'items_count' => isset($payload['items']) && is_array($payload['items']) ? count($payload['items']) : 0,
			],
		];
		$opt['backlog'] = array_slice($opt['backlog'], -250);
		$this->update_all($opt);
	}

	public function get_backlog(): array {
		$opt = $this->get_all();
		return is_array($opt['backlog'] ?? null) ? $opt['backlog'] : [];
	}

	public function clear_backlog(): void {
		$opt = $this->get_all();
		$opt['backlog'] = [];
		$this->update_all($opt);
	}

	public function reconcile_state(): array {
		$opt = $this->get_all();
		return is_array($opt['reconcile'] ?? null) ? $opt['reconcile'] : ['running'=>false,'page'=>0,'per_page'=>200,'last_run'=>0];
	}

	public function set_reconcile_state(array $state): void {
		$opt = $this->get_all();
		$opt['reconcile'] = $state;
		$this->update_all($opt);
	}

	public function get_health(): array {
		$opt = $this->get_all();
		$h = $opt['health'] ?? [];
		return is_array($h) ? $h : [];
	}

	public function set_health(array $patch): void {
		$opt = $this->get_all();
		$h = is_array($opt['health'] ?? null) ? $opt['health'] : [];
		foreach ($patch as $k => $v) {
			$h[(string)$k] = $v;
		}
		$opt['health'] = $h;
		$this->update_all($opt);
	}

	public function set_health_value(string $key, mixed $value): void {
		$this->set_health([$key => $value]);
	}

	public function health_value(string $key, mixed $default = null): mixed {
		$h = $this->get_health();
		return array_key_exists($key, $h) ? $h[$key] : $default;
	}

	private function sanitize_context(array $context): array {
		$allowed = [];
		foreach ($context as $k => $v) {
			$key = is_string($k) ? $k : (string)$k;
			if (is_scalar($v) || $v === null) {
				$allowed[$key] = $v;
			} else {
				$allowed[$key] = wp_json_encode($v);
			}
		}
		return $allowed;
	}

	public static function parent_menu_slug(): string {
		return (string) apply_filters('kitgenix_stock_sync_for_woocommerce_parent_menu_slug', 'kitgenix');
	}
}
