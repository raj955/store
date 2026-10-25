<?php
/**
 * "Front" / "Back" labels on product photos, so shoppers know which side of the garment a
 * photo shows (the big print is often on the back).
 *
 * Each photo's side is stored on the image itself (_sf_photo_side = front|back). The product
 * page shows a small label on the big photo and on its thumbnail.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	function () {
		$admin = function () {
			return current_user_can( 'manage_woocommerce' );
		};
		// Save sides: { sides: { "123": "front", "124": "back", "125": "" } }.
		register_rest_route(
			'store-features/v1',
			'/photo-sides',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => function ( $request ) {
					$saved = array();
					foreach ( (array) $request['sides'] as $id => $side ) {
						$id   = absint( $id );
						$side = in_array( $side, array( 'front', 'back' ), true ) ? $side : '';
						if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
							continue;
						}
						if ( '' === $side ) {
							delete_post_meta( $id, '_sf_photo_side' );
						} else {
							update_post_meta( $id, '_sf_photo_side', $side );
						}
						$saved[ $id ] = $side;
					}
					return rest_ensure_response( array( 'saved' => $saved ) );
				},
			)
		);
		// Photos of ready products, with their colour and side, for tagging.
		register_rest_route(
			'store-features/v1',
			'/photo-sides',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => function () {
					$out = array();
					foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'type' => 'variable' ) ) as $product ) {
						if ( function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product ) ) {
							continue;
						}
						$colour_of = array();
						foreach ( (array) $product->get_meta( '_sf_qikink_colour_images' ) as $slug => $ids ) {
							foreach ( (array) $ids as $id ) {
								$colour_of[ (int) $id ] = $slug;
							}
						}
						$ids = array_values( array_unique( array_filter( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) ) ) ) );
						$out[] = array(
							'id'     => $product->get_id(),
							'name'   => $product->get_name(),
							'photos' => array_map(
								function ( $id ) use ( $colour_of ) {
									return array(
										'id'     => $id,
										'url'    => wp_get_attachment_image_url( $id, 'woocommerce_thumbnail' ),
										'colour' => $colour_of[ $id ] ?? '',
										'side'   => (string) get_post_meta( $id, '_sf_photo_side', true ),
									);
								},
								$ids
							),
						);
					}
					return rest_ensure_response( $out );
				},
			)
		);
	}
);

/**
 * Sides of this product's photos, keyed by the full-size file path (without size suffix).
 *
 * @param WC_Product $product Product.
 * @return array<string, string>
 */
function store_features_photo_sides( $product ) {
	$ids   = array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) );
	$sides = array();
	foreach ( array_unique( array_filter( $ids ) ) as $id ) {
		$side = (string) get_post_meta( $id, '_sf_photo_side', true );
		$url  = wp_get_attachment_image_url( $id, 'full' );
		if ( $url && in_array( $side, array( 'front', 'back' ), true ) ) {
			$sides[ wp_parse_url( $url, PHP_URL_PATH ) ] = $side;
		}
	}
	return $sides;
}

/**
 * Put the labels on the product page.
 */
function store_features_photo_sides_assets() {
	if ( ! is_product() ) {
		return;
	}
	$product = wc_get_product( get_queried_object_id() );
	if ( ! $product || ( function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product ) ) ) {
		return;
	}
	$sides = store_features_photo_sides( $product );
	if ( ! $sides ) {
		return;
	}
	$labels = array(
		'front' => __( 'Front · आगे', 'store-features' ),
		'back'  => __( 'Back · पीछे', 'store-features' ),
	);
	wp_add_inline_style(
		'store-features',
		'.woocommerce-product-gallery__image{position:relative}'
		. '.sf-side{position:absolute;left:12px;top:12px;z-index:3;padding:5px 11px;border-radius:999px;background:rgba(23,28,58,.86);color:#fff;font-size:13px;font-weight:700;line-height:1.2;letter-spacing:.01em;pointer-events:none}'
		. '.sf-side--back{background:#A96546}'
		. '.flex-control-thumbs li{position:relative}'
		. '.sf-side--thumb{left:50%;top:auto;bottom:4px;transform:translateX(-50%);padding:2px 7px;font-size:10.5px;white-space:nowrap}'
	);
	$script = 'window.sfPhotoSides=' . wp_json_encode( array( 'sides' => $sides, 'labels' => $labels ) ) . ';' . <<<'JS'
(function () {
	var data = window.sfPhotoSides;
	function path(url) {
		try { return new URL(url, location.href).pathname.replace(/-[0-9]+x[0-9]+(?=\.[^.]+$)/, ''); } catch (e) { return ''; }
	}
	function sideOf(el) {
		var img = el && el.querySelector('img');
		if (!img) { return ''; }
		var a = el.querySelector('a');
		var keys = [img.getAttribute('data-large_image'), a && a.getAttribute('href'), img.getAttribute('data-src'), img.getAttribute('src')];
		for (var i = 0; i < keys.length; i++) {
			var s = keys[i] && data.sides[path(keys[i])];
			if (s) { return s; }
		}
		return '';
	}
	function badge(el, side, thumb) {
		if (!side || el.querySelector(':scope > .sf-side')) { return; }
		var b = document.createElement('span');
		b.className = 'sf-side sf-side--' + side + (thumb ? ' sf-side--thumb' : '');
		b.textContent = thumb ? (side === 'front' ? 'Front' : 'Back') : data.labels[side];
		el.appendChild(b);
	}
	function apply() {
		var gallery = document.querySelector('.single-product .woocommerce-product-gallery');
		if (!gallery) { return false; }
		var slides = gallery.querySelectorAll('.woocommerce-product-gallery__image');
		var sides = [];
		slides.forEach(function (el) { var s = sideOf(el); sides.push(s); badge(el, s, false); });
		var thumbs = gallery.querySelectorAll('.flex-control-thumbs li');
		thumbs.forEach(function (li, i) {
			var s = sides[i] || data.sides[path((li.querySelector('img') || {}).src || '')] || '';
			badge(li, s, true);
		});
		return thumbs.length > 0;
	}
	var tries = 0;
	(function wait() { if (!apply() && ++tries < 30) { setTimeout(wait, 250); } })();
})();
JS;
	wp_add_inline_script( 'store-features', $script );
}
add_action( 'wp_enqueue_scripts', 'store_features_photo_sides_assets', 35 );
