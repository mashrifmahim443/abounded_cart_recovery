<?php
/**
 * Admin settings and UI.
 *
 * @package Smart_Cart_Recovery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Smart_Cart_Recovery_Admin_Settings
 */
class Smart_Cart_Recovery_Admin_Settings {

	/**
	 * Singleton instance.
	 *
	 * @var Smart_Cart_Recovery_Admin_Settings
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Smart_Cart_Recovery_Admin_Settings
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_export_abandoned_carts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register admin menu.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Smart Cart Recovery', 'smart-cart-recovery' ),
			__( 'Smart Cart Recovery', 'smart-cart-recovery' ),
			'manage_woocommerce',
			'scrm-smart-cart-recovery',
			array( $this, 'render_settings_page' ),
			'dashicons-email-alt2',
			56
		);

		add_submenu_page(
			'scrm-smart-cart-recovery',
			__( 'Settings', 'smart-cart-recovery' ),
			__( 'Settings', 'smart-cart-recovery' ),
			'manage_woocommerce',
			'scrm-smart-cart-recovery',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'scrm-smart-cart-recovery',
			__( 'Abandoned Carts', 'smart-cart-recovery' ),
			__( 'Abandoned Carts', 'smart-cart-recovery' ),
			'manage_woocommerce',
			'scrm-abandoned-carts',
			array( $this, 'render_abandoned_carts_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting(
			'scrm_settings_group',
			'scrm_settings',
			array( $this, 'sanitize_settings' )
		);

		add_settings_section(
			'scrm_general_section',
			__( 'General Settings', 'smart-cart-recovery' ),
			'__return_false',
			'scrm_settings_page'
		);

		add_settings_field(
			'enabled',
			__( 'Enable Plugin', 'smart-cart-recovery' ),
			array( $this, 'field_enabled_callback' ),
			'scrm_settings_page',
			'scrm_general_section'
		);

		add_settings_field(
			'abandon_time',
			__( 'Abandoned Time (minutes)', 'smart-cart-recovery' ),
			array( $this, 'field_abandon_time_callback' ),
			'scrm_settings_page',
			'scrm_general_section'
		);

		add_settings_section(
			'scrm_email_section',
			__( 'Email Settings', 'smart-cart-recovery' ),
			'__return_false',
			'scrm_settings_page'
		);

		add_settings_field(
			'from_email',
			__( 'From Email', 'smart-cart-recovery' ),
			array( $this, 'field_from_email_callback' ),
			'scrm_settings_page',
			'scrm_email_section'
		);

		add_settings_field(
			'from_name',
			__( 'From Name', 'smart-cart-recovery' ),
			array( $this, 'field_from_name_callback' ),
			'scrm_settings_page',
			'scrm_email_section'
		);

		add_settings_field(
			'email_subject',
			__( 'Email Subject', 'smart-cart-recovery' ),
			array( $this, 'field_email_subject_callback' ),
			'scrm_settings_page',
			'scrm_email_section'
		);

		add_settings_field(
			'email_body',
			__( 'Email Body (HTML)', 'smart-cart-recovery' ),
			array( $this, 'field_email_body_callback' ),
			'scrm_settings_page',
			'scrm_email_section'
		);

		add_settings_section(
			'scrm_smtp_section',
			__( 'SMTP Settings', 'smart-cart-recovery' ),
			'__return_false',
			'scrm_settings_page'
		);

		add_settings_field(
			'smtp_enabled',
			__( 'Enable SMTP', 'smart-cart-recovery' ),
			array( $this, 'field_smtp_enabled_callback' ),
			'scrm_settings_page',
			'scrm_smtp_section'
		);

		add_settings_field(
			'smtp_host',
			__( 'SMTP Host', 'smart-cart-recovery' ),
			array( $this, 'field_smtp_host_callback' ),
			'scrm_settings_page',
			'scrm_smtp_section'
		);

		add_settings_field(
			'smtp_port',
			__( 'SMTP Port', 'smart-cart-recovery' ),
			array( $this, 'field_smtp_port_callback' ),
			'scrm_settings_page',
			'scrm_smtp_section'
		);

		add_settings_field(
			'smtp_username',
			__( 'SMTP Username', 'smart-cart-recovery' ),
			array( $this, 'field_smtp_username_callback' ),
			'scrm_settings_page',
			'scrm_smtp_section'
		);

		add_settings_field(
			'smtp_password',
			__( 'SMTP Password', 'smart-cart-recovery' ),
			array( $this, 'field_smtp_password_callback' ),
			'scrm_settings_page',
			'scrm_smtp_section'
		);

		add_settings_field(
			'smtp_encryption',
			__( 'Encryption', 'smart-cart-recovery' ),
			array( $this, 'field_smtp_encryption_callback' ),
			'scrm_settings_page',
			'scrm_smtp_section'
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'scrm-' ) ) {
			return;
		}

		wp_enqueue_style(
			'scrm-admin',
			SCRM_PLUGIN_URL . 'assets/admin.css',
			array(),
			'1.0.0'
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Input values.
	 *
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$settings = scrm_get_settings();

		$settings['enabled']         = isset( $input['enabled'] ) ? (int) (bool) $input['enabled'] : 0;
		$settings['abandon_time']    = isset( $input['abandon_time'] ) ? absint( $input['abandon_time'] ) : 60;
		$settings['from_email']      = isset( $input['from_email'] ) ? sanitize_email( $input['from_email'] ) : $settings['from_email'];
		$settings['from_name']       = isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : $settings['from_name'];
		$settings['email_subject']   = isset( $input['email_subject'] ) ? wp_kses_post( $input['email_subject'] ) : $settings['email_subject'];
		$settings['email_body']      = isset( $input['email_body'] ) ? wp_kses_post( $input['email_body'] ) : $settings['email_body'];
		$settings['smtp_enabled']    = isset( $input['smtp_enabled'] ) ? (int) (bool) $input['smtp_enabled'] : 0;
		$settings['smtp_host']       = isset( $input['smtp_host'] ) ? sanitize_text_field( $input['smtp_host'] ) : $settings['smtp_host'];
		$settings['smtp_port']       = isset( $input['smtp_port'] ) ? absint( $input['smtp_port'] ) : $settings['smtp_port'];
		$settings['smtp_username']   = isset( $input['smtp_username'] ) ? sanitize_text_field( $input['smtp_username'] ) : $settings['smtp_username'];
		$settings['smtp_password']   = isset( $input['smtp_password'] ) ? sanitize_text_field( $input['smtp_password'] ) : $settings['smtp_password'];
		$settings['smtp_encryption'] = isset( $input['smtp_encryption'] ) && in_array( $input['smtp_encryption'], array( 'tls', 'ssl' ), true ) ? $input['smtp_encryption'] : 'tls';

		return $settings;
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		?>
		<div class="wrap scrm-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'scrm_settings_group' );
				do_settings_sections( 'scrm_settings_page' );
				submit_button();
				?>
				<p>
					<strong><?php esc_html_e( 'Available placeholders:', 'smart-cart-recovery' ); ?></strong>
					<br />
					<code>{customer_name}</code>,
					<code>{cart_items}</code>,
					<code>{cart_total}</code>,
					<code>{checkout_url}</code>,
					<code>{site_name}</code>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render abandoned carts page.
	 */
	public function render_abandoned_carts_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		global $wpdb;

		$per_page = 20;
		$paged    = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $paged < 1 ) {
			$paged = 1;
		}
		$offset = ( $paged - 1 ) * $per_page;

		$total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . SCRM_DB_TABLE );
		$total_pages = $total_items > 0 ? ceil( $total_items / $per_page ) : 1;

		$carts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . SCRM_DB_TABLE . " ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'         => 'scrm-abandoned-carts',
					'scrm_export'  => 'csv',
				),
				admin_url( 'admin.php' )
			),
			'scrm_export_abandoned_carts'
		);

		?>
		<div class="wrap scrm-wrap">
			<h1><?php esc_html_e( 'Abandoned Carts', 'smart-cart-recovery' ); ?></h1>

			<p>
				<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Export Abandoned Carts (CSV)', 'smart-cart-recovery' ); ?>
				</a>
			</p>

			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'smart-cart-recovery' ); ?></th>
						<th><?php esc_html_e( 'Customer Email', 'smart-cart-recovery' ); ?></th>
						<th><?php esc_html_e( 'Cart Items', 'smart-cart-recovery' ); ?></th>
						<th><?php esc_html_e( 'Cart Total', 'smart-cart-recovery' ); ?></th>
						<th><?php esc_html_e( 'Date Added', 'smart-cart-recovery' ); ?></th>
						<th><?php esc_html_e( 'Email Sent', 'smart-cart-recovery' ); ?></th>
						<th><?php esc_html_e( 'Recovered', 'smart-cart-recovery' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $carts ) ) : ?>
						<?php foreach ( $carts as $cart ) : ?>
							<?php
							$cart_data   = json_decode( $cart->cart_data, true );
							$items       = ! empty( $cart_data['items'] ) && is_array( $cart_data['items'] ) ? $cart_data['items'] : array();
							$items_names = array();

							foreach ( $items as $item ) {
								$name         = isset( $item['name'] ) ? $item['name'] : '';
								$qty          = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
								$items_names[] = $name . ' × ' . $qty;
							}
							?>
							<tr>
								<td><?php echo esc_html( $cart->id ); ?></td>
								<td><?php echo esc_html( $cart->email ); ?></td>
								<td><?php echo esc_html( implode( ', ', $items_names ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( (float) $cart->cart_total ) ); ?></td>
								<td><?php echo esc_html( get_date_from_gmt( $cart->created_at, 'Y-m-d H:i:s' ) ); ?></td>
								<td><?php echo $cart->email_sent ? esc_html__( 'Yes', 'smart-cart-recovery' ) : esc_html__( 'No', 'smart-cart-recovery' ); ?></td>
								<td><?php echo $cart->recovered ? esc_html__( 'Yes', 'smart-cart-recovery' ) : esc_html__( 'No', 'smart-cart-recovery' ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td colspan="7"><?php esc_html_e( 'No carts found.', 'smart-cart-recovery' ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'prev_text' => __( '&laquo;', 'smart-cart-recovery' ),
									'next_text' => __( '&raquo;', 'smart-cart-recovery' ),
									'total'     => $total_pages,
									'current'   => $paged,
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Maybe export abandoned carts as CSV for users who did not complete purchase.
	 */
	public function maybe_export_abandoned_carts() {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! isset( $_GET['page'], $_GET['scrm_export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( 'scrm-abandoned-carts' !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( 'csv' !== sanitize_text_field( wp_unslash( $_GET['scrm_export'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		check_admin_referer( 'scrm_export_abandoned_carts' );

		global $wpdb;

		// Only carts that have not been recovered (purchase not completed).
		$carts = $wpdb->get_results(
			"SELECT * FROM " . SCRM_DB_TABLE . " WHERE recovered = 0 AND email IS NOT NULL AND email != '' ORDER BY created_at DESC"
		);

		$filename = 'abandoned-carts-' . gmdate( 'Y-m-d-H-i-s' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );

		// CSV header row.
		fputcsv(
			$output,
			array(
				'ID',
				'Email',
				'Cart Items',
				'Cart Total',
				'Date Added (GMT)',
				'Email Sent',
				'Recovered',
			)
		);

		if ( ! empty( $carts ) ) {
			foreach ( $carts as $cart ) {
				$cart_data   = json_decode( $cart->cart_data, true );
				$items       = ! empty( $cart_data['items'] ) && is_array( $cart_data['items'] ) ? $cart_data['items'] : array();
				$items_names = array();

				foreach ( $items as $item ) {
					$name = isset( $item['name'] ) ? $item['name'] : '';
					$qty  = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
					$items_names[] = $name . ' x ' . $qty;
				}

				fputcsv(
					$output,
					array(
						$cart->id,
						$cart->email,
						implode( '; ', $items_names ),
						$cart->cart_total,
						$cart->created_at,
						$cart->email_sent ? 'Yes' : 'No',
						$cart->recovered ? 'Yes' : 'No',
					)
				);
			}
		}

		fclose( $output );
		exit;
	}

	/**
	 * Field: enabled.
	 */
	public function field_enabled_callback() {
		$settings = scrm_get_settings();
		?>
		<label>
			<input type="checkbox" name="scrm_settings[enabled]" value="1" <?php checked( 1, (int) $settings['enabled'] ); ?> />
			<?php esc_html_e( 'Enable Smart Cart Recovery Mailer', 'smart-cart-recovery' ); ?>
		</label>
		<?php
	}

	/**
	 * Field: abandon_time.
	 */
	public function field_abandon_time_callback() {
		$settings = scrm_get_settings();
		?>
		<input type="number" min="1" name="scrm_settings[abandon_time]" value="<?php echo esc_attr( $settings['abandon_time'] ); ?>" />
		<p class="description"><?php esc_html_e( 'Time in minutes after which cart is considered abandoned.', 'smart-cart-recovery' ); ?></p>
		<?php
	}

	/**
	 * Field: from_email.
	 */
	public function field_from_email_callback() {
		$settings = scrm_get_settings();
		?>
		<input type="email" name="scrm_settings[from_email]" value="<?php echo esc_attr( $settings['from_email'] ); ?>" class="regular-text" />
		<?php
	}

	/**
	 * Field: from_name.
	 */
	public function field_from_name_callback() {
		$settings = scrm_get_settings();
		?>
		<input type="text" name="scrm_settings[from_name]" value="<?php echo esc_attr( $settings['from_name'] ); ?>" class="regular-text" />
		<?php
	}

	/**
	 * Field: email_subject.
	 */
	public function field_email_subject_callback() {
		$settings = scrm_get_settings();
		?>
		<input type="text" name="scrm_settings[email_subject]" value="<?php echo esc_attr( $settings['email_subject'] ); ?>" class="regular-text" />
		<?php
	}

	/**
	 * Field: email_body.
	 */
	public function field_email_body_callback() {
		$settings = scrm_get_settings();

		$content = $settings['email_body'];

		wp_editor(
			$content,
			'scrm_email_body',
			array(
				'textarea_name' => 'scrm_settings[email_body]',
				'media_buttons' => false,
				'teeny'         => true,
				'textarea_rows' => 10,
			)
		);
	}

	/**
	 * Field: smtp_enabled.
	 */
	public function field_smtp_enabled_callback() {
		$settings = scrm_get_settings();
		?>
		<label>
			<input type="checkbox" name="scrm_settings[smtp_enabled]" value="1" <?php checked( 1, (int) $settings['smtp_enabled'] ); ?> />
			<?php esc_html_e( 'Use SMTP for sending emails', 'smart-cart-recovery' ); ?>
		</label>
		<?php
	}

	/**
	 * Field: smtp_host.
	 */
	public function field_smtp_host_callback() {
		$settings = scrm_get_settings();
		?>
		<input type="text" name="scrm_settings[smtp_host]" value="<?php echo esc_attr( $settings['smtp_host'] ); ?>" class="regular-text" />
		<?php
	}

	/**
	 * Field: smtp_port.
	 */
	public function field_smtp_port_callback() {
		$settings = scrm_get_settings();
		?>
		<input type="number" name="scrm_settings[smtp_port]" value="<?php echo esc_attr( $settings['smtp_port'] ); ?>" class="small-text" />
		<?php
	}

	/**
	 * Field: smtp_username.
	 */
	public function field_smtp_username_callback() {
		$settings = scrm_get_settings();
		?>
		<input type="text" name="scrm_settings[smtp_username]" value="<?php echo esc_attr( $settings['smtp_username'] ); ?>" class="regular-text" />
		<?php
	}

	/**
	 * Field: smtp_password.
	 */
	public function field_smtp_password_callback() {
		$settings = scrm_get_settings();
		?>
		<input type="password" name="scrm_settings[smtp_password]" value="<?php echo esc_attr( $settings['smtp_password'] ); ?>" class="regular-text" autocomplete="off" />
		<?php
	}

	/**
	 * Field: smtp_encryption.
	 */
	public function field_smtp_encryption_callback() {
		$settings   = scrm_get_settings();
		$encryption = $settings['smtp_encryption'];
		?>
		<select name="scrm_settings[smtp_encryption]">
			<option value="tls" <?php selected( 'tls', $encryption ); ?>><?php esc_html_e( 'TLS', 'smart-cart-recovery' ); ?></option>
			<option value="ssl" <?php selected( 'ssl', $encryption ); ?>><?php esc_html_e( 'SSL', 'smart-cart-recovery' ); ?></option>
		</select>
		<?php
	}
}

