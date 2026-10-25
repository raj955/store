<?php
/**
 * Offers and buying help, the way the big Indian tee stores do it:
 *
 * - Bundle offer: 2 ready-design pieces in the cart = ₹20 off, 3 or more = ₹30 off (flat, so every
 *   order stays in profit). Applied automatically as a discount line; "Design your own" pieces don't count.
 * - First-order code PEHLA20: ₹20 off a first order of ₹599 or more (ready designs).
 * - Any other coupon (made by hand in WooCommerce for one customer) replaces the bundle offer,
 *   so two discounts never stack into a loss.
 * - The offer in the top bar, under Add to cart, and a nudge in the cart ("add 1 more").
 * - "Not sure about size?" helper on product pages: height and weight in, a size out.
 *
 * Percentages and the code live in store_features_offer_config() so they are easy to change.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * The offer settings.
 *
 * @return array
 */
function store_features_offer_config() {
	return apply_filters(
		'store_features_offer_config',
		array(
			// pieces => rupees off the whole order
			// Bundle offer and first-order code switched off (Oct 2026): the only offer now is
			// ₹20 off for paying online (see prepaid.php). Put tiers back here to restore them.
			'tiers'       => array(),
			'coupon'      => '',
			'coupon_off'  => 20,
			'coupon_min'  => 599,
		)
	);
}

/**
 * Is this cart line a ready design (not a custom piece)?
 *
 * @param array $item Cart item.
 * @return bool
 */
function store_features_offer_counts( $item ) {
	if ( isset( $item['sf_custom'] ) ) {
		return false;
	}
	$product = $item['data'] ?? null;
	if ( ! $product instanceof WC_Product ) {
		return false;
	}
	return ! ( function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product ) );
}

/**
 * Pieces and value of ready designs in the cart.
 *
 * @return array{0:int,1:float}
 */
function store_features_offer_cart_count() {
	$count = 0;
	$value = 0.0;
	if ( function_exists( 'WC' ) && WC()->cart ) {
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( store_features_offer_counts( $item ) ) {
				$count += (int) $item['quantity'];
				$value += (float) $item['line_subtotal'];
			}
		}
	}
	return array( $count, $value );
}

/**
 * The bundle saving in rupees for a number of pieces (0 when none applies).
 *
 * @param int $count Pieces.
 * @return int
 */
function store_features_offer_amount( $count ) {
	foreach ( store_features_offer_config()['tiers'] as $min => $amount ) {
		if ( $count >= $min ) {
			return (int) $amount;
		}
	}
	return 0;
}

/**
 * The next tier above a number of pieces: array( pieces, rupees ), or null at the top.
 *
 * @param int $count Pieces.
 * @return array|null
 */
function store_features_offer_next( $count ) {
	$tiers = store_features_offer_config()['tiers'];
	ksort( $tiers );
	foreach ( $tiers as $min => $amount ) {
		if ( $count < $min ) {
			return array( (int) $min, (int) $amount );
		}
	}
	return null;
}

/**
 * The bundle discount, as a negative fee line ("Bundle offer: 3 pieces, ₹200 off").
 *
 * @param WC_Cart $cart Cart.
 */
function store_features_offer_fee( $cart ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return;
	}
	$own = store_features_offer_config()['coupon'];
	foreach ( $cart->get_applied_coupons() as $code ) {
		if ( strtolower( $code ) !== $own ) {
			return; // A hand-made coupon is in use: it replaces the bundle offer.
		}
	}
	list( $count, $value ) = store_features_offer_cart_count();
	$amount                = min( store_features_offer_amount( $count ), (int) floor( $value ) );
	if ( $amount <= 0 ) {
		return;
	}
	/* translators: 1: pieces, 2: rupees */
	$cart->add_fee( sprintf( __( 'Bundle offer: %1$d pieces, ₹%2$d off', 'store-features' ), $count, $amount ), -$amount, false );
}
add_action( 'woocommerce_cart_calculate_fees', 'store_features_offer_fee' );

/**
 * PEHLA20 exists without being created in the admin.
 *
 * @param mixed  $data Coupon data (false when unknown).
 * @param string $code Code.
 * @return mixed
 */
