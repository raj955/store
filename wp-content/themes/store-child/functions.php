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
 * Load the child stylesheet after Kadence's own styles.
 * The file's modified time is used as the version, so every deploy busts the browser cache.
 */
function store_child_enqueue_styles() {
	$path = get_stylesheet_directory() . '/style.css';

	wp_enqueue_style(
		'store-child',
		get_stylesheet_uri(),
		array(),
		file_exists( $path ) ? (string) filemtime( $path ) : null
	);
}
add_action( 'wp_enqueue_scripts', 'store_child_enqueue_styles', 20 );
