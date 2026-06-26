<?php
/**
 * Local mapping table — mirrors Studio's ArticleExternalMapping so the plugin
 * keeps working when Studio is unreachable.
 *
 * Table: {wp_prefix}soldx_mappings
 *   id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   studio_article_id VARCHAR(64)  NOT NULL
 *   integration_id    VARCHAR(64)  NOT NULL
 *   wc_product_id     BIGINT UNSIGNED NOT NULL
 *   wc_sku            VARCHAR(255) NULL
 *   sync_status       VARCHAR(32)  NOT NULL DEFAULT 'PENDING'
 *   is_enabled        TINYINT(1)   NOT NULL DEFAULT 1
 *   last_sync_at      DATETIME     NULL
 *   last_error        TEXT         NULL
 *   payload_hash      CHAR(64)     NULL
 *   UNIQUE (integration_id, studio_article_id)
 *   UNIQUE (wc_product_id)
 *
 * @package SoldxWooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Soldx_Mapping_Store {

	private static $instance = null;
	private $table;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'soldx_mappings';
	}

	public function table_name() {
		return $this->table;
	}

	/**
	 * Create the table on activation. Uses dbDelta for forward-compatible migrations.
	 */
	public function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			studio_article_id VARCHAR(64) NOT NULL,
			integration_id VARCHAR(64) NOT NULL,
			wc_product_id BIGINT UNSIGNED NOT NULL,
			wc_sku VARCHAR(255) NULL,
			sync_status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
			is_enabled TINYINT(1) NOT NULL DEFAULT 1,
			last_sync_at DATETIME NULL,
			last_error TEXT NULL,
			payload_hash CHAR(64) NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY integration_article (integration_id, studio_article_id),
			UNIQUE KEY wc_product (wc_product_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	// ------------------------------------------------------------------
	// Lookups
	// ------------------------------------------------------------------

	/**
	 * Get a mapping by Studio article id (scoped to current integration).
	 *
	 * @param string $studio_article_id
	 * @return object|null
	 */
	public function get_by_studio_id( $studio_article_id ) {
		global $wpdb;
		$integration_id = get_option( 'soldx_integration_id', '' );
		if ( ! $integration_id ) {
			return null;
		}
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE integration_id = %s AND studio_article_id = %s LIMIT 1",
				$integration_id,
				$studio_article_id
			)
		);
	}

	/**
	 * Get a mapping by WC product id.
	 *
	 * @param int $wc_product_id
	 * @return object|null
	 */
	public function get_by_wc_id( $wc_product_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE wc_product_id = %d LIMIT 1",
				$wc_product_id
			)
		);
	}

	/**
	 * Get all mappings for the current integration, optionally filtered.
	 *
	 * @param array $args {
	 *     Optional. Filters.
	 *     @type bool   $enabled_only
	 *     @type int    $limit
	 *     @type int    $offset
	 *     @type string $status
	 * }
	 * @return array
	 */
	public function list_mappings( $args = array() ) {
		global $wpdb;
		$integration_id = get_option( 'soldx_integration_id', '' );
		if ( ! $integration_id ) {
			return array();
		}

		$where  = "WHERE integration_id = %s";
		$params = array( $integration_id );

		if ( ! empty( $args['enabled_only'] ) ) {
			$where   .= ' AND is_enabled = 1';
		}
		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND sync_status = %s';
			$params[] = $args['status'];
		}

		$limit  = ! empty( $args['limit'] ) ? (int) $args['limit'] : 100;
		$offset = ! empty( $args['offset'] ) ? (int) $args['offset'] : 0;

		$params[] = $limit;
		$params[] = $offset;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} {$where} ORDER BY updated_at DESC LIMIT %d OFFSET %d",
				$params
			)
		);
	}

	// ------------------------------------------------------------------
	// Mutations
	// ------------------------------------------------------------------

	/**
	 * Insert or update a mapping after a successful sync.
	 *
	 * @param array $data {
	 *     @type string $studio_article_id
	 *     @type int    $wc_product_id
	 *     @type string $wc_sku
	 *     @type string $payload_hash
	 * }
	 * @return bool
	 */
	public function upsert( $data ) {
		global $wpdb;
		$integration_id = get_option( 'soldx_integration_id', '' );
		if ( ! $integration_id || empty( $data['studio_article_id'] ) || empty( $data['wc_product_id'] ) ) {
			return false;
		}

		$existing = $this->get_by_studio_id( $data['studio_article_id'] );

		$row = array(
			'integration_id'    => $integration_id,
			'studio_article_id' => $data['studio_article_id'],
			'wc_product_id'     => (int) $data['wc_product_id'],
			'wc_sku'            => isset( $data['wc_sku'] ) ? $data['wc_sku'] : null,
			'sync_status'       => 'SYNCED',
			'is_enabled'        => 1,
			'last_sync_at'      => current_time( 'mysql' ),
			'last_error'        => null,
			'payload_hash'      => isset( $data['payload_hash'] ) ? $data['payload_hash'] : null,
			'updated_at'        => current_time( 'mysql' ),
		);

		if ( $existing ) {
			return false !== $wpdb->update(
				$this->table,
				$row,
				array( 'id' => $existing->id ),
				array( '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		$row['created_at'] = current_time( 'mysql' );
		return false !== $wpdb->insert(
			$this->table,
			$row,
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Record a sync error on a mapping.
	 */
	public function set_error( $studio_article_id, $message ) {
		global $wpdb;
		$integration_id = get_option( 'soldx_integration_id', '' );
		if ( ! $integration_id ) {
			return false;
		}
		return false !== $wpdb->update(
			$this->table,
			array(
				'sync_status' => 'ERROR',
				'last_error'  => $message,
				'updated_at'  => current_time( 'mysql' ),
			),
			array(
				'integration_id'    => $integration_id,
				'studio_article_id' => $studio_article_id,
			),
			array( '%s', '%s', '%s' ),
			array( '%s', '%s' )
		);
	}

	/**
	 * Mark a mapping disabled (user unselected it in WP UI).
	 */
	public function set_disabled( $studio_article_id ) {
		global $wpdb;
		$integration_id = get_option( 'soldx_integration_id', '' );
		if ( ! $integration_id ) {
			return false;
		}
		return false !== $wpdb->update(
			$this->table,
			array(
				'is_enabled'  => 0,
				'sync_status' => 'DELETED_REMOTE',
				'updated_at'  => current_time( 'mysql' ),
			),
			array(
				'integration_id'    => $integration_id,
				'studio_article_id' => $studio_article_id,
			),
			array( '%d', '%s', '%s' ),
			array( '%s', '%s' )
		);
	}

	/**
	 * Delete a mapping by WC product id (cleans up stale local entries).
	 *
	 * @param int $wc_product_id
	 * @return bool
	 */
	public function delete_by_wc_id( $wc_product_id ) {
		global $wpdb;
		return false !== $wpdb->delete(
			$this->table,
			array( 'wc_product_id' => (int) $wc_product_id ),
			array( '%d' )
		);
	}

	/**
	 * Build a lookup map: studio_article_id → mapping row, for a list of ids.
	 *
	 * @param array $studio_ids
	 * @return array associative
	 */
	public function map_for_studio_ids( $studio_ids ) {
		global $wpdb;
		$integration_id = get_option( 'soldx_integration_id', '' );
		if ( ! $integration_id || empty( $studio_ids ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $studio_ids ), '%s' ) );
		$params       = array_merge( array( $integration_id ), $studio_ids );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE integration_id = %s AND studio_article_id IN ({$placeholders})",
				$params
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[ $row->studio_article_id ] = $row;
		}
		return $out;
	}
}
