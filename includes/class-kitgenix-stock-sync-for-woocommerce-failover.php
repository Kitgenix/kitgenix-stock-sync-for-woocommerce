<?php
defined('ABSPATH') || exit;

/**
 * Failover Readiness Report: documents, from this store's live
 * configuration and health, what happens if the Master becomes
 * unreachable – checkout behaviour, how long queued changes keep
 * retrying, the age of the last-known-good stock snapshots a Child can
 * fall back on, and how to recover.
 *
 * On a Child the report covers this store. On the Master it also covers
 * every Child, using the compact summary each Child returns from /ping
 * (ping_summary()), recorded in that Child's health as remote_failover.
 */
final class Kitgenix_Stock_Sync_For_WooCommerce_Failover {

	/** Backlog rows are capped at 200 (Settings::BACKLOG_CAP); warn well before that. */
	private const BACKLOG_WARN_AT = 150;

	private Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings;
	private Kitgenix_Stock_Sync_For_WooCommerce_Sync $sync;

	public function __construct(
		Kitgenix_Stock_Sync_For_WooCommerce_Settings $settings,
		Kitgenix_Stock_Sync_For_WooCommerce_Sync $sync
	) {
		$this->settings = $settings;
		$this->sync = $sync;
	}

	/** Compact, non-secret readiness summary returned to the Master from /ping. */
	public function ping_summary(): array {
		$snap = $this->sync->stock_snapshot_summary(0);
		$backlog = $this->backlog_stats();
		return [
			'strict' => $this->settings->strict_checkout_validation(),
			'strategy' => $this->settings->checkout_validation_failure_strategy(),
			'stale_minutes' => $this->settings->checkout_stale_cache_minutes(),
			'snapshots' => [
				'count' => (int) $snap['count'],
				'usable' => (int) $snap['usable'],
				'newest_age' => $snap['newest_age'],
				'oldest_age' => $snap['oldest_age'],
			],
			'backlog_pending' => $backlog['pending'],
			'backlog_exhausted' => $backlog['exhausted'],
			'action_scheduler' => $this->action_scheduler_available(),
		];
	}

	public function build(): array {
		$is_master = $this->settings->is_master();
		$snap = $this->sync->stock_snapshot_summary(10);
		$backlog = $this->backlog_stats();
		$findings = [];

		$as_ok = $this->action_scheduler_available();
		$findings[] = $as_ok
			? ['level' => 'ok', 'text' => __('Action Scheduler is available, so failed deliveries queue and retry in the background.', 'kitgenix-stock-sync-for-woocommerce')]
			: ['level' => 'critical', 'text' => __('Action Scheduler is not available. Failed deliveries are not retried automatically and the health ping does not run.', 'kitgenix-stock-sync-for-woocommerce')];

		if ($backlog['exhausted'] > 0) {
			$findings[] = ['level' => 'critical', 'text' => sprintf(
				/* translators: %d: number of backlog items */
				__('%d queued change(s) used up their retries and will not be delivered without a manual Retry (Logs tab).', 'kitgenix-stock-sync-for-woocommerce'),
				$backlog['exhausted']
			)];
		}
		if ($backlog['pending'] > 0) {
			$findings[] = ['level' => 'warning', 'text' => sprintf(
				/* translators: 1: number of backlog items, 2: age, e.g. "2 hours" */
				__('%1$d change(s) are waiting to be retried; the oldest has been waiting %2$s.', 'kitgenix-stock-sync-for-woocommerce'),
				$backlog['pending'],
				$this->duration((int) $backlog['oldest_pending_age'])
			)];
		}
		if ($backlog['total'] >= self::BACKLOG_WARN_AT) {
			$findings[] = ['level' => 'warning', 'text' => __('The backlog is close to its 200-item limit. During a long outage the oldest queued changes are dropped once it is full; run a Reconcile after recovery.', 'kitgenix-stock-sync-for-woocommerce')];
		}

		$holds = count($this->settings->sku_holds());
		if ($holds > 0) {
			$findings[] = ['level' => 'warning', 'text' => sprintf(
				/* translators: %d: number of SKUs */
				__('%d SKU(s) are on a sync hold and do not sync at all until repaired (Conflicts tab).', 'kitgenix-stock-sync-for-woocommerce'),
				$holds
			)];
		}

		$report = [
			'generated_at' => time(),
			'store_name' => $this->settings->this_store_name(),
			'role' => $this->settings->role(),
			'retry_window' => Kitgenix_Stock_Sync_For_WooCommerce_Sync::retry_window_seconds(),
			'retry_schedule' => Kitgenix_Stock_Sync_For_WooCommerce_Sync::retry_schedule(),
			'action_scheduler' => $as_ok,
			'backlog' => $backlog,
			'snapshots' => $snap,
			'master' => null,
			'children' => [],
		];

		if ($is_master) {
			$findings = array_merge($findings, $this->master_findings($report));
		} else {
			$findings = array_merge($findings, $this->child_findings($report, $snap));
		}

		$report['findings'] = $findings;
		$report['verdict'] = $this->verdict($findings);
		$report['behaviour'] = $is_master ? $this->master_behaviour($report) : $this->child_behaviour($report, $snap);
		$report['recovery'] = $this->recovery_steps($is_master);

		return $report;
	}

