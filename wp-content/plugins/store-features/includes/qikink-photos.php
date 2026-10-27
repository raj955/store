<?php
/**
 * Every colour shows all of its photos (front, back, model shots), for every product, however it
 * arrived (Qikink push, Qikink plugin, our own adoption, manual).
 *
 * Qikink pushes one copy of the same photo per size, puts the colour in a local "Color"
 * attribute or in pa_color, and uploads extra mockups (back, model) to the product without
 * linking them to a colour. This module, a few minutes after a product is created or changed
 * and again once an hour for recent products:
 * - removes duplicate photos (same file content) and points every size of a colour at one photo;
 * - works out which colour each extra mockup belongs to by the colour of the garment in it;
 * - fills the product gallery (chosen colour first) and saves the colour → photos map that the
 *   product page uses to show only the chosen colour's photos.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

const STORE_FEATURES_PHOTOS_VERSION = 1;

/**
 * Colour attribute key of a variable product ('' if none).
 *
 * @param WC_Product $product Variable product.
 * @return string e.g. pa_color or color.
 */
function store_features_photos_colour_key( $product ) {
	foreach ( array_keys( $product->get_attributes() ) as $key ) {
		if ( preg_match( '/colou?r/i', (string) $key ) ) {
			return (string) $key;
		}
	}
	return '';
}

/**
 * Content hash of an attachment's file (cached).
 *
 * @param int $id Attachment ID.
 * @return string
 */
function store_features_photos_hash( $id ) {
	$hash = (string) get_post_meta( $id, '_sf_md5', true );
	if ( '' === $hash ) {
		$file = get_attached_file( $id );
		$hash = ( $file && is_readable( $file ) ) ? (string) md5_file( $file ) : 'missing-' . $id;
		update_post_meta( $id, '_sf_md5', $hash );
	}
	return $hash;
}

/**
 * Garment colour in a photo: median colour of two patches on the body, left and right of the
 * print (cached). Null if the image can't be read.
 *
 * @param int $id Attachment ID.
 * @return int[]|null RGB.
 */
