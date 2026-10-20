<?php
/**
 * Store Child theme setup.
 *
 * Deployed automatically from GitHub. Edit this file in the repo, not on the server,
 * or the next deploy will overwrite your change.
 *
 * @package store-child
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is the 2026 design (dark, Sarpanch headlines, new home page) on?
 * Switch it off with the option store_child_design = "v1" to get the old look back at once.
 *
 * @return bool
 */
function store_child_v2() {
	return 'v1' !== get_option( 'store_child_design', 'v2' );
}

require_once get_stylesheet_directory() . '/inc/home.php';

/**
 * Body class that every rule of the new design hangs off.
 *
 * @param array $classes Body classes.
 * @return array
 */
function store_child_body_class( $classes ) {
	if ( store_child_v2() ) {
		$classes[] = 'pw2';
	}
	return $classes;
}
add_filter( 'body_class', 'store_child_body_class' );

/**
 * Load the brand font, then the child stylesheet after Kadence's own styles.
 * The stylesheet's modified time is used as the version, so every deploy busts the browser cache.
 */
function store_child_enqueue_styles() {
	$path = get_stylesheet_directory() . '/style.css';

	wp_enqueue_style(
		'store-child-fonts',
		store_child_v2()
			? 'https://fonts.googleapis.com/css2?family=Anek+Devanagari:wdth,wght@75..125,100..800&family=Kalam:wght@700&family=Sarpanch:wght@700;900&display=swap'
			: 'https://fonts.googleapis.com/css2?family=Anek+Devanagari:wdth,wght@75..125,100..800&display=swap',
		array(),
		null
	);

	wp_enqueue_style(
		'store-child',
		get_stylesheet_uri(),
		array( 'store-child-fonts' ),
		file_exists( $path ) ? (string) filemtime( $path ) : null
	);

	if ( store_child_v2() ) {
		$v2 = get_stylesheet_directory() . '/assets/v2.css';
		wp_enqueue_style(
			'store-child-v2',
			get_stylesheet_directory_uri() . '/assets/v2.css',
			array( 'store-child' ),
			file_exists( $v2 ) ? (string) filemtime( $v2 ) : null
		);
	}
}
add_action( 'wp_enqueue_scripts', 'store_child_enqueue_styles', 20 );

/**
 * Open the connection to Google Fonts early so text appears sooner.
 *
 * @param array  $urls          URLs to print for resource hints.
 * @param string $relation_type The relation type the URLs are printed for.
 * @return array
 */
function store_child_resource_hints( $urls, $relation_type ) {
	if ( 'preconnect' === $relation_type ) {
		$urls[] = array( 'href' => 'https://fonts.googleapis.com' );
		$urls[] = array(
			'href' => 'https://fonts.gstatic.com',
			'crossorigin',
		);
	}

	return $urls;
}
add_filter( 'wp_resource_hints', 'store_child_resource_hints', 10, 2 );

/**
 * The header sits on a dark background in the new design, so the logo uses its light version.
 *
 * @param array   $attr       Image attributes.
 * @param WP_Post $attachment Image.
 * @return array
 */
function store_child_light_logo( $attr, $attachment ) {
	if ( is_admin() || ! store_child_v2() || ! $attachment || (int) get_theme_mod( 'custom_logo' ) !== (int) $attachment->ID ) {
		return $attr;
	}
	$attr['src'] = get_stylesheet_directory_uri() . '/assets/brand/panuwear-logo-light.png';
	unset( $attr['srcset'], $attr['sizes'] );
	return $attr;
}
add_filter( 'wp_get_attachment_image_attributes', 'store_child_light_logo', 10, 2 );
