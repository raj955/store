<?php
/**
 * Cancellation window: a new order reaches Qikink only after 12 hours, so a cancelled order is
 * never printed.
 *
 * How it works:
 * 1. A new order (COD or paid) is put "On hold" (shown as "Confirmed") instead of "Processing".
 *    The customer gets the order email with a line that they can still cancel for 12 hours.
 * 2. While an order is held, nothing about it is delivered to Qikink's webhook.
 * 3. After 12 hours, if it hasn't been cancelled, it moves to Processing and goes to Qikink.
 *    If it was cancelled, it never goes, and the order notes say so.
 * 4. Logged-in customers see a Cancel button in My account during the window. Others message us.
 * 5. Need it sooner? Set the order to Processing by hand: it goes to Qikink straight away.
 * 6. An order cancelled after it went to Qikink emails the owner to cancel it in Qikink too.
 *
 * Orders placed before this existed are not touched. "Design your own" orders keep their own
 * manual review and are not held here.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

const STORE_FEATURES_HOLD_HOURS = 12;

/**
 * Switched off on 20 Oct 2026 at the owner's request: orders go to Qikink at once, so delivery
 * isn't delayed. Set to true to bring the 12-hour window back.
 */
const STORE_FEATURES_HOLD_ENABLED = false;

/**
 * Should this order wait out the cancellation window?
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function store_features_hold_applies( $order ) {
	if ( ! STORE_FEATURES_HOLD_ENABLED || ! $order instanceof WC_Order ) {
		return false;
	}
	if ( function_exists( 'store_features_custom_order_has_design' ) && store_features_custom_order_has_design( $order ) ) {
		return false;
	}
	return true;
}

/**
 * Is the order still inside its window (held, not yet released)?
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function store_features_hold_active( $order ) {
	return $order instanceof WC_Order && $order->get_meta( '_sf_hold_until' ) && 'yes' !== $order->get_meta( '_sf_qikink_released' );
}

/**
 * Start the window on an order.
 *
 * @param WC_Order $order Order.
 */
function store_features_hold_start( $order ) {
	if ( $order->get_meta( '_sf_hold_until' ) ) {
		return;
	}
	$until = time() + STORE_FEATURES_HOLD_HOURS * HOUR_IN_SECONDS;
	$order->update_meta_data( '_sf_hold_until', $until );
	$order->add_order_note(
		sprintf(
			/* translators: %s: time */
			__( 'Cancellation window: this order goes to Qikink at %s if it is not cancelled. To send it now, set the status to Processing.', 'store-features' ),
			wp_date( 'j M, g:i a', $until )
		)
	);
	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( $until + 60, 'store_features_hold_release', array( 'order_id' => $order->get_id() ), 'store-features' );
	}
}

/**
 * COD orders: "On hold" instead of "Processing" while the window runs.
 *
 * @param string   $status Status.
 * @param WC_Order $order  Order.
 * @return string
 */
function store_features_hold_cod_status( $status, $order = null ) {
	if ( 'processing' !== $status || ! store_features_hold_applies( $order ) ) {
		return $status;
	}
	store_features_hold_start( $order );
	return 'on-hold';
}
add_filter( 'woocommerce_cod_process_payment_order_status', 'store_features_hold_cod_status', 20, 2 );

/**
 * Paid orders (Razorpay and others): the same.
 *
 * @param string   $status   Status.
 * @param int      $order_id Order ID.
 * @param WC_Order $order    Order.
 * @return string
 */
function store_features_hold_paid_status( $status, $order_id = 0, $order = null ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
	if ( 'processing' !== $status || ! store_features_hold_applies( $order ) || 'yes' === $order->get_meta( '_sf_qikink_released' ) ) {
		return $status;
	}
	store_features_hold_start( $order );
	return 'on-hold';
}
add_filter( 'woocommerce_payment_complete_order_status', 'store_features_hold_paid_status', 20, 3 );

/**
 * Nothing goes to Qikink's webhook while an order is in its window.
 *
 * @param bool       $should  Deliver?
 * @param WC_Webhook $webhook Webhook.
 * @param mixed      $arg     Resource ID.
 * @return bool
 */
