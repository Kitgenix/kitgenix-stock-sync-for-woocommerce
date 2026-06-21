=== Kitgenix Stock Sync for WooCommerce ===
Contributors: kitgenix
Donate link: https://donate.stripe.com/9B65kDgG3fTQ2Kzcmwf7i00
Tags: woocommerce, stock, inventory, sync, multi-store
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.2
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

Sync WooCommerce stock between stores with secure master-child inventory updates and signed REST requests.

== Description ==

Running multiple WooCommerce stores often creates the same operational problem: **stock drift**.

You update stock on one site, but another site still shows the old quantity. That can lead to oversells, customer frustration, and messy fulfilment.

**Kitgenix Stock Sync for WooCommerce** solves this with a secure, practical model:

- One **Master** store holds the authoritative stock state.
- One or more **Child** stores receive updates from the Master.
- Stores communicate over **signed REST requests** (HMAC SHA-256) with timestamp + nonce replay protection.

This plugin is designed to be lightweight:
- No third-party SaaS.
- No custom database tables.
- Uses WooCommerce + WordPress primitives (REST API, options, product meta, transients, WooCommerce logging, Action Scheduler).

= What this plugin syncs =

Stock state is synced per SKU, including:
- stock quantity
- stock status
- backorders
- low stock amount

Note: this plugin is focused on inventory syncing. It does not sync pricing, product content, images, or orders.

= How it works (high level) =

1) Stock changes are captured on each store using WooCommerce stock hooks.

2) Children send events to the Master; the Master applies incoming events locally and then pushes **authoritative stock state** out to all enabled children.

3) The Master can also run a **Reconcile** operation to push stock state for all products in batches.

= SKU rename sync (important) =

This plugin supports SKU renames by maintaining an internal, stable identifier (a “GID”) stored as product meta:
- `_kitgenix_stock_sync_for_woocommerce_gid`

When SKUs change on the Master, the plugin emits a `sku_rename` event using the GID so child stores can map the update safely.

Tip: Run **Reconcile** on the Master after initial setup. Reconcile establishes stable GIDs for products that don’t already have one, which makes SKU rename sync reliable.

= Strict checkout validation (Child) =

Child stores can optionally enable **Strict checkout validation**:
- During checkout, the child queries the Master’s stock for SKUs in the cart.
- If the Master reports the SKU is out of stock or insufficient (with backorders disabled), checkout is blocked.
- If the Master can’t be reached, validation is **fail-open** to avoid breaking checkout.

= Exclusions =

You can exclude SKUs (comma or new line separated). Excluded SKUs are ignored for:
- outbound stock events
- reconcile batches
- strict checkout validation
- audit checks

= Tools & diagnostics included =

From the plugin admin screen:
- **Test Connection** (ping the configured store)
- **Reconcile (Master)**: push authoritative stock state to all children in batches
- **Manual SKU Sync (Master)**: push a specific set of SKUs to all children
- **Audit Children (Master)**: query each child’s local stock fields and compare against the Master
- **Event Log + Backlog**: see recent events and failed pushes, and clear logs when needed
- **Status**: last inbound/outbound health timestamps and last error message

== Quick Start ==

1. Install and activate the plugin on the Master and all Child stores.
2. Choose your role on each store:
   - Master: one store
   - Child: all other stores
3. On the Child store: set the Master connection (Master URL, Master Store ID, Shared Secret).
4. On the Master store: add each child (Child URL, Child Store ID, Shared Secret).
5. Use Tools → **Test Connection**.
6. On the Master store: run Tools → **Reconcile** to establish stable GIDs and push initial state.
7. Optionally enable Strict checkout validation on children.

== Installation ==

1. Install via Plugins → Add New and search for “Kitgenix Stock Sync for WooCommerce”, or upload the ZIP to `/wp-content/plugins/`.
2. Activate the plugin (WooCommerce required).
3. Open the settings under the Kitgenix hub (Kitgenix → Stock Sync).
4. Configure Master/Child roles and connection secrets.
5. Run a Reconcile on the Master.

== Frequently Asked Questions ==

= Does this require WooCommerce? =
Yes. This plugin hooks WooCommerce product stock APIs and requires WooCommerce to be active.