function store_features_offer_coupon_data( $data, $code ) {
	$c = store_features_offer_config();
	if ( strtolower( (string) $code ) !== $c['coupon'] ) {
		return $data;
	}
	return array(
		'code'                 => $c['coupon'],
		'discount_type'        => 'fixed_cart',
		'amount'               => $c['coupon_off'],
		'minimum_amount'       => $c['coupon_min'],
		'individual_use'       => false,
		// PEHLA20 is a virtual coupon, not a database coupon. WooCommerce Store API's
		// per-user limit validator calls get_data_store(), which is null for this coupon.
		// The custom first-order checks below enforce one-time eligibility instead.
		'exclude_product_ids'  => function_exists( 'store_features_custom_product_ids' ) ? store_features_custom_product_ids() : array(),
	);
}
add_filter( 'woocommerce_get_shop_coupon_data', 'store_features_offer_coupon_data', 10, 2 );

/**
 * Last 10 digits of a phone number ("" when it doesn't look like one).
 *
 * @param string $phone Phone.
 * @return string
 */
function store_features_phone10( $phone ) {
	$digits = preg_replace( '/\D/', '', (string) $phone );
	return strlen( $digits ) >= 10 ? substr( $digits, -10 ) : '';
}

/**
 * Has this person ordered before? Checks the account, every email and the phone number,
 * so a code used as a guest can't be used again after signing up (or the other way round).
 *
 * @param array $who Keys: user_id, emails (array), phone.
 * @return bool
 */
