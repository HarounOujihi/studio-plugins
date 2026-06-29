.PHONY: build-magento build-woocommerce build-prestashop build-all clean

MAGENTO_DIR    := plugins/soldx-for-magento
WOOCOMMERCE_DIR := plugins/soldx-for-woocommerce
PRESTASHOP_DIR := plugins/soldx-for-prestashop
DIST_DIR       := dist

# Auto-detect version from composer.json / plugin file
MAGENTO_VER    := $(shell grep -oP '"version"\s*:\s*"\K[^"]+' $(MAGENTO_DIR)/composer.json)
WOOCOMMERCE_VER := $(shell grep -oP 'Version:\s*\K[0-9.]+' $(WOOCOMMERCE_DIR)/soldx-for-woocommerce.php)
PRESTASHOP_VER := $(shell grep -oP '<version><!\[CDATA\[\K[^]]+' $(PRESTASHOP_DIR)/config.xml)

build-magento:
	@echo "Building soldx-for-magento v$(MAGENTO_VER)..."
	@mkdir -p $(DIST_DIR)
	@cd $(MAGENTO_DIR) && zip -r ../../$(DIST_DIR)/soldx-for-magento-$(MAGENTO_VER).zip . -x ".git/*"
	@echo "Done: $(DIST_DIR)/soldx-for-magento-$(MAGENTO_VER).zip"

build-woocommerce:
	@echo "Building soldx-for-woocommerce v$(WOOCOMMERCE_VER)..."
	@mkdir -p $(DIST_DIR)
	@cd $(WOOCOMMERCE_DIR) && zip -r ../../$(DIST_DIR)/soldx-for-woocommerce-$(WOOCOMMERCE_VER).zip . -x ".git/*"
	@echo "Done: $(DIST_DIR)/soldx-for-woocommerce-$(WOOCOMMERCE_VER).zip"

build-prestashop:
	@echo "Building soldx-for-prestashop v$(PRESTASHOP_VER)..."
	@mkdir -p $(DIST_DIR)
	@cd $(PRESTASHOP_DIR) && zip -r ../../$(DIST_DIR)/soldx-for-prestashop-$(PRESTASHOP_VER).zip . -x ".git/*"
	@echo "Done: $(DIST_DIR)/soldx-for-prestashop-$(PRESTASHOP_VER).zip"

build-all: build-magento build-woocommerce build-prestashop
	@echo ""
	@echo "All packages built in $(DIST_DIR)/"
	@ls -lh $(DIST_DIR)/*.zip

clean:
	@rm -f $(DIST_DIR)/*.zip
	@echo "Cleaned $(DIST_DIR)/"
