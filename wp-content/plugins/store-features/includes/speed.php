<?php
/**
 * Speed: browser caching and compression for static files, and fewer files on the shop.
 *
 * - Adds long browser-cache times and gzip rules to the site's .htaccess (inside its own
 *   "Panuwear speed" block, written once per version; nothing else in .htaccess is touched).
 *   Files that change get a new ?ver= in their URL, so long cache times are safe.
 * - The dashicons icon font is only needed by logged-in admins.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

const STORE_FEATURES_SPEED_VERSION = 1;

/**
 * Write the .htaccess block.
 */
function store_features_speed_htaccess() {
	if ( (int) get_option( 'store_features_speed_done' ) >= STORE_FEATURES_SPEED_VERSION ) {
		return;
	}
	if ( ! function_exists( 'insert_with_markers' ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}
	$file  = ABSPATH . '.htaccess';
	$rules = array(
		'<IfModule mod_expires.c>',
		'ExpiresActive On',
		'ExpiresByType image/jpeg "access plus 1 year"',
		'ExpiresByType image/png "access plus 1 year"',
		'ExpiresByType image/webp "access plus 1 year"',
		'ExpiresByType image/avif "access plus 1 year"',
		'ExpiresByType image/svg+xml "access plus 1 year"',
		'ExpiresByType image/x-icon "access plus 1 year"',
		'ExpiresByType video/mp4 "access plus 1 year"',
		'ExpiresByType font/woff2 "access plus 1 year"',
		'ExpiresByType font/woff "access plus 1 year"',
		'ExpiresByType application/font-woff2 "access plus 1 year"',
		'ExpiresByType text/css "access plus 1 year"',
		'ExpiresByType application/javascript "access plus 1 year"',
		'ExpiresByType text/javascript "access plus 1 year"',
		'</IfModule>',
		'<IfModule mod_headers.c>',
		'<FilesMatch "\.(jpe?g|png|webp|avif|gif|svg|ico|mp4|woff2?|css|js)$">',
		'Header set Cache-Control "public, max-age=31536000"',
		'</FilesMatch>',
		'</IfModule>',
		'<IfModule mod_deflate.c>',
		'AddOutputFilterByType DEFLATE text/html text/plain text/css text/xml application/xml application/javascript text/javascript application/json image/svg+xml',
		'</IfModule>',
	);
	if ( ( file_exists( $file ) && is_writable( $file ) ) || ( ! file_exists( $file ) && is_writable( ABSPATH ) ) ) {
		$ok = insert_with_markers( $file, 'Panuwear speed', $rules );
		update_option( 'store_features_speed_done', $ok ? STORE_FEATURES_SPEED_VERSION : -1, false );
	} else {
		update_option( 'store_features_speed_done', -1, false );
	}
}
add_action( 'admin_init', 'store_features_speed_htaccess' );

/**
 * Shoppers don't need the admin icon font.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! is_user_logged_in() && ! is_admin_bar_showing() ) {
			wp_dequeue_style( 'dashicons' );
		}
	},
	100
);
