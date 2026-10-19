<?php
/**
 * Take over a product that Qikink pushed to the store ("Push to Store") from one of our older copies.
 *
 * Qikink makes the product with its own variation SKUs (that is how its plugin sends orders), its
 * mockup photos, and plain "Color" / "Size" attributes. This keeps the SKUs and photos as they are,
 * and only:
 *  - moves Color / Size onto the store's colour and size attributes, so swatches, quick add and the
 *    size line work as on every other product;
 *  - copies the words and categories from the older product it replaces, and gives it that URL;
 *  - puts one Qikink photo per colour in the gallery;
 *  - turns the older product into a draft (nothing is deleted).
 *
 * Admin only: POST /wp-json/store-features/v1/adopt-qikink { new_id, old_id, colour }.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'store-features/v1',
			'/adopt-qikink',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'callback'            => 'store_features_adopt_qikink_product',
			)
		);
		register_rest_route(
			'store-features/v1',
			'/qikink-photos-tidy',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'callback'            => 'store_features_qikink_photos_tidy',
			)
		);
	}
);

/**
 * Point every variation of a colour at the one gallery photo of that colour.
 * Body: { id }. Only touches products adopted from Qikink.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function store_features_qikink_photos_tidy( $request ) {
	$product = wc_get_product( absint( $request['id'] ) );
	if ( ! $product || ! $product->is_type( 'variable' ) || 'yes' !== $product->get_meta( '_sf_from_qikink' ) ) {
		return new WP_Error( 'sf_tidy', 'Not a product adopted from Qikink.', array( 'status' => 400 ) );
	}
	$gallery = array_filter( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) ) );
	$by_colour = array();
	$children  = array();
	foreach ( $product->get_children() as $child_id ) {
		$variation = wc_get_product( $child_id );
		if ( ! $variation ) {
			continue;
		}
		$colour     = (string) $variation->get_meta( 'attribute_pa_color' );
		$colour     = '' !== $colour ? $colour : (string) get_post_meta( $child_id, 'attribute_pa_color', true );
		$children[] = array( $variation, $colour );
		if ( in_array( (int) $variation->get_image_id(), $gallery, true ) && empty( $by_colour[ $colour ] ) ) {
			$by_colour[ $colour ] = (int) $variation->get_image_id();
		}
	}
	$changed = 0;
	foreach ( $children as $row ) {
		list( $variation, $colour ) = $row;
		if ( ! empty( $by_colour[ $colour ] ) && (int) $variation->get_image_id() !== $by_colour[ $colour ] ) {
			$variation->set_image_id( $by_colour[ $colour ] );
			$variation->save();
			++$changed;
		}
	}
	wc_delete_product_transients( $product->get_id() );
	return rest_ensure_response( array( 'id' => $product->get_id(), 'colours' => count( $by_colour ), 'changed' => $changed ) );
}

/**
 * Qikink colour names → our colour term slug, with a swatch colour for terms we do not have yet.
 *
 * @return array<string, array{0: string, 1: string, 2: string}> name => [slug, label, hex]
 */
function store_features_qikink_colour_map() {
	return array(
		'black'            => array( 'black', 'Black', '#1E1E21' ),
		'white'            => array( 'white', 'White', '#F4F4F1' ),
		'off white'        => array( 'off-white', 'Off-white', '#EFEBE3' ),
		'navy blue'        => array( 'navy', 'Navy', '#1F2A44' ),
		'maroon'           => array( 'maroon', 'Maroon', '#5C1E2A' ),
		'bottle green'     => array( 'bottle-green', 'Bottle green', '#2E4A3B' ),
		'olive green'      => array( 'olive', 'Olive', '#5A5F38' ),
		'royal blue'       => array( 'royal-blue', 'Royal blue', '#2B4BA8' ),
		'grey melange'     => array( 'grey-melange', 'Grey melange', '#BDBDBD' ),
		'mustard yellow'   => array( 'mustard', 'Mustard', '#D29A2A' ),
		'lavender'         => array( 'lavender', 'Lavender', '#C9B8E8' ),
		'mint'             => array( 'mint', 'Mint', '#BEE8D6' ),
		'baby blue'        => array( 'baby-blue', 'Baby blue', '#BCD9F0' ),
		'light baby pink'  => array( 'light-pink', 'Light pink', '#F6CFDC' ),
		'red'              => array( 'red', 'Red', '#B3202A' ),
		'coral'            => array( 'coral', 'Coral', '#E0705F' ),
		'beige'            => array( 'beige', 'Beige', '#E3C79A' ),
		'purple'           => array( 'purple', 'Purple', '#3B2349' ),
		'petrol blue'      => array( 'petrol-blue', 'Petrol blue', '#1D4550' ),
		'golden yellow'    => array( 'golden-yellow', 'Golden yellow', '#F2A516' ),
		'peach'            => array( 'peach', 'Peach', '#F6CBA8' ),
		'charcoal melange' => array( 'charcoal-melange', 'Charcoal melange', '#4A4A4C' ),
	);
}