function store_features_hold_gate_webhook( $should, $webhook, $arg ) {
	if ( ! $should || ! $webhook instanceof WC_Webhook || false === stripos( (string) $webhook->get_delivery_url(), 'qikink' ) ) {
		return $should;
	}
	if ( 'order' !== $webhook->get_resource() ) {
		return $should;
	}
	$order = wc_get_order( is_numeric( $arg ) ? (int) $arg : ( is_object( $arg ) && method_exists( $arg, 'get_id' ) ? $arg->get_id() : 0 ) );
	if ( $order && store_features_hold_active( $order ) ) {
		return false;
	}
	if ( $order && $order->get_meta( '_sf_hold_until' ) && $order->has_status( array( 'cancelled', 'failed' ) ) && 'yes' !== $order->get_meta( '_sf_qikink_released' ) ) {
		return false; // Cancelled inside the window: Qikink never hears about it.
	}
	return $should;
}
add_filter( 'woocommerce_webhook_should_deliver', 'store_features_hold_gate_webhook', 10, 3 );

/**
 * Send an order to Qikink now: mark it released, move it to Processing, and make sure every
 * Qikink webhook gets it (a webhook that only listens for new orders wouldn't fire otherwise).
 *
 * @param WC_Order $order Order.
 * @param string   $why   Note.
 */
function store_features_hold_release_now( $order, $why ) {
	$order->update_meta_data( '_sf_qikink_released', 'yes' );
	$order->save();
	$order->update_status( 'processing', $why );
	store_features_hold_push_created_webhooks( $order );
}

/**
 * Qikink webhooks that only listen for new orders never fire on a status change, so push to them.
 * (Status-change and update webhooks fire by themselves once the order is released.)
 *
 * @param WC_Order $order Order.
 */
function store_features_hold_push_created_webhooks( $order ) {
	if ( ! class_exists( 'WC_Data_Store' ) ) {
		return;
	}
	try {
		$store = WC_Data_Store::load( 'webhook' );
		foreach ( $store->search_webhooks( array( 'status' => 'active', 'limit' => 20 ) ) as $id ) {
			$hook = wc_get_webhook( $id );
			if ( $hook && 'order.created' === $hook->get_topic() && false !== stripos( (string) $hook->get_delivery_url(), 'qikink' ) ) {
				$hook->deliver( $order->get_id() );
			}
		}
	} catch ( Exception $e ) {
		$order->add_order_note( 'Qikink webhook push failed: ' . $e->getMessage() );
	}
}

/**
 * The window has passed: send the order, unless it was cancelled.
 *
 * @param int $order_id Order ID.
 */
function store_features_hold_release( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || ! store_features_hold_active( $order ) ) {
		return;
	}
	if ( (int) $order->get_meta( '_sf_hold_until' ) > time() ) {
		return;
	}
	if ( ! $order->has_status( 'on-hold' ) ) {
		if ( $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) && ! $order->get_meta( '_sf_hold_noted' ) ) {
			$order->update_meta_data( '_sf_hold_noted', 'yes' );
			$order->add_order_note( __( 'Cancelled inside the cancellation window: not sent to Qikink, nothing printed.', 'store-features' ) );
			$order->save();
		}
		return;
	}
	store_features_hold_release_now( $order, __( 'Cancellation window over: sent to Qikink for printing.', 'store-features' ) );
}
add_action( 'store_features_hold_release', 'store_features_hold_release' );

/**
 * Safety net if scheduled tasks are slow on this host: after a normal page has been sent,
 * release any orders whose window has passed (checked at most every 5 minutes).
 */
function store_features_hold_sweep() {
	if ( wp_doing_ajax() || wp_doing_cron() || get_transient( 'store_features_hold_sweep' ) ) {
		return;
	}
	set_transient( 'store_features_hold_sweep', 1, 5 * MINUTE_IN_SECONDS );
	if ( ! is_admin() ) {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}
	$due = wc_get_orders(
		array(
			'status'       => array( 'wc-on-hold' ),
			'limit'        => 10,
			'return'       => 'ids',
			'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_sf_hold_until',
					'value'   => time(),
					'compare' => '<=',
					'type'    => 'NUMERIC',
				),
			),
		)
	);
	foreach ( $due as $id ) {
		store_features_hold_release( $id );
	}
}
add_action( 'shutdown', 'store_features_hold_sweep', 98 );

