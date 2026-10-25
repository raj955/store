<?php
/**
 * Simpler choices for shoppers: clear colour and size prompts, no "Choose an option" or "Clear",
 * a short "N colours" line instead of a wall of swatches on product cards, and small layout fixes.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product cards show swatches only up to this many colours; above it, "N colours".
 */
const STORE_FEATURES_MAX_CARD_SWATCHES = 6;

/**
 * No "Clear" link under the options: picking another colour or size is enough.
 *
 * @return string
 */
function store_features_no_reset_link() {
	return '';
}
add_filter( 'woocommerce_reset_variations_link', 'store_features_no_reset_link' );

/**
 * Plain placeholders in the (hidden) option menus, read by screen readers.
 *
 * @param array $args Dropdown arguments.
 * @return array
 */
function store_features_option_placeholders( $args ) {
	$names = array(
		'pa_color' => __( 'Select colour', 'store-features' ),
		'pa_size'  => __( 'Select size', 'store-features' ),
	);
	if ( isset( $args['attribute'], $names[ $args['attribute'] ] ) ) {
		$args['show_option_none'] = $names[ $args['attribute'] ];
	}
	return $args;
}
add_filter( 'woocommerce_dropdown_variation_attribute_options_args', 'store_features_option_placeholders' );

/**
 * "Colour" in British/Indian spelling on the product page label.
 *
 * @param string $label Attribute label.
 * @param string $name  Attribute name.
 * @return string
 */
function store_features_attribute_label( $label, $name = '' ) {
	if ( 'pa_color' === $name || 'Color' === $label ) {
		return __( 'Colour', 'store-features' );
	}
	return $label;
}
add_filter( 'woocommerce_attribute_label', 'store_features_attribute_label', 10, 2 );

/**
 * The "Details" tab already says what it is; no extra "Description" heading inside it.
 *
 * @return string
 */
function store_features_no_description_heading() {
	return '';
}
add_filter( 'woocommerce_product_description_heading', 'store_features_no_description_heading' );

/**
 * Number of colours a variable product comes in.
 *
 * @param WC_Product $product Product.
 * @return int
 */
function store_features_colour_count( $product ) {
	if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
		return 0;
	}
	$attributes = $product->get_variation_attributes();
	return isset( $attributes['pa_color'] ) ? count( (array) $attributes['pa_color'] ) : 0;
}

/**
 * Mark product cards with many colours, so their swatches are replaced by a short line.
 *
 * @param array      $classes Classes.
 * @param WC_Product $product Product.
 * @return array
 */
function store_features_card_class( $classes, $product ) {
	if ( ! is_product() && store_features_colour_count( $product ) > STORE_FEATURES_MAX_CARD_SWATCHES ) {
		$classes[] = 'sf-many-colours';
	}
	return $classes;
}
add_filter( 'woocommerce_post_class', 'store_features_card_class', 10, 2 );

/**
 * "17 colours" under the price on cards with many colours.
 */
function store_features_card_colour_count() {
	global $product;
	$count = store_features_colour_count( $product );
	if ( $count > STORE_FEATURES_MAX_CARD_SWATCHES ) {
		/* translators: %d: number of colours. */
		echo '<p class="sf-colour-count">' . esc_html( sprintf( _n( '%d colour', '%d colours', $count, 'store-features' ), $count ) ) . '</p>';
	}
}
add_action( 'woocommerce_after_shop_loop_item_title', 'store_features_card_colour_count', 15 );

/**
 * Styles for the pieces above.
 *
 * @return string
 */
