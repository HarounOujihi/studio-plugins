<?php
/**
 * Articles → Toggle action.
 * Enables or disables auto-sync for a specific product mapping.
 */
declare(strict_types=1);

namespace Soldx\Integration\Controller\Adminhtml\Articles;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Soldx\Integration\Model\MappingStore;

class Toggle extends Action
{
    /**
     * @var MappingStore
     */
    private MappingStore $mappingStore;

    /**
     * @var JsonFactory
     */
    private JsonFactory $jsonFactory;

    /**
     * @param Context $context
     * @param MappingStore $mappingStore
     * @param JsonFactory $jsonFactory
     */
    public function __construct(
        Context $context,
        MappingStore $mappingStore,
        JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
        $this->mappingStore = $mappingStore;
        $this->jsonFactory = $jsonFactory;
    }

    /**
     * @return Json
     */
    public function execute(): Json
    {
        $productId = (int) $this->getRequest()->getParam('product_id');
        $enabled = (bool) $this->getRequest()->getParam('enabled', true);
        $result = $this->jsonFactory->create();

        if ($productId === 0) {
            return $result->setData([
                'success' => false,
                'message' => 'Product ID is required.',
            ]);
        }

        $this->mappingStore->setEnabled($productId, $enabled);

        return $result->setData([
            'success' => true,
            'is_enabled' => $enabled,
        ]);
    }

    /**
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Soldx_Integration::articles');
    }
}
