<?php
/**
 * Soldx SyncEngine — pushes Magento products to Studio.
 *
 * Equivalent of WC class-sync-engine.php, adapted for Magento's Catalog model.
 * Implements: POST on first sync, PUT on update, 409→PUT / 404→POST fallbacks,
 * sha256 payload-hash for no-op skip, S3 image upload via ApiClient.
 */
declare(strict_types=1);

namespace Soldx\Integration\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface as DirectoryReadInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Soldx\Integration\Model\Exception\SoldxApiException;

class SyncEngine
{
    private const DTO_VERSION = 'v1';
    private const DTO_SOURCE = 'magento';

    /**
     * Cache of already-uploaded S3 image keys per product image path.
     *
     * @var array<string,string>
     */
    private array $imageCache = [];

    /**
     * Cached default sale unit ID from Studio.
     *
     * @var string|null
     */
    private ?string $defaultSaleUnitId = null;

    /**
     * @var Auth
     */
    private Auth $auth;

    /**
     * @var ApiClient
     */
    private ApiClient $apiClient;

    /**
     * @var MappingStore
     */
    private MappingStore $mappingStore;

    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * @var MediaConfig
     */
    private MediaConfig $mediaConfig;

    /**
     * @var CategoryCollectionFactory
     */
    private CategoryCollectionFactory $categoryCollectionFactory;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var Filesystem
     */
    private Filesystem $filesystem;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var DirectoryReadInterface|null
     */
    private ?DirectoryReadInterface $mediaDir = null;

    /**
     * @param Auth $auth
     * @param ApiClient $apiClient
     * @param MappingStore $mappingStore
     * @param ProductRepositoryInterface $productRepository
     * @param MediaConfig $mediaConfig
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param StoreManagerInterface $storeManager
     * @param Filesystem $filesystem
     * @param LoggerInterface $logger
     */
    public function __construct(
        Auth $auth,
        ApiClient $apiClient,
        MappingStore $mappingStore,
        ProductRepositoryInterface $productRepository,
        MediaConfig $mediaConfig,
        CategoryCollectionFactory $categoryCollectionFactory,
        StoreManagerInterface $storeManager,
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->auth = $auth;
        $this->apiClient = $apiClient;
        $this->mappingStore = $mappingStore;
        $this->productRepository = $productRepository;
        $this->mediaConfig = $mediaConfig;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->storeManager = $storeManager;
        $this->filesystem = $filesystem;
        $this->logger = $logger;
    }

    /**
     * Sync a single Magento product to Studio.
     *
     * @param int $productId
     * @param array|null $overrides Optional field overrides (e.g. from manual sync form).
     * @return array Result with keys: success, studio_article_id, action (created|updated|skipped)
     */
    public function syncProduct(int $productId, ?array $overrides = null): array
    {
        try {
            $product = $this->productRepository->getById($productId);
        } catch (\Exception $e) {
            $this->logger->error('Soldx sync: product not found', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error' => 'Product not found: ' . $e->getMessage(),
            ];
        }

        // Check if mapping is disabled
        $mapping = $this->mappingStore->getByProductId($productId);
        if ($mapping && (int) $mapping['is_enabled'] === 0) {
            return [
                'success' => false,
                'error' => 'Sync is disabled for this product.',
            ];
        }

        // Build the DTO (includes hash for Studio + local skip detection)
        $dto = $this->buildDto($product, $overrides);

        // Compute payload hash — skip if unchanged
        $payloadHash = $dto['hash'];
        if ($mapping && ($mapping['payload_hash'] ?? null) === $payloadHash) {
            $this->logger->info('Soldx sync: no changes, skipping', [
                'product_id' => $productId,
            ]);
            return [
                'success' => true,
                'action' => 'skipped',
                'studio_article_id' => $mapping['studio_article_id'],
            ];
        }

        // Decide POST (create) vs PUT (update)
        $studioArticleId = $mapping['studio_article_id'] ?? null;

        try {
            if ($studioArticleId) {
                // PUT uses the externalId (Magento product ID), not the studio article ID
                $response = $this->apiClient->updateProduct($dto['externalId'], $dto);
                $action = 'updated';
            } else {
                $response = $this->apiClient->pushProduct($dto);
                $action = 'created';
                $studioArticleId = $response['idArticle'] ?? null;
            }
        } catch (SoldxApiException $e) {
            // 409 Conflict → product already exists in Studio, try PUT
            if ($e->getStatusCode() === 409 && !$mapping) {
                return $this->handleConflictFallback($dto, $productId, $payloadHash);
            }
            // 404 Not Found → mapping is stale, product was deleted in Studio, try POST
            if ($e->getStatusCode() === 404 && $studioArticleId) {
                return $this->handleNotFoundFallback($dto, $productId, $payloadHash);
            }

            $this->mappingStore->setError($productId, $e->getMessage(), $studioArticleId);
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'http_status' => $e->getStatusCode(),
            ];
        }

