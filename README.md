# Soldx Plugins

Official Soldx Studio integration plugins for major e-commerce platforms.

## Plugins

| Platform | Directory | Status |
|----------|-----------|--------|
| Magento 2 | `plugins/soldx-for-magento` | v1.0.2 |
| WooCommerce | `plugins/soldx-for-woocommerce` | v0.1.0 |
| PrestaShop | `plugins/soldx-for-prestashop` | v0.1.0 |

Each plugin syncs products, categories, and images from the e-commerce platform to Soldx Studio.

## Repository Structure

```
soldx-plugins/
├── plugins/                    # Plugin source code
│   ├── soldx-for-magento/
│   ├── soldx-for-woocommerce/
│   └── soldx-for-prestashop/
├── demo/                       # Docker dev environments
│   ├── magento/                # markshust/docker-magento (separate repo)
│   └── prestashop/             # docker-compose dev setup
├── dist/                       # Built distribution zips (gitignored)
├── Makefile                    # Build automation
└── .gitignore
```

## Building Packages

```bash
# Build a single plugin
make build-magento
make build-woocommerce
make build-prestashop

# Build all
make build-all

# Clean dist/
make clean
```

Zips are output to `dist/`. Version numbers are auto-detected from each plugin's metadata.

## Development

Each plugin has its own README with platform-specific setup instructions:

- [Magento README](plugins/soldx-for-magento/README.md)
- [WooCommerce README](plugins/soldx-for-woocommerce/readme.txt)
- [PrestaShop README](plugins/soldx-for-prestashop/README.md)

## License

MIT
