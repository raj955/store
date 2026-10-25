<?php
/**
 * Product cards (shop, categories, home): clicking a colour dot shows that colour's photo.
 *
 * The swatch plugin only swaps the card photo once a full variation (colour and size) is picked,
 * so on a card the photo never changed. Each card now carries its colour => photo list, and a
 * small script swaps the photo as soon as a colour is clicked.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * The colour attribute of a variable product, whatever it is called (pa_color, pa_colour, Color...).
 *
 * @param WC_Product $product Variable product.
 * @return string Attribute key as WooCommerce lists it, or ''.
 */
function store_features_colour_attr_key( $product ) {
	if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
		return '';
	}
	foreach ( array_keys( $product->get_variation_attributes() ) as $key ) {
		if ( preg_match( '/colou?r/i', (string) $key ) ) {
			return (string) $key;
		}
	}
	return '';
}

/**
 * Dot colour for a colour slug. Known names map to a colour; two-tone names such as
 * "white-black" or "green-black" become a half-and-half dot. Unknown names stay light grey.
 *
 * @param string $slug Colour slug.
 * @return string CSS colour or gradient.
 */
function store_features_colour_css( $slug ) {
	$known = array(
		'black' => '#171717', 'white' => '#FFFFFF', 'off-white' => '#F4F0E8', 'navy' => '#202A44',
		'maroon' => '#641F32', 'red' => '#C62828', 'pink' => '#E7A9B8', 'light-pink' => '#F6CFDC',
		'lavender' => '#C9B8E8', 'grey' => '#A8A8A8', 'gray' => '#A8A8A8', 'olive' => '#5A5F38',
		'blue' => '#2B4BA8', 'royal-blue' => '#2B4BA8', 'beige' => '#E3C79A', 'mustard' => '#D29A2A',
		'green' => '#38805A', 'bottle-green' => '#0F3D23', 'mint' => '#BEE8D6', 'baby-blue' => '#BCD9F0',
		'purple' => '#6B3D83', 'brown' => '#795548', 'charcoal' => '#4A4A4C', 'grey-melange' => '#BDBDBD',
		'yellow' => '#F2C230', 'orange' => '#EF7D22', 'coral' => '#E8736C', 'cream' => '#F2E6C9', 'sky-blue' => '#8EC9EE',
	);
	$slug = sanitize_title( $slug );
	if ( isset( $known[ $slug ] ) ) {
		return $known[ $slug ];
	}
	$tones = array();
	$rest  = $slug;
	while ( '' !== $rest ) {
		$hit = '';
		foreach ( array_keys( $known ) as $name ) {
			if ( ( $rest === $name || 0 === strpos( $rest, $name . '-' ) ) && strlen( $name ) > strlen( $hit ) ) {
				$hit = $name;
			}
		}
		if ( '' === $hit ) {
			$hit = (string) strtok( $rest, '-' );
		}
		$tones[] = isset( $known[ $hit ] ) ? $known[ $hit ] : null;
		$rest    = ltrim( substr( $rest, strlen( $hit ) ), '-' );
	}
	$tones = array_values( array_filter( $tones ) );
	if ( count( $tones ) >= 2 ) {
		return sprintf( 'linear-gradient(135deg,%1$s 50%%,%2$s 50%%)', $tones[0], $tones[1] );
	}
	return $tones ? $tones[0] : '#D8D8D8';
}

/**
 * Colour options of a product: slug => array( label, hex ), read from its variations.
 *
 * @param WC_Product $product Product.
 * @return array
 */
