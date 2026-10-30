# Changelog

## 2.2.0 – 30 October 2026

- **Fix:** Fixed a stock sync race that could oversell. Children previously sent their *absolute* stock quantity on every change, and the Master applied it with a plain overwrite. Two near-simultaneous sales – for example, a Master with 10 in stock, Child A selling one and reporting 9, then Child B independently selling one and also reporting 9 – could leave the Master at 9 instead of the correct 8, silently losing one of the two decrements (and a Master-side sale in that same window could be overwritten the same way). Children now send a **delta** for every order-driven stock change (a reduction or restoration tied to a specific order line, captured from WooCommerce's `woocommerce_reduce_order_stock` / `woocommerce_restore_order_stock` hooks so it is captured whatever status triggers it – processing, on-hold, or a pending order's held stock expiring), and the Master applies each one atomically with WooCommerce's own increase/decrease stock function instead of overwriting the quantity outright. Each delta carries an idempotency key (store, order, item and operation) so a retried or duplicate delivery of the same delta is never applied twice. Absolute updates are kept for deliberate admin edits, imports, and for stores not yet updated: the event's wire format still includes the resulting absolute quantity alongside the new delta information, so a store on the previous protocol version keeps working exactly as before (with the old oversell risk) until it is updated too. Event schema bumped to version 3.
- **New:** Added a Site Health check that detects Kitgenix MultiStore Sync for WooCommerce running on the same site with its own inventory sync enabled. Running two Kitgenix inventory engines at once writes stock twice, with different protocols and identities, and can create a feedback loop between stores; the check flags this as critical and explains how to pick one engine.
- **New:** Stock History per SKU. Each store records a versioned history of stock events per product (captured, authoritative, applied, SKU rename, stale update ignored). Look up a SKU on the Tools tab to see its current state on every connected store and one merged timeline, linked by event ID. Also available as `wp kitgenix-stock-sync history <sku>`.
- **New:** SKU Mapping Repair Tool on the Conflicts tab. Scans for duplicate SKUs, duplicate GIDs and stock-managed products without a SKU. Duplicate SKUs and GIDs are held out of sync until repaired, so an ambiguous match cannot overwrite the wrong product. Assign a unique SKU or issue a new GID from the same screen; holds are released automatically once fixed, and the Master re-pushes released SKUs to every Child. Also available as `wp kitgenix-stock-sync identity`.
- **New:** Failover Readiness Report on the Status tab. Explains what happens if the Master is unreachable for this store's exact settings – checkout behaviour, the retry window for queued changes, stock drift, the age of last-known-good stock snapshots, and recovery steps – with an overall verdict. On the Master it also lists every Child's readiness. Download it as Markdown or run `wp kitgenix-stock-sync failover-report`.
- **Improved:** Products created with WooCommerce's "Duplicate" action no longer copy the original product's GID, version counters or stock history, which previously made both products share one sync identity.
- **Fix:** When a Child matches a product by SKU but the product carries a different GID, it now resets that product's applied version as it adopts the Master's GID, so later updates are no longer ignored as stale.
- **Fix:** A store promoted from Child to Master now continues each product's version counter from the version it last applied, so the other Children accept its updates instead of ignoring them as stale.
- **Fix:** The shared "Kitgenix" admin menu was being registered immediately during `plugins_loaded`, instead of on the `admin_menu` hook, which could trigger a "translation loading triggered too early" notice on sites running other Kitgenix plugins. It is now only registered on `admin_menu`, as intended.
- **Fix:** The health-ping and event-log-pruning cron schedules were set up during `plugins_loaded`, before Action Scheduler's data store is guaranteed to be ready, which could trigger "called before the Action Scheduler data store was initialized" notices. Scheduling is now deferred to `init`.
- **Improved:** Raised the minimum PHP version to 8.2 (`Requires PHP: 8.2`).
- **Improved:** Tested up to WordPress 7.4 and WooCommerce 11.2.1, and reviewed for compatibility with PHP 8.2 to 8.5. The full codebase passes syntax checks on PHP 8.5 and a PHPCompatibility scan for PHP 8.2–8.5, and uses no dynamic properties or functions deprecated in PHP 8.2–8.5.
- **Improved:** Declared compatibility with the WooCommerce Cart and Checkout blocks (`cart_checkout_blocks`), so WooCommerce no longer lists the plugin as possibly incompatible in the Checkout block editor.
- **Fix:** Strict checkout validation on Child stores now also runs on the WooCommerce Checkout block. It was hooked only to the classic checkout's `woocommerce_after_checkout_validation`, so stores using the Checkout block (the WooCommerce default) skipped the live Master stock check. The same check and failure strategies now run on Store API checkout via `woocommerce_store_api_checkout_update_order_from_request`, and failures are shown as a checkout error notice.
- **Dev:** `/ping` on a Child now returns a `failover` readiness summary, and `/stock-state` accepts `include_history`. New filter `kitgenix_stock_sync_for_woocommerce_history_limit`, new diagnostic code `kss_sku_held`, settings schema version 3.
- **Dev:** Uninstall now also removes the Stock History product meta and the snapshot index option.
- **Security:** Store-to-store requests now carry an additional signature that also covers the HTTP method and REST route, so a captured request cannot be replayed against a different endpoint. Once a store has sent the new signature, requests from it without the new signature are rejected, while stores still running an older version keep syncing until they are updated.
- **Security:** Request authentication now runs in each REST route's `permission_callback`, so WordPress enforces it before any endpoint code runs.
- **Dev:** `Kitgenix_Stock_Sync_For_WooCommerce_Security::sign_headers()` now takes the target endpoint URL as a fourth argument.
- **Dev:** Removed the PluginScore verification code from the readme, as Kitgenix Plugin Score has been discontinued.
- **Dev:** Bumped the version to 2.2.0 across the plugin.
- **Documentation:** Rewrote the readme's Description section as plain text and moved the feature list into Other Notes, so the plugin directory no longer truncates the Description for exceeding the 2,500-character limit.
- **Documentation:** Regenerated the `.pot` translation template for 2.2.0.
- **Documentation:** The readme changelog now lists only the current release. The full release history is in `CHANGELOG.md`.

