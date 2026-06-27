<?php
/**
 * Soldx ApiClient — REST client for Studio's plugin API.
 *
 * Uses raw PHP cURL for all requests (more reliable than Magento's Curl wrapper
 * for non-GET methods and multipart uploads).
 *
 * Auth model: the Studio apiKey IS the permanent bearer credential.
 * There is no token exchange — every request sends Authorization: Bearer {apiKey}
 * and X-Soldx-Api-Key: {apiKey} (same value on both headers).
 */
declare(strict_types=1);

namespace Soldx\Integration\Model;

use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Soldx\Integration\Model\Exception\SoldxApiException;

class ApiClient
{
    private const API_PATH_AUTH = '/api/plugin/auth';
    private const API_PATH_OPTIONS = '/api/plugin/options';
    private const API_PATH_CATEGORIES = '/api/plugin/categories';
    private const API_PATH_ARTICLES_IMPORT = '/api/plugin/articles/import';
    private const API_PATH_UPLOAD = '/api/upload';

    private const TIMEOUT = 30;

    /**
     * @var Auth
     */
    private Auth $auth;

    /**
     * @var Json
     */
    private Json $json;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Auth $auth
     * @param Json $json
     * @param LoggerInterface $logger
     * @param mixed $curl Unused (kept for backward-compat with DI)
     */
    public function __construct(
        Auth $auth,
        Json $json,
        LoggerInterface $logger,
        $curl = null
    ) {
        $this->auth = $auth;
        $this->json = $json;
        $this->logger = $logger;
    }

    /**
     * Authenticate against the Studio API.
     *
     * Sends the apiKey in the body as { "apiKey": "..." } and also as
     * Authorization + X-Soldx-Api-Key headers. Studio validates the key and
     * returns integration context (integrationId, establishmentName, idOrg, ...).
     *
     * @param string $apiKey
     * @return array
     * @throws SoldxApiException
     */
    public function authenticate(string $apiKey): array
    {
        return $this->request('POST', self::API_PATH_AUTH, [
            'apiKey' => $apiKey,
        ]);
    }

    /**
     * Fetch plugin options (tax rates, categories, units, deposits, etc.).
     *
     * @return array
     * @throws SoldxApiException
     */
    public function getOptions(): array
    {
        return $this->request('GET', self::API_PATH_OPTIONS);
    }

    /**
     * Create a category in Studio.
     *
     * @param array $data
     * @return array
     * @throws SoldxApiException
     */
    public function createCategory(array $data): array
    {
        return $this->request('POST', self::API_PATH_CATEGORIES, $data);
    }

    /**
     * Update a Studio category's image (PATCH with S3 key).
     *
     * @param string $categoryId
     * @param string $imageS3Key
     * @return array
     * @throws SoldxApiException
     */
    public function updateCategoryImage(string $categoryId, string $imageS3Key): array
    {
        $path = self::API_PATH_CATEGORIES . '/' . $categoryId;
        return $this->request('PATCH', $path, ['image' => $imageS3Key]);
    }

