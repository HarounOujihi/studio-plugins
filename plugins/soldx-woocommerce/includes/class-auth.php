<?php
/**
 * Auth / settings storage.
 *
 * Stores Studio URL + API key + integration id in wp_options. For v1 these
 * are stored in plaintext (WP options table is server-side and not exposed).
 * If your wp_options leaks, you have bigger problems.
 *
 * @package SoldxWooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Soldx_Auth {

	const OPT_STUDIO_URL      = 'soldx_studio_url';
	const OPT_API_KEY         = 'soldx_api_key';
	const OPT_INTEGRATION_ID  = 'soldx_integration_id';
	const OPT_ETB_NAME        = 'soldx_establishment_name';
	const OPT_ORG_ID          = 'soldx_org_id';

	/**
	 * Get the configured Studio base URL (no trailing slash).
	 *
	 * @return string
	 */
	public static function studio_url() {
		return rtrim( (string) get_option( self::OPT_STUDIO_URL, '' ), '/' );
	}

	/**
	 * Get the configured API key.
	 *
	 * @return string
	 */
	public static function api_key() {
		return (string) get_option( self::OPT_API_KEY, '' );
	}

	/**
	 * Get the integration id (set after a successful /api/plugin/auth call).
	 *
	 * @return string
	 */
	public static function integration_id() {
		return (string) get_option( self::OPT_INTEGRATION_ID, '' );
	}

	/**
	 * Establishment display name (for the UI banner).
	 *
	 * @return string
	 */
	public static function establishment_name() {
		return (string) get_option( self::OPT_ETB_NAME, '' );
	}

	/**
	 * Organization ID (used as the S3 prefix for image uploads).
	 *
	 * Set after a successful /api/plugin/auth call.
	 *
	 * @return string
	 */
	public static function org_id() {
		return (string) get_option( self::OPT_ORG_ID, '' );
	}

	/**
	 * Whether the plugin is configured (URL + key both set).
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::studio_url() && '' !== self::api_key();
	}

	/**
	 * Persist settings from the settings form.
	 *
	 * @param array $input {
	 *     @type string $studio_url
	 *     @type string $api_key
	 * }
	 * @return bool
	 */
	public static function save_settings( $input ) {
		$studio_url = isset( $input['studio_url'] ) ? esc_url_raw( trim( $input['studio_url'] ) ) : '';
		$api_key    = isset( $input['api_key'] ) ? trim( $input['api_key'] ) : '';

		// Basic validation
		if ( '' !== $studio_url && false === filter_var( $studio_url, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		if ( '' !== $api_key && ! preg_match( '/^[a-f0-9]{64}$/i', $api_key ) ) {
			// Not a strict requirement, but warns early on typos.
			return false;
		}

		// Detect if the API key or URL actually changed.
		$old_key = get_option( self::OPT_API_KEY, '' );
		$old_url = get_option( self::OPT_STUDIO_URL, '' );

		update_option( self::OPT_STUDIO_URL, $studio_url );
		update_option( self::OPT_API_KEY, $api_key );

		// Reset integration id until next /auth call confirms the new key works.
		if ( $old_key !== $api_key || $old_url !== $studio_url ) {
			update_option( self::OPT_INTEGRATION_ID, '' );
			update_option( self::OPT_ETB_NAME, '' );
			// Clear cached establishment options so the Articles page
			// fetches fresh data from the new integration.
			delete_transient( 'soldx_establishment_options' );
		}
		return true;
	}

	/**
	 * Store the result of a successful /api/plugin/auth call.
	 *
	 * @param array $auth {
	 *     @type string $integrationId
	 *     @type string $establishmentName
	 * }
	 * @return void
	 */
	public static function save_auth_result( $auth ) {
		if ( ! empty( $auth['integrationId'] ) ) {
			update_option( self::OPT_INTEGRATION_ID, sanitize_text_field( $auth['integrationId'] ) );
		}
		if ( ! empty( $auth['establishmentName'] ) ) {
			update_option( self::OPT_ETB_NAME, sanitize_text_field( $auth['establishmentName'] ) );
		}
		if ( ! empty( $auth['idOrg'] ) ) {
			update_option( self::OPT_ORG_ID, sanitize_text_field( $auth['idOrg'] ) );
		}
	}

	/**
	 * Reset everything (used by the "Disconnect" button).
	 */
	public static function reset() {
		delete_option( self::OPT_STUDIO_URL );
		delete_option( self::OPT_API_KEY );
		delete_option( self::OPT_INTEGRATION_ID );
		delete_option( self::OPT_ETB_NAME );
		delete_option( self::OPT_ORG_ID );
		delete_transient( 'soldx_establishment_options' );
	}
}
