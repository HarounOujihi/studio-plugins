<?php
/**
 * Shared helper functions.
 *
 * @package SoldxWooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get a singleton instance of the API client built from current settings.
 *
 * @return Soldx_Api_Client
 */
function soldx_api() {
	static $client = null;
	if ( null === $client ) {
		$client = new Soldx_Api_Client();
	}
	return $client;
}

/**
 * Get a mapping store instance (wrapper for readability).
 *
 * @return Soldx_Mapping_Store
 */
function soldx_store() {
	return Soldx_Mapping_Store::instance();
}

/**
 * Render a notice row consistent with WP admin styling.
 *
 * @param string $message HTML-free message.
 * @param string $type     One of: success|error|warning|info.
 */
function soldx_admin_notice( $message, $type = 'info' ) {
	$map = array(
		'success' => 'notice-success',
		'error'   => 'notice-error',
		'warning' => 'notice-warning',
		'info'    => 'notice-info',
	);
	$class = isset( $map[ $type ] ) ? $map[ $type ] : 'notice-info';
	echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>';
	echo wp_kses_post( $message );
	echo '</p></div>';
}

/**
 * Inline (transient) notice stored in a session-like option for one render.
 *
 * Uses a request-scoped static array instead of sessions, flushed on
 * redirect via the `soldx_notice` query var.
 *
 * @param string $message
 * @param string $type
 */
function soldx_flash_notice_set( $message, $type = 'info' ) {
	set_transient(
		'soldx_flash_' . get_current_user_id(),
		array(
			'message' => $message,
			'type'    => $type,
		),
		60
	);
}

/**
 * Emit and clear a flash notice if present.
 */
function soldx_flash_notice_maybe_print() {
	$key = 'soldx_flash_' . get_current_user_id();
	$bag = get_transient( $key );
	if ( ! empty( $bag['message'] ) ) {
		soldx_admin_notice( $bag['message'], isset( $bag['type'] ) ? $bag['type'] : 'info' );
		delete_transient( $key );
	}
}

/**
 * Format a money amount for display using the establishment currency if known.
 *
 * @param float  $amount
 * @param string $currency_code Optional ISO 4217 code; falls back to store currency.
 * @return string
 */
function soldx_format_money( $amount, $currency_code = '' ) {
	if ( '' === $currency_code ) {
		$currency_code = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
	}
	$symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $currency_code ) : $currency_code;
	return sprintf( '%s %s', number_format( (float) $amount, 2, '.', '' ), $symbol );
}

/**
 * Generate a nonce field for admin forms (echoes).
 *
 * @param string $action
 */
function soldx_nonce_field( $action ) {
	wp_nonce_field( $action, 'soldx_nonce' );
}

/**
 * Verify a nonce from the current request.
 *
 * Uses $_POST directly (more reliable for POST forms) instead of $_REQUEST.
 * This avoids issues where Cookie or GET values might override POST values
 * in $_REQUEST due to PHP's variables_order/request_order settings.
 *
 * @param string $action
 * @return bool
 */
function soldx_verify_nonce( $action ) {
	$token = isset( $_POST['soldx_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['soldx_nonce'] ) ) : '';
	return '' !== $token && (bool) wp_verify_nonce( $token, $action );
}

/**
 * Small helper for building admin URLs relative to the plugin's admin slug.
 *
 * @param string $page
 * @param array  $args
 * @return string
 */
function soldx_admin_url( $page, $args = array() ) {
	$args         = array_merge( array( 'page' => $page ), $args );
	$admin_anchor = 'admin.php';
	// Settings pages registered under wc-marketing|woocommerce use a different anchor.
	if ( 0 === strpos( $page, 'woocommerce' ) ) {
		$admin_anchor = 'admin.php';
	}
	return add_query_arg( $args, admin_url( $admin_anchor ) );
}
