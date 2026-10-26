<?php
/**
 * Product feed for Google Merchant Center (free Google Shopping listings) and Meta Commerce
 * Manager (Instagram / Facebook Shop).
 *
 * URL: https://<site>/product-feed.xml (also /?sf_feed=1). One item per colour and size, grouped
 * by product, with the colour's photo, price, sizes and free shipping. It is rebuilt from the
 * live products every time it is read (cached for an hour), so new products, price changes and
 * stock changes reach Google and Instagram on their own.
 *
 * "Design your own" products are left out: they need the designer on the site.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	function () {
		add_rewrite_rule( '^product-feed\.xml$', 'index.php?sf_feed=1', 'top' );
		if ( get_option( 'store_features_feed_rules' ) !== '1' ) {
			flush_rewrite_rules( false );
			update_option( 'store_features_feed_rules', '1', false );
		}
	}
);
add_filter(
	'query_vars',
	function ( $vars ) {
		$vars[] = 'sf_feed';
		return $vars;
	}
);
add_action(
	'template_redirect',
	function () {
		if ( ! get_query_var( 'sf_feed' ) ) {
			return;
		}
		$xml = get_transient( 'store_features_feed_xml' );
		if ( ! is_string( $xml ) || '' === $xml ) {
			$xml = store_features_feed_xml();
			set_transient( 'store_features_feed_xml', $xml, HOUR_IN_SECONDS );
		}
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	},
	0
);
// A product change shows in the feed at once.
foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_update_product_variation', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ) as $sf_feed_hook ) {
	add_action(
		$sf_feed_hook,
		function () {
			delete_transient( 'store_features_feed_xml' );
		}
	);
}

/**
 * Value of a variation attribute whose name contains one of the words (colour / size).
 *
 * @param WC_Product_Variation $variation Variation.
 * @param string[]             $words     Words in the attribute name.
 * @return string Display name ('' if none).
 */
function store_features_feed_attr( $variation, $words ) {
	foreach ( $variation->get_variation_attributes( false ) as $name => $value ) {
		foreach ( $words as $word ) {
			if ( false === stripos( $name, $word ) || '' === (string) $value ) {
				continue;
			}
			if ( taxonomy_exists( $name ) ) {
				$term = get_term_by( 'slug', $value, $name );
				return $term ? $term->name : (string) $value;
			}
			return ucwords( str_replace( '-', ' ', (string) $value ) );
		}
	}
	return '';
}

/**
 * The feed.
 *
 * @return string XML.
 */
