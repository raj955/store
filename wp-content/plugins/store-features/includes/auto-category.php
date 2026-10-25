<?php
/**
 * Men / Women categories that fill themselves.
 *
 * Every product that comes from Qikink (or is added by hand) is put in the right place on its own:
 *
 *   Men    > T-shirts, Oversized T-shirts, Hoodies, ...
 *   Women  > T-shirts, Crop Tops, Hoodies, ...
 *   Mugs, Tote bags (stay on their own, outside Men and Women)
 *
 * The gender and the kind of product are read from the product name ("Women's Crop Top",
 * "Boy's Crew Neck T-Shirt", "Unisex Sweatpants"). Unisex or no gender word = both Men and Women.
 * A kind we do not know yet (say "Cap") gets a new category made for it, named after the
 * category Qikink gave it, or after the name. The product keeps its old category as well, so
 * prices and the older pages work as before.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

const STORE_FEATURES_AUTOCAT_VERSION = 2;

/**
 * Kinds of product, in the order they are tried: slug => array( label, pattern, old category or '' ).
 * Mugs and totes have no gender.
 *
 * @return array
 */
function store_features_autocat_types() {
	return array(
		'mugs'               => array( 'Mugs', '/\bmugs?\b/', '', false ),
		'tote-bags'          => array( 'Tote bags', '/\btote\b|\bbags?\b/', '', false ),
		'crop-tops'          => array( 'Crop Tops', '/\bcrop\b/', '', true ),
		'hoodies'            => array( 'Hoodies', '/hood/', 'hoodies', true ),
		'sweatshirts'        => array( 'Sweatshirts', '/sweat\s*shirt/', 'sweatshirts', true ),
		'oversized-t-shirts' => array( 'Oversized T-shirts', '/oversized/', 'oversized-t-shirts', true ),
		'sweatpants'         => array( 'Sweatpants', '/sweat\s*pants?|joggers?|track\s*pants?|trousers?|\bpants?\b/', '', true ),
		'shorts'             => array( 'Shorts', '/\bshorts?\b/', '', true ),
		'polos'              => array( 'Polos', '/\bpolo/', '', true ),
		'tank-tops'          => array( 'Tank Tops', '/\btank|\bvest\b|sleeveless/', '', true ),
		't-shirts'           => array( 'T-shirts', '/\btees?\b|t-?\s?shirts?|tshirts?/', 't-shirts', true ),
	);
}

/**
 * "men", "women" or "both" from the product name.
 *
 * @param string $name Product name.
 * @return string[]
 */
function store_features_autocat_genders( $name ) {
	$n     = strtolower( str_replace( array( "'", '’', '`' ), '', $name ) );
	$women = (bool) preg_match( '/\b(women|womens|woman|ladies|lady|girls?|female|crop)\b/', $n );
	$men   = (bool) preg_match( '/\b(men|mens|man|boys?|male)\b/', $n );
	if ( $women && ! $men ) {
		return array( 'women' );
	}
	if ( $men && ! $women ) {
		return array( 'men' );
	}
	return array( 'men', 'women' ); // Unisex, or no gender word.
}

/**
 * A kind of product we have no rule for, read from the name: the last meaningful word.
 * "AOP Female Kaftan" gives Kaftan, "Unisex Baseball Cap" gives Cap.
 *
 * @param string $name Product name.
 * @return array|null slug, label
 */
function store_features_autocat_name_type( $name ) {
	$stop  = array( 'unisex', 'men', 'mens', 'women', 'womens', 'woman', 'man', 'female', 'male', 'boy', 'boys', 'girl', 'girls', 'ladies', 'kids', 'aop', 'all', 'over', 'print', 'printed', 'oversized', 'fit', 'regular', 'classic', 'premium', 'the', 'and', 'for', 'with', 'of', 'new', 'set' );
	$words = preg_split( '/[^a-z]+/', strtolower( str_replace( array( "'", '’' ), '', $name ) ), -1, PREG_SPLIT_NO_EMPTY );
	$words = array_values( array_diff( $words, $stop ) );
	$last  = $words ? end( $words ) : '';
	if ( strlen( $last ) < 3 ) {
		return null;
	}
	return array( sanitize_title( $last ), ucfirst( $last ) );
}

/**
 * Kind of product for a name: slug and label, or null. Used by the home page price list.
 *
 * @param string $name Product name.
 * @return array|null
 */
function store_features_autocat_type_of( $name ) {
	$lower = strtolower( str_replace( array( "'", '’' ), '', $name ) );
	foreach ( store_features_autocat_types() as $slug => $row ) {
		if ( preg_match( $row[1], $lower ) ) {
			return array( $slug, $row[0] );
		}
	}
	return store_features_autocat_name_type( $name );
}

/**
 * A category by slug; made (under a parent) when it does not exist.
 *
 * @param string $slug   Slug.
 * @param string $name   Name.
 * @param int    $parent Parent term ID.
 * @return int Term ID, 0 on failure.
 */
function store_features_autocat_term( $slug, $name, $parent = 0 ) {
	$term = get_term_by( 'slug', $slug, 'product_cat' );
	if ( $term && ! is_wp_error( $term ) ) {
		return (int) $term->term_id;
	}
	$made = wp_insert_term( $name, 'product_cat', array( 'slug' => $slug, 'parent' => $parent ) );
	return is_wp_error( $made ) ? 0 : (int) $made['term_id'];
}

