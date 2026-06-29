# Soldx Plugins

Connect your e-commerce store to [Soldx Studio](https://soldx.com) — sync products, categories, pricing, and images automatically.

## What is Soldx Studio?

Soldx Studio is a multi-channel commerce platform that lets you manage your catalog across sales channels from a single dashboard. These plugins push your store's product data (designation, pricing, stock, images, categories) to Studio in real time.

## Available Plugins

| Platform | Install | Version |
|----------|---------|---------|
| **Magento 2** | [Marketplace](https://commermarketplace.adobe.com) or `composer require soldx/module-integration` | 1.0.2 |
| **WooCommerce** | [WordPress.org](https://wordpress.org/plugins/) or upload zip | 0.1.0 |
| **PrestaShop** | Upload zip via Back Office > Modules | 0.1.0 |

## Quick Start

1. Create an integration in **Soldx Studio > Settings > Integrations** — note the API key
2. Install the plugin for your platform (see links above)
3. Enter your Studio URL and API key in the plugin settings
4. Click **Connect** — your store is now linked to Studio

## Features

- **Product sync** — push products to Studio automatically on save (Magento) or manually (WooCommerce, PrestaShop)
- **Category mapping** — map your store categories to Studio categories with a visual tree UI
- **Batch category creation** — create all unmapped categories in Studio with one click
- **Image upload** — product images are uploaded to Studio S3 automatically
- **Smart change detection** — only modified products are synced (hash-based, Magento)
- **Discount support** — sync special prices as discounts with date ranges
- **Per-product control** — enable/disable sync for individual products

## Build from Source

```bash
git clone https://github.com/soldx/soldx-plugins.git
cd soldx-plugins

# Build all distribution zips
make build-all

# Or build a single plugin
make build-magento
make build-woocommerce
make build-prestashop
```

Zips are output to `dist/`. Version numbers are auto-detected from each plugin's metadata.

## Repository Structure

```
soldx-plugins/
├── plugins/
│   ├── soldx-for-magento/       # Magento 2 module
│   ├── soldx-for-woocommerce/   # WordPress/WooCommerce plugin
│   └── soldx-for-prestashop/    # PrestaShop module
├── dist/                        # Built zips (gitignored)
└── Makefile                     # Build automation
```

Each plugin has its own README with platform-specific setup instructions:

- [Magento — Installation & User Guide](plugins/soldx-for-magento/README.md)
- [WooCommerce — Setup Guide](plugins/soldx-for-woocommerce/readme.txt)
- [PrestaShop — Setup Guide](plugins/soldx-for-prestashop/README.md)

## Requirements

| Platform | Version | PHP |
|----------|---------|-----|
| Magento 2 | 2.4.5+ | 8.2 / 8.3 / 8.4 / 8.5 |
| WooCommerce | WP 6.0+ + WC 7.0+ | 7.4+ |
| PrestaShop | 1.7.8+ / 8.x | 7.4+ |

## Support

- Website: [soldx.com](https://soldx.com)
- Email: support@soldx.com

## License

MIT
