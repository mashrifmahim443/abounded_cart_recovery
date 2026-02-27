<?php
/**
 * Email handler.
 *
 * @package Smart_Cart_Recovery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Smart_Cart_Recovery_Email_Handler
 */
class Smart_Cart_Recovery_Email_Handler {

	/**
	 * Singleton instance.
	 *
	 * @var Smart_Cart_Recovery_Email_Handler
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Smart_Cart_Recovery_Email_Handler
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
	private function __construct() {}

	/**
	 * Send recovery email.
	 *
	 * @param object $cart Cart record.
	 *
	 * @return bool
	 */
	public function send_recovery_email( $cart ) {
		if ( empty( $cart->email ) || ! is_email( $cart->email ) ) {
			return false;
		}

		$settings = scrm_get_settings();

		$subject_template = isset( $settings['email_subject'] ) ? $settings['email_subject'] : '';
		$body_template    = isset( $settings['email_body'] ) ? $settings['email_body'] : '';

		if ( empty( $subject_template ) || empty( $body_template ) ) {
			return false;
		}

		$abandoned_cart = Smart_Cart_Recovery_Abandoned_Cart::get_instance();
		$recovery_url   = $abandoned_cart->get_recovery_url( $cart );

		$cart_data = json_decode( $cart->cart_data, true );
		$items     = ! empty( $cart_data['items'] ) && is_array( $cart_data['items'] ) ? $cart_data['items'] : array();

		$cart_items_html = '<ul>';
		foreach ( $items as $item ) {
			$name     = isset( $item['name'] ) ? $item['name'] : '';
			$qty      = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
			$subtotal = isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0;
			$cart_items_html .= '<li>' . esc_html( $name ) . ' &times; ' . intval( $qty ) . ' - ' . wp_kses_post( wc_price( $subtotal ) ) . '</li>';
		}
		$cart_items_html .= '</ul>';

		$customer_name = '';

		if ( ! empty( $cart->user_id ) ) {
			$user = get_userdata( $cart->user_id );
			if ( $user ) {
				$customer_name = trim( $user->first_name . ' ' . $user->last_name );
				if ( '' === $customer_name ) {
					$customer_name = $user->display_name;
				}
			}
		}

		if ( '' === $customer_name ) {
			$customer_name = __( 'there', 'smart-cart-recovery' );
		}

		$placeholders = array(
			'{customer_name}' => $customer_name,
			'{cart_items}'    => $cart_items_html,
			'{cart_total}'    => wc_price( (float) $cart->cart_total ),
			'{checkout_url}'  => esc_url( $recovery_url ),
			'{site_name}'     => wp_specialchars_decode( get_bloginfo( 'name', 'display' ), ENT_QUOTES ),
		);

		$subject = strtr( $subject_template, $placeholders );
		$body    = strtr( $body_template, $placeholders );

		add_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );

		$result = wp_mail( $cart->email, $subject, $body );

		remove_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );

		return (bool) $result;
	}

	/**
	 * Set email content type to HTML.
	 *
	 * @return string
	 */
	public function set_html_content_type() {
		return 'text/html';
	}
}

