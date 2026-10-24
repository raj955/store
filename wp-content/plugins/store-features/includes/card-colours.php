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
 * Colour slug => card-size photo for one variable product.
 *
 * @param WC_Product $product Product.
 * @return array
 */
function store_features_card_colour_photos( $product ) {
	$ids = $product->get_children();
	if ( ! $ids ) {
		return array();
	}
	update_meta_cache( 'post', $ids );
	$photos = array();
	foreach ( $ids as $id ) {
		$colour = (string) get_post_meta( $id, 'attribute_pa_color', true );
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
 * Get the actual colour options from variation attributes, even when an imported
 * product has no distinct variation thumbnail (the old logic hid all swatches).
 */
function store_features_card_colour_options( $product ) {
	$options = array();
	if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
		return $options;
	}
	foreach ( $product->get_children() as $id ) {
		$colour = (string) get_post_meta( $id, 'attribute_pa_color', true );
		if ( '' === $colour ) {
			$variation = wc_get_product( $id );
			if ( $variation ) {
				$attrs = $variation->get_variation_attributes( false );
				$colour = (string) ( $attrs['pa_color'] ?? '' );
			}
		}
		if ( '' === $colour || isset( $options[ $colour ] ) ) {
			continue;
		}
		$term = get_term_by( 'slug', $colour, 'pa_color' );
		$label = $term && ! is_wp_error( $term ) ? trim( $term->name ) : ucwords( str_replace( '-', ' ', $colour ) );
		if ( '' === $label ) {
			$label = ucwords( str_replace( '-', ' ', $colour ) );
		}
		$hex = $term && ! is_wp_error( $term ) ? (string) get_term_meta( $term->term_id, 'cfvsw_color', true ) : '';
		$known = array(
			'black' => '#171717', 'white' => '#FFFFFF', 'off-white' => '#F4F0E8',
			'navy' => '#202A44', 'maroon' => '#641F32', 'red' => '#C62828',
			'pink' => '#E7A9B8', 'light-pink' => '#F6CFDC', 'lavender' => '#C9B8E8',
			'grey' => '#A8A8A8', 'gray' => '#A8A8A8', 'olive' => '#5A5F38',
			'blue' => '#2B4BA8', 'royal-blue' => '#2B4BA8', 'beige' => '#E3C79A',
			'mustard' => '#D29A2A', 'green' => '#38805A', 'mint' => '#BEE8D6',
			'baby-blue' => '#BCD9F0', 'purple' => '#6B3D83', 'brown' => '#795548',
			'charcoal' => '#4A4A4C', 'grey-melange' => '#BDBDBD',
		);
		if ( ! preg_match( '/^#[0-9a-f]{3,8}$/i', $hex ) ) {
			$hex = $known[ $colour ] ?? '#D8D8D8';
		}
		$options[ $colour ] = array( 'label' => $label, 'hex' => $hex );
	}
	return $options;
}

/**
 * Render fallback colour dots from real variation data. Existing plugin swatches stay
 * untouched; this only fills the gap when the swatch plugin omitted them on a card.
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
	echo '<div class="sf-card-colour-fallback" aria-label="' . esc_attr__( 'Available colours', 'store-features' ) . '">';
	foreach ( $options as $slug => $option ) {
		printf(
			'<a class="sf-card-colour-fallback__dot" href="%1$s" data-slug="%2$s" title="%3$s" aria-label="%3$s" style="--sf-colour:%4$s"></a>',
			esc_url( add_query_arg( 'attribute_pa_color', $slug, $product->get_permalink() ) ),
			esc_attr( $slug ),
			esc_attr( $option['label'] ),
			esc_attr( $option['hex'] )
		);
	}
	echo '</div>';
}
add_action( 'woocommerce_after_shop_loop_item_title', 'store_features_card_colour_fallback', 15 );

/**
 * Hide fallback dots whenever the swatch plugin already rendered its native swatches.
 * This prevents two sets of colour dots appearing on cards where both systems work.
 */
function store_features_hide_duplicate_card_swatches() {
	?>
	<style>
		.pw2 li.product:has(.cfvsw-shop-container .cfvsw-swatches-option) .sf-card-colour-fallback,
		.pw2 li.product:has(.cfvsw-swatches-container .cfvsw-swatches-option) .sf-card-colour-fallback {
			display: none !important;
		}
	</style>
	<script>
	(function(){
		function removeDuplicateFallbacks(){
			document.querySelectorAll('.pw2 li.product, li.product').forEach(function(card){
				if(card.querySelector('.cfvsw-shop-container .cfvsw-swatches-option, .cfvsw-swatches-container .cfvsw-swatches-option')){
					var fallback=card.querySelector('.sf-card-colour-fallback');
					if(fallback) fallback.remove();
				}
			});
		}
		if(document.readyState==='loading'){
			document.addEventListener('DOMContentLoaded',removeDuplicateFallbacks);
		}else{removeDuplicateFallbacks();}
	})();
	</script>
	<?php
}
add_action( 'wp_footer', 'store_features_hide_duplicate_card_swatches', 60 );

/**
 * Print the list inside each card.
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
 * The swap script, only on pages that showed such cards.
 */
function store_features_card_colour_script() {
	if ( empty( $GLOBALS['store_features_card_colours'] ) ) {
		return;
	}
	?>
<script>
(function(){
	function swap(option){
		var card=option.closest('li.product, .product, .wc-block-grid__product');
		if(!card){return;}
		var data=card.querySelector('script.sf-card-colours');
		if(!data){return;}
		var map; try{map=JSON.parse(data.textContent);}catch(e){return;}
		var p=map[option.getAttribute('data-slug')];
		var img=card.querySelector('img.attachment-woocommerce_thumbnail, img.wp-post-image, a img');
		if(!p||!img){return;}
		img.removeAttribute('loading');
		img.setAttribute('src',p.s);
		if(p.ss){img.setAttribute('srcset',p.ss);}else{img.removeAttribute('srcset');}
	}
	document.addEventListener('click',function(e){
		var o=e.target.closest && e.target.closest('.cfvsw-shop-container .cfvsw-swatches-option');
		if(o){setTimeout(function(){swap(o);},0);}
	},true);
})();
</script>
	<?php
}
add_action( 'wp_footer', 'store_features_card_colour_script', 50 );