/**
 * Qikink size name → our size term slug and label.
 *
 * @param string $name Qikink size, e.g. "XXL".
 * @return array{0: string, 1: string}
 */
function store_features_qikink_size( $name ) {
	$key = strtolower( trim( $name ) );
	$map = array(
		'xs'   => array( 'xs', 'XS' ),
		's'    => array( 's', 'S' ),
		'm'    => array( 'm', 'M' ),
		'l'    => array( 'l', 'L' ),
		'xl'   => array( 'xl', 'XL' ),
		'xxl'  => array( '2xl', '2XL' ),
		'2xl'  => array( '2xl', '2XL' ),
		'xxxl' => array( '3xl', '3XL' ),
		'3xl'  => array( '3xl', '3XL' ),
		'4xl'  => array( '4xl', '4XL' ),
		'5xl'  => array( '5xl', '5XL' ),
	);
	return $map[ $key ] ?? array( sanitize_title( $name ), trim( $name ) );
}

/**
 * Make sure a term exists; returns its slug.
 *
 * @param string $taxonomy pa_color or pa_size.
 * @param string $slug Slug.
 * @param string $label Name.
 * @param string $hex Swatch colour for new colour terms.
 * @return string|WP_Error
 */
function store_features_ensure_term( $taxonomy, $slug, $label, $hex = '' ) {
	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( ! $term ) {
		$made = wp_insert_term( $label, $taxonomy, array( 'slug' => $slug ) );
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		$term = get_term( $made['term_id'], $taxonomy );
	}
	if ( $hex && 'pa_color' === $taxonomy && '' === (string) get_term_meta( $term->term_id, 'cfvsw_color', true ) ) {
		update_term_meta( $term->term_id, 'cfvsw_color', $hex );
	}
	return $term->slug;
}

