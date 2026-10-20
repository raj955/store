<?php
/**
 * Real reviews, from real buyers.
 *
 * - Only people who bought a product can review it, and their review shows "verified owner".
 * - Five days after an order is marked complete, the customer gets one short email asking how
 *   it was, with a link to review each item (photos by reply). Sent once per order.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

const STORE_FEATURES_REVIEW_DELAY = 5 * DAY_IN_SECONDS;

/**
 * Review settings: on, star ratings on, only verified buyers, with the label.
 */
function store_features_review_settings() {
	foreach ( array(
		'woocommerce_enable_reviews'                       => 'yes',
		'woocommerce_enable_review_rating'                 => 'yes',
		'woocommerce_review_rating_required'               => 'yes',
		'woocommerce_review_rating_verification_label'     => 'yes',
		'woocommerce_review_rating_verification_required'  => 'yes',
	) as $key => $value ) {
		if ( get_option( $key ) !== $value ) {
			update_option( $key, $value );
		}
	}
}
add_action( 'admin_init', 'store_features_review_settings' );
add_action( 'woocommerce_init', 'store_features_review_settings' );

/**
 * Queue the email when an order is completed.
 *
 * @param int $order_id Order ID.
 */
function store_features_queue_review_request( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || $order->get_meta( '_sf_review_asked' ) || ! $order->get_billing_email() ) {
		return;
	}
	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( time() + STORE_FEATURES_REVIEW_DELAY, 'store_features_send_review_request', array( (int) $order_id ), 'store-features' );
	} else {
		wp_schedule_single_event( time() + STORE_FEATURES_REVIEW_DELAY, 'store_features_send_review_request', array( (int) $order_id ) );
	}
}
add_action( 'woocommerce_order_status_completed', 'store_features_queue_review_request' );

/**
 * Send the email.
 *
 * @param int $order_id Order ID.
 */
function store_features_send_review_request( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || $order->get_meta( '_sf_review_asked' ) || ! in_array( $order->get_status(), array( 'completed' ), true ) ) {
		return;
	}
	$items = array();
	foreach ( $order->get_items() as $item ) {
		$product = $item->get_product();
		$parent  = $product && $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
		if ( ! $parent || isset( $items[ $parent->get_id() ] ) ) {
			continue;
		}
		if ( function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $parent ) ) {
			continue; // A one-off custom piece has no public product page to review.
		}
		$items[ $parent->get_id() ] = '<li style="margin:0 0 10px"><a href="' . esc_url( get_permalink( $parent->get_id() ) . '#reviews' ) . '">' . esc_html( $parent->get_name() ) . '</a></li>';
	}
	if ( ! $items ) {
		$order->update_meta_data( '_sf_review_asked', 'skip' );
		$order->save();
		return;
	}
	$name    = $order->get_billing_first_name();
	$heading = 'Kaisa laga aapka order?';
	$body    = '<p>' . esc_html( sprintf( 'Namaste %s,', $name ? $name : '' ) ) . '</p>'
		. '<p>Umeed hai aapka Panuwear order pasand aaya. Agar 1 minute ho, to batayein kaisa laga: print, kapda, fit. Sachcha review, chahe chhota ho, dusre logon ki bahut madad karta hai. Pehne hue photo bhejna chahein to isi mail ka reply kar dein.</p>'
		. '<ul style="padding-left:18px">' . implode( '', $items ) . '</ul>'
		. '<p>Kuch gadbad hai? Isi mail ka reply karein, hum theek karenge.</p>';

	$mailer  = WC()->mailer();
	$message = $mailer->wrap_message( $heading, $body );
	$sent    = $mailer->send( $order->get_billing_email(), 'Panuwear: aapka order kaisa laga?', $message, "Content-Type: text/html\r\n" . ( defined( 'STORE_FEATURES_BUSINESS_EMAIL' ) ? 'Reply-To: ' . STORE_FEATURES_BUSINESS_EMAIL . "\r\n" : '' ) );

	$order->update_meta_data( '_sf_review_asked', $sent ? gmdate( 'c' ) : 'failed' );
	$order->add_order_note( $sent ? 'Review request email sent.' : 'Review request email failed.' );
	$order->save();
}
add_action( 'store_features_send_review_request', 'store_features_send_review_request' );
