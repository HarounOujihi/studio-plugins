<?php
/**
 * Uninstall handler — runs when the user clicks "Delete" in WP admin.
 * Removes all plugin data (options + custom table).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the mappings table.
$table = $wpdb->prefix . 'soldx_mappings';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Clean options.
foreach ( array(
	'soldx_studio_url',
	'soldx_api_key',
	'soldx_integration_id',
	'soldx_establishment_name',
	'soldx_org_id',
	'soldx_category_mapping', // WC → Studio category id map
) as $option ) {
	delete_option( $option );
}

// Clean up transients.
delete_transient( 'soldx_establishment_options' );