function store_features_card_colour_options( $product ) {
	$options = array();
	$key     = store_features_colour_attr_key( $product );
	if ( '' === $key ) {
		return $options;
	}
	$meta = 'attribute_' . sanitize_title( $key );
	foreach ( $product->get_children() as $id ) {
		$value = (string) get_post_meta( $id, $meta, true );
		if ( '' === $value ) {
			continue;
		}
		$colour = sanitize_title( $value );
		if ( isset( $options[ $colour ] ) ) {
			continue;
		}
		$term  = taxonomy_exists( $key ) ? get_term_by( 'slug', $value, $key ) : false;
		$label = $term && ! is_wp_error( $term ) && '' !== trim( $term->name ) ? trim( $term->name ) : ucwords( str_replace( '-', ' ', $colour ) );
		$hex   = $term && ! is_wp_error( $term ) ? (string) get_term_meta( $term->term_id, 'cfvsw_color', true ) : '';
		if ( ! preg_match( '/^#[0-9a-f]{3,8}$/i', $hex ) ) {
			$hex = store_features_colour_css( $colour );
		}
		$options[ $colour ] = array( 'label' => $label, 'hex' => $hex, 'value' => $value );
	}
	return $options;
}

/**
 * Colour slug => card-size photo for one variable product.
 *
 * @param WC_Product $product Product.
 * @return array
 */
function store_features_card_colour_photos( $product ) {
	$ids = $product->get_children();
	$key = store_features_colour_attr_key( $product );
	if ( ! $ids || '' === $key ) {
		return array();
	}
	update_meta_cache( 'post', $ids );
	$photos = array();
	foreach ( $ids as $id ) {
		$colour = sanitize_title( (string) get_post_meta( $id, 'attribute_' . sanitize_title( $key ), true ) );
		$image  = (int) get_post_meta( $id, '_thumbnail_id', true );
		if ( '' === $colour || ! $image || isset( $photos[ $colour ] ) ) {
			continue;
		}
		$src = wp_get_attachment_image_src( $image, 'woocommerce_thumbnail' );
		if ( $src ) {
			$photos[ $colour ] = array(
				's'  => $src[0],
				'ss' => (string) wp_get_attachment_image_srcset( $image, 'woocommerce_thumbnail' ),
			);
		}
	}
	return count( $photos ) > 1 ? $photos : array();
}

/**
 * Colour dots under the price on every product card (shop, categories, home). They are drawn from
 * the product's own colours, so they always show, whatever the swatch plugin does.
 */
function store_features_card_colour_fallback() {
	global $product;
	if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
		return;
	}
	$options = store_features_card_colour_options( $product );
	if ( count( $options ) < 2 ) {
		return;
	}
	echo '<div class="sf-card-colour-fallback" role="group" aria-label="' . esc_attr__( 'Available colours', 'store-features' ) . '">';
	$shown = 0;
	foreach ( $options as $slug => $option ) {
		if ( ++$shown > 7 ) {
			printf( '<span class="sf-card-colour-more">+%d</span>', count( $options ) - 7 );
			break;
		}
		printf(
			'<a class="sf-card-colour-fallback__dot" href="%1$s" data-slug="%2$s" title="%3$s" aria-label="%3$s" style="--sf-colour:%4$s"></a>',
			esc_url( add_query_arg( 'attribute_' . sanitize_title( store_features_colour_attr_key( $product ) ), $option['value'], $product->get_permalink() ) ),
			esc_attr( $slug ),
			esc_attr( $option['label'] ),
			esc_attr( $option['hex'] )
		);
	}
	echo '</div>';
}
add_action( 'woocommerce_after_shop_loop_item_title', 'store_features_card_colour_fallback', 15 );

/**
 * Print the colour => photo list inside each card.
 */
function store_features_card_colour_data() {
	global $product;
	if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
		return;
	}
	$photos = store_features_card_colour_photos( $product );
	if ( ! $photos ) {
		return;
	}
	$GLOBALS['store_features_card_colours'] = true;
	echo '<script type="application/json" class="sf-card-colours">' . wp_json_encode( $photos ) . '</script>';
}
add_action( 'woocommerce_after_shop_loop_item', 'store_features_card_colour_data', 1 );

/**
 * Clicking a colour dot on a card shows that colour's photo (the link still works without script).
 */
