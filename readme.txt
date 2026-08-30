=== Kitgenix Stock Sync for WooCommerce ===
Contributors: kitgenix
Donate link: https://www.paypal.com/donate/?hosted_button_id=KALF36K6JJ9B2
Tags: woocommerce, stock sync, inventory sync, multistore, backorders
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.0.0
Requires Plugins: woocommerce
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Plugin URI: https://wordpress.org/plugins/kitgenix-stock-sync-for-woocommerce/
Author: Kitgenix
Author URI: https://kitgenix.com/
Author Plugin URI: https://kitgenix.com/plugins/kitgenix-stock-sync-for-woocommerce
Documentation URI: https://kitgenix.com/plugins/kitgenix-stock-sync-for-woocommerce/documentation
Support URI: https://wordpress.org/support/plugin/kitgenix-stock-sync-for-woocommerce/
Author Support URI: https://kitgenix.com/plugins/kitgenix-stock-sync-for-woocommerce/support
Feature Request URI: https://kitgenix.com/plugins/kitgenix-stock-sync-for-woocommerce/feature-request

Real-time WooCommerce stock sync between stores: quantity, status, and backorders kept in step via secure, signed REST requests.

== Description ==

**Kitgenix Stock Sync for WooCommerce** keeps stock levels consistent across two or more WooCommerce stores in real time. It's built for merchants running a warehouse/retail split, a wholesale and a retail front end, or any set of stores that share the same physical inventory and can't afford to sell what they don't have.

= The Problem: Stock Drift =

Stock drift happens when you update stock on one store but a connected store still shows the old quantity. Left unresolved, it leads to oversells, customer frustration, and messy fulfilment. This plugin solves it with one authoritative **Master** store and one or more **Child** stores that stay in step with it.

= Key Features =

* **Master/Child architecture** – one store holds the authoritative stock state; every Child receives updates from it, so there's a single source of truth instead of stores fighting over whose number is right.
* **Per-SKU stock sync** – stock quantity, stock status, backorder setting, low stock amount, and effective stock-management mode (including a variation that inherits management from its parent) are kept aligned.
* **Automatic capture, not manual export** – stock changes are picked up the moment WooCommerce itself changes them (order processing, refunds, cancellations, REST edits, CSV imports, or a third-party integration such as WooCommerce Square) – there's nothing to run or upload by hand.
* **Coalesced, asynchronous delivery** – every product touched in one request is combined into a single event and dispatched through Action Scheduler, so a busy order doesn't fire a flood of individual network requests against the customer-facing page load.
* **Version-fenced updates** – every authoritative item carries a monotonically increasing version number, so a delayed, duplicated, or replayed delivery can never overwrite already-current stock.
* **SKU rename tracking** – a stable internal identifier (GID) survives SKU changes on the Master, so renaming a SKU doesn't break the mapping to the matching Child product.
* **Strict checkout validation (optional)** – Child stores can query the Master's live stock during checkout and block a sale the Master reports as unavailable, with a configurable fallback strategy if the Master can't be reached.
* **Reconcile and audit tools** – compare or push stock state for the whole catalogue or selected SKUs, in resumable batches, with dry-run and differences-only modes.
* **Conflict Dashboard** – missing products, duplicate SKUs, GID mismatches, field mismatches, offline Children, and authentication errors collected into one report.
* **Backlog with bounded retries** – a Child or Master that's temporarily unreachable gets automatic retries with increasing delay; once the retry budget is exhausted the item is flagged for manual attention instead of retrying forever.
* **WP-CLI support** – run status checks, audits, reconciles, SKU pushes, and backlog management from the command line.
* **WordPress Site Health integration** – configuration and connection-health checks appear under Site Health → Status, with a non-secret diagnostic summary under Site Health → Info.
* **No third-party SaaS, no custom database tables** – built entirely on WooCommerce and WordPress primitives (REST API, options, product meta, transients, WooCommerce logging, Action Scheduler).

Note: this plugin is focused on inventory syncing only. It does not sync pricing, product content, images, or orders. It's a companion to, not a replacement for, Kitgenix MultiStore Sync for WooCommerce (which syncs product content) – the two are independent and can be used together or alone.

= How Stock Sync Works =

1. **Capture.** Stock changes are captured on each store through WooCommerce's own stock-state hooks (`woocommerce_product_set_stock`, `woocommerce_variation_set_stock`, `woocommerce_product_object_updated_props`, plus the order reduce/restore hooks) – the same hooks WooCommerce core, order processing, refunds, cancellations, the WooCommerce 11.0 failed-order stock restoration, REST product edits, CSV imports, and integrations such as WooCommerce Square all funnel through. There is no hard-coded per-integration list: anything that changes stock through WooCommerce's own CRUD is captured automatically.

2. **Coalesce.** Every product touched during a single request (e.g. every line item in one order) is collected into one in-memory set and sent as a single coalesced event when the request finishes, instead of one network request per hook firing. This avoids stock-trigger storms on large orders or bulk edits while still reflecting each product's true final state.

