<?php
/**
 * Store prices, set from code (agreed with the owner, 20 Oct 2026): light fabric (180 GSM) keeps
 * about ₹100 a piece after Qikink's cost; good fabric (240/300 GSM) keeps ₹200–230.
 *
 *   From Qikink's real costs (product + print, plus ₹64 shipping, ₹127 for fleece):
 *   Front print only: tee ₹529 · oversized ₹719 · sweatshirt ₹849 · hoodie ₹1,029
 *   Front + back print (new designs, set in Qikink when pushed): oversized ₹779
 *   Women's crop top ₹449.
 *   Design your own: a little above the ready price (bigger print area, checked by hand).
 *
 * Version 2 also takes text-heavy designs (and ones printed with the old name) off the store.
 *
 * Runs once, after a normal page has been sent; "Design your own" products are not touched.
 * Bump STORE_FEATURES_PRICES_VERSION and the table to change prices again.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

const STORE_FEATURES_PRICES_VERSION = 7;

/**
 * Category slug => price (fallback for products not in the slug list below).
 *
 * @return array
 */
function store_features_price_table() {
	return array(
		't-shirts'           => 499,
		'oversized-t-shirts' => 699,
		'sweatshirts'        => 849,
		'hoodies'            => 1029,
		'women'              => 449,
		'mugs'               => 299,
		'tote-bags'          => 349,
	);
}

/**
 * Exact price per product (slug => price), from each product's Qikink cost + shipping:
 * light fabric keeps about ₹100, good fabric ₹200–230.
 *
 * @return array
 */
function store_features_price_by_slug() {
	return array(
		// Oversized 240 GSM, front print (Qikink ₹399 + ₹64 shipping)
		'chill-maar-oversized-tee'          => 699,
		'moh-maya-oversized-tee'            => 699,
		// Oversized 240 GSM, chest + back print (Qikink ₹462 + ₹64)
		'har-har-mahadev-oversized-tee'     => 749,
		'radhe-radhe-oversized-tee'         => 749,
		'pahadi-raste-oversized-tee'        => 749,
		'azaad-oversized-tee'               => 749,
		'no-excuses-oversized-tee'          => 749,
		'never-settle-oversized-tee'        => 749,
		'chai-over-everything-oversized-tee' => 749,
		// T-shirts 180 GSM
		'dheere-chalo-tee'                  => 499,
		'kal-karenge-tee'                   => 499,
		'azaad-tee'                         => 499,
		'chai-over-everything-tee'          => 499,
		// Fleece 300 GSM (shipping ₹127)
		'sardi-hai-sweatshirt'              => 849,
		'never-settle-sweatshirt'           => 939,
		'pahad-hoodie'                      => 1029,
		'har-har-mahadev-hoodie'            => 1079,
		'pahadi-raste-hoodie'               => 1079,
		// Women's crop tops 180 GSM (Qikink ₹231–262 + ₹64)
		'sab-theek-ho-jayega-crop-top'      => 449,
		'khilti-raho-crop-top'              => 449,
		'udaan-baaki-hai-crop-top'          => 449,
		'one-of-a-kind-crop-top'            => 449,
		'dhoop-si-raho-crop-top'            => 449,
		'radha-rani-crop-top'               => 449,
		'chai-kitaab-crop-top'              => 449,
		'cat-mom-crop-top'                  => 449,
		// Women's oversized 240 GSM, front print (Qikink ₹399 + ₹64)
		'sab-theek-ho-jayega-oversized-tee' => 699,
		'udaan-baaki-hai-oversized-tee'     => 699,
		// Mugs 11 oz (Qikink ₹121–189 + ₹64) and tote bags (+ ₹64)
		'chai-over-everything-mug'          => 299,
		'sab-theek-ho-jayega-mug'           => 299,
		'chai-kitaab-mug'                   => 299,
		'radhe-radhe-mug'                   => 299,
		'har-har-mahadev-black-mug'         => 349,
		'never-settle-black-mug'            => 349,
		'khilti-raho-tote-bag'              => 349,
		'dhoop-si-raho-tote-bag'            => 349,
		'chai-kitaab-tote-bag'              => 349,
		'cat-mom-tote-bag'                  => 349,
	);
}

/**
 * Set one product (and its variations) to a price.
 *
 * @param WC_Product $product Product.
 * @param int        $price   Price.
 */
function store_features_set_product_price( $product, $price ) {
	$targets = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
	foreach ( $targets as $item ) {
		if ( (string) $item->get_regular_price() === (string) $price && '' === (string) $item->get_sale_price() ) {
			continue;
		}
		$item->set_regular_price( (string) $price );
		$item->set_sale_price( '' );
		$item->save();
	}
	if ( $product->is_type( 'variable' ) ) {
		WC_Product_Variable::sync( $product->get_id() );
		wc_delete_product_transients( $product->get_id() );
	}
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
	$by_slug = store_features_price_by_slug();
	foreach ( $by_slug as $slug => $price ) {
		$post    = get_page_by_path( $slug, OBJECT, 'product' );
		$product = $post ? wc_get_product( $post->ID ) : null;
		if ( $product ) {
			store_features_set_product_price( $product, $price );
			$log[] = $slug . ' = ' . $price;
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
			if ( isset( $by_slug[ $product->get_slug() ] ) ) {
				continue; // Priced exactly above.
			}
			if ( 'women' === $slug && has_term( 'men', 'product_cat', $product->get_id() ) ) {
				continue; // Unisex: not priced as a women-only item.
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
	// Design your own: same rule, by garment.
	foreach ( array( 'design-your-own-t-shirt' => 549, 'design-your-own-oversized-t-shirt' => 749, 'design-your-own-sweatshirt' => 899, 'design-your-own-hoodie' => 1079, 'design-your-own-crop-top' => 499 ) as $slug => $price ) {
		$post    = get_page_by_path( $slug, OBJECT, 'product' );
		$product = $post ? wc_get_product( $post->ID ) : null;
		if ( ! $product ) {
			$log[] = $slug . ' not found';
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
		$log[] = $slug . ' = ' . $price;
	}
	update_option( 'store_features_prices_done', STORE_FEATURES_PRICES_VERSION, false );
	update_option( 'store_features_prices_log', implode( '; ', $log ), false );
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
		try {
			store_features_apply_prices();
		} catch ( Throwable $e ) {
			update_option( 'store_features_prices_error', $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine(), false );
			delete_transient( 'store_features_prices_lock' );
		}
	},
	97
);

/**
 * Read-only status of the price update (version and last error), for checking a deploy.
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'store-features/v1',
			'/prices-status',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					return array(
						'code_version' => STORE_FEATURES_PRICES_VERSION,
						'done'         => (int) get_option( 'store_features_prices_done', 0 ),
						'locked'       => (bool) get_transient( 'store_features_prices_lock' ),
						'error'        => (string) get_option( 'store_features_prices_error', '' ),
						'log'          => (string) get_option( 'store_features_prices_log', '' ),
					);
				},
			)
		);
	}
);