function store_features_card_colour_script() {
	if ( empty( $GLOBALS['store_features_card_colours'] ) ) {
		return;
	}
	?>
<script>
(function(){
	function swap(dot){
		var card=dot.closest('li.product, .product, .wc-block-grid__product');
		if(!card){return;}
		card.querySelectorAll('.sf-card-colour-fallback__dot').forEach(function(d){d.classList.toggle('is-active',d===dot);});
		var data=card.querySelector('script.sf-card-colours');
		if(!data){return;}
		var map; try{map=JSON.parse(data.textContent);}catch(e){return;}
		var p=map[dot.getAttribute('data-slug')];
		var img=card.querySelector('img.attachment-woocommerce_thumbnail, img.wp-post-image, a img');
		if(!p||!img){return;}
		img.removeAttribute('loading');
		img.setAttribute('src',p.s);
		if(p.ss){img.setAttribute('srcset',p.ss);}else{img.removeAttribute('srcset');}
	}
	document.addEventListener('click',function(e){
		var d=e.target.closest && e.target.closest('.sf-card-colour-fallback__dot');
		if(d){e.preventDefault();swap(d);}
	},true);
})();
</script>
	<?php
}
add_action( 'wp_footer', 'store_features_card_colour_script', 50 );

/**
 * Product page: the colour choices become real colour dots (the name shows on hover and
 * beside the label), in the same colours as on the cards.
 */
function store_features_product_colour_dots() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	$product = wc_get_product( get_queried_object_id() );
	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		return;
	}
	$map = array();
	foreach ( store_features_card_colour_options( $product ) as $slug => $option ) {
		$map[ $slug ] = array( 'c' => $option['hex'], 'n' => $option['label'] );
	}
	if ( count( $map ) < 2 ) {
		return;
	}
	wp_add_inline_style(
		'store-features',
		'.pw2.single-product table.variations .cfvsw-swatches-option.sf-dot,.pw2.single-product table.variations .cfvsw-swatches-option.sf-dot.cfvsw-label-option,.pw2.single-product table.variations .cfvsw-swatches-option.sf-dot.cfvsw-label-option.cfvsw-selected-swatch{width:40px!important;height:40px!important;min-width:40px!important;min-height:40px!important;padding:0!important;border-radius:50%!important;background:var(--sf-colour,#D8D8D8)!important;border:1px solid rgba(23,23,23,.28)!important;box-shadow:inset 0 0 0 3px #fff!important;font-size:0!important;color:transparent!important;cursor:pointer}'
		. '.pw2.single-product table.variations .cfvsw-swatches-option.sf-dot .cfvsw-swatch-inner{display:none!important}'
		. '.pw2.single-product table.variations .cfvsw-swatches-option.sf-dot.cfvsw-selected-swatch,.pw2.single-product table.variations .cfvsw-swatches-option.sf-dot.cfvsw-label-option.cfvsw-selected-swatch{border-color:#171717!important;box-shadow:inset 0 0 0 3px #fff,0 0 0 2px #171717!important}'
		. '.pw2.single-product table.variations .cfvsw-swatches-container:has(.sf-dot){gap:12px!important;padding:4px 2px}'
	);
	wp_add_inline_script(
		'store-features',
		'(function(){var M=' . wp_json_encode( $map ) . ';'
		. 'function slug(t){return String(t||"").toLowerCase().trim().replace(/[^a-z0-9]+/g,"-").replace(/^-+|-+$/g,"");}'
		. 'function run(){document.querySelectorAll(".single-product form.variations_form .cfvsw-swatches-option").forEach(function(o){'
		. 'var s=o.getAttribute("data-slug")||slug(o.getAttribute("title")||o.getAttribute("aria-label")||o.textContent);var m=M[s];if(!m){return;}'
		. 'o.classList.add("sf-dot");o.style.setProperty("--sf-colour",m.c);o.setAttribute("title",m.n);o.setAttribute("aria-label",m.n);});}'
		. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",run);}else{run();}'
		. 'setTimeout(run,500);if(window.jQuery){jQuery(document).on("woocommerce_variation_has_changed reset_data",function(){setTimeout(run,50);});}'
		. '})();'
	);
}
add_action( 'wp_enqueue_scripts', 'store_features_product_colour_dots', 36 );