function store_features_offer_has_ordered( $who ) {
	$statuses = array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-cancelled', 'wc-failed', 'wc-pending', 'wc-checkout-draft' ) );
	$base     = array(
		'status' => $statuses,
		'limit'  => 1,
		'return' => 'ids',
	);
	if ( ! empty( $who['user_id'] ) && wc_get_orders( $base + array( 'customer_id' => (int) $who['user_id'] ) ) ) {
		return true;
	}
	foreach ( array_unique( array_filter( array_map( 'sanitize_email', (array) ( $who['emails'] ?? array() ) ) ) ) as $email ) {
		if ( wc_get_orders( $base + array( 'billing_email' => $email ) ) ) {
			return true;
		}
	}
	$p10 = store_features_phone10( $who['phone'] ?? '' );
	if ( $p10 ) {
		if ( wc_get_orders( $base + array( 'meta_key' => '_sf_phone10', 'meta_value' => $p10 ) ) ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			return true;
		}
		foreach ( array( $p10, '+91' . $p10, '91' . $p10, '0' . $p10, '+91 ' . $p10, '+91 ' . substr( $p10, 0, 5 ) . ' ' . substr( $p10, 5 ) ) as $variant ) {
			if ( wc_get_orders( $base + array( 'billing_phone' => $variant ) ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Who is shopping right now, from the cart's customer and the login.
 *
 * @return array
 */
function store_features_offer_current_shopper() {
	$who = array(
		'user_id' => get_current_user_id(),
		'emails'  => array(),
		'phone'   => '',
	);
	if ( function_exists( 'WC' ) && WC()->customer ) {
		$who['emails'][] = WC()->customer->get_billing_email();
		$who['phone']    = WC()->customer->get_billing_phone();
	}
	if ( is_user_logged_in() ) {
		$who['emails'][] = wp_get_current_user()->user_email;
	}
	return $who;
}

/**
 * The message shown when the code was already used.
 *
 * @return string
 */
function store_features_offer_used_message() {
	/* translators: %s: code */
	return sprintf( __( '%s is for your first order only. The bundle offer still applies.', 'store-features' ), strtoupper( store_features_offer_config()['coupon'] ) );
}

/**
 * In the cart: PEHLA20 only on a first order.
 *
 * @param bool      $valid  Valid so far.
 * @param WC_Coupon $coupon Coupon.
 * @return bool
 * @throws Exception When it is not a first order.
 */
function store_features_offer_first_order( $valid, $coupon ) {
	if ( ! $valid || strtolower( $coupon->get_code() ) !== store_features_offer_config()['coupon'] ) {
		return $valid;
	}
	if ( store_features_offer_has_ordered( store_features_offer_current_shopper() ) ) {
		throw new Exception( esc_html( store_features_offer_used_message() ) );
	}
	return $valid;
}
add_filter( 'woocommerce_coupon_is_valid', 'store_features_offer_first_order', 10, 2 );

/**
 * Does an order carry the first-order code?
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function store_features_offer_order_has_code( $order ) {
	$code = store_features_offer_config()['coupon'];
	foreach ( $order->get_coupon_codes() as $c ) {
		if ( strtolower( $c ) === $code ) {
			return true;
		}
	}
	return false;
}

/**
 * At checkout (block checkout), check again with the email and phone the customer typed,
 * before the order is placed. Also remember the phone's last 10 digits on every order.
 *
 * @param WC_Order        $order   Order being placed.
 * @param WP_REST_Request $request Request.
 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the code was used before.
 */
function store_features_offer_checkout_guard( $order, $request = null ) {
	$p10 = store_features_phone10( $order->get_billing_phone() ?: $order->get_shipping_phone() );
	if ( $p10 ) {
		$order->update_meta_data( '_sf_phone10', $p10 );
	}
	if ( ! store_features_offer_order_has_code( $order ) ) {
		return;
	}
	$who = array(
		'user_id' => $order->get_customer_id() ?: get_current_user_id(),
		'emails'  => array( $order->get_billing_email(), is_user_logged_in() ? wp_get_current_user()->user_email : '' ),
		'phone'   => $order->get_billing_phone() ?: $order->get_shipping_phone(),
	);
	if ( store_features_offer_has_ordered( $who ) ) {
		$message = store_features_offer_used_message() . ' ' . __( 'Remove the code and place the order again.', 'store-features' );
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'sf_first_order_code_used', esc_html( $message ), 400 );
		}
		throw new Exception( esc_html( $message ) );
	}
}
add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'store_features_offer_checkout_guard', 10, 2 );

/**
 * Classic checkout: the same check, as a checkout error.
 *
 * @param array    $data   Posted data.
 * @param WP_Error $errors Errors.
 */
function store_features_offer_classic_guard( $data, $errors ) {
	$applied = function_exists( 'WC' ) && WC()->cart ? array_map( 'strtolower', WC()->cart->get_applied_coupons() ) : array();
	if ( ! in_array( store_features_offer_config()['coupon'], $applied, true ) ) {
		return;
	}
	$who = array(
		'user_id' => get_current_user_id(),
		'emails'  => array( $data['billing_email'] ?? '', is_user_logged_in() ? wp_get_current_user()->user_email : '' ),
		'phone'   => $data['billing_phone'] ?? '',
	);
	if ( store_features_offer_has_ordered( $who ) ) {
		$errors->add( 'sf_first_order_code_used', store_features_offer_used_message() );
	}
}
add_action( 'woocommerce_after_checkout_validation', 'store_features_offer_classic_guard', 10, 2 );

/**
 * Classic checkout: remember the phone's last 10 digits on the order too.
 *
 * @param WC_Order $order Order.
 */
function store_features_offer_remember_phone( $order ) {
	$p10 = store_features_phone10( $order->get_billing_phone() ?: $order->get_shipping_phone() );
	if ( $p10 && ! $order->get_meta( '_sf_phone10' ) ) {
		$order->update_meta_data( '_sf_phone10', $p10 );
	}
}
add_action( 'woocommerce_checkout_create_order', 'store_features_offer_remember_phone' );

/**
 * The short offer line used in the top bar.
 *
 * @return string
 */
function store_features_offer_line() {
	$from = store_features_lowest_price();
	return $from
		/* translators: %s: lowest price */
		? sprintf( __( 'Free shipping across India | ₹20 off on online payment | Tees from ₹%s', 'store-features' ), number_format_i18n( $from ) )
		: __( 'Free shipping across India | ₹20 off on online payment', 'store-features' );
}

/**
 * Lowest price of a ready design on sale right now (cached for an hour, cleared when a product is saved).
 *
 * @return int
 */
function store_features_lowest_price() {
	$cached = get_transient( 'store_features_lowest_price' );
	if ( false !== $cached ) {
		return (int) $cached;
	}
	$min = 0;
	if ( function_exists( 'wc_get_products' ) ) {
		foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => 100, 'category' => array( 't-shirts', 'oversized-t-shirts', 'women' ) ) ) as $product ) {
			if ( function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product ) ) {
				continue;
			}
			$p = (float) ( $product->is_type( 'variable' ) ? $product->get_variation_price( 'min', true ) : $product->get_price() );
			if ( $p > 0 && ( ! $min || $p < $min ) ) {
				$min = $p;
			}
		}
	}
	set_transient( 'store_features_lowest_price', (int) round( $min ), HOUR_IN_SECONDS );
	return (int) round( $min );
}

