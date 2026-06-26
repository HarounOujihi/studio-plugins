=== Soldx for WooCommerce ===
Contributors: automato
Tags: woocommerce, sync, inventory, soldx, studio
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Push WooCommerce products into Soldx Studio. Manual selection, per-article units/deposit, category and tag mapping, image sync. No automatic syncing.

== Description ==

This plugin connects a WooCommerce shop to a Soldx Studio account and pushes
products from the shop into Studio (WooCommerce → Studio, one-way only).

* **Manual push only**: pick which products to sync on the Articles page.
  Nothing happens automatically — no cron jobs, no background tasks.
* **Per-article options**: for each product, choose a sale unit (required),
  purchase unit, deposit, and tags from lists pulled from your Studio
  establishment.
* **Category mapping**: map your WooCommerce categories to Studio categories.
  Create Studio categories directly from the mapping page.
* **Tag auto-match**: WooCommerce product tags are auto-matched to Studio
  tags by slug. You can also select tags manually.
* **Image sync**: product images and gallery images are uploaded to Studio.
* **Pricing is synced; stock is intentionally NOT synced.**
* **Read-only**: this plugin never modifies, deletes, or reorders your
  WooCommerce products, orders, or settings.
* **Re-syncing a product updates** the matching Studio article in Studio —
  not in WooCommerce.

= Privacy / data flow =

The plugin reads product data from WooCommerce (name, price, description,
images, SKU, weight, categories, tags) and sends it to your Studio
installation, along with the configured Studio API key. Studio creates or
updates the corresponding Article.

No data is sent anywhere until you manually click "Push to Studio".

== Installation ==

1. Get the API key from Soldx Studio → Settings → Plugins → Activate WooCommerce.
2. Install this plugin via Plugins → Add New → Upload Plugin.
3. Activate.
4. Go to WooCommerce → Soldx Sync. Enter your Studio URL (e.g.
   https://studio.soldx.tn) and paste the API key. Click "Save & Test connection".
5. Go to WooCommerce → Soldx Categories to map your WooCommerce categories
   to Studio categories.
6. Go to WooCommerce → Soldx Articles. Select products, pick a sale unit
   for each, optionally select tags, click "Push selected to Studio".

== Frequently Asked Questions ==

= Is this plugin free? =

Yes. The plugin is free and open-source (GPLv2). A Soldx Studio account is required.

= Does it modify my WooCommerce store? =

No. The plugin is read-only with respect to WooCommerce. It reads product data
and sends it to Studio. It never modifies, deletes, or reorders your products,
orders, or settings.

= Does it sync automatically? =

No. Products are pushed to Studio only when you manually select them and click
"Push selected to Studio". There are no cron jobs, no webhooks, no background tasks.

= Is stock synced? =

No. Stock is intentionally not synced. Only pricing, text fields, images,
categories, and tags are synced.

= What happens when I disconnect? =

Your WooCommerce store is completely unaffected. Products already pushed to
Studio remain there but will no longer be updated from WooCommerce.

== Changelog ==

= 0.1.0 =
* Initial release.
* Settings page with Studio URL + API key configuration and connection test.
* Articles page with paginated product list, search, per-product sale unit /
  purchase unit / deposit selection, tag selection, and manual push.
* Category mapping page with WC → Studio category mapping and inline Studio
  category creation.
* Sync engine: pricing, discounts, text fields, images, categories, tags.
* Read-only: WooCommerce data is never modified.
