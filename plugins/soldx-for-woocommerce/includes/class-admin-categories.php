<?php
/**
 * Category mapping page — maps WC product_cat terms to Studio categories.
 *
 * Lists all WC categories with a dropdown of Studio categories next to
 * each. The mapping is stored in wp_options and used by the sync engine
 * to auto-resolve product categories on push.
 *
 * @package SoldxWooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Soldx_Admin_Categories {

	const PAGE_SLUG    = 'soldx-categories';
	const OPTION_KEY   = 'soldx_category_mapping';
	const OPTIONS_TTL  = 300; // 5-minute cache (matches Articles page).

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_soldx_create_category', array( $this, 'ajax_create_category' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Soldx Categories', 'soldx-for-woocommerce' ),
			__( 'Soldx Categories', 'soldx-for-woocommerce' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'soldx-admin',
			SOLDX_PLUGIN_URL . 'admin/assets/admin.css',
			array(),
			filemtime( SOLDX_PLUGIN_DIR . 'admin/assets/admin.css' )
		);
		// SelectWoo = WooCommerce's Select2 fork — makes the Studio category
		// dropdowns searchable (shipped with WC core).
		wp_enqueue_script( 'selectWoo' );
		wp_enqueue_script(
			'soldx-admin-categories',
			SOLDX_PLUGIN_URL . 'admin/assets/admin-categories.js',
			array( 'jquery', 'selectWoo' ),
			filemtime( SOLDX_PLUGIN_DIR . 'admin/assets/admin-categories.js' ),
			true
		);
		wp_localize_script( 'soldx-admin-categories', 'soldxCategories', array(
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'ajaxNonce'        => wp_create_nonce( 'soldx_create_category' ),
			'searchPlaceholder' => __( 'Search Studio category…', 'soldx-for-woocommerce' ),
		) );
	}

	// ------------------------------------------------------------------
	// Save handler
	// ------------------------------------------------------------------

	/**
	 * Handle the "Save mapping" form post.
	 */
	public function handle_save() {
		if ( ! isset( $_POST['soldx_action'] ) || 'save_categories' !== $_POST['soldx_action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'soldx-for-woocommerce' ) );
		}
		if ( ! soldx_verify_nonce( 'soldx_categories' ) ) {
			wp_die( esc_html__( 'Nonce expired. Please go back and try again.', 'soldx-for-woocommerce' ) );
		}

		$raw      = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : array();
		$clean    = array();
		foreach ( $raw as $wc_cat_id => $studio_cat_id ) {
			$wc_cat_id     = absint( $wc_cat_id );
			$studio_cat_id = sanitize_text_field( $studio_cat_id );
			if ( $wc_cat_id && '' !== $studio_cat_id ) {
				$clean[ $wc_cat_id ] = $studio_cat_id;
			}
		}

		update_option( self::OPTION_KEY, $clean, false );

		// Sync WC category images to Studio for all mapped categories.
		$image_synced = 0;
		$image_failed = 0;
		foreach ( $clean as $wc_cat_id => $studio_cat_id ) {
			$image_key = $this->upload_category_image( $wc_cat_id );
			if ( '' !== $image_key ) {
				try {
					soldx_api()->update_category_image( $studio_cat_id, $image_key );
					$image_synced++;
				} catch ( Soldx_Api_Exception $e ) {
					$image_failed++;
				}
			}
		}

		// Bust cache so fresh data shows on next page load.
		delete_transient( 'soldx_establishment_options' );

		$notice_msg = sprintf(
			/* translators: %d: number of mapped categories */
			_n( '%d category mapping saved.', '%d category mappings saved.', count( $clean ), 'soldx-for-woocommerce' ),
			count( $clean )
		);
		if ( $image_synced > 0 ) {
			$notice_msg .= sprintf(
				/* translators: %d: number of synced images */
				_n( ' %d category image synced.', ' %d category images synced.', $image_synced, 'soldx-for-woocommerce' ),
				$image_synced
			);
		}
		soldx_flash_notice_set( $notice_msg, 'success' );

		wp_safe_redirect( soldx_admin_url( self::PAGE_SLUG ) );
		exit;
	}

	// ------------------------------------------------------------------
	// Public API (used by the sync engine)
	// ------------------------------------------------------------------

	/**
	 * Get the stored WC → Studio category mapping.
	 *
	 * @return array Associative: wc_term_id (int) → studio_category_id (string).
	 */
	public static function get_mapping() {
		$mapping = get_option( self::OPTION_KEY, array() );
		return is_array( $mapping ) ? $mapping : array();
	}

	/**
	 * Resolve a list of WC category IDs to Studio category IDs.
	 *
	 * De-duplicates the result. Returns an empty array if no mappings exist.
	 *
	 * @param array $wc_cat_ids Array of WC term IDs.
	 * @return array Array of Studio category IDs (unique, non-empty).
	 */
	public static function resolve( $wc_cat_ids ) {
		if ( empty( $wc_cat_ids ) ) {
			return array();
		}
		$mapping   = self::get_mapping();
		$resolved  = array();
		$seen      = array();
		foreach ( $wc_cat_ids as $wc_id ) {
			$wc_id = (int) $wc_id;
			if ( ! isset( $mapping[ $wc_id ] ) ) {
				continue;
			}
			$studio_id = $mapping[ $wc_id ];
			if ( ! isset( $seen[ $studio_id ] ) ) {
				$seen[ $studio_id ]     = true;
				$resolved[] = $studio_id;
			}
		}
		return $resolved;
	}

	// ------------------------------------------------------------------
	// Render
	// ------------------------------------------------------------------

	/**
	 * Fetch establishment options with a short-lived transient cache.
	 *
	 * @return array|false
	 */
	private function get_establishment_options() {
		$cached = get_transient( 'soldx_establishment_options' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		try {
			$options = soldx_api()->get_options();
			if ( is_array( $options ) ) {
				set_transient( 'soldx_establishment_options', $options, self::OPTIONS_TTL );
				return $options;
			}
		} catch ( Soldx_Api_Exception $e ) {
			return false;
		}
		return false;
	}

	/**
	 * Render the category mapping page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'soldx-for-woocommerce' ) );
		}

		if ( ! Soldx_Auth::is_configured() ) {
			echo '<div class="wrap"><div class="notice notice-warning"><p>';
			printf(
				/* translators: %s: settings URL */
				wp_kses_post( __( 'Soldx is not configured yet. <a href="%s">Configure the plugin</a> first.', 'soldx-for-woocommerce' ) ),
				esc_url( soldx_admin_url( Soldx_Admin_Settings::PAGE_SLUG ) )
			);
			echo '</p></div></div>';
			return;
		}

		soldx_flash_notice_maybe_print();

		// Always fetch fresh Studio data — categories may have been created
		// or deleted in Studio since the last page load. This is an admin
		// page visited infrequently, so the extra API call is negligible.
		delete_transient( 'soldx_establishment_options' );

		// Fetch Studio categories from cached options.
		$options    = $this->get_establishment_options();
		$studio_cats = array();
		if ( is_array( $options ) && isset( $options['categories'] ) && is_array( $options['categories'] ) ) {
			$studio_cats = $options['categories'];
		}

		// Fetch WC categories (hierarchical).
		$wc_cats = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		) );

		$mapping    = self::get_mapping();
		$base_url   = soldx_admin_url( self::PAGE_SLUG );
		?>
		<div class="wrap soldx-wrap">
			<h1 class="soldx-title"><?php esc_html_e( 'Soldx Categories', 'soldx-for-woocommerce' ); ?>
				<a class="page-title-action" href="<?php echo esc_url( add_query_arg( 'refresh', '1', $base_url ) ); ?>"><?php esc_html_e( 'Refresh', 'soldx-for-woocommerce' ); ?></a>
			</h1>
			<p class="soldx-subtitle"><?php esc_html_e( 'Map your WooCommerce categories to Soldx Studio categories. Products pushed to Studio will be auto-categorized based on these mappings.', 'soldx-for-woocommerce' ); ?></p>

			<?php if ( empty( $studio_cats ) ) : ?>
				<div class="notice notice-warning inline">
					<p><?php
						printf(
							/* translators: %s: refresh URL */
							wp_kses_post( __( 'No Studio categories found. You can create them directly from this page using the "+ Studio" buttons, or create them in Studio first and then <a href="%s">refresh</a>.', 'soldx-for-woocommerce' ) ),
							esc_url( $base_url )
						);
					?></p>
				</div>
			<?php endif; ?>

			<?php if ( is_wp_error( $wc_cats ) || empty( $wc_cats ) ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'No WooCommerce categories found.', 'soldx-for-woocommerce' ); ?></p>
				</div>
			<?php else : ?>
				<p>
					<button type="button" class="button soldx-create-all-btn" id="soldx-create-all">
						<?php esc_html_e( 'Create All Unmapped in Studio', 'soldx-for-woocommerce' ); ?>
					</button>
				</p>
				<p class="soldx-search-wrap">
					<input type="search" id="soldx-cat-search" class="regular-text" placeholder="<?php esc_attr_e( 'Filter categories…', 'soldx-for-woocommerce' ); ?>" />
					<span class="soldx-search-count"></span>
				</p>
				<form method="post" class="soldx-sync-form">
					<input type="hidden" name="soldx_action" value="save_categories" />
					<?php soldx_nonce_field( 'soldx_categories' ); ?>

					<table class="widefat striped soldx-table">
						<thead>
							<tr>
								<th style="width:40%;"><?php esc_html_e( 'WooCommerce Category', 'soldx-for-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Studio Category', 'soldx-for-woocommerce' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $wc_cats as $term ) : ?>
								<?php
								// Indent subcategories by depth.
								$depth   = $this->get_term_depth( $term->term_id, 'product_cat' );
								$indent  = str_repeat( '— ', $depth );
								$selected = isset( $mapping[ $term->term_id ] ) ? $mapping[ $term->term_id ] : '';
								?>
								<tr>
									<td>
										<strong><?php echo esc_html( $indent . $term->name ); ?></strong>
										<br><span class="soldx-muted">#<?php echo esc_html( (string) $term->term_id ); ?> · <?php echo esc_html( $term->slug ); ?></span>
									</td>
									<td class="soldx-cat-cell">
										<select name="mapping[<?php echo esc_attr( (string) $term->term_id ); ?>]" class="soldx-select soldx-cat-select">
											<option value=""><?php esc_html_e( '— Not mapped —', 'soldx-for-woocommerce' ); ?></option>
											<?php foreach ( $studio_cats as $cat ) : ?>
												<?php
												$label = ! empty( $cat['designation'] ) ? $cat['designation'] : ( ! empty( $cat['reference'] ) ? $cat['reference'] : $cat['id'] );
												$parent_indent = '';
												if ( ! empty( $cat['idParent'] ) ) {
													$parent_indent = '— ';
												}
												?>
												<option value="<?php echo esc_attr( $cat['id'] ); ?>"
													<?php selected( $selected, $cat['id'] ); ?>
												><?php echo esc_html( $parent_indent . $label ); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="button"
											class="button button-secondary button-small soldx-create-cat-btn"
											data-wc-name="<?php echo esc_attr( $term->name ); ?>"
											data-wc-term-id="<?php echo esc_attr( (string) $term->term_id ); ?>"
											data-wc-parent="<?php echo esc_attr( (string) $term->parent ); ?>"
										><?php esc_html_e( '+ Studio', 'soldx-for-woocommerce' ); ?></button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Save Mappings', 'soldx-for-woocommerce' ); ?>
						</button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get the depth of a term in its taxonomy tree.
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 * @return int
	 */
	private function get_term_depth( $term_id, $taxonomy ) {
		$depth = 0;
		$term  = get_term( $term_id, $taxonomy );
		while ( $term && ! empty( $term->parent ) ) {
			$depth++;
			$term = get_term( $term->parent, $taxonomy );
			if ( $depth > 20 ) {
				break; // Safety guard.
			}
		}
		return $depth;
	}

	// ------------------------------------------------------------------
	// AJAX: Create Studio category on demand
	// ------------------------------------------------------------------

	/**
	 * AJAX handler for "Create in Studio" buttons.
	 *
	 * Accepts POST `designation` (the WC category name), optional `idParent`
	 * (a Studio category ID to use as parent), and optional `wcTermId`
	 * (the WC term ID, used to look up and upload the category image).
	 *
	 * Creates the Studio category with hierarchy + image, then returns
	 * the new category so the JS can add it to every dropdown.
	 */
	public function ajax_create_category() {
		check_ajax_referer( 'soldx_create_category', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'soldx-for-woocommerce' ) ), 403 );
		}

		$designation = isset( $_POST['designation'] ) ? sanitize_text_field( wp_unslash( $_POST['designation'] ) ) : '';
		$id_parent   = isset( $_POST['idParent'] ) ? sanitize_text_field( wp_unslash( $_POST['idParent'] ) ) : '';
		$wc_term_id  = isset( $_POST['wcTermId'] ) ? absint( $_POST['wcTermId'] ) : 0;

		if ( '' === $designation ) {
			wp_send_json_error( array( 'message' => __( 'Designation is required.', 'soldx-for-woocommerce' ) ), 422 );
		}

		// Try to upload the WC category image (if it has one).
		$image_key = '';
		if ( $wc_term_id ) {
			$image_key = $this->upload_category_image( $wc_term_id );
		}

		try {
			$result = soldx_api()->create_category( $designation, $id_parent, $image_key );

			// Bust the transient so next page load fetches fresh options.
			delete_transient( 'soldx_establishment_options' );

			wp_send_json_success( $result );
		} catch ( Soldx_Api_Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	/**
	 * Upload a WC category's thumbnail image to Studio.
	 *
	 * Returns the S3 key on success, or '' on failure (image is optional —
	 * we don't block category creation if the upload fails).
	 *
	 * @param int $wc_term_id WC product_cat term ID.
	 * @return string S3 key, or '' if no image or upload failed.
	 */
	private function upload_category_image( $wc_term_id ) {
		$thumbnail_id = (int) get_term_meta( $wc_term_id, 'thumbnail_id', true );
		if ( ! $thumbnail_id ) {
			return '';
		}

		$file_path = get_attached_file( $thumbnail_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return '';
		}

		$org_id = Soldx_Auth::org_id();
		if ( '' === $org_id ) {
			return '';
		}

		try {
			return soldx_api()->upload_image( $file_path, $org_id, basename( $file_path ) );
		} catch ( Soldx_Api_Exception $e ) {
			// Image upload is best-effort — don't block category creation.
			return '';
		}
	}
}
