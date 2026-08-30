# Changelog

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