# Soldx Studio Integration for Magento 2

Sync your Magento 2 catalog to [Soldx Studio](https://soldx.com) — products, categories, and images — automatically or on demand.

## Features

- **Automatic product sync** — products are pushed to Studio when saved in Magento admin
- **Category mapping** — map Magento categories to Studio categories with a drag-and-drop tree UI
- **Batch category creation** — create all unmapped Magento categories in Studio with one click
- **Image upload** — product and category images are uploaded to Studio S3 automatically
- **Hash-based change detection** — only modified products are synced, reducing API calls
- **Manual sync toggle** — enable/disable auto-sync per product from the Articles grid
- **Discount support** — sync special prices as discounts with start/end dates
- **Tax rate support** — configurable default tax rate for accurate pricing in Studio

## Requirements

- Magento 2.4.5+ (Open Source or Commerce)
- PHP 8.1, 8.2, or 8.3
- Active Soldx Studio account with API key

## Installation

### Via Composer (recommended)

```bash
composer require soldx/module-integration
bin/magento module:enable Soldx_Integration
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Via Zip (manual)

1. Download the release zip
2. Extract to `app/code/Soldx/Integration/`
3. Run:

```bash
bin/magento module:enable Soldx_Integration
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## Configuration

1. Go to **Stores > Configuration > Soldx > Soldx Studio Integration**
2. Enter your **Studio URL** and **API Key** (from Studio > Settings > Integrations)
3. Click **Save Config**
4. Go to **Soldx Studio > Settings** and click **Connect**

## Usage

### Articles (Products)

Navigate to **Soldx Studio > Articles** to see all Magento products and their sync status. Toggle auto-sync per product or trigger a manual sync.

### Categories

Navigate to **Soldx Studio > Categories** to map Magento categories to Studio categories. Use the search to filter, map individual categories via dropdown, or click **Create All Unmapped in Studio** for batch creation.

## Support

For support, contact support@soldx.com or visit https://soldx.com/support

## License

Proprietary - (c) Soldx. All rights reserved.
