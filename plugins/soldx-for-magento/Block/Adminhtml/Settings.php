<?php
/**
 * Settings page block — passes auth state to the template.
 */
declare(strict_types=1);

namespace Soldx\Integration\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Soldx\Integration\Model\Auth;

class Settings extends Template
{
    /**
     * @var Auth
     */
    private Auth $auth;

    /**
     * @param Context $context
     * @param Auth $auth
     * @param array $data
     */
    public function __construct(Context $context, Auth $auth, array $data = [])
    {
        parent::__construct($context, $data);
        $this->auth = $auth;
    }

    /**
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->auth->isConnected();
    }

    /**
     * @return string
     */
    public function getApiBaseUrl(): string
    {
        return $this->auth->getApiBaseUrl();
    }

    /**
     * @return string
     */
    public function getIntegrationName(): string
    {
        return $this->auth->getIntegrationName();
    }

    /**
     * @return string
     */
    public function getIntegrationId(): string
    {
        return (string) $this->auth->getIntegrationId();
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->auth->getStatus();
    }

    /**
     * @return string
     */
    public function getConnectUrl(): string
    {
        return $this->getUrl('soldx/settings/connect');
    }

    /**
     * @return string
     */
    public function getDisconnectUrl(): string
    {
        return $this->getUrl('soldx/settings/disconnect');
    }

    /**
     * @return string
     */
    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }
}
