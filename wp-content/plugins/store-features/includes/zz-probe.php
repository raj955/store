<?php
// Temporary: which product meta does Qikink's push leave (to find each product's Qikink cost). Removed after one check.
defined( 'ABSPATH' ) || exit;
add_action( 'rest_api_init', function () {
	register_rest_route( 'store-features/v1', '/probe-meta', array(
		'methods' => 'GET', 'permission_callback' => '__return_true',
		'callback' => function () {
			$out = array();
			foreach ( wc_get_products( array( 'status' => array( 'publish', 'trash', 'draft' ), 'limit' => 80, 'type' => 'variable' ) ) as $p ) {
				$meta = array();
				foreach ( get_post_meta( $p->get_id() ) as $k => $v ) {
					if ( 0 === strpos( $k, '_wp_' ) || in_array( $k, array( '_edit_lock', '_edit_last' ), true ) ) continue;
					$meta[ $k ] = substr( (string) $v[0], 0, 40 );
				}
				$kids = $p->get_children();
				$vm = array();
				if ( $kids ) foreach ( get_post_meta( $kids[0] ) as $k => $v ) { $vm[ $k ] = substr( (string) $v[0], 0, 40 ); }
				$out[] = array( 'id' => $p->get_id(), 'name' => $p->get_name(), 'status' => get_post_status( $p->get_id() ), 'price' => $p->get_price(), 'meta' => $meta, 'var' => $vm );
			}
			return $out;
		},
	) );
} );
