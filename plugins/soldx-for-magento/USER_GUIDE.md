# Soldx Studio Integration - User Guide & Installation Manual

**Version 1.0.0**
**Compatible with Magento 2.4.5+ (Open Source and Commerce)**

---

## Table of Contents

1. [Overview](#1-overview)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [Configuration](#4-configuration)
5. [Using the Articles Page](#5-using-the-articles-page)
6. [Using the Categories Page](#6-using-the-categories-page)
7. [Automatic Product Sync](#7-automatic-product-sync)
8. [Troubleshooting](#8-troubleshooting)

---

## 1. Overview

The Soldx Studio Integration extension connects your Magento 2 store to Soldx Studio, enabling real-time synchronization of products, categories, and images. This allows you to manage your catalog across multiple sales channels from a single Studio dashboard.

### Key Features

- **Real-time product sync** - Products are automatically pushed to Studio when saved
- **Category mapping** - Visually map Magento categories to Studio categories
- **Batch category creation** - Create all unmapped categories in Studio with one click
- **Automatic image upload** - Product and category images are uploaded to Studio S3
- **Smart change detection** - Only modified products are synced (hash-based)
- **Per-product sync control** - Enable or disable auto-sync for individual products
- **Discount and special price support** - Sync special prices as discounts with date ranges

---

## 2. Requirements

- Magento 2.4.5 or higher (Open Source or Commerce)
- PHP 8.1, 8.2, or 8.3
- MySQL 8.0+
- Active Soldx Studio account with API credentials
- Cron configured (for background processes)

---

## 3. Installation

### Method A: Install via Composer (Recommended)

```bash
composer require soldx/module-integration
bin/magento module:enable Soldx_Integration
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Method B: Install via ZIP (Manual)

1. Download the extension ZIP file
2. Extract the contents to `app/code/Soldx/Integration/` in your Magento root directory
3. Run the following commands from your Magento root:

```bash
bin/magento module:enable Soldx_Integration
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Verify Installation

Go to **Stores > Configuration > Soldx**. If the Soldx tab appears, the extension is installed correctly.

### Uninstallation

```bash
bin/magento module:disable Soldx_Integration
bin/magento setup:upgrade
composer remove soldx/module-integration
bin/magento cache:flush
```

---

## 4. Configuration

### Step 1: Get Your API Credentials from Studio

1. Log in to your Soldx Studio dashboard
2. Navigate to **Settings > Integrations**
3. Create a new Magento integration or copy the existing one
4. Note the **Studio URL** and **API Key** (64-character hex string)

### Step 2: Configure Magento

1. In Magento admin, go to **Stores > Configuration > Soldx > Soldx Studio Integration**
2. In the **Connection** section:
   - **Studio URL**: Enter your Studio base URL (e.g., `https://studio.soldx.com`)
   - **API Key**: Paste your 64-character API key
3. Click **Save Config**

### Step 3: Connect

1. Go to **Soldx Studio > Settings** in the Magento admin menu
2. Click the **Connect** button
3. The page will display your Integration ID, Integration Name, and connection status
4. If the status shows "Connected", you are ready to sync

### Step 4: Optional Settings

In **Stores > Configuration > Soldx > Soldx Studio Integration > Synchronisation**:

- **Automatic Sync**: Set to "Yes" to push products to Studio automatically when saved (default: No)
- **Default Tax Rate (%)**: Enter a fallback tax rate used when a product's tax class cannot be determined (default: 0)

---

## 5. Using the Articles Page

Navigate to **Soldx Studio > Articles** to manage product synchronization.

### Understanding the Grid

| Column | Description |
|--------|-------------|
| **Product** | Magento product name and thumbnail |
| **SKU** | Magento product SKU |
| **Studio Article** | Studio article designation (if synced) |
| **Sync Status** | Pending, Synced, or Error |
| **Auto-Sync** | Toggle switch to enable/disable sync per product |
| **Last Sync** | Timestamp of last successful sync |

### Actions

- **Toggle Auto-Sync**: Click the toggle switch in the Auto-Sync column to enable or disable sync for a specific product
- **Manual Sync**: Click the **Sync** button next to a product to push it to Studio immediately
- **Bulk Sync**: Select multiple products and use the mass action to sync them all

### Sync Statuses

- **Pending**: Product has not been synced to Studio yet
- **Synced**: Product has been successfully pushed to Studio
- **Error**: Last sync attempt failed (hover to see the error message)

---

## 6. Using the Categories Page

Navigate to **Soldx Studio > Categories** to map Magento categories to Studio categories.

### Dashboard

At the top of the page, three stat cards show:
- **Total**: Total number of Magento categories
- **Mapped**: Categories linked to a Studio category
- **Unmapped**: Categories not yet linked to Studio

### Mapping a Single Category

1. Find the category in the tree (use the search bar to filter)
2. Select a Studio category from the dropdown on the right
3. Click **Save Mappings** at the bottom of the page

### Creating a Category in Studio

1. Click the **+ Studio** button next to any unmapped category
2. The category will be created in Studio and automatically mapped
3. If the Magento parent category is already mapped, the Studio parent will be set automatically

### Batch Create All Unmapped Categories

Click **Create All Unmapped in Studio** at the top right to create all unmapped Magento categories in Studio in one operation. Categories are processed sequentially, with parent categories created first.

### Searching

Type in the search bar to filter categories by name or path. Parent categories of matching results are automatically expanded and shown.

---

## 7. Automatic Product Sync

When **Automatic Sync** is enabled:

1. Every time a product is saved in Magento admin, the extension checks if the product data has changed (using a SHA-256 hash)
2. If the data has changed, the product is pushed to Studio via the API
3. If the product already exists in Studio, it is updated; otherwise, it is created
4. Images are uploaded to Studio S3 storage automatically

### What Gets Synced

| Field | Source |
|-------|--------|
| Designation | Product name |
| Description | Full description |
| Short description | Short description |
| SKU | Product SKU |
| Price | Final price (including discounts) |
| Stock quantity | Inventory qty |
| Status | Enabled/disabled |
| Categories | Mapped category IDs |
| Main image | Base product image |
| Gallery | Additional product images |
| Weight | Product weight |
| Discount | Special price percentage with date range |
| Sale unit | Configurable sale unit |
| Tax rate | Product tax class or default rate |

---

## 8. Troubleshooting

### Connection Fails

- Verify the Studio URL is correct and accessible from your server
- Ensure the API Key is exactly 64 characters
- Check that your server's firewall allows outbound HTTPS to your Studio domain
- Try flushing the Magento cache: `bin/magento cache:flush`

### Products Not Syncing

- Ensure **Automatic Sync** is enabled in configuration, OR use the manual Sync button
- Check that the product's **Auto-Sync** toggle is enabled on the Articles page
- Review the `var/log/exception.log` and `var/log/system.log` for error messages
- Verify the product has a valid name and SKU

### Images Not Appearing in Studio

- Ensure the product has a base image assigned
- Verify image files exist in `pub/media/catalog/product/`
- Check that file permissions allow the web server to read image files
- Review Magento logs for S3 upload errors

### Category Mapping Not Saving

- Ensure you have the correct ACL permissions (**Soldx Studio > Categories**)
- Try clearing the config cache: `bin/magento cache:clean config`

### Getting Support

- Email: support@soldx.com
- Include your Magento version, extension version, and relevant log entries

---

## License

Proprietary - (c) Soldx. All rights reserved.