function store_features_photos_signature( $id ) {
	$cached = get_post_meta( $id, '_sf_rgb', true );
	if ( is_array( $cached ) && 3 === count( $cached ) ) {
		return array_map( 'intval', $cached );
	}
	if ( ! function_exists( 'imagecreatefromstring' ) ) {
		return null;
	}
	$src  = wp_get_attachment_image_src( $id, 'thumbnail' );
	$file = get_attached_file( $id );
	if ( $src ) {
		$small = path_join( dirname( (string) $file ), wp_basename( $src[0] ) );
		if ( is_readable( $small ) ) {
			$file = $small;
		}
	}
	if ( ! $file || ! is_readable( $file ) || filesize( $file ) > 8 * MB_IN_BYTES ) {
		return null;
	}
	$im = @imagecreatefromstring( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	if ( ! $im ) {
		return null;
	}
	$w  = imagesx( $im );
	$h  = imagesy( $im );
	$px = array( array(), array(), array() );
	foreach ( array( array( .27, .37 ), array( .63, .73 ) ) as $band ) {
		for ( $x = (int) ( $w * $band[0] ); $x < (int) ( $w * $band[1] ); $x += 2 ) {
			for ( $y = (int) ( $h * .55 ); $y < (int) ( $h * .75 ); $y += 2 ) {
				$c       = imagecolorat( $im, $x, $y );
				$px[0][] = ( $c >> 16 ) & 255;
				$px[1][] = ( $c >> 8 ) & 255;
				$px[2][] = $c & 255;
			}
		}
	}
	imagedestroy( $im );
	if ( ! $px[0] ) {
		return null;
	}
	$rgb = array();
	foreach ( $px as $ch ) {
		sort( $ch );
		$rgb[] = (int) $ch[ (int) ( count( $ch ) / 2 ) ];
	}
	update_post_meta( $id, '_sf_rgb', $rgb );
	return $rgb;
}

/**
 * Organise one product's photos.
 *
 * @param int $product_id Product ID.
 * @return array|WP_Error Summary.
 */
function store_features_photos_build( $product_id ) {
	$GLOBALS['sf_photos_busy'] = true;
	$out                       = store_features_photos_build_inner( $product_id );
	$GLOBALS['sf_photos_busy'] = false;
	return $out;
}

/**
 * Worker for store_features_photos_build().
 *
 * @param int $product_id Product ID.
 * @return array|WP_Error
 */
function store_features_photos_build_inner( $product_id ) {
	$product = wc_get_product( $product_id );
	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		return new WP_Error( 'sf_photos', 'Not a variable product.' );
	}
	if ( function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product ) ) {
		return new WP_Error( 'sf_photos', 'Design your own product.' );
	}
	$key = store_features_photos_colour_key( $product );
	if ( '' === $key ) {
		return new WP_Error( 'sf_photos', 'No colour attribute.' );
	}

	// 1. Variations by colour, and every photo that belongs to the product.
	$variations = array();
	$all        = array_map( 'intval', array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) );
	foreach ( $product->get_children() as $child_id ) {
		$v = wc_get_product( $child_id );
		if ( ! $v ) {
			continue;
		}
		$attrs  = $v->get_variation_attributes( false );
		$colour = (string) ( $attrs[ $key ] ?? '' );
		if ( '' === $colour ) {
			continue;
		}
		$variations[] = array( $v, $colour );
		$all[]        = (int) $v->get_image_id();
	}
	if ( function_exists( 'store_features_qikink_unlinked_photos' ) ) {
		$all = array_merge( $all, store_features_qikink_unlinked_photos( $product ) );
	}
	$all = array_values( array_unique( array_filter( $all ) ) );
	if ( ! $variations || ! $all ) {
		return new WP_Error( 'sf_photos', 'Waiting for variations or photos.' );
	}

	// 2. One photo per distinct file.
	$canon = array();
	$first = array();
	foreach ( $all as $id ) {
		$h = store_features_photos_hash( $id );
		if ( ! isset( $first[ $h ] ) ) {
			$first[ $h ] = $id;
		}
		$canon[ $id ] = $first[ $h ];
	}

	// 3. Each colour's own photos (from its variations).
	$by_colour = array();
	foreach ( $variations as $pair ) {
		$slug = sanitize_title( $pair[1] );
		$img  = (int) $pair[0]->get_image_id();
		if ( ! isset( $by_colour[ $slug ] ) ) {
			$by_colour[ $slug ] = array();
		}
		if ( $img && isset( $canon[ $img ] ) && ! in_array( $canon[ $img ], $by_colour[ $slug ], true ) ) {
			$by_colour[ $slug ][] = $canon[ $img ];
		}
	}
	$owned = array();
	foreach ( $by_colour as $ids ) {
		$owned = array_merge( $owned, $ids );
	}

	// 4. Extra photos: give each to the colour whose garment it looks like; unclear ones go to all.
	$refs = array();
	foreach ( $by_colour as $slug => $ids ) {
		$sig = $ids ? store_features_photos_signature( $ids[0] ) : null;
		if ( $sig ) {
			$refs[ $slug ] = $sig;
		}
	}
	$common = array();
	foreach ( array_unique( array_values( $canon ) ) as $id ) {
		if ( in_array( $id, $owned, true ) ) {
			continue;
		}
		$sig  = store_features_photos_signature( $id );
		if ( ! $sig ) {
			$common[] = $id;
			continue;
		}
		// Nearest colour wins; if two colours are about as near (white vs off-white), it goes to
		// both of them, never to clearly different colours.
		$dist = array();
		foreach ( $refs as $slug => $ref ) {
			$dist[ $slug ] = sqrt( pow( $sig[0] - $ref[0], 2 ) + pow( $sig[1] - $ref[1], 2 ) + pow( $sig[2] - $ref[2], 2 ) );
		}
		asort( $dist );
		$d1   = $dist ? reset( $dist ) : PHP_INT_MAX;
		$near = array_keys( array_filter( $dist, function ( $d ) use ( $d1 ) { return $d < 90 && $d <= $d1 + 20; } ) );
		if ( $near ) {
			foreach ( $near as $slug ) {
				$by_colour[ $slug ][] = $id;
			}
		} else {
			$common[] = $id;
		}
	}
	foreach ( $by_colour as $slug => $ids ) {
		$by_colour[ $slug ] = array_values( array_unique( array_merge( $ids, $common ) ) );
	}

	// 5. Gallery (default colour first) and one photo per colour for every size.
	$defaults = $product->get_default_attributes();
	$default  = sanitize_title( (string) ( $defaults[ $key ] ?? '' ) );
	if ( ! isset( $by_colour[ $default ] ) ) {
		$default = (string) array_key_first( $by_colour );
	}
	$order   = array_merge( array( $default ), array_diff( array_keys( $by_colour ), array( $default ) ) );
	$gallery = array();
	foreach ( $order as $slug ) {
		foreach ( $by_colour[ $slug ] as $id ) {
			if ( ! in_array( $id, $gallery, true ) ) {
				$gallery[] = $id;
			}
		}
	}
	$featured = $by_colour[ $default ][0] ?? $gallery[0];
	foreach ( $variations as $pair ) {
		$slug = sanitize_title( $pair[1] );
		$want = $by_colour[ $slug ][0] ?? 0;
		if ( $want && (int) $pair[0]->get_image_id() !== $want ) {
			$pair[0]->set_image_id( $want );
			$pair[0]->save();
		}
	}
	$product->set_image_id( $featured );
	$product->set_gallery_image_ids( array_values( array_diff( $gallery, array( $featured ) ) ) );
	$product->update_meta_data( '_sf_qikink_colour_images', $by_colour );
	$product->update_meta_data( '_sf_photos', STORE_FEATURES_PHOTOS_VERSION . ':' . md5( wp_json_encode( array( $all, array_keys( $by_colour ) ) ) ) );
	$product->save();
	wc_delete_product_transients( $product->get_id() );
	if ( function_exists( 'wp_cache_post_change' ) ) {
		wp_cache_post_change( $product->get_id() ); // Refresh the cached product page.
	}
	return array( 'colours' => array_map( 'count', $by_colour ), 'common' => count( $common ), 'photos' => count( $gallery ) );
}

