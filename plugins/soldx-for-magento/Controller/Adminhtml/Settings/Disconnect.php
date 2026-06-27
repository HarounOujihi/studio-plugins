<?php
/**
 * Settings → Disconnect action.
 * Clears all auth configuration.
 */
declare(strict_types=1);

namespace Soldx\Integration\Controller\Adminhtml\Settings;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Controller\Result\Redirect;
use Soldx\Integration\Model\Auth;

class Disconnect extends Action
{
    /**
     * @var Auth
     */
    private Auth $auth;

    /**
     * @var TypeListInterface
     */
    private TypeListInterface $cacheTypeList;

    /**
     * @param Context $context
     * @param Auth $auth
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        Context $context,
        Auth $auth,
        TypeListInterface $cacheTypeList
    ) {
        parent::__construct($context);
        $this->auth = $auth;
        $this->cacheTypeList = $cacheTypeList;
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $this->auth->reset();
        $this->cacheTypeList->cleanType('config');

        $this->messageManager->addSuccessMessage(__('Disconnected from Studio.'));

        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('*/*/');
        return $redirect;
    }

    /**
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('Soldx_Integration::settings');
    }
}