/**
 * The REST callback.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function store_features_adopt_qikink_product( $request ) {
	$new = wc_get_product( absint( $request['new_id'] ) );
	$old = $request['old_id'] ? wc_get_product( absint( $request['old_id'] ) ) : null;
	if ( ! $new || ! $new->is_type( 'variable' ) ) {
		return new WP_Error( 'sf_adopt', 'The Qikink product was not found.', array( 'status' => 400 ) );
	}
	if ( $old && $old->get_id() === $new->get_id() ) {
		return new WP_Error( 'sf_adopt', 'Old and new are the same product.', array( 'status' => 400 ) );
	}
	$want_colour = sanitize_title( (string) $request['colour'] );
	$colours     = store_features_qikink_colour_map();
	$log         = array();

	// 1. Read Qikink's plain attributes.
	$color_key = '';
	$size_key  = '';
	foreach ( $new->get_attributes() as $key => $attribute ) {
		if ( $attribute->is_taxonomy() ) {
			continue;
		}
		$label = strtolower( $attribute->get_name() );
		if ( in_array( $label, array( 'color', 'colour' ), true ) ) {
			$color_key = $key;
		} elseif ( 'size' === $label ) {
			$size_key = $key;
		}
	}
	if ( ! $color_key || ! $size_key ) {
		return new WP_Error( 'sf_adopt', 'This product has no plain Color and Size attributes (already done?).', array( 'status' => 400 ) );
	}

	// 2. Map every variation; collect the terms in use and one photo per colour.
	$colour_slugs = array();
	$size_slugs   = array();
	$photos       = array();
	$variations   = array();
	foreach ( $new->get_children() as $child_id ) {
		$variation = wc_get_product( $child_id );
		if ( ! $variation ) {
			continue;
		}
		$raw_colour = (string) $variation->get_attribute( $color_key );
		$raw_size   = (string) $variation->get_attribute( $size_key );
		if ( '' === $raw_colour ) {
			$raw_colour = (string) get_post_meta( $child_id, 'attribute_' . $color_key, true );
		}
		if ( '' === $raw_size ) {
			$raw_size = (string) get_post_meta( $child_id, 'attribute_' . $size_key, true );
		}
		$ckey = strtolower( trim( $raw_colour ) );
		$c    = $colours[ $ckey ] ?? array( sanitize_title( $raw_colour ), ucfirst( strtolower( trim( $raw_colour ) ) ), '' );
		$cs   = store_features_ensure_term( 'pa_color', $c[0], $c[1], $c[2] );
		$s    = store_features_qikink_size( $raw_size );
		$ss   = store_features_ensure_term( 'pa_size', $s[0], $s[1] );
		if ( is_wp_error( $cs ) || is_wp_error( $ss ) ) {
			return is_wp_error( $cs ) ? $cs : $ss;
		}
		$colour_slugs[ $cs ] = true;
		$size_slugs[ $ss ]   = true;
		if ( empty( $photos[ $cs ] ) && $variation->get_image_id() ) {
			$photos[ $cs ] = (int) $variation->get_image_id();
		}
		$variations[] = array( $variation, $cs, $ss );
	}

	// 3. Product attributes: the store's colour and size, in the store's order.
	$ordered = function ( $taxonomy, $used ) {
		$ids = array();
		foreach ( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'menu_order' ) ) as $term ) {
			if ( isset( $used[ $term->slug ] ) ) {
				$ids[] = (int) $term->term_id;
			}
		}
		return $ids;
	};
	$attributes = array();
	foreach ( array( 'pa_color' => $colour_slugs, 'pa_size' => $size_slugs ) as $taxonomy => $used ) {
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
		$attribute->set_name( $taxonomy );
		$attribute->set_options( $ordered( $taxonomy, $used ) );
		$attribute->set_position( 'pa_color' === $taxonomy ? 0 : 1 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$attributes[ $taxonomy ] = $attribute;
	}
	$new->set_attributes( $attributes );
	wp_set_object_terms( $new->get_id(), array_keys( $colour_slugs ), 'pa_color' );
	wp_set_object_terms( $new->get_id(), array_keys( $size_slugs ), 'pa_size' );

	foreach ( $variations as $row ) {
		list( $variation, $cs, $ss ) = $row;
		delete_post_meta( $variation->get_id(), 'attribute_' . $color_key );
		delete_post_meta( $variation->get_id(), 'attribute_' . $size_key );
		$variation->set_attributes( array( 'pa_color' => $cs, 'pa_size' => $ss ) );
		// Qikink uploads a copy of the photo per variation; one photo per colour lets the
		// gallery jump to that colour's slide when a colour is picked.
		if ( ! empty( $photos[ $cs ] ) ) {
			$variation->set_image_id( $photos[ $cs ] );
		}
		$variation->save();
	}
	$log[] = count( $variations ) . ' variations on store colours/sizes';

	// 4. Photos: default colour first, then the rest in swatch order.
	$order = $ordered( 'pa_color', $colour_slugs );
	$first = $want_colour && isset( $photos[ $want_colour ] ) ? $want_colour : (string) array_key_first( $photos );
	$gallery = array();
	foreach ( $order as $term_id ) {
		$slug = get_term( $term_id, 'pa_color' )->slug;
		if ( $slug !== $first && ! empty( $photos[ $slug ] ) ) {
			$gallery[] = $photos[ $slug ];
		}
	}
	if ( $first && ! empty( $photos[ $first ] ) ) {
		$new->set_image_id( $photos[ $first ] );
	}
	$new->set_gallery_image_ids( $gallery );
	$new->set_default_attributes( $first ? array( 'pa_color' => $first ) : array() );

	// 5. Words, categories and URL from the older copy.
	if ( $old ) {
		$new->set_name( $old->get_name() );
		$new->set_description( $old->get_description() );
		$new->set_short_description( $old->get_short_description() );
		$new->set_category_ids( $old->get_category_ids() );
		$new->set_tag_ids( $old->get_tag_ids() );
		$new->set_featured( $old->get_featured() );
		$new->set_menu_order( $old->get_menu_order() );
		$new->set_reviews_allowed( $old->get_reviews_allowed() );
		foreach ( get_post_meta( $old->get_id() ) as $key => $values ) {
			if ( 0 === strpos( $key, 'rank_math_' ) || 0 === strpos( $key, '_yoast_wpseo_' ) ) {
				update_post_meta( $new->get_id(), $key, maybe_unserialize( $values[0] ) );
			}
		}
		$slug = $old->get_slug();
		$old->set_status( 'draft' );
		$old->set_slug( $slug . '-old' );
		$old->save();
		$new->set_slug( $slug );
		$log[] = 'old product ' . $old->get_id() . ' is now a draft';
	}

	// 6. Orders for this product go through Qikink's own plugin, never our API sender.
	foreach ( array( '_sf_qikink_style', '_sf_qikink_design_code', '_sf_qikink_design_url', '_sf_qikink_design_w', '_sf_qikink_design_h' ) as $key ) {
		$new->delete_meta_data( $key );
	}
	$new->update_meta_data( '_sf_from_qikink', 'yes' );
	if ( $old ) {
		$new->update_meta_data( '_sf_replaces', $old->get_id() );
	}
	$new->save();
	WC_Product_Variable::sync( $new->get_id() );
	wc_delete_product_transients( $new->get_id() );

	return rest_ensure_response(
		array(
			'id'      => $new->get_id(),
			'url'     => get_permalink( $new->get_id() ),
			'colours' => array_keys( $colour_slugs ),
			'sizes'   => array_keys( $size_slugs ),
			'photos'  => count( $photos ),
			'log'     => $log,
		)
	);
}

/**
 * One-time tidy of the products adopted before the one-photo-per-colour rule (19 Oct 2026).
 */
add_action(
	'admin_init',
	function () {
		if ( '1' === get_option( 'store_features_qikink_tidy' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		update_option( 'store_features_qikink_tidy', '1', false );
		$ids = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'any',
				'numberposts' => 50,
				'fields'      => 'ids',
				'meta_key'    => '_sf_from_qikink', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $ids as $id ) {
			$request = new WP_REST_Request( 'POST' );
			$request->set_param( 'id', $id );
			store_features_qikink_photos_tidy( $request );
		}
	}
);
