<?php
/**
 * Cart reminders: when someone types their email at checkout (or is logged in) and leaves
 * without ordering, they get a friendly email 1 hour later, and one more after a day.
 *
 * - The email shows what was in the cart and a button that puts it all back in the cart and
 *   opens checkout.
 * - Nothing is sent once that email places an order, and each mail has a one-click
 *   "stop these reminders" link.
 * - "Design your own" pieces are not remembered (the design lives only in that visit).
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

const STORE_FEATURES_CART_FIRST  = HOUR_IN_SECONDS;
const STORE_FEATURES_CART_SECOND = DAY_IN_SECONDS;

/**
 * Saved carts, keyed by a hash of the email.
 *
 * @return array
 */
function store_features_cart_saved() {
	$all = get_option( 'store_features_carts', array() );
	return is_array( $all ) ? $all : array();
}

/**
 * Key for an email.
 *
 * @param string $email Email.
 * @return string
 */
function store_features_cart_key( $email ) {
	return md5( strtolower( trim( $email ) ) );
}

/**
 * Remember the current cart for this email and (re)start the reminder clock.
 *
 * @param string $email Email.
 * @param string $name  First name.
 */
function store_features_cart_remember( $email, $name = '' ) {
	if ( ! is_email( $email ) || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return;
	}
	$key  = store_features_cart_key( $email );
	$stop = (array) get_option( 'store_features_cart_stop', array() );
	if ( in_array( $key, $stop, true ) ) {
		return;
	}
	$items = array();
	foreach ( WC()->cart->get_cart() as $line ) {
		if ( ! empty( $line['sf_design'] ) || ( function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( (int) $line['product_id'] ) ) ) {
			continue;
		}
		$items[] = array(
			'p' => (int) $line['product_id'],
			'v' => (int) $line['variation_id'],
			'q' => (int) $line['quantity'],
			'a' => isset( $line['variation'] ) ? (array) $line['variation'] : array(),
		);
	}
	if ( ! $items ) {
		return;
	}
	$all = store_features_cart_saved();
	$old = $all[ $key ] ?? array();
	if ( ! empty( $old['items'] ) && $old['items'] === $items && time() - (int) $old['updated'] < 10 * MINUTE_IN_SECONDS ) {
		return; // Same cart, just saved.
	}
	$all[ $key ] = array(
		'email'   => sanitize_email( $email ),
		'name'    => sanitize_text_field( $name ? $name : ( $old['name'] ?? '' ) ),
		'items'   => $items,
		'updated' => time(),
		'sent'    => 0,
		'token'   => $old['token'] ?? wp_generate_password( 24, false ),
	);
	// Keep the list small: drop carts older than 7 days, then the oldest beyond 400.
	foreach ( $all as $k => $c ) {
		if ( time() - (int) ( $c['updated'] ?? 0 ) > WEEK_IN_SECONDS ) {
			unset( $all[ $k ] );
		}
	}
	if ( count( $all ) > 400 ) {
		uasort( $all, function ( $a, $b ) { return (int) $b['updated'] <=> (int) $a['updated']; } );
		$all = array_slice( $all, 0, 400, true );
	}
	update_option( 'store_features_carts', $all, false );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'store_features_cart_reminder', array( $key, 1 ), 'store-features' );
		as_unschedule_all_actions( 'store_features_cart_reminder', array( $key, 2 ), 'store-features' );
		as_schedule_single_action( time() + STORE_FEATURES_CART_FIRST, 'store_features_cart_reminder', array( $key, 1 ), 'store-features' );
	}
}

/**
 * Forget a cart (order placed or reminders stopped).
 *
 * @param string $email Email.
 */
function store_features_cart_forget( $email ) {
	$key = store_features_cart_key( $email );
	$all = store_features_cart_saved();
	if ( isset( $all[ $key ] ) ) {
		unset( $all[ $key ] );
		update_option( 'store_features_carts', $all, false );
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'store_features_cart_reminder', array( $key, 1 ), 'store-features' );
		as_unschedule_all_actions( 'store_features_cart_reminder', array( $key, 2 ), 'store-features' );
	}
}

/* ---------------------------------------------------------------- capture */

