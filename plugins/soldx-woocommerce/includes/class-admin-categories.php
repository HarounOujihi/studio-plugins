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
			__( 'Soldx Categories', 'soldx-woocommerce' ),
			__( 'Soldx Categories', 'soldx-woocommerce' ),
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
			wp_die( esc_html__( 'Insufficient permissions.', 'soldx-woocommerce' ) );
		}
		if ( ! soldx_verify_nonce( 'soldx_categories' ) ) {
			wp_die( esc_html__( 'Nonce expired. Please go back and try again.', 'soldx-woocommerce' ) );
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
			_n( '%d category mapping saved.', '%d category mappings saved.', count( $clean ), 'soldx-woocommerce' ),
			count( $clean )
		);
		if ( $image_synced > 0 ) {
			$notice_msg .= sprintf(
				/* translators: %d: number of synced images */
				_n( ' %d category image synced.', ' %d category images synced.', $image_synced, 'soldx-woocommerce' ),
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
			wp_die( esc_html__( 'Insufficient permissions.', 'soldx-woocommerce' ) );
		}

		if ( ! Soldx_Auth::is_configured() ) {
			echo '<div class="wrap"><div class="notice notice-warning"><p>';
			printf(
				wp_kses_post( __( 'Soldx is not configured yet. <a href="%s">Configure the plugin</a> first.', 'soldx-woocommerce' ) ),
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
		$ajax_nonce = wp_create_nonce( 'soldx_create_category' );
		$ajax_url   = admin_url( 'admin-ajax.php' );
		?>
		<div class="wrap soldx-wrap">
			<h1 class="soldx-title"><?php esc_html_e( 'Soldx Categories', 'soldx-woocommerce' ); ?>
				<a class="page-title-action" href="<?php echo esc_url( add_query_arg( 'refresh', '1', $base_url ) ); ?>"><?php esc_html_e( 'Refresh', 'soldx-woocommerce' ); ?></a>
			</h1>
			<p class="soldx-subtitle"><?php esc_html_e( 'Map your WooCommerce categories to Soldx Studio categories. Products pushed to Studio will be auto-categorized based on these mappings.', 'soldx-woocommerce' ); ?></p>

			<?php if ( empty( $studio_cats ) ) : ?>
				<div class="notice notice-warning inline">
					<p><?php
						printf(
							wp_kses_post( __( 'No Studio categories found. You can create them directly from this page using the "+ Studio" buttons, or create them in Studio first and then <a href="%s">refresh</a>.', 'soldx-woocommerce' ) ),
							esc_url( $base_url )
						);
					?></p>
				</div>
			<?php endif; ?>

			<?php if ( is_wp_error( $wc_cats ) || empty( $wc_cats ) ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'No WooCommerce categories found.', 'soldx-woocommerce' ); ?></p>
				</div>
			<?php else : ?>
				<p>
					<button type="button" class="button soldx-create-all-btn" id="soldx-create-all">
						<?php esc_html_e( 'Create All Unmapped in Studio', 'soldx-woocommerce' ); ?>
					</button>
				</p>
				<p class="soldx-search-wrap">
					<input type="search" id="soldx-cat-search" class="regular-text" placeholder="<?php esc_attr_e( 'Filter categories…', 'soldx-woocommerce' ); ?>" />
					<span class="soldx-search-count"></span>
				</p>
				<form method="post" class="soldx-sync-form">
					<input type="hidden" name="soldx_action" value="save_categories" />
					<?php soldx_nonce_field( 'soldx_categories' ); ?>

					<table class="widefat striped soldx-table">
						<thead>
							<tr>
								<th style="width:40%;"><?php esc_html_e( 'WooCommerce Category', 'soldx-woocommerce' ); ?></th>
								<th><?php esc_html_e( 'Studio Category', 'soldx-woocommerce' ); ?></th>
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
											<option value=""><?php esc_html_e( '— Not mapped —', 'soldx-woocommerce' ); ?></option>
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
										><?php esc_html_e( '+ Studio', 'soldx-woocommerce' ); ?></button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Save Mappings', 'soldx-woocommerce' ); ?>
						</button>
					</p>
				</form>

				<script>
				(function($) {
					'use strict';

					var ajaxUrl   = '<?php echo esc_js( $ajax_url ); ?>';
					var ajaxNonce = '<?php echo esc_js( $ajax_nonce ); ?>';

					/**
					 * Live search/filter for the categories table.
					 */
					$('#soldx-cat-search').on('input', function() {
						var query = $(this).val().toLowerCase().trim();
						var $rows = $('.soldx-table tbody tr');
						var visible = 0;
						$rows.each(function() {
							var text = $(this).find('td').first().text().toLowerCase();
							var match = !query || text.indexOf(query) !== -1;
							$(this).toggle(match);
							if (match) visible++;
						});
						$('.soldx-search-count').text(
							query ? visible + ' / ' + $rows.length + ' matches' : ''
						);
					});

					/**
					 * Make Studio category dropdowns searchable via SelectWoo
					 * (WC's Select2 fork). If SelectWoo isn't available the
					 * selects fall back to plain HTML dropdowns.
					 */
					if ($.fn.selectWoo) {
						$('.soldx-cat-select').selectWoo({
							placeholder: '<?php echo esc_js( __( 'Search Studio category…', 'soldx-woocommerce' ) ); ?>',
							allowClear: true,
							width: '65%'
						});
					}

					/**
					 * Add a newly-created category as an <option> to every
					 * dropdown on the page.
					 */
					function addCategoryToAllSelects( cat ) {
						var label = cat.designation || cat.reference || cat.id;
						$('.soldx-cat-select').each(function() {
							if ($(this).find('option[value="' + cat.id + '"]').length === 0) {
								$(this).append('<option value="' + cat.id + '">' + label + '</option>');
							}
						});
					}

					/**
					 * Resolve the Studio parent ID for a button.
					 *
					 * Checks data-wc-parent to find the WC parent term, then
					 * looks up that parent's row select value (its Studio
					 * category ID). Returns '' if no parent or parent not mapped.
					 */
					function resolveParentId( btn ) {
						var wcParent = String(btn.data('wc-parent') || '0');
						if (!wcParent || wcParent === '0') return '';
						var parentBtn = $('.soldx-create-cat-btn[data-wc-term-id="' + wcParent + '"]');
						if (!parentBtn.length) return '';
						var parentSel = parentBtn.siblings('.soldx-cat-select');
						return parentSel.val() || '';
					}

					/**
					 * Create a single category via AJAX.
					 *
					 * @param {string} name     WC category name.
					 * @param {string} idParent Studio parent category ID ('' if none).
					 * @param {string} termId   WC term ID (for image lookup).
					 * @return {Promise} Resolves with the created category object.
					 */
					function createCategory(name, idParent, termId) {
						return $.post(ajaxUrl, {
							action:      'soldx_create_category',
							nonce:       ajaxNonce,
							designation: name,
							idParent:    idParent || '',
							wcTermId:    termId || ''
						}).then(function(resp) {
							if (resp && resp.success) {
								return resp.data;
							}
							throw new Error((resp && resp.data && resp.data.message) || 'Unknown error');
						});
					}

					/**
					 * Handle a single "+ Studio" button click.
					 */
					$('.soldx-create-cat-btn').on('click', function() {
						var btn      = $(this);
						var name     = btn.data('wc-name');
						var termId   = String(btn.data('wc-term-id') || '');
						var idParent = resolveParentId(btn);
						var sel      = btn.siblings('.soldx-cat-select');

						btn.prop('disabled', true).text('Creating…');

						createCategory(name, idParent, termId).then(function(cat) {
							addCategoryToAllSelects(cat);
							sel.val(cat.id).trigger("change");
							btn.removeClass('button-secondary').addClass('button-primary').text('✓ Created').prop('disabled', true);
						}).catch(function(err) {
							btn.prop('disabled', false).text('+ Studio');
							alert('Error creating category: ' + err.message);
						});
					});

					/**
					 * "Create All Unmapped" — processes rows by depth so that
					 * parents are always created before children. Uses a
					 * createdMap to pass parent IDs to children created in
					 * the same batch.
					 */
					$('#soldx-create-all').on('click', function() {
						var btn  = $(this);
						var rows = [];

						// Collect all unmapped rows.
						$('.soldx-cat-select').each(function() {
							if (!$(this).val()) {
								var createBtn = $(this).siblings('.soldx-create-cat-btn');
								var name = createBtn.data('wc-name');
								if (name && !createBtn.prop('disabled')) {
									rows.push({
										sel: $(this),
										btn: createBtn,
										name: String(name),
										termId: String(createBtn.data('wc-term-id') || ''),
										wcParent: String(createBtn.data('wc-parent') || '0')
									});
								}
							}
						});

						if (rows.length === 0) {
							alert('No unmapped categories to create.');
							return;
						}

						if (!confirm('Create ' + rows.length + ' categor' + (rows.length > 1 ? 'ies' : 'y') + ' in Studio?')) {
							return;
						}

						// Map: wc_term_id → studio_cat_id (for children created in same batch).
						var createdMap = {};
						var done = 0, failed = 0;

						btn.prop('disabled', true).text('Processing…');

						// Sort by depth so parents are always created before children.
						var parentMap = {};
						$('.soldx-create-cat-btn').each(function() {
							var tid = String($(this).data('wc-term-id') || '');
							var pid = String($(this).data('wc-parent') || '0');
							parentMap[tid] = pid;
						});
						function getDepth(termId) {
							var d = 0, cur = termId, g = 0;
							while (parentMap[cur] && parentMap[cur] !== '0' && g < 20) { d++; cur = parentMap[cur]; g++; }
							return d;
						}
						rows.sort(function(a, b) { return getDepth(a.termId) - getDepth(b.termId); });

						// Process sequentially to guarantee parents exist first.
						function processNext(index) {
							if (index >= rows.length) {
								btn.prop('disabled', false).text('Create All Unmapped in Studio');
								if (failed > 0) {
									alert('Done: ' + done + ' created, ' + failed + ' failed. Check console (F12) for details.');
								}
								return;
							}

							var row = rows[index];
							row.btn.prop('disabled', true).text('Creating…');

							// Resolve parent: check createdMap first, then sibling select.
							var idParent = '';
							if (row.wcParent && row.wcParent !== '0') {
								if (createdMap[row.wcParent]) {
									idParent = createdMap[row.wcParent];
								} else {
									var parentBtn = $('.soldx-create-cat-btn[data-wc-term-id="' + row.wcParent + '"]');
									var parentSel = parentBtn.siblings('.soldx-cat-select');
									idParent = parentSel.val() || '';
								}
							}

							createCategory(row.name, idParent, row.termId).then(function(cat) {
								addCategoryToAllSelects(cat);
								row.sel.val(cat.id).trigger("change");
								createdMap[row.termId] = cat.id;
								row.btn.removeClass('button-secondary').addClass('button-primary').text('✓');
								done++;
							}).catch(function(err) {
								row.btn.prop('disabled', false).text('+ Studio');
								failed++;
								console.error('Failed to create "' + row.name + '":', err.message);
							}).always(function() {
								processNext(index + 1);
							});
						}

						processNext(0);
					});
				})(jQuery);
				</script>
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
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'soldx-woocommerce' ) ), 403 );
		}

		$designation = isset( $_POST['designation'] ) ? sanitize_text_field( wp_unslash( $_POST['designation'] ) ) : '';
		$id_parent   = isset( $_POST['idParent'] ) ? sanitize_text_field( wp_unslash( $_POST['idParent'] ) ) : '';
		$wc_term_id  = isset( $_POST['wcTermId'] ) ? absint( $_POST['wcTermId'] ) : 0;

		if ( '' === $designation ) {
			wp_send_json_error( array( 'message' => __( 'Designation is required.', 'soldx-woocommerce' ) ), 422 );
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
