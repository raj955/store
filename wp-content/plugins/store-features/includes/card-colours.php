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
