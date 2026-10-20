<?php
/**
 * Cancellation policy, shown on the Returns page under its own heading, so the page covers
 * cancellations and refunds in one place (payment providers such as Razorpay look for this).
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * The cancellation section.
 *
 * @return string
 */
function store_features_cancellation_html() {
	$email = sanitize_email( (string) get_option( 'store_features_email', '' ) );
	$phone = trim( (string) get_option( 'store_features_phone', '' ) );
	$reach = array();
	if ( $phone ) {
		$reach[] = esc_html( sprintf( /* translators: %s phone */ __( 'WhatsApp or call %s', 'store-features' ), $phone ) );
	}
	if ( $email ) {
		$reach[] = sprintf( /* translators: %s email */ esc_html__( 'email %s', 'store-features' ), '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>' );
	}
	$how = $reach ? implode( ' ' . esc_html__( 'or', 'store-features' ) . ' ', $reach ) : esc_html__( 'contact us', 'store-features' );

	return '<h2 id="cancellation" class="wp-block-heading">' . esc_html__( 'Cancellation and refund policy', 'store-features' ) . '</h2>'
		. '<ul class="wp-block-list">'
		. '<li>' . esc_html__( 'Every piece is printed after you order. You can cancel within 12 hours of placing the order, before printing starts:', 'store-features' ) . ' ' . $how . ' ' . esc_html__( 'with your order number.', 'store-features' ) . '</li>'
		. '<li>' . esc_html__( 'Once printing has started, the order can\'t be cancelled. Damaged, misprinted or wrong items are replaced or refunded as described above.', 'store-features' ) . '</li>'
		. '<li>' . esc_html__( 'Custom (design your own) orders can\'t be cancelled after you confirm the design.', 'store-features' ) . '</li>'
		. '<li>' . esc_html__( 'Refunds for cancelled prepaid orders go back to the original payment method within 5 to 7 working days. Cash on delivery orders are simply not shipped, so nothing is charged.', 'store-features' ) . '</li>'
		. '<li>' . esc_html__( 'If a cash on delivery parcel is refused at the door without a valid reason, we may ask for advance payment on future orders.', 'store-features' ) . '</li>'
		. '</ul>';
}

/**
 * Add the section to the Returns page.
 *
 * @param string $content Page content.
 * @return string
 */
function store_features_cancellation_on_returns( $content ) {
	if ( is_admin() || ! in_the_loop() || ! is_main_query() || ! is_page( array( 'returns', 'refund-policy', 'returns-and-replacements' ) ) ) {
		return $content;
	}
	return $content . store_features_cancellation_html();
}
add_filter( 'the_content', 'store_features_cancellation_on_returns', 20 );
