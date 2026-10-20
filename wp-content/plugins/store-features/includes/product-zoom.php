<?php
/**
 * Seeing a product up close.
 *
 * - Every ready design gets a sharp print close-up (1800 x 2250) as its second photo, made from the
 *   real print file, so shoppers can zoom into the print. Qikink's own photos are only 682 px wide.
 *   The images ship in assets/closeups/ and are added to the media library once, in the background.
 * - Computer: hover zooms, a click opens the full-screen viewer.
 * - Phone: a tap opens the full-screen viewer, where pinch and double-tap zoom work.
 *   (The small in-place zoom box is turned off on phones; it fights with scrolling.)
 * - A visible "zoom" button and a one-line hint, so people know they can.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

const STORE_FEATURES_CLOSEUPS_VERSION = 1;

/**
 * Close-up files that ship with the plugin: product slug => file path.
 *
 * @return array
 */
function store_features_closeup_files() {
	$files = array();
	foreach ( glob( STORE_FEATURES_DIR . 'assets/closeups/*-print-closeup.jpg' ) ?: array() as $path ) {
		$files[ basename( $path, '-print-closeup.jpg' ) ] = $path;
	}
	return $files;
}

/**
 * Queue the one-time import when the shipped close-ups are newer than what was imported.
 */
function store_features_closeups_schedule() {
	if ( (int) get_option( 'store_features_closeups_done', 0 ) >= STORE_FEATURES_CLOSEUPS_VERSION ) {
		return;
	}
	if ( ! wp_next_scheduled( 'store_features_import_closeups' ) ) {
		wp_schedule_single_event( time() + 5, 'store_features_import_closeups' );
	}
}
add_action( 'init', 'store_features_closeups_schedule' );

/**
 * Add each close-up to the media library and put it second in its product's gallery.
 * Safe to run again: a product that already has its close-up is skipped.
 */
function store_features_import_closeups() {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$all_done = true;
	foreach ( store_features_closeup_files() as $slug => $path ) {
		$post = get_page_by_path( $slug, OBJECT, 'product' );
		if ( ! $post ) {
			continue; // Product not on this site (yet).
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			continue;
		}
		$existing = (int) get_post_meta( $post->ID, '_sf_closeup_id', true );
		if ( $existing && get_post( $existing ) ) {
			continue;
		}

		$upload = wp_upload_bits( basename( $path ), null, file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! empty( $upload['error'] ) ) {
			$all_done = false;
			continue;
		}
		$title = sprintf( /* translators: product name */ __( '%s, print close-up', 'store-features' ), $product->get_name() );
		$id    = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $title,
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$post->ID
		);
		if ( ! $id || is_wp_error( $id ) ) {
			$all_done = false;
			continue;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		update_post_meta( $id, '_wp_attachment_image_alt', $title );
		update_post_meta( $post->ID, '_sf_closeup_id', $id );

		$gallery = array_values( array_diff( $product->get_gallery_image_ids(), array( $id ) ) );
		array_unshift( $gallery, $id );
		$product->set_gallery_image_ids( $gallery );
		$product->save();
	}

	if ( $all_done ) {
		update_option( 'store_features_closeups_done', STORE_FEATURES_CLOSEUPS_VERSION, false );
	}
}
add_action( 'store_features_import_closeups', 'store_features_import_closeups' );

/**
 * Phones: no in-place zoom box; a tap opens the full-screen viewer instead (WooCommerce does this
 * by itself when the zoom box is off).
 *
 * @param bool $enabled Zoom on.
 * @return bool
 */
function store_features_zoom_on_desktop_only( $enabled ) {
	return wp_is_mobile() ? false : $enabled;
}
add_filter( 'woocommerce_single_product_zoom_enabled', 'store_features_zoom_on_desktop_only' );

/**
 * Hover zoom: a little more magnification, so even the 682 px photos visibly enlarge.
 *
 * @param array $options jQuery Zoom options.
 * @return array
 */
function store_features_zoom_options( $options ) {
	$options['magnify'] = 1.5;
	return $options;
}
add_filter( 'woocommerce_single_product_zoom_options', 'store_features_zoom_options' );

/**
 * Full-screen viewer: allow zooming further in, keep the background solid.
 *
 * @param array $options PhotoSwipe options.
 * @return array
 */
function store_features_photoswipe_options( $options ) {
	$options['maxSpreadZoom']       = 3;
	$options['bgOpacity']           = 1;
	$options['showHideOpacity']     = true;
	$options['closeOnVerticalDrag'] = true;
	$options['shareEl']             = false;
	return $options;
}
add_filter( 'woocommerce_single_product_photoswipe_options', 'store_features_photoswipe_options' );

/**
 * A short hint under the photos.
 */
function store_features_zoom_hint() {
	$text = wp_is_mobile()
		? __( 'Photo पर tap करो, फिर दो उँगलियों से zoom करो।', 'store-features' )
		: __( 'Photo पर mouse रखो zoom के लिए, click करो बड़ा देखने के लिए।', 'store-features' );
	echo '<p class="sf-zoom-hint">' . esc_html( $text ) . '</p>';
}
add_action( 'woocommerce_product_thumbnails', 'store_features_zoom_hint', 99 );

/**
 * Computer: a click on the photo opens the full-screen viewer too (not only the small icon).
 */
function store_features_zoom_assets() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	wp_add_inline_style(
		'store-features',
		'.woocommerce-product-gallery__image{cursor:zoom-in}'
		. '.woocommerce-product-gallery .woocommerce-product-gallery__trigger{width:auto!important;height:40px!important;padding:0 14px 0 38px!important;border-radius:999px!important;background:rgba(18,18,20,.82)!important;color:#F3EEE4!important;font-size:14px!important;font-weight:700;line-height:40px;text-indent:0!important;display:flex!important;align-items:center;top:12px!important;right:12px!important;z-index:9}'
		. '.woocommerce-product-gallery .woocommerce-product-gallery__trigger::after{content:"Zoom";position:static;display:inline;border:0;background:none;width:auto;height:auto;transform:none;color:inherit}'
		. '.woocommerce-product-gallery .woocommerce-product-gallery__trigger::before{left:12px!important;top:11px!important;border-color:#F3EEE4!important}'
		. '.woocommerce-product-gallery .woocommerce-product-gallery__trigger img,.woocommerce-product-gallery .woocommerce-product-gallery__trigger span{display:none}'
		. '.sf-zoom-hint{margin:10px 0 0;font-size:14px;color:var(--muted,#565D7A);text-align:center;width:100%;clear:both}'
		. '.pswp__bg{background:#121214}'
	);
	wp_add_inline_script(
		'store-features',
		"(function($){if(!$||!window.wc_single_product_params||wc_single_product_params.zoom_enabled!=='1'){return;}$(document).on('click','.woocommerce-product-gallery__image',function(e){var t=$(this).closest('.woocommerce-product-gallery').find('.woocommerce-product-gallery__trigger');if(t.length&&!$(e.target).closest('.woocommerce-product-gallery__trigger').length){e.preventDefault();t.trigger('click');}});})(window.jQuery);"
	);
}
add_action( 'wp_enqueue_scripts', 'store_features_zoom_assets', 35 );