function store_features_shop_ux_style() {
	return <<<'CSS'
li.product.sf-many-colours .cfvsw_variations_form { display: none; }
.sf-colour-count { margin: 4px 0 0; color: var(--muted, #565D7A); font-size: 14px; }
.single-product .reset_variations { display: none !important; }
.single-product table.variations tr.sf-needs-choice .cfvsw-swatches-container { border-radius: var(--radius, 4px); box-shadow: 0 0 0 2px var(--pw-accent, #A96546), 0 0 0 5px var(--pw-accent-soft, #F3E9E1); }
.sf-choice-msg { margin: 8px 0 0; color: #A3122F; font-size: 14px; font-weight: 600; }
.wc-block-components-address-card__edit { flex: 0 0 auto; margin-left: 12px; white-space: nowrap; }
.woocommerce-tabs .woocommerce-Tabs-panel--description > h2:first-child:empty { display: none; }
CSS;
}

/**
 * Product page: when Add to cart is pressed before a size (or colour) is picked, say so next to
 * the option instead of the browser's pop-up.
 *
 * @return string
 */
function store_features_shop_ux_script() {
	return <<<'JS'
(function () {
	'use strict';
	function labelFor(row) {
		var label = row.querySelector('th label, .label label');
		var text = label ? label.childNodes[0].textContent : '';
		return text.replace(/[:\s]+$/, '').trim().toLowerCase() || 'option';
	}
	function clear(form) {
		form.querySelectorAll('tr.sf-needs-choice').forEach(function (row) {
			row.classList.remove('sf-needs-choice');
		});
		form.querySelectorAll('.sf-choice-msg').forEach(function (msg) {
			msg.remove();
		});
	}
	document.addEventListener('click', function (e) {
		var btn = e.target.closest && e.target.closest('.single_add_to_cart_button');
		if (!btn || !btn.classList.contains('disabled')) {
			return;
		}
		var form = btn.closest('form.variations_form');
		if (!form) {
			return;
		}
		var missing = null;
		form.querySelectorAll('table.variations tr').forEach(function (row) {
			var select = row.querySelector('select');
			if (!missing && select && !select.value) {
				missing = row;
			}
		});
		if (!missing) {
			return; /* Let WooCommerce explain (for example, out of stock). */
		}
		e.preventDefault();
		e.stopImmediatePropagation();
		clear(form);
		missing.classList.add('sf-needs-choice');
		var msg = document.createElement('p');
		msg.className = 'sf-choice-msg';
		msg.setAttribute('role', 'alert');
		msg.textContent = 'Please choose your ' + labelFor(missing) + '.';
		(missing.querySelector('td.value') || missing).appendChild(msg);
		missing.scrollIntoView({ behavior: 'smooth', block: 'center' });
	}, true);
	/* Swatches set the hidden menus through jQuery, so listen there. */
	if (window.jQuery) {
		window.jQuery(document).on('change', 'form.variations_form select', function () {
			var row = this.closest('tr');
			if (this.value && row && row.classList.contains('sf-needs-choice')) {
				clear(this.closest('form'));
			}
		});
	}
})();
JS;
}

/**
 * Load the styles everywhere on the front end and the script on product pages.
 */
function store_features_shop_ux_assets() {
	if ( is_admin() ) {
		return;
	}
	wp_add_inline_style( 'store-features', store_features_shop_ux_style() );
	if ( is_product() ) {
		wp_add_inline_script( 'store-features', store_features_shop_ux_script() );
	}
	if ( is_shop() || is_product_category() ) {
		// Bring the current category chip into view in the sideways-scrolling row on phones.
		wp_add_inline_script( 'store-features', "(function(){var r=document.querySelector('.chip-row'),a=r&&r.querySelector('[aria-current]');if(a&&r.scrollWidth>r.clientWidth){r.scrollLeft=a.getBoundingClientRect().left-r.getBoundingClientRect().left+r.scrollLeft-(r.clientWidth-a.offsetWidth)/2;}})();" );
	}
}
add_action( 'wp_enqueue_scripts', 'store_features_shop_ux_assets', 31 );

/**
 * Category chips above the products on the shop and category pages, built from the top-level
 * product categories so new categories (Men, Women, Kids…) appear on their own.
 */
function store_features_category_chips() {
	if ( ! ( is_shop() || is_product_category() ) || is_product_category( 'design-your-own' ) ) {
		return;
	}
	$own     = get_term_by( 'slug', 'design-your-own', 'product_cat' );
	$current = is_product_category() ? (int) get_queried_object_id() : 0;
	$top     = $current ? array_merge( array( $current ), get_ancestors( $current, 'product_cat' ) ) : array();

	// Inside Men or Women: show that gender's own groups (T-shirts, Hoodies...).
	$gender = null;
	foreach ( array( 'men', 'women' ) as $slug ) {
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $term && in_array( (int) $term->term_id, $top, true ) ) {
			$gender = $term;
		}
	}
	if ( $gender ) {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => $gender->term_id, 'hide_empty' => true, 'orderby' => 'name' ) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return;
		}
		echo '<nav class="chip-row" aria-label="' . esc_attr( $gender->name ) . '">';
		printf( '<a href="%s"%s>%s</a>', esc_url( get_term_link( $gender ) ), (int) $gender->term_id === $current ? ' aria-current="page"' : '', esc_html( sprintf( /* translators: %s: Men or Women */ __( 'All %s', 'store-features' ), $gender->name ) ) );
		foreach ( $terms as $term ) {
			printf( '<a href="%s"%s>%s</a>', esc_url( get_term_link( $term ) ), in_array( (int) $term->term_id, $top, true ) ? ' aria-current="page"' : '', esc_html( $term->name ) );
		}
		echo '</nav>';
		return;
	}

	// Elsewhere: Men and Women first, then the rest. The old type categories now live inside Men and Women.
	$hide  = array();
	foreach ( array( 't-shirts', 'oversized-t-shirts', 'sweatshirts', 'hoodies' ) as $slug ) {
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $term && get_term_by( 'slug', 'men', 'product_cat' ) ) {
			$hide[] = (int) $term->term_id;
		}
	}
	$terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'parent'     => 0,
			'hide_empty' => true,
			'orderby'    => 'menu_order',
			'order'      => 'ASC',
			'exclude'    => array_filter( array_merge( array( (int) get_option( 'default_product_cat' ), $own ? (int) $own->term_id : 0 ), $hide ) ),
		)
	);
	if ( is_wp_error( $terms ) || count( $terms ) < 2 ) {
		return;
	}
	$rank  = array( 'men' => 0, 'women' => 1 );
	usort( $terms, function ( $a, $b ) use ( $rank ) {
		return ( $rank[ $a->slug ] ?? 9 ) <=> ( $rank[ $b->slug ] ?? 9 );
	} );

	echo '<nav class="chip-row" aria-label="' . esc_attr__( 'Product categories', 'store-features' ) . '">';
	printf( '<a href="%s"%s>%s</a>', esc_url( wc_get_page_permalink( 'shop' ) ), $current ? '' : ' aria-current="page"', esc_html__( 'All', 'store-features' ) );
	foreach ( $terms as $term ) {
		printf(
			'<a href="%s"%s>%s</a>',
			esc_url( get_term_link( $term ) ),
			in_array( (int) $term->term_id, $top, true ) ? ' aria-current="page"' : '',
			esc_html( $term->name )
		);
	}
	echo '</nav>';
}
add_action( 'woocommerce_archive_description', 'store_features_category_chips', 20 );
