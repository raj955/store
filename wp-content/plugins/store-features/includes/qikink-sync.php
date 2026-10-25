<?php
/**
 * Automatic tracking from Qikink: no AWB typing, ever.
 *
 * Qikink has no webhooks, so about once an hour (after a normal page has been sent to a visitor)
 * the site reads Qikink's newest orders through the Open API (same Client ID / Secret as in
 * WooCommerce → Settings → Integration → Qikink) and, for each matching store order:
 *  - saves the courier, AWB and tracking link, and emails the customer the live tracking link
 *    the first time an AWB appears;
 *  - marks the order Delivered (and Completed) when Qikink says delivered;
 *  - adds a note when Qikink reports RTO / return / cancel.
 *
 * Matching: Qikink order numbers look like "654367_2945" (account _ store order number), and
 * orders sent through our Open API also carry Qikink's order ID in _sf_qikink_order_id.
 *
 * Status (no personal data): GET /wp-json/store-features/v1/qikink-sync-status
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

const STORE_FEATURES_QK_SYNC_PAGES = 5; // 10 orders a page.

/**
 * GET from the Qikink API (retries once with a fresh token).
 *
 * @param array $params Query.
 * @return array|WP_Error
 */
function store_features_qk_get( $params ) {
	if ( ! function_exists( 'store_features_qikink_token' ) ) {
		return new WP_Error( 'qk', 'Qikink module missing.' );
	}
	for ( $attempt = 0; $attempt < 2; $attempt++ ) {
		$token = store_features_qikink_token( $attempt > 0 );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$response = wp_remote_get(
			add_query_arg( $params, store_features_qikink_base_url() . '/api/order' ),
			array(
				'timeout' => 25,
				'headers' => array(
					'ClientId'    => store_features_qikink_setting( 'client_id' ),
					'Accesstoken' => $token,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$err  = is_array( $body ) && isset( $body['error'] ) && $body['error'] ? $body['error'] : '';
		if ( 401 === $code || 403 === $code || ( is_string( $err ) && false !== stripos( $err, 'token' ) ) ) {
			continue;
		}
		if ( $code >= 400 || $err ) {
			return new WP_Error( 'qk', 'Qikink: ' . ( is_string( $err ) && $err ? $err : 'HTTP ' . $code ) );
		}
		if ( is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ) ) {
			$body = $body['data'];
		}
		return is_array( $body ) ? $body : array();
	}
	return new WP_Error( 'qk', 'Qikink refused the token.' );
}

/**
 * Find the store order for a Qikink order.
 *
 * @param array $qk Qikink order.
 * @return WC_Order|null
 */
function store_features_qk_match( $qk ) {
	$qk_id = (string) ( $qk['order_id'] ?? $qk['id'] ?? '' );
	if ( '' !== $qk_id ) {
		$found = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_sf_qikink_order_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $qk_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $found ) {
			return $found[0];
		}
	}
	$number = (string) ( $qk['number'] ?? $qk['order_number'] ?? '' );
	$number = preg_replace( '/^\d+_/', '', $number );
	$number = preg_replace( '/\D/', '', (string) $number );
	if ( '' === $number ) {
		return null;
	}
	$order = wc_get_order( (int) $number );
	if ( ! $order ) {
		return null;
	}
	// Guard against a different order that happens to share the number: the phone must match if both have one.
	$qk_phone = substr( preg_replace( '/\D/', '', (string) ( $qk['shipping_address']['phone'] ?? $qk['phone'] ?? '' ) ), -10 );
	$wc_phone = substr( preg_replace( '/\D/', '', (string) $order->get_billing_phone() ), -10 );
	if ( 10 === strlen( $qk_phone ) && 10 === strlen( $wc_phone ) && $qk_phone !== $wc_phone ) {
		return null;
	}
	return $order;
}

/**
 * Apply one Qikink order to the store order.
 *
 * @param WC_Order $order Store order.
 * @param array    $qk    Qikink order.
 * @return string What changed ('' for nothing).
 */
function store_features_qk_apply( $order, $qk ) {
	$status   = strtolower( trim( (string) ( $qk['status'] ?? '' ) ) );
	$shipping = isset( $qk['shipping'] ) && is_array( $qk['shipping'] ) ? $qk['shipping'] : array();
	$awb      = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) ( $shipping['awb'] ?? $qk['awb'] ?? '' ) );
	$courier  = trim( (string) ( $shipping['courier_provider_name'] ?? $shipping['courier'] ?? '' ) );
	$link     = esc_url_raw( (string) ( $shipping['tracking_link'] ?? $shipping['tracking_url'] ?? '' ) );
	$changed  = array();

	if ( $status && $order->get_meta( '_sf_qikink_live_status' ) !== $status ) {
		$order->update_meta_data( '_sf_qikink_live_status', $status );
		$changed[] = 'status ' . $status;
	}

	$old_awb = (string) $order->get_meta( '_sf_awb' );
	if ( $awb && $awb !== $old_awb ) {
		$slug = 'other';
		foreach ( store_features_couriers() as $key => $c ) {
			if ( 'other' !== $key && $courier && false !== stripos( str_replace( ' ', '', $courier ), str_replace( ' ', '', $c[0] ) ) ) {
				$slug = $key;
				break;
			}
		}
		$order->update_meta_data( '_sf_courier', $slug );
		$order->update_meta_data( '_sf_courier_name', $courier );
		$order->update_meta_data( '_sf_awb', $awb );
		if ( $link ) {
			$order->update_meta_data( '_sf_track_url', $link );
		}
		$order->save();
		store_features_notify_shipped( $order );
		$changed[] = 'awb';
	} elseif ( $link && ! $order->get_meta( '_sf_track_url' ) ) {
		$order->update_meta_data( '_sf_track_url', $link );
		$changed[] = 'link';
	}

	if ( 'delivered' === $status && 'yes' !== $order->get_meta( '_sf_delivered' ) ) {
		$order->update_meta_data( '_sf_delivered', 'yes' );
		$order->save();
		if ( ! in_array( $order->get_status(), array( 'completed', 'refunded', 'cancelled' ), true ) ) {
			$order->update_status( 'completed', 'Qikink: delivered.' );
		}
		$changed[] = 'delivered';
	} elseif ( preg_match( '/rto|return|cancel|lost/', $status ) && ! $order->get_meta( '_sf_qk_problem_noted' ) ) {
		$order->update_meta_data( '_sf_qk_problem_noted', $status );
		$order->add_order_note( 'Qikink status: ' . $status . '. Check this order in the Qikink dashboard.' );
		$changed[] = 'problem';
	}

	if ( $changed ) {
		$order->save();
	}
	return implode( ', ', $changed );
}

