<?php
/**
 * Categories page block — loads Magento categories as a tree,
 * Studio categories, and the persisted mapping for the template.
 */
declare(strict_types=1);

namespace Soldx\Integration\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Soldx\Integration\Model\ApiClient;
use Soldx\Integration\Model\Auth;

class Categories extends Template
{
    /**
     * @var CategoryCollectionFactory
     */
    private CategoryCollectionFactory $categoryCollectionFactory;

    /**
     * @var ApiClient
     */
    private ApiClient $apiClient;

    /**
     * @var Auth
     */
    private Auth $auth;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var TypeListInterface
     */
    private TypeListInterface $cacheTypeList;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var array|null
     */
    private ?array $studioOptions = null;

    /**
     * @var array|null
     */
    private ?array $flatCategories = null;

    /**
     * @param Context $context
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param ApiClient $apiClient
     * @param Auth $auth
     * @param LoggerInterface $logger
     * @param TypeListInterface $cacheTypeList
     * @param StoreManagerInterface $storeManager
     * @param array $data
     */
    public function __construct(
        Context $context,
        CategoryCollectionFactory $categoryCollectionFactory,
        ApiClient $apiClient,
        Auth $auth,
        LoggerInterface $logger,
        TypeListInterface $cacheTypeList,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->apiClient = $apiClient;
        $this->auth = $auth;
        $this->logger = $logger;
        $this->cacheTypeList = $cacheTypeList;
        $this->storeManager = $storeManager;
    }

    /**
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->auth->isConnected();
    }

    /**
     * Get all Magento categories as a flat list (cached).
     *
     * @return array
     */
    public function getMagentoCategories(): array
    {
        if ($this->flatCategories !== null) {
            return $this->flatCategories;
        }

        try {
            $collection = $this->categoryCollectionFactory->create();
            $collection->addAttributeToSelect(['name', 'url_key', 'image'])
                ->addAttributeToFilter('is_active', 1)
                ->addAttributeToFilter('entity_id', ['gt' => 1])
                ->setOrder('level', 'ASC')
                ->setOrder('position', 'ASC');

            $categories = [];
            foreach ($collection as $category) {
                $id = (int) $category->getId();
                $categories[$id] = [
                    'id' => $id,
                    'name' => (string) $category->getName(),
                    'url_key' => (string) $category->getUrlKey(),
                    'level' => (int) $category->getLevel(),
                    'parent_id' => (int) $category->getParentId(),
                    'image' => (string) $category->getImage(),
                    'image_url' => $this->getCategoryImageUrl((string) $category->getImage()),
                    'children' => [],
                ];
            }

            $this->flatCategories = $categories;
            return $categories;
        } catch (\Exception $e) {
            $this->logger->error('Soldx Categories: failed to load Magento categories', [
                'error' => $e->getMessage(),
            ]);
            $this->flatCategories = [];
            return [];
        }
    }

    /**
     * Build a nested tree of Magento categories.
     * Each node has: id, name, level, parent_id, image, image_url, path (breadcrumb), children[].
     *
     * @return array
     */
    public function getCategoryTree(): array
    {
        $flat = $this->getMagentoCategories();
        if (empty($flat)) {
            return [];
        }

        // Group by parent
        $byParent = [];
        foreach ($flat as $cat) {
            $pid = $cat['parent_id'];
            if (!isset($byParent[$pid])) {
                $byParent[$pid] = [];
            }
            $byParent[$pid][] = $cat;
        }

        // Build tree recursively starting from root categories (parent_id = 1 or 0)
        $roots = array_merge($byParent[1] ?? [], $byParent[0] ?? []);
        return $this->buildTree($roots, $byParent, '');
    }

    /**
     * Recursively build tree nodes with breadcrumb paths.
     *
     * @param array $nodes
     * @param array $byParent
     * @param string $parentPath
     * @return array
     */
    private function buildTree(array $nodes, array $byParent, string $parentPath): array
    {
        $tree = [];
        foreach ($nodes as $node) {
            $path = $parentPath !== '' ? $parentPath . ' › ' . $node['name'] : $node['name'];
            $node['path'] = $path;

            $children = $byParent[$node['id']] ?? [];
            $node['children'] = $this->buildTree($children, $byParent, $path);
            $node['has_children'] = !empty($node['children']);

            $tree[] = $node;
        }
        return $tree;
    }

