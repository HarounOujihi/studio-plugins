<?php
/**
 * Categories → Create action (AJAX).
 * Creates a category in Studio from a Magento category, then updates
 * the local mapping. Supports auto-parent-resolution.
 */
declare(strict_types=1);

namespace Soldx\Integration\Controller\Adminhtml\Categories;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;
use Soldx\Integration\Model\ApiClient;
use Soldx\Integration\Model\Auth;
use Soldx\Integration\Model\Exception\SoldxApiException;

class Create extends Action
{
    /**
     * @var JsonFactory
     */
    private JsonFactory $resultJsonFactory;

    /**
     * @var Auth
     */
    private Auth $auth;

    /**
     * @var ApiClient
     */
    private ApiClient $apiClient;

    /**
     * @var CategoryFactory
     */
    private CategoryFactory $categoryFactory;

    /**
     * @var TypeListInterface
     */
    private TypeListInterface $cacheTypeList;

    /**
     * @var Filesystem
     */
    private Filesystem $filesystem;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Auth $auth
     * @param ApiClient $apiClient
     * @param CategoryFactory $categoryFactory
     * @param TypeListInterface $cacheTypeList
     * @param Filesystem $filesystem
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Auth $auth,
        ApiClient $apiClient,
        CategoryFactory $categoryFactory,
        TypeListInterface $cacheTypeList,
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->auth = $auth;
        $this->apiClient = $apiClient;
        $this->categoryFactory = $categoryFactory;
        $this->cacheTypeList = $cacheTypeList;
        $this->filesystem = $filesystem;
        $this->logger = $logger;
    }

    /**
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();

        $designation = (string) $this->getRequest()->getParam('designation', '');
        $explicitParent = (string) $this->getRequest()->getParam('idParent', '');
        $categoryId = (int) $this->getRequest()->getParam('categoryId', 0);

        if ($designation === '') {
            return $result->setData([
                'success' => false,
                'message' => __('Designation is required.'),
            ]);
        }

        try {
            // Auto-resolve parent: if no explicit parent, check if the Magento
            // category's parent is mapped, and use that mapped Studio ID.
            $parentId = $explicitParent;
            if ($parentId === '' && $categoryId > 0) {
                $category = $this->categoryFactory->create()->load($categoryId);
                $magentoParentId = (int) $category->getParentId();
                if ($magentoParentId > 1) {
                    $map = $this->auth->getCategoryMap();
                    $parentId = $map[(string) $magentoParentId] ?? '';
                }
            }

            // Validate that the parent exists in Studio
            if ($parentId !== '') {
                $parentId = $this->validateStudioParent($parentId);
            }

            // Upload category image (best-effort)
            $imageKey = '';
            if ($categoryId > 0) {
                $imageKey = $this->uploadCategoryImage($categoryId);
            }

            // Create the category in Studio
            $data = [
                'designation' => $designation,
            ];
            if ($parentId !== '') {
                $data['idParent'] = $parentId;
            }
            if ($imageKey !== '') {
                $data['image'] = $imageKey;
            }

            $response = $this->apiClient->createCategory($data);
            $studioCatId = $response['id'] ?? ($response['idCategory'] ?? '');

            if ($studioCatId === '') {
                throw new SoldxApiException('Studio response missing category ID.');
            }

            // Update the mapping immediately so subsequent creates can resolve the parent
            if ($categoryId > 0) {
                $this->auth->updateCategoryMappingEntry($categoryId, (string) $studioCatId);
                $this->cacheTypeList->cleanType('config');
            }

            return $result->setData([
                'success' => true,
                'category' => [
                    'id' => $studioCatId,
                    'designation' => $designation,
                    'idParent' => $parentId,
                ],
            ]);
        } catch (SoldxApiException $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Soldx Categories: create failed', [
                'error' => $e->getMessage(),
            ]);
            return $result->setData([
                'success' => false,
                'message' => __('An unexpected error occurred.'),
            ]);
        }
    }

    /**
     * Validate that the parent ID exists in Studio.
     * If not (stale mapping), return '' to create at root level.
     *
     * @param string $parentId
     * @return string
     */
    private function validateStudioParent(string $parentId): string
    {
        try {
            $options = $this->apiClient->getOptions();
            $studioCats = $options['categories'] ?? [];
            foreach ($studioCats as $cat) {
                if ((string) ($cat['id'] ?? '') === $parentId) {
                    return $parentId;
                }
            }
            return '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Upload a Magento category image to Studio S3 (best-effort).
     *
     * @param int $categoryId
     * @return string
     */
    private function uploadCategoryImage(int $categoryId): string
    {
        try {
            $category = $this->categoryFactory->create()->load($categoryId);
            $image = $category->getImage();
            if (!$image) {
                return '';
            }

            // Magento stores category image paths in various formats
            // (/media/catalog/category/x.png, /s/h/x.png, x.png) — extract filename
            $filename = basename($image);
            $mediaPath = $this->filesystem->getDirectoryRead(
                \Magento\Framework\App\Filesystem\DirectoryList::MEDIA
            )->getAbsolutePath('catalog/category/' . $filename);

            if (!file_exists($mediaPath)) {
                return '';
            }

            $result = $this->apiClient->uploadImage($mediaPath, 'cat-' . $categoryId . '.jpg');
            return $result['key'] ?? '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Soldx_Integration::categories');
    }
}