= Can I have more than one Master? =
No. This plugin is designed for a single authoritative Master store and one or more children.

= Does it sync product data (title, price, images) or orders? =
No. It syncs stock state only.

= What stock fields are synced? =
Per SKU: stock quantity, stock status, backorders, and low stock amount.

= Does it support variable products? =
Yes. Variations are synced by their own variation SKU.

= Are any product types excluded? =
External/Affiliate and Grouped products are skipped for stock syncing (these types are not stock-managed in WooCommerce).

= What happens if a child store is offline? =
Failed pushes are recorded in the Backlog and retried automatically using WooCommerce’s Action Scheduler when available.

= What happens if the Master is unreachable during checkout validation? =
Strict checkout validation is fail-open: it logs a warning and does not block checkout if the Master cannot be reached.

= Where can I see logs? =
- WooCommerce → Status → Logs (source: `kitgenix-stock-sync-for-woocommerce`)
- Kitgenix → Stock Sync → Logs tab (Event Log + Backlog)

= How do I exclude SKUs? =
Add SKUs in Configuration → Exclusions. Excluded SKUs are ignored across all sync and tooling paths.

== Screenshots ==

1. Status tab showing role, this store ID, and inbound/outbound health timestamps.
2. Configuration tab for store name, Master/Child role selection, Strict checkout validation, and Exclusions.
3. Stores tab: Child → Master connection fields, or Master → manage configured Child stores and shared secrets.
4. Tools tab: Test Connection, plus Master tools (Reconcile, Manual SKU Sync, Audit Children) and audit results.
5. Logs tab: Event Log and Backlog (failures), with actions to clear each.
6. Support tab showing plugin impact summary and support links.

== Developers ==

Text domain:
kitgenix-stock-sync-for-woocommerce

Option key:
- `kitgenix_stock_sync_for_woocommerce_settings`

Option schema (high-level):
- `this_store_id`, `this_store_name`, `role`, `strict_checkout_validation`
- `master` (child config): `url`, `store_id`, `secret`
- `children` (master config): entries with `id`, `name`, `url`, `secret`, `enabled`
- `exclusions.skus`
- Diagnostics/admin UI state: `notices`, `event_log`, `backlog`, `reconcile`, `health`

Product meta key:
- `_kitgenix_stock_sync_for_woocommerce_gid`

REST API routes (POST):
- `/wp-json/kitgenix-stock-sync/v1/ping`
- `/wp-json/kitgenix-stock-sync/v1/event`
- `/wp-json/kitgenix-stock-sync/v1/stock` (master only; used by strict checkout validation)
- `/wp-json/kitgenix-stock-sync/v1/stock-state` (used by audit)

Authentication headers:
- `X-Kitgenix-Store-Id`
- `X-Kitgenix-Timestamp`
- `X-Kitgenix-Nonce`
- `X-Kitgenix-Signature`

Signatures:
- HMAC SHA-256 over: `timestamp + "\n" + nonce + "\n" + request_body`
- Timestamp skew allowed: 5 minutes
- Nonce replay protection stored via transients

Action Scheduler hooks:
- (Action group: `kitgenix-stock-sync`)
- `kitgenix_stock_sync_for_woocommerce_process_event`
- `kitgenix_stock_sync_for_woocommerce_push_to_store` (async enqueue)
- `kitgenix_stock_sync_for_woocommerce_retry_send_to_master`
- `kitgenix_stock_sync_for_woocommerce_retry_push_to_store`
- `kitgenix_stock_sync_for_woocommerce_reconcile_batch`

Admin capability:
- `manage_woocommerce`

Admin nonces:
- `kss_save_config`
- `kss_save_connection`
- `kss_save_children`
- `kss_test_connection`
- `kss_tools`
- `kss_logs`

Filters:
- `kitgenix_stock_sync_for_woocommerce_parent_menu_slug` (change the parent menu slug; default: `kitgenix`)

