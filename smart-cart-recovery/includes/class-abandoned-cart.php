<?php
/**
 * Abandoned cart handling.
 *
 * @package Smart_Cart_Recovery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Smart_Cart_Recovery_Abandoned_Cart
 */
class Smart_Cart_Recovery_Abandoned_Cart {

	/**
	 * Singleton instance.
	 *
	 * @var Smart_Cart_Recovery_Abandoned_Cart
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Smart_Cart_Recovery_Abandoned_Cart
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
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'capture_checkout_email' ), 10, 2 );
		add_action( 'scrm_check_abandoned_carts', array( $this, 'process_abandoned_carts' ) );
		add_action( 'init', array( $this, 'handle_recovery_link' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'mark_recovered_on_order' ), 10, 4 );
	}

	/**
	 * Check if plugin is enabled.
	 *
	 * @return bool
	 */
	protected function is_enabled() {
		$settings = scrm_get_settings();
		return ! empty( $settings['enabled'] );
	}

	/**
	 * Capture checkout email even if order not completed.
	 *
	 * @param array    $data Posted data.
	 * @param WP_Error $errors Validation errors.
	 */
	public function capture_checkout_email( $data, $errors ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( empty( $data['billing_email'] ) ) {
			return;
		}

		$email = sanitize_email( $data['billing_email'] );
		if ( ! is_email( $email ) ) {
			return;
		}

		$this->maybe_store_cart( $email );
	}

