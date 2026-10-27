<?php
/**
 * "Free delivery" and "Cash on delivery", shown big and clear wherever people decide to buy:
 * home page, product page (right under the price), product cards, cart and checkout, and the
 * top bar. Hindi first, with a short English line, because most shoppers read both.
 *
 * "Design your own" pieces are paid online, so they only show free delivery.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Hindi tagline.
 *
 * @return string
 */
function store_features_trust_tagline() {
	return 'घर बैठे मँगाओ, सामान मिले तब पैसे दो।';
}

/**
 * Icon SVG.
 *
 * @param string $name truck|cash.
 * @return string
 */
function store_features_trust_icon( $name ) {
	$paths = array(
		'truck' => '<path d="M3 6h11v9H3z"/><path d="M14 9h4l3 3v3h-7"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
		'cash'  => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 9.5v5M18 9.5v5"/>',
	);
	return '<svg class="sf-trust__svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
}

/**
 * The two promises.
 *
 * @param bool   $cod     Show cash on delivery.
 * @param string $variant full (tiles) | line (one compact line for cards).
 * @param bool   $tagline Show the Hindi tagline above the tiles.
 * @return string
 */
function store_features_trust_html( $cod = true, $variant = 'full', $tagline = false ) {
	if ( 'line' === $variant ) {
		$parts = '<span class="sf-trust-line__item">' . store_features_trust_icon( 'truck' ) . 'Free delivery</span>';
		if ( $cod ) {
			$parts .= '<span class="sf-trust-line__item">' . store_features_trust_icon( 'cash' ) . 'COD</span>';
		}
		return '<p class="sf-trust-line">' . $parts . '</p>';
	}
	$tiles = array(
		array( 'truck', 'फ्री डिलीवरी', 'पूरे भारत में · Free shipping' ),
	);
	if ( $cod ) {
		$tiles[] = array( 'cash', 'कैश ऑन डिलीवरी', 'सामान मिले, तब पैसे दो · COD' );
	}
	$html = '<div class="sf-trust' . ( 1 === count( $tiles ) ? ' sf-trust--single' : '' ) . '">';
	if ( $tagline && $cod ) {
		$html .= '<p class="sf-trust__tagline">' . esc_html( store_features_trust_tagline() ) . '</p>';
	}
	$html .= '<ul class="sf-trust__tiles">';
	foreach ( $tiles as $t ) {
		$html .= '<li class="sf-trust__tile"><span class="sf-trust__icon">' . store_features_trust_icon( $t[0] ) . '</span><span class="sf-trust__text"><strong class="sf-trust__title" lang="hi">' . esc_html( $t[1] ) . '</strong><span class="sf-trust__sub">' . esc_html( $t[2] ) . '</span></span></li>';
	}
	return $html . '</ul></div>';
}

/**
 * Does the cart hold a "Design your own" piece (online payment only)?
 *
 * @return bool
 */
function store_features_trust_cart_has_custom() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || ! function_exists( 'store_features_is_custom_product' ) ) {
		return false;
	}
	foreach ( WC()->cart->get_cart() as $line ) {
		if ( store_features_is_custom_product( (int) $line['product_id'] ) ) {
			return true;
		}
	}
	return false;
}

/* ---------------------------------------------------------------- product page: right under the price */
add_action(
	'woocommerce_single_product_summary',
	function () {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$custom = function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product );
		echo store_features_trust_html( ! $custom ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	},
	11
);

/* ---------------------------------------------------------------- product cards: one short line under the price */
add_action(
	'woocommerce_after_shop_loop_item_title',
	function () {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$custom = function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product );
		echo store_features_trust_html( ! $custom, 'line' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	},
	11
);

/* ---------------------------------------------------------------- cart and checkout pages */
add_filter(
	'render_block',
	function ( $html, $block ) {
		if ( is_admin() || ! in_array( $block['blockName'] ?? '', array( 'woocommerce/cart', 'woocommerce/checkout' ), true ) ) {
			return $html;
		}
		return store_features_trust_html( ! store_features_trust_cart_has_custom(), 'full', true ) . $html;
	},
	10,
	2
);
add_action(
	'woocommerce_before_cart',
	function () {
		echo store_features_trust_html( ! store_features_trust_cart_has_custom(), 'full', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
);

/* ---------------------------------------------------------------- styles */
add_action(
	'wp_enqueue_scripts',
	function () {
		wp_add_inline_style(
			'store-features',
			<<<'CSS'
.sf-trust { margin: 14px 0 18px; }
.sf-trust__tagline { margin: 0 0 10px; color: var(--pw-ink, #17171A); font-family: var(--pw-hand, "Kalam", cursive); font-size: 20px; font-weight: 700; line-height: 1.3; }
.sf-trust__tiles { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin: 0 !important; padding: 0 !important; list-style: none; }
.sf-trust--single .sf-trust__tiles { grid-template-columns: minmax(0, 1fr); }
.sf-trust__tile { display: flex; align-items: center; gap: 12px; margin: 0 !important; padding: 12px 14px; border: 1.5px solid #BFD6C3; border-radius: 14px; background: #EEF6EF; }
.sf-trust__icon { flex: 0 0 auto; display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; border-radius: 50%; background: #2F6B3F; color: #fff; }
.sf-trust__svg { width: 24px; height: 24px; }
.sf-trust__text { display: flex; flex-direction: column; min-width: 0; }
.sf-trust__title { color: #173A22; font-size: 17px; font-weight: 800; line-height: 1.25; }
.sf-trust__sub { margin-top: 2px; color: #3C5A44; font-size: 13px; line-height: 1.35; }
@media (max-width: 420px) {
	.sf-trust__tile { flex-direction: column; align-items: flex-start; gap: 8px; padding: 12px; }
	.sf-trust__icon { width: 38px; height: 38px; }
	.sf-trust__title { font-size: 16px; }
	.sf-trust--single .sf-trust__tile { flex-direction: row; align-items: center; }
}
.sf-trust-line { display: flex; flex-wrap: wrap; gap: 4px 12px; margin: 4px 0 0 !important; color: #2F6B3F; font-size: 12.5px; font-weight: 700; line-height: 1.3; }
.sf-trust-line__item { display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
.sf-trust-line .sf-trust__svg { width: 16px; height: 16px; }
/* The small promise list under the title is replaced by the tiles under the price. */
.sf-product-promises { display: none !important; }
.wp-block-woocommerce-cart > .sf-trust, .wp-block-woocommerce-checkout > .sf-trust { max-width: 720px; }
CSS
		);
	},
	40
);