    /**
     * Fetch the Studio<->Shop mapping for a given external ID (Magento product ID).
     *
     * @param string $externalId
     * @return array|null Returns null on 404.
     * @throws SoldxApiException
     */
    public function getMapping(string $externalId): ?array
    {
        try {
            return $this->request('GET', self::API_PATH_ARTICLES_IMPORT . '/' . urlencode($externalId));
        } catch (SoldxApiException $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Push (create) a new product in Studio.
     *
     * POST /api/plugin/articles/import
     *
     * @param array $dto The WcProductImportDTO-shaped array.
     * @return array Contains idArticle, mappingId, reference, created.
     * @throws SoldxApiException
     */
    public function pushProduct(array $dto): array
    {
        return $this->request('POST', self::API_PATH_ARTICLES_IMPORT, $dto);
    }

    /**
     * Update an existing product in Studio.
     *
     * PUT /api/plugin/articles/import/{externalId}
     *
     * @param string $externalId The Magento product ID (as string).
     * @param array $dto
     * @return array Contains idArticle, mappingId, reference, created, priceChanged.
     * @throws SoldxApiException
     */
    public function updateProduct(string $externalId, array $dto): array
    {
        return $this->request(
            'PUT',
            self::API_PATH_ARTICLES_IMPORT . '/' . urlencode($externalId),
            $dto
        );
    }

    /**
     * Upload an image to Studio's S3 proxy endpoint.
     *
     * @param string $filePath
     * @param string|null $fileName
     * @return array Returns ['key' => '...']
     * @throws SoldxApiException
     */
    public function uploadImage(string $filePath, ?string $fileName = null): array
    {
        if (!file_exists($filePath)) {
            throw new SoldxApiException('File not found: ' . $filePath);
        }

        $baseUrl = $this->auth->getApiBaseUrl();
        if ($baseUrl === '') {
            throw new SoldxApiException('Studio URL is not configured.');
        }

        $url = $baseUrl . self::API_PATH_UPLOAD;
        $fileName = $fileName ?: basename($filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

        $postFields = [
            'file' => new \CURLFile($filePath, $mimeType, $fileName),
        ];

        // Include orgId if available (used as S3 prefix by Studio)
        $orgId = $this->auth->getIdOrg();
        if ($orgId) {
            $postFields['orgId'] = $orgId;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->auth->getApiKey(),
                'X-Soldx-Api-Key: ' . $this->auth->getApiKey(),
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $postFields,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new SoldxApiException('cURL error during file upload: ' . $error);
        }

        $data = $this->parseResponse($status, (string) $response, 'UPLOAD', $url);

        // Studio returns { files: [{ key: "..." }] }
        if (isset($data['files'][0]['key'])) {
            return ['key' => $data['files'][0]['key']];
        }
        // Fallback: some endpoints return { key: "..." }
        if (isset($data['key'])) {
            return ['key' => $data['key']];
        }

        throw new SoldxApiException('Upload response missing file key.');
    }

    /**
     * Core request method for JSON API endpoints.
     *
     * All requests send the apiKey as both Authorization: Bearer and X-Soldx-Api-Key
     * headers — the Studio API accepts either.
     *
     * @param string $method
     * @param string $path
     * @param array|null $body
     * @return array
     * @throws SoldxApiException
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null
    ): array {
        $baseUrl = $this->auth->getApiBaseUrl();
        if ($baseUrl === '') {
            throw new SoldxApiException('Studio URL is not configured.');
        }

        $url = $baseUrl . $path;

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->auth->getApiKey(),
            'X-Soldx-Api-Key: ' . $this->auth->getApiKey(),
        ];

        $payload = '';
        if ($body !== null) {
            $payload = $this->json->serialize($body);
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($payload !== '') {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }

        curl_setopt_array($ch, $options);

        $this->logger->info('Soldx API Request', [
            'method' => $method,
            'url' => $url,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new SoldxApiException('cURL error: ' . $error);
        }

        return $this->parseResponse($status, (string) $response, $method, $url);
    }

    /**
     * Parse and validate the HTTP response.
     *
     * @param int $status
     * @param string $body
     * @param string $method
     * @param string $url
     * @return array
     * @throws SoldxApiException
     */
    private function parseResponse(int $status, string $body, string $method, string $url): array
    {
        $this->logger->info('Soldx API Response', [
            'status' => $status,
            'method' => $method,
            'url' => $url,
            'body_length' => strlen($body),
        ]);

        $data = [];
        if ($body !== '') {
            try {
                $data = $this->json->unserialize($body) ?: [];
            } catch (\InvalidArgumentException $e) {
                $data = ['raw' => $body];
            }
        }

        if ($status < 200 || $status >= 300) {
            $message = is_array($data) && isset($data['error'])
                ? (string) $data['error']
                : (isset($data['message'])
                    ? (string) $data['message']
                    : "HTTP $status from $method $url");
            throw new SoldxApiException($message, $status);
        }

        return $data;
    }
}
