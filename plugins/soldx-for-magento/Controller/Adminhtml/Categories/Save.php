<?php
/**
 * Categories → Save action.
 * Persists the category mapping and syncs category images (best-effort).
 */
declare(strict_types=1);

namespace Soldx\Integration\Controller\Adminhtml\Categories;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;
use Soldx\Integration\Model\ApiClient;
use Soldx\Integration\Model\Auth;
use Soldx\Integration\Model\Exception\SoldxApiException;

class Save extends Action
{
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
     * @param Auth $auth
     * @param ApiClient $apiClient
     * @param CategoryFactory $categoryFactory
     * @param TypeListInterface $cacheTypeList
     * @param Filesystem $filesystem
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        Auth $auth,
        ApiClient $apiClient,
        CategoryFactory $categoryFactory,
        TypeListInterface $cacheTypeList,
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->auth = $auth;
        $this->apiClient = $apiClient;
        $this->categoryFactory = $categoryFactory;
        $this->cacheTypeList = $cacheTypeList;
        $this->filesystem = $filesystem;
        $this->logger = $logger;
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('soldx/categories/index');

        $mapping = $this->getRequest()->getParam('mapping', []);
        if (!is_array($mapping)) {
            $mapping = [];
        }

        // Sanitise: magento_cat_id => studio_cat_id
        $clean = [];
        foreach ($mapping as $catId => $studioCatId) {
            $catId = (int) $catId;
            $studioCatId = (string) $studioCatId;
            if ($catId > 1 && $studioCatId !== '') {
                $clean[(string) $catId] = $studioCatId;
            }
        }

        $this->auth->saveCategoryMap($clean);
        $this->cacheTypeList->cleanType('config');

        // Best-effort category image sync
        $imagesSynced = 0;
        foreach ($clean as $catIdStr => $studioCatId) {
            $imageKey = $this->uploadCategoryImage((int) $catIdStr);
            if ($imageKey !== '') {
                try {
                    $this->apiClient->updateCategoryImage($studioCatId, $imageKey);
                    $imagesSynced++;
                } catch (SoldxApiException $e) {
                    $this->logger->warning('Soldx Categories: image PATCH failed', [
                        'category_id' => $catIdStr,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $msg = __('%1 category mapping(s) saved.', count($clean));
        if ($imagesSynced > 0) {
            $msg = __('%1 category mapping(s) saved. %2 category image(s) synced.', count($clean), $imagesSynced);
        }
        $this->messageManager->addSuccessMessage($msg);

        return $redirect;
    }

    /**
     * Upload a Magento category image to Studio S3 (best-effort).
     *
     * @param int $categoryId
     * @return string S3 key, or '' on failure.
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
            $this->logger->warning('Soldx Categories: image upload failed', [
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
            ]);
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
