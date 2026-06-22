<?php
/**
 * Plugin Name:       Soldx for WooCommerce
 * Plugin URI:        https://soldx.tn
 * Description:       Push WooCommerce products into Soldx Studio. Manual selection, per-article units/deposit, no auto-sync.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Soldx
 * Author URI:        https://soldx.tn
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       soldx-woocommerce
 * Domain Path:       /languages
 *
 * WC requires at least: 7.8
 * WC tested up to:      9.x
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'SOLDX_VERSION', '0.1.0' );
define( 'SOLDX_PLUGIN_FILE', __FILE__ );
define( 'SOLDX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOLDX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SOLDX_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Minimum requirements check.
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Soldx for WooCommerce requires the WooCommerce plugin to be active.', 'soldx-woocommerce' );
			echo '</p></div>';
		} );
		return;
	}

	soldx_bootstrap_plugin();
} );

/**
 * Bootstrap the plugin internals.
 *
 * Reaches here only after WooCommerce is confirmed active.
 */
function soldx_bootstrap_plugin() {
	// PSR-4-ish manual loader (no Composer dependency for portability).
	require_once SOLDX_PLUGIN_DIR . 'includes/class-mapping-store.php';
	require_once SOLDX_PLUGIN_DIR . 'includes/class-auth.php';
	require_once SOLDX_PLUGIN_DIR . 'includes/class-api-client.php';
	require_once SOLDX_PLUGIN_DIR . 'includes/class-sync-engine.php';
	require_once SOLDX_PLUGIN_DIR . 'includes/class-admin-settings.php';
	require_once SOLDX_PLUGIN_DIR . 'includes/class-admin-articles.php';
	require_once SOLDX_PLUGIN_DIR . 'includes/helpers.php';

	// Initialize singletons.
	Soldx_Mapping_Store::instance();
	Soldx_Sync_Engine::instance();
	Soldx_Admin_Settings::instance();
	Soldx_Admin_Articles::instance();
}

// Hooks ----------------------------------------------------------------------

register_activation_hook( __FILE__, function () {
	require_once SOLDX_PLUGIN_DIR . 'includes/class-mapping-store.php';
	Soldx_Mapping_Store::instance()->create_table();
	// Default options
	if ( false === get_option( 'soldx_studio_url', false ) ) {
		add_option( 'soldx_studio_url', '' );
	}
	if ( false === get_option( 'soldx_api_key', false ) ) {
		add_option( 'soldx_api_key', '' );
	}
	if ( false === get_option( 'soldx_integration_id', false ) ) {
		add_option( 'soldx_integration_id', '' );
	}
	if ( false === get_option( 'soldx_establishment_name', false ) ) {
		add_option( 'soldx_establishment_name', '' );
	}
	flush_rewrite_rules();
} );
