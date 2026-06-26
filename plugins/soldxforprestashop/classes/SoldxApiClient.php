<?php
/**
 * HTTP client for the Studio /api/plugin/* endpoints.
 *
 * Uses cURL directly — no external dependencies.
 *
 * Direction: PrestaShop → Studio (push PS products into Studio).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SoldxApiClient
{
    /** @var string Studio base URL, no trailing slash. */
    private $base_url;

    /** @var string API key. */
    private $api_key;

    /** @var int Request timeout in seconds. */
    private $timeout = 30;

    public function __construct($base_url = null, $api_key = null)
    {
        $this->base_url = $base_url !== null ? rtrim($base_url, '/') : SoldxAuth::studioUrl();
        $this->api_key = $api_key !== null ? $api_key : SoldxAuth::apiKey();
    }

    /**
     * Test connection — exchange the apiKey for integration context.
     *
     * @return array
     * @throws SoldxApiException
     */
    public function authenticate()
    {
        return $this->request('POST', '/api/plugin/auth', [
            'apiKey' => $this->api_key,
        ]);
    }

    /**
     * Fetch establishment-level option lists (units, deposits, taxes, tags, categories).
     *
     * @return array
     * @throws SoldxApiException
     */
    public function getOptions()
    {
        return $this->request('GET', '/api/plugin/options');
    }

    /**
     * Create a Studio Category.
     *
     * @param string $designation
     * @param string $id_parent
     * @param string $image
     * @return array
     * @throws SoldxApiException
     */
    public function createCategory($designation, $id_parent = '', $image = '')
    {
        $body = ['designation' => $designation];
        if ('' !== $id_parent) {
            $body['idParent'] = $id_parent;
        }
        if ('' !== $image) {
            $body['image'] = $image;
        }
        return $this->request('POST', '/api/plugin/categories', $body);
    }

    /**
     * Update a Studio Category's image via PATCH.
     *
     * @param string $studio_cat_id
     * @param string $image_key
     * @return array
     * @throws SoldxApiException
     */
    public function updateCategoryImage($studio_cat_id, $image_key)
    {
        return $this->request(
            'PATCH',
            '/api/plugin/categories/' . rawurlencode($studio_cat_id),
            ['image' => $image_key]
        );
    }

    /**
     * Read the current mapping state for a PS product (externalId = PS product id).
     *
     * @param string|int $external_id
     * @return array|null
     * @throws SoldxApiException
     */
    public function getMapping($external_id)
    {
        try {
            return $this->request(
                'GET',
                '/api/plugin/articles/import/' . rawurlencode((string) $external_id)
            );
        } catch (SoldxApiException $e) {
            if (404 === (int) $e->getCode()) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Push a PS product to Studio (create Article + Pricing + mapping).
     *
     * @param array $dto
     * @return array
     * @throws SoldxApiException
     */
    public function pushProduct($dto)
    {
        return $this->request('POST', '/api/plugin/articles/import', $dto);
    }

    /**
     * Idempotent update of an already-imported PS product.
     *
     * @param string|int $external_id
     * @param array $dto
     * @return array
     * @throws SoldxApiException
     */
    public function updateProduct($external_id, $dto)
    {
        return $this->request(
            'PUT',
            '/api/plugin/articles/import/' . rawurlencode((string) $external_id),
            $dto
        );
    }

    // ------------------------------------------------------------------
    // File upload (Studio /api/upload → S3)
    // ------------------------------------------------------------------

    /**
     * Upload an image file to Studio's /api/upload endpoint.
     *
     * @param string $file_path Absolute path.
     * @param string $org_id    Organization ID (S3 prefix).
     * @param string $filename  Display name.
     * @return string S3 key.
     * @throws SoldxApiException
     */
    public function uploadImage($file_path, $org_id, $filename = '')
    {
        if ('' === $this->base_url) {
            throw new SoldxApiException('Plugin not configured.');
        }
        if (!file_exists($file_path) || !is_readable($file_path)) {
            throw new SoldxApiException('Image file not found or not readable: ' . $file_path);
        }

        if ('' === $filename) {
            $filename = basename($file_path);
        }

        $file_content = file_get_contents($file_path);
        if (false === $file_content) {
            throw new SoldxApiException('Could not read image file: ' . $file_path);
        }

        $mime_types = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
        ];
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $content_type = isset($mime_types[$ext]) ? $mime_types[$ext] : 'application/octet-stream';

        // Build multipart/form-data body.
        $boundary = md5(uniqid('', true));

        $body  = '';
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="orgId"' . "\r\n\r\n";
        $body .= $org_id . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n";
        $body .= 'Content-Type: ' . $content_type . "\r\n\r\n";
        $body .= $file_content . "\r\n";
        $body .= '--' . $boundary . '--' . "\r\n";

        $url = $this->base_url . '/api/upload';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->api_key,
                'X-Soldx-Api-Key: ' . $this->api_key,
                'Accept: application/json',
                'Content-Type: multipart/form-data; boundary=' . $boundary,
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
        ]);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new SoldxApiException('Image upload network error: ' . $err);
        }

        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $message = isset($data['message']) ? $data['message'] : 'HTTP ' . $code;
            throw new SoldxApiException('Image upload failed: ' . $message, $code);
        }

        if (!empty($data['files'][0]['key'])) {
            return $data['files'][0]['key'];
        }

        throw new SoldxApiException('Image upload succeeded but no key was returned.');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Perform an HTTP request to Studio via cURL.
     *
     * @param string $method GET|POST|PUT|PATCH|DELETE
     * @param string $path
     * @param array|null $body JSON body for POST/PUT/PATCH.
     * @return array Decoded JSON.
     * @throws SoldxApiException
     */
    private function request($method, $path, $body = null)
    {
        if ('' === $this->base_url || '' === $this->api_key) {
            throw new SoldxApiException('Plugin not configured.');
        }

        $url = $this->base_url . $path;

        $headers = [
            'Authorization: Bearer ' . $this->api_key,
            'X-Soldx-Api-Key: ' . $this->api_key,
            'Accept: application/json',
        ];

        $post_fields = null;
        $is_post = false;

        if (null !== $body) {
            $headers[] = 'Content-Type: application/json';
            $post_fields = json_encode($body);
            $is_post = true;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if ($is_post || in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        }

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new SoldxApiException('Network error: ' . $err);
        }

        $data = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $message = isset($data['error']) ? $data['error'] : 'HTTP ' . $code;
            throw new SoldxApiException($message, $code);
        }

        return is_array($data) ? $data : [];
    }
}

class SoldxApiException extends Exception
{
}
