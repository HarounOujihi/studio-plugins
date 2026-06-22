=== Soldx for WooCommerce ===
Contributors: soldx
Tags: woocommerce, sync, inventory, soldx, studio
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Push WooCommerce products into Soldx Studio. Manual selection, per-article
units/deposit, no auto-sync.

== Description ==

This plugin connects a WooCommerce shop to a Soldx Studio account and pushes
products from the shop into Studio (WooCommerce → Studio, one-way).

* Manual push: pick which products to sync on the Articles page.
* Per-article options: for each product, choose a sale unit (required),
  purchase unit, and deposit from lists pulled from Studio.
* Pricing is synced; stock is intentionally NOT synced.
* Re-syncing a product updates the matching Studio article.
* Future-proof: built on top of a generic Studio integrations API, so the
  same plugin shape can later target Prestashop, Shopify, etc.

= Privacy / data flow =

The plugin reads product data from WooCommerce (name, price, description,
images, SKU, weight) and sends it to your Studio installation, along with
the configured Studio API key. Studio creates or updates the corresponding
Article.

== Installation ==

1. Get the API key from Soldx Studio → Settings → Plugins → Activate WooCommerce.
2. Install this plugin via Plugins → Add New → Upload.
3. Activate.
4. Go to WooCommerce → Soldx Sync → Settings. Enter your Studio URL (e.g.
   https://studio.soldx.tn) and paste the API key. Click "Test connection".
5. Go to WooCommerce → Soldx Sync → Articles. Select products, pick a sale
   unit for each, click "Push selected to Studio".

== Changelog ==

= 0.1.0 =
* Initial release: settings page, articles selection, manual sync (text fields,
  pricing, discounts, categories).