// Block checkout: the email is sent to the server as the shopper fills the form.
add_action(
	'woocommerce_store_api_cart_update_customer_from_request',
	function ( $customer, $request ) {
		$billing = (array) $request['billing_address'];
		$email   = (string) ( $billing['email'] ?? ( $customer ? $customer->get_billing_email() : '' ) );
		store_features_cart_remember( $email, (string) ( $billing['first_name'] ?? '' ) );
	},
	10,
	2
);
// Classic checkout.
add_action(
	'woocommerce_checkout_update_order_review',
	function ( $data ) {
		parse_str( (string) $data, $fields );
		store_features_cart_remember( (string) ( $fields['billing_email'] ?? '' ), (string) ( $fields['billing_first_name'] ?? '' ) );
	}
);
// Logged-in shoppers: their account email.
add_action(
	'woocommerce_add_to_cart',
	function () {
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			add_action(
				'shutdown',
				function () use ( $user ) {
					store_features_cart_remember( $user->user_email, $user->first_name );
				},
				1
			);
		}
	},
	20
);
// An order placed: no reminders for that email.
add_action(
	'woocommerce_checkout_order_processed',
	function ( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			store_features_cart_forget( $order->get_billing_email() );
		}
	}
);
add_action(
	'woocommerce_store_api_checkout_order_processed',
	function ( $order ) {
		if ( $order instanceof WC_Order ) {
			store_features_cart_forget( $order->get_billing_email() );
		}
	}
);

/* ---------------------------------------------------------------- send */

/**
 * Send reminder 1 or 2.
 *
 * @param string $key   Cart key.
 * @param int    $stage 1 or 2.
 */
function store_features_cart_send( $key, $stage ) {
	$all  = store_features_cart_saved();
	$cart = $all[ $key ] ?? null;
	if ( ! $cart || (int) $cart['sent'] >= (int) $stage ) {
		return;
	}
	// Ordered meanwhile (any way, even as a guest with another session)?
	$orders = wc_get_orders(
		array(
			'billing_email' => $cart['email'],
			'date_created'  => '>' . ( (int) $cart['updated'] - HOUR_IN_SECONDS ),
			'status'        => array( 'pending', 'processing', 'on-hold', 'completed' ),
			'limit'         => 1,
			'return'        => 'ids',
		)
	);
	if ( $orders ) {
		store_features_cart_forget( $cart['email'] );
		return;
	}

	$rows  = '';
	$count = 0;
	foreach ( $cart['items'] as $it ) {
		$product = wc_get_product( $it['v'] ? $it['v'] : $it['p'] );
		$parent  = wc_get_product( $it['p'] );
		if ( ! $product || ! $parent || 'publish' !== $parent->get_status() || ! $product->is_in_stock() ) {
			continue;
		}
		++$count;
		$img   = wp_get_attachment_image_url( $product->get_image_id() ? $product->get_image_id() : $parent->get_image_id(), 'woocommerce_thumbnail' );
		$extra = $product->is_type( 'variation' ) ? wc_get_formatted_variation( $product, true, false, false ) : '';
		$rows .= '<tr>'
			. '<td style="padding:8px 12px 8px 0;width:84px;vertical-align:top">' . ( $img ? '<img src="' . esc_url( $img ) . '" width="80" style="display:block;width:80px;height:auto;border-radius:8px" alt="">' : '' ) . '</td>'
			. '<td style="padding:8px 0;vertical-align:top"><strong>' . esc_html( $parent->get_name() ) . '</strong><br>'
			. ( $extra ? '<span style="color:#565D7A">' . esc_html( $extra ) . '</span><br>' : '' )
			. esc_html( $it['q'] . ' × ' ) . wp_kses_post( wc_price( $product->get_price() ) ) . '</td></tr>';
	}
	if ( ! $count ) {
		store_features_cart_forget( $cart['email'] );
		return;
	}

	$back = add_query_arg( 'sf_cart', $cart['token'], home_url( '/' ) );
	$stop = add_query_arg( 'sf_cart_stop', $cart['token'], home_url( '/' ) );
	$hi   = $cart['name'] ? sprintf( 'Namaste %s,', $cart['name'] ) : 'Namaste,';
	if ( 1 === (int) $stage ) {
		$subject = 'Aapka cart wait kar raha hai 🛒';
		$heading = 'Kuch bhool to nahi gaye?';
		$lead    = 'Aapne Panuwear par ye cheezein cart mein rakhi thi. Sab abhi bhi available hain, bas ek click mein order ho jayega.';
	} else {
		$subject = 'Aapki pasand abhi bhi cart mein hai';
		$heading = 'Order complete karein';
		$lead    = 'Ek yaad dila dein: aapka cart abhi bhi save hai. Online payment (UPI, card) karein to ₹20 aur kam lagte hain. Shipping poore India mein free hai.';
	}
	$body = '<p>' . esc_html( $hi ) . '</p><p>' . esc_html( $lead ) . '</p>'
		. '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;margin:12px 0 18px">' . $rows . '</table>'
		. '<p style="margin:0 0 22px"><a href="' . esc_url( $back ) . '" style="display:inline-block;padding:13px 26px;background:#171C3A;color:#ffffff;border-radius:8px;font-weight:700;text-decoration:none">Order complete karein</a></p>'
		. '<p>Size ya kisi bhi cheez mein confusion ho to isi mail ka reply karein, hum madad karenge.</p>'
		. '<p style="font-size:12px;color:#8B90A6">Ye reminder nahi chahiye? <a href="' . esc_url( $stop ) . '" style="color:#8B90A6">Yahan click karein</a>, aage se nahi aayega.</p>';

	$mailer = WC()->mailer();
	$sent   = $mailer->send( $cart['email'], $subject, $mailer->wrap_message( $heading, $body ), "Content-Type: text/html\r\n" . ( defined( 'STORE_FEATURES_BUSINESS_EMAIL' ) ? 'Reply-To: ' . STORE_FEATURES_BUSINESS_EMAIL . "\r\n" : '' ) );

	$all = store_features_cart_saved();
	if ( isset( $all[ $key ] ) ) {
		$all[ $key ]['sent'] = (int) $stage;
		update_option( 'store_features_carts', $all, false );
	}
	$log   = (array) get_option( 'store_features_cart_log', array() );
	$log[] = gmdate( 'Y-m-d H:i' ) . ' reminder ' . (int) $stage . ( $sent ? ' sent' : ' FAILED' );
	update_option( 'store_features_cart_log', array_slice( $log, -50 ), false );

	if ( 1 === (int) $stage && function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( time() + STORE_FEATURES_CART_SECOND, 'store_features_cart_reminder', array( $key, 2 ), 'store-features' );
	}
}
add_action( 'store_features_cart_reminder', 'store_features_cart_send', 10, 2 );

