<?php
/**
 * Clean prices.
 *
 * - Every price is rounded to the nearest ₹10 (632 -> 630, 679 -> 680), for products and their
 *   variations, so the store shows tidy prices. Runs automatically after a product is saved
 *   (Qikink pushes included), once a day for all products, and on demand via REST.
 * - A variable product with different prices per size shows one price (the lowest) instead of
 *   a "₹680 – ₹710" range; the exact price shows once a size is picked.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * Round one price to the nearest 10.
 *
 * @param string $price Price as stored.
 * @return string
 */
function store_features_prices_tidy( $price ) {
	if ( '' === $price || null === $price || ! is_numeric( $price ) || (float) $price < 10 ) {
		return (string) $price;
	}
	return (string) ( (int) round( (float) $price / 10 ) * 10 );
}

/**
 * Round the prices of one product (and its variations).
 *
 * @param int $product_id Product ID.
 * @return int Number of prices changed.
 */
function store_features_prices_round( $product_id ) {
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return 0;
	}
	$GLOBALS['sf_prices_busy'] = true;
	$changed                   = 0;
	$items                     = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
	foreach ( $items as $item ) {
		$reg  = $item->get_regular_price( 'edit' );
		$sale = $item->get_sale_price( 'edit' );
		$nreg = store_features_prices_tidy( $reg );
		$nsal = store_features_prices_tidy( $sale );
		if ( '' !== $nsal && '' !== $nreg && (float) $nsal >= (float) $nreg ) {
			$nsal = '';
		}
		if ( $nreg !== (string) $reg || $nsal !== (string) $sale ) {
			$item->set_regular_price( $nreg );
			$item->set_sale_price( $nsal );
			$item->save();
			++$changed;
		}
	}
	if ( $changed && $product->is_type( 'variable' ) ) {
		WC_Product_Variable::sync( $product_id );
		wc_delete_product_transients( $product_id );
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $product_id );
		}
	}
	$GLOBALS['sf_prices_busy'] = false;
	return $changed;
}

/**
 * All published/draft products.
 *
 * @return int[]
 */
function store_features_prices_all_ids() {
	return wc_get_products(
		array(
			'limit'  => -1,
			'status' => array( 'publish', 'draft', 'pending', 'private' ),
			'return' => 'ids',
		)
	);
}

// After a product is saved (Qikink adds variations a little later, so check twice).
add_action(
	'woocommerce_update_product',
	function ( $id ) {
		if ( ! empty( $GLOBALS['sf_prices_busy'] ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		foreach ( array( 2, 16 ) as $min ) {
			if ( ! as_has_scheduled_action( 'store_features_prices_run', array( (int) $id, $min ), 'store-features' ) ) {
				as_schedule_single_action( time() + $min * MINUTE_IN_SECONDS, 'store_features_prices_run', array( (int) $id, $min ), 'store-features' );
			}
		}
	}
);
add_action(
	'store_features_prices_run',
	function ( $id ) {
		store_features_prices_round( (int) $id );
	}
);

// Daily safety sweep over all products.
add_action(
	'init',
	function () {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'store_features_prices_sweep', array(), 'store-features' ) ) {
			as_schedule_recurring_action( time() + 60, DAY_IN_SECONDS, 'store_features_prices_sweep', array(), 'store-features' );
		}
	}
);
add_action(
	'store_features_prices_sweep',
	function () {
		foreach ( store_features_prices_all_ids() as $id ) {
			store_features_prices_round( $id );
		}
	}
);

// Run now: POST /wp-json/store-features/v1/prices {id?}.
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'store-features/v1',
			'/prices',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'callback'            => function ( $request ) {
					$ids = $request['id'] ? array( (int) $request['id'] ) : store_features_prices_all_ids();
					$out = array();
					foreach ( $ids as $id ) {
						$out[ $id ] = store_features_prices_round( $id );
					}
					return $out;
				},
			)
		);
	}
);

/**
 * One price instead of a range for variable products.
 *
 * @param string     $html    Price HTML.
 * @param WC_Product $product Product.
 * @return string
 */
function store_features_prices_single( $html, $product ) {
	$prices = $product->get_variation_prices( true );
	if ( empty( $prices['price'] ) ) {
		return $html;
	}
	$min     = current( $prices['price'] );
	$max     = end( $prices['price'] );
	$min_reg = current( $prices['regular_price'] );
	if ( $min === $max && current( $prices['regular_price'] ) === end( $prices['regular_price'] ) ) {
		return $html;
	}
	if ( $product->is_on_sale() && $min_reg > $min ) {
		return wc_format_sale_price( wc_price( $min_reg ), wc_price( $min ) ) . $product->get_price_suffix();
	}
	return wc_price( $min ) . $product->get_price_suffix();
}
add_filter( 'woocommerce_variable_price_html', 'store_features_prices_single', 20, 2 );
add_filter( 'woocommerce_variable_sale_price_html', 'store_features_prices_single', 20, 2 );
