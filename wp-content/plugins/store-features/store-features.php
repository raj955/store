<?php
/**
 * Plugin Name:       Store Features
 * Description:       Custom store features (size chart, pincode check, COD rules and more). Deployed from GitHub.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Text Domain:       store-features
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

define( 'STORE_FEATURES_VERSION', '0.1.0' );
define( 'STORE_FEATURES_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Read the deploy info written by the GitHub Actions pipeline.
 *
 * @return array{sha?: string, time?: string}
 */
function store_features_build_info() {
	$file = STORE_FEATURES_DIR . 'build-info.php';

	if ( ! is_readable( $file ) ) {
		return array();
	}

	$info = include $file;

	return is_array( $info ) ? $info : array();
}

/**
 * Show which commit is live in the admin footer, so you can confirm a deploy worked.
 *
 * @param string $text Default footer text.
 * @return string
 */
function store_features_admin_footer( $text ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return $text;
	}

	$info = store_features_build_info();

	if ( empty( $info['sha'] ) ) {
		return $text;
	}

	return sprintf(
		'Store Features %1$s &middot; deploy <code>%2$s</code> &middot; %3$s',
		esc_html( STORE_FEATURES_VERSION ),
		esc_html( $info['sha'] ),
		esc_html( isset( $info['time'] ) ? $info['time'] : '' )
	);
}
add_filter( 'admin_footer_text', 'store_features_admin_footer' );