/**
 * Top bar: the offer instead of the plain shipping line (custom product pages keep theirs).
 *
 * @param mixed $mods Theme settings.
 * @return mixed
 */
function store_features_offer_topbar( $mods ) {
	if ( is_admin() || ! did_action( 'wp' ) || ! function_exists( 'store_features_custom_fix_strings' ) ) {
		return $mods;
	}
	if ( function_exists( 'is_product' ) && is_product() && function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( get_queried_object_id() ) ) {
		return $mods;
	}
	return store_features_custom_fix_strings(
		$mods,
		function ( $text ) {
			return 0 === stripos( trim( wp_strip_all_tags( $text ) ), 'Free shipping and cash on delivery' ) && strlen( $text ) < 120
				? store_features_offer_line()
				: $text;
		}
	);
}
add_filter( 'option_theme_mods_' . get_option( 'stylesheet' ), 'store_features_offer_topbar', 11 );

/**
 * Under Add to cart: the offer and the first-order code.
 */
function store_features_offer_product_note() {
	global $product;
	if ( ! $product instanceof WC_Product || ( function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product ) ) ) {
		return;
	}
	$off = defined( 'STORE_FEATURES_PREPAID_OFF' ) ? STORE_FEATURES_PREPAID_OFF : 20;
	/* translators: %d: rupees */
	echo '<div class="sf-offer" role="note"><p class="sf-offer__main"><strong>' . esc_html( sprintf( __( 'Pay online and save ₹%d.', 'store-features' ), $off ) ) . '</strong> ' . esc_html__( 'UPI, cards or net banking at checkout. Cash on delivery is also available.', 'store-features' ) . '</p></div>';
}
add_action( 'woocommerce_single_product_summary', 'store_features_offer_product_note', 31 );

/**
 * Cart and checkout nudge: how close the next saving is.
 *
 * @return string
 */
function store_features_offer_nudge_html() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return '';
	}
	$off = defined( 'STORE_FEATURES_PREPAID_OFF' ) ? STORE_FEATURES_PREPAID_OFF : 20;
	/* translators: %d: rupees */
	return '<div class="sf-offer sf-offer--cart" role="note"><p class="sf-offer__main"><strong>' . esc_html( sprintf( __( 'Pay online at checkout and get ₹%d off.', 'store-features' ), $off ) ) . '</strong></p></div>';
}

/**
 * Put the nudge above the cart and checkout blocks.
 *
 * @param string $content Block HTML.
 * @return string
 */
function store_features_offer_block_nudge( $content ) {
	return store_features_offer_nudge_html() . $content;
}
add_filter( 'render_block_woocommerce/cart', 'store_features_offer_block_nudge', 20 );
add_filter( 'render_block_woocommerce/checkout', 'store_features_offer_block_nudge', 20 );

/**
 * "Not sure about size?" on ready-design product pages.
 */
function store_features_size_helper() {
	global $product;
	if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) || ( function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product ) ) ) {
		return;
	}
	if ( function_exists( 'store_features_is_wearable' ) && ! store_features_is_wearable( $product ) ) {
		return; // Mugs and bags have no clothing size.
	}
	$oversized = has_term( array( 'oversized-t-shirts' ), 'product_cat', $product->get_id() ) || false !== stripos( $product->get_name(), 'oversized' );
	?>
	<details class="sf-sizehelp" data-oversized="<?php echo $oversized ? '1' : '0'; ?>">
		<summary><?php esc_html_e( 'Not sure about your size? We\'ll suggest one', 'store-features' ); ?></summary>
		<div class="sf-sizehelp__body">
			<label><?php esc_html_e( 'Height (cm)', 'store-features' ); ?> <input type="number" inputmode="numeric" min="130" max="210" class="sf-sizehelp__h" placeholder="170"></label>
			<label><?php esc_html_e( 'Weight (kg)', 'store-features' ); ?> <input type="number" inputmode="numeric" min="35" max="150" class="sf-sizehelp__w" placeholder="65"></label>
			<p class="sf-sizehelp__out" aria-live="polite"></p>
		</div>
	</details>
	<?php
}
add_action( 'woocommerce_single_product_summary', 'store_features_size_helper', 32 );

