<?php
/**
 * Articles page block — lists ALL Magento products with Studio sync status.
 */
declare(strict_types=1);

namespace Soldx\Integration\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Soldx\Integration\Model\Auth;
use Soldx\Integration\Model\MappingStore;

class Articles extends Template
{
    /**
     * @var ProductCollectionFactory
     */
    private ProductCollectionFactory $productCollectionFactory;

    /**
     * @var CategoryCollectionFactory
     */
    private CategoryCollectionFactory $categoryCollectionFactory;

    /**
     * @var MappingStore
     */
    private MappingStore $mappingStore;

    /**
     * @var Auth
     */
    private Auth $auth;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var array|null
     */
    private ?array $mappingsCache = null;

    /**
     * @var array|null
     */
    private ?array $categoryNamesCache = null;

    /**
     * @param Context $context
     * @param ProductCollectionFactory $productCollectionFactory
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param MappingStore $mappingStore
     * @param Auth $auth
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param array $data
     */
    public function __construct(
        Context $context,
        ProductCollectionFactory $productCollectionFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        MappingStore $mappingStore,
        Auth $auth,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->productCollectionFactory = $productCollectionFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->mappingStore = $mappingStore;
        $this->auth = $auth;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->auth->isConnected();
    }

    /**
     * Get all Magento products with sync status merged.
     *
     * @return array
     */
    public function getProducts(): array
    {
        try {
            $search = (string) $this->getRequest()->getParam('q', '');
            $page = max(1, (int) $this->getRequest()->getParam('p', 1));
            $pageSize = 25;

            $collection = $this->productCollectionFactory->create();
            $collection->addAttributeToSelect([
                'name', 'sku', 'price', 'status', 'image',
                'thumbnail', 'small_image', 'visibility',
            ]);

            if ($search !== '') {
                $collection->addAttributeToFilter([
                    ['attribute' => 'name', 'like' => "%{$search}%"],
                    ['attribute' => 'sku', 'like' => "%{$search}%"],
                ]);
            }

            $collection->setOrder('entity_id', 'DESC');
            $collection->setPageSize($pageSize);
            $collection->setCurPage($page);

            $products = [];
            foreach ($collection as $product) {
                $productId = (int) $product->getId();
                $mapping = $this->getMappingForProduct($productId);

                $status = 'new';
                $studioId = '';
                $lastSync = '';
                $lastError = '';

                if ($mapping) {
                    $status = $mapping['sync_status'] ?? 'pending';
                    $studioId = $mapping['studio_article_id'] ?? '';
                    $lastSync = $mapping['last_sync_at'] ?? '';
                    $lastError = $mapping['last_error'] ?? '';
                }

                $products[] = [
                    'id' => $productId,
                    'name' => (string) $product->getName(),
                    'sku' => (string) $product->getSku(),
                    'price' => (float) $product->getPrice(),
                    'status' => (int) $product->getStatus(),
                    'image_url' => $this->getProductImageUrl($product),
                    'category_names' => $this->getCategoryNames($product->getCategoryIds()),
                    'sync_status' => $status,
                    'studio_article_id' => $studioId,
                    'last_sync' => $lastSync,
                    'last_error' => $lastError,
                    'is_enabled' => $mapping ? (int) ($mapping['is_enabled'] ?? 1) : 1,
                ];
            }

            return $products;
        } catch (\Exception $e) {
            $this->logger->error('Soldx Articles: failed to load products', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get total product count for pagination.
     *
     * @return int
     */
    public function getTotalProducts(): int
    {
        try {
            $search = (string) $this->getRequest()->getParam('q', '');
            $collection = $this->productCollectionFactory->create();

            if ($search !== '') {
                $collection->addAttributeToFilter([
                    ['attribute' => 'name', 'like' => "%{$search}%"],
                    ['attribute' => 'sku', 'like' => "%{$search}%"],
                ]);
            }

            return (int) $collection->getSize();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Current page number.
     *
     * @return int
     */
    public function getCurrentPage(): int
    {
        return max(1, (int) $this->getRequest()->getParam('p', 1));
    }

    /**
     * Page size.
     *
     * @return int
     */
    public function getPageSize(): int
    {
        return 25;
    }

    /**
     * Get dashboard counts.
     *
     * @return array
     */
    public function getCounts(): array
    {
        $synced = $this->mappingStore->countMappings(['sync_status' => 'synced']);
        $errors = $this->mappingStore->countMappings(['sync_status' => 'error']);
        $pending = $this->mappingStore->countMappings(['sync_status' => 'pending']);
        $totalProducts = $this->getTotalProducts();
        $mapped = $synced + $errors + $pending;

        return [
            'synced' => $synced,
            'errors' => $errors,
            'pending' => $pending,
            'new' => max(0, $totalProducts - $mapped),
            'total' => $totalProducts,
        ];
    }

    /**
     * @return string
     */
    public function getSyncUrl(): string
    {
        return $this->getUrl('soldx/articles/sync');
    }

    /**
     * @return string
     */
    public function getToggleUrl(): string
    {
        return $this->getUrl('soldx/articles/toggle');
    }

    /**
     * @return string
     */
    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    /**
     * @return string
     */
    public function getSearchQuery(): string
    {
        return (string) $this->getRequest()->getParam('q', '');
    }

    /**
     * Get mapping for a single product.
     *
     * @param int $productId
     * @return array|null
     */
    private function getMappingForProduct(int $productId): ?array
    {
        if ($this->mappingsCache === null) {
            $this->mappingsCache = [];
            try {
                $mappings = $this->mappingStore->listMappings([], 500);
                foreach ($mappings as $m) {
                    $pid = (int) ($m['magento_product_id'] ?? 0);
                    if ($pid > 0) {
                        $this->mappingsCache[$pid] = $m;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('Soldx Articles: failed to load mappings', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $this->mappingsCache[$productId] ?? null;
    }

    /**
     * Resolve category IDs to names.
     *
     * @param array $categoryIds
     * @return array
     */
    private function getCategoryNames(array $categoryIds): array
    {
        if (empty($categoryIds)) {
            return [];
        }

        if ($this->categoryNamesCache === null) {
            $this->categoryNamesCache = [];
            try {
                $collection = $this->categoryCollectionFactory->create();
                $collection->addAttributeToSelect(['name']);
                foreach ($collection as $cat) {
                    $this->categoryNamesCache[(int) $cat->getId()] = (string) $cat->getName();
                }
            } catch (\Exception $e) {
                $this->logger->error('Soldx Articles: failed to load categories', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $names = [];
        foreach ($categoryIds as $cid) {
            $cid = (int) $cid;
            if ($cid > 1 && isset($this->categoryNamesCache[$cid])) {
                $names[] = $this->categoryNamesCache[$cid];
            }
        }
        return $names;
    }

    /**
     * Get product thumbnail image URL.
     *
     * @param mixed $product
     * @return string
     */
    private function getProductImageUrl($product): string
    {
        try {
            $image = $product->getSmallImage();
            if (!$image || $image === 'no_selection') {
                return '';
            }

            $baseUrl = $this->storeManager->getStore()
                ->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
            return $baseUrl . 'catalog/product' . $image;
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Format price with currency symbol.
     *
     * @param float $price
     * @return string
     */
    public function formatPrice(float $price): string
    {
        try {
            $currencyCode = $this->storeManager->getStore()->getBaseCurrencyCode();
            $symbols = [
                'USD' => '$', 'EUR' => '€', 'GBP' => '£',
                'MAD' => 'DH', 'AED' => 'AED', 'SAR' => 'SAR',
            ];
            $symbol = $symbols[$currencyCode] ?? $currencyCode . ' ';
            return $symbol . number_format($price, 2);
        } catch (\Exception $e) {
            return '$' . number_format($price, 2);
        }
    }
}
