<?php
/**
 * Admin articles selection page.
 *
 * Lists WooCommerce products, lets the user pick which ones to push into
 * Soldx Studio, and for each pick a sale unit / purchase unit / deposit.
 * Runs the sync engine (which pushes to Studio) on demand.
 *
 * Direction: WooCommerce → Studio.
 *
 * @package SoldxWooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Soldx_Admin_Articles {

	const PAGE_SLUG     = 'soldx-articles';
	const OPTIONS_TTL   = 300; // 5-minute cache for establishment options.

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_sync' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Soldx Articles', 'soldx-woocommerce' ),
			__( 'Soldx Articles', 'soldx-woocommerce' ),
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
			SOLDX_VERSION
		);
	}

	/**
	 * Handle "Sync selected" bulk action.
	 */
	public function handle_sync() {
		if ( ! isset( $_POST['soldx_action'] ) || 'sync_selected' !== $_POST['soldx_action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'soldx-woocommerce' ) );
		}
		if ( ! soldx_verify_nonce( 'soldx_sync' ) ) {
			wp_die( esc_html__( 'Nonce expired. Please go back and try again.', 'soldx-woocommerce' ) );
		}

		$ids = isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] )
			? array_map( 'absint', wp_unslash( $_POST['product_ids'] ) )
			: array();

		if ( empty( $ids ) ) {
			soldx_flash_notice_set( __( 'No products selected.', 'soldx-woocommerce' ), 'warning' );
			wp_safe_redirect( $this->redirect_back() );
			exit;
		}

		// Read per-product overrides from the form.
		$raw_overrides = isset( $_POST['overrides'] ) && is_array( $_POST['overrides'] )
			? wp_unslash( $_POST['overrides'] )
			: array();

		$ok        = 0;
		$failed    = 0;
		$skipped   = 0;
		$errors    = array();
		$engine    = Soldx_Sync_Engine::instance();

		foreach ( $ids as $pid ) {
			$ov = $this->extract_overrides( $pid, $raw_overrides );

			// saleUnitId is mandatory (D8).
			if ( empty( $ov['saleUnitId'] ) ) {
				$skipped++;
				$errors[] = sprintf( '#%d: %s', $pid, __( 'missing sale unit', 'soldx-woocommerce' ) );
				continue;
			}

			$result = $engine->sync_product( $pid, $ov );

			if ( ! empty( $result['success'] ) ) {
				$ok++;
			} else {
				$failed++;
				$msg = isset( $result['error'] ) ? $result['error'] : __( 'Unknown error', 'soldx-woocommerce' );
				$errors[] = sprintf( '#%d: %s', $pid, $msg );
			}
		}

		$summary = sprintf(
			/* translators: 1: synced count */
			_n( 'Synced %1$d product to Studio.', 'Synced %1$d products to Studio.', $ok, 'soldx-woocommerce' ),
			$ok
		);
		if ( $failed > 0 ) {
			$summary .= ' ' . sprintf(
				/* translators: %d: failed count */
				_n( '%d failed.', '%d failed.', $failed, 'soldx-woocommerce' ),
				$failed
			);
		}
		if ( $skipped > 0 ) {
			$summary .= ' ' . sprintf(
				/* translators: %d: skipped count */
				_n( '%d skipped.', '%d skipped.', $skipped, 'soldx-woocommerce' ),
				$skipped
			);
		}
		if ( ! empty( $errors ) ) {
			$summary .= '<br><details><summary>' . esc_html__( 'Show errors', 'soldx-woocommerce' ) . '</summary><ul style="margin-top:6px">';
			foreach ( array_slice( $errors, 0, 20 ) as $err ) {
				$summary .= '<li><code>' . esc_html( $err ) . '</code></li>';
			}
			$summary .= '</ul></details>';
		}

		soldx_flash_notice_set( $summary, $failed > 0 ? ( $ok > 0 ? 'warning' : 'error' ) : 'success' );

		wp_safe_redirect( $this->redirect_back() );
		exit;
	}

	/**
	 * Extract a clean overrides array for a single product id from the raw POST.
	 *
	 * @param int   $pid
	 * @param array $raw
	 * @return array
	 */
	private function extract_overrides( $pid, $raw ) {
		$out = array();
		$key = (string) $pid;
		if ( ! isset( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
			// published defaults to true even if no overrides were sent.
			$out['published'] = true;
			return $out;
		}
		foreach ( array( 'saleUnitId', 'purchaseUnitId', 'depositId', 'reference' ) as $field ) {
			if ( isset( $raw[ $key ][ $field ] ) ) {
				$val = sanitize_text_field( $raw[ $key ][ $field ] );
				if ( '' !== $val ) {
					$out[ $field ] = $val;
				}
			}
		}
		// Checkbox: present = true, absent = false.
		$out['published'] = isset( $raw[ $key ]['published'] );
		return $out;
	}

	/**
	 * Build a redirect target preserving the page the user was on.
	 */
	private function redirect_back() {
		$page = isset( $_POST['return_page'] ) ? max( 1, (int) $_POST['return_page'] ) : 1;
		$q    = isset( $_POST['return_q'] ) ? sanitize_text_field( wp_unslash( $_POST['return_q'] ) ) : '';
		return soldx_admin_url( self::PAGE_SLUG, array_filter( array(
			'paged' => $page,
			'q'     => $q,
		) ) );
	}

	// ------------------------------------------------------------------
	// Establishment options (cached)
	// ------------------------------------------------------------------

	/**
	 * Fetch establishment options (units/deposits/taxes/defaults) with a
	 * short-lived transient cache so we don't hit Studio on every render.
	 *
	 * @return array|false EstablishmentOptions or false on failure.
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
	 * Read the default sale/purchase unit + deposit ids from the cached
	 * establishment options (set on the Studio side in integration.config).
	 *
	 * @return array
	 */
	private function get_defaults() {
		$options = $this->get_establishment_options();
		$config  = is_array( $options ) && isset( $options['config'] ) && is_array( $options['config'] )
			? $options['config']
			: array();
		return array(
			'saleUnitId'     => isset( $config['defaultSaleUnitId'] ) ? $config['defaultSaleUnitId'] : '',
			'purchaseUnitId' => isset( $config['defaultPurchaseUnitId'] ) ? $config['defaultPurchaseUnitId'] : '',
			'depositId'      => isset( $config['defaultDepositId'] ) ? $config['defaultDepositId'] : '',
		);
	}

	// ------------------------------------------------------------------
	// Render
	// ------------------------------------------------------------------

	/**
	 * Render the articles selection page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'soldx-woocommerce' ) );
		}

		if ( ! Soldx_Auth::is_configured() ) {
			echo '<div class="wrap"><div class="notice notice-warning"><p>';
			printf(
				/* translators: %s: settings URL */
				wp_kses_post( __( 'Soldx is not configured yet. <a href="%s">Configure the plugin</a> first.', 'soldx-woocommerce' ) ),
				esc_url( soldx_admin_url( Soldx_Admin_Settings::PAGE_SLUG ) )
			);
			echo '</p></div></div>';
			return;
		}

		$page      = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$search    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$page_size = 25;

		soldx_flash_notice_maybe_print();

		// Fetch establishment options once for the whole page.
		$options = $this->get_establishment_options();
		if ( false === $options ) {
			echo '<div class="wrap soldx-wrap">';
			echo '<h1 class="soldx-title">' . esc_html__( 'Soldx Articles', 'soldx-woocommerce' ) . '</h1>';
			soldx_admin_notice(
				__(
					'Could not load establishment options from Studio. Check your connection in Settings, then retry.',
					'soldx-woocommerce'
				),
				'error'
			);
			echo '<p><a class="button" href="' . esc_url( soldx_admin_url( Soldx_Admin_Settings::PAGE_SLUG ) ) . '">' . esc_html__( 'Open Settings', 'soldx-woocommerce' ) . '</a></p>';
			echo '</div>';
			return;
		}

		$units    = isset( $options['units'] ) && is_array( $options['units'] ) ? $options['units'] : array();
		$deposits = isset( $options['deposits'] ) && is_array( $options['deposits'] ) ? $options['deposits'] : array();
		$defaults = $this->get_defaults();

		// Fall back to first available item when no config default is set.
		if ( empty( $defaults['saleUnitId'] ) && ! empty( $units[0]['id'] ) ) {
			$defaults['saleUnitId'] = $units[0]['id'];
		}
		if ( empty( $defaults['purchaseUnitId'] ) && ! empty( $units[0]['id'] ) ) {
			$defaults['purchaseUnitId'] = $units[0]['id'];
		}
		if ( empty( $defaults['depositId'] ) && ! empty( $deposits[0]['id'] ) ) {
			$defaults['depositId'] = $deposits[0]['id'];
		}

		// Fetch WC products for this page.
		$query = $this->query_wc_products( $page, $page_size, $search );
		$items = isset( $query['items'] ) ? $query['items'] : array();
		$total = isset( $query['total'] ) ? $query['total'] : 0;
		$pages = max( 1, (int) ceil( $total / $page_size ) );

		// Build a lookup of existing mappings by WC product id (one round trip).
		$mappings = array();
		if ( ! empty( $items ) ) {
			$store   = soldx_store();
			$wc_ids  = array();
			foreach ( $items as $p ) {
				$wc_ids[] = $p->get_id();
			}
			// map_for_wc_ids is a thin wrapper; fall back to per-id lookups.
			foreach ( $wc_ids as $wid ) {
				$m = $store->get_by_wc_id( $wid );
				if ( $m ) {
					$mappings[ $wid ] = $m;
				}
			}
		}

		$base_url = soldx_admin_url( self::PAGE_SLUG );
		?>
		<div class="wrap soldx-wrap">
			<h1 class="soldx-title"><?php esc_html_e( 'Soldx Articles', 'soldx-woocommerce' ); ?>
				<a class="page-title-action" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Refresh', 'soldx-woocommerce' ); ?></a>
			</h1>
			<p class="soldx-subtitle"><?php esc_html_e( 'Select WooCommerce products to push into Soldx Studio. Choose a sale unit (required), purchase unit, and deposit for each.', 'soldx-woocommerce' ); ?></p>

			<form method="get" class="soldx-search-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<label class="screen-reader-text" for="soldx-q"><?php esc_html_e( 'Search products', 'soldx-woocommerce' ); ?></label>
				<input
					type="search"
					id="soldx-q"
					name="q"
					value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php esc_attr_e( 'Search by name or SKU…', 'soldx-woocommerce' ); ?>"
					class="regular-text"
				/>
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'soldx-woocommerce' ); ?></button>
				<?php if ( '' !== $search ) : ?>
					<a class="button button-link" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Clear', 'soldx-woocommerce' ); ?></a>
				<?php endif; ?>
			</form>

			<form method="post" class="soldx-sync-form" id="soldx-sync-form">
				<input type="hidden" name="soldx_action" value="sync_selected" />
				<input type="hidden" name="return_page" value="<?php echo esc_attr( (string) $page ); ?>" />
				<input type="hidden" name="return_q" value="<?php echo esc_attr( $search ); ?>" />
				<?php soldx_nonce_field( 'soldx_sync' ); ?>

				<div class="tablenav top soldx-tablenav">
					<div class="alignleft actions bulkactions">
						<button type="submit" class="button button-primary soldx-bulk-sync" id="soldx-bulk-sync">
							<?php esc_html_e( 'Push selected to Studio', 'soldx-woocommerce' ); ?>
						</button>
					</div>
					<div class="tablenav-pages">
						<?php echo wp_kses_post( $this->pagination( $page, $pages, $total, $search, $base_url ) ); ?>
					</div>
				</div>

				<table class="widefat striped soldx-table">
					<thead>
						<tr>
							<th class="soldx-check"><input type="checkbox" id="soldx-select-all" /></th>
							<th class="soldx-thumb"><?php esc_html_e( 'Image', 'soldx-woocommerce' ); ?></th>
							<th class="soldx-name"><?php esc_html_e( 'Product', 'soldx-woocommerce' ); ?></th>
							<th class="soldx-sku"><?php esc_html_e( 'SKU', 'soldx-woocommerce' ); ?></th>
							<th class="soldx-price"><?php esc_html_e( 'Reg. Price', 'soldx-woocommerce' ); ?></th>
							<th class="soldx-price"><?php esc_html_e( 'Sale Price', 'soldx-woocommerce' ); ?></th>
							<th class="soldx-unit"><?php esc_html_e( 'Sale unit', 'soldx-woocommerce' ); ?></th>
							<th class="soldx-unit"><?php esc_html_e( 'Purchase unit', 'soldx-woocommerce' ); ?></th>
							<th class="soldx-deposit"><?php esc_html_e( 'Deposit', 'soldx-woocommerce' ); ?></th>
							<th class="soldx-publish"><?php esc_html_e( 'Publish', 'soldx-woocommerce' ); ?></th>
							<th class="soldx-status"><?php esc_html_e( 'Status', 'soldx-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $items ) ) : ?>
							<tr>
								<td colspan="11"><?php esc_html_e( 'No products found.', 'soldx-woocommerce' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $items as $p ) : ?>
								<?php
								$pid   = $p->get_id();
								$map   = isset( $mappings[ $pid ] ) ? $mappings[ $pid ] : null;
								// Preselect from last-synced overrides when available, else defaults.
								$su = $this->stored_or_default( $map, 'saleUnitId', $defaults['saleUnitId'] );
								$pu = $this->stored_or_default( $map, 'purchaseUnitId', $defaults['purchaseUnitId'] );
								$dp = $this->stored_or_default( $map, 'depositId', $defaults['depositId'] );
								?>
								<tr>
									<td class="soldx-check">
										<input
											type="checkbox"
											name="product_ids[]"
											value="<?php echo esc_attr( (string) $pid ); ?>"
											class="soldx-row-check"
										/>
									</td>
									<td class="soldx-thumb">
										<?php
										$thumb_id = $p->get_image_id();
										if ( $thumb_id ) {
											echo wp_get_attachment_image( $thumb_id, array( 48, 48 ), false, array( 'class' => 'soldx-thumb-img' ) );
										} else {
											echo '<span class="soldx-thumb-placeholder">—</span>';
										}
										?>
									</td>
									<td class="soldx-name">
										<?php echo esc_html( $p->get_name() ); ?>
										<br><span class="soldx-muted">#<?php echo esc_html( (string) $pid ); ?></span>
									</td>
									<td class="soldx-sku"><code><?php echo esc_html( $p->get_sku() ? $p->get_sku() : '—' ); ?></code></td>
									<td class="soldx-price"><?php echo wp_kses_post( wc_price( $p->get_regular_price() ) ); ?></td>
								<td class="soldx-price"><?php
									$sp = $p->get_sale_price();
									echo wp_kses_post( '' !== $sp ? wc_price( $sp ) : '—' );
								?></td>
									<td class="soldx-unit">
										<?php echo $this->select_field( "overrides[{$pid}][saleUnitId]", $units, $su, 'designation', true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									</td>
									<td class="soldx-unit">
										<?php echo $this->select_field( "overrides[{$pid}][purchaseUnitId]", $units, $pu, 'designation', false ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									</td>
									<td class="soldx-deposit">
										<?php echo $this->select_field( "overrides[{$pid}][depositId]", $deposits, $dp, 'designation', false ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									</td>
									<td class="soldx-publish">
										<input
											type="checkbox"
											name="overrides[<?php echo esc_attr( (string) $pid ); ?>][published]"
											value="1"
											checked
										/>
									</td>
									<td class="soldx-status"><?php echo wp_kses_post( $map ? $this->status_badge( $map ) : '<span class="soldx-badge soldx-badge--new">' . esc_html__( 'New', 'soldx-woocommerce' ) . '</span>' ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<div class="tablenav bottom soldx-tablenav">
					<div class="tablenav-pages">
						<?php echo wp_kses_post( $this->pagination( $page, $pages, $total, $search, $base_url ) ); ?>
					</div>
				</div>
			</form>
		</div>

		<script>
		(function() {
			var selectAll = document.getElementById('soldx-select-all');
			if (!selectAll) { return; }
			selectAll.addEventListener('change', function() {
				var boxes = document.querySelectorAll('.soldx-row-check');
				for (var i = 0; i < boxes.length; i++) {
					boxes[i].checked = selectAll.checked;
				}
			});
			var form = document.getElementById('soldx-sync-form');
			var btn = document.getElementById('soldx-bulk-sync');
			if (form && btn) {
				form.addEventListener('submit', function(e) {
					var checked = form.querySelectorAll('.soldx-row-check:checked');
					if (checked.length === 0) {
						e.preventDefault();
						alert(<?php echo wp_json_encode( __( 'Please select at least one product.', 'soldx-woocommerce' ) ); ?>);
					} else {
						btn.setAttribute('disabled', 'disabled');
						btn.innerText = <?php echo wp_json_encode( __( 'Pushing…', 'soldx-woocommerce' ) ); ?>;
					}
				});
			}
		})();
		</script>
		<?php
	}

	// ------------------------------------------------------------------
	// Small render helpers
	// ------------------------------------------------------------------

	/**
	 * Render a <select> dropdown from an option list.
	 *
	 * Each option row is an associative array with at least `id` + a label key.
	 *
	 * @param string $name      Form field name.
	 * @param array  $options   Rows from EstablishmentOptions.
	 * @param string $selected  Currently selected id.
	 * @param string $label_key Key to use for the option label.
	 * @param bool   $required  Whether the field is required.
	 * @return string HTML (escaped).
	 */
	private function select_field( $name, $options, $selected, $label_key, $required ) {
		$out  = '<select name="' . esc_attr( $name ) . '" class="soldx-select"';
		$out .= $required ? ' required' : '';
		$out .= '>';
		$out .= '<option value="">' . esc_html__( '— Select —', 'soldx-woocommerce' ) . '</option>';
		if ( is_array( $options ) ) {
			foreach ( $options as $opt ) {
				$id = isset( $opt['id'] ) ? $opt['id'] : '';
				$label = isset( $opt[ $label_key ] ) && $opt[ $label_key ]
					? $opt[ $label_key ]
					: ( isset( $opt['reference'] ) ? $opt['reference'] : $id );
				$out .= '<option value="' . esc_attr( $id ) . '"'
					. selected( (string) $selected, (string) $id, false )
					. '>' . esc_html( $label ) . '</option>';
			}
		}
		$out .= '</select>';
		return $out;
	}

	/**
	 * Pick a stored override value from a mapping row, else fall back to default.
	 *
	 * The mapping row's `payload` is not stored locally in this MVP — we rely
	 * on the Studio-side payload. So when there's no local mapping, we use the
	 * integration default; when there is one, we still use the default so the
	 * user can change their mind. (The Studio PUT will merge with stored payload.)
	 *
	 * @param object|null $map
	 * @param string      $key
	 * @param string      $default
	 * @return string
	 */
	private function stored_or_default( $map, $key, $default ) {
		return $default;
	}

	/**
	 * Render a small status badge from a mapping row.
	 *
	 * @param object $map Mapping row from soldx_store().
	 * @return string HTML (safe to echo).
	 */
	private function status_badge( $map ) {
		$status = isset( $map->sync_status ) ? $map->sync_status : 'PENDING';
		switch ( $status ) {
			case 'SYNCED':
				$label = __( 'Synced', 'soldx-woocommerce' );
				$cls   = 'soldx-badge--ok';
				break;
			case 'ERROR':
				$label = __( 'Error', 'soldx-woocommerce' );
				$cls   = 'soldx-badge--err';
				break;
			case 'DELETED_REMOTE':
				$label = __( 'Removed', 'soldx-woocommerce' );
				$cls   = 'soldx-badge--muted';
				break;
			default:
				$label = __( 'Pending', 'soldx-woocommerce' );
				$cls   = 'soldx-badge--warn';
				break;
		}
		return '<span class="soldx-badge ' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Query WC products with pagination + search.
	 *
	 * @param int    $page
	 * @param int    $page_size
	 * @param string $search
	 * @return array { items: WC_Product[], total: int }
	 */
	private function query_wc_products( $page, $page_size, $search ) {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => $page_size,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'no_found_rows'  => false,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		$query = new WP_Query( $args );

		$items = array();
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$product = wc_get_product( $post );
				if ( $product ) {
					$items[] = $product;
				}
			}
		}
		wp_reset_postdata();

		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Compact pagination markup.
	 */
	private function pagination( $page, $pages, $total, $search, $base_url ) {
		if ( $pages <= 1 ) {
			return '<span class="displaying-num">' . sprintf(
				/* translators: %d: total items */
				_n( '%d item', '%d items', $total, 'soldx-woocommerce' ),
				$total
			) . '</span>';
		}

		$out  = '<span class="displaying-num">' . sprintf( _n( '%d item', '%d items', $total, 'soldx-woocommerce' ), $total ) . '</span> ';
		$args = array_filter( array( 'q' => $search ) );

		$prev_url = add_query_arg( array_merge( $args, array( 'paged' => $page - 1 ) ), $base_url );
		$next_url = add_query_arg( array_merge( $args, array( 'paged' => $page + 1 ) ), $base_url );

		$out .= '<span class="pagination-links">';
		if ( $page > 1 ) {
			$out .= '<a class="prev-page button" href="' . esc_url( $prev_url ) . '">‹</a> ';
		} else {
			$out .= '<span class="prev-page button disabled">‹</span> ';
		}
		$out .= '<span class="tablenav-paging-text">' . sprintf(
			/* translators: 1: current page, 2: total pages */
			esc_html__( '%1$d of %2$d', 'soldx-woocommerce' ),
			$page,
			$pages
		) . '</span> ';
		if ( $page < $pages ) {
			$out .= '<a class="next-page button" href="' . esc_url( $next_url ) . '">›</a>';
		} else {
			$out .= '<span class="next-page button disabled">›</span>';
		}
		$out .= '</span>';

		return $out;
	}
}