/**
 * One sync run.
 *
 * @return array Summary.
 */
function store_features_qk_sync() {
	// Reading status works even when sending orders through the API is switched off.
	$force_live = function ( $settings ) {
		if ( is_array( $settings ) && ( empty( $settings['mode'] ) || 'off' === $settings['mode'] ) ) {
			$settings['mode'] = 'live';
		}
		return $settings;
	};
	add_filter( 'option_woocommerce_store_features_qikink_settings', $force_live );
	try {
		return store_features_qk_sync_run();
	} finally {
		remove_filter( 'option_woocommerce_store_features_qikink_settings', $force_live );
	}
}

/**
 * The sync itself.
 *
 * @return array Summary.
 */
function store_features_qk_sync_run() {
	$summary = array(
		'time'    => gmdate( 'c' ),
		'seen'    => 0,
		'matched' => 0,
		'updated' => 0,
		'error'   => '',
		'keys'    => array(),
	);
	for ( $page = 1; $page <= STORE_FEATURES_QK_SYNC_PAGES; $page++ ) {
		$list = store_features_qk_get( array( 'page_no' => $page ) );
		if ( is_wp_error( $list ) ) {
			$summary['error'] = $list->get_error_message();
			break;
		}
		if ( isset( $list['number'] ) || isset( $list['order_id'] ) ) {
			$list = array( $list ); // A single order came back.
		}
		if ( ! $list ) {
			break;
		}
		foreach ( $list as $qk ) {
			if ( ! is_array( $qk ) ) {
				continue;
			}
			++$summary['seen'];
			if ( ! $summary['keys'] ) {
				// Field names only (no values), to check the format without storing customer data.
				$summary['keys'] = array_keys( $qk );
				if ( isset( $qk['shipping'] ) && is_array( $qk['shipping'] ) ) {
					$summary['keys'][] = 'shipping: ' . implode( ',', array_keys( $qk['shipping'] ) );
				}
			}
			$order = store_features_qk_match( $qk );
			if ( ! $order ) {
				continue;
			}
			++$summary['matched'];
			if ( '' !== store_features_qk_apply( $order, $qk ) ) {
				++$summary['updated'];
			}
		}
		if ( count( $list ) < 10 ) {
			break;
		}
	}
	update_option( 'store_features_qk_sync_last', $summary, false );
	return $summary;
}

/**
 * Run about once an hour, after the page has gone to the visitor (no slowdown for them).
 */
function store_features_qk_sync_on_shutdown() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	if ( '' === store_features_qikink_setting( 'client_id' ) || get_transient( 'store_features_qk_sync_wait' ) ) {
		return;
	}
	set_transient( 'store_features_qk_sync_wait', 1, HOUR_IN_SECONDS );
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	} elseif ( function_exists( 'litespeed_finish_request' ) ) {
		litespeed_finish_request();
	}
	try {
		store_features_qk_sync();
	} catch ( Throwable $e ) {
		update_option( 'store_features_qk_sync_last', array( 'time' => gmdate( 'c' ), 'error' => $e->getMessage() ), false );
	}
}
add_action( 'shutdown', 'store_features_qk_sync_on_shutdown', 98 );

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'store-features/v1',
			'/qikink-sync-status',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					$last = get_option( 'store_features_qk_sync_last', array() );
					return array(
						'configured' => '' !== store_features_qikink_setting( 'client_id' ),
						'mode'       => store_features_qikink_setting( 'mode', 'off' ),
						'last'       => $last,
						'next_in'    => get_transient( 'store_features_qk_sync_wait' ) ? 'within the hour' : 'next page view',
					);
				},
			)
		);
	}
);
