<?php
/**
 * Settings → Connect action.
 * Authenticates against Studio and saves the result.
 */
declare(strict_types=1);

namespace Soldx\Integration\Controller\Adminhtml\Settings;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Message\ManagerInterface;
use Soldx\Integration\Model\ApiClient;
use Soldx\Integration\Model\Auth;
use Soldx\Integration\Model\Exception\SoldxApiException;

class Connect extends Action
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
     * @var TypeListInterface
     */
    private TypeListInterface $cacheTypeList;

    /**
     * @param Context $context
     * @param Auth $auth
     * @param ApiClient $apiClient
     * @param TypeListInterface $cacheTypeList
     */
    public function __construct(
        Context $context,
        Auth $auth,
        ApiClient $apiClient,
        TypeListInterface $cacheTypeList
    ) {
        parent::__construct($context);
        $this->auth = $auth;
        $this->apiClient = $apiClient;
        $this->cacheTypeList = $cacheTypeList;
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('*/*/');

        $apiBaseUrl = (string) $this->getRequest()->getParam('api_base_url');
        $apiKey = (string) $this->getRequest()->getParam('api_key');

        if ($apiBaseUrl === '' || $apiKey === '') {
            $this->messageManager->addErrorMessage(__('Studio URL and API Key are required.'));
            return $redirect;
        }

        if (!$this->auth->isValidApiKeyFormat($apiKey)) {
            $this->messageManager->addErrorMessage(__('API Key must be 64 hexadecimal characters.'));
            return $redirect;
        }

        // Save connection details to DB
        $this->auth->saveConnection($apiBaseUrl, $apiKey);

        // Set runtime overrides so ApiClient can use the credentials
        // immediately, bypassing ScopeConfig's in-memory cache
        $this->auth->setRuntimeCredentials($apiBaseUrl, $apiKey);

        // Flush config cache for subsequent requests
        $this->cacheTypeList->cleanType('config');

        try {
            $result = $this->apiClient->authenticate($apiKey);

            if (!isset($result['integrationId']) || $result['integrationId'] === '') {
                throw new SoldxApiException('Authentication succeeded but no integrationId was returned.');
            }

            $this->auth->saveAuthResult($result);
            $this->cacheTypeList->cleanType('config');

            $this->messageManager->addSuccessMessage(
                __('Connected to Studio as "%1".', $result['establishmentName'] ?? 'Unknown')
            );
        } catch (SoldxApiException $e) {
            $this->auth->markError($e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Failed to connect to Studio: %1', $e->getMessage())
            );
        }

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