        if (!$studioArticleId) {
            $studioArticleId = $response['idArticle'] ?? null;
        }

        if (!$studioArticleId) {
            $this->mappingStore->setError($productId, 'Studio response missing article ID', null);
            return [
                'success' => false,
                'error' => 'Studio response missing article ID',
            ];
        }

        $this->mappingStore->markSynced($productId, $studioArticleId, $payloadHash);

        return [
            'success' => true,
            'action' => $action,
            'studio_article_id' => $studioArticleId,
        ];
    }

    /**
     * Build the WcProductImportDTO-shaped array from a Magento product.
     *
     * @param Product $product
     * @param array|null $overrides
     * @return array
     */
    private function buildDto(Product $product, ?array $overrides = null): array
    {
        $basePrice = (float) $product->getPrice();
        $taxRate = $this->estimateTaxRate($product);
        $discountInfo = $this->getDiscountInfo($product, $basePrice);

        $dto = [
            'source' => self::DTO_SOURCE,
            'sourceVersion' => self::DTO_VERSION,
            'externalId' => (string) $product->getId(),
            'externalSlug' => $product->getSku() ?: null,
            'designation' => $product->getName(),
            'sku' => $product->getSku(),
            'description' => (string) $product->getDescription() ?: (string) $product->getShortDescription(),
            'shortDescription' => (string) $product->getShortDescription(),
            'published' => (int) $product->getStatus() === 1,
            'status' => (int) $product->getStatus() === 1 ? 'active' : 'draft',
            'productType' => $this->mapProductType($product),
            'weight' => (float) $product->getWeight() ?: 0,
            'ean' => (string) ($product->getData('ean') ?? $product->getData('barcode') ?? ''),
            'pricing' => [
                'salePrice' => $basePrice,
                'purchasePrice' => 0,
                'taxRate' => $taxRate,
            ],
            'currency' => $this->getStoreCurrency(),
            'saleUnitId' => $this->getDefaultSaleUnitId(),
            'discountPercent' => $discountInfo['percent'],
            'discountStartDate' => $discountInfo['start'],
            'discountEndDate' => $discountInfo['end'],
            'stockQuantity' => $this->getStockQuantity($product),
            'categoryIds' => $this->resolveCategoryIds($product),
            'media' => $this->resolveMedia($product),
            'gallery' => $this->resolveGallery($product),
            'attributes' => $this->resolveAttributes($product),
            'hash' => '', // filled below
        ];

        if ($overrides) {
            $dto = array_merge($dto, $overrides);
        }

        $dto['hash'] = $this->payloadHash($dto);
        return $dto;
    }

    /**
     * Compute discount info from Magento's special_price attribute.
     *
     * Returns ['percent' => float|null, 'start' => string|null, 'end' => string|null].
     * Only active discounts (within date range) are returned.
     *
     * @param Product $product
     * @param float $basePrice
     * @return array
     */
    private function getDiscountInfo(Product $product, float $basePrice): array
    {
        $empty = ['percent' => null, 'start' => null, 'end' => null];

        $specialPrice = (float) $product->getSpecialPrice();
        if ($specialPrice <= 0 || $specialPrice >= $basePrice) {
            return $empty;
        }

        // Check date validity
        $now = time();
        $start = null;
        $end = null;

        $fromDate = (string) ($product->getSpecialFromDate() ?? '');
        $toDate = (string) ($product->getSpecialToDate() ?? '');

        if ($fromDate !== '' && $fromDate !== '0000-00-00 00:00:00') {
            $fromTs = strtotime($fromDate);
            if ($fromTs && $fromTs > $now) {
                return $empty;
            }
            if ($fromTs) {
                $start = date('c', $fromTs);
            }
        }

        if ($toDate !== '' && $toDate !== '0000-00-00 00:00:00') {
            $toTs = strtotime($toDate);
            if ($toTs && $toTs < $now) {
                return $empty;
            }
            if ($toTs) {
                $end = date('c', $toTs);
            }
        }

        return [
            'percent' => round((1.0 - $specialPrice / $basePrice) * 100, 4),
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * Compute a sha256 hash of the DTO's relevant fields for no-op detection.
     *
     * @param array $dto
     * @return string
     */
    private function payloadHash(array $dto): string
    {
        $fields = [
            'designation', 'description', 'shortDescription', 'pricing',
            'sku', 'status', 'published', 'stockQuantity', 'categoryIds',
            'media', 'gallery', 'productType', 'weight', 'attributes',
            'discountPercent', 'discountStartDate', 'discountEndDate',
            'saleUnitId',
        ];

        $parts = [];
        foreach ($fields as $field) {
            $parts[$field] = $dto[$field] ?? null;
        }

        ksort($parts);
        return hash('sha256', json_encode($parts));
    }

    /**
     * Map Magento product type to Studio product type.
     *
     * @param Product $product
     * @return string
     */
    private function mapProductType(Product $product): string
    {
        $typeId = $product->getTypeId();
        return match ($typeId) {
            'virtual' => 'SERVICE',
            'downloadable' => 'DIGITAL_PRODUCT',
            default => 'PHYSICAL_PRODUCT',
        };
    }

    /**
     * Get the store's base currency code.
     *
     * @return string
     */
    private function getStoreCurrency(): string
    {
        try {
            return $this->storeManager->getStore()->getBaseCurrencyCode();
        } catch (\Exception $e) {
            return 'USD';
        }
    }

    /**
     * Get the default sale unit ID from Studio options (cached).
     *
     * Prefers a unit with reference "U" or designation containing "unit"/"unite".
     * Falls back to the first available unit.
     *
     * @return string
     */
    private function getDefaultSaleUnitId(): string
    {
        if ($this->defaultSaleUnitId !== null) {
            return $this->defaultSaleUnitId;
        }

        try {
            $options = $this->apiClient->getOptions();
            $units = $options['units'] ?? [];

            // Prefer a generic "unit" sale unit
            foreach ($units as $unit) {
                $ref = strtolower((string) ($unit['reference'] ?? ''));
                $designation = strtolower((string) ($unit['designation'] ?? ''));
                if ($ref === 'u' || $ref === 'unit'
                    || str_contains($designation, 'unit')
                    || str_contains($designation, 'unite')
                ) {
                    $this->defaultSaleUnitId = (string) ($unit['id'] ?? '');
                    return $this->defaultSaleUnitId;
                }
            }

            // Fallback: first available unit
            if (!empty($units)) {
                $this->defaultSaleUnitId = (string) ($units[0]['id'] ?? '');
                return $this->defaultSaleUnitId;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Soldx sync: failed to fetch sale units', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->defaultSaleUnitId = '';
        return $this->defaultSaleUnitId;
    }

    /**
     * Estimate the tax rate for a product.
     *
     * @param Product $product
     * @return float
     */
    private function estimateTaxRate(Product $product): float
    {
        $taxClassId = $product->getTaxClassId();
        if (!$taxClassId) {
            return $this->auth->getDefaultTaxRate();
        }

        // Tax classes in Magento don't directly expose the rate.
        // Use the configured default rate as a best-effort estimate.
        return $this->auth->getDefaultTaxRate();
    }

    /**
     * Get the product's stock quantity.
     *
     * @param Product $product
     * @return int
     */
    private function getStockQuantity(Product $product): int
    {
        $qty = $product->getQty();
        if ($qty === null) {
            $stockItem = $product->getExtensionAttributes()?->getStockItem();
            $qty = $stockItem?->getQty();
        }
        return (int) ($qty ?? 0);
    }

    /**
     * Resolve Magento category IDs to Studio category IDs via the mapping.
     *
     * @param Product $product
     * @return array Array of Studio category IDs (deduplicated).
     */
    private function resolveCategoryIds(Product $product): array
    {
        $categoryIds = $product->getCategoryIds();
        if (empty($categoryIds)) {
            return [];
        }

        $map = $this->auth->getCategoryMap();
        if (empty($map)) {
            return [];
        }

        $resolved = [];
        $seen = [];
        foreach ($categoryIds as $catId) {
            $key = (string) $catId;
            if (!isset($map[$key])) {
                continue;
            }
            $studioId = $map[$key];
            if (!isset($seen[$studioId])) {
                $seen[$studioId] = true;
                $resolved[] = $studioId;
            }
        }
        return $resolved;
    }

    /**
     * Resolve the product's main (base) image — upload to S3 and return the key.
     *
     * @param Product $product
     * @return string|null S3 key, or null if no image / upload failed.
     */
    private function resolveMedia(Product $product): ?string
    {
        $mainFile = (string) $product->getImage();
        if ($mainFile === '' || $mainFile === 'no_selection') {
            return null;
        }

        // Return from cache if already uploaded
        if (isset($this->imageCache[$mainFile])) {
            return $this->imageCache[$mainFile];
        }

        $key = $this->uploadImageFile($product, $mainFile);
        if ($key !== null) {
            $this->imageCache[$mainFile] = $key;
        }
        return $key;
    }

    /**
     * Resolve product gallery images (excluding the main image) — upload to S3.
     *
     * @param Product $product
     * @return array Array of S3 key strings.
     */
    private function resolveGallery(Product $product): array
    {
        $galleryKeys = [];
        $mainFile = (string) $product->getImage();

        $gallery = $product->getMediaGalleryImages();
        if ($gallery === null || $gallery->count() === 0) {
            return $galleryKeys;
        }

        foreach ($gallery as $image) {
            $file = $image->getFile();
            if (!$file) {
                continue;
            }

            // Skip the main image (handled by resolveMedia)
            if ($file === $mainFile) {
                continue;
            }

            // Return from cache if already uploaded
            if (isset($this->imageCache[$file])) {
                $galleryKeys[] = $this->imageCache[$file];
                continue;
            }

            $key = $this->uploadImageFile($product, $file);
            if ($key !== null) {
                $this->imageCache[$file] = $key;
                $galleryKeys[] = $key;
            }
        }

        return $galleryKeys;
    }

    /**
     * Upload a single product image file to Studio S3.
     *
     * @param Product $product
     * @param string $file Relative path from catalog/product (e.g. /s/h/img.png)
     * @return string|null S3 key, or null on failure.
     */
    private function uploadImageFile(Product $product, string $file): ?string
    {
        $localPath = $this->getMediaDirectory()->getAbsolutePath('catalog/product' . $file);
        if (!file_exists($localPath)) {
            $this->logger->warning('Soldx sync: image file not found', [
                'product_id' => $product->getId(),
                'file' => $localPath,
            ]);
            return null;
        }

        try {
            $result = $this->apiClient->uploadImage($localPath, basename($file));
            return $result['key'] ?? ($result['Key'] ?? null);
        } catch (SoldxApiException $e) {
            $this->logger->warning('Soldx sync: image upload failed', [
                'product_id' => $product->getId(),
                'file' => $file,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Resolve custom product attributes into a flat key→value map.
     *
     * @param Product $product
     * @return array
     */
    private function resolveAttributes(Product $product): array
    {
        $attributes = [];

        // Only pick up visible custom attributes
        $visibleAttributes = $product->getAttributes();
        foreach ($visibleAttributes as $attribute) {
            if (!$attribute->getIsUserDefined()) {
                continue;
            }
            $code = $attribute->getAttributeCode();
            $value = $product->getData($code);
            if ($value !== null && $value !== '') {
                $label = $attribute->getStoreLabel();
                $attributes[$label ?: $code] = $this->getAttributeDisplayValue($attribute, $value);
            }
        }

        return $attributes;
    }

    /**
     * Get the human-readable value for an attribute (handles select/multiselect).
     *
     * @param \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute
     * @param mixed $value
     * @return string
     */
    private function getAttributeDisplayValue(
        \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute,
        $value
    ): string {
        $frontend = $attribute->getFrontend();
        if ($attribute->getFrontendInput() === 'select'
            || $attribute->getFrontendInput() === 'multiselect'
        ) {
            return (string) $frontend->getOptionText($value);
        }
        return (string) $value;
    }

    /**
     * Handle 409 Conflict — the product already exists in Studio.
     * Try to GET the mapping, then PUT.
     *
     * @param array $dto
     * @param int $productId
     * @param string $payloadHash
     * @return array
     */
    private function handleConflictFallback(array $dto, int $productId, string $payloadHash): array
    {
        try {
            $mapping = $this->apiClient->getMapping($dto['externalId']);
            if (!$mapping) {
                throw new SoldxApiException('Could not resolve mapping after 409 conflict.', 409);
            }

            $studioArticleId = $mapping['idArticle'] ?? '';
            if (!$studioArticleId) {
                throw new SoldxApiException('Mapping response missing studio_article_id.', 500);
            }

            // PUT uses the externalId (Magento product ID), not the studio article ID
            $response = $this->apiClient->updateProduct($dto['externalId'], $dto);
            $this->mappingStore->markSynced($productId, $studioArticleId, $payloadHash);

            return [
                'success' => true,
                'action' => 'updated',
                'studio_article_id' => $studioArticleId,
            ];
        } catch (SoldxApiException $e) {
            $this->mappingStore->setError($productId, '409 fallback failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => '409 fallback failed: ' . $e->getMessage(),
                'http_status' => $e->getStatusCode(),
            ];
        }
    }

    /**
     * Handle 404 Not Found — the mapping is stale, product was deleted in Studio.
     * Retry as POST to create a new article.
     *
     * @param array $dto
     * @param int $productId
     * @param string $payloadHash
     * @return array
     */
    private function handleNotFoundFallback(array $dto, int $productId, string $payloadHash): array
    {
        try {
            $response = $this->apiClient->pushProduct($dto);
            $studioArticleId = $response['idArticle'] ?? null;

            if (!$studioArticleId) {
                throw new SoldxApiException('Studio response missing article ID after 404 fallback.', 500);
            }

            $this->mappingStore->markSynced($productId, $studioArticleId, $payloadHash);

            return [
                'success' => true,
                'action' => 'created',
                'studio_article_id' => $studioArticleId,
            ];
        } catch (SoldxApiException $e) {
            $this->mappingStore->setError($productId, '404 fallback failed: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => '404 fallback failed: ' . $e->getMessage(),
                'http_status' => $e->getStatusCode(),
            ];
        }
    }

    /**
     * Get the media directory reader.
     *
     * @return DirectoryReadInterface
     */
    private function getMediaDirectory(): DirectoryReadInterface
    {
        if ($this->mediaDir === null) {
            $this->mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        }
        return $this->mediaDir;
    }
}