/* ---------------------------------------------------------------- links in the email */

/**
 * Find a saved cart by its token.
 *
 * @param string $token Token.
 * @return array{0: string, 1: array}|null Key and cart.
 */
function store_features_cart_by_token( $token ) {
	$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
	if ( strlen( $token ) < 20 ) {
		return null;
	}
	foreach ( store_features_cart_saved() as $key => $cart ) {
		if ( isset( $cart['token'] ) && hash_equals( (string) $cart['token'], $token ) ) {
			return array( $key, $cart );
		}
	}
	return null;
}

add_action(
	'wp_loaded',
	function () {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- links from our own email, token checked.
		if ( isset( $_GET['sf_cart_stop'] ) ) {
			$found = store_features_cart_by_token( wp_unslash( $_GET['sf_cart_stop'] ) );
			if ( $found ) {
				$stop   = (array) get_option( 'store_features_cart_stop', array() );
				$stop[] = $found[0];
				update_option( 'store_features_cart_stop', array_values( array_unique( $stop ) ), false );
				store_features_cart_forget( $found[1]['email'] );
			}
			wp_die( esc_html__( 'Done. You will not get cart reminders from us again.', 'store-features' ), esc_html( get_bloginfo( 'name' ) ), array( 'response' => 200, 'link_url' => home_url( '/' ), 'link_text' => get_bloginfo( 'name' ) ) );
		}
		if ( ! isset( $_GET['sf_cart'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$found = store_features_cart_by_token( wp_unslash( $_GET['sf_cart'] ) );
		// phpcs:enable
		if ( $found ) {
			$in_cart = array();
			foreach ( WC()->cart->get_cart() as $line ) {
				$in_cart[] = (int) $line['product_id'] . ':' . (int) $line['variation_id'];
			}
			foreach ( $found[1]['items'] as $it ) {
				if ( ! in_array( $it['p'] . ':' . $it['v'], $in_cart, true ) ) {
					WC()->cart->add_to_cart( $it['p'], max( 1, $it['q'] ), $it['v'], $it['a'] );
				}
			}
			if ( WC()->customer && ! WC()->customer->get_billing_email() ) {
				WC()->customer->set_billing_email( $found[1]['email'] );
				WC()->customer->save();
			}
			wc_clear_notices();
		}
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	},
	30
);