## 2.1.2 – 13 October 2026

- **Fix:** Bump version to 2.1.2 across the plugin.

## 2.1.1 – 13 October 2026

- **Fix:** Updated PluginScore Verification code.

## 2.1.0 – 13 October 2026

- **New:** Added a configurable log auto-delete setting (7/14/30 days) to automatically remove old event log entries.
- **New:** Added a "Request a Feature" button to the Need Help? sidebar, linking directly to this plugin's page on the Kitgenix roadmap (https://roadmap.kitgenix.com).
- **New:** Added a "Request a Feature" banner below the plugin grid on the Kitgenix Hub page, linking to the shared Kitgenix roadmap.

## 2.0.0 – 22 August 2026

- **New:** Redesigned the entire admin settings screen around the shared Kitgenix design system – card-based sections, stat tiles, status badges, and toggle switches replace the old WordPress form tables and status tables.
- **New:** Added a sticky topbar with the Kitgenix brand, plugin name/version, tab navigation, a link to the Kitgenix Hub, and a mobile hamburger menu, replacing the previous nav-tab bar and static page header.
- **New:** Added an in-page settings search (topbar search panel with "/" and Cmd/Ctrl+K shortcuts) that filters cards and rows on the active tab as you type.
- **New:** Added a light/dark theme toggle for the admin UI; the chosen theme is remembered in the browser.
- **New:** Editing or removing a Child store on the Stores tab now opens an accessible modal dialog instead of an inline form and a JavaScript `confirm()` prompt.
- **New:** Event Log and Backlog tables on the Logs tab now support live search/filtering, colour-coded status badges, and empty-state messaging.
- **New:** Event Log and Backlog tables are now paginated at 25 rows per page so a busy store's history does not turn into one long scroll.
- **New:** Added a diagnostic code reference table to the Logs tab explaining what each recorded category means and whether it needs action.
- **New:** Added versioned stock state. Every authoritative item now carries a monotonically increasing per-product version; a receiving store records the last version it applied and ignores anything arriving with an equal or older version. Delayed, duplicated, retried, and out-of-order events can no longer overwrite already-current stock.
- **New:** Added an event envelope. Every sync event now has a stable unique ID plus origin store, creation time, delivery-attempt count, and a schema version – carried through retries rather than regenerated per attempt.
- **New:** Added a Conflict Dashboard tab covering missing products, missing or duplicate SKUs, GID mismatches, quantity/backorder/stock-status mismatches, Child stores being offline, and authentication errors.
- **New:** Conflict Dashboard results can now be populated by Audit, Reconcile dry-runs/difference checks, and a dedicated duplicate-SKU scan.
- **New:** Reconcile now supports selected SKUs instead of requiring all products to be processed.
- **New:** Added Reconcile dry-run mode so differences can be reviewed without changing stock.
- **New:** Added a push-differences-only mode to Reconcile.
- **New:** Added Reconcile run summaries showing processed products, detected differences, and pushed updates.
- **New:** Added Resume and Cancel controls for in-progress Reconcile runs.
- **New:** Added Backlog v2. Failed push/send attempts now retain their complete payload, stable event ID, status, and next-retry time so queued events can be resent correctly.
- **New:** Added per-item manual Retry controls to the Backlog.
- **New:** Added per-item Discard controls with confirmation to the Backlog.
- **New:** Added a bounded retry policy so deliveries that exhaust their retry budget are marked for manual attention instead of retrying indefinitely.
- **New:** Added per-store Connection Health tracking, replacing the previous single global set of health fields regardless of how many Child stores were configured.
- **New:** Connection Health now records last inbound communication, last outbound communication, last successful communication, last error, remote WooCommerce version, remote plugin version, and a derived connection status.
- **New:** Added a recurring health-check ping to keep per-store connection status current.
- **New:** Added merchant-configurable strict checkout validation failure strategies – fail open, fail closed, or use a last-known-good stock snapshot with a configurable maximum age.
- **New:** Added safe shared-secret rotation from the Stores tab, including a configurable overlap window so both sides of a store pairing can be updated without interrupting synchronisation.
- **New:** Added WP-CLI commands for server administrators: `wp kitgenix-stock-sync status`, `audit`, `reconcile`, `sku`, `backlog`, and `conflicts`.
- **New:** Added WordPress Site Health Status checks for role/connection configuration, connection health, backlog items requiring attention, and Action Scheduler availability.
- **New:** Added a non-secret Kitgenix Stock Sync diagnostic section to WordPress Site Health → Info.
- **Improved:** Authentication rejections between stores – including missing signatures, bad timestamps, clock drift, replayed requests, and invalid signatures – are now recorded in the Event Log, with clock drift and replayed requests flagged as routine rather than genuine security concerns.
- **Improved:** Sync failures between stores now distinguish temporary network or connection issues from genuine rejections such as bad configuration or hard errors in both the Event Log and the Status tab's health indicator.
- **Improved:** The Support tab is now three focused cards – a donate card with a collapsible monthly-amount picker, a "what your support funds" summary, and a single "get involved" panel for reviews, plugin links, and community – replacing the previous stack of donate/trust/community cards and sidebar.
- **Improved:** Audit Children results are now grouped into collapsible per-child cards instead of one long flat list.
- **Improved:** The Kitgenix Hub page now shares the same topbar and card design system as the plugin settings screens and shows installed/active plugin counts alongside each listing.
- **Improved:** Added the Image Optimizer plugin to the Kitgenix Hub's plugin list and updated the MultiStore Sync entry to the renamed "MultiStore for WooCommerce" plugin with its new slug and page.
- **Improved:** Refreshed the Kitgenix brand logo and favicon assets with new primary, dark, white, and black variants, including tagline versions, and updated the admin menu icon.
- **Improved:** Outbound stock changes are now coalesced per request, so every product touched by a single operation – such as one order's line items – is sent as a single event instead of generating one network request per WooCommerce hook.
- **Improved:** The Master's real-time push to Child stores now dispatches asynchronously through Action Scheduler instead of blocking the request that triggered the stock change, matching the behaviour already used by bulk tools.
- **Improved:** The Master no longer echoes an incoming event back to the Child store that sent it, eliminating an unnecessary round trip and preventing integrations such as WooCommerce Square from creating stock-sync feedback loops when driving a Child store's local inventory.
- **Improved:** Variations that inherit stock management from their parent using WooCommerce's "parent" stock-management mode are now synchronised and applied faithfully instead of being forced into independently managed stock.
- **Improved:** Strict checkout validation continues to block checkout in every failure strategy when a reachable Master definitively reports that an item is out of stock or has insufficient quantity.
- **Fix:** The settings sidebar now sticks flush beneath the topbar based on its real measured height instead of a fixed guessed offset, preventing overlap when the topbar wraps to two lines on narrower screens.
- **Fix:** Number inputs across the settings screens are no longer cramped to a fixed 50px width.
- **Fix:** Corrected order stock-reduction/restoration handling where the suppression flag was previously being set after WooCommerce had already fired the per-item stock hooks it was intended to suppress.
- **Fix:** Replaced the previous order-level suppression workaround with request-scoped stock-change coalescing, removing the dependency on WooCommerce hook ordering entirely.
- **Security:** Verified compatibility with WooCommerce 11.0 failed-order stock restoration. WooCommerce's `wc_maybe_increase_stock_levels()` now also runs on `woocommerce_order_status_failed`, guarded by WooCommerce's own stock-reduced flag, and Kitgenix Stock Sync correctly synchronises the resulting WooCommerce stock state without reimplementing order-status arithmetic.
- **Security:** Request bodies larger than 2MB are now rejected before HMAC verification.
- **Security:** Malformed JSON requests now return a clean error instead of falling through into further request processing.
- **Security:** Repeated authentication failures from the same sender now trigger a temporary lockout.
- **Security:** Authentication failure responses now use a single generic message so callers cannot enumerate configured store pairings.
- **Security:** Master and Child store URLs must now use HTTPS, with a filtered exception available for local development environments.
- **Dev:** Added a new shared `kitgenix-admin-components.js` script providing modals, collapsible cards, copy-to-clipboard controls, live table search, and toast-style save confirmations.
- **Dev:** Substantially expanded `kitgenix-admin-tabs.js` to drive the new topbar, mobile menu, dropdowns, and dynamic sticky-offset behaviour.