	/* -----------------------------
	 * Child perspective
	 * ----------------------------- */

	private function child_findings(array &$report, array $snap): array {
		$findings = [];
		$master = $this->settings->master_config();
		$configured = !empty($master['url']) && !empty($master['secret']) && !empty($master['store_id']);
		$health = $this->settings->get_master_health();
		$status = Kitgenix_Stock_Sync_For_WooCommerce_Settings::derive_status($health);
		$last_contact = max((int) $health['last_outbound_success'], (int) $health['last_ping'], (int) $health['last_inbound']);

		$report['master'] = [
			'configured' => $configured,
			'status' => $status,
			'last_contact' => $last_contact,
			'last_error' => (string) $health['last_error_message'],
		];

		if (!$configured) {
			$findings[] = ['level' => 'critical', 'text' => __('The Master connection is not configured, so this store is not syncing at all.', 'kitgenix-stock-sync-for-woocommerce')];
			return $findings;
		}

		if ($status === 'error') {
			$findings[] = ['level' => 'critical', 'text' => __('The Master is unreachable right now: the most recent contact attempt failed. The behaviour described below is in effect.', 'kitgenix-stock-sync-for-woocommerce')];
		} elseif ($status === 'stale' || $status === 'never') {
			$findings[] = ['level' => 'warning', 'text' => __('There has been no successful contact with the Master in the last 24 hours.', 'kitgenix-stock-sync-for-woocommerce')];
		} else {
			$findings[] = ['level' => 'ok', 'text' => sprintf(
				/* translators: %s: age, e.g. "5 minutes" */
				__('The Master is reachable (last successful contact %s ago).', 'kitgenix-stock-sync-for-woocommerce'),
				$this->duration(time() - $last_contact)
			)];
		}

		$strict = $this->settings->strict_checkout_validation();
		$strategy = $this->settings->checkout_validation_failure_strategy();
		if (!$strict) {
			$findings[] = ['level' => 'warning', 'text' => __('Strict checkout validation is off: checkout never checks the Master, so oversells are possible whenever stores are out of step.', 'kitgenix-stock-sync-for-woocommerce')];
		} elseif ($strategy === 'fail_open') {
			$findings[] = ['level' => 'warning', 'text' => __('If the Master is unreachable, checkout is allowed without verification (fail open). Oversells are possible during an outage.', 'kitgenix-stock-sync-for-woocommerce')];
		} elseif ($strategy === 'fail_closed') {
			$findings[] = ['level' => 'warning', 'text' => __('If the Master is unreachable, checkout is blocked (fail closed). No oversells, but no sales of synced products during an outage.', 'kitgenix-stock-sync-for-woocommerce')];
		} elseif ((int) $snap['usable'] === 0) {
			$findings[] = ['level' => 'warning', 'text' => __('Checkout falls back to last-known stock, but no snapshot is currently fresh enough to use, so an outage right now would behave like fail open.', 'kitgenix-stock-sync-for-woocommerce')];
		} else {
			$findings[] = ['level' => 'ok', 'text' => sprintf(
				/* translators: 1: usable snapshots, 2: total snapshots */
				__('Checkout falls back to last-known stock: %1$d of %2$d snapshot(s) are fresh enough to use right now.', 'kitgenix-stock-sync-for-woocommerce'),
				(int) $snap['usable'],
				(int) $snap['count']
			)];
		}

		return $findings;
	}

