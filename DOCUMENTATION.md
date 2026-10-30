# Kitgenix Stock Sync for WooCommerce

## Overview

**Kitgenix Stock Sync for WooCommerce** is a WordPress/WooCommerce plugin that synchronizes stock **between multiple independent WooCommerce stores** using a **Master/Child architecture**, communicating over **direct, signed REST API requests** between the sites the merchant configures. It is not a supplier/CSV/XML feed importer, not a marketplace (Amazon/eBay) connector, and not a within-store bundle/linked-SKU synchronizer 2.2.0 – 30 October 2026 the exact model, taken verbatim from the plugin header docblock (`kitgenix-stock-sync-for-woocommerce.php`, lines 1–25), is:

> **Description:** Sync WooCommerce stock between stores with secure master-child inventory updates and signed REST requests.

One store is designated **Master** (the authoritative inventory source); one or more other WooCommerce installs are designated **Child** stores. A Child detects a local stock-changing event, sends it to the Master; the Master re-derives the authoritative state from its own database and fans it out (pushes) to every other configured, enabled Child. There is no Kitgenix cloud relay 2.2.0 – 30 October 2026 every request goes directly from one merchant's WordPress install to another's, authenticated with an HMAC-SHA256 shared-secret signature.

| Property | Value |
|---|---|
| Plugin Name | Kitgenix Stock Sync for WooCommerce |
| Version (main file / `KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION`) | `2.2.0` |
| Version (readme.txt `Stable tag`) | `2.2.0` |
| Requires WordPress | 6.0+ (Tested up to 7.4) |
| Requires PHP | 8.2+ |
| Requires WooCommerce | 10.0+ (Tested up to 11.2.1), declared via `Requires Plugins: woocommerce` |
| License | GPLv3 or later |
| Text Domain | `kitgenix-stock-sync-for-woocommerce` |
| Domain Path | `/languages` |
| Author | Kitgenix (https://kitgenix.com/) |

Bootstrap requires WooCommerce to be active; if it is not, the plugin deactivates itself on activation with `wp_die()` (see Architecture below). The plugin declares HPOS (`custom_order_tables`) compatibility via `FeaturesUtil::declare_compatibility()` on `before_woocommerce_init`.

## Architecture

### File layout

```
kitgenix-stock-sync-for-woocommerce.php        Main plugin file: header, constants, Kitgenix Hub admin page, activation/deactivation, bootstrap
includes/
  class-kitgenix-stock-sync-for-woocommerce.php            Core singleton: wires all subsystems together, registers Action Scheduler hooks
  class-kitgenix-stock-sync-for-woocommerce-settings.php   Settings/option storage, event log, backlog, health, conflicts report, notices
  class-kitgenix-stock-sync-for-woocommerce-security.php   HMAC signing/verification, replay/nonce protection, auth throttling, URL policy
  class-kitgenix-stock-sync-for-woocommerce-rest.php        REST API route registration + authentication wrapper for all 4 routes
  class-kitgenix-stock-sync-for-woocommerce-sync.php        The sync engine: capture, coalesce, dispatch, apply, reconcile, audit, conflicts
  class-kitgenix-stock-sync-for-woocommerce-history.php     Stock History per SKU: capped per-product event history in postmeta
  class-kitgenix-stock-sync-for-woocommerce-identity.php    SKU Mapping Repair Tool: identity scan, sync holds, repairs
  class-kitgenix-stock-sync-for-woocommerce-failover.php    Failover Readiness Report: build, Markdown export, /ping summary
  class-kitgenix-stock-sync-for-woocommerce-admin.php       Admin settings screen (7 tabs), all POST handlers, all rendering
  class-kitgenix-stock-sync-for-woocommerce-site-health.php WordPress Site Health "Info" + "Status" integration
  class-kitgenix-stock-sync-for-woocommerce-cli.php         WP-CLI commands (`wp kitgenix-stock-sync ...`), loaded only under WP_CLI
uninstall.php                                   Deletes plugin option + plugin-only transients on uninstall
languages/kitgenix-stock-sync-for-woocommerce.pot   Translation template
readme.txt                                      WordPress.org readme (Description, FAQ, Developer Reference, current-release Changelog)
CHANGELOG.md                                    Full, human-maintained release history (readme.txt lists only the current release)
assets/
  css/admin.css                                 Plugin-specific layout on top of the shared design system
  css/kitgenix-admin-ui.css                      Shared Kitgenix design system (cards, tables, toggles, badges, tokens) 2.2.0 – 30 October 2026 shared across all Kitgenix plugins
  css/kitgenix-hub.css                           Styles for the cross-plugin "Kitgenix Hub" landing page
  js/admin.js                                    Legacy/orphaned tab-switch script (see Known Limitations 2.2.0 – 30 October 2026 dead code)
  js/kitgenix-admin-tabs.js                      Shared topbar/tabs/search/theme-toggle/mobile-menu controller
  js/kitgenix-admin-components.js                Shared modal/collapsible/copy-to-clipboard/table-search/pagination/toast controller
```

No `composer.json`/autoloader is present; classes are loaded with a fixed sequence of `require_once` calls.

### Bootstrap sequence

1. `kitgenix-stock-sync-for-woocommerce.php` is loaded by WordPress. It defines constants (`KITGENIX_STOCK_SYNC_FOR_WOOCOMMERCE_VERSION`, `_FILE`, `_DIR`, `_URL`), registers the shared **Kitgenix top-level admin menu** (`kitgenix_ensure_admin_menu()` on `admin_menu` priority 5 2.2.0 – 30 October 2026 only if no other Kitgenix plugin already registered it) and the **Kitgenix Hub** page renderer (`kitgenix_render_admin_page()`), which lists sibling Kitgenix plugins (Turnstile CAPTCHA, Custom Tabs, Document Manager, Order Tracking, PDF Invoicing, Stock Sync, Image Optimizer, MultiStore Sync, Affiliate Link Manager) with WordPress.org install-count/rating/media badges fetched via `plugins_api()` and cached in transients (`kitgenix_hub_wporg_active_installs_v1`, `_ratings_v1`, `_media_v1`, 24h TTL each).
2. It `require_once`s, in order: `class-kitgenix-stock-sync-for-woocommerce.php` (core), `-settings.php`, `-security.php`, `-rest.php`, `-sync.php`, `-admin.php`, `-site-health.php`, and 2.2.0 – 30 October 2026 only `if (defined('WP_CLI') && WP_CLI)` 2.2.0 – 30 October 2026 `-cli.php`.
3. `register_activation_hook()`: bails with `wp_die()` and self-deactivates (`deactivate_plugins()`) if `WooCommerce` class is missing; otherwise calls `Kitgenix_Stock_Sync_For_WooCommerce_Settings::ensure_defaults()` and sets a 30-second `kitgenix_stock_sync_for_woocommerce_do_activation_redirect` transient.
4. `admin_init`: if the activation-redirect transient is set (and not a bulk activation, detected via `$_GET['activate-multi']`), redirects to `admin.php?page=kitgenix-stock-sync-for-woocommerce`.
5. `register_deactivation_hook()`: only deletes the activation-redirect transient 2.2.0 – 30 October 2026 no other cleanup on deactivation (see Uninstall / Cleanup).
6. `plugin_action_links_{basename}` filter adds a "Settings" link on the Plugins screen.
7. `before_woocommerce_init`: declares `custom_order_tables` (HPOS) compatibility.
8. `plugins_loaded` (priority 20): if `WooCommerce` class exists, calls `Kitgenix_Stock_Sync_For_WooCommerce::instance()->init()` 2.2.0 – 30 October 2026 this is the real bootstrap.

### Core singleton 2.2.0 – 30 October 2026 `Kitgenix_Stock_Sync_For_WooCommerce::init()`

(`includes/class-kitgenix-stock-sync-for-woocommerce.php`)

1. Calls `Settings::ensure_defaults()` again (idempotent 2.2.0 – 30 October 2026 fills in any missing option keys/schema migrations).
2. Instantiates, in dependency order: `Settings` → `Security($settings)` → `Sync($settings, $security)` → `REST($settings, $security, $sync)` → `Admin($settings, $security, $sync)`. All are stored as public properties on the singleton (no dependency-injection container; manual constructor injection).
3. Calls `$rest->hooks()`, `$sync->hooks()`, `$admin->hooks()`.
4. Conditionally instantiates `Site_Health($settings)` and calls `->hooks()` if the class exists.
5. Conditionally calls `Kitgenix_Stock_Sync_For_WooCommerce_CLI::register($settings, $sync)` if running under WP-CLI.
6. Registers 7 Action Scheduler action callbacks (see Cron / Scheduled Events).
7. Calls two private methods that each schedule a **recurring** Action Scheduler action once (guarded by `as_next_scheduled_action()` so they are never double-scheduled): a 15-minute health ping, and a daily event-log pruning job.

### Key classes and responsibilities

| Class | File | Responsibility |
|---|---|---|
| `Kitgenix_Stock_Sync_For_WooCommerce` | `includes/class-kitgenix-stock-sync-for-woocommerce.php` | Singleton bootstrap/orchestrator; wires the other classes and Action Scheduler hooks. |
| `Kitgenix_Stock_Sync_For_WooCommerce_Settings` | `-settings.php` | Single-option persistence layer (`kitgenix_stock_sync_for_woocommerce_settings`); typed accessors for role, master/child config, health, event log, backlog, conflicts report, reconcile state, notices, diagnostic-code catalogue. |
| `Kitgenix_Stock_Sync_For_WooCommerce_Security` | `-security.php` | HMAC-SHA256 request signing/verification, nonce replay protection, auth-failure throttling/lockout, remote-URL HTTPS policy. |
| `Kitgenix_Stock_Sync_For_WooCommerce_REST` | `-rest.php` | Registers and authenticates the 4 REST routes; delegates business logic to `Sync`. |
| `Kitgenix_Stock_Sync_For_WooCommerce_Sync` | `-sync.php` | The sync engine itself: WooCommerce hook capture, per-request coalescing, event envelope construction, async dispatch via Action Scheduler, retry/backlog, apply-to-local-store with staleness fencing, reconcile, audit, conflict scanning, strict checkout validation, connection health pings. |
| `Kitgenix_Stock_Sync_For_WooCommerce_Admin` | `-admin.php` | Renders the 7-tab admin settings screen; handles every settings-page POST action (nonce-checked, capability-checked); enqueues admin assets. |
| `Kitgenix_Stock_Sync_For_WooCommerce_Site_Health` | `-site-health.php` | Adds a non-secret diagnostic block to Site Health → Info and 5 checks to Site Health → Status, including the 2.2.0 inventory-engine coexistence guard. |
| `Kitgenix_Stock_Sync_For_WooCommerce_CLI` | `-cli.php` | WP-CLI command surface (`wp kitgenix-stock-sync ...`), thin wrapper over `Sync`/`Settings`/`Identity`/`Failover`. |
| `Kitgenix_Stock_Sync_For_WooCommerce_History` | `-history.php` | Static helpers that append to / read a product's capped Stock History (`_kitgenix_stock_sync_for_woocommerce_history`). |
| `Kitgenix_Stock_Sync_For_WooCommerce_Identity` | `-identity.php` | SKU Mapping Repair Tool: scans for duplicate SKUs/GIDs and missing SKUs, maintains `sku_holds`, performs repairs (assign SKU, reissue GID, release). |
| `Kitgenix_Stock_Sync_For_WooCommerce_Failover` | `-failover.php` | Builds the Failover Readiness Report, renders it as Markdown, and supplies the compact readiness summary a Child returns from `/ping`. |

All classes are `final`, declared with `defined('ABSPATH') || exit;` guards, and use constructor-injected dependencies (no static service locators except the top-level singleton itself).

## Sync Engine

### Data source and matching model

There is **no external feed** (no CSV/XML/API import, no supplier feed, no marketplace polling). The "source of truth" is WooCommerce's own product stock fields on the **Master** store; the "targets" are the WooCommerce product stock fields on each **Child** store. Products are matched between stores primarily by a **plugin-generated Global ID (GID)** stored in product/variation postmeta (`_kitgenix_stock_sync_for_woocommerce_gid`), with **SKU** as the fallback/bootstrapping identifier:

- `find_product_id_by_gid()` performs an indexed `WP_Query` (`meta_key`/`meta_value` exact match) cached in the `kitgenix_stock_sync` object-cache group for 1 hour.
- If no GID match, `wc_get_product_id_by_sku()` is used (and, for `sku_rename` events, the *old* SKU is tried first via a temporary transient captured in `capture_old_sku_before_update()`).
- The Master generates a GID (`wp_generate_uuid4()`) for a product the first time it captures that product's authoritative state (`ensure_gid()`), persisted to postmeta with outbound-suppression active so the write doesn't re-trigger the capture hooks. GIDs are never regenerated once set, and they survive SKU renames 2.2.0 – 30 October 2026 this is the plugin's answer to "SKU can change but identity must not."
- External/Affiliate and Grouped product types are excluded from stock sync entirely (`is_stock_syncable_product()`) because WooCommerce does not stock-manage them.
- A variation's `manage_stock` can be the literal string `'parent'` (inherits from its parent) 2.2.0 – 30 October 2026 this is captured and applied faithfully rather than being coerced to a boolean (`effective_manage_stock()`), so inheriting variations stay inheriting on both ends.

### Fields synchronized

Per product/variation, keyed by SKU + GID: `stock_quantity`, `stock_status`, `backorders`, `low_stock_amount`, and effective `manage_stock` (bool or `'parent'`). No price, title, images, categories, or other catalogue data is touched 2.2.0 – 30 October 2026 this plugin is stock-only (a sibling plugin, "Kitgenix MultiStore Sync", covers the broader catalogue-sync use case per the readme).

### What triggers a sync (capture side)

`Sync::hooks()` registers WooCommerce hooks that mark a product "dirty" for the current request rather than sending immediately:

| WordPress/WooCommerce hook | Handler | Purpose |
|---|---|---|
| `woocommerce_product_set_stock` | `on_product_set_stock()` | Simple/variable product stock quantity changed |
| `woocommerce_variation_set_stock` | `on_product_set_stock()` | Variation stock quantity changed |
| `woocommerce_product_set_stock_status` | `on_product_set_stock_status()` | Stock status changed |
| `woocommerce_variation_set_stock_status` | `on_product_set_stock_status()` | Variation stock status changed |
| `woocommerce_product_object_updated_props` | `on_product_object_updated_props()` | Any of `stock_quantity`, `stock_status`, `manage_stock`, `backorders`, `low_stock_amount` changed via the product-object save path |
| `woocommerce_order_status_processing` | `on_order_status_processing()` | Order moved to Processing 2.2.0 – 30 October 2026 defence-in-depth tagging only (WooCommerce already reduced stock through the hooks above) |
| `woocommerce_restore_order_stock` | `on_restore_order_stock_after()` | Order stock restored (cancellation/failure) 2.2.0 – 30 October 2026 same defence-in-depth role, also covers WooCommerce 11.0's `failed`-order restoration |
| `woocommerce_reduce_order_stock` (priority 20) | `on_reduce_order_stock()` | **2.2.0, S1 fix.** Fires once per order, whatever status triggered the reduction, after WooCommerce has reduced stock for every line via its own atomic `wc_update_product_stock(..., 'decrease')`. Reads each item's `_reduced_stock` meta (the exact quantity WooCommerce itself just deducted) and queues a delta 2.2.0 – 30 October 2026 see "Order-driven deltas" below. |
| `woocommerce_restore_order_stock` (priority 5) | `on_capture_restore_order_stock_delta()` | **2.2.0, S1 fix.** Same action as the defence-in-depth row above, separate callback. WooCommerce clears `_reduced_stock` as part of the same pass, so this uses the order line's own quantity (what WooCommerce itself restores by) and queues an `increase` delta. |
| `updated_post_meta` | `on_updated_post_meta()` | Fallback: any of `_stock`, `_stock_status`, `_backorders`, `_manage_stock`, `_low_stock_amount` postmeta written directly (covers integrations that bypass the WC_Product API) |
| `update_post_metadata` (filter) | `capture_old_sku_before_update()` | Captures the pre-change `_sku` value into a 60s transient (Master only), so a rename can be detected |
| `updated_post_meta` | `on_sku_updated_post_meta()` | Detects `_sku` changes (Master only) and immediately sends a `sku_rename` event carrying old+new SKU and the GID |
| `woocommerce_after_checkout_validation` | `child_checkout_validation()` | Strict checkout validation (Child only, opt-in) |
| `woocommerce_store_api_checkout_update_order_from_request` | `child_blocks_checkout_validation()` | Strict checkout validation for the Checkout block (Store API `POST` only); throws a `RouteException` with the same errors |
| `shutdown` (priority 20) | `flush_dirty_products()` | Sends the coalesced event for every product touched this request |

Debouncing: `on_product_set_stock`/`on_product_set_stock_status` use a 2-second per-SKU transient debounce (`debounce_allow()`) to avoid redundant capture when WooCommerce fires both the granular hook and the props-updated hook for the same change.

### Order-driven deltas (2.2.0, fixes S1 2.2.0 – 30 October 2026 oversell under concurrent sales)

Before 2.2.0, every outbound item carried only the *absolute* `stock_quantity` read at flush time, and the Master applied it with a plain `set_stock_quantity()`. Two near-simultaneous sales (on the same or different stores) within one sync window could lose a decrement: Master at 10; Child A sells one and reports 9; Child B independently sells one and also reports 9; Master ends at 9 instead of 8.

`on_reduce_order_stock()` / `on_capture_restore_order_stock_delta()` queue a delta (`queue_delta()`) for every order line touched, keyed by an idempotency key of the shape `{this_store_id}:{order_id}:{item_id}:{increase|decrease}`, buffered in a static `$pending_deltas` array for the duration of the request (mirroring `$dirty`). Only for a line whose product/variation manages its own stock directly (`effective_manage_stock() === true`) 2.2.0 – 30 October 2026 a variation inheriting stock management from its parent (`'parent'`) actually has its *parent's* stock reduced, not the line item's own product object, so it is deliberately excluded from delta capture and continues to sync via the existing absolute-snapshot path for that product instead. `maybe_send_stock_state()` attaches every pending delta for a product onto that product's outbound item as a `deltas[]` array (`collect_pending_deltas()`) 2.2.0 – 30 October 2026 **alongside**, not instead of, the existing absolute `stock_quantity` field, so a peer still on event schema 2 (pre-2.2.0) ignores the unknown `deltas` key and applies the absolute value exactly as before.

On the receiving end, `apply_to_local_store()` applies each `deltas[]` entry atomically with `wc_update_product_stock($product, $qty, 'increase'|'decrease')` 2.2.0 – 30 October 2026 WooCommerce's own direct, row-locked stock write 2.2.0 – 30 October 2026 instead of overwriting `stock_quantity`. Each delta's idempotency key is checked against a 7-day transient (`is_duplicate_delta()` / `mark_delta_seen()`, well past the ~7.4-hour maximum retry window from `retry_window_seconds()`) before being applied, so a retried or duplicated delivery of the same order-line change is never double-applied. `stock_status` is then recomputed locally from the product's fresh post-delta quantity and backorder setting, rather than trusting the sender's (possibly now-stale) `stock_status` field. Absolute `stock_quantity` events are still used for everything that isn't an order-driven change 2.2.0 – 30 October 2026 a manual admin edit, an import, a reconcile, a `sku_rename` 2.2.0 – 30 October 2026 since there's no concurrent-writer risk to guard against there.

A **self-originated** event (a Master's own local order looped back through the same pipeline a Child uses, inside `master_process_event()`) skips re-applying its `deltas[]` (`apply_to_local_store($payload, $skip_deltas = true)`): WooCommerce already wrote that change to the Master's own DB before the capture hooks even ran, so re-applying the delta a second time would double-count it. Absolute fields are still safely re-applied in that case (setting a value to itself is a no-op).

This bumps `schema_version` to `3` (see below) but changes nothing about the Master's own authoritative rebroadcast to Children (`master_rebuild_authoritative_stock_payload()`), which continues to push a freshly-read absolute snapshot outward exactly as before 2.2.0 – 30 October 2026 the oversell race only exists on the many-children-to-one-master write path, not the one-master-to-many-children fan-out.

Because capture happens through WooCommerce's own hooks, **any** mechanism that changes stock through WooCommerce APIs is automatically observed 2.2.0 – 30 October 2026 a manual product edit, an order, a refund, a CSV import via WooCommerce's own importer, a REST API edit, or a third-party integration (the readme explicitly calls out WooCommerce Square as a verified case) 2.2.0 – 30 October 2026 without any Kitgenix-specific integration code.

### Coalescing

Rather than sending one network request per hook firing, `mark_dirty($product_id, $context)` accumulates touched product IDs (plus which contexts touched them) in a static `$dirty` array for the duration of the request (auto-flushing early if 500 products accumulate in one request). `flush_dirty_products()`, hooked to `shutdown` (priority 20), re-fetches each dirty product fresh (clearing product/meta caches first via `get_fresh_product()`) and sends **one** event per request containing every touched product's final state 2.2.0 – 30 October 2026 this runs for admin, front-end, REST, WP-CLI, and Action-Scheduler-run requests alike.

### Event envelope

Every event (`build_stock_state_payload()` / `ensure_event_id()`) carries:

```
event_id            UUID, stable across retries
origin_store_id      the originating store's this_store_id
created_at            unix timestamp
delivery_attempt      integer, incremented per retry
schema_version        currently 3 (2.2.0; was 2) 2.2.0 – 30 October 2026 bumped for the deltas[] item field below. A store on schema 2 ignores an unknown deltas[] key and applies the absolute stock_quantity field exactly as before, so a mixed-version network keeps syncing (with 2's oversell risk) until every store updates.
event_type            'stock_state' | 'sku_rename'
origin                'master_local' | 'child_local' | 'master_authoritative'
context                free-text tag, e.g. 'stock_set+order_processing', 'reconcile', 'manual_sku_sync'
items[]                per-product state (gid, sku, manage_stock, stock_quantity, stock_status, backorders, low_stock_amount[, version, updated_at][, deltas: [{key, op: 'increase'|'decrease', qty}]])
```

### Dispatch flow

- **Child → Master**: `send_event()` calls `enqueue_send_to_master()`, which schedules `kitgenix_stock_sync_for_woocommerce_send_to_master` as an **async** Action Scheduler action (`as_enqueue_async_action`, group `kitgenix-stock-sync`) 2.2.0 – 30 October 2026 or, if Action Scheduler isn't available, runs synchronously as a fallback. `child_send_event_to_master()` POSTs signed JSON to `{master_url}/wp-json/kitgenix-stock-sync/v1/event` (8s timeout).
- **Master receiving from a Child**: `master_process_event()` applies the incoming event to the Master's own DB (`apply_to_local_store()`), then 2.2.0 – 30 October 2026 for `stock_state` events 2.2.0 – 30 October 2026 **rebuilds the authoritative payload from the Master's own database** (`master_rebuild_authoritative_stock_payload()`, which bumps each product's version counter and re-derives every field) rather than blindly re-broadcasting what the Child sent. This is what makes the Master authoritative rather than a "last write wins" relay.
- **Master → Children (fan-out)**: for each enabled configured child (except the child that originated the event, to prevent feedback loops 2.2.0 – 30 October 2026 explicitly called out for integrations like WooCommerce Square), `enqueue_push_to_store()` schedules `kitgenix_stock_sync_for_woocommerce_push_to_store` async. `as_push_to_store()` POSTs signed JSON to `{child_url}/wp-json/kitgenix-stock-sync/v1/event`.
- **Master → Master itself**: when a Master's own products change, `send_event()` calls `enqueue_incoming_event(this_store_id, payload, true)` directly (no network hop needed) which feeds into `master_process_event()`.

### Staleness fencing (conflict resolution)

Each product carries two postmeta counters:

- `_kitgenix_stock_sync_for_woocommerce_version` (`META_VERSION`) 2.2.0 – 30 October 2026 a Master-authoritative monotonic counter, atomically incremented with a direct `UPDATE ... SET meta_value = meta_value + 1` SQL statement (`bump_and_get_version()`), bumped only when the Master captures a state as authoritative.
- `_kitgenix_stock_sync_for_woocommerce_applied_version` (`META_APPLIED_VERSION`) 2.2.0 – 30 October 2026 the last version a *receiving* store actually applied.

`is_stale_version($incoming, $applied)` returns true when `$incoming > 0 && $incoming <= $applied`; `apply_to_local_store()` uses this to **silently skip** (logged at `info` level, code `kss_stale_version`) any incoming item whose version is not strictly newer than what's already applied 2.2.0 – 30 October 2026 this is the mechanism that prevents a delayed, duplicated, retried, or out-of-order event from ever regressing stock, independent of transport-level delivery order. Items without a `version` field (older peer plugin versions, or non-authoritative captures) are applied unconditionally.

Separately, **duplicate-event detection** at the transport level: `is_duplicate_event()` remembers each `event_id` in a transient for 24 hours (`SEEN_EVENT_TTL`) and drops exact re-deliveries before they're even processed.

### Retry / backlog

Failed outbound HTTP calls (`wp_remote_post` returning a `WP_Error`, or a non-2xx response) are classified:

- `classify_wp_error()` → `http_timeout` or `http_connection_error` (by inspecting the error message for "timed out").
- `classify_http_status()` → `http_5xx` (treated as transient/recoverable) or `http_4xx_rejected` (treated as a genuine rejection).

On failure, a row is written/updated in the **backlog** (`Settings::add_backlog()`/`update_backlog_item()`, capped at 200 rows, oldest dropped first) storing the full payload (unless it exceeds 200,000 bytes JSON-encoded, in which case only `payload_meta` 2.2.0 – 30 October 2026 event type, context, item count 2.2.0 – 30 October 2026 is retained, and such a row can never be retried automatically). A retry is scheduled via `as_schedule_single_action()` at an increasing delay:

```
RETRY_DELAYS = [60, 300, 900, 3600, 21600]   // seconds: 1m, 5m, 15m, 1h, 6h
MAX_DELIVERY_ATTEMPTS = 8                     // beyond this, delay is null → retry budget exhausted
```

`retry_delay_for_attempt($attempt)` maps attempt number to a delay (clamped to the last configured delay once attempts exceed the array length), returning `null` once `$attempt > 8`, at which point the backlog row's `status` is set to `exhausted` and it stops retrying automatically 2.2.0 – 30 October 2026 requiring a manual **Retry** or **Discard** from the admin Logs tab or `wp kitgenix-stock-sync backlog`. A successful later delivery removes the corresponding backlog row.

### Reconcile (Master only, bulk operation)

`start_reconcile($options)` 2.2.0 – 30 October 2026 triggered from the admin Tools tab or `wp kitgenix-stock-sync reconcile` 2.2.0 – 30 October 2026 processes either **all** publish-status products/variations or a **selected SKU list**, in resumable batches (50–500 per batch, default 200) driven by the recurring `kitgenix_stock_sync_for_woocommerce_reconcile_batch` single-shot Action Scheduler action (re-scheduled 5 seconds after each batch via `as_reconcile_batch()`), persisting progress in the `reconcile` option sub-array (`running`, `page`, `mode`, `dry_run`, `differences_only`, `selected_skus`, `processed`, `total_estimate`, `differences_found`, `pushed_count`, `started_at`, `finished_at`, `last_batch_at`). Supports:

- **Dry-run**: compares Master vs. each Child's live state (via the `/stock-state` REST route) without pushing anything.
- **Differences-only**: only pushes to a Child when its reported state actually differs.
- **Normal**: pushes the authoritative batch to every enabled Child regardless.

Admin can **Resume** or **Cancel** an in-progress reconcile (`resume_reconcile()`, `cancel_reconcile()`).

### Audit (Master only, synchronous, SKU-scoped)

`master_audit_children_stock($skus)` synchronously queries each configured Child's live stock state for a small SKU set (via `/stock-state`) and classifies differences (`classify_conflicts()`) into: `missing_product`, `gid_mismatch`, `quantity_mismatch`, `backorder_mismatch`, `stock_status_mismatch`, `child_offline`, `auth_error`. Results feed both the Tools-tab "Audit Children" panel (cached per-admin-user in a 10-minute transient) and the Conflicts report.

### Conflict scanning

`scan_for_conflicts()` (Master only) runs `find_duplicate_skus()` 2.2.0 – 30 October 2026 a single indexed `GROUP BY` query over `wp_postmeta`/`wp_posts` finding SKUs used by more than one product/variation 2.2.0 – 30 October 2026 and appends `duplicate_sku` rows to the conflicts report. The conflicts report (`conflicts_report` option key: `{generated_at, items[]}`, capped at 500 items) is fed by audit, reconcile (dry-run/differences-only), and this scan.

### Strict checkout validation (Child only, opt-in)

`child_checkout_validation()`, hooked to `woocommerce_after_checkout_validation` (classic checkout) and, via `child_blocks_checkout_validation()`, to `woocommerce_store_api_checkout_update_order_from_request` (Checkout block), is a **live, synchronous** check distinct from the async stock-sync pipeline: it sums cart quantities per SKU, queries the Master's `/stock` REST route for live availability, and adds `WP_Error` entries (blocking checkout) for out-of-stock or insufficient-quantity items. Three configurable failure strategies govern behaviour when the Master **cannot be reached/verified** (a definitive out-of-stock from a *reachable* Master always blocks checkout regardless of strategy):

- `fail_open` (default) 2.2.0 – 30 October 2026 allow checkout.
- `fail_closed` 2.2.0 – 30 October 2026 block checkout with a generic "could not verify stock" error.
- `stale_cache` 2.2.0 – 30 October 2026 use a cached last-known-good snapshot per SKU (transient, 24h TTL, but gated by a merchant-configured max-age in minutes via `checkout_stale_cache_minutes`) if fresh enough; otherwise falls back to fail-open.

### Connection health pings

`as_health_ping()`, run every ~15 minutes (see Cron), calls `/ping` on the Master (from a Child) or on every enabled Child (from the Master), recording `last_ping`, `remote_wc_version`, `remote_plugin_version` into per-store health.

### Error handling summary

Every outbound failure is: (1) classified into a diagnostic code, (2) logged to both `wc_get_logger()` (source `kitgenix-stock-sync-for-woocommerce`) and the plugin's own in-option `event_log`, (3) recorded into per-store health (`last_outbound_error`, `last_error_message`, `last_error_code`), (4) surfaced as an admin notice, and (5) queued into the backlog with a scheduled retry (bounded). `Settings::is_recoverable_code()` distinguishes transient/expected friction (`kss_auth_replay`, `kss_auth_skew`, `http_timeout`, `http_connection_error`, `http_5xx`, `kss_stale_version`) 2.2.0 – 30 October 2026 logged as `warning` 2.2.0 – 30 October 2026 from genuine problems 2.2.0 – 30 October 2026 logged as `error`. `Settings::get_category_and_note($code)` provides a plain-English category/explanation for every diagnostic code, shown in the admin Logs tab.

## Stock History, SKU Mapping Repair and Failover Readiness (2.2.0)

### Stock History per SKU

`History::record()` appends an entry to the product's `_kitgenix_stock_sync_for_woocommerce_history` meta (oldest first, capped by `History::limit()`). Recording points in `Sync`:

| Type | Where | Store |
|---|---|---|
| `captured` | `maybe_send_stock_state()` | Child only – the raw local change, with the outgoing event_id |
| `authoritative` | `product_to_state(..., true, $history)` from `master_rebuild_authoritative_stock_payload()` (keeps the incoming event_id), reconcile batches (`reconcile` / `reconcile_dry_run`) and manual SKU sync | Master |
| `applied` | `apply_to_local_store()` after a successful save | Any receiving store; skipped on the Master when the event originated on the Master itself (self-apply no-op) |
| `sku_rename` | `apply_to_local_store()` | Any receiving store |
| `skipped_stale` | `apply_to_local_store()` when `is_stale_version()` skips an item | Any receiving store |

`Sync::stock_history_across_stores($sku)` combines this store's `local_stock_lookup(..., true)` with the same lookup on each counterpart (`fetch_child_stock_state(..., ['include_history' => true])`: every enabled Child from the Master, the Master from a Child), and merges all entries into one newest-first timeline. Stores on an older version return state without `history`; the UI says so. Admin: Tools → Stock History per SKU (nonce `kss_tools`, button `kss_stock_history`, result in transient `..._kss_history_result_{user_id}`). CLI: `wp kitgenix-stock-sync history <sku> [--local]`.

### SKU Mapping Repair Tool

`Identity::scan()` runs three bounded queries (200 rows each): `Sync::find_duplicate_skus()`, `Sync::find_duplicate_gids()`, and a `_manage_stock = 'yes'` / empty-`_sku` join for missing SKUs. Results go to `identity_report`. `duplicate_sku` and `duplicate_gid` items are *blocking*: their SKUs are written to `sku_holds` (keeping the original `since` for existing holds). Holds no longer present are released, logged, and – on the Master only – re-pushed with `master_push_skus()`. A Child does not send its own state on release, because that would overwrite the Master with possibly stale stock.

Held SKUs (`Settings::is_held_sku()`) are skipped by `maybe_send_stock_state()`, `apply_to_local_store()` (incoming SKU or old SKU), `master_rebuild_authoritative_stock_payload()`, reconcile batches and `master_push_skus()`, logging diagnostic code `kss_sku_held`. Audit and checkout validation are read-only and unaffected.

Repairs (nonce `kss_identity`), each followed by a full rescan:

- `set_sku()` – validates with `wc_product_has_unique_sku()` and saves inside `Sync::with_outbound_suppressed()`, so it is **not** broadcast as a `sku_rename`: with a duplicate SKU there is no way to know which Child product corresponds to the renamed one.
- `reissue_gid()` – Master: new UUID GID. Child: deletes the GID and applied version so the product is matched by SKU and adopts the Master's GID on the next update. Clears the GID lookup cache.
- `release()` – manual override; the next scan holds the SKU again if it is still ambiguous.

On the Master, `Identity::remote_issues()` lists `gid_mismatch` / `missing_product` rows from the latest Conflicts report. "Push Master GID" calls `master_push_skus()`. To make that push stick, `apply_to_local_store()` on a Child now resets the applied version when a product is matched by SKU but holds a different GID (it belonged to the old identity and would otherwise mark the update stale).

CLI: `wp kitgenix-stock-sync identity scan|list|holds|release <sku>`.

### Failover Readiness Report

`Failover::build()` returns findings (`ok` / `warning` / `critical`), a verdict (`ready` / `at_risk` / `not_ready`), plain-English "what happens" statements, snapshot ages, backlog stats and recovery steps. Inputs: role, master/child health (`derive_status()`), strict-checkout flag and failure strategy, `Sync::retry_schedule()` / `retry_window_seconds()` (attempts 2–8: 5m, 15m, 1h, 6h ×4 ≈ 25h), the backlog (pending, exhausted, oldest pending, closeness to the 200-row cap), Action Scheduler availability, sync holds, and `Sync::stock_snapshot_summary()` (reads the snapshot index and flags each snapshot usable if younger than `checkout_stale_cache_minutes`).

On the Master, each Child's row comes from `children[].health.remote_failover`, recorded by `ping_and_record_health()` from the `failover` field of that Child's `/ping` response (`Failover::ping_summary()`; non-secret). Children on older versions show as "Unknown".

Rendered as a card on the Status tab; downloadable as Markdown via `admin-post.php?action=kitgenix_stock_sync_failover_report` (nonce `kss_failover_report`, `manage_woocommerce`); CLI `wp kitgenix-stock-sync failover-report [--format=markdown|json]`.

Promotion support: `bump_and_get_version()` seeds a product's first authoritative version from its applied version + 1. On an original Master this is normally 1, as before. On a Child promoted to Master it continues the sequence the other Children already applied, so they don't ignore its updates as stale.

## Admin Interface

Single top-level submenu page under the shared **Kitgenix** parent menu (slug `kitgenix`, filterable via `kitgenix_stock_sync_for_woocommerce_parent_menu_slug`): **Kitgenix → Stock Sync**, registered by `Admin::admin_menu()` with `add_submenu_page($parent, 'Stock Sync', 'Stock Sync', 'manage_woocommerce', 'kitgenix-stock-sync-for-woocommerce', [$this, 'render_page'])`. The Kitgenix top-level menu item itself is registered separately by the main plugin file (`kitgenix_ensure_admin_menu()`) and is shared across all Kitgenix plugins (only the first-loaded one registers it).

The page is a single-page-app-style screen with a sticky topbar (brand, tab nav, in-page search, light/dark theme toggle, mobile hamburger) and **7 tabs**, selected via `?tab=` query arg / URL hash and client-side `kitgenix-admin-tabs.js` (falls back gracefully server-side: `hidden="hidden"` attribute on inactive panels).

### Tabs and fields

**Status** (`tab=status`, default) 2.2.0 – 30 October 2026 read-only dashboard: a sync-hold warning (if any SKUs are held), role, last inbound/outbound/error timestamps, last error message + diagnostic category, an explicit "Connection health" card with a per-store table (Store, Status, Last inbound, Last outbound success, Last error, Remote WC, Remote plugin), and the Failover Readiness Report card.

**Configuration** (`tab=configuration`) 2.2.0 – 30 October 2026 form nonce `kss_save_config`, submit button `kss_save_config`:

| Field name | Type | Option key | Notes |
|---|---|---|---|
| `this_store_name` | text | `this_store_name` | Friendly label shown in logs/audits |
| `role` | radio (`master`/`child`) | `role` | Master pushes; Child receives |
| `strict_checkout_validation` | checkbox | `strict_checkout_validation` | Default `true` |
| `checkout_validation_failure_strategy` | select | `checkout_validation_failure_strategy` | `fail_open` (default) / `fail_closed` / `stale_cache` |
| `checkout_stale_cache_minutes` | number (1–1440) | `checkout_stale_cache_minutes` | Default 30 |
| `excluded_skus` | textarea (newline/comma list) | `exclusions.skus` | SKUs ignored by all sync/tooling paths |

**Stores** (`tab=stores`) 2.2.0 – 30 October 2026 role-dependent:
- *Child role*: "Master Connection" form (nonce `kss_save_connection`): `master_url` (must pass `Security::is_acceptable_remote_url()` 2.2.0 – 30 October 2026 HTTPS required, or `http://` for `localhost`/`.local`/`.test` only if filter `kitgenix_stock_sync_for_woocommerce_allow_insecure_url` returns true), `master_store_id`, `master_secret` → `master.url`/`master.store_id`/`master.secret`. Separate button `kss_rotate_master_secret` (same nonce) regenerates the secret via `rotate_master_secret()`.
- *Master role*: "Add Child Store" form (nonce `kss_save_children`, button `kss_add_child`): `child_name`, `child_url`, `child_id` (paste the Child's own "This Store ID"), `child_secret` (blank = auto-generate 32-char password). "Configured Children" table with per-row edit modal: `edit_child_id` (hidden), `edit_child_name`, `edit_child_url`, `edit_child_secret`, `edit_child_enabled` (checkbox, auto-submits on toggle) → `kss_update_child`; `kss_rotate_child_secret` (`rotate_child_id` hidden field); `kss_remove_child` (`remove_id`).
- Both roles: "Test Connection" form (nonce `kss_test_connection`, button `kss_test`) → `Admin::test_connection()`, which pings the Master (Child role) or the first configured child (Master role) via `/ping`.

**Tools** (`tab=tools`, Master-only sections gated by `$this->settings->is_master()`) 2.2.0 – 30 October 2026 all forms share nonce `kss_tools`:
- Reconcile: `reconcile_mode` (radio `all`/`selected`), `reconcile_skus` (textarea, shown when `selected`), `reconcile_per_page` (number 50–500), `reconcile_dry_run` (checkbox), `reconcile_differences_only` (checkbox) → button `kss_reconcile`; plus `kss_reconcile_resume` and `kss_reconcile_cancel` buttons (shown while a reconcile is running).
- Stock History per SKU (both roles): `history_sku` (text) → button `kss_stock_history` → `Sync::stock_history_across_stores()`, result cached in a per-user transient.
- Manual SKU Sync: `manual_skus` (textarea) → button `kss_push_skus` → `Sync::master_push_skus()`.
- Audit Children: `audit_skus` (textarea) → button `kss_audit` → `Sync::master_audit_children_stock()`, result cached in a per-user transient and rendered inline (grouped into collapsible per-child cards).

**Conflicts** (`tab=conflicts`) 2.2.0 – 30 October 2026 SKU Mapping Repair card first (nonce `kss_identity`: `kss_identity_scan`, `kss_identity_set_sku` + `product_id`/`new_sku`, `kss_identity_new_gid` + `product_id`, `kss_identity_release` + `hold_sku`, Master-only `kss_identity_push` + `push_sku`), then the Conflict Dashboard 2.2.0 – 30 October 2026 nonce `kss_conflicts`: `kss_scan_conflicts` button (Master only) → `Sync::scan_for_conflicts()`; `kss_clear_conflicts` button (either role) → clears the conflicts report. Renders the conflicts report table with columns Type, SKU, Child (name only), Master, Child value, Detail (the underlying data model also carries `gid`/`child_id`, but those aren't rendered as visible columns).

**Logs** (`tab=logs`) 2.2.0 – 30 October 2026 nonce `kss_logs`: `kss_clear_event_log`, `kss_clear_backlog` buttons; `log_retention_days` select (`7`/`14`/`30`, default 30) → `kss_save_log_retention` button → `Settings::set_log_retention_days()`; per-backlog-row `kss_backlog_retry`/`kss_backlog_discard` buttons (each carries a hidden `backlog_id`) → `Sync::retry_backlog_item()`/`discard_backlog_item()`. Renders the Event Log table (time, level, message, diagnostic code + plain-English category/note, context) and the Backlog table (type, store, attempt, error, status, next retry time), both with client-side live search and 25-row pagination (via `kitgenix-admin-components.js`).

**Support** (`tab=support`) 2.2.0 – 30 October 2026 static content: donate link, "what your support funds," reviews/support-forum/documentation links, social links (sidebar, rendered by `render_sidebar()`).

### Option storage

All settings live in a **single serialized option**, `kitgenix_stock_sync_for_woocommerce_settings` (constant `Kitgenix_Stock_Sync_For_WooCommerce_Settings::OPTION_KEY`), `autoload = false`. `Settings::ensure_defaults()` (called on activation and on every `init()`) is additive/idempotent 2.2.0 – 30 October 2026 it fills in any missing keys without ever overwriting existing ones, tracked by an internal `schema_version` (currently `2`). Default shape:

```php
[
  'this_store_id'          => <uuid>,
  'this_store_name'        => <site name>,
  'role'                   => 'child',
  'strict_checkout_validation' => true,
  'master'                 => ['url'=>'', 'store_id'=>'', 'secret'=>'', 'health'=>[...]],
  'children'                => [],   // each: id, name, url, secret, enabled, health, [secret_previous, secret_previous_expires_at]
  'exclusions'              => ['skus' => []],
  'notices'                 => [],   // capped at 20, popped/cleared on render
  'event_log'                => [],   // capped at 250, plus age-based pruning
  'log_retention_days'       => 30,   // one of 7/14/30
  'backlog'                  => [],   // capped at 200
  'reconcile'                 => [ running, page, per_page, mode, dry_run, differences_only, selected_skus, processed, total_estimate, differences_found, pushed_count, started_at, finished_at, last_run, last_batch_at ],
  'checkout_validation_failure_strategy' => 'fail_open',
  'checkout_stale_cache_minutes'          => 30,
  'conflicts_report'          => ['generated_at'=>0, 'items'=>[]],  // capped at 500
  'sku_holds'                 => [],   // 2.2.0+: sku => {reason, since, detail}, capped at 500
  'identity_report'           => ['generated_at'=>0, 'items'=>[]],  // 2.2.0+: last SKU Mapping Repair scan, capped at 500
  'health'                     => [ last_inbound_event, last_outbound_success, last_outbound_error, last_error_message, last_error_code, last_reconcile_start, last_reconcile_complete ],
  'schema_version'             => 3,
]
```

## Hooks Reference

### WordPress/WooCommerce hooks the plugin hooks INTO

| Hook | Type | Registered in | Purpose |
|---|---|---|---|
| `admin_menu` (priority 5) | action | main file | Register shared Kitgenix top-level menu |
| `admin_menu` (priority 50) | action | `Admin::hooks()` | Register the Stock Sync submenu page |
| `admin_enqueue_scripts` (priority 5) | action | main file | Register shared `kitgenix-admin-ui.css` |
| `admin_enqueue_scripts` | action | main file | Enqueue Kitgenix Hub CSS on the hub page |
| `admin_enqueue_scripts` | action | `Admin::hooks()` | Enqueue this plugin's admin CSS/JS |
| `admin_notices` | action | `Admin::hooks()` | Render popped notices |
| `admin_init` | action | main file | Perform the once-only activation redirect |
| `before_woocommerce_init` | action | main file | Declare HPOS compatibility |
| `plugins_loaded` (priority 20) | action | main file | Boot the plugin core if WooCommerce is active |
| `plugin_action_links_{basename}` | filter | main file | Add "Settings" row-action link |
| `rest_api_init` | action | `REST::hooks()` | Register the 4 REST routes |
| `woocommerce_product_set_stock` | action | `Sync::hooks()` | Capture stock quantity change |
| `woocommerce_variation_set_stock` | action | `Sync::hooks()` | Capture variation stock quantity change |
| `woocommerce_product_set_stock_status` | action | `Sync::hooks()` | Capture stock status change |
| `woocommerce_variation_set_stock_status` | action | `Sync::hooks()` | Capture variation stock status change |
| `woocommerce_product_object_updated_props` | action | `Sync::hooks()` | Capture stock-related prop changes |
| `woocommerce_order_status_processing` | action | `Sync::hooks()` | Tag order line items dirty (defence-in-depth) |
| `woocommerce_restore_order_stock` | action | `Sync::hooks()` | Tag restored-stock line items dirty |
| `updated_post_meta` | action | `Sync::hooks()` | Fallback stock-meta capture; SKU-rename detection |
| `update_post_metadata` | filter | `Sync::hooks()` | Capture old SKU value before it's overwritten |
| `woocommerce_after_checkout_validation` | action | `Sync::hooks()` | Strict checkout validation (Child) |
| `woocommerce_store_api_checkout_update_order_from_request` | action | `Sync::hooks()` | Strict checkout validation on the Checkout block (Child) |
| `shutdown` (priority 20) | action | `Sync::hooks()` | Flush coalesced dirty-product event |
| `debug_information` | filter | `Site_Health::hooks()` | Add Site Health → Info section |
| `site_status_tests` | filter | `Site_Health::hooks()` | Add 5 Site Health → Status checks, including `kitgenix_stock_sync_coexistence` (2.2.0): flags "critical" if Kitgenix MultiStore Sync for WooCommerce is active with its own `sync_inventory` setting enabled, since both plugins would then write stock independently. Detected without a hard dependency 2.2.0 – 30 October 2026 only `defined('KITGENIX_MULTISTORE_SYNC_FOR_WOOCOMMERCE_VERSION')` and a direct `get_option('kitgenix_multistore_sync_for_woocommerce_settings')` read. |
| `register_activation_hook` | 2.2.0 – 30 October 2026 | main file | WooCommerce-required guard + defaults + redirect flag |
| `register_deactivation_hook` | 2.2.0 – 30 October 2026 | main file | Clear the redirect flag |

### Custom hooks the plugin fires (Action Scheduler actions, all in group `kitgenix-stock-sync`)

| Hook | Args | Handler | Purpose |
|---|---|---|---|
| `kitgenix_stock_sync_for_woocommerce_process_event` | `$sender_store_id, $payload` | `Sync::as_process_event()` | Process one inbound event (used for the local-Master short-circuit path) |
| `kitgenix_stock_sync_for_woocommerce_push_to_store` | `$store_id, $payload, $attempt, $backlog_id` | `Sync::as_push_to_store()` | Master → Child delivery (initial + retries) |
| `kitgenix_stock_sync_for_woocommerce_send_to_master` | `$payload, $attempt, $backlog_id` | `Sync::as_send_to_master()` | Child → Master delivery (initial + retries) |
| `kitgenix_stock_sync_for_woocommerce_reconcile_batch` | `$page, $per_page` | `Sync::as_reconcile_batch()` | One reconcile batch, self-rescheduling |
| `kitgenix_stock_sync_for_woocommerce_process_order_processing` | `$order_id` | `Sync::as_process_order_processing()` | Order-processing tagging |
| `kitgenix_stock_sync_for_woocommerce_health_ping` | none | `Sync::as_health_ping()` | Recurring connection-health ping (~15 min) |
| `kitgenix_stock_sync_for_woocommerce_prune_event_log` | none | `Settings::prune_event_log()` | Daily event-log age-based pruning |

### Filters exposed for site owners/developers

| Filter | Default | Purpose |
|---|---|---|
| `kitgenix_stock_sync_for_woocommerce_parent_menu_slug` | `kitgenix` | Change the shared top-level admin menu slug |
| `kitgenix_stock_sync_for_woocommerce_secret_rotation_overlap` | `86400` (seconds), clamped to [3600, 604800] | How long a rotated-out secret keeps working |
| `kitgenix_stock_sync_for_woocommerce_allow_insecure_url` | `false` | Allow `http://` for `localhost`/`.local`/`.test` hosts only |

## Shortcodes / Blocks / Widgets

**None.** This plugin registers no shortcodes, no Gutenberg blocks, and no widgets. It is purely an admin/backend + REST integration; it has no shortcode/block-registration calls anywhere in the codebase (confirmed by grep across all PHP files).

## Data Storage

No custom database tables. All persistent state lives in a single WordPress option plus WooCommerce product/variation postmeta plus transients (and, optionally, a persistent-object-cache group).

### Option

| Option key | Autoload | Purpose |
|---|---|---|
| `kitgenix_stock_sync_for_woocommerce_v2_senders` | `false` | Store IDs that have sent a valid route-bound (v2) signature; legacy-only requests from these are rejected. Deleted on uninstall. |
| `kitgenix_stock_sync_for_woocommerce_snapshot_index` | `false` | 2.2.0+: `{sku: cached_at}` index of last-known-good stock snapshots (capped at 1,000; entries older than the 24h snapshot TTL dropped). Buffered per request and written once on `shutdown`. Lets the Failover Readiness Report show snapshot ages, which transients alone cannot (they are not listable under a persistent object cache). Deleted on uninstall. |
| `kitgenix_stock_sync_for_woocommerce_settings` | `false` | Everything: role, store identity, master/child configs + secrets + per-store health, exclusions, notices, event log, log retention, backlog, reconcile state, checkout-validation settings, conflicts report, global health, schema version. See Admin Interface → Option storage above for the full shape. |

### Product/variation postmeta

| Meta key | Constant | Purpose |
|---|---|---|
| `_kitgenix_stock_sync_for_woocommerce_gid` | `Sync::META_GID` | Stable cross-store product identity (UUID), survives SKU renames, never regenerated once set |
| `_kitgenix_stock_sync_for_woocommerce_version` | `Sync::META_VERSION` | Master-authoritative monotonic version counter |
| `_kitgenix_stock_sync_for_woocommerce_applied_version` | `Sync::META_APPLIED_VERSION` | Last version this store actually applied (staleness fence) |
| `_kitgenix_stock_sync_for_woocommerce_history` | `History::META_KEY` | 2.2.0+: Stock History per SKU – the most recent stock events (default 20, filter `kitgenix_stock_sync_for_woocommerce_history_limit`). Diagnostic only; removed on uninstall. |

All four keys are excluded from WooCommerce's "Duplicate" product action (filter `woocommerce_duplicate_product_exclude_meta`, which WooCommerce also applies to duplicated variations), so a duplicate gets its own identity.

No usermeta keys are used by this plugin.

### Transients

| Transient key pattern | TTL | Purpose |
|---|---|---|
| `kitgenix_stock_sync_for_woocommerce_do_activation_redirect` | 30s | One-time post-activation redirect flag |
| `kitgenix_stock_sync_for_woocommerce_kss_nonce_{md5(store_id\|nonce)}` | 10 min | Nonce replay protection |
| `kitgenix_stock_sync_for_woocommerce_kss_seen_{md5(event_id)}` | 24h | Duplicate-event detection |
| `kitgenix_stock_sync_for_woocommerce_kss_debounce_{md5(key)}` | 2s | Per-SKU capture debounce |
| `kitgenix_stock_sync_for_woocommerce_kss_old_sku_{post_id}` | 60s | Pre-rename SKU capture |
| `kitgenix_stock_sync_for_woocommerce_kss_audit_result_{user_id}` | 10 min | Last audit result, per admin user |
| `kitgenix_stock_sync_for_woocommerce_kss_stockcache_{md5(sku)}` | 24h (age-gated by `checkout_stale_cache_minutes`) | Last-known-good stock snapshot for `stale_cache` checkout strategy |
| `kitgenix_stock_sync_for_woocommerce_kss_authfail_{md5(identity)}` | 10 min | Auth-failure counter (throttle) |
| `kitgenix_stock_sync_for_woocommerce_kss_lockout_{md5(identity)}` | 15 min | Auth lockout flag |
| `kitgenix_hub_wporg_active_installs_v1` / `_ratings_v1` / `_media_v1` | 24h | WordPress.org plugin metadata cache for the Kitgenix Hub page |

### Object cache

Group `kitgenix_stock_sync`, key `kitgenix_stock_sync_for_woocommerce_kss_gid_{md5(gid)}` (~1 hour) 2.2.0 – 30 October 2026 GID-to-product-ID lookup, only effective if a persistent object cache is active (falls back to WP_Query on cache miss/default in-memory cache).

### Sync logs/history storage (in-option, not a DB table)

- **Event Log** (`event_log`): array of `{time, level, message, context[], code}`, capped at 250 entries **and** pruned by age (`log_retention_days`: 7/14/30, default 30) both on write (`add_event_log()`) and daily via the scheduled `prune_event_log` action. This is the plugin's own structured log, separate from (but written alongside) WooCommerce's own logger (`wc_get_logger()`, source `kitgenix-stock-sync-for-woocommerce`, viewable at WooCommerce → Status → Logs).
- **Backlog** (`backlog`): array of `{id, time, type, store_id, attempt, error, code, status, next_retry_at, payload, payload_meta}`, capped at 200 entries 2.2.0 – 30 October 2026 this is the record of failed/pending deliveries, retryable/discardable individually.
- **Conflicts report** (`conflicts_report`): `{generated_at, items[]}`, capped at 500 items 2.2.0 – 30 October 2026 the output of audit/reconcile/scan operations.
- **Health** (`health`, `master.health`, `children[].health`): timestamps and last-error info per connection, not a time series 2.2.0 – 30 October 2026 always the latest snapshot only.

## AJAX / REST Endpoints

**No `wp_ajax_*`/admin-ajax.php handlers exist.** All admin interactivity is plain HTML form POST-back (PRG pattern) to the settings page itself, not `admin-ajax.php`.

### REST API routes

Namespace: `kitgenix-stock-sync/v1`. All routes registered in `REST::register_routes()` on `rest_api_init`. All four use `permission_callback => [$this, 'authenticate']` 2.2.0 – 30 October 2026 these are server-to-server signed webhooks with no WordPress user/cookie/application-password context, so `REST::authenticate()` performs HMAC verification (plus lockout/size checks) as the permission callback, and WordPress rejects an unauthenticated request before any route callback runs (2.2.0+; previously `__return_true` with the check inside each callback).

| Route | Method | Callback | Auth | Purpose | Response shape |
|---|---|---|---|---|---|
| `/wp-json/kitgenix-stock-sync/v1/ping` | POST | `REST::ping()` | Signed | Connectivity/version check; on a Child (2.2.0+) also returns its failover readiness summary, which the Master stores as `children[].health.remote_failover` | `{ok, store_id, store_name, role, time, wc_version, plugin_version, failover}` |
| `/wp-json/kitgenix-stock-sync/v1/event` | POST | `REST::event()` | Signed | Deliver a stock/SKU-rename event | `{ok, processed}` |
| `/wp-json/kitgenix-stock-sync/v1/stock` | POST | `REST::stock_query()` | Signed, Master-only (403 otherwise) | Live stock lookup by SKU for strict checkout validation | `{ok, items: {sku: {exists, stock_quantity, stock_status, backorders}}}` |
| `/wp-json/kitgenix-stock-sync/v1/stock-state` | POST | `REST::stock_state_query()` | Signed | Full local stock-state lookup by SKU for audit/reconcile/conflict comparisons; `include_history: true` (2.2.0+) adds `version`, `applied_version`, `history` for Stock History per SKU | `{ok, items: {sku: {exists, gid, stock_quantity, stock_status, backorders, low_stock_amount, manage_stock[, version, applied_version, history]}}}` |

Every route requires request body `{items: [{sku: "..."}]}` (for `/stock` and `/stock-state`) or the event envelope (for `/event`) or `{ping: true}` (for `/ping`), plus the authentication headers below.

### Authentication scheme (`Security` class)

- Headers: `X-Kitgenix-Store-Id`, `X-Kitgenix-Timestamp`, `X-Kitgenix-Nonce`, `X-Kitgenix-Signature` (legacy v1) and `X-Kitgenix-Signature-V2` (2.2.0+).
- v2 signature: `hash_hmac('sha256', "v2\n{METHOD}\n{route}\n{timestamp}\n{nonce}\n" . hash('sha256', $body), $secret)`, where `{route}` is `WP_REST_Request::get_route()` (e.g. `/kitgenix-stock-sync/v1/event`), derived on the sender from the endpoint URL by `Security::route_from_endpoint()`. `Security::sign_headers( $secret, $store_id, $body, $endpoint )` emits both signatures.
- Legacy v1 signature: `hash_hmac('sha256', "{timestamp}\n{nonce}\n{body}", $secret)`. Only checked when no v2 header is sent.
- Downgrade protection: after a sender's first valid v2 request its store ID is recorded in the `kitgenix_stock_sync_for_woocommerce_v2_senders` option, and from then on requests from that sender without a v2 signature are rejected. Stores still on an older version keep syncing with v1 until they are updated.
- Comparison is timing-safe (`hash_equals()`).
- Timestamp skew allowed: 5 minutes (`kss_auth_skew` if exceeded).
- Nonce replay protection via 10-minute transient, consumed only after successful verification.
- Verified against every currently-valid secret for the sender: current secret + a not-yet-expired rotated-out previous secret (secret rotation overlap).
- Request bodies over 2MB rejected before any HMAC computation (`kss_payload_too_large`).
- Brute-force throttle: 20 failures / 10-minute window → 15-minute lockout, keyed on **both** remote IP and claimed store ID (so an attacker can't dodge the lockout by rotating the store-ID header).
- All rejection reasons are logged in detail internally, but every response to the caller is a single generic `401 {code: 'kss_auth_failed', message: 'Authentication failed'}` (or `429`/`413`/`400` for rate-limit/size/JSON errors) 2.2.0 – 30 October 2026 this deliberately prevents an unauthenticated caller from enumerating configured store pairings.

## Cron / Scheduled Events

This plugin uses **WooCommerce Action Scheduler exclusively** 2.2.0 – 30 October 2026 there is no native `wp_schedule_event()`/`wp_cron` usage anywhere in the codebase (confirmed by grep).

| Action hook | Schedule | Registered by | Purpose |
|---|---|---|---|
| `kitgenix_stock_sync_for_woocommerce_health_ping` | Recurring, every 15 minutes (`as_schedule_recurring_action`, first run `time()+300`) | `Kitgenix_Stock_Sync_For_WooCommerce::maybe_schedule_health_ping()`, guarded by `as_next_scheduled_action()` so it's scheduled once | Ping Master (from Child) or all enabled Children (from Master) |
| `kitgenix_stock_sync_for_woocommerce_prune_event_log` | Recurring, every 24 hours (`DAY_IN_SECONDS`, first run `time()+600`) | `maybe_schedule_event_log_pruning()`, same once-only guard | Age-based event-log cleanup |
| `kitgenix_stock_sync_for_woocommerce_push_to_store` | Async (immediate) + single-shot scheduled retries at `now()+delay` | `Sync::enqueue_push_to_store()` / `schedule_retry_push_to_store()` | Master → Child event delivery |
| `kitgenix_stock_sync_for_woocommerce_send_to_master` | Async (immediate) + single-shot scheduled retries at `now()+delay` | `Sync::enqueue_send_to_master()` / `schedule_retry_send_to_master()` | Child → Master event delivery |
| `kitgenix_stock_sync_for_woocommerce_reconcile_batch` | Single-shot, self-rescheduling `now()+5s` per batch | `Sync::start_reconcile()` / `as_reconcile_batch()` | Bulk reconcile, one batch per invocation |

All scheduled/async actions use the Action Scheduler group `kitgenix-stock-sync`. If Action Scheduler is unavailable (`as_schedule_single_action`/`as_enqueue_async_action` don't exist), the plugin falls back to running the equivalent method **synchronously in-request** rather than failing silently 2.2.0 – 30 October 2026 but the recurring health-ping and log-pruning jobs simply never get scheduled in that case (Site Health flags this via the `kitgenix_stock_sync_action_scheduler` "critical" check).

## Frontend Behavior

There is essentially no public/front-end-facing output from this plugin. The only front-end effect is **checkout blocking**: `Sync::child_checkout_validation()`, hooked to `woocommerce_after_checkout_validation` on a Child store with "Strict checkout validation" enabled, can add `WP_Error` entries that WooCommerce surfaces as standard checkout validation error notices (e.g. `"%s" is out of stock.` / `Insufficient stock for "%1$s". Available: %2$d.` / a "could not verify stock" message for `fail_closed`). No shortcodes, widgets, blocks, scripts, or styles are enqueued on the front end.

## Assets

| File | Type | Purpose | Enqueued on |
|---|---|---|---|
| `assets/css/admin.css` | CSS | Plugin-specific layout on top of the shared design system (panel-stack spacing, support-tab list, reduced-motion override) | Only the plugin's own settings page (`Admin::enqueue_assets()`, gated on `?page=kitgenix-stock-sync-for-woocommerce` or the registered page hook, and `current_user_can('manage_woocommerce')`) |
| `assets/css/kitgenix-admin-ui.css` | CSS | Shared cross-plugin Kitgenix design system: color/spacing/radius tokens, cards, tables, toggles, badges, stat tiles, modals, topbar. Explicitly documented as "keep identical between plugins" | Registered on every admin page (`admin_enqueue_scripts` priority 5 in the main file) but only *enqueued* where a Kitgenix screen actually uses it |
| `assets/css/kitgenix-hub.css` | CSS | Styles for the shared "Kitgenix Hub" plugin-discovery landing page | Only `admin.php?page=kitgenix` (or hook suffix `toplevel_page_kitgenix`) |
| `assets/js/admin.js` | JS | Legacy tab-switcher targeting `data-kitgenix-stock-sync-for-woocommerce-tab`/`-tab-panel` attributes | Enqueued on the plugin's settings page, but see Known Limitations 2.2.0 – 30 October 2026 dead code, no matching markup |
| `assets/js/kitgenix-admin-tabs.js` | JS | Shared topbar controller: tab switching (`data-kitgenix-tab`/`data-kitgenix-tab-panel`), URL hash/query sync, in-page search/filter, mobile hamburger menu, dropdowns, search-panel toggle, light/dark theme toggle (persisted to `localStorage['kitgenixAdminTheme']`), sticky-topbar offset measurement, WP admin-notice repositioning | Plugin's settings page |
| `assets/js/kitgenix-admin-components.js` | JS | Shared component controller: toast notifications, accessible modal dialogs (focus trap, `Esc` to close, return-focus), collapsible/accordion cards, copy-to-clipboard, live table search/filter, lightweight client-side table pagination | Plugin's settings page |

CSS/JS are enqueued with a cache-busting version derived from `filemtime()` of the file (falling back to the plugin version constant) 2.2.0 – 30 October 2026 not a manually bumped version string.

## Internationalization

- Text domain: `kitgenix-stock-sync-for-woocommerce`; Domain Path: `/languages`.
- Translation template: `languages/kitgenix-stock-sync-for-woocommerce.pot` (generated by WP-CLI 2.12.0, `Project-Id-Version: ... 2.2.0`, `POT-Creation-Date: 2026-10-22`).
- No explicit `load_plugin_textdomain()` call was found in the codebase 2.2.0 – 30 October 2026 as of WordPress 4.6+, translations for plugins hosted on WordPress.org are loaded automatically from translate.wordpress.org without an explicit call, which is consistent with this being a `wordpress.org/plugins/...` plugin (per the header's Plugin URI).
- All user-facing strings use `__()`/`_e()`/`esc_html__()`/`esc_attr__()` with the correct text domain throughout; translator comments (`/* translators: ... */`) are present for strings with placeholders (e.g. the install-count and rating badges in the Hub, the SKU-based checkout error messages).

## Security

- **Capability checks**: every admin entry point requires `manage_woocommerce` (`Admin::render_page()`, `admin_menu()`'s `add_submenu_page`, `enqueue_assets()`, `admin_notices()`); the shared Kitgenix Hub page requires `manage_options` or `manage_woocommerce`. WP-CLI commands run with whatever `wp` shell permission the operator has (no additional `current_user_can` inside CLI, appropriate for a server-admin channel).
- **Nonces**: every admin POST handler is wrapped in `isset($_POST['action_field']) && check_admin_referer('nonce_action')` before any mutation 2.2.0 – 30 October 2026 nonce actions are `kss_save_config`, `kss_save_connection`, `kss_save_children`, `kss_test_connection`, `kss_tools`, `kss_conflicts`, `kss_logs` (one nonce action can guard several distinct submit buttons on the same tab). Sensitive delete-like actions in the UI additionally use a JS `confirm()` (secret rotation) 2.2.0 – 30 October 2026 WP-CLI's `backlog discard`/`discard-all` use `\WP_CLI::confirm()` unless `--yes` is passed.
- **Input handling**: `Admin` funnels all `$_POST` reads through typed helpers (`read_post_text()`, `read_post_url()` 2.2.0 – 30 October 2026 `esc_url_raw()`, `read_post_int()` 2.2.0 – 30 October 2026 `absint()`, `read_post_bool()` 2.2.0 – 30 October 2026 presence-only, `read_post_sku_list()` 2.2.0 – 30 October 2026 split + `sanitize_text_field()` per item) that unslash and sanitize centrally; direct `$_POST`/`$_GET` superglobal reads elsewhere are `sanitize_key()`/`wp_unslash()`'d and carry `phpcs:ignore` annotations explaining why nonce verification is deferred to the caller (e.g. reading the `page`/`tab` query arg for asset-enqueue gating, which is not a state-changing action).
- **Output escaping**: consistent `esc_html()`/`esc_attr()`/`esc_url()`/`esc_html__()`/`esc_attr__()`/`esc_textarea()`/`wp_kses()` throughout the rendered admin HTML; the only unescaped `echo` calls are of statically-defined, developer-authored SVG icon markup (`Admin::icon()`) or the Hub page's `wp_kses()`-filtered dynamic HTML 2.2.0 – 30 October 2026 never raw user input.
- **Transport security**: HMAC-SHA256 request signing with `hash_equals()` timing-safe comparison (see AJAX/REST section above); HTTPS-only Master/Child URLs enforced both at save time (`Security::is_acceptable_remote_url()`) and structurally (only `https://.../wp-json/...` endpoints are ever constructed); secrets are never written to the event log, backlog, or WooCommerce's own logger; Site Health's "Info" diagnostic block deliberately excludes secrets and full URLs (booleans/counts/timestamps only).
- **Rate limiting**: see Security class throttle/lockout described above (20 failures/10 min → 15 min lockout, keyed on IP + claimed store ID).
- **Generic auth errors**: every authentication failure returns an identical, generic 401 response regardless of the actual cause, specifically to prevent enumeration of configured store IDs/pairings by an unauthenticated caller.

## Uninstall / Cleanup

**Deactivation** (`register_deactivation_hook`, main file): only deletes the `kitgenix_stock_sync_for_woocommerce_do_activation_redirect` transient. No settings, postmeta, scheduled actions, or logs are touched 2.2.0 – 30 October 2026 the plugin is designed to be safely deactivated/reactivated without losing configuration or sync state.

**Uninstall** (`uninstall.php`, guarded by `defined('WP_UNINSTALL_PLUGIN')`):
- Deletes the option `kitgenix_stock_sync_for_woocommerce_settings` (both `delete_option()` and `delete_site_option()`).
- Deletes the `kitgenix_stock_sync_for_woocommerce_snapshot_index` option and all `_kitgenix_stock_sync_for_woocommerce_history` product meta (`delete_post_meta_by_key()`; Stock History is diagnostic only).
- Deletes all transients (and their `_transient_timeout_` rows) matching the prefixes `kitgenix_stock_sync_for_woocommerce_` and `kss_` via a direct `$wpdb` `LIKE` query on `wp_options`, then routes each through `delete_transient()` for proper cache invalidation 2.2.0 – 30 October 2026 this catches dynamic per-key transients (nonce/seen/debounce/old-sku/audit-result/stock-cache/auth-throttle) without needing to know every exact key.
- On multisite, repeats the option+transient cleanup for every site via `get_sites()` + `switch_to_blog()`/`restore_current_blog()`.
- **Deliberately does NOT remove**: WooCommerce product/order meta in general, Action Scheduler's own history tables, or 2.2.0 – 30 October 2026 explicitly and intentionally 2.2.0 – 30 October 2026 this plugin's own product/variation meta (`_kitgenix_stock_sync_for_woocommerce_gid`, `_..._version`, `_..._applied_version`). The code comment and readme.txt both explain this: removing GID/version meta on every uninstall/reinstall cycle would force GID regeneration and reset staleness fencing, defeating the migration policy described in `Settings::ensure_defaults()`. A site owner who wants full cleanup must delete this postmeta manually (readme.txt documents the exact keys and the caveat about only doing so if not planning to resume syncing with the same counterpart stores).

## Known Limitations / TODOs

No `TODO`/`FIXME`/`@todo` code comments exist anywhere in the codebase (verified by grep across `includes/`, the main file, and `assets/js/`) 2.2.0 – 30 October 2026 the plugin's inline comments are explanatory/rationale-documenting rather than flagging unfinished work. The following gaps were observed during review, not stated in the code:

- **Dead/orphaned JS file**: `assets/js/admin.js` implements a tab-switcher that queries `.kitgenix-tab-trigger[data-kitgenix-stock-sync-for-woocommerce-tab]` and `[data-kitgenix-stock-sync-for-woocommerce-tab-panel]`. No such attributes exist anywhere in the rendered admin markup (`Admin::render_page()` uses `data-kitgenix-tab` / `data-kitgenix-tab-panel`, matched by `kitgenix-admin-tabs.js` instead). `admin.js` is still enqueued on every page load but its `boot()` function's querySelector calls will simply never match anything 2.2.0 – 30 October 2026 effectively dead code left over from an earlier UI iteration.
- **The shared `kitgenix-admin-ui.css` stylesheet loads Google Fonts from an external CDN** (`@import url('https://fonts.googleapis.com/css2?...')`) on every wp-admin page where it's enqueued (this plugin's settings page and the Kitgenix Hub page) 2.2.0 – 30 October 2026 a third-party network dependency not otherwise called out alongside this plugin's other outbound network calls (REST sync, WP.org Hub `plugins_api()` calls).
- **Single-Master design is a hard architectural limit, not a bug**: the plugin explicitly supports only one Master per network (documented in the readme FAQ); merging stock from multiple independent authoritative sources into one store is out of scope by design.
- **No built-in product-existence bootstrapping**: sync only ever matches an *existing* product by GID or SKU on the receiving store 2.2.0 – 30 October 2026 it never creates a new product from an event. A Child (or Master) that doesn't already have a matching SKU/GID simply logs `Missing product for incoming update. Skipping.` (code `config_error`) and drops that item; the merchant must ensure the product catalogue itself is already aligned across stores by some other means (this plugin is stock-only, not catalogue-sync 2.2.0 – 30 October 2026 see readme's explicit pointer to the separate "Kitgenix MultiStore Sync" plugin for full catalogue replication).
- **Reconcile/Audit network calls are not parallelized**: `fetch_child_stock_state()` is called sequentially per configured Child inside `master_audit_children_stock()` and each reconcile batch, so a large number of Child stores (or a slow/offline one within its HTTP timeout) can make an audit or reconcile batch take proportionally longer; there is no async/concurrent HTTP fan-out for these read-only comparison calls (the async dispatch only applies to the actual push/send writes).
- **WP-CLI `reconcile` synchronous drain has a very high iteration ceiling** (`while ($running && $iterations < 100000)` in `Kitgenix_Stock_Sync_For_WooCommerce_CLI::reconcile()`), effectively unbounded for realistic catalogue sizes, but a pathological configuration (e.g. `--batch=1` on a very large catalogue) could still make the command run for a very long time in a single foreground process.
