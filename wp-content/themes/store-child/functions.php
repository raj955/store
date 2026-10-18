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
 * Load the brand font, then the child stylesheet after Kadence's own styles.
 * The stylesheet's modified time is used as the version, so every deploy busts the browser cache.
 */
function store_child_enqueue_styles() {
	$path = get_stylesheet_directory() . '/style.css';

	wp_enqueue_style(
		'store-child-fonts',
		'https://fonts.googleapis.com/css2?family=Anek+Devanagari:wdth,wght@75..125,100..800&display=swap',
		array(),
		null
	);

	wp_enqueue_style(
		'store-child',
		get_stylesheet_uri(),
		array( 'store-child-fonts' ),
		file_exists( $path ) ? (string) filemtime( $path ) : null
	);
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
