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
			// Old WooCommerce lightbox styles; the theme has its own photo zoom.
			wp_dequeue_style( 'woocommerce_prettyPhoto_css' );
		}
	},
	100
);

/**
 * Another plugin pulls the admin icon font back in as a dependency; drop its tag for shoppers.
 */
add_filter(
	'style_loader_tag',
	function ( $tag, $handle ) {
		return ( 'dashicons' === $handle && ! is_admin() && ! is_user_logged_in() ) ? '' : $tag;
	},
	10,
	2
);

/**
 * Timing: a Server-Timing header shows how long each stage of building the page took
 * (visible in the browser's network tab; no private data).
 */
$GLOBALS['sf_timing'] = array( 'start' => isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ) );
foreach ( array( 'plugins_loaded', 'setup_theme', 'after_setup_theme', 'init', 'wp_loaded', 'wp', 'template_redirect', 'wp_head', 'wp_footer' ) as $sf_hook ) {
	add_action(
		$sf_hook,
		function () use ( $sf_hook ) {
			$GLOBALS['sf_timing'][ $sf_hook ] = microtime( true );
		},
		PHP_INT_MAX
	);
}
add_action(
	'template_redirect',
	function () {
		ob_start(
			function ( $html ) {
				if ( ! headers_sent() ) {
					global $wpdb;
					$t    = $GLOBALS['sf_timing'];
					$prev = $t['start'];
					$out  = array();
					foreach ( $t as $k => $v ) {
						if ( 'start' === $k ) {
							continue;
						}
						$out[] = str_replace( '_', '-', $k ) . ';dur=' . round( ( $v - $prev ) * 1000 );
						$prev  = $v;
					}
					$out[] = 'render;dur=' . round( ( microtime( true ) - $prev ) * 1000 );
					$out[] = 'total;dur=' . round( ( microtime( true ) - $t['start'] ) * 1000 );
					$out[] = 'queries;dur=' . (int) $wpdb->num_queries;
					header( 'Server-Timing: ' . implode( ', ', $out ) );
				}
				return $html;
			}
		);
	},
	PHP_INT_MAX
);

/**
 * The checkout page is the block checkout (React). The old-style checkout scripts that some
 * plugins still load next to it do nothing there but cost time on phones: the classic checkout
 * script, the country dropdown library, and the password strength checker (zxcvbn, ~800 KB).
 * Only on the checkout page itself, and only when it really is the block checkout.
 */
function store_features_speed_block_checkout_only() {
	if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url() ) {
		return false;
	}
	$page = get_post( wc_get_page_id( 'checkout' ) );
	return $page && has_block( 'woocommerce/checkout', $page );
}
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! store_features_speed_block_checkout_only() ) {
			return;
		}
		foreach ( array( 'wc-checkout', 'selectWoo', 'select2', 'wc-password-strength-meter', 'password-strength-meter', 'zxcvbn-async', 'wc-country-select', 'wc-address-i18n', 'wc-cart-fragments' ) as $h ) {
			wp_dequeue_script( $h );
		}
		wp_dequeue_style( 'select2' );
	},
	1000
);
