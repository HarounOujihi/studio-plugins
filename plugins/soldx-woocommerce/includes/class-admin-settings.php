<?php
/**
 * Admin settings page — Studio URL + apiKey + Test connection.
 *
 * Registered under the WooCommerce top-level menu so it sits next to the
 * other WC integrations.
 *
 * @package SoldxWooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Soldx_Admin_Settings {

	private static $instance = null;

	const PAGE_SLUG = 'soldx-settings';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the Settings sub-menu under WooCommerce.
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Soldx Sync', 'soldx-woocommerce' ),
			__( 'Soldx Sync', 'soldx-woocommerce' ),
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
	 * Handle Save / Test connection / Disconnect form posts.
	 */
	public function handle_actions() {
		if ( ! isset( $_POST['soldx_action'] ) ) {
			return;
		}
		// Only handle our own actions (save / test / disconnect).
		// The articles page also uses soldx_action=sync_selected — we must
		// not intercept that.
		$action = sanitize_key( $_POST['soldx_action'] );
		if ( ! in_array( $action, array( 'save', 'test', 'disconnect' ), true ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'soldx-woocommerce' ) );
		}
		if ( ! soldx_verify_nonce( 'soldx_settings' ) ) {
			wp_die( esc_html__( 'Nonce expired. Please go back and try again.', 'soldx-woocommerce' ) );
		}
		switch ( $action ) {
			case 'save':
				$this->handle_save();
				break;
			case 'test':
				$this->handle_test_connection();
				break;
			case 'disconnect':
				$this->handle_disconnect();
				break;
		}
	}

	private function handle_save() {
		$input = array(
			'studio_url' => isset( $_POST['studio_url'] ) ? wp_unslash( $_POST['studio_url'] ) : '',
			'api_key'    => isset( $_POST['api_key'] ) ? wp_unslash( $_POST['api_key'] ) : '',
		);
		$ok = Soldx_Auth::save_settings( $input );
		if ( $ok ) {
			soldx_flash_notice_set( __( 'Settings saved.', 'soldx-woocommerce' ), 'success' );
		} else {
			soldx_flash_notice_set( __( 'Settings could not be saved. Check the API key format (64 hex characters) and Studio URL.', 'soldx-woocommerce' ), 'error' );
		}
		wp_safe_redirect( soldx_admin_url( self::PAGE_SLUG ) );
		exit;
	}

	private function handle_test_connection() {
		// Persist the form first so the client uses the latest values.
		$input = array(
			'studio_url' => isset( $_POST['studio_url'] ) ? wp_unslash( $_POST['studio_url'] ) : '',
			'api_key'    => isset( $_POST['api_key'] ) ? wp_unslash( $_POST['api_key'] ) : '',
		);
		Soldx_Auth::save_settings( $input );

		if ( ! Soldx_Auth::is_configured() ) {
			soldx_flash_notice_set( __( 'Please enter both the Studio URL and the API key first.', 'soldx-woocommerce' ), 'warning' );
			wp_safe_redirect( soldx_admin_url( self::PAGE_SLUG ) );
			exit;
		}

		try {
			$result = soldx_api()->authenticate();
			Soldx_Auth::save_auth_result( $result );

			$etb = ! empty( $result['establishmentName'] ) ? $result['establishmentName'] : __( '(unknown)', 'soldx-woocommerce' );
			soldx_flash_notice_set(
				sprintf(
					/* translators: %s: establishment name */
					__( 'Connection successful. Linked to <strong>%s</strong>.', 'soldx-woocommerce' ),
					esc_html( $etb )
				),
				'success'
			);
		} catch ( Soldx_Api_Exception $e ) {
			soldx_flash_notice_set(
				sprintf(
					/* translators: %s: error message */
					__( 'Connection failed: %s', 'soldx-woocommerce' ),
					esc_html( $e->getMessage() )
				),
				'error'
			);
		}

		wp_safe_redirect( soldx_admin_url( self::PAGE_SLUG ) );
		exit;
	}

	private function handle_disconnect() {
		Soldx_Auth::reset();
		soldx_flash_notice_set( __( 'Plugin disconnected. Your WooCommerce products are untouched.', 'soldx-woocommerce' ), 'info' );
		wp_safe_redirect( soldx_admin_url( self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'soldx-woocommerce' ) );
		}
		$studio_url      = Soldx_Auth::studio_url();
		$api_key         = Soldx_Auth::api_key();
		$integration_id  = Soldx_Auth::integration_id();
		$etb_name        = Soldx_Auth::establishment_name();
		$is_connected    = '' !== $integration_id;
		$articles_url    = soldx_admin_url( Soldx_Admin_Articles::PAGE_SLUG );
		?>
		<div class="wrap soldx-wrap">
			<h1 class="soldx-title"><?php echo esc_html__( 'Soldx Sync', 'soldx-woocommerce' ); ?></h1>
			<p class="soldx-subtitle"><?php esc_html_e( 'Connect your WooCommerce shop to Soldx Studio to push products into Studio.', 'soldx-woocommerce' ); ?></p>

			<?php soldx_flash_notice_maybe_print(); ?>

			<?php if ( $is_connected ) : ?>
				<div class="soldx-card soldx-card--connected">
					<div class="soldx-card-body">
						<h2 class="soldx-card-title">
							<span class="soldx-dot soldx-dot--ok"></span>
							<?php esc_html_e( 'Connected', 'soldx-woocommerce' ); ?>
						</h2>
						<p class="soldx-card-meta">
							<?php
							printf(
								/* translators: 1: establishment name, 2: integration id (short) */
								esc_html__( 'Establishment: %1$s · Integration: %2$s', 'soldx-woocommerce' ),
								'<strong>' . esc_html( $etb_name ? $etb_name : __( '—', 'soldx-woocommerce' ) ) . '</strong>',
								'<code>' . esc_html( substr( $integration_id, 0, 8 ) ) . '</code>'
							);
							?>
						</p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( $articles_url ); ?>">
								<?php esc_html_e( 'Go to Articles', 'soldx-woocommerce' ); ?>
							</a>
						</p>
					</div>
				</div>
			<?php endif; ?>

			<form method="post" class="soldx-form">
				<?php soldx_nonce_field( 'soldx_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="studio_url"><?php esc_html_e( 'Studio URL', 'soldx-woocommerce' ); ?></label>
						</th>
						<td>
							<input
								type="url"
								id="studio_url"
								name="studio_url"
								class="regular-text"
								placeholder="https://studio.soldx.tn"
								value="<?php echo esc_attr( $studio_url ); ?>"
								autocomplete="off"
							/>
							<p class="description"><?php esc_html_e( 'The base URL of your Soldx Studio installation (no trailing slash).', 'soldx-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="api_key"><?php esc_html_e( 'API Key', 'soldx-woocommerce' ); ?></label>
						</th>
						<td>
							<input
								type="password"
								id="api_key"
								name="api_key"
								class="regular-text"
								placeholder="<?php echo $api_key ? str_repeat( '•', 12 ) : ''; ?>"
								value=""
								autocomplete="new-password"
							/>
							<p class="description">
								<?php
								if ( $api_key ) {
									echo '<code>' . esc_html( substr( $api_key, 0, 6 ) ) . '…' . esc_html( substr( $api_key, -4 ) ) . '</code> ';
									esc_html_e( 'Already set. Leave blank to keep the current key.', 'soldx-woocommerce' );
								} else {
									esc_html_e( 'Get this from Studio → Settings → Plugins → Activate WooCommerce.', 'soldx-woocommerce' );
								}
								?>
							</p>
						</td>
					</tr>
				</table>

				<p class="submit soldx-actions">
					<button type="submit" name="soldx_action" value="save" class="button button-secondary">
						<?php esc_html_e( 'Save settings', 'soldx-woocommerce' ); ?>
					</button>
					<button type="submit" name="soldx_action" value="test" class="button button-primary">
						<?php esc_html_e( 'Save & Test connection', 'soldx-woocommerce' ); ?>
					</button>
					<?php if ( $is_connected ) : ?>
						<button type="submit" name="soldx_action" value="disconnect" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Disconnect? Products already synced will stay in place but will no longer be updated.', 'soldx-woocommerce' ) ); ?>');">
							<?php esc_html_e( 'Disconnect', 'soldx-woocommerce' ); ?>
						</button>
					<?php endif; ?>
				</p>
			</form>

			<div class="soldx-help">
				<h3><?php esc_html_e( 'How sync works', 'soldx-woocommerce' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Products are pushed from WooCommerce to Studio only (one-way).', 'soldx-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'You pick which products to push on the Articles page.', 'soldx-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'For each product you choose a sale unit (required), a purchase unit, and a deposit.', 'soldx-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'Pricing is synced; stock is intentionally NOT synced.', 'soldx-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'Re-pushing a product updates the matching Studio article.', 'soldx-woocommerce' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}
