<?php
/**
 * Soldx Auth — stores and retrieves Studio connection settings via Magento core_config_data.
 *
 * Auth model: the Studio apiKey IS the permanent credential. There is no
 * separate token — every API request sends the apiKey as Bearer + X-Soldx-Api-Key.
 */
declare(strict_types=1);

namespace Soldx\Integration\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Auth
{
    private const CONFIG_PATH_CONNECTION_API_BASE_URL = 'soldx/connection/api_base_url';
    private const CONFIG_PATH_CONNECTION_API_KEY = 'soldx/connection/api_key';
    private const CONFIG_PATH_AUTH_INTEGRATION_ID = 'soldx/auth_result/integration_id';
    private const CONFIG_PATH_AUTH_INTEGRATION_NAME = 'soldx/auth_result/integration_name';
    private const CONFIG_PATH_AUTH_ORG_ID = 'soldx/auth_result/org_id';
    private const CONFIG_PATH_AUTH_ESTABLISHMENT_NAME = 'soldx/auth_result/establishment_name';
    private const CONFIG_PATH_AUTH_CONNECTED_AT = 'soldx/auth_result/connected_at';
    private const CONFIG_PATH_AUTH_STATUS = 'soldx/auth_result/status';
    private const CONFIG_PATH_SYNC_AUTO = 'soldx/sync/auto_sync';
    private const CONFIG_PATH_SYNC_DEFAULT_TAX_RATE = 'soldx/sync/default_tax_rate';
    private const CONFIG_PATH_CATEGORY_MAP = 'soldx/categories/mapping';

    private const STATUS_CONNECTED = 'connected';
    private const STATUS_DISCONNECTED = 'disconnected';
    private const STATUS_ERROR = 'error';

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var WriterInterface
     */
    private WriterInterface $configWriter;

    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;

    /**
     * Runtime overrides — set by Connect controller so that ApiClient
     * can use freshly-saved credentials without relying on ScopeConfig
     * re-reading from the DB within the same request.
     *
     * @var string|null
     */
    private ?string $runtimeApiBaseUrl = null;

