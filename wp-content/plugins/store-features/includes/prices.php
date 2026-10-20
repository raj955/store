<?php
/**
 * Store prices, set from code (agreed with the owner, 20 Oct 2026): light fabric (180 GSM) keeps
 * about ₹100 a piece after Qikink's cost; good fabric (240/300 GSM) keeps ₹200–230.
 *
 *   Front print only (the current designs): tee ₹499 · oversized ₹749 · sweatshirt ₹949 · hoodie ₹1,079
 *   Front + back print (new designs):       oversized ₹849 · sweatshirt ₹1,049 · hoodie ₹1,179
 *   Women's crop top ₹449.
 *
 * Version 2 also takes text-heavy designs (and ones printed with the old name) off the store.
 *
 * Runs once, after a normal page has been sent; "Design your own" products are not touched.
 * Bump STORE_FEATURES_PRICES_VERSION and the table to change prices again.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

const STORE_FEATURES_PRICES_VERSION = 2;

/**
 * Category slug => price.
 *
 * @return array
 */
function store_features_price_table() {
	return array(
		't-shirts'           => 499,
		'oversized-t-shirts' => 749,
		'sweatshirts'        => 949,
		'hoodies'            => 1079,
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
	// Too much text, and printed with the old brand name: hidden (drafted), not deleted.
	foreach ( array( 'befikr-oversized-tee', 'jugaad-oversized-tee', 'thoda-adjust-tee', 'ek-cutting-tee' ) as $slug ) {
		$post = get_page_by_path( $slug, OBJECT, 'product' );
		if ( $post && 'publish' === $post->post_status ) {
			wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' ) );
			$log[] = $slug . ' drafted';
		}
	}
	foreach ( store_features_price_table() as $slug => $price ) {
		$products = wc_get_products(
			array(
				'status'   => array( 'publish', 'private' ),
				'limit'    => -1,
				'category' => array( $slug ),
			)
		);
		foreach ( $products as $product ) {
			if ( function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product ) ) {
				continue;
			}
			if ( 'both' === $product->get_meta( '_sf_print_sides' ) ) {
				continue; // Front-and-back designs are priced when they are added.
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