	/**
	 * Store cart in DB for current user/session.
	 *
	 * @param string $email Optional email.
	 */
	protected function maybe_store_cart( $email = '' ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$cart = WC()->cart;
		if ( $cart->is_empty() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( empty( $email ) ) {
			if ( $user_id ) {
				$user  = get_userdata( $user_id );
				$email = $user && isset( $user->user_email ) ? $user->user_email : '';
				$email = sanitize_email( $email );
			} else {
				$email = '';
			}
		}

		$items = array();
		foreach ( $cart->get_cart() as $item ) {
			$product    = $item['data'];
			$product_id = $product ? $product->get_id() : 0;
			$items[]    = array(
				'product_id'    => $product_id,
				'name'          => $product ? $product->get_name() : '',
				'quantity'      => isset( $item['quantity'] ) ? (int) $item['quantity'] : 1,
				'price'         => $product ? wc_get_price_to_display( $product ) : 0,
				'line_subtotal' => isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0,
				'variation_id'  => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0,
				'variation'     => isset( $item['variation'] ) ? $item['variation'] : array(),
			);
		}

		$cart_total = (float) $cart->get_total( 'edit' );

		$cart_data = array(
			'items' => $items,
		);

		global $wpdb;

		$existing_id = null;

		if ( ! empty( $email ) ) {
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM " . SCRM_DB_TABLE . " WHERE email = %s AND email_sent = 0 AND recovered = 0 ORDER BY id DESC LIMIT 1",
					$email
				)
			);
		} elseif ( $user_id ) {
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM " . SCRM_DB_TABLE . " WHERE user_id = %d AND email_sent = 0 AND recovered = 0 ORDER BY id DESC LIMIT 1",
					$user_id
				)
			);
		}

		$data = array(
			'user_id'    => $user_id ? $user_id : null,
			'email'      => $email ? $email : null,
			'cart_data'  => wp_json_encode( $cart_data ),
			'cart_total' => $cart_total,
			'created_at' => current_time( 'mysql', 1 ),
			'email_sent' => 0,
			'recovered'  => 0,
		);

		$format = array(
			'%d',
			'%s',
			'%s',
			'%f',
			'%s',
			'%d',
			'%d',
		);

		if ( $existing_id ) {
			$wpdb->update(
				SCRM_DB_TABLE,
				$data,
				array( 'id' => (int) $existing_id ),
				$format,
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				SCRM_DB_TABLE,
				$data,
				$format
			);
		}
	}

	/**
	 * Process abandoned carts via cron.
	 */
	public function process_abandoned_carts() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		global $wpdb;

		$settings     = scrm_get_settings();
		$abandon_time = isset( $settings['abandon_time'] ) ? (int) $settings['abandon_time'] : 60;
		if ( $abandon_time <= 0 ) {
			$abandon_time = 60;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $abandon_time * 60 ) );

		$carts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . SCRM_DB_TABLE . " WHERE created_at <= %s AND email_sent = 0 AND recovered = 0 AND email IS NOT NULL AND email != ''",
				$cutoff
			)
		);

		if ( empty( $carts ) ) {
			return;
		}

		foreach ( $carts as $cart ) {
			$this->send_recovery_email_for_cart( $cart );
		}
	}

	/**
	 * Send recovery email for a cart.
	 *
	 * @param object $cart Cart record.
	 */
	protected function send_recovery_email_for_cart( $cart ) {
		$handler = Smart_Cart_Recovery_Email_Handler::get_instance();

		$sent = $handler->send_recovery_email( $cart );

		if ( $sent ) {
			global $wpdb;
			$wpdb->update(
				SCRM_DB_TABLE,
				array(
					'email_sent' => 1,
				),
				array( 'id' => (int) $cart->id ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Handle recovery link.
	 */
	public function handle_recovery_link() {
		if ( ! isset( $_GET['scrm_recover'], $_GET['scrm_cart'], $_GET['scrm_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$cart_id = absint( wp_unslash( $_GET['scrm_cart'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key     = sanitize_text_field( wp_unslash( $_GET['scrm_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $cart_id || empty( $key ) ) {
			return;
		}

		global $wpdb;

		$cart = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . SCRM_DB_TABLE . " WHERE id = %d",
				$cart_id
			)
		);

		if ( ! $cart ) {
			return;
		}

		$expected_key = $this->generate_recovery_key( $cart );
		if ( ! hash_equals( $expected_key, $key ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		$cart_data = json_decode( $cart->cart_data, true );
		if ( ! empty( $cart_data['items'] ) && is_array( $cart_data['items'] ) ) {
			foreach ( $cart_data['items'] as $item ) {
				$product_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
				$quantity     = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
				$variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
				$variation    = isset( $item['variation'] ) && is_array( $item['variation'] ) ? $item['variation'] : array();

				if ( $product_id > 0 ) {
					WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
				}
			}
		}

		// User re-entered checkout via recovery link and will now purchase,
		// so this entry no longer needs to be tracked as abandoned.
		$wpdb->delete(
			SCRM_DB_TABLE,
			array( 'id' => (int) $cart->id )
		);

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Mark cart as recovered when order is placed.
	 *
	 * @param int      $order_id   Order ID.
	 * @param string   $old_status Old status.
	 * @param string   $new_status New status.
	 * @param WC_Order $order      Order object.
	 */
	public function mark_recovered_on_order( $order_id, $old_status = '', $new_status = '', $order = null ) {
		if ( ! $this->is_enabled() || ! $order_id ) {
			return;
		}

		// Only treat as recovered when payment is completed.
		if ( ! in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( ! $email ) {
			return;
		}

		$email = sanitize_email( $email );

		global $wpdb;

		// Order completed successfully – clean up any abandoned entries for this email
		// so we only keep users who did NOT finish purchase.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM " . SCRM_DB_TABLE . " WHERE email = %s",
				$email
			)
		);
	}

	/**
	 * Generate recovery key for a cart record.
	 *
	 * @param object $cart Cart record.
	 *
	 * @return string
	 */
	public function generate_recovery_key( $cart ) {
		$secret = wp_salt( 'scrm_recovery' );
		return hash( 'sha256', $cart->id . '|' . $cart->email . '|' . $cart->created_at . '|' . $secret );
	}

	/**
	 * Generate recovery URL for cart.
	 *
	 * @param object $cart Cart record.
	 *
	 * @return string
	 */
	public function get_recovery_url( $cart ) {
		$key = $this->generate_recovery_key( $cart );

		$args = array(
			'scrm_recover' => 1,
			'scrm_cart'    => (int) $cart->id,
			'scrm_key'     => $key,
		);

		return add_query_arg( $args, wc_get_checkout_url() );
	}
}