/**
 * Put one product in its categories. Never removes a category.
 *
 * @param WC_Product $product Product.
 * @param bool       $force   Also redo products that already have a gender.
 * @return string[] What was done.
 */
function store_features_autocat_product( $product, $force = false ) {
	if ( ! $product instanceof WC_Product || '' === trim( $product->get_name() ) ) {
		return array();
	}
	if ( function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product ) ) {
		return array(); // "Design your own" has its own page.
	}
	$id    = $product->get_id();
	$name  = $product->get_name();
	$have  = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'slugs' ) );
	$have  = is_wp_error( $have ) ? array() : $have;
	$done  = array_intersect( $have, array( 'men', 'women' ) );
	$lower = strtolower( str_replace( array( "'", '’' ), '', $name ) );

	// Which kind of product?
	$type = '';
	$info = null;
	foreach ( store_features_autocat_types() as $slug => $row ) {
		if ( preg_match( $row[1], $lower ) ) {
			$type = $slug;
			$info = $row;
			break;
		}
	}
	if ( '' === $type ) {
		// Nothing known in the name: use a category it already has (Qikink's own), if any.
		foreach ( $have as $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( $term && 0 === (int) $term->parent && ! in_array( $slug, array( 'uncategorized', 'men', 'women', 'design-your-own' ), true ) ) {
				$type = $slug;
				$info = array( $term->name, '', $slug, true );
				break;
			}
		}
	}
	if ( '' === $type ) {
		$derived = store_features_autocat_name_type( $name );
		if ( $derived ) {
			$type = $derived[0];
			$info = array( $derived[1], '', '', true );
		}
	}
	if ( '' === $type ) {
		return array();
	}

	$ids = array();
	$log = array();

	// Mugs and totes: a top-level category of their own, no Men/Women.
	if ( false === $info[3] ) {
		if ( ! in_array( $type, $have, true ) ) {
			$ids[] = store_features_autocat_term( $type, $info[0] );
			$log[] = $info[0];
		}
		return store_features_autocat_apply( $id, $ids, $log );
	}

	if ( $done && ! $force ) {
		return array();
	}
	// A product already in the old "women" category is a women's product, whatever its name says.
	$genders = in_array( 'women', $have, true ) && ! in_array( 'men', $have, true ) ? array( 'women' ) : store_features_autocat_genders( $name );
	foreach ( $genders as $g ) {
		$parent = store_features_autocat_term( $g, 'men' === $g ? 'Men' : 'Women' );
		if ( ! $parent ) {
			continue;
		}
		$child = store_features_autocat_term( $g . '-' . $type, $info[0], $parent );
		$ids[] = $parent;
		if ( $child ) {
			$ids[] = $child;
		}
		$log[] = ( 'men' === $g ? 'Men' : 'Women' ) . ' > ' . $info[0];
	}
	// Keep the old type category (T-shirts, Hoodies...) when the shop already has it.
	if ( $info[2] && ! in_array( $info[2], $have, true ) ) {
		$old = get_term_by( 'slug', $info[2], 'product_cat' );
		if ( $old && ! is_wp_error( $old ) ) {
			$ids[] = (int) $old->term_id;
		}
	}
	return store_features_autocat_apply( $id, $ids, $log );
}

/**
 * Add the categories (keeping the ones the product has).
 *
 * @param int   $id  Product ID.
 * @param int[] $ids Term IDs.
 * @param array $log What is being done.
 * @return array
 */
function store_features_autocat_apply( $id, $ids, $log ) {
	$ids = array_values( array_filter( array_unique( array_map( 'intval', $ids ) ) ) );
	if ( $ids ) {
		wp_set_object_terms( $id, $ids, 'product_cat', true );
		update_post_meta( $id, '_sf_autocat', STORE_FEATURES_AUTOCAT_VERSION );
		clean_post_cache( $id );
		wc_delete_product_transients( $id );
	}
	return $log;
}

/**
 * Whenever a product is saved (a new one pushed from Qikink, or one edited by hand) that is
 * not yet in Men or Women, put it there.
 *
 * @param int $product_id Product ID.
 */
function store_features_autocat_on_save( $product_id ) {
	static $busy = false;
	if ( $busy ) {
		return;
	}
	$busy    = true;
	$product = wc_get_product( $product_id );
	if ( $product && 'auto-draft' !== get_post_status( $product_id ) ) {
		store_features_autocat_product( $product );
	}
	$busy = false;
}
add_action( 'woocommerce_new_product', 'store_features_autocat_on_save', 99 );
add_action( 'woocommerce_update_product', 'store_features_autocat_on_save', 99 );

/**
 * Once, for the products already in the shop (runs when a shop manager opens wp-admin).
 */
function store_features_autocat_backfill() {
	if ( (int) get_option( 'store_features_autocat_done', 0 ) >= STORE_FEATURES_AUTOCAT_VERSION || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$ids = get_posts(
		array(
			'post_type'   => 'product',
			'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);
	foreach ( $ids as $id ) {
		store_features_autocat_product( wc_get_product( $id ) );
	}
	update_option( 'store_features_autocat_done', STORE_FEATURES_AUTOCAT_VERSION, false );
	delete_transient( 'store_child_price_ladder_v2' );
}
add_action( 'admin_init', 'store_features_autocat_backfill', 40 );