/**
 * Styles and the size helper script.
 */
function store_features_offer_assets() {
	if ( is_admin() ) {
		return;
	}
	$css = '.sf-offer{margin:14px 0;padding:12px 14px;border:1px dashed var(--rani,#C2185B);border-radius:10px;background:#FFF5F9;color:var(--ink,#2B3150);font-size:14.5px;line-height:1.5}'
		. '.sf-offer p{margin:0}.sf-offer p+p{margin-top:6px}.sf-offer code{padding:1px 6px;border-radius:4px;background:#fff;border:1px solid #F3C2D6;color:var(--rani,#C2185B);font-weight:700;font-family:inherit}'
		. '.sf-offer a{color:var(--neel,#1E2650);font-weight:600;text-decoration:underline;text-underline-offset:3px}'
		. '.sf-offer--cart{max-width:1100px;margin:0 auto 16px}'
		. '.sf-sizehelp{margin:6px 0 14px;font-size:14.5px}.sf-sizehelp summary{cursor:pointer;color:var(--neel,#1E2650);font-weight:600;text-decoration:underline;text-underline-offset:3px}'
		. '.sf-sizehelp__body{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:end;padding:12px 0 0}.sf-sizehelp label{display:flex;flex-direction:column;gap:4px;font-size:13.5px;color:var(--muted,#565D7A)}'
		. '.sf-sizehelp input{width:110px;padding:8px 10px;border:1px solid #C9CCDA;border-radius:8px;font-size:16px}.sf-sizehelp__out{flex-basis:100%;margin:2px 0 0;color:var(--ink,#2B3150)}';
	wp_add_inline_style( 'store-features', $css );
	$js = <<<'JS'
(function () {
	function pick(h, w) {
		var sizes = ['XS', 'S', 'M', 'L', 'XL', '2XL'];
		var i = w <= 50 ? 0 : w <= 58 ? 1 : w <= 67 ? 2 : w <= 77 ? 3 : w <= 88 ? 4 : 5;
		if (h >= 183 && i < 5) { i++; }
		if (h <= 160 && i > 0) { i--; }
		return sizes[i];
	}
	document.addEventListener('input', function (e) {
		var box = e.target.closest && e.target.closest('.sf-sizehelp');
		if (!box) { return; }
		var h = parseFloat(box.querySelector('.sf-sizehelp__h').value), w = parseFloat(box.querySelector('.sf-sizehelp__w').value);
		var out = box.querySelector('.sf-sizehelp__out');
		if (!(h >= 130 && h <= 210 && w >= 35 && w <= 150)) { out.textContent = ''; return; }
		var s = pick(h, w), sizes = ['XS', 'S', 'M', 'L', 'XL', '2XL'], down = sizes[Math.max(0, sizes.indexOf(s) - 1)];
		out.innerHTML = box.dataset.oversized === '1'
			? 'Take <strong>' + s + '</strong> for the relaxed oversized look' + (down !== s ? ', or <strong>' + down + '</strong> for a closer fit.' : '.')
			: 'Take <strong>' + s + '</strong>. Between two sizes? Go one up for comfort.';
	});
})();
JS;
	wp_add_inline_script( 'store-features', $js );
}
add_action( 'wp_enqueue_scripts', 'store_features_offer_assets', 35 );

add_action(
	'woocommerce_update_product',
	function () {
		delete_transient( 'store_features_lowest_price' );
	}
);

/**
 * Clothes (not mugs or bags): only these get size help and size chart lines.
 *
 * @param WC_Product|int $product Product.
 * @return bool
 */
function store_features_is_wearable( $product ) {
	$id = $product instanceof WC_Product ? $product->get_id() : (int) $product;
	return ! has_term( array( 'mugs', 'tote-bags' ), 'product_cat', $id );
}
