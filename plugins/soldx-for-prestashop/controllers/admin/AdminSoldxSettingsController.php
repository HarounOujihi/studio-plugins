<?php

/**
 * Soldx for PrestaShop — settings controller.
 *
 * Admin settings controller — Studio URL + apiKey + Test connection.
 *
 * @author    Soldx
 * @copyright Soldx
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 * @version   0.1.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSoldxSettingsController extends ModuleAdminController
{
    public $bootstrap = true;

    public function initContent()
    {
        parent::initContent();

        // Read & clear flash message from cookie.
        $flash = null;

        if ($this->context->cookie->__isset('soldx_flash')) {
            $flash = json_decode($this->context->cookie->__get('soldx_flash'), true);
            $this->context->cookie->__unset('soldx_flash');
        }

        $studio_url = SoldxAuth::studioUrl();
        $api_key = SoldxAuth::apiKey();
        $integration_id = SoldxAuth::integrationId();
        $etb_name = SoldxAuth::establishmentName();
        $is_connected = '' !== $integration_id;

        $admin_url = $this->context->link->getAdminLink('AdminSoldxArticles');
        $cats_url = $this->context->link->getAdminLink('AdminSoldxCategories');

        $this->context->smarty->assign([
            'studio_url' => $studio_url,
            'api_key' => $api_key,
            'api_key_masked' => $api_key ? substr($api_key, 0, 6) . '…' . substr($api_key, -4) : '',
            'integration_id' => $integration_id,
            'integration_short' => $integration_id ? substr($integration_id, 0, 8) : '',
            'etb_name' => $etb_name,
            'is_connected' => $is_connected,
            'articles_url' => $admin_url,
            'categories_url' => $cats_url,
            'flash' => $flash,
            'token' => $this->token,
            'post_url' => $this->context->link->getAdminLink('AdminSoldxSettings'),
        ]);

        $this->context->smarty->addTemplateDir(_PS_MODULE_DIR_ . 'soldxforprestashop/views/templates/admin/');
        $this->setTemplate('settings.tpl');
    }

    public function postProcess()
    {
        if (Tools::isSubmit('soldx_action')) {
            $action = Tools::getValue('soldx_action');

            switch ($action) {
                case 'save':
                    return $this->handleSave();
                case 'test':
                    return $this->handleTestConnection();
                case 'disconnect':
                    return $this->handleDisconnect();
            }
        }

        return true;
    }

    private function handleSave()
    {
        $input = [
            'studio_url' => Tools::getValue('studio_url'),
            'api_key' => Tools::getValue('api_key'),
        ];
        $ok = SoldxAuth::saveSettings($input);

        if ($ok) {
            $this->setFlash('Settings saved.', 'success');
        } else {
            $this->setFlash('Settings could not be saved. Check the API key format (64 hex characters) and Studio URL.', 'error');
        }
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminSoldxSettings'));
    }

    private function handleTestConnection()
    {
        $input = [
            'studio_url' => Tools::getValue('studio_url'),
            'api_key' => Tools::getValue('api_key'),
        ];
        SoldxAuth::saveSettings($input);

        if (!SoldxAuth::isConfigured()) {
            $this->setFlash('Please enter both the Studio URL and the API key first.', 'warning');
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminSoldxSettings'));
        }

        try {
            $result = Soldxforprestashop::getApiClient()->authenticate();
            SoldxAuth::saveAuthResult($result);
            $etb = !empty($result['establishmentName']) ? $result['establishmentName'] : '(unknown)';
            $this->setFlash('Connection successful. Linked to ' . $etb . '.', 'success');
        } catch (SoldxApiException $e) {
            $this->setFlash('Connection failed: ' . $e->getMessage(), 'error');
        }

        Tools::redirectAdmin($this->context->link->getAdminLink('AdminSoldxSettings'));
    }

    private function handleDisconnect()
    {
        SoldxAuth::reset();
        $this->setFlash('Plugin disconnected. Your PrestaShop products are untouched.', 'info');
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminSoldxSettings'));
    }

    private function setFlash($msg, $type = 'info')
    {
        $this->context->cookie->__set('soldx_flash', json_encode(['msg' => $msg, 'type' => $type]));
    }
}
