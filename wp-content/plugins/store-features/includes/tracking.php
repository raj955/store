<?php
/**
 * Visitor tracking: Google Analytics 4, Microsoft Clarity (heatmaps and session recordings) and the
 * Meta (Facebook/Instagram) Pixel, with shop events.
 *
 * Each service loads only when its ID is set in WooCommerce → Settings → Integration → Tracking.
 * Shop staff who are logged in are never tracked, so their own visits don't skew the numbers.
 *
 * Events sent (GA4 name / Meta name):
 *   view_item / ViewContent        product page
 *   add_to_cart / AddToCart        every add to cart (also from the cart drawer and customizer)
 *   begin_checkout / InitiateCheckout
 *   purchase / Purchase            once per order, on the thank-you page
 *   sign_up, login                 Google sign-in
 *   design_started, design_photo   "Design your own" customizer
 * Anything on the page can send more with window.sfTrack(name, params).
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page.
 */
function store_features_tracking_register_integration( $integrations ) {
	if ( class_exists( 'WC_Integration' ) && ! class_exists( 'Store_Features_Tracking_Integration' ) ) {
		require_once __DIR__ . '/class-tracking-integration.php';
	}
	if ( class_exists( 'Store_Features_Tracking_Integration' ) ) {
		$integrations[] = 'Store_Features_Tracking_Integration';
	}
	return $integrations;
}
add_filter( 'woocommerce_integrations', 'store_features_tracking_register_integration' );

/**
 * Tracking IDs, checked for the right shape.
 *
 * @return array{ga: string, clarity: string, pixel: string}
 */
function store_features_tracking_ids() {
	$settings = get_option( 'woocommerce_store_features_tracking_settings', array() );
	$ga       = strtoupper( trim( (string) ( $settings['ga4_id'] ?? '' ) ) );
	$clarity  = strtolower( trim( (string) ( $settings['clarity_id'] ?? '' ) ) );
	$pixel    = trim( (string) ( $settings['pixel_id'] ?? '' ) );
	return array(
		'ga'      => preg_match( '/^G-[A-Z0-9]{4,16}$/', $ga ) ? $ga : '',
		'clarity' => preg_match( '/^[a-z0-9]{6,16}$/', $clarity ) ? $clarity : '',
		'pixel'   => preg_match( '/^[0-9]{8,20}$/', $pixel ) ? $pixel : '',
	);
}

/**
 * Track this request? Not in the admin, not for logged-in staff, and only with at least one ID.
 *
 * @return bool
 */
function store_features_tracking_on() {
	if ( is_admin() || wp_doing_ajax() || current_user_can( 'edit_posts' ) || current_user_can( 'manage_woocommerce' ) ) {
		return false;
	}
	return (bool) array_filter( store_features_tracking_ids() );
}

/**
 * One product line in GA4's item format.
 *
 * @param WC_Product $product  Product or variation.
 * @param int        $quantity Quantity.
 * @param float|null $price    Price paid (defaults to the current price).
 * @return array
 */
function store_features_tracking_item( $product, $quantity = 1, $price = null ) {
	$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
	$terms  = get_the_terms( $parent->get_id(), 'product_cat' );
	$item   = array(
		'item_id'   => (string) $parent->get_id(),
		'item_name' => $parent->get_name(),
		'price'     => round( (float) ( null === $price ? wc_get_price_to_display( $product ) : $price ), 2 ),
		'quantity'  => (int) $quantity,
	);
	if ( $terms && ! is_wp_error( $terms ) ) {
		$item['item_category'] = $terms[0]->name;
	}
	if ( $product->is_type( 'variation' ) ) {
		$item['item_variant'] = implode( ' / ', array_filter( array_map( 'strval', $product->get_variation_attributes( false ) ) ) );
	}
	return $item;
}

/**
 * Events waiting to be sent from the browser (kept in the shopper's session across page loads).
 *
 * @param array|null $event Event to add, or null to take (and clear) the queue.
 * @return array
 */
function store_features_tracking_queue( $event = null ) {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return array();
	}
	$queue = (array) WC()->session->get( 'sf_track_queue', array() );
	if ( null === $event ) {
		WC()->session->set( 'sf_track_queue', array() );
		return $queue;
	}
	$queue[] = $event;
	WC()->session->set( 'sf_track_queue', array_slice( $queue, -10 ) );
	return $queue;
}

/**
 * Queue add_to_cart for every add, however it happened.
 *
 * @param string $key          Cart item key.
 * @param int    $product_id   Product ID.
 * @param int    $quantity     Quantity.
 * @param int    $variation_id Variation ID.
 */
function store_features_tracking_added( $key, $product_id, $quantity, $variation_id ) {
	if ( ! store_features_tracking_on() ) {
		return;
	}
	$product = wc_get_product( $variation_id ? $variation_id : $product_id );
	if ( ! $product ) {
		return;
	}
	$item = store_features_tracking_item( $product, $quantity );
	$cart = WC()->cart ? WC()->cart->get_cart_item( $key ) : array();
	if ( ! empty( $cart['sf_custom'] ) ) {
		$item['item_brand'] = 'Own design';
	}
	store_features_tracking_queue(
		array(
			'name'   => 'add_to_cart',
			'params' => array(
				'currency' => get_woocommerce_currency(),
				'value'    => round( $item['price'] * $item['quantity'], 2 ),
				'items'    => array( $item ),
			),
		)
	);
}
add_action( 'woocommerce_add_to_cart', 'store_features_tracking_added', 10, 4 );

/**
 * Hand queued events to the page after an add to cart without a reload.
 *
 * @param array $fragments Cart fragments.
 * @return array
 */
