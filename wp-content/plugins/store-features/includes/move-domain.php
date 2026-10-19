<?php
/**
 * Move the store to its own domain: WordPress address + every stored link to the old domain.
 *
 * Admin only: POST /wp-json/store-features/v1/move-domain { from, to, dry_run }.
 *  - from / to: bare hosts, e.g. "theonlinefood.com" → "panuwear.com".
 *  - dry_run (default true): only counts what would change.
 * Serialized values (widgets, theme settings, product data) are unserialized, replaced and saved
 * again, so their lengths stay right. GUIDs are left alone, as WordPress recommends.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'store-features/v1',
			'/move-domain',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => 'store_features_move_domain',
			)
		);
	}
);

/**
 * Replace every form of the old address in a value (strings, arrays, objects).
 *
 * @param mixed $value Value.
 * @param array $pairs from => to.
 * @return mixed
 */
function store_features_move_domain_replace( $value, $pairs ) {
	if ( is_string( $value ) ) {
		return strtr( $value, $pairs );
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = store_features_move_domain_replace( $v, $pairs );
		}
		return $value;
	}
	if ( is_object( $value ) && ! ( $value instanceof __PHP_Incomplete_Class ) ) {
		foreach ( get_object_vars( $value ) as $k => $v ) {
			$value->$k = store_features_move_domain_replace( $v, $pairs );
		}
	}
	return $value;
}

/**
 * The REST callback.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function store_features_move_domain( $request ) {
	global $wpdb;
	$from = strtolower( trim( (string) $request['from'] ) );
	$to   = strtolower( trim( (string) $request['to'] ) );
	$dry  = ! isset( $request['dry_run'] ) || filter_var( $request['dry_run'], FILTER_VALIDATE_BOOLEAN );
	$host = '~^[a-z0-9-]+(\.[a-z0-9-]+)+$~';
	if ( ! preg_match( $host, $from ) || ! preg_match( $host, $to ) || $from === $to ) {
		return new WP_Error( 'sf_move', 'Give two different bare domains, e.g. theonlinefood.com and panuwear.com.', array( 'status' => 400 ) );
	}
	if ( false === strpos( (string) get_option( 'home' ), $from ) ) {
		return new WP_Error( 'sf_move', 'The site address is not on ' . $from . ' (already moved?).', array( 'status' => 400 ) );
	}

	$new   = 'https://' . $to;
	$pairs = array();
	foreach ( array( 'https://www.', 'http://www.', 'https://', 'http://' ) as $scheme ) {
		$pairs[ $scheme . $from ]                           = $new;
		$pairs[ str_replace( '/', '\\/', $scheme . $from ) ] = str_replace( '/', '\\/', $new );
	}
	$like  = '%' . $wpdb->esc_like( $from ) . '%';
	$count = array();

	// Posts (pages, products, menus, blocks): plain text, no serialization.
	$posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE post_content LIKE %s OR post_excerpt LIKE %s", $like, $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$count['posts'] = count( $posts );
	if ( ! $dry ) {
		foreach ( $posts as $p ) {
			$wpdb->update( $wpdb->posts, array( 'post_content' => strtr( $p->post_content, $pairs ), 'post_excerpt' => strtr( $p->post_excerpt, $pairs ) ), array( 'ID' => $p->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $p->ID );
		}
	}

	// Meta tables and options: may be serialized.
	$tables = array(
		'postmeta' => array( $wpdb->postmeta, 'meta_id', 'meta_value' ),
		'termmeta' => array( $wpdb->termmeta, 'meta_id', 'meta_value' ),
		'options'  => array( $wpdb->options, 'option_id', 'option_value' ),
	);
	foreach ( $tables as $name => list( $table, $id_col, $val_col ) ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$id_col} AS id, {$val_col} AS val" . ( 'options' === $name ? ', option_name AS name' : '' ) . " FROM {$table} WHERE {$val_col} LIKE %s", $like ) ); // phpcs:ignore
		$n    = 0;
		foreach ( $rows as $row ) {
			if ( 'options' === $name && ( in_array( $row->name, array( 'home', 'siteurl' ), true ) || 0 === strpos( $row->name, '_transient' ) || 0 === strpos( $row->name, '_site_transient' ) ) ) {
				continue;
			}
			$value = maybe_unserialize( $row->val );
			$new_v = store_features_move_domain_replace( $value, $pairs );
			$out   = is_string( $row->val ) && is_serialized( $row->val ) ? maybe_serialize( $new_v ) : ( is_string( $new_v ) ? $new_v : maybe_serialize( $new_v ) );
			if ( $out !== $row->val ) {
				++$n;
				if ( ! $dry ) {
					$wpdb->update( $table, array( $val_col => $out ), array( $id_col => $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
		}
		$count[ $name ] = $n;
	}

	if ( ! $dry ) {
		update_option( 'home', $new );
		update_option( 'siteurl', $new );
		wp_cache_flush();
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
	}

	return rest_ensure_response(
		array(
			'dry_run' => $dry,
			'from'    => $from,
			'to'      => $new,
			'changed' => $count,
		)
	);
}

/**
 * The old test address sends people to the store's own domain (pages, products, wp-admin).
 * Machine endpoints (REST, WooCommerce API, cron, admin-ajax) and non-GET requests are left
 * alone, so Qikink and other integrations keep working until they use the new address.
 */
add_action(
	'init',
	function () {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		if ( ! in_array( $host, array( 'theonlinefood.com', 'www.theonlinefood.com' ), true ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || preg_match( '~^/(wp-json|wc-api)(/|$)|[?&](wc-api|rest_route)=|wp-cron\.php|xmlrpc\.php|admin-ajax\.php~', $uri ) ) {
			return;
		}
		$home = untrailingslashit( home_url() );
		if ( false !== strpos( $home, 'theonlinefood.com' ) ) {
			return;
		}
		wp_redirect( $home . $uri, 301 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	},
	0
);
