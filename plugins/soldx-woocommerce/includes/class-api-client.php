<?php
/**
 * HTTP client for the Studio /api/plugin/* endpoints.
 *
 * Direction: WooCommerce → Studio (the plugin pushes WC products into Studio).
 *
 * Uses wp_remote_{get,post,put} — no external dependencies.
 *
 * @package SoldxWooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Soldx_Api_Exception extends Exception {
}

class Soldx_Api_Client {

	/**
	 * @var string Studio base URL, no trailing slash.
	 */
	private $base_url;

	/**
	 * @var string API key.
	 */
	private $api_key;

	/**
	 * @var int Request timeout in seconds.
	 */
	private $timeout = 30;

	public function __construct( $base_url = null, $api_key = null ) {
		$this->base_url = $base_url !== null ? rtrim( $base_url, '/' ) : Soldx_Auth::studio_url();
		$this->api_key  = $api_key !== null ? $api_key : Soldx_Auth::api_key();
	}

	/**
	 * Test connection — exchange the apiKey for integration context.
	 *
	 * @return array {
	 *     @type string $integrationId
	 *     @type string $idEtb
	 *     @type string $idOrg
	 *     @type string $type
	 *     @type string $name
	 *     @type string $establishmentName
	 *     @type array|null $currency
	 *     @type array  $config  Default unit/deposit ids for preselecting dropdowns (D12).
	 * }
	 *
	 * @throws Soldx_Api_Exception
	 */
	public function authenticate() {
		return $this->request( 'POST', '/api/plugin/auth', array(
			'apiKey' => $this->api_key,
		) );
	}

	/**
	 * Fetch establishment-level option lists (units, deposits, taxes) and
	 * the integration's configured defaults. Populates the per-article
	 * dropdowns in the WP admin UI (D8).
	 *
	 * @return array {
	 *     @type array $units
	 *     @type array $deposits
	 *     @type array $taxes
	 *     @type array $config
	 * }
	 *
	 * @throws Soldx_Api_Exception
	 */
	public function get_options() {
		return $this->request( 'GET', '/api/plugin/options' );
	}

	/**
	 * Read the current mapping state for a WC product (externalId = WC post_id).
	 * Used by the "is this already synced?" check and to preselect dropdowns
	 * from the last-synced overrides.
	 *
	 * @param string|int $external_id WC post_id.
	 * @return array|null Mapping state, or null when 404 (not yet synced).
	 *
	 * @throws Soldx_Api_Exception On transport failure or non-404 error.
	 */
	public function get_mapping( $external_id ) {
		try {
			return $this->request(
				'GET',
				'/api/plugin/articles/import/' . rawurlencode( (string) $external_id )
			);
		} catch ( Soldx_Api_Exception $e ) {
			// 404 means "not yet imported" — that's a normal state, not an error.
			if ( 404 === (int) $e->getCode() ) {
				return null;
			}
			throw $e;
		}
	}

	/**
	 * Push a WC product to Studio (create Article + Pricing + mapping atomically).
	 *
	 * @param array $dto WcProductImportDTO (see PLAN.md §7).
	 * @return array {
	 *     @type string $idArticle
	 *     @type string $mappingId
	 *     @type string $reference
	 *     @type bool   $created  Always true on this endpoint.
	 * }
	 *
	 * @throws Soldx_Api_Exception On validation (422), conflict (409), or server error.
	 */
	public function push_product( $dto ) {
		return $this->request( 'POST', '/api/plugin/articles/import', $dto );
	}

	/**
	 * Idempotent update of an already-imported WC product.
	 *
	 * @param string|int $external_id WC post_id.
	 * @param array      $dto         Partial WcProductImportDTO merged with stored payload.
	 * @return array {
	 *     @type string $idArticle
	 *     @type string $mappingId
	 *     @type string $reference
	 *     @type bool   $created      Always false on this endpoint.
	 *     @type bool   $priceChanged
	 * }
	 *
	 * @throws Soldx_Api_Exception On 404 (no mapping, caller should push_product) or server error.
	 */
	public function update_product( $external_id, $dto ) {
		return $this->request(
			'PUT',
			'/api/plugin/articles/import/' . rawurlencode( (string) $external_id ),
			$dto
		);
	}

	// ------------------------------------------------------------------
	// File upload (Studio /api/upload → S3)
	// ------------------------------------------------------------------

	/**
	 * Upload an image file to Studio's /api/upload endpoint.
	 *
	 * Studio stores the file on S3 under `<orgId>/<filename>` and returns
	 * a JSON response with the S3 key. We return only the key (the relative
	 * path `<orgId>/<img_name>`) because that's what Studio stores in the
	 * article's `media` / `gallery` fields.
	 *
	 * @param string $file_path Absolute path to the file on disk.
	 * @param string $org_id    Organization ID (S3 prefix).
	 * @param string $filename  Optional display name; defaults to basename.
	 * @return string S3 key, e.g. `<org-id>/product-abc123.jpg`.
	 *
	 * @throws Soldx_Api_Exception On transport failure, HTTP error, or missing key.
	 */
	public function upload_image( $file_path, $org_id, $filename = '' ) {
		if ( '' === $this->base_url ) {
			throw new Soldx_Api_Exception( __( 'Plugin not configured.', 'soldx-woocommerce' ) );
		}
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			throw new Soldx_Api_Exception(
				sprintf( 'Image file not found or not readable: %s', $file_path )
			);
		}

		if ( '' === $filename ) {
			$filename = basename( $file_path );
		}

		$file_content = file_get_contents( $file_path );
		if ( false === $file_content ) {
			throw new Soldx_Api_Exception( sprintf( 'Could not read image file: %s', $file_path ) );
		}

		$mime        = wp_check_filetype( $file_path );
		$content_type = isset( $mime['type'] ) && '' !== $mime['type'] ? $mime['type'] : 'application/octet-stream';

		// Build multipart/form-data body manually (wp_remote_post doesn't
		// support file uploads natively).
		$boundary = wp_generate_password( 24, false );

		$body  = '';
		// orgId field.
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="orgId"' . "\r\n\r\n";
		$body .= $org_id . "\r\n";
		// file field.
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n";
		$body .= 'Content-Type: ' . $content_type . "\r\n\r\n";
		$body .= $file_content . "\r\n";
		$body .= '--' . $boundary . '--' . "\r\n";

		$url      = $this->base_url . '/api/upload';
		$response = wp_remote_post( $url, array(
			'timeout'     => 60,
			'redirection' => 5,
			'headers'     => array(
				'Authorization'   => 'Bearer ' . $this->api_key,
				'X-Soldx-Api-Key' => $this->api_key,
				'Accept'          => 'application/json',
				'Content-Type'    => 'multipart/form-data; boundary=' . $boundary,
			),
			'body'        => $body,
		) );

		if ( is_wp_error( $response ) ) {
			throw new Soldx_Api_Exception(
				sprintf(
					/* translators: %s: error message */
					__( 'Image upload network error: %s', 'soldx-woocommerce' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['message'] ) ? $data['message'] : sprintf( 'HTTP %d', $code );
			throw new Soldx_Api_Exception(
				sprintf( 'Image upload failed: %s', $message ),
				$code
			);
		}

		// Response: { success, files: [{ name, key, path, url, success }] }
		if ( ! empty( $data['files'][0]['key'] ) ) {
			return $data['files'][0]['key'];
		}

		throw new Soldx_Api_Exception( 'Image upload succeeded but no key was returned.' );
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	/**
	 * Perform an HTTP request to Studio.
	 *
	 * @param string     $method GET|POST|PUT|PATCH|DELETE
	 * @param string     $path   Path relative to base_url. May include query string.
	 * @param array|null $body   JSON body for POST/PUT/PATCH.
	 * @return array Decoded JSON.
	 * @throws Soldx_Api_Exception On non-2xx or transport failure.
	 */
	private function request( $method, $path, $body = null ) {
		if ( '' === $this->base_url || '' === $this->api_key ) {
			throw new Soldx_Api_Exception( __( 'Plugin not configured.', 'soldx-woocommerce' ) );
		}

		$url  = $this->base_url . $path;
		$args = array(
			'method'      => $method,
			'timeout'     => $this->timeout,
			'redirection' => 5,
			'headers'     => array(
				'Authorization'   => 'Bearer ' . $this->api_key,
				'X-Soldx-Api-Key' => $this->api_key, // belt + suspenders; some hosts strip Authorization
				'Accept'          => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			// body MUST be JSON (wp_remote_* does not auto-encode arrays reliably)
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new Soldx_Api_Exception(
				sprintf(
					/* translators: %s: error message */
					__( 'Network error: %s', 'soldx-woocommerce' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = isset( $data['error'] ) ? $data['error'] : sprintf( 'HTTP %d', $code );
			throw new Soldx_Api_Exception( $message, $code );
		}

		return is_array( $data ) ? $data : array();
	}
}
