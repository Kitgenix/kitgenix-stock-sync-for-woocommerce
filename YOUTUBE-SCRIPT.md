# YouTube Script: Kitgenix Stock Sync for WooCommerce

- **Suggested video title:** Stop Overselling Across Multiple WooCommerce Stores – Real-Time Stock Sync (Kitgenix Stock Sync)
- **Alternative title:** How to Share One Inventory Between WooCommerce Stores (Master/Child Stock Sync)
- **Estimated runtime:** about 12–13 minutes at a brisk presenting pace (around 2,350 spoken words). To bring it down to 10 minutes, shorten section 10 to a single line and leave out the version-number paragraph in section 6.
- **Plugin version covered:** 2.2.0 (requires WordPress 6.0+, PHP 8.2+, WooCommerce 10.0+)
- **Recording note:** You'll need at least two WooCommerce test sites on HTTPS (three is better for the real-world example in section 11). The plugin only allows plain `http://` for `localhost`, `.local` or `.test` hosts, and only when a developer filter turns it on.

---

## 1. Hook

[ON SCREEN: Split screen. Left: "Retail Store" product page showing "Blue Ceramic Mug, In stock (1)". Right: "Wholesale Store", same mug, an order confirmation for 1 unit.]

You've got one last blue mug on the shelf. Someone buys it on your wholesale site. Thirty seconds later someone else buys the same mug on your retail site, because as far as that site knows, it's still in stock.

[ON SCREEN: Both order confirmations side by side. A red "OVERSOLD" stamp lands between them.]

So now you're writing the apology email, issuing the refund, and hoping they don't leave a one-star review. If you run more than one WooCommerce store from the same stock, you've almost certainly been here. In this video we'll show you how to stop it happening.

## 2. Intro

[ON SCREEN: Kitgenix logo, then the plugin title card: "Kitgenix Stock Sync for WooCommerce".]

Hi, this is Kitgenix, and this is **Kitgenix Stock Sync for WooCommerce**.

It's a plugin for anyone who sells the same physical stock through more than one WooCommerce store. That could be a retail site and a wholesale site, separate UK and Ireland storefronts, or several brand sites that all ship from one warehouse.

[ON SCREEN: Simple diagram: one "Master" store in the centre, arrows to three "Child" stores.]

The promise is simple. One store holds the true stock count, every other store stays in line with it, and the stores talk to each other directly over signed, secure requests.

## 3. Why We Built This

[ON SCREEN: A WooCommerce product edit screen, zoomed in on the Inventory tab.]

WooCommerce is brilliant at managing stock for one store. But it has no idea that this product also exists on another site. Each install keeps its own stock count and doesn't know the others exist.

[ON SCREEN: Montage: a spreadsheet labelled "stock_export_FINAL_v3.csv", a code editor full of custom webhook code, a support inbox full of "Where's my order?" emails.]

So multi-store sellers end up exporting CSV files by hand, paying for custom code that breaks on the next update, or putting up with overselling.

We wanted something better: connect your stores once, and let WooCommerce's own stock changes flow between them automatically, directly from store to store, with no Kitgenix cloud service in the middle.

## 4. Installing & Activating on Both Stores

[ON SCREEN: WordPress admin → Plugins → Add New, searching "Kitgenix Stock Sync for WooCommerce".]

Setup has two sides, so you install the plugin on **every** store in your network. Go to Plugins, Add New, search for "Kitgenix Stock Sync for WooCommerce", then install and activate. WooCommerce has to be active first. If it isn't, the plugin deactivates itself rather than leaving you with a half-working setup.

[ON SCREEN: After activation, the browser redirects to Kitgenix → Stock Sync. Show the topbar with its seven tabs: Status, Configuration, Stores, Tools, Conflicts, Logs, Support.]

After you activate it, you land on **Kitgenix → Stock Sync**. Now make the most important decision in the whole setup: which store is the **Master**?

[ON SCREEN: Configuration tab. Highlight "This store name" and the Role radio buttons: Master / Child.]

The Master holds the authoritative stock. It's usually the store closest to your warehouse or point of sale. Every other store is a **Child**. You can have one Master and as many Children as you need, but only one Master. That's deliberate, because two sources of truth means no source of truth.

On each site, open the Configuration tab, give the store a friendly name you'll recognise in the logs, pick its role, and save.

## 5. Pairing Stores Securely

[ON SCREEN: Child store → Stores tab. Highlight the "This Store ID" value and click to copy.]

Now we connect them. Every store gets its own unique **Store ID** when it's set up. Start on the Child: go to the Stores tab and copy its "This Store ID".