function store_features_tracking_fragments( $fragments ) {
	if ( store_features_tracking_on() ) {
		$fragments['div.sf-track-queue'] = store_features_tracking_queue_html( store_features_tracking_queue() );
	}
	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'store_features_tracking_fragments' );

/**
 * The hidden element that carries queued events.
 *
 * @param array $events Events.
 * @return string
 */
function store_features_tracking_queue_html( $events ) {
	return '<div class="sf-track-queue" hidden data-events="' . esc_attr( wp_json_encode( array_values( $events ) ) ) . '"></div>';
}

/**
 * Events for this page view.
 *
 * @return array
 */
function store_features_tracking_page_events() {
	$events   = store_features_tracking_queue();
	$currency = get_woocommerce_currency();

	if ( is_product() ) {
		$product = wc_get_product( get_queried_object_id() );
		if ( $product ) {
			$item     = store_features_tracking_item( $product );
			$events[] = array(
				'name'   => 'view_item',
				'params' => array( 'currency' => $currency, 'value' => $item['price'], 'items' => array( $item ) ),
			);
		}
	}

	if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) && WC()->cart && ! WC()->cart->is_empty() ) {
		$items = array();
		foreach ( WC()->cart->get_cart() as $line ) {
			$items[] = store_features_tracking_item( $line['data'], $line['quantity'], $line['line_total'] / max( 1, $line['quantity'] ) );
		}
		$events[] = array(
			'name'   => 'begin_checkout',
			'params' => array( 'currency' => $currency, 'value' => round( (float) WC()->cart->get_total( 'edit' ), 2 ), 'items' => $items ),
		);
	}

	if ( is_wc_endpoint_url( 'order-received' ) ) {
		$order_id = absint( get_query_var( 'order-received' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $order && hash_equals( $order->get_order_key(), $key ) && ! $order->get_meta( '_sf_tracked' ) && ! $order->has_status( array( 'failed', 'cancelled' ) ) ) {
			$items = array();
			foreach ( $order->get_items() as $line ) {
				$product = $line->get_product();
				if ( $product ) {
					$items[] = store_features_tracking_item( $product, $line->get_quantity(), $order->get_item_total( $line, true ) );
				}
			}
			$events[] = array(
				'name'   => 'purchase',
				'params' => array(
					'transaction_id' => (string) $order->get_order_number(),
					'currency'       => $order->get_currency(),
					'value'          => round( (float) $order->get_total(), 2 ),
					'payment_type'   => 'cod' === $order->get_payment_method() ? 'COD' : 'Prepaid',
					'items'          => $items,
				),
			);
			$order->update_meta_data( '_sf_tracked', time() );
			$order->save();
		}
	}

	return $events;
}

/**
 * Tracking tags in <head>.
 */
function store_features_tracking_head() {
	if ( ! store_features_tracking_on() ) {
		return;
	}
	$ids = store_features_tracking_ids();
	if ( $ids['ga'] ) {
		printf(
			"<script async src=\"https://www.googletagmanager.com/gtag/js?id=%1\$s\"></script>\n<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','%1\$s');</script>\n",
			esc_js( $ids['ga'] )
		);
	}
	if ( $ids['clarity'] ) {
		printf(
			"<script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src='https://www.clarity.ms/tag/'+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,'clarity','script','%s');</script>\n",
			esc_js( $ids['clarity'] )
		);
	}
	if ( $ids['pixel'] ) {
		printf(
			"<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','%s');fbq('track','PageView');</script>\n",
			esc_js( $ids['pixel'] )
		);
	}
}
add_action( 'wp_head', 'store_features_tracking_head', 5 );

/**
 * Page events and the sfTrack helper, at the end of the page.
 */
function store_features_tracking_footer() {
	if ( ! store_features_tracking_on() ) {
		return;
	}
	echo store_features_tracking_queue_html( store_features_tracking_page_events() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<script>' . store_features_tracking_script() . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_footer', 'store_features_tracking_footer', 30 );

/**
 * Sends each event to every service that is on.
 *
 * @return string
 */
function store_features_tracking_script() {
	return <<<'JS'
(function () {
	'use strict';
	var META = { view_item: 'ViewContent', add_to_cart: 'AddToCart', begin_checkout: 'InitiateCheckout', purchase: 'Purchase', sign_up: 'CompleteRegistration' };
	window.sfTrack = function (name, params) {
		params = params || {};
		if (window.gtag) {
			window.gtag('event', name, params);
		}
		if (window.fbq) {
			var meta = META[name];
			var data = { currency: params.currency, value: params.value };
			if (params.items) {
				data.content_ids = params.items.map(function (i) { return i.item_id; });
				data.content_type = 'product';
				data.contents = params.items.map(function (i) { return { id: i.item_id, quantity: i.quantity }; });
			}
			if (meta) {
				window.fbq('track', meta, data, params.transaction_id ? { eventID: 'order-' + params.transaction_id } : undefined);
			} else {
				window.fbq('trackCustom', name, params);
			}
		}
		if (window.clarity) {
			window.clarity('event', name);
		}
	};
	function flush(el) {
		if (!el) {
			return;
		}
		var events = [];
		try {
			events = JSON.parse(el.getAttribute('data-events') || '[]');
		} catch (e) {}
		el.setAttribute('data-events', '[]');
		events.forEach(function (ev) {
			window.sfTrack(ev.name, ev.params);
		});
	}
	flush(document.querySelector('.sf-track-queue'));
	if (window.jQuery) {
		window.jQuery(document.body).on('added_to_cart', function (e, fragments) {
			if (fragments && fragments['div.sf-track-queue']) {
				var box = document.createElement('div');
				box.innerHTML = fragments['div.sf-track-queue'];
				flush(box.firstChild);
			}
		});
	}
})();
JS;
}
