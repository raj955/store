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
function store_features_import_closeups( $limit = 0 ) {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$all_done = true;
	$added    = 0;
	foreach ( store_features_closeup_files() as $slug => $path ) {
		if ( $limit && $added >= $limit ) {
			$all_done = false;
			break;
		}
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
		++$added;
	}

	if ( $all_done ) {
		update_option( 'store_features_closeups_done', STORE_FEATURES_CLOSEUPS_VERSION, false );
	}
}
add_action(
	'store_features_import_closeups',
	function () {
		if ( get_transient( 'store_features_closeups_lock' ) ) {
			return;
		}
		set_transient( 'store_features_closeups_lock', 1, 300 );
		store_features_import_closeups();
		delete_transient( 'store_features_closeups_lock' );
	}
);

/**
 * In case WordPress's scheduled tasks don't run on this host: after a normal page has been sent
 * to the visitor, add one close-up per request until all are in. A short lock stops overlaps.
 */
function store_features_closeups_on_shutdown() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		|| (int) get_option( 'store_features_closeups_done', 0 ) >= STORE_FEATURES_CLOSEUPS_VERSION
		|| get_transient( 'store_features_closeups_lock' ) ) {
		return;
	}
	set_transient( 'store_features_closeups_lock', 1, 90 );
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	} elseif ( function_exists( 'litespeed_finish_request' ) ) {
		litespeed_finish_request();
	}
	store_features_import_closeups( 1 );
	delete_transient( 'store_features_closeups_lock' );
}
add_action( 'shutdown', 'store_features_closeups_on_shutdown', 99 );

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
 * Zoom button, hint and click-to-open, added to the gallery once WooCommerce has built it.
 */
function store_features_zoom_assets() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	$hint = wp_is_mobile()
		? __( 'Photo पर tap करो, फिर दो उँगलियों से zoom करो। दूसरी photo में print पास से दिखता है।', 'store-features' )
		: __( 'Photo पर mouse रखो तो zoom होगा, click करो तो पूरी screen पर खुलेगी। दूसरी photo में print पास से दिखता है।', 'store-features' );
	wp_add_inline_style(
		'store-features',
		'.woocommerce-product-gallery{position:relative}'
		. '.woocommerce-product-gallery__image{cursor:zoom-in}'
		. '.woocommerce div.product div.images .woocommerce-product-gallery__trigger{display:none!important}'
		. '.sf-zoom-btn{position:absolute;top:12px;right:12px;z-index:9;display:inline-flex;align-items:center;gap:6px;height:40px;padding:0 14px;border:0;border-radius:999px;background:rgba(18,18,20,.85);color:#F3EEE4;font:inherit;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.25)}'
		. '.sf-zoom-btn:hover{background:#121214;color:#FFA41B}'
		. '.sf-zoom-btn svg{width:18px;height:18px}'
		. '.woocommerce-product-gallery .sf-zoom-hint{order:100;margin:0;padding:8px 14px 12px;font-size:13.5px;line-height:1.45;color:#4A4740;text-align:center;width:100%}'
		. '.woocommerce div.product div.images .flex-control-thumbs{display:flex;flex-wrap:nowrap;gap:8px;overflow-x:auto;padding:8px 0 2px;margin:0;scrollbar-width:thin}'
		. '.woocommerce div.product div.images .flex-control-thumbs li{flex:0 0 72px;width:72px!important;margin:0!important;float:none!important}'
		. '.woocommerce div.product div.images .flex-control-thumbs li img{border-radius:8px}'
		. '.pswp__bg{background:#121214}'
	);
	$data = wp_json_encode( array( 'hint' => $hint ) );
	wp_add_inline_script(
		'store-features',
		'(function($){if(!$){return;}var cfg=' . $data . ';'
		. 'var ICON=\'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5M11 8v6M8 11h6" stroke-linecap="round"/></svg>\';'
		. 'function open(g){var t=g.find(".woocommerce-product-gallery__trigger");if(t.length){t.trigger("click");return;}var a=g.find(".flex-active-slide a").first();if(!a.length){a=g.find(".woocommerce-product-gallery__image a").first();}a.trigger("click");}'
		. 'function setup(){$(".woocommerce-product-gallery").each(function(){var g=$(this);if(g.data("sfZoom")){return;}g.data("sfZoom",1);'
		. 'var b=$(\'<button type="button" class="sf-zoom-btn" aria-label="Photo बड़ी करके देखो">\'+ICON+\'<span>Zoom</span></button>\');b.on("click",function(e){e.preventDefault();open(g);});g.append(b);'
		. 'g.append($(\'<p class="sf-zoom-hint"></p>\').text(cfg.hint));});}'
		. '$(window).on("load",setup);$(document.body).on("wc-product-gallery-after-init",function(){setTimeout(setup,0);});'
		. 'if(window.wc_single_product_params&&wc_single_product_params.zoom_enabled==="1"){$(document).on("click",".woocommerce-product-gallery__image",function(e){if($(e.target).closest(".sf-zoom-btn").length){return;}e.preventDefault();open($(this).closest(".woocommerce-product-gallery"));});}'
		. '})(window.jQuery);'
	);
}
add_action( 'wp_enqueue_scripts', 'store_features_zoom_assets', 35 );