[ON SCREEN: Master store → Stores tab → "Add Child Store" form. Fill in Child name, Child URL (https://retail.example.com), paste the Child's Store ID, leave Shared Secret blank.]

Switch to the Master, open the Stores tab, and find "Add Child Store". Give it a name and enter the Child's URL, which must be HTTPS. Paste in the Child's Store ID. Leave the shared secret blank and the plugin generates a strong 32-character secret for you. Then add the store.

[ON SCREEN: The "Configured Children" table showing the new Child row with its secret. Copy the secret and the Master's own "This Store ID".]

Copy that shared secret and the Master's own Store ID, then go back to the Child.

[ON SCREEN: Child store → Stores tab → "Master Connection" form: Master URL, Master Store ID, Shared Secret. Save, then click "Test Connection" and show the success notice.]

On the Child, open "Master Connection" and enter the Master's URL, the Master's Store ID and that same shared secret. Save it, then click **Test Connection**. If both sides agree, you'll see a success message straight away.

[ON SCREEN: Animated diagram: a request envelope with four labels: Store ID, Timestamp, Nonce, Signature. The secret is shown as a key that stays inside each store and never travels.]

So what makes this secure? The shared secret is **never sent across the internet**. Instead, every request between your stores is *signed* with it, using an industry-standard method called HMAC-SHA256. The receiving store works the signature out again using its own copy of the secret. If anything in the request was tampered with, or the sender doesn't know the secret, the signatures won't match and the request is rejected.

[ON SCREEN: Checklist animating in, one line at a time.]

On top of that, requests more than five minutes old are refused, each one can only be used once, URLs must be HTTPS, and repeated failures trigger a temporary lockout. Every failure gets the same generic reply, so an attacker can't even work out which stores you've connected.

[ON SCREEN: Master → Stores tab → Configured Children → "Rotate Secret" button, with the confirmation prompt.]

And if you ever need to change a secret, maybe a staff member has left or you just rotate credentials on a schedule, use **Rotate Secret** on the Stores tab. The old secret keeps working for an overlap window, 24 hours by default, so you have time to update the other store without breaking sync.

## 6. How Sync Actually Works

[ON SCREEN: Diagram: Child store sells an item → arrow to Master labelled "event" → Master updates its own database → arrows fan out to the other Children labelled "authoritative stock".]

Here's what happens in practice. Sync is **real-time and automatic**, with no schedule to set up. The plugin listens to WooCommerce's own stock hooks, so anything that changes stock through WooCommerce gets picked up: an order, a refund, a cancellation, a manual edit, a CSV import through WooCommerce's importer, a REST API update, or an integration like WooCommerce Square.

[ON SCREEN: Highlight the Master in the diagram with a crown icon.]

When stock changes on a Child, the Child sends that change to the Master. The Master applies it, then **rebuilds the true stock state from its own database** and pushes that to every *other* Child. It doesn't send it back to the store it came from, so you won't get feedback loops. This is the key difference from a "last save wins" setup, where two stores can keep overwriting each other with old numbers.

All of this runs in the background through WooCommerce's Action Scheduler, so your customer's checkout never waits on another website. Changes usually land within seconds.

[ON SCREEN: Product edit screen with the SKU field highlighted, plus a small "GID" badge.]

How does it know which product is which? Primarily by **SKU**. Products and variations need matching SKUs on each store. On top of that, the Master gives every product a permanent **Global ID**, so if you rename a SKU on the Master, the plugin tracks the rename and keeps the link intact. One thing to know: this plugin syncs *stock*, not your catalogue. It won't create products on a Child, so the product needs to exist on both sides already.

[ON SCREEN: Text list: Stock quantity · Stock status · Backorders · Low stock amount · Manage stock (including "inherit from parent" variations).]

For each product it syncs stock quantity, stock status, the backorders setting, the low-stock amount, and whether stock is managed, including variations that inherit stock management from their parent product. External/affiliate and grouped products are skipped, because WooCommerce doesn't manage stock for those.

[ON SCREEN: Diagram: three events arrive out of order, numbered v12, v14, v13. v13 bounces off with a "stale – ignored" label.]

One more detail we're proud of. Every update the Master sends carries a **version number** for that product. If an older update turns up late, maybe after a retry or a slow network, the receiving store sees it's out of date and ignores it. A late message can never roll your stock backwards.

[ON SCREEN: Master → Tools tab → Reconcile panel: scope (All / Selected SKUs), batch size, Dry run, Differences only. Then the Manual SKU Sync and Audit Children panels.]

The safety net is on the Master's **Tools** tab. **Reconcile** works through your whole catalogue, or just the SKUs you pick, in background batches and pushes the Master's stock to every Child. You can do a **dry run** first, push **differences only**, and resume or cancel partway through. **Manual SKU Sync** pushes specific SKUs immediately, and **Audit Children** compares a few SKUs across every Child. Reconcile runs when you start it, not on a timer. Everyday syncing is the real-time part.

## 7. The Conflicts Report

[ON SCREEN: Conflicts tab with a populated report table: Type, SKU, Child, Master, Child value, Detail.]

So how do you know when something doesn't line up? That's the **Conflicts** tab.

[ON SCREEN: Highlight several rows: "quantity_mismatch", "missing_product", "duplicate_sku", "child_offline".]

The report fills up when you run an Audit, a Reconcile dry run or differences-only pass, or the **Scan for conflicts** button on the Master, which looks for duplicate SKUs. It flags things like:
- a product that's missing on a Child,
- a Global ID mismatch,
- quantity, backorder or stock status differences,
- a Child that's offline,
- authentication errors,
- duplicate SKUs on the Master.

[ON SCREEN: Walkthrough: fix a duplicate SKU in the product editor → back to Tools → Manual SKU Sync for that SKU → Conflicts → "Clear" button.]

Fixing a conflict is a short loop. First fix the cause: give that duplicate product its own SKU, create the missing product on the Child, or sort out the connection. Then push the correct stock with Manual SKU Sync or Reconcile, and clear the report. Run the audit again and that row should be gone.

## 8. Status & Connection Health

[ON SCREEN: Status tab on the Master. Show the stat tiles (Role, last inbound, last outbound, last error), then scroll to the "Connection health" table.]

The **Status** tab is your home screen. At the top you'll see this store's role and when it last sent and received updates, plus the last error in plain English if there was one.

[ON SCREEN: Zoom into the Connection health table: Store, Status, Last inbound, Last outbound success, Last error, Remote WC, Remote plugin. Show status badges: OK, Stale, Error, Never.]

Below that is the **Connection health** table, with one row per connected store. Each row shows whether the connection is OK, stale, in error, or has never connected yet, along with the last inbound and outbound activity and which WooCommerce and plugin versions the other store is running. That last part is handy for spotting a store that missed an update.

A background health check pings each connection about every fifteen minutes to keep this table up to date. That ping only checks the connection. It doesn't sync stock, because stock already syncs in real time.

## 9. What Happens If a Store Goes Down

[ON SCREEN: Diagram: Master pushes to a Child that's greyed out with a "503" label. The event drops into a box labelled "Backlog".]

Websites go down sometimes: hosting hiccups, maintenance mode, an expired certificate. When a store can't be reached, the update isn't lost. It goes into the **Backlog** with the reason, the attempt count and the next retry time.

[ON SCREEN: Timeline of retry delays: 1 min → 5 min → 15 min → 1 hr → 6 hrs.]

The plugin retries automatically and waits longer each time: one minute, five minutes, fifteen minutes, an hour, then six hours. After a fixed number of attempts it stops and marks the item as needing attention, rather than retrying forever in the background.

[ON SCREEN: Logs tab → Backlog table, with Retry and Discard buttons on a row. Then the Event Log above it with diagnostic codes and their plain-English explanations.]

You'll find it all on the **Logs** tab. Retry or discard any item with one click. The Event Log next to it explains every diagnostic code in plain English, so you can tell a passing network blip from a real configuration problem.

[ON SCREEN: Child store → Configuration tab. Highlight "Strict checkout validation", the failure strategy dropdown, and the "last-known stock max age" field.]

Now for checkout. On a Child store you can turn on **Strict checkout validation**. When a customer checks out, the Child asks the Master live: "Do you actually have these quantities?" If the Master says no, checkout is blocked with a clear out-of-stock message. That closes the gap for even a few seconds of delay.

But what if the Master can't be reached at that moment? You decide:
- **Fail open** is the default. Let the sale go through, because availability matters most.
- **Fail closed** blocks checkout with a "couldn't verify stock" message. Pick this if overselling is worse than a lost sale.
- **Use last-known stock** checks against a recent cached snapshot, as long as it's newer than the maximum age you set (30 minutes by default). If the snapshot is too old, it falls back to fail open.

[ON SCREEN: Callout text: "A reachable Master saying 'out of stock' ALWAYS blocks checkout."]

Whichever you choose, if the Master *is* reachable and says the item is out of stock, checkout is always blocked.

## 10. For Developers/Agencies

[ON SCREEN: Terminal window running `wp kitgenix-stock-sync status`, showing a table of role, store ID and per-store health.]

If you manage stores for clients, there's a full set of WP-CLI commands under `wp kitgenix-stock-sync`.

[ON SCREEN: Type each command in turn as it's mentioned.]

- `status` shows the role and the health of every connection.
- `audit --skus=...` compares specific SKUs across every Child.
- `reconcile` runs to completion with a progress bar, and supports `--dry-run`, `--differences-only` and `--skus`.
- `sku push` sends specific SKUs out immediately.
- `backlog` lists, retries or discards queued deliveries.
- `conflicts` prints the conflicts report, and `--rescan` runs a fresh duplicate-SKU scan first.

[ON SCREEN: Tools → Site Health → Status showing the Kitgenix Stock Sync checks, then the Info tab's "Kitgenix Stock Sync" section.]

It's also built into WordPress **Site Health**. You get four checks: role and connection setup, connection health, backlog items needing attention, and whether Action Scheduler is available. The Info tab has a diagnostic section you can safely paste into a support ticket, because it never includes secrets or full store URLs.

## 11. Real-World Example

[ON SCREEN: Diagram: "Warehouse – Wholesale (Master)" in the centre, with "Retail UK (Child)" and "Retail Ireland (Child)" on either side. Each shows "Oak Chopping Board – SKU OAK-CB-01 – Stock: 3".]

Let's put it all together. Our Master is a wholesale store that sits right next to the warehouse. There are two retail Child stores, one for the UK and one for Ireland. The oak chopping board, SKU OAK-CB-01, shows a stock level of 3 everywhere.

[ON SCREEN: Wholesale Master: a trade customer orders 3 boards. The order moves to Processing. Master stock drops to 0.]

A trade customer orders all three on the wholesale site. WooCommerce reduces the Master's stock to zero, as normal.

[ON SCREEN: Cut to the UK and Ireland product pages refreshing. Both flip to "Out of stock" within seconds.]

The plugin picks that up and queues a signed update for each Child. Seconds later both retail stores show zero and "Out of stock". Nobody clicked a button.

[ON SCREEN: The Ireland Child goes offline (greyed out). The Master restocks to 20. The UK Child updates. Logs → Backlog shows a pending item for Ireland.]

Now let's make it harder. Next morning, 20 boards arrive and you update the Master. The UK store updates straight away, but the Ireland site is down for maintenance. That update goes into the Backlog and waits.

[ON SCREEN: Ireland comes back online. The next retry runs, the backlog row disappears, and Ireland shows 20.]

When Ireland comes back, the next retry delivers it and the backlog row clears. Ireland shows 20. And if a shopper had tried to check out on Ireland while it was out of date, strict checkout validation would have checked with the Master first.

[ON SCREEN: Status tab: all three rows showing "OK".]

All three stores now agree, and you didn't have to touch anything.

## 12. Tips & Best Practices

[ON SCREEN: Title card "Tips & Best Practices", then each tip as a numbered lower-third.]

A few tips from us:

1. **Get your SKUs in order first.** Every product you want synced needs the same SKU on each store, and SKUs need to be unique. Run the duplicate-SKU scan on the Master before you go live.
2. **Run a Reconcile straight after pairing.** It sets up the Global IDs and pushes your starting stock to every Child. Do a dry run first if you'd like to see the differences before anything changes.
3. **Test every pairing.** On the Master, Test Connection checks the first Child in your list, so after adding the others, keep an eye on the Connection health table to make sure each one shows OK.
4. **Exclude local-only stock.** If a Child has products it should manage itself, add those SKUs to Exclusions on the Configuration tab so they're never overwritten.
5. **Choose your checkout strategy on purpose.** High-value or one-of-a-kind stock usually suits fail closed. Fast-moving everyday products usually suit fail open or last-known stock.
6. **Keep Action Scheduler healthy.** That's what drives background delivery and retries, and Site Health will warn you if it isn't available.
7. **Treat shared secrets like passwords**, and rotate them when staff change.

## 13. Recap & Call to Action

[ON SCREEN: Recap montage with quick clips of each tab: Status, Stores, Tools, Conflicts, Logs.]

So that's **Kitgenix Stock Sync for WooCommerce**. You get one Master store as your source of truth and real-time, signed updates to every Child. Version checks stop old updates from rolling stock backwards, the Backlog retries automatically when a store is down, and there's a Conflicts report, a Connection health table, checkout protection, plus WP-CLI and Site Health for the technical side.

[ON SCREEN: WordPress.org plugin page for "Kitgenix Stock Sync for WooCommerce", with the URL kitgenix.com below.]

You can get it free on WordPress.org. Search "Kitgenix Stock Sync" from your Plugins screen. Install it on two stores, pair them, and watch the stock line up.

[ON SCREEN: Subscribe button animation, plus an end-screen card linking to "Kitgenix MultiStore for WooCommerce".]

If you need to sync more than stock, like titles, prices and images, take a look at our sister plugin, MultiStore for WooCommerce. If this video helped, give it a like and subscribe for more WooCommerce guides. And if you have a question about your own multi-store setup, leave it in the comments and we'll get back to you.

Thanks for watching. See you in the next one.

[ON SCREEN: Kitgenix logo outro.]
