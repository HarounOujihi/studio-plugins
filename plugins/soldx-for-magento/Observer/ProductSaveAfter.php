<?php
/**
 * Observer: auto-sync product to Studio after save.
 *
 * Only fires when auto_sync is enabled in config AND the product
 * already has a mapping (or auto_sync is enabled for new products).
 */
declare(strict_types=1);

namespace Soldx\Integration\Observer;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Soldx\Integration\Model\Auth;
use Soldx\Integration\Model\MappingStore;
use Soldx\Integration\Model\SyncEngine;

class ProductSaveAfter implements ObserverInterface
{
    /**
     * @var Auth
     */
    private Auth $auth;

    /**
     * @var SyncEngine
     */
    private SyncEngine $syncEngine;

    /**
     * @var MappingStore
     */
    private MappingStore $mappingStore;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Auth $auth
     * @param SyncEngine $syncEngine
     * @param MappingStore $mappingStore
     * @param LoggerInterface $logger
     */
    public function __construct(
        Auth $auth,
        SyncEngine $syncEngine,
        MappingStore $mappingStore,
        LoggerInterface $logger
    ) {
        $this->auth = $auth;
        $this->syncEngine = $syncEngine;
        $this->mappingStore = $mappingStore;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        // Bail out if auto-sync is disabled or not connected
        if (!$this->auth->isConnected() || !$this->auth->isAutoSyncEnabled()) {
            return;
        }

        /** @var ProductInterface $product */
        $product = $observer->getEvent()->getProduct();
        if (!$product || !$product->getId()) {
            return;
        }

        $productId = (int) $product->getId();

        // Check if this product has a mapping — only auto-sync mapped products
        $mapping = $this->mappingStore->getByProductId($productId);
        if (!$mapping) {
            return; // Not synced yet, don't auto-create
        }

        // Skip if disabled
        if ((int) ($mapping['is_enabled'] ?? 1) === 0) {
            return;
        }

        try {
            $result = $this->syncEngine->syncProduct($productId);
            if (!$result['success'] ?? false) {
                $this->logger->warning('Soldx auto-sync failed', [
                    'product_id' => $productId,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Soldx auto-sync exception', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