3. **Sync the resulting state, not the arithmetic.** The plugin never reimplements WooCommerce's stock math (reduce/restore/backorder logic). It always reads the current, already-correct WooCommerce stock state after WooCommerce has finished its own calculation, and synchronises that.

4. **Dispatch asynchronously.** Outbound sends (Child → Master, and Master → Children) are dispatched through Action Scheduler rather than blocking the customer-facing request that triggered them.

5. **Apply with version fencing.** Every authoritative item the Master sends carries a monotonically increasing per-product version number. A receiving store records the last version it applied and ignores any incoming item whose version is not newer – this makes a duplicate delivery, a retried send, a replayed request, or an event that arrives out of order structurally unable to overwrite already-current stock.

6. **Rebuild and fan out.** The Master applies incoming events locally, then always rebuilds and pushes authoritative stock state freshly read from its own database – never a delta – to every enabled Child except the one that just sent the event. This is what prevents a feedback loop when, for example, WooCommerce Square changes stock directly on the Master.

7. **Reconcile on demand.** The Master can run a Reconcile operation (all products or selected SKUs, optionally dry-run and/or differences-only) to compare or push stock state in resumable batches. A Conflict Dashboard collects missing products, duplicate SKUs, GID mismatches, field mismatches, offline Children, and authentication errors from Audit/Reconcile/scans into one report.

= Scheduling & Automation =

There is no polling schedule to configure: stock changes propagate as soon as WooCommerce itself changes stock, dispatched asynchronously via Action Scheduler. In addition:

* A recurring health-check ping runs roughly every 15 minutes to keep per-store connection status current.
* Failed deliveries retry automatically on an increasing delay (1, 5, 15, 60, and 360 minutes) for up to 8 attempts before being marked for manual attention.
* Reconcile runs in resumable batches via Action Scheduler in the admin UI, or synchronously (with a progress bar) when triggered through WP-CLI.

= SKU Rename Sync =

This plugin supports SKU renames by maintaining an internal, stable identifier (a "GID") stored as product meta: `_kitgenix_stock_sync_for_woocommerce_gid`.

When SKUs change on the Master, the plugin emits a `sku_rename` event using the GID so Child stores can map the update safely.

Tip: run **Reconcile** on the Master after initial setup. Reconcile establishes stable GIDs for products that don't already have one, which makes SKU rename sync reliable.

= Strict Checkout Validation (Child Stores) =

Child stores can optionally enable **Strict checkout validation**:

* During checkout, the Child queries the Master's stock for SKUs in the cart.
* If the Master reports the SKU is out of stock or insufficient (with backorders disabled), checkout is blocked – this never changes, in every mode below, because it is a definitive rejection from a reachable Master.
* If the Master **cannot be reached at all** (offline, timeout, error), the merchant-configurable **failure strategy** decides what happens:
  * **Fail open** (default): allow checkout.
  * **Fail closed**: block checkout until the Master can be verified again.
  * **Use last-known stock**: apply the most recent snapshot this Child has seen (from a prior successful validation or an applied push), only if it is fresher than a configurable number of minutes; otherwise falls back to fail-open.
* This plugin does not reserve stock or replace WooCommerce's own hold-stock/order-level concurrency handling for two truly concurrent checkouts – that remains WooCommerce's job. Strict checkout validation is an advisory, additional check against the Master's last-known-good snapshot.

= Exclusions =

You can exclude SKUs (comma or new line separated). Excluded SKUs are ignored for:

* outbound stock events (including the reconcile/coalescing engine)
* reconcile batches and the Conflict Dashboard
* strict checkout validation
* audit checks and the duplicate-SKU scan

= Tools & Diagnostics =

From the plugin admin screen:

* **Test Connection** – ping the configured store.
* **Reconcile (Master)** – all products or selected SKUs, optionally dry-run and/or differences-only, resumable in batches, with a summary and a child discrepancy report.
* **Manual SKU Sync (Master)** – push a specific set of SKUs to all children.
* **Audit Children (Master)** – query each child's local stock fields and compare against the Master.
* **Conflict Dashboard** – missing product, missing/duplicate SKU, GID mismatch, quantity/backorder/stock-status mismatch, child offline, and authentication-error rows collected from Audit/Reconcile/scans.
* **Connection Health** – per-store last inbound, last outbound, last success, last error, remote WooCommerce version, remote plugin version, and status.
* **Backlog** – reason, attempt count, next retry time, manual Retry/Discard (with confirmation) per item, and a bounded retry policy – a delivery that exhausts its retry budget is marked for manual attention rather than retried forever.
* **Event Log** – recent synchronisation events, with a diagnostic code reference table.
* **Secret rotation** – generate a new shared secret with a short overlap window so the old one keeps working while you update the other store.
* **WP-CLI** – `wp kitgenix-stock-sync` – see the developer reference below.

= Logging & Diagnostics =

Every sync attempt, success, and failure is recorded in two places: WooCommerce's own logging system (WooCommerce → Status → Logs, source `kitgenix-stock-sync-for-woocommerce`) and the plugin's own Event Log and Backlog under Kitgenix → Stock Sync → Logs. WordPress Site Health also gets configuration and connection-health checks under Status, plus a non-secret diagnostic summary under Info.

