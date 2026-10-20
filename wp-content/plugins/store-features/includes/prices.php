<?php
/**
 * Store prices, set once from code (20 Oct 2026, agreed with the owner): about ₹100 profit a piece
 * after Qikink's cost, close to what shoppers pay elsewhere.
 *
 *   Regular tee ₹499 · Oversized tee ₹649 · Sweatshirt ₹999 · Hoodie ₹1,199
 *   (New front-and-back oversized designs ₹749, women's crop tops ₹449, set when they are added.)
 *
 * Runs once, after a normal page has been sent; "Design your own" products are not touched.
 * Bump STORE_FEATURES_PRICES_VERSION and the table to change prices again.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

const STORE_FEATURES_PRICES_VERSION = 1;

/**
 * Category slug => price.
 *
 * @return array
 */
function store_features_price_table() {
	return array(
		't-shirts'           => 499,
		'oversized-t-shirts' => 649,
		'sweatshirts'        => 999,
		'hoodies'            => 1199,
	);
}

/**
 * Apply the table to every ready-design product (and all its variations).
 */
function store_features_apply_prices() {
	if ( (int) get_option( 'store_features_prices_done', 0 ) >= STORE_FEATURES_PRICES_VERSION || get_transient( 'store_features_prices_lock' ) ) {
		return;
	}
	if ( wp_doing_ajax() || wp_doing_cron() || ! function_exists( 'wc_get_products' ) ) {
		return;
	}
	set_transient( 'store_features_prices_lock', 1, 120 );
	$log = array();
	foreach ( store_features_price_table() as $slug => $price ) {
		$products = wc_get_products(
			array(
				'status'   => array( 'publish', 'draft', 'private' ),
				'limit'    => -1,
				'category' => array( $slug ),
			)
		);
		foreach ( $products as $product ) {
			if ( function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product ) ) {
				continue;
			}
			$targets = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
			foreach ( $targets as $item ) {
				$item->set_regular_price( (string) $price );
				$item->set_sale_price( '' );
				$item->save();
			}
			if ( $product->is_type( 'variable' ) ) {
				WC_Product_Variable::sync( $product->get_id() );
				wc_delete_product_transients( $product->get_id() );
			}
			$log[] = $product->get_name() . ' = ' . $price;
		}
	}
	update_option( 'store_features_prices_done', STORE_FEATURES_PRICES_VERSION, false );
	delete_transient( 'store_features_prices_lock' );
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->info( 'Prices set: ' . implode( '; ', $log ), array( 'source' => 'store-features-prices' ) );
	}
}
add_action(
	'shutdown',
	function () {
		if ( (int) get_option( 'store_features_prices_done', 0 ) >= STORE_FEATURES_PRICES_VERSION ) {
			return;
		}
		if ( ! is_admin() ) {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			} elseif ( function_exists( 'litespeed_finish_request' ) ) {
				litespeed_finish_request();
			}
		}
		store_features_apply_prices();
	},
	97
);