    /**
     * Get Studio categories from the /api/plugin/options endpoint.
     *
     * @return array
     */
    public function getStudioCategories(): array
    {
        if ($this->studioOptions === null) {
            try {
                $options = $this->apiClient->getOptions();
                $this->studioOptions = $options['categories'] ?? [];
            } catch (\Exception $e) {
                $this->logger->error('Soldx Categories: failed to fetch Studio options', [
                    'error' => $e->getMessage(),
                ]);
                $this->studioOptions = [];
            }
        }
        return $this->studioOptions;
    }

    /**
     * @var array|null
     */
    private ?array $validCategoryMap = null;

    /**
     * Get the saved category mapping, filtered to only include entries
     * where the Studio category still exists. Stale mappings (pointing
     * to deleted Studio categories) are automatically removed from config.
     *
     * @return array
     */
    public function getCategoryMap(): array
    {
        if ($this->validCategoryMap !== null) {
            return $this->validCategoryMap;
        }

        $rawMap = $this->auth->getCategoryMap();
        $studioCats = $this->getStudioCategories();

        // If Studio categories couldn't be fetched (API error), return raw map
        // to avoid accidentally clearing mappings on network issues.
        if (empty($studioCats)) {
            $this->validCategoryMap = $rawMap;
            return $this->validCategoryMap;
        }

        // Build set of valid Studio category IDs
        $validStudioIds = [];
        foreach ($studioCats as $cat) {
            $id = (string) ($cat['id'] ?? '');
            if ($id !== '') {
                $validStudioIds[$id] = true;
            }
        }

        // Filter out stale mappings
        $clean = [];
        $hadStale = false;
        foreach ($rawMap as $magentoId => $studioId) {
            if (isset($validStudioIds[$studioId])) {
                $clean[$magentoId] = $studioId;
            } else {
                $hadStale = true;
            }
        }

        // Auto-clean stale entries from config so they don't linger
        if ($hadStale) {
            $this->auth->saveCategoryMap($clean);
            $this->cacheTypeList->cleanType('config');
        }

        $this->validCategoryMap = $clean;
        return $this->validCategoryMap;
    }

    /**
     * Dashboard counts: total, mapped, unmapped.
     *
     * @return array
     */
    public function getCategoryCounts(): array
    {
        $flat = $this->getMagentoCategories();
        $map = $this->getCategoryMap();
        $total = count($flat);
        $mapped = 0;
        $withImage = 0;

        foreach ($flat as $cat) {
            $id = (string) $cat['id'];
            if (isset($map[$id]) && $map[$id] !== '') {
                $mapped++;
            }
            if ($cat['image'] !== '' && $cat['image'] !== 'no_selection') {
                $withImage++;
            }
        }

        return [
            'total' => $total,
            'mapped' => $mapped,
            'unmapped' => $total - $mapped,
            'with_image' => $withImage,
        ];
    }

    /**
     * Resolve a category image attribute to a full media URL.
     *
     * @param string $image
     * @return string
     */
    private function getCategoryImageUrl(string $image): string
    {
        if ($image === '' || $image === 'no_selection') {
            return '';
        }

        try {
            // Handle full URLs
            if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
                return $image;
            }

            // Magento stores category images with various path formats:
            //   /media/catalog/category/shop-cover.png
            //   /s/h/shop-cover.png
            //   shop-cover.png
            // Extract just the filename and build the URL from the media base.
            $filename = basename($image);
            $baseUrl = $this->storeManager->getStore()
                ->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
            return $baseUrl . 'catalog/category/' . $filename;
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * @return string
     */
    public function getSaveUrl(): string
    {
        return $this->getUrl('soldx/categories/save');
    }

    /**
     * @return string
     */
    public function getCreateAjaxUrl(): string
    {
        return $this->getUrl('soldx/categories/create');
    }

    /**
     * @return string
     */
    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }
}
