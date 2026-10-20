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
		. '<li>' . esc_html__( 'Every piece is made to order and goes for printing as soon as you place the order, so orders can\'t be cancelled once placed.', 'store-features' ) . '</li>'
		. '<li>' . esc_html__( 'Made a mistake in size, colour or address? Tell us right away:', 'store-features' ) . ' ' . $how . '. ' . esc_html__( 'If printing hasn\'t started yet, we\'ll fix it.', 'store-features' ) . '</li>'
		. '<li>' . esc_html__( 'Damaged, misprinted or wrong items are replaced free, or refunded in full if a replacement isn\'t possible, as described above.', 'store-features' ) . '</li>'
		. '<li>' . esc_html__( 'Refunds for prepaid orders go back to the original payment method within 5 to 7 working days. Cash on delivery refunds go to your bank account or UPI ID.', 'store-features' ) . '</li>'
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

/**
 * The owner's legal name: from the setting, or the proprietor's name when the setting is empty.
 *
 * @param mixed $value Saved value.
 * @return string
 */
function store_features_owner_fallback( $value ) {
	return '' !== trim( (string) $value ) ? $value : 'Rajendra Singh Panu';
}
add_filter( 'option_store_features_owner', 'store_features_owner_fallback' );
add_filter( 'default_option_store_features_owner', 'store_features_owner_fallback' );

/**
 * Contact page: who runs the store, and the grievance officer (payment providers and Indian
 * consumer rules ask for both).
 *
 * @param string $content Page content.
 * @return string
 */
function store_features_owner_on_contact( $content ) {
	if ( is_admin() || ! in_the_loop() || ! is_main_query() || ! is_page( array( 'contact', 'contact-us' ) ) ) {
		return $content;
	}
	$owner = trim( (string) get_option( 'store_features_owner', '' ) );
	if ( '' === $owner || false !== stripos( wp_strip_all_tags( $content ), 'grievance' ) ) {
		return $content;
	}
	$email = sanitize_email( (string) get_option( 'store_features_email', '' ) );
	$phone = trim( (string) get_option( 'store_features_phone', '' ) );
	$html  = '<h2 class="wp-block-heading">' . esc_html__( 'Who runs Panuwear', 'store-features' ) . '</h2>';
	/* translators: %s: owner name */
	$html .= '<p>' . esc_html( sprintf( __( 'Panuwear is owned and run by %s (sole proprietor), Haldwani, Uttarakhand.', 'store-features' ), $owner ) ) . '</p>';
	/* translators: 1: owner, 2: email, 3: phone */
	$html .= '<p>' . esc_html( sprintf( __( 'Grievance officer: %1$s. Email %2$s, phone %3$s. We reply within 48 hours and resolve complaints within 30 days.', 'store-features' ), $owner, $email, $phone ) ) . '</p>';
	return $content . $html;
}
add_filter( 'the_content', 'store_features_owner_on_contact', 20 );