function store_features_feed_xml() {
	$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$items = array();
	$women = get_term_by( 'slug', 'women', 'product_cat' );

	foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'visibility' => 'visible' ) ) as $product ) {
		if ( function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product ) ) {
			continue;
		}
		$cats      = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'slugs' ) );
		$cats      = is_wp_error( $cats ) ? array() : $cats;
		$is_mug    = in_array( 'mugs', $cats, true );
		$is_bag    = in_array( 'tote-bags', $cats, true );
		$wearable  = ! $is_mug && ! $is_bag;
		$is_women  = $women && in_array( 'women', $cats, true ) && ! in_array( 'men', $cats, true );
		$type_path = array();
		foreach ( wp_get_post_terms( $product->get_id(), 'product_cat' ) as $term ) {
			if ( ! is_wp_error( $term ) ) {
				$type_path[] = $term->name;
			}
		}
		$desc = trim( wp_strip_all_tags( $product->get_short_description() ? $product->get_short_description() : $product->get_description() ) );
		$desc = mb_substr( preg_replace( '/\s+/', ' ', $desc ), 0, 4900 );
		if ( '' === $desc ) {
			$desc = $product->get_name();
		}
		$gallery = array();
		foreach ( array_slice( $product->get_gallery_image_ids(), 0, 8 ) as $gid ) {
			$url = wp_get_attachment_image_url( $gid, 'full' );
			if ( $url ) {
				$gallery[] = $url;
			}
		}
		$base = array(
			'brand'       => $site,
			'condition'   => 'new',
			'description' => $desc,
			'type'        => implode( ' > ', array_slice( $type_path, 0, 3 ) ),
			'category'    => $is_mug ? '2169' : ( $is_bag ? '' : '212' ),
			'gallery'     => $gallery,
			'wearable'    => $wearable,
			'gender'      => $is_women ? 'female' : 'unisex',
		);

		$variations = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
		foreach ( $variations as $v ) {
			if ( ! $v || ! $v->is_purchasable() || '' === (string) $v->get_price() ) {
				continue;
			}
			$colour = $v->is_type( 'variation' ) ? store_features_feed_attr( $v, array( 'color', 'colour' ) ) : '';
			$size   = $v->is_type( 'variation' ) ? store_features_feed_attr( $v, array( 'size' ) ) : '';
			$image  = wp_get_attachment_image_url( $v->get_image_id() ? $v->get_image_id() : $product->get_image_id(), 'full' );
			if ( ! $image ) {
				continue;
			}
			$link = $v->is_type( 'variation' ) ? $v->get_permalink() : get_permalink( $product->get_id() );
			$items[] = $base + array(
				'id'      => 'pw-' . $v->get_id(),
				'group'   => 'pw-' . $product->get_id(),
				'title'   => trim( $product->get_name() . ( $colour ? ' - ' . $colour : '' ) . ( $size ? ' - ' . $size : '' ) ),
				'link'    => $link,
				'image'   => $image,
				'price'   => wc_format_decimal( $v->get_regular_price() ? $v->get_regular_price() : $v->get_price(), 2 ),
				'sale'    => $v->is_on_sale() ? wc_format_decimal( $v->get_sale_price(), 2 ) : '',
				'stock'   => $v->is_in_stock() ? 'in_stock' : 'out_of_stock',
				'colour'  => $colour,
				'size'    => $size,
			);
		}
	}

	$e   = function ( $s ) {
		return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	};
	$out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
		. '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>'
		. '<title>' . $e( $site ) . '</title><link>' . $e( home_url( '/' ) ) . '</link><description>' . $e( $site . ' products' ) . '</description>' . "\n";
	foreach ( $items as $it ) {
		$out .= '<item>'
			. '<g:id>' . $e( $it['id'] ) . '</g:id>'
			. '<g:item_group_id>' . $e( $it['group'] ) . '</g:item_group_id>'
			. '<g:title>' . $e( mb_substr( $it['title'], 0, 150 ) ) . '</g:title>'
			. '<g:description>' . $e( $it['description'] ) . '</g:description>'
			. '<g:link>' . $e( $it['link'] ) . '</g:link>'
			. '<g:image_link>' . $e( $it['image'] ) . '</g:image_link>';
		foreach ( array_diff( $it['gallery'], array( $it['image'] ) ) as $extra ) {
			$out .= '<g:additional_image_link>' . $e( $extra ) . '</g:additional_image_link>';
		}
		$out .= '<g:availability>' . $it['stock'] . '</g:availability>'
			. '<g:price>' . $e( $it['price'] ) . ' INR</g:price>'
			. ( $it['sale'] ? '<g:sale_price>' . $e( $it['sale'] ) . ' INR</g:sale_price>' : '' )
			. '<g:brand>' . $e( $it['brand'] ) . '</g:brand>'
			. '<g:condition>new</g:condition>'
			. '<g:identifier_exists>no</g:identifier_exists>'
			. ( $it['category'] ? '<g:google_product_category>' . $it['category'] . '</g:google_product_category>' : '' )
			. ( $it['type'] ? '<g:product_type>' . $e( $it['type'] ) . '</g:product_type>' : '' )
			. ( $it['colour'] ? '<g:color>' . $e( $it['colour'] ) . '</g:color>' : '' )
			. ( $it['size'] ? '<g:size>' . $e( $it['size'] ) . '</g:size><g:size_system>IN</g:size_system>' : '' )
			. ( $it['wearable'] ? '<g:gender>' . $it['gender'] . '</g:gender><g:age_group>adult</g:age_group>' : '' )
			. '<g:shipping><g:country>IN</g:country><g:price>0 INR</g:price></g:shipping>'
			. '</item>' . "\n";
	}
	return $out . '</channel></rss>' . "\n";
}