= Compatibility =

* Requires WooCommerce to be active; the plugin will deactivate itself on activation if WooCommerce is missing.
* Declares compatibility with WooCommerce High-Performance Order Storage (HPOS / custom order tables).
* Supports simple, variable, and variation products. Variations that inherit stock management from their parent are synced and applied faithfully, without being forced into independently managed stock.
* External/Affiliate and Grouped products are skipped for stock syncing, since WooCommerce does not stock-manage these product types.
* Works alongside integrations that update stock through WooCommerce's own APIs (such as WooCommerce Square) without any product-specific code.
* Multisite: settings and transients are scoped per site, and uninstall cleans up per site.

= Quick Start =

1. Install and activate the plugin on the Master and all Child stores.
2. Choose your role on each store:
   * Master: one store
   * Child: all other stores
3. On the Child store: set the Master connection (Master URL – must be HTTPS, Master Store ID, Shared Secret).
4. On the Master store: add each child (Child URL – must be HTTPS, Child Store ID, Shared Secret).
5. Use Tools → **Test Connection**.
6. On the Master store: run Tools → **Reconcile** to establish stable GIDs and push initial state.
7. Optionally enable Strict checkout validation on children, and choose a failure strategy under Configuration.

== Installation ==

1. Install via Plugins → Add New and search for "Kitgenix Stock Sync for WooCommerce", or upload the ZIP to `/wp-content/plugins/`.
2. Activate the plugin (WooCommerce required – the plugin will deactivate itself if WooCommerce isn't active).
3. Open the settings under the Kitgenix hub (Kitgenix → Stock Sync).
4. Decide which store is the Master and which are Children, then configure the connection on each: Master/Child URLs must use HTTPS, plus a Store ID and a shared secret.
5. Use Test Connection to confirm each pairing before relying on it.
6. Run a Reconcile on the Master to establish stable GIDs and push the current stock state to every Child.
7. Optional: enable Strict checkout validation on Child stores and choose a failure strategy.
8. Optional: server administrators can also drive setup checks and operations via WP-CLI (`wp kitgenix-stock-sync status`) – see the developer reference below.

The plugin cannot sync anything until at least one Master and one Child are configured and connected – there is no default or automatic pairing.

== External Services ==

This is a store-to-store sync plugin, so connecting to remote services is core to how it works. It talks to two kinds of external endpoint:

**1. Your own WooCommerce stores (Master and Child)**

The plugin makes signed REST API requests directly between the WordPress/WooCommerce sites you configure as Master and Child – it does not route data through any Kitgenix server or third-party service. Master and Child URLs must use HTTPS (a filter allows plain `http://` only for `localhost`/`.local`/`.test` hosts for local development).

* **What is sent:** product SKUs; stock state (quantity, stock status, backorders, low stock amount, effective stock-management mode); a per-product monotonic version counter and timestamp (see How Stock Sync Works); and, only via the `/ping` health-check route, the sending store's name and its WooCommerce/plugin version. Strict checkout validation additionally sends the SKUs currently in a Child's cart to the Master for a live stock check.
* **When it happens:** automatically, in real time, whenever WooCommerce itself changes a product's stock (an order, refund, cancellation, REST edit, CSV import, or a compatible integration such as WooCommerce Square) – dispatched asynchronously via Action Scheduler. There is no polling interval to configure. A recurring health-check ping additionally runs roughly every 15 minutes per configured store, and Reconcile/Audit/Manual SKU Sync send requests on demand when you trigger them.
* **Authentication:** every request carries `X-Kitgenix-Store-Id`, `X-Kitgenix-Timestamp`, `X-Kitgenix-Nonce`, and an `X-Kitgenix-Signature` HMAC-SHA256 header computed from a shared secret you configure on both sides – see Developer Reference below for the full scheme.
* **Caching:** a last-known-good stock snapshot per SKU is cached (transient, 24 hours) on Child stores solely to support the optional "use last-known stock" checkout failure strategy; a GID → product ID lookup is cached in the object cache (~1 hour) if a persistent object cache is active.

**2. WordPress.org Plugins API (admin screen only, not part of stock sync)**

The plugin includes a shared "Kitgenix hub" admin page (Kitgenix → top-level menu) listing other Kitgenix plugins. To populate install counts, ratings, and artwork for plugins listed there, it calls WordPress core's own `plugins_api()` function, which requests public plugin metadata from `api.wordpress.org`. No site data, settings, or stock information is sent in these requests – only the public plugin slugs of the Kitgenix plugins shown on that page.

* Cached via transients: `kitgenix_hub_wporg_active_installs_v1`, `kitgenix_hub_wporg_ratings_v1`, `kitgenix_hub_wporg_media_v1` (24 hours each).
* This lookup only runs when a logged-in administrator views the Kitgenix hub screen; it never runs on the front end and is unrelated to stock synchronisation.

No other third-party services, SaaS platforms, or analytics/tracking endpoints are contacted by this plugin.

== Developer Reference ==

Text domain:
`kitgenix-stock-sync-for-woocommerce`

Option key:
* `kitgenix_stock_sync_for_woocommerce_settings`

Option schema (high-level):
* `this_store_id`, `this_store_name`, `role`, `strict_checkout_validation`, `schema_version`
* `checkout_validation_failure_strategy` (`fail_open` | `fail_closed` | `stale_cache`), `checkout_stale_cache_minutes`
* `master` (child config): `url`, `store_id`, `secret`, `secret_previous`, `secret_previous_expires_at`, `health`
* `children` (master config): entries with `id`, `name`, `url`, `secret`, `secret_previous`, `secret_previous_expires_at`, `enabled`, `health`
* `exclusions.skus`
* `conflicts_report`: `{generated_at, items[]}` – each item has `type`, `sku`, `gid`, `child_id`, `child_name`, `master_value`, `child_value`, `detail`
* Diagnostics/admin UI state: `notices`, `event_log`, `backlog` (v2: `id`, `type`, `store_id`, `attempt`, `status`, `next_retry_at`, `payload`, `payload_meta`, `error`, `code`), `reconcile` (v2: `mode`, `dry_run`, `differences_only`, `selected_skus`, `processed`, `total_estimate`, `differences_found`, `pushed_count`, `started_at`, `finished_at`, `last_batch_at`), `health` (this store's own global health, retained alongside the newer per-store `master`/`children[].health`)

Product/variation meta keys:
* `_kitgenix_stock_sync_for_woocommerce_gid` – stable cross-store identity, survives SKU renames. Never regenerated once set.
* `_kitgenix_stock_sync_for_woocommerce_version` – Master-authoritative monotonic version counter, bumped on every authoritative capture.
* `_kitgenix_stock_sync_for_woocommerce_applied_version` – last version a receiving store actually applied; the staleness-fencing guard.

REST API routes (POST):
* `/wp-json/kitgenix-stock-sync/v1/ping` – returns `wc_version` and `plugin_version`
* `/wp-json/kitgenix-stock-sync/v1/event`
* `/wp-json/kitgenix-stock-sync/v1/stock` (master only; used by strict checkout validation)
* `/wp-json/kitgenix-stock-sync/v1/stock-state` (used by audit/reconcile/conflict comparisons; returns `gid` and effective `manage_stock`)

All four routes register with `permission_callback => __return_true` and perform authentication inside the callback via the signed-request headers below – this is intentional (a signed server-to-server webhook has no WordPress user/cookie/application-password to authenticate against) and not a missing-permission-callback oversight.

Authentication headers:
* `X-Kitgenix-Store-Id`
* `X-Kitgenix-Timestamp`
* `X-Kitgenix-Nonce`
* `X-Kitgenix-Signature`

Signatures:
* HMAC SHA-256 over: `timestamp + "\n" + nonce + "\n" + request_body`
* Timing-safe comparison (`hash_equals`)
* Timestamp skew allowed: 5 minutes
* Nonce replay protection stored via transients; a nonce is only consumed once a request is confirmed authentic, so a wrong signature can't burn a legitimate nonce slot
* Verification tries the current secret, then a rotated-out previous secret if it hasn't expired (see "Rotate Secret")
* Request bodies over 2MB are rejected before any HMAC work
* Repeated authentication failures from the same sender trigger a temporary lockout (20 failures / 10 minutes → 15 minute lockout)
* All authentication failure responses are a single generic 401 (detailed reason logged internally only) so a caller cannot enumerate which store IDs are configured

Action Scheduler hooks:
* (Action group: `kitgenix-stock-sync`)
* `kitgenix_stock_sync_for_woocommerce_process_event`
* `kitgenix_stock_sync_for_woocommerce_push_to_store` – used for both the initial async dispatch (attempt 1) and scheduled retries of the same delivery (attempt > 1)
* `kitgenix_stock_sync_for_woocommerce_send_to_master` – same dual purpose, Child → Master direction
* `kitgenix_stock_sync_for_woocommerce_reconcile_batch`
* `kitgenix_stock_sync_for_woocommerce_process_order_processing`
* `kitgenix_stock_sync_for_woocommerce_health_ping` – recurring, ~15 minutes

Admin capability:
* `manage_woocommerce`

Admin nonces:
* `kss_save_config`
* `kss_save_connection`
* `kss_save_children`
* `kss_test_connection`
* `kss_tools`
* `kss_conflicts`
* `kss_logs`

Filters:
* `kitgenix_stock_sync_for_woocommerce_parent_menu_slug` (change the parent menu slug; default: `kitgenix`)
* `kitgenix_stock_sync_for_woocommerce_secret_rotation_overlap` (seconds a rotated-out secret keeps working; default 86400, clamped to 1 hour–7 days)
* `kitgenix_stock_sync_for_woocommerce_allow_insecure_url` (return `true` to allow `http://` for `localhost`/`.local`/`.test` hosts only – never for a real remote host)

Transients (dynamic keys):
* `kitgenix_stock_sync_for_woocommerce_do_activation_redirect` (30 seconds)
* `kitgenix_stock_sync_for_woocommerce_kss_nonce_{md5(store_id|nonce)}` (nonce replay protection, 10 minutes)
* `kitgenix_stock_sync_for_woocommerce_kss_seen_{md5(event_id)}` (duplicate event detection, 24 hours)
* `kitgenix_stock_sync_for_woocommerce_kss_debounce_{md5(key)}` (debounce, ~2 seconds)
* `kitgenix_stock_sync_for_woocommerce_kss_old_sku_{post_id}` (SKU rename helper, 60 seconds)
* `kitgenix_stock_sync_for_woocommerce_kss_audit_result_{user_id}` (stores last audit result in wp-admin, 10 minutes)
* `kitgenix_stock_sync_for_woocommerce_kss_stockcache_{md5(sku)}` (last-known-good stock snapshot for the `stale_cache` checkout strategy, 24 hours; a per-item `cached_at` timestamp – not the transient TTL – enforces the merchant-configured max age)
* `kitgenix_stock_sync_for_woocommerce_kss_authfail_{md5(identity)}` / `..._kss_lockout_{md5(identity)}` (brute-force throttle)

Object cache (if persistent object cache is enabled):
* Cache group: `kitgenix_stock_sync`
* Key: `kitgenix_stock_sync_for_woocommerce_kss_gid_{md5(gid)}` (GID → product ID lookup, ~1 hour)

Internal action hooks (called directly, but can be hooked):
* `kitgenix_stock_sync_for_woocommerce_process_order_processing`

= WP-CLI =

`wp kitgenix-stock-sync status [--format=<table|json|yaml>]`
Role, store ID, and a per-store connection health summary.

`wp kitgenix-stock-sync audit --skus=<a,b,c> [--format=<table|json>]`
Master only. Compare Master vs. every configured Child for the given SKUs.

`wp kitgenix-stock-sync reconcile [--skus=<a,b,c>] [--dry-run] [--differences-only] [--batch=<n>]`
Master only. Runs to completion synchronously (with a progress bar) rather than relying on Action Scheduler's own timing, so the command reports a real result. Omit `--skus` to reconcile the whole catalogue.

`wp kitgenix-stock-sync sku push <sku>...`
Master only. Push specific SKUs to all children immediately.

`wp kitgenix-stock-sync backlog list|retry <id>|discard <id>|retry-all|discard-all [--yes]`
Inspect and manage the backlog. `discard`/`discard-all` prompt for confirmation unless `--yes` is passed.

`wp kitgenix-stock-sync conflicts [--rescan] [--format=<table|json>]`
Print the current Conflict Dashboard report; `--rescan` runs a fresh duplicate-SKU scan first (Master only).

== Frequently Asked Questions ==

= What is stock drift? =
Stock drift happens when you update stock on one WooCommerce store but another connected store still shows the old quantity. Left unresolved, it leads to oversells, customer frustration, and messy fulfilment. This plugin prevents stock drift by keeping one authoritative Master store's stock state in sync with all Child stores.

= Does this require WooCommerce? =
Yes. This plugin hooks WooCommerce product stock APIs and requires WooCommerce to be active.

= How often does stock sync run? =
There is no fixed interval or polling schedule. Stock changes are captured and sent the moment WooCommerce itself changes stock, dispatched asynchronously via Action Scheduler – typically within seconds. A separate recurring health-check ping runs roughly every 15 minutes purely to keep connection status current, not to sync stock.

= What format does the feed or import file need to be? =
There is no feed file or CSV import involved in the sync itself. This plugin does not read a feed URL or file – it connects directly, over authenticated REST API calls, to the other WooCommerce store(s) you configure as Master or Child. If you separately import stock into WooCommerce via CSV, that import triggers WooCommerce's own stock-update hooks, which this plugin then syncs like any other stock change.

= Can I sync from multiple sources? =
This plugin uses a single-Master, multiple-Child model: one authoritative Master store can push stock to any number of Child stores, but only one store may act as Master at a time. It does not support pulling stock from multiple independent Master sources into one store.

= How do I set up Master and Child stores? =
Install and activate the plugin on the Master store and every Child store, then choose a role for each: one store as Master, all others as Child. On each Child, enter the Master's connection details (HTTPS URL, Master Store ID, and Shared Secret); on the Master, add each Child the same way. Use Test Connection to verify the link, then run Reconcile on the Master to establish stable GIDs and push the initial stock state. See Quick Start above for the full walkthrough.

= Can I use more than one Master store? =
No. This plugin is designed for a single authoritative Master store and one or more children.

= Does it sync product data (title, price, images) or orders? =
No. It syncs stock state only.

= What stock fields are synced? =
Per SKU: stock quantity, stock status, backorders, and low stock amount.

= Is stock sync automatic, or do I need to trigger it manually? =
Sync is automatic. Stock changes are captured the moment WooCommerce itself changes stock (an order, a refund, a CSV import, a REST edit, or a third-party integration) and dispatched asynchronously through Action Scheduler – there's no schedule to configure and no file to upload. Manual tools (Reconcile, Manual SKU Sync) exist for initial setup and troubleshooting, not for day-to-day operation.

= Does this plugin support variable products and variations? =
Yes. Variations are synced by their own variation SKU, including variations that inherit stock management from their parent product.

= Are any product types excluded? =
External/Affiliate and Grouped products are skipped for stock syncing (these types are not stock-managed in WooCommerce).

= Does this plugin work with WooCommerce High-Performance Order Storage (HPOS)? =
Yes. The plugin declares compatibility with WooCommerce's custom order tables (HPOS) feature.

= What happens if a child store is offline? =
Failed pushes are recorded in the Backlog (with reason, attempt count, and next retry time) and retried automatically using WooCommerce's Action Scheduler with an increasing delay, up to a bounded number of attempts. Once that budget is exhausted, the item is marked for manual attention – it will not retry forever – and can be retried or discarded (with confirmation) from the Logs tab or WP-CLI.

= What happens if the Master is unreachable during checkout validation? =
Depends on the configured failure strategy (Configuration tab): fail-open (default, allows checkout), fail-closed (blocks checkout), or use last-known stock (applies a recent cached snapshot if it's fresh enough, otherwise falls back to fail-open). A definitive out-of-stock/insufficient response from a *reachable* Master always blocks checkout, regardless of this setting.

= Where can I view the sync logs? =
* WooCommerce → Status → Logs (source: `kitgenix-stock-sync-for-woocommerce`)
* Kitgenix → Stock Sync → Logs tab (Event Log + Backlog)

= How do I exclude specific SKUs from syncing? =
Add SKUs in Configuration → Exclusions. Excluded SKUs are ignored across all sync and tooling paths.

= Can a delayed, duplicated, or retried event corrupt my stock? =
No. Every authoritative item the Master sends carries a monotonically increasing per-product version number. A receiving store records the last version it applied and ignores anything that arrives with an equal or older version – so a delayed event, a retried send, or a replayed request can never overwrite already-current stock, regardless of delivery order. This is in addition to (not instead of) transport-level duplicate detection via each event's unique ID.

= How does this plugin handle WooCommerce 11.0's failed-order stock restoration? =
WooCommerce 11.0 now restores previously-reduced stock when an order moves to `failed` (not just `cancelled`/`pending`), guarded by WooCommerce's own "was stock actually reduced for this order" flag so it never double-adjusts. This plugin does not reimplement that arithmetic – it reads the resulting WooCommerce stock state after WooCommerce has made the change and synchronises that, so the 11.0 behaviour change is handled automatically with no plugin-specific logic required.

= Does this work with WooCommerce Square? =
Yes. When Square changes a product's stock on a WooCommerce store, it does so through WooCommerce's own stock-update APIs, which this plugin already listens to – no Square-specific code is needed. If that store is the Master, the change propagates to all Children. If it's a Child, the change is sent to the Master, which rebuilds the authoritative state and fans it out to every *other* Child (not back to the one that sent it), which is what prevents a feedback loop.

= Can I rotate a shared secret without breaking sync? =
Yes. Use "Rotate Secret" on the Stores tab (this is an admin-UI action; there is no WP-CLI equivalent). The old secret keeps working for a short overlap window (default 24 hours, filterable) so you can update the other store before it expires.

= Does this plugin support WP-CLI? =
Yes – see the developer reference above for the full command list (`wp kitgenix-stock-sync status|audit|reconcile|sku|backlog|conflicts`).

= Does this add any WordPress Site Health checks? =
Yes. Site Health → Status includes checks for role/connection configuration, connection health, backlog items needing attention, and Action Scheduler availability. Site Health → Info includes a non-secret diagnostic section (role, store ID, counts, timestamps) – shared secrets and store URLs' credentials are never included.

= Does this plugin connect to any third-party services? =
It connects directly between your own WordPress/WooCommerce sites over authenticated REST requests – no third-party sync service is involved. The only outside call is an optional, admin-only lookup of public WordPress.org plugin metadata (install counts, ratings, artwork) for the "Kitgenix hub" screen; see External Services above for full details.

== Screenshots ==

1. Status tab showing role, this store ID, inbound/outbound health timestamps, and per-store Connection Health.
2. Configuration tab for store name, Master/Child role selection, Strict checkout validation and failure strategy, and Exclusions.
3. Stores tab: Child → Master connection fields, or Master → manage configured Child stores, shared secrets, and secret rotation.
4. Tools tab: Test Connection, plus Master tools (Reconcile with dry-run/differences/selected-SKU modes, Manual SKU Sync, Audit Children) and audit results.
5. Conflicts tab: missing products, duplicate/mismatched SKUs, GID mismatches, and offline/authentication errors in one report.
6. Logs tab: Event Log and Backlog (reason, attempts, next retry, manual Retry/Discard), with actions to clear each.

== Security & Privacy ==

* No tracking cookies are added by this plugin.
* Admin actions are protected with nonces and capability checks.
* REST requests are authenticated using HMAC-SHA256 signatures (timing-safe comparison) with timestamp + nonce replay protection, a request-size cap, and a brute-force lockout on repeated authentication failures.
* Authentication failure responses are generic (a single 401) so a caller cannot enumerate configured store pairings; the specific reason is only recorded in this store's own Event Log.
* Master/Child URLs must use HTTPS (loopback/`.local`/`.test` hosts can opt into `http://` for local development only, via a filter).
* Shared secrets are stored in the plugin settings option (`kitgenix_stock_sync_for_woocommerce_settings`), the same way most WordPress integrations store API credentials. Treat secrets like passwords. Secrets are never written to the Event Log, the Backlog, or WooCommerce's own logs.
* Secrets can be rotated from the Stores tab with a short overlap window so both sides of a pairing can be updated without an outage.
* Site Health's diagnostic "Info" section deliberately excludes secrets and full store URLs – only booleans, counts, and timestamps.

== Uninstall ==

This plugin removes its settings and plugin-only transients on uninstall. It does not remove WooCommerce product/order meta or Action Scheduler records – this is intentional for the plugin's own product meta too (see below), so that reinstalling the plugin does not force GID regeneration or reset version fencing.

Removed on uninstall:
* Option: `kitgenix_stock_sync_for_woocommerce_settings`
* Site option: `kitgenix_stock_sync_for_woocommerce_settings`
* Transients by prefix: `kitgenix_stock_sync_for_woocommerce_` and `kss_` (covers dynamic keys such as nonce/seen/debounce/stock-cache/auth-throttle helpers)

Multisite:
* Removes per-site options and transients for each site.

If you want to remove all plugin data, you can also manually delete this product/variation meta:
* `_kitgenix_stock_sync_for_woocommerce_gid` (stable cross-store identity – only delete if you no longer need SKU rename mapping)
* `_kitgenix_stock_sync_for_woocommerce_version` and `_kitgenix_stock_sync_for_woocommerce_applied_version` (version-fencing counters – only delete alongside the GID, and only if you are not planning to reinstall and resume syncing with the same counterpart stores)

== Support Development ==

If this plugin saves you admin time or helps prevent oversells across multiple stores, you can support ongoing development with a one-off donation via PayPal:
https://www.paypal.com/donate/?hosted_button_id=KALF36K6JJ9B2

== Credits ==
Built with ❤︎ by @kitgenix - https://kitgenix.com

== Upgrade Notice ==

= 2.0.0 =
Adds versioned stock state so delayed/duplicate/replayed events can't corrupt stock, a Conflict Dashboard, a redesigned admin UI, WP-CLI, Site Health, and WooCommerce 11.0 compatibility. Migration is additive and idempotent – nothing existing is lost.

== Changelog ==

= 2.0.0 (25 August 2026) =

* New: Redesigned the entire admin settings screen around the shared Kitgenix design system – card-based sections, stat tiles, status badges, and toggle switches replace the old WordPress form tables and status tables.
* New: Added a sticky topbar with the Kitgenix brand, plugin name/version, tab navigation, a link to the Kitgenix Hub, and a mobile hamburger menu, replacing the previous nav-tab bar and static page header.
* New: Added an in-page settings search (topbar search panel with "/" and Cmd/Ctrl+K shortcuts) that filters cards and rows on the active tab as you type.
* New: Added a light/dark theme toggle for the admin UI; the chosen theme is remembered in the browser.
* New: Editing or removing a Child store on the Stores tab now opens an accessible modal dialog instead of an inline form and a JavaScript confirm() prompt.
* New: Event Log and Backlog tables on the Logs tab now support live search/filtering, colour-coded status badges, and empty-state messaging.
* New: Event Log and Backlog tables are now paginated (25 rows per page) so a busy store's history doesn't turn into one long scroll.
* New: Added a diagnostic code reference table to the Logs tab explaining what each recorded category means and whether it needs action.
* New: Added versioned stock state. Every authoritative item now carries a monotonically increasing per-product version; a receiving store records the last version it applied and ignores anything arriving with an equal or older version. Delayed, duplicated, retried, and out-of-order events can no longer overwrite already-current stock.
* New: Added an event envelope. Every sync event now has a stable unique ID plus origin store, creation time, delivery-attempt count, and a schema version – carried through retries rather than regenerated per attempt.
* New: Added a Conflict Dashboard tab covering missing products, missing or duplicate SKUs, GID mismatches, quantity/backorder/stock-status mismatches, Child stores being offline, and authentication errors.
* New: Conflict Dashboard results can now be populated by Audit, Reconcile dry-runs/difference checks, and a dedicated duplicate-SKU scan.
* New: Reconcile now supports selected SKUs instead of requiring all products to be processed.
* New: Added Reconcile dry-run mode so differences can be reviewed without changing stock.
* New: Added a push-differences-only mode to Reconcile.
* New: Added Reconcile run summaries showing processed products, detected differences, and pushed updates.
* New: Added Resume and Cancel controls for in-progress Reconcile runs.
* New: Added Backlog v2. Failed push/send attempts now retain their complete payload, stable event ID, status, and next-retry time so queued events can be resent correctly.
* New: Added per-item manual Retry controls to the Backlog.
* New: Added per-item Discard controls with confirmation to the Backlog.
* New: Added a bounded retry policy so deliveries that exhaust their retry budget are marked for manual attention instead of retrying indefinitely.
* New: Added per-store Connection Health tracking, replacing the previous single global set of health fields regardless of how many Child stores were configured.
* New: Connection Health now records last inbound communication, last outbound communication, last successful communication, last error, remote WooCommerce version, remote plugin version, and a derived connection status.
* New: Added a recurring health-check ping to keep per-store connection status current.
* New: Added merchant-configurable strict checkout validation failure strategies – fail open, fail closed, or use a last-known-good stock snapshot with a configurable maximum age.
* New: Added safe shared-secret rotation from the Stores tab, including a configurable overlap window so both sides of a store pairing can be updated without interrupting synchronisation.
* New: Added WP-CLI commands for server administrators: `wp kitgenix-stock-sync status`, `audit`, `reconcile`, `sku`, `backlog`, and `conflicts`.
* New: Added WordPress Site Health Status checks for role/connection configuration, connection health, backlog items requiring attention, and Action Scheduler availability.
* New: Added a non-secret Kitgenix Stock Sync diagnostic section to WordPress Site Health → Info.
* Improved: Authentication rejections between stores – including missing signatures, bad timestamps, clock drift, replayed requests, and invalid signatures – are now recorded in the Event Log, with clock drift and replayed requests flagged as routine rather than genuine security concerns.
* Improved: Sync failures between stores now distinguish temporary network or connection issues from genuine rejections such as bad configuration or hard errors in both the Event Log and the Status tab's health indicator.
* Improved: The Support tab is now three focused cards – a donate card with a collapsible monthly-amount picker, a "what your support funds" summary, and a single "get involved" panel for reviews, plugin links, and community – replacing the previous stack of donate/trust/community cards and sidebar.
* Improved: Audit Children results are now grouped into collapsible per-child cards instead of one long flat list.
* Improved: The Kitgenix Hub page now shares the same topbar and card design system as the plugin settings screens and shows installed/active plugin counts alongside each listing.
* Improved: Added the Image Optimizer plugin to the Kitgenix Hub's plugin list and updated the MultiStore Sync entry to the renamed "MultiStore for WooCommerce" plugin with its new slug and page.
* Improved: Refreshed the Kitgenix brand logo and favicon assets with new primary, dark, white, and black variants, including tagline versions, and updated the admin menu icon.
* Improved: Outbound stock changes are now coalesced per request, so every product touched by a single operation – such as one order's line items – is sent as a single event instead of generating one network request per WooCommerce hook.
* Improved: The Master's real-time push to Child stores now dispatches asynchronously through Action Scheduler instead of blocking the request that triggered the stock change, matching the behaviour already used by bulk tools.
* Improved: The Master no longer echoes an incoming event back to the Child store that sent it, eliminating an unnecessary round trip and preventing integrations such as WooCommerce Square from creating stock-sync feedback loops when driving a Child store's local inventory.
* Improved: Variations that inherit stock management from their parent using WooCommerce's "parent" stock-management mode are now synchronised and applied faithfully instead of being forced into independently managed stock.
* Improved: Strict checkout validation continues to block checkout in every failure strategy when a reachable Master definitively reports that an item is out of stock or has insufficient quantity.
* Fix: The settings sidebar now sticks flush beneath the topbar based on its real measured height instead of a fixed guessed offset, preventing overlap when the topbar wraps to two lines on narrower screens.
* Fix: Number inputs across the settings screens are no longer cramped to a fixed 50px width.
* Fix: Corrected order stock-reduction/restoration handling where the suppression flag was previously being set after WooCommerce had already fired the per-item stock hooks it was intended to suppress.
* Fix: Replaced the previous order-level suppression workaround with request-scoped stock-change coalescing, removing the dependency on WooCommerce hook ordering entirely.
* Security: Verified compatibility with WooCommerce 11.0 failed-order stock restoration. WooCommerce's `wc_maybe_increase_stock_levels()` now also runs on `woocommerce_order_status_failed`, guarded by WooCommerce's own stock-reduced flag, and Kitgenix Stock Sync correctly synchronises the resulting WooCommerce stock state without reimplementing order-status arithmetic.
* Security: Request bodies larger than 2MB are now rejected before HMAC verification.
* Security: Malformed JSON requests now return a clean error instead of falling through into further request processing.
* Security: Repeated authentication failures from the same sender now trigger a temporary lockout.
* Security: Authentication failure responses now use a single generic message so callers cannot enumerate configured store pairings.
* Security: Master and Child store URLs must now use HTTPS, with a filtered exception available for local development environments.
* Dev: Added a new shared `kitgenix-admin-components.js` script providing modals, collapsible cards, copy-to-clipboard controls, live table search, and toast-style save confirmations.
* Dev: Substantially expanded `kitgenix-admin-tabs.js` to drive the new topbar, mobile menu, dropdowns, and dynamic sticky-offset behaviour.
