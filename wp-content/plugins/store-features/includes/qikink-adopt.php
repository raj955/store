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
 *  - keeps all distinct Qikink variation photos grouped by colour in the gallery;
 *  - turns the older product into a draft (nothing is deleted).
 *
 * Admin only: POST /wp-json/store-features/v1/adopt-qikink { new_id, old_id, colour }.
 * Brand-new products: POST /wp-json/store-features/v1/finish-qikink-new (see below).
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

	// 2. Map variations and retain every distinct Qikink image per colour (not just one).
	$colour_slugs = array();
	$size_slugs   = array();
	$photos       = array();
	$photos_by_colour = array();
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
		$image_id = (int) $variation->get_image_id();
		if ( $image_id ) {
			if ( empty( $photos[ $cs ] ) ) {
				$photos[ $cs ] = $image_id;
			}
			if ( ! isset( $photos_by_colour[ $cs ] ) ) {
				$photos_by_colour[ $cs ] = array();
			}
			// Qikink may create duplicate attachments for sizes; keep each attachment only once.
			if ( ! in_array( $image_id, $photos_by_colour[ $cs ], true ) ) {
				$photos_by_colour[ $cs ][] = $image_id;
			}
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

	// 4. Photos: use variation images AND Qikink's product-level gallery.
	// Qikink sometimes puts front/back mockups in the parent gallery only, not on each
	// variation. Previously those photos were silently dropped because only variation
	// thumbnail IDs were collected above.
	$order = $ordered( 'pa_color', $colour_slugs );
	$first = $want_colour && isset( $photos[ $want_colour ] ) ? $want_colour : (string) array_key_first( $photos );

	$parent_gallery_ids = array_values(
		array_unique(
			array_filter(
				array_map(
					'intval',
					array_merge( array( $new->get_image_id() ), $new->get_gallery_image_ids() )
				)
			)
		)
	);
	$known_photo_ids = array();
	foreach ( $photos_by_colour as $known_ids ) {
		$known_photo_ids = array_merge( $known_photo_ids, (array) $known_ids );
	}
	$known_photo_ids = array_values( array_unique( array_map( 'intval', $known_photo_ids ) ) );

	// Any parent-gallery photo not attached to a variation is an extra Qikink mockup.
	// Keep it with the default/first colour so it appears instead of disappearing. Do
	// not duplicate photos already assigned to a colour.
	if ( $first ) {
		if ( ! isset( $photos_by_colour[ $first ] ) ) {
			$photos_by_colour[ $first ] = array();
		}
		foreach ( $parent_gallery_ids as $parent_image_id ) {
			if ( ! in_array( $parent_image_id, $known_photo_ids, true )
				&& ! in_array( $parent_image_id, $photos_by_colour[ $first ], true ) ) {
				$photos_by_colour[ $first ][] = $parent_image_id;
			}
		}
		// Keep the main product image as the first image for the default colour.
		if ( $new->get_image_id() && ! in_array( (int) $new->get_image_id(), $photos_by_colour[ $first ], true ) ) {
			array_unshift( $photos_by_colour[ $first ], (int) $new->get_image_id() );
		}
	}
	$gallery = array();
	$ordered_colours = array();
	if ( $first ) {
		$ordered_colours[] = $first;
	}
	foreach ( $order as $term_id ) {
		$slug = get_term( $term_id, 'pa_color' )->slug;
		if ( $slug !== $first ) {
			$ordered_colours[] = $slug;
		}
	}
	foreach ( $ordered_colours as $slug ) {
		$ids = isset( $photos_by_colour[ $slug ] ) ? $photos_by_colour[ $slug ] : array();
		foreach ( $ids as $image_id ) {
			if ( $slug !== $first || ! isset( $photos[ $first ] ) || $image_id !== (int) $photos[ $first ] ) {
				$gallery[] = (int) $image_id;
			}
		}
	}
	if ( $first && ! empty( $photos[ $first ] ) ) {
		$new->set_image_id( $photos[ $first ] );
	}
	$new->set_gallery_image_ids( array_values( array_unique( $gallery ) ) );
	$new->update_meta_data( '_sf_qikink_colour_images', $photos_by_colour );
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
 * One-time tidy of the variation image assignment for previously adopted Qikink products.
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

/**
 * Finish brand-new products pushed from Qikink (no older copy to take words from).
 *
 * Admin only: POST /wp-json/store-features/v1/finish-qikink-new
 * {
 *   template_from: 1034,                       // product whose description is the template
 *   short_tail: "100% super-combed cotton…",    // fabric line after the design line
 *   category_ids: [17], tag_ids: [55], menu_order_start: 11, colour: "black",
 *   items: [ { name, slug, line } ]             // name = the name saved in Qikink
 * }
 * For each item: finds the pushed product by name, adopts it (store colours and sizes, all distinct
 * variation photos per colour), writes the description (template with this design's line first), short
 * description, category, tag, URL and order, publishes it and fills photo alt text.
 * Products still waiting for photos are skipped and reported, so the call can simply be repeated.
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'store-features/v1',
			'/finish-qikink-new',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'callback'            => 'store_features_finish_qikink_new',
			)
		);
	}
);

/**
 * The REST callback.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function store_features_finish_qikink_new( $request ) {
	$items = (array) $request['items'];
	if ( ! $items ) {
		return new WP_Error( 'sf_finish', 'No items.', array( 'status' => 400 ) );
	}
	$template = wc_get_product( absint( $request['template_from'] ) );
	$base     = $template ? $template->get_description() : '';
	// Everything after the first paragraph (fabric, size chart, printing, care) is shared.
	$shared   = preg_replace( '~^\s*<p>.*?</p>\s*~s', '', $base, 1 );
	if ( ! empty( $request['shared_html'] ) ) {
		$shared = wp_kses_post( (string) $request['shared_html'] ); // A garment with no template yet.
	}
	$tail     = trim( (string) $request['short_tail'] );
	$cats     = array_map( 'absint', (array) $request['category_ids'] );
	$tags     = array_map( 'absint', (array) $request['tag_ids'] );
	$order    = (int) $request['menu_order_start'];
	$colour   = sanitize_title( (string) ( $request['colour'] ? $request['colour'] : 'black' ) );
	$out      = array();

	foreach ( $items as $i => $item ) {
		$name  = sanitize_text_field( $item['name'] ?? '' );
		$line  = trim( wp_strip_all_tags( (string) ( $item['line'] ?? '' ) ) );
		$found = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
				'title'       => $name,
				'numberposts' => 5,
				'orderby'     => 'ID',
				'order'       => 'DESC',
			)
		);
		if ( ! $found ) {
			$out[] = array( $name, 'not in the store yet (push it from Qikink)' );
			continue;
		}
		$product = wc_get_product( $found[0]->ID );
		$done    = 'yes' === $product->get_meta( '_sf_from_qikink' );

		if ( ! $done ) {
			// Wait until Qikink has sent the photos (they arrive after the product itself).
			$with_photo = 0;
			foreach ( $product->get_children() as $child_id ) {
				if ( get_post_thumbnail_id( $child_id ) ) {
					++$with_photo;
				}
			}
			if ( $with_photo < count( $product->get_children() ) ) {
				$out[] = array( $name, sprintf( 'photos still arriving (%d of %d)', $with_photo, count( $product->get_children() ) ) );
				continue;
			}
			$adopt = new WP_REST_Request( 'POST' );
			$adopt->set_param( 'new_id', $product->get_id() );
			$adopt->set_param( 'old_id', 0 );
			$adopt->set_param( 'colour', $colour );
			$result = store_features_adopt_qikink_product( $adopt );
			if ( is_wp_error( $result ) ) {
				$out[] = array( $name, 'adopt failed: ' . $result->get_error_message() );
				continue;
			}
			$product = wc_get_product( $product->get_id() );
		}

		$product->set_description( '<p>' . esc_html( $line ) . '</p>' . "\n" . $shared );
		$product->set_short_description( esc_html( $line ) . ( $tail ? ' ' . esc_html( $tail ) : '' ) );
		if ( $cats ) {
			$product->set_category_ids( $cats );
		}
		if ( $tags ) {
			$product->set_tag_ids( $tags );
		}
		if ( ! empty( $item['slug'] ) ) {
			$product->set_slug( sanitize_title( $item['slug'] ) );
		}
		$product->set_menu_order( $order + $i );
		$product->set_status( 'publish' );
		$product->save();
		wc_delete_product_transients( $product->get_id() );
		$out[] = array( $name, 'live', $product->get_id(), get_permalink( $product->get_id() ) );
	}

	$alt = function_exists( 'store_features_seo_fill_alt' ) ? store_features_seo_fill_alt() : null;
	return rest_ensure_response(
		array(
			'items' => $out,
			'alt'   => $alt,
		)
	);
}
