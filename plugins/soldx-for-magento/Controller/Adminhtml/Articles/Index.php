<?php
/**
 * Articles → Index action.
 * Lists all product mappings (synced, pending, error).
 */
declare(strict_types=1);

namespace Soldx\Integration\Controller\Adminhtml\Articles;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    /**
     * @var PageFactory
     */
    private PageFactory $resultPageFactory;

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     */
    public function __construct(Context $context, PageFactory $resultPageFactory)
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    /**
     * @return Page
     */
    public function execute(): Page
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Soldx_Integration::articles');
        $resultPage->getConfig()->getTitle()->prepend(__('Soldx Articles'));
        return $resultPage;
    }

    /**
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Soldx_Integration::articles');
    }
}