/**
 * Owner moved a held order to Processing by hand: it's released at once.
 *
 * @param int      $order_id Order ID.
 * @param WC_Order $order    Order.
 */
function store_features_hold_manual_release( $order_id, $order = null ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
	if ( $order && store_features_hold_active( $order ) ) {
		$order->update_meta_data( '_sf_qikink_released', 'yes' );
		$order->save();
		$order->add_order_note( __( 'Set to Processing by hand: sent to Qikink before the window ended.', 'store-features' ) );
		store_features_hold_push_created_webhooks( $order );
	}
}
add_action( 'woocommerce_order_status_processing', 'store_features_hold_manual_release', 5, 2 );

/**
 * Cancelled after it already went to Qikink (it was Processing): tell the owner to cancel it there too.
 *
 * @param int      $order_id Order ID.
 * @param WC_Order $order    Order.
 */
function store_features_hold_late_cancel( $order_id, $order = null ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	$to = get_option( 'woocommerce_new_order_settings' );
	$to = is_array( $to ) && ! empty( $to['recipient'] ) ? $to['recipient'] : get_option( 'admin_email' );
	wp_mail(
		$to,
		/* translators: %s: order number */
		sprintf( __( 'Cancel order #%s in Qikink too', 'store-features' ), $order->get_order_number() ),
		/* translators: %s: order number */
		sprintf( __( "Order #%s was cancelled on the website, but it had already gone to Qikink.\n\nOpen the Qikink dashboard, Orders, and cancel it there now if it isn't printed yet.", 'store-features' ), $order->get_order_number() )
	);
	$order->add_order_note( __( 'Cancelled after it went to Qikink: owner emailed to cancel it in Qikink too.', 'store-features' ) );
}
add_action( 'woocommerce_order_status_processing_to_cancelled', 'store_features_hold_late_cancel', 10, 2 );

/**
 * My account: a Cancel button while the window runs.
 *
 * @param array    $statuses Statuses that can be cancelled.
 * @param WC_Order $order    Order.
 * @return array
 */
function store_features_hold_customer_cancel( $statuses, $order = null ) {
	if ( STORE_FEATURES_HOLD_ENABLED && $order instanceof WC_Order && store_features_hold_active( $order ) && (int) $order->get_meta( '_sf_hold_until' ) > time() ) {
		$statuses[] = 'on-hold';
	}
	return $statuses;
}
add_filter( 'woocommerce_valid_order_statuses_for_cancel', 'store_features_hold_customer_cancel', 10, 2 );

/**
 * "On hold" reads as a problem to customers; call it "Confirmed".
 *
 * @param array $statuses Order statuses.
 * @return array
 */
function store_features_hold_status_label( $statuses ) {
	if ( STORE_FEATURES_HOLD_ENABLED && isset( $statuses['wc-on-hold'] ) ) {
		$statuses['wc-on-hold'] = _x( 'Confirmed', 'Order status', 'store-features' );
	}
	return $statuses;
}
add_filter( 'wc_order_statuses', 'store_features_hold_status_label' );

/**
 * The on-hold email talks about waiting for payment; for our held orders say what is true.
 *
 * @param string $translated Text.
 * @param string $text       Original.
 * @param string $domain     Domain.
 * @return string
 */
function store_features_hold_email_text( $translated, $text, $domain ) {
	if ( STORE_FEATURES_HOLD_ENABLED && 'woocommerce' === $domain && false !== stripos( $text, 'on-hold until we confirm' ) ) {
		/* translators: %d: hours */
		return sprintf( __( 'Thanks for your order! It is confirmed, and printing starts in %d hours. Want to change or cancel it? Reply to this email or message us on WhatsApp before then.', 'store-features' ), STORE_FEATURES_HOLD_HOURS );
	}
	return $translated;
}
add_filter( 'gettext', 'store_features_hold_email_text', 10, 3 );