	private function child_behaviour(array $report, array $snap): array {
		$strict = $this->settings->strict_checkout_validation();
		$strategy = $this->settings->checkout_validation_failure_strategy();
		$minutes = $this->settings->checkout_stale_cache_minutes();

		if (!$strict) {
			$checkout = __('Checkout is not checked against the Master (strict checkout validation is off). Customers can buy anything this store shows in stock.', 'kitgenix-stock-sync-for-woocommerce');
		} elseif ($strategy === 'fail_closed') {
			$checkout = __('Checkout is blocked with "Stock could not be verified right now" for any cart containing a synced SKU, until the Master responds again.', 'kitgenix-stock-sync-for-woocommerce');
		} elseif ($strategy === 'stale_cache') {
			$checkout = sprintf(
				/* translators: %d: minutes */
				__('Checkout uses the last-known-good stock snapshot for each SKU if it is no more than %d minutes old, and blocks carts that snapshot shows as out of stock or short. A cart with no fresh-enough snapshot is allowed (fail open).', 'kitgenix-stock-sync-for-woocommerce'),
				$minutes
			);
		} else {
			$checkout = __('Checkout is allowed without verification (fail open). A definite "out of stock" from a reachable Master always blocks checkout, but while it is unreachable nothing is checked.', 'kitgenix-stock-sync-for-woocommerce');
		}

		return [
			[
				'title' => __('Sales on this store', 'kitgenix-stock-sync-for-woocommerce'),
				'text' => __('Customers can keep ordering. WooCommerce keeps reducing and restoring stock on this store as normal.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			[
				'title' => __('Checkout', 'kitgenix-stock-sync-for-woocommerce'),
				'text' => $checkout,
			],
			[
				'title' => __('Changes made here', 'kitgenix-stock-sync-for-woocommerce'),
				'text' => sprintf(
					/* translators: 1: number of retries, 2: duration, e.g. "25 hours", 3: list of waits, e.g. "5 mins, 15 mins, 1 hour" */
					__('Each stock change is queued in the backlog and retried %1$d times over about %2$s (waiting %3$s). After that it stops and needs a manual Retry on the Logs tab.', 'kitgenix-stock-sync-for-woocommerce'),
					count($report['retry_schedule']),
					$this->duration((int) $report['retry_window']),
					implode(', ', array_map([$this, 'duration'], $report['retry_schedule']))
				),
			],
			[
				'title' => __('Sales on other stores', 'kitgenix-stock-sync-for-woocommerce'),
				'text' => __('Stock changes from the Master and other Children stop arriving, so stock here drifts further from the truth the longer the outage lasts.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			[
				'title' => __('Last-known-good stock snapshots', 'kitgenix-stock-sync-for-woocommerce'),
				'text' => $this->snapshot_sentence($snap, $strategy),
			],
			[
				'title' => __('When the Master returns', 'kitgenix-stock-sync-for-woocommerce'),
				'text' => __('Queued changes are delivered on their next retry. Versioned staleness fencing means a delayed or out-of-order update can never overwrite newer stock. Run a Reconcile (differences only) on the Master to correct any drift.', 'kitgenix-stock-sync-for-woocommerce'),
			],
		];
	}

	private function snapshot_sentence(array $snap, string $strategy): string {
		if ((int) $snap['count'] === 0) {
			return __('No snapshots are stored yet. They are recorded each time the Master answers a checkout check and each time this store applies an update from the Master.', 'kitgenix-stock-sync-for-woocommerce');
		}

		$sentence = sprintf(
			/* translators: 1: snapshot count, 2: newest age, 3: oldest age, 4: usable count, 5: max age in minutes */
			__('%1$d snapshot(s) stored; newest %2$s old, oldest %3$s old. %4$d are within the %5$d-minute limit for checkout use.', 'kitgenix-stock-sync-for-woocommerce'),
			(int) $snap['count'],
			$this->duration((int) $snap['newest_age']),
			$this->duration((int) $snap['oldest_age']),
			(int) $snap['usable'],
			(int) round(((int) $snap['max_age']) / 60)
		);
		if ($strategy !== 'stale_cache') {
			$sentence .= ' ' . __('They are only used at checkout in "Use last-known stock" mode.', 'kitgenix-stock-sync-for-woocommerce');
		}
		return $sentence;
	}

	/* -----------------------------
	 * Master perspective
	 * ----------------------------- */

	private function master_findings(array &$report): array {
		$findings = [];
		$children = [];

		foreach ($this->settings->children() as $child) {
			if (!is_array($child)) continue;
			$health = array_merge(Kitgenix_Stock_Sync_For_WooCommerce_Settings::default_health(), is_array($child['health'] ?? null) ? $child['health'] : []);
			$remote = is_array($health['remote_failover'] ?? null) ? $health['remote_failover'] : null;
			$children[] = [
				'id' => (string) ($child['id'] ?? ''),
				'name' => (string) (($child['name'] ?? '') !== '' ? $child['name'] : ($child['id'] ?? '')),
				'enabled' => (bool) ($child['enabled'] ?? true),
				'status' => Kitgenix_Stock_Sync_For_WooCommerce_Settings::derive_status($health),
				'last_contact' => max((int) $health['last_outbound_success'], (int) $health['last_ping'], (int) $health['last_inbound']),
				'remote' => $remote,
			];
		}
		$report['children'] = $children;

		if (empty($children)) {
			$findings[] = ['level' => 'critical', 'text' => __('No Child stores are configured.', 'kitgenix-stock-sync-for-woocommerce')];
			return $findings;
		}

		foreach ($children as $c) {
			if (!$c['enabled']) continue;
			if ($c['status'] === 'error') {
				$findings[] = ['level' => 'critical', 'text' => sprintf(/* translators: %s: store name */ __('%s is unreachable from the Master right now.', 'kitgenix-stock-sync-for-woocommerce'), $c['name'])];
			}
			if ($c['remote'] === null) {
				$findings[] = ['level' => 'warning', 'text' => sprintf(/* translators: %s: store name */ __('%s has not reported its failover settings yet (it runs an older plugin version, or has not been pinged since updating).', 'kitgenix-stock-sync-for-woocommerce'), $c['name'])];
				continue;
			}
			if (empty($c['remote']['strict']) || ($c['remote']['strategy'] ?? '') === 'fail_open') {
				$findings[] = ['level' => 'warning', 'text' => sprintf(/* translators: %s: store name */ __('%s allows checkout without verification if this Master is unreachable (oversell risk).', 'kitgenix-stock-sync-for-woocommerce'), $c['name'])];
			}
			if ((int) ($c['remote']['backlog_exhausted'] ?? 0) > 0) {
				$findings[] = ['level' => 'critical', 'text' => sprintf(/* translators: %s: store name */ __('%s has queued changes that used up their retries.', 'kitgenix-stock-sync-for-woocommerce'), $c['name'])];
			}
		}

		return $findings;
	}

	private function master_behaviour(array $report): array {
		return [
			[
				'title' => __('Single Master', 'kitgenix-stock-sync-for-woocommerce'),
				'text' => __('There is no automatic failover. Children do not sync with each other directly, so while this Master is unreachable no stock moves between stores.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			[
				'title' => __('Sales on each Child', 'kitgenix-stock-sync-for-woocommerce'),
				'text' => __('Children keep selling from their own local stock. Each Child\'s checkout follows its own "If Master cannot be verified at checkout" setting, shown per store below.', 'kitgenix-stock-sync-for-woocommerce'),
			],
			[
				'title' => __('Changes made on Children', 'kitgenix-stock-sync-for-woocommerce'),
				'text' => sprintf(
					/* translators: 1: number of retries, 2: duration, e.g. "25 hours" */
					__('Each Child queues its changes and retries them %1$d times over about %2$s, then needs a manual Retry.', 'kitgenix-stock-sync-for-woocommerce'),
					count($report['retry_schedule']),
					$this->duration((int) $report['retry_window'])
				),
			],
			[
				'title' => __('Changes made on this Master', 'kitgenix-stock-sync-for-woocommerce'),
				'text' => __('If this store is up but a Child is not, pushes to that Child queue and retry on the same schedule.', 'kitgenix-stock-sync-for-woocommerce'),
			],
		];
	}

	/* -----------------------------
	 * Shared
	 * ----------------------------- */

	private function recovery_steps(bool $is_master): array {
		$steps = [
			__('Restore the Master. Queued changes are delivered automatically on their next retry.', 'kitgenix-stock-sync-for-woocommerce'),
			__('On each store, retry any exhausted backlog items (Logs tab, or wp kitgenix-stock-sync backlog retry-all).', 'kitgenix-stock-sync-for-woocommerce'),
			__('On the Master, run a Reconcile with "Push differences only" to correct drift that built up during the outage.', 'kitgenix-stock-sync-for-woocommerce'),
			__('If the Master cannot be restored, promote one Child: set its role to Master, add the other Children on its Stores tab, update each Child\'s Master connection, then run a full Reconcile. The promoted store already holds the same GIDs, and its version counters continue from the versions it last applied, so Children accept its updates. If a Child reports "Superseded update (ignored)" for some SKUs afterwards, run the Reconcile again.', 'kitgenix-stock-sync-for-woocommerce'),
		];
		if (!$is_master) {
			$steps[] = __('Check this report on the Master too: it lists every Child\'s readiness in one place.', 'kitgenix-stock-sync-for-woocommerce');
		}
		return $steps;
	}

	/** @return 'ready'|'at_risk'|'not_ready' */
	private function verdict(array $findings): string {
		$levels = array_column($findings, 'level');
		if (in_array('critical', $levels, true)) return 'not_ready';
		if (in_array('warning', $levels, true)) return 'at_risk';
		return 'ready';
	}

	private function backlog_stats(): array {
		$pending = 0;
		$exhausted = 0;
		$oldest = 0;
		$backlog = $this->settings->get_backlog();
		foreach ($backlog as $row) {
			if (!is_array($row)) continue;
			if (($row['status'] ?? '') === 'exhausted') {
				$exhausted++;
				continue;
			}
			$pending++;
			$t = (int) ($row['time'] ?? 0);
			if ($t > 0 && ($oldest === 0 || $t < $oldest)) $oldest = $t;
		}
		return [
			'total' => count($backlog),
			'pending' => $pending,
			'exhausted' => $exhausted,
			'oldest_pending_age' => $oldest > 0 ? time() - $oldest : 0,
		];
	}

	private function action_scheduler_available(): bool {
		return function_exists('as_enqueue_async_action') && function_exists('as_schedule_single_action');
	}

	public function duration(int $seconds): string {
		if ($seconds < 60) {
			/* translators: %d: number of seconds */
			return sprintf(_n('%d second', '%d seconds', max(0, $seconds), 'kitgenix-stock-sync-for-woocommerce'), max(0, $seconds));
		}
		return human_time_diff(0, $seconds);
	}

	public static function verdict_label(string $verdict): string {
		$labels = [
			'ready' => __('Ready', 'kitgenix-stock-sync-for-woocommerce'),
			'at_risk' => __('At risk', 'kitgenix-stock-sync-for-woocommerce'),
			'not_ready' => __('Not ready', 'kitgenix-stock-sync-for-woocommerce'),
		];
		return $labels[$verdict] ?? $verdict;
	}

	public static function strategy_label(string $strategy): string {
		$labels = [
			'fail_open' => __('Fail open', 'kitgenix-stock-sync-for-woocommerce'),
			'fail_closed' => __('Fail closed', 'kitgenix-stock-sync-for-woocommerce'),
			'stale_cache' => __('Last-known stock', 'kitgenix-stock-sync-for-woocommerce'),
		];
		return $labels[$strategy] ?? $strategy;
	}

	/** The report as a Markdown document, for download or WP-CLI. */
	public function to_markdown(array $r): string {
		$fmt = static fn(int $ts) => $ts > 0 ? gmdate('Y-m-d H:i:s', $ts) . ' UTC' : 'never';
		$lines = [];
		$lines[] = '# Failover Readiness Report';
		$lines[] = '';
		$lines[] = sprintf('- Store: %s (%s)', $r['store_name'], ucfirst((string) $r['role']));
		$lines[] = sprintf('- Generated: %s', $fmt((int) $r['generated_at']));
		$lines[] = sprintf('- Verdict: **%s**', self::verdict_label((string) $r['verdict']));
		$lines[] = '';
		$lines[] = '## Findings';
		$lines[] = '';
		foreach ($r['findings'] as $f) {
			$lines[] = sprintf('- [%s] %s', strtoupper((string) $f['level']), $f['text']);
		}
		$lines[] = '';
		$lines[] = '## What happens if the Master is unreachable';
		$lines[] = '';
		foreach ($r['behaviour'] as $b) {
			$lines[] = sprintf('- **%s:** %s', $b['title'], $b['text']);
		}

		if (!empty($r['snapshots']['oldest'])) {
			$lines[] = '';
			$lines[] = '## Oldest last-known-good snapshots';
			$lines[] = '';
			$lines[] = '| SKU | Age | Usable at checkout |';
			$lines[] = '|---|---|---|';
			foreach ($r['snapshots']['oldest'] as $s) {
				$lines[] = sprintf('| %s | %s | %s |', $s['sku'], $this->duration((int) $s['age']), $s['usable'] ? 'yes' : 'no');
			}
		}

		if (!empty($r['children'])) {
			$lines[] = '';
			$lines[] = '## Child stores';
			$lines[] = '';
			$lines[] = '| Store | Status | Last contact | Checkout if Master unreachable | Usable snapshots | Oldest snapshot | Backlog (pending / exhausted) |';
			$lines[] = '|---|---|---|---|---|---|---|';
			foreach ($r['children'] as $c) {
				$rem = $c['remote'];
				$lines[] = sprintf(
					'| %s | %s | %s | %s | %s | %s | %s |',
					$c['name'] . ($c['enabled'] ? '' : ' (disabled)'),
					$c['status'],
					$fmt((int) $c['last_contact']),
					$rem === null ? 'unknown' : (empty($rem['strict']) ? 'not checked' : self::strategy_label((string) ($rem['strategy'] ?? ''))),
					$rem === null ? '–' : sprintf('%d / %d', (int) ($rem['snapshots']['usable'] ?? 0), (int) ($rem['snapshots']['count'] ?? 0)),
					$rem === null || ($rem['snapshots']['oldest_age'] ?? null) === null ? '–' : $this->duration((int) $rem['snapshots']['oldest_age']),
					$rem === null ? '–' : sprintf('%d / %d', (int) ($rem['backlog_pending'] ?? 0), (int) ($rem['backlog_exhausted'] ?? 0))
				);
			}
		}

		$lines[] = '';
		$lines[] = '## Recovery';
		$lines[] = '';
		foreach ($r['recovery'] as $i => $step) {
			$lines[] = sprintf('%d. %s', $i + 1, $step);
		}
		$lines[] = '';

		return implode("\n", $lines);
	}
}
