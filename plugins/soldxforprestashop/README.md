# Soldx for PrestaShop

Push PrestaShop products, categories, and discounts into **Soldx Studio** — manually, with full control over what gets synced.

## Features

- **Product sync** — Push products to Studio with name, description, price, weight, EAN, images, and discounts
- **Discount sync** — PrestaShop specific prices are automatically converted to Studio discounts (percentage or fixed amount)
- **Category mapping** — Map PrestaShop categories to Studio categories with parent/child hierarchy
- **Tag auto-matching** — Studio tags are auto-selected based on matching PrestaShop product tags
- **Read-only** — Nothing is modified in your PrestaShop store. Products are pushed only when you click the button
- **Per-article overrides** — Choose sale unit, purchase unit, deposit, and tags for each product individually
- **Idempotent** — Re-syncing an already-imported product updates it in Studio instead of creating a duplicate

## Requirements

- PrestaShop 1.7.8.0 or later (including 8.x)
- PHP 7.4+ with cURL extension
- A Soldx Studio instance with an API key

## Installation

1. Go to your PrestaShop back office
2. Navigate to **Modules > Module Manager**
3. Click **Upload a module**
4. Select the `soldxforprestashop.zip` file
5. Click **Configure** after installation completes

## Configuration

1. Open the **Soldx** tab in your back office sidebar
2. Go to **Settings**
3. Enter your Studio URL (e.g. `https://studio.soldx.com`) and API key
4. Click **Test connection**
5. Once connected, go to **Categories** to map your PrestaShop categories to Studio categories
6. Go to **Articles** to select and push products

## Usage

### Push products

1. Go to **Soldx > Articles**
2. Select products using the checkboxes
3. Choose sale unit (required), purchase unit, and deposit for each product
4. Optionally toggle tags and publication status
5. Click **Push selected to Studio**

### Sync categories

1. Go to **Soldx > Categories**
2. Select PrestaShop categories to push to Studio
3. Studio categories are created with the same hierarchy
4. Use **Category Mapping** to link existing Studio categories

## How discounts work

When a PrestaShop product has a **specific price** (catalog rule or discount), the module:

1. Reads the base price from `ps_product.price`
2. Computes the effective sale price from the specific price
3. Calculates the discount percentage: `(1 - sale_price / base_price) * 100`
4. Sends `salePrice` (base price), `discountPercent`, and optional date range to Studio
5. Studio creates a `Discount` record linked to the article

Supported specific price types:
- Fixed price reduction
- Percentage reduction
- Date-bounded promotions (from/to dates)

## Support

- Documentation: [https://docs.soldx.com](https://docs.soldx.com)
- Contact: support@soldx.com

## License

GPL-2.0-or-later

## Changelog

### 0.1.0
- Initial release
- Product sync with images, pricing, and discounts
- Category mapping with hierarchy support
- Tag auto-matching
- Specific price / discount sync
