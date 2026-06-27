<?php
/**
 * Articles → Sync action.
 * Manually triggers a sync for a specific product.
 */
declare(strict_types=1);

namespace Soldx\Integration\Controller\Adminhtml\Articles;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Soldx\Integration\Model\SyncEngine;

class Sync extends Action
{
    /**
     * @var SyncEngine
     */
    private SyncEngine $syncEngine;

    /**
     * @var JsonFactory
     */
    private JsonFactory $jsonFactory;

    /**
     * @param Context $context
     * @param SyncEngine $syncEngine
     * @param JsonFactory $jsonFactory
     */
    public function __construct(
        Context $context,
        SyncEngine $syncEngine,
        JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
        $this->syncEngine = $syncEngine;
        $this->jsonFactory = $jsonFactory;
    }

    /**
     * @return Json
     */
    public function execute(): Json
    {
        $productId = (int) $this->getRequest()->getParam('product_id');
        $result = $this->jsonFactory->create();

        if ($productId === 0) {
            return $result->setData([
                'success' => false,
                'message' => 'Product ID is required.',
            ]);
        }

        $syncResult = $this->syncEngine->syncProduct($productId);

        return $result->setData($syncResult);
    }

    /**
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Soldx_Integration::articles');
    }
}