/**
 * Does the product need organising (new photos or colours since last time)?
 *
 * @param WC_Product $product Product.
 * @return bool
 */
function store_features_photos_stale( $product ) {
	return 0 !== strpos( (string) $product->get_meta( '_sf_photos' ), STORE_FEATURES_PHOTOS_VERSION . ':' )
		|| count( $product->get_gallery_image_ids() ) + 1 < count( array_unique( array_filter( function_exists( 'store_features_qikink_unlinked_photos' ) ? store_features_qikink_unlinked_photos( $product ) : array() ) ) );
}

// A product created or changed: organise it a few minutes later (Qikink keeps adding photos and
// variations for a while after the push), again once it settles.
add_action(
	'woocommerce_update_product',
	function ( $id ) {
		if ( ! empty( $GLOBALS['sf_photos_busy'] ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		foreach ( array( 3, 15 ) as $min ) {
			if ( ! as_next_scheduled_action( 'store_features_photos_run', array( (int) $id, $min ), 'store-features' ) ) {
				as_schedule_single_action( time() + $min * MINUTE_IN_SECONDS, 'store_features_photos_run', array( (int) $id, $min ), 'store-features' );
			}
		}
	},
	100
);
add_action(
	'store_features_photos_run',
	function ( $id ) {
		store_features_photos_build( (int) $id );
	}
);

// Hourly: products changed in the last 3 days that still need it; and once, every product.
add_action(
	'init',
	function () {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'store_features_photos_sweep', array(), 'store-features' ) ) {
			as_schedule_recurring_action( time() + 120, HOUR_IN_SECONDS, 'store_features_photos_sweep', array(), 'store-features' );
		}
	}
);
add_action(
	'store_features_photos_sweep',
	function () {
		$all  = (int) get_option( 'store_features_photos_swept' ) < STORE_FEATURES_PHOTOS_VERSION;
		$args = array( 'type' => 'variable', 'status' => array( 'publish', 'draft', 'private' ), 'limit' => -1, 'return' => 'ids' );
		if ( ! $all ) {
			$args['date_modified'] = '>' . ( time() - 3 * DAY_IN_SECONDS );
		}
		foreach ( wc_get_products( $args ) as $id ) {
			$p = wc_get_product( $id );
			if ( $p && ( $all || store_features_photos_stale( $p ) ) ) {
				store_features_photos_build( $id );
			}
		}
		if ( $all ) {
			update_option( 'store_features_photos_swept', STORE_FEATURES_PHOTOS_VERSION, false );
		}
	}
);

// Admin: run it now for one product or all: POST /wp-json/store-features/v1/photos {id?}.
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'store-features/v1',
			'/photos',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'callback'            => function ( $request ) {
					$ids = $request['id'] ? array( absint( $request['id'] ) ) : wc_get_products( array( 'type' => 'variable', 'status' => array( 'publish', 'draft', 'private' ), 'limit' => -1, 'return' => 'ids' ) );
					$out = array();
					foreach ( $ids as $id ) {
						$r          = store_features_photos_build( $id );
						$out[ $id ] = is_wp_error( $r ) ? $r->get_error_message() : $r;
					}
					return rest_ensure_response( $out );
				},
			)
		);
	}
);