Transients (dynamic keys):
- `kitgenix_stock_sync_for_woocommerce_do_activation_redirect` (30 seconds)
- `kitgenix_stock_sync_for_woocommerce_kss_nonce_{md5(store_id|nonce)}` (nonce replay protection, 10 minutes)
- `kitgenix_stock_sync_for_woocommerce_kss_seen_{md5(event_id)}` (duplicate event detection, ~2 hours)
- `kitgenix_stock_sync_for_woocommerce_kss_debounce_{md5(key)}` (debounce, ~2 seconds)
- `kitgenix_stock_sync_for_woocommerce_kss_old_sku_{post_id}` (SKU rename helper, 60 seconds)
- `kitgenix_stock_sync_for_woocommerce_kss_audit_result_{user_id}` (stores last audit result in wp-admin, 10 minutes)

Object cache (if persistent object cache is enabled):
- Cache group: `kitgenix_stock_sync`
- Key: `kitgenix_stock_sync_for_woocommerce_kss_gid_{md5(gid)}` (GID → product ID lookup, ~1 hour)

Internal action hooks (called directly, but can be hooked):
- `kitgenix_stock_sync_for_woocommerce_process_order_processing`

== External Services ==

This plugin includes a shared “Kitgenix hub” component in wp-admin which may fetch publicly available plugin metadata from WordPress.org using WordPress core’s `plugins_api()` function.

Caching:
- Transient: `kitgenix_hub_wporg_active_installs_v1`
- Transient: `kitgenix_hub_wporg_ratings_v1`

This plugin does not otherwise connect to third-party services as part of its stock sync. It does make REST requests between your own WordPress sites (Master and Child stores). These requests may include:
- product SKUs
- stock state (quantity/status/backorders/low stock)

Strict checkout validation on children sends SKUs in the cart to the Master for stock verification.

== Security & Privacy ==

- No tracking cookies are added by this plugin.
- Admin actions are protected with nonces and capability checks.
- REST requests are authenticated using HMAC signatures with timestamp + nonce replay protection.
- Shared secrets are stored in the plugin settings option (`kitgenix_stock_sync_for_woocommerce_settings`). Treat secrets like passwords.

== Uninstall ==

This plugin removes its settings and plugin-only transients on uninstall. It does not remove WooCommerce product meta or Action Scheduler records.

Removed on uninstall:
- Option: `kitgenix_stock_sync_for_woocommerce_settings`
- Site option: `kitgenix_stock_sync_for_woocommerce_settings`
- Transients by prefix: `kitgenix_stock_sync_for_woocommerce_` and `kss_` (covers dynamic keys such as nonce/seen/debounce helpers)

Multisite:
- Removes per-site options and transients for each site.

If you want to remove all plugin data, you can also delete:
- the product meta `_kitgenix_stock_sync_for_woocommerce_gid` (if you no longer need SKU rename mapping)

== Support Development ==

If this plugin saves you admin time or helps prevent oversells across multiple stores, you can support ongoing development here:
https://donate.stripe.com/9B65kDgG3fTQ2Kzcmwf7i00

== Credits ==
Built with ❤︎ by @kitgenix - https://kitgenix.com

== Upgrade Notice ==

= 1.0.2 =
Maintenance and compatibility update. Recommended for all sites.

== Changelog ==

= 1.0.2 (19 March 2026) =
Update: Improved the Kitgenix admin header layout for better alignment and less clutter.
Update: Social links in admin headers now render as compact icon buttons (with accessible labels).
Update: Added responsive header helpers so titles/description and actions/links lay out consistently.
Fix: Added defensive notice normalization to keep WordPress admin notices above the Kitgenix header.
Update: Admin tables inside Kitgenix pages now use Kitgenix styling for a more consistent branded look.
Fix: Added spacing between adjacent action links/buttons (e.g., Edit/Delete).
Fix: Escaped shared Kitgenix hub card media output for WordPress coding standards compliance.
Maintenance: Updated the plugin Author URI to the public Kitgenix WordPress.org profile and replaced the old custom admin-menu icon CSS with the native Dashicons icon.

= 1.0.1 (18 February 2026) =
* Tweak: Clarified uninstall behaviour (plugin settings + transients are removed; WooCommerce product meta and Action Scheduler data are retained).
* Dev: Updated translation template metadata.

= 1.0.0 (14 February 2026) =
* New: Initial release.
* New: Master + child stock sync via signed REST events.
* New: Strict checkout validation on children (optional).
* New: Master tools: reconcile, manual SKU sync, and audit.
* New: Event log and backlog with retries via Action Scheduler when available.
