<?php

/**
 * Soldx for PrestaShop — auth & settings.
 *
 * Auth / settings storage (PrestaShop Configuration).
 *
 * Stores Studio URL + API key + integration id in ps_configuration.
 *
 * @author    Soldx
 * @copyright Soldx
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 * @version   0.1.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SoldxAuth
{
    public const CFG_STUDIO_URL = 'SOLDX_STUDIO_URL';
    public const CFG_API_KEY = 'SOLDX_API_KEY';
    public const CFG_INTEGRATION_ID = 'SOLDX_INTEGRATION_ID';
    public const CFG_ETB_NAME = 'SOLDX_ESTABLISHMENT_NAME';
    public const CFG_ORG_ID = 'SOLDX_ORG_ID';

    /** @return string Studio base URL, no trailing slash. */
    public static function studioUrl()
    {
        return rtrim((string) Configuration::get(self::CFG_STUDIO_URL), '/');
    }

    /** @return string API key. */
    public static function apiKey()
    {
        return (string) Configuration::get(self::CFG_API_KEY);
    }

    /** @return string Integration id. */
    public static function integrationId()
    {
        return (string) Configuration::get(self::CFG_INTEGRATION_ID);
    }

    /** @return string Establishment name. */
    public static function establishmentName()
    {
        return (string) Configuration::get(self::CFG_ETB_NAME);
    }

    /** @return string Org id (S3 prefix). */
    public static function orgId()
    {
        return (string) Configuration::get(self::CFG_ORG_ID);
    }

    /** @return bool Whether URL + key are both set. */
    public static function isConfigured()
    {
        return '' !== self::studioUrl() && '' !== self::apiKey();
    }

    /**
     * Persist settings from the settings form.
     *
     * @param array $input { studio_url, api_key }
     *
     * @return bool
     */
    public static function saveSettings($input)
    {
        $studio_url = isset($input['studio_url']) ? trim($input['studio_url']) : '';
        $api_key = isset($input['api_key']) ? trim($input['api_key']) : '';

        if ('' !== $studio_url && false === filter_var($studio_url, FILTER_VALIDATE_URL)) {
            return false;
        }
        if ('' !== $api_key && !preg_match('/^[a-f0-9]{64}$/i', $api_key)) {
            return false;
        }

        $old_key = Configuration::get(self::CFG_API_KEY);
        $old_url = Configuration::get(self::CFG_STUDIO_URL);

        Configuration::updateValue(self::CFG_STUDIO_URL, $studio_url);
        Configuration::updateValue(self::CFG_API_KEY, $api_key);

        if ($old_key !== $api_key || $old_url !== $studio_url) {
            Configuration::updateValue(self::CFG_INTEGRATION_ID, '');
            Configuration::updateValue(self::CFG_ETB_NAME, '');
        }

        return true;
    }

    /**
     * Store the result of a successful /api/plugin/auth call.
     *
     * @param array $auth
     */
    public static function saveAuthResult($auth)
    {
        if (!empty($auth['integrationId'])) {
            Configuration::updateValue(self::CFG_INTEGRATION_ID, pSQL($auth['integrationId']));
        }
        if (!empty($auth['establishmentName'])) {
            Configuration::updateValue(self::CFG_ETB_NAME, pSQL($auth['establishmentName']));
        }
        if (!empty($auth['idOrg'])) {
            Configuration::updateValue(self::CFG_ORG_ID, pSQL($auth['idOrg']));
        }
    }

    /**
     * Reset everything (used by the "Disconnect" button).
     */
    public static function reset()
    {
        Configuration::updateValue(self::CFG_STUDIO_URL, '');
        Configuration::updateValue(self::CFG_API_KEY, '');
        Configuration::updateValue(self::CFG_INTEGRATION_ID, '');
        Configuration::updateValue(self::CFG_ETB_NAME, '');
        Configuration::updateValue(self::CFG_ORG_ID, '');
    }
}