## 1.0.0 – 19 March 2026

- **Update:** Improved the Kitgenix admin header layout for better alignment and less clutter.
- **Update:** Social links in admin headers now render as compact icon buttons with accessible labels.
- **Update:** Added responsive header helpers so titles/descriptions and actions/links lay out consistently.
- **Update:** Admin tables inside Kitgenix pages now use Kitgenix styling for a more consistent branded look.
- **Fix:** Added defensive notice normalization to keep WordPress admin notices above the Kitgenix header.
- **Fix:** Added spacing between adjacent action links/buttons such as Edit/Delete.
- **Fix:** Escaped shared Kitgenix Hub card media output for WordPress coding standards compliance.
- **Maintenance:** Updated the plugin Author URI to the public Kitgenix WordPress.org profile and replaced the old custom admin-menu icon CSS with the native Dashicons icon.

## 1.0.1 – 18 February 2026

- **Tweak:** Clarified uninstall behaviour: plugin settings and transients are removed, while WooCommerce product meta and Action Scheduler data are retained.
- **Dev:** Updated translation template metadata.

## 1.0.0 – 14 February 2026

- **New:** Initial release.
- **New:** Added Master and Child stock synchronisation via signed REST events.
- **New:** Added optional strict checkout validation on Child stores.
- **New:** Added Master tools for reconciliation, manual SKU synchronisation, and auditing.
- **New:** Added an Event Log and retry Backlog using Action Scheduler when available.