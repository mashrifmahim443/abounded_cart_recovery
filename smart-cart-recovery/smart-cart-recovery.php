<?php
/**
 * Plugin Name:       Smart Cart Recovery Mailer
 * Plugin URI:        https://example.com/
 * Description:       Detects abandoned WooCommerce carts and sends recovery emails.
 * Version:           1.0.0
 * Author:            Your Name
 * Author URI:        https://example.com/
 * Text Domain:       smart-cart-recovery
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package Smart_Cart_Recovery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SCRM_PLUGIN_FILE' ) ) {
	define( 'SCRM_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'SCRM_PLUGIN_DIR' ) ) {
	define( 'SCRM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'SCRM_PLUGIN_URL' ) ) {
	define( 'SCRM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'SCRM_DB_TABLE' ) ) {
	global $wpdb;
	define( 'SCRM_DB_TABLE', $wpdb->prefix . 'scrm_abandoned_carts' );
}

/**
 * Load plugin text domain.
 */
function scrm_load_textdomain() {
	load_plugin_textdomain( 'smart-cart-recovery', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'scrm_load_textdomain' );

/**
 * Check WooCommerce dependency.
 *
 * @return bool
 */
function scrm_is_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

/**
 * Activation hook - create DB table and schedule cron.
 */
function scrm_activate() {
	if ( ! scrm_is_woocommerce_active() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'Smart Cart Recovery Mailer requires WooCommerce to be installed and active.', 'smart-cart-recovery' ),
			esc_html__( 'Plugin dependency check', 'smart-cart-recovery' ),
			array( 'back_link' => true )
		);
	}

	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS " . SCRM_DB_TABLE . " (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT(20) UNSIGNED NULL,
		email VARCHAR(200) NULL,
		cart_data LONGTEXT NULL,
		cart_total DECIMAL(18, 6) NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL,
		email_sent TINYINT(1) NOT NULL DEFAULT 0,
		recovered TINYINT(1) NOT NULL DEFAULT 0,
		PRIMARY KEY (id),
		KEY email (email),
		KEY created_at (created_at),
		KEY email_sent (email_sent),
		KEY recovered (recovered)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	if ( ! wp_next_scheduled( 'scrm_check_abandoned_carts' ) ) {
		wp_schedule_event( time() + 600, 'scrm_quarter_hour', 'scrm_check_abandoned_carts' );
	}
}
register_activation_hook( __FILE__, 'scrm_activate' );

/**
 * Deactivation hook - clear cron.
 */
function scrm_deactivate() {
	$timestamp = wp_next_scheduled( 'scrm_check_abandoned_carts' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'scrm_check_abandoned_carts' );
	}
}
register_deactivation_hook( __FILE__, 'scrm_deactivate' );

/**
 * Add custom cron schedule (15 minutes).
 *
 * @param array $schedules Schedules.
 *
 * @return array
 */
function scrm_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['scrm_quarter_hour'] ) ) {
		$schedules['scrm_quarter_hour'] = array(
			'interval' => 15 * 60,
			'display'  => __( 'Every 15 Minutes', 'smart-cart-recovery' ),
		);
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'scrm_cron_schedules' );

/**
 * Include required files.
 */
function scrm_includes() {
	if ( ! scrm_is_woocommerce_active() ) {
		return;
	}

	require_once SCRM_PLUGIN_DIR . 'includes/class-email-handler.php';
	require_once SCRM_PLUGIN_DIR . 'includes/class-abandoned-cart.php';
	require_once SCRM_PLUGIN_DIR . 'includes/class-admin-settings.php';

	// Initialize core classes.
	Smart_Cart_Recovery_Email_Handler::get_instance();
	Smart_Cart_Recovery_Abandoned_Cart::get_instance();
	Smart_Cart_Recovery_Admin_Settings::get_instance();
}
add_action( 'plugins_loaded', 'scrm_includes', 20 );

/**
 * Get plugin settings.
 *
 * @return array
 */
function scrm_get_settings() {
	$defaults = array(
		'enabled'            => 1,
		'abandon_time'       => 60,
		'smtp_enabled'       => 0,
		'smtp_host'          => '',
		'smtp_port'          => '',
		'smtp_username'      => '',
		'smtp_password'      => '',
		'smtp_encryption'    => 'tls',
		'from_email'         => get_option( 'admin_email' ),
		'from_name'          => get_bloginfo( 'name', 'display' ),
		'email_subject'      => __( 'We saved your cart at {site_name}', 'smart-cart-recovery' ),
		'email_body'         => '<p>' . __( 'Hi {customer_name},', 'smart-cart-recovery' ) . '</p>' .
			'<p>' . __( 'You left some items in your cart on {site_name}.', 'smart-cart-recovery' ) . '</p>' .
			'<p>{cart_items}</p>' .
			'<p>' . __( 'Cart total: {cart_total}', 'smart-cart-recovery' ) . '</p>' .
			'<p><a href="{checkout_url}">' . __( 'Click here to recover your cart', 'smart-cart-recovery' ) . '</a></p>',
	);

	$settings = get_option( 'scrm_settings', array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	return wp_parse_args( $settings, $defaults );
}

/**
 * Initialize SMTP if enabled.
 *
 * @param PHPMailer $phpmailer PHPMailer instance.
 */
function scrm_configure_phpmailer( $phpmailer ) {
	$settings = scrm_get_settings();

	if ( empty( $settings['smtp_enabled'] ) ) {
		return;
	}

	$phpmailer->isSMTP();
	$phpmailer->Host       = isset( $settings['smtp_host'] ) ? $settings['smtp_host'] : '';
	$phpmailer->Port       = isset( $settings['smtp_port'] ) ? (int) $settings['smtp_port'] : 587;
	$phpmailer->SMTPAuth   = true;
	$phpmailer->Username   = isset( $settings['smtp_username'] ) ? $settings['smtp_username'] : '';
	$phpmailer->Password   = isset( $settings['smtp_password'] ) ? $settings['smtp_password'] : '';
	$phpmailer->SMTPSecure = in_array( $settings['smtp_encryption'], array( 'ssl', 'tls' ), true ) ? $settings['smtp_encryption'] : 'tls';

	$from_email = ! empty( $settings['from_email'] ) ? $settings['from_email'] : get_option( 'admin_email' );
	$from_name  = ! empty( $settings['from_name'] ) ? $settings['from_name'] : get_bloginfo( 'name', 'display' );

	$phpmailer->setFrom( $from_email, $from_name );
}
add_action( 'phpmailer_init', 'scrm_configure_phpmailer' );

