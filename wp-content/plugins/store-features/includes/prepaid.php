<?php
/**
 * The only offer: ₹20 off when the customer pays online (UPI, card, net banking).
 *
 * Why: a cash-on-delivery order costs about ₹40 more at Qikink (COD fee + GST), so a prepaid
 * order with ₹20 off still leaves the store better off.
 *
 * - Shows as a discount line "Online payment discount" in checkout as soon as an online method
 *   is chosen, and disappears when Cash on delivery is chosen (block and classic checkout).
 * - Checked again on the order itself before payment, so the order is always right even if the
 *   page did not refresh.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

const STORE_FEATURES_PREPAID_OFF = 20;

/**
 * Label of the discount line.
 *
 * @return string
 */
function store_features_prepaid_label() {
	return __( 'Online payment discount', 'store-features' );
}

/**
 * Payment method the customer has picked in this session ('' if none yet).
 *
 * @return string
 */
function store_features_prepaid_method() {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return '';
	}
	$method = (string) WC()->session->get( 'sf_pay_method' );
	if ( '' === $method ) {
		$method = (string) WC()->session->get( 'chosen_payment_method' );
	}
	return $method;
}

/**
 * Is this payment method an online one?
 *
 * @param string $method Gateway ID.
 * @return bool
 */
function store_features_prepaid_is_online( $method ) {
	return '' !== $method && ! in_array( $method, array( 'cod', 'bacs', 'cheque' ), true );
}

/**
 * Cart: add the discount line while an online method is chosen.
 *
 * @param WC_Cart $cart Cart.
 */
function store_features_prepaid_fee( $cart ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return;
	}
	if ( ! store_features_prepaid_is_online( store_features_prepaid_method() ) ) {
		return;
	}
	$off = min( STORE_FEATURES_PREPAID_OFF, (int) floor( (float) $cart->get_subtotal() ) );
	if ( $off > 0 ) {
		$cart->add_fee( store_features_prepaid_label(), -$off, false );
	}
}
add_action( 'woocommerce_cart_calculate_fees', 'store_features_prepaid_fee', 20 );

/**
 * Block checkout: the page tells the server which method is chosen, and the totals refresh.
 */
add_action(
	'woocommerce_blocks_loaded',
	function () {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => 'sf-pay',
				'callback'  => function ( $data ) {
					if ( WC()->session ) {
						WC()->session->set( 'sf_pay_method', sanitize_key( (string) ( $data['method'] ?? '' ) ) );
					}
				},
			)
		);
	}
);

/**
 * The script that reports the chosen method (block checkout) or refreshes totals (classic).
 */
function store_features_prepaid_script() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
		return;
	}
	?>
<script>
(function () {
	// Classic checkout: recalculate when the payment method changes.
	if (window.jQuery) {
		jQuery(document.body).on('change', 'input[name="payment_method"]', function () {
			jQuery(document.body).trigger('update_checkout');
		});
	}
	// Block checkout.
	var tries = 0, last = null;
	function start() {
		var wp = window.wp, wc = window.wc;
		if (!wp || !wp.data || !wc || !wc.blocksCheckout || !wc.blocksCheckout.extensionCartUpdate || !wp.data.select('wc/store/payment')) {
			if (++tries < 40) { setTimeout(start, 250); }
			return;
		}
		wp.data.subscribe(function () {
			var store = wp.data.select('wc/store/payment');
			var method = store && store.getActivePaymentMethod ? store.getActivePaymentMethod() : '';
			if (method && method !== last) {
				last = method;
				wc.blocksCheckout.extensionCartUpdate({ namespace: 'sf-pay', data: { method: method } });
			}
		});
	}
	start();
})();
</script>
	<?php
}
add_action( 'wp_footer', 'store_features_prepaid_script', 60 );

/**
 * Make the order match its payment method: add the discount for online payment, remove it for COD.
 *
 * @param WC_Order $order Order (not yet paid).
 */
function store_features_prepaid_fix_order( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$online  = store_features_prepaid_is_online( (string) $order->get_payment_method() );
	$label   = store_features_prepaid_label();
	$has     = false;
	$changed = false;
	foreach ( $order->get_fees() as $item_id => $fee ) {
		if ( $fee->get_name() !== $label ) {
			continue;
		}
		if ( $online && ! $has ) {
			$has = true;
			continue;
		}
		$order->remove_item( $item_id );
		$changed = true;
	}
	if ( $online && ! $has ) {
		$subtotal = (float) $order->get_subtotal();
		$off      = min( STORE_FEATURES_PREPAID_OFF, (int) floor( $subtotal ) );
		if ( $off > 0 ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( $label );
			$fee->set_amount( -$off );
			$fee->set_total( -$off );
			$fee->set_tax_status( 'none' );
			$order->add_item( $fee );
			$changed = true;
		}
	}
	if ( $changed ) {
		$order->calculate_totals( false );
	}
}
add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'store_features_prepaid_fix_order', 20 );
add_action( 'woocommerce_checkout_create_order', 'store_features_prepaid_fix_order', 20 );