    /**
     * @var string|null
     */
    private ?string $runtimeApiKey = null;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param WriterInterface $configWriter
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        EncryptorInterface $encryptor
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->encryptor = $encryptor;
    }

    /**
     * Set runtime credential overrides. Takes priority over ScopeConfig reads.
     *
     * @param string $apiBaseUrl
     * @param string $apiKey
     * @return void
     */
    public function setRuntimeCredentials(string $apiBaseUrl, string $apiKey): void
    {
        $this->runtimeApiBaseUrl = rtrim($apiBaseUrl, '/');
        $this->runtimeApiKey = $apiKey;
    }

    /**
     * Get the Studio base URL (no trailing slash).
     *
     * @return string
     */
    public function getApiBaseUrl(): string
    {
        if ($this->runtimeApiBaseUrl !== null) {
            return $this->runtimeApiBaseUrl;
        }
        return rtrim((string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_CONNECTION_API_BASE_URL,
            ScopeInterface::SCOPE_STORE
        ), '/');
    }

    /**
     * Get the decrypted API key (used as the bearer credential).
     *
     * @return string
     */
    public function getApiKey(): string
    {
        if ($this->runtimeApiKey !== null) {
            return $this->runtimeApiKey;
        }
        $value = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_CONNECTION_API_KEY,
            ScopeInterface::SCOPE_STORE
        );
        return $value !== '' ? $this->encryptor->decrypt($value) : '';
    }

    /**
     * Whether the module has enough configuration to attempt a connection.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->getApiBaseUrl() !== '' && $this->getApiKey() !== '';
    }

    /**
     * Whether the auth handshake completed successfully.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        $status = $this->scopeConfig->getValue(
            self::CONFIG_PATH_AUTH_STATUS,
            ScopeInterface::SCOPE_STORE
        );
        return $status === self::STATUS_CONNECTED;
    }

    /**
     * @return string|null
     */
    public function getIntegrationId(): ?string
    {
        $val = $this->scopeConfig->getValue(
            self::CONFIG_PATH_AUTH_INTEGRATION_ID,
            ScopeInterface::SCOPE_STORE
        );
        return $val !== null && $val !== '' ? (string) $val : null;
    }

    /**
     * @return string
     */
    public function getIntegrationName(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_AUTH_ESTABLISHMENT_NAME,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get the org ID (used as S3 prefix for image uploads).
     *
     * @return string
     */
    public function getIdOrg(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_AUTH_ORG_ID,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_AUTH_STATUS,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * @return bool
     */
    public function isAutoSyncEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::CONFIG_PATH_SYNC_AUTO,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * @return float
     */
    public function getDefaultTaxRate(): float
    {
        return (float) $this->scopeConfig->getValue(
            self::CONFIG_PATH_SYNC_DEFAULT_TAX_RATE,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Persist the connection credentials (API key is encrypted).
     *
     * @param string $apiBaseUrl
     * @param string $apiKey
     * @return void
     */
    public function saveConnection(string $apiBaseUrl, string $apiKey): void
    {
        $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $this->configWriter->save(self::CONFIG_PATH_CONNECTION_API_BASE_URL, $apiBaseUrl, $scope, 0);
        $this->configWriter->save(
            self::CONFIG_PATH_CONNECTION_API_KEY,
            $this->encryptor->encrypt($apiKey),
            $scope,
            0
        );
    }

    /**
     * Persist the authentication result from the Studio /api/plugin/auth response.
     *
     * Expected response fields: integrationId, establishmentName, idOrg.
     *
     * @param array $result
     * @return void
     */
    public function saveAuthResult(array $result): void
    {
        $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;

        $this->configWriter->save(
            self::CONFIG_PATH_AUTH_INTEGRATION_ID,
            $result['integrationId'] ?? '',
            $scope,
            0
        );
        $this->configWriter->save(
            self::CONFIG_PATH_AUTH_ESTABLISHMENT_NAME,
            $result['establishmentName'] ?? '',
            $scope,
            0
        );
        $this->configWriter->save(
            self::CONFIG_PATH_AUTH_ORG_ID,
            $result['idOrg'] ?? '',
            $scope,
            0
        );
        $this->configWriter->save(
            self::CONFIG_PATH_AUTH_CONNECTED_AT,
            date('Y-m-d H:i:s'),
            $scope,
            0
        );
        $this->configWriter->save(
            self::CONFIG_PATH_AUTH_STATUS,
            self::STATUS_CONNECTED,
            $scope,
            0
        );
    }

    /**
     * Mark the connection status as error.
     *
     * @param string $message
     * @return void
     */
    public function markError(string $message): void
    {
        $this->configWriter->save(
            self::CONFIG_PATH_AUTH_STATUS,
            self::STATUS_ERROR . ':' . substr($message, 0, 200),
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            0
        );
    }

    /**
     * Reset all Soldx configuration.
     *
     * @return void
     */
    public function reset(): void
    {
        $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $this->configWriter->delete(self::CONFIG_PATH_CONNECTION_API_BASE_URL, $scope, 0);
        $this->configWriter->delete(self::CONFIG_PATH_CONNECTION_API_KEY, $scope, 0);
        $this->configWriter->delete(self::CONFIG_PATH_AUTH_INTEGRATION_ID, $scope, 0);
        $this->configWriter->delete(self::CONFIG_PATH_AUTH_ESTABLISHMENT_NAME, $scope, 0);
        $this->configWriter->delete(self::CONFIG_PATH_AUTH_ORG_ID, $scope, 0);
        $this->configWriter->delete(self::CONFIG_PATH_AUTH_CONNECTED_AT, $scope, 0);
        $this->configWriter->delete(self::CONFIG_PATH_AUTH_STATUS, $scope, 0);
    }

    /**
     * Validate that the API key matches the expected 64-hex-char format.
     *
     * @param string $apiKey
     * @return bool
     */
    public function isValidApiKeyFormat(string $apiKey): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/i', $apiKey);
    }

    /**
     * Get the category mapping (magento_cat_id => studio_cat_id).
     *
     * @return array<string,string>
     */
    public function getCategoryMap(): array
    {
        $json = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_CATEGORY_MAP,
            ScopeInterface::SCOPE_STORE
        );
        if ($json === '') {
            return [];
        }
        $map = json_decode($json, true);
        return is_array($map) ? $map : [];
    }

    /**
     * Persist the full category mapping.
     *
     * @param array $map
     * @return void
     */
    public function saveCategoryMap(array $map): void
    {
        $clean = [];
        foreach ($map as $catId => $studioCatId) {
            $catId = (string) (int) $catId;
            $studioCatId = (string) $studioCatId;
            if ($catId !== '0' && $studioCatId !== '') {
                $clean[$catId] = $studioCatId;
            }
        }
        $this->configWriter->save(
            self::CONFIG_PATH_CATEGORY_MAP,
            json_encode($clean),
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            0
        );
    }

    /**
     * Update a single entry in the category mapping.
     *
     * @param int $categoryId
     * @param string $studioCategoryId
     * @return void
     */
    public function updateCategoryMappingEntry(int $categoryId, string $studioCategoryId): void
    {
        $map = $this->getCategoryMap();
        $map[(string) $categoryId] = $studioCategoryId;
        $this->saveCategoryMap($map);
    }
}
