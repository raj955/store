<?php
/**
 * Qikink fulfilment: send WooCommerce orders straight to Qikink through its Open API.
 *
 * Every order line carries the Qikink SKU (blank + colour + size), the print file and the
 * print size, so nothing has to be created or mapped inside the Qikink dashboard.
 *
 * Setup: WooCommerce → Settings → Integration → Qikink. Choose Sandbox or Live, paste the
 * Client ID and Client Secret from Qikink → Integrations → Open API. Orders are sent only
 * when "Send orders automatically" is ticked, or by hand from the order screen
 * (Order actions → Send to Qikink).
 *
 * Product data (set once per product, as product meta):
 *   _sf_qikink_style        UC22 | UC21 | UH26 | UH24
 *   _sf_qikink_design_code  e.g. BEFIKR (max 20 characters)
 *   _sf_qikink_design_url   public URL of the print-ready PNG
 *   _sf_qikink_design_w     print width in inches
 *   _sf_qikink_design_h     print height in inches
 * A variation can override the generated SKU with _sf_qikink_sku.
 * Order lines made with "Design your own" carry their own print file (see custom-design.php)
 * and are never sent automatically: the order waits for a person to check the design.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

const STORE_FEATURES_QIKINK_LOG = 'store-features-qikink';

/**
 * Qikink SKU prefix for each blank used by the store.
 *
 * @return array<string, string>
 */
function store_features_qikink_style_prefixes() {
	return array(
		'UC22' => 'UOsMRnHs', // Oversized Classic T-Shirt, 240 GSM.
		'UC21' => 'MRnHs',    // Classic Crew T-Shirt, 180 GSM.
		'UH26' => 'USs',      // Sweatshirt, 300 GSM.
		'UH24' => 'UHd',      // Hooded Sweatshirt, 300 GSM.
	);
}

/**
 * Qikink colour codes, keyed by the store's colour term slug.
 *
 * @return array<string, string>
 */
function store_features_qikink_colour_codes() {
	return array(
		'black'        => 'Bk',
		'white'        => 'Wh',
		'off-white'    => 'OFw',
		'navy'         => 'Nb',
		'maroon'       => 'Mn',
		'lavender'     => 'Lv',
		'bottle-green' => 'Gn',
		'olive'        => 'OG',
		'beige'        => 'Be',
		'mustard'      => 'MYl',
		'grey-melange' => 'Gm',
		'mint'         => 'Mnt',
		'baby-blue'    => 'BB',
		'red'          => 'Rd',
		'royal-blue'   => 'Rb',
		'light-pink'   => 'LBp',
		'coral'        => 'Cor',
	);
}

/**
 * Qikink size codes, keyed by the store's size term slug.
 *
 * @return array<string, string>
 */
function store_features_qikink_size_codes() {
	return array(
		'xs'  => 'XS',
		's'   => 'S',
		'm'   => 'M',
		'l'   => 'L',
		'xl'  => 'XL',
		'2xl' => 'XXL',
		'3xl' => '3XL',
	);
}

/**
 * Settings page under WooCommerce → Settings → Integration.
 */
function store_features_qikink_register_integration( $integrations ) {
	if ( class_exists( 'WC_Integration' ) ) {
		require_once __DIR__ . '/class-qikink-integration.php';
		$integrations[] = 'Store_Features_Qikink_Integration';
	}
	return $integrations;
}
add_filter( 'woocommerce_integrations', 'store_features_qikink_register_integration' );

/**
 * Read one setting.
 *
 * @param string $key     Setting key.
 * @param string $default Fallback.
 * @return string
 */
function store_features_qikink_setting( $key, $default = '' ) {
	$settings = get_option( 'woocommerce_store_features_qikink_settings', array() );
	return isset( $settings[ $key ] ) && '' !== $settings[ $key ] ? (string) $settings[ $key ] : $default;
}

/**
 * API host for the chosen mode, or '' when sending is switched off.
 *
 * @return string
 */
function store_features_qikink_base_url() {
	switch ( store_features_qikink_setting( 'mode', 'off' ) ) {
		case 'sandbox':
			return 'https://sandbox.qikink.com';
		case 'live':
			return 'https://api.qikink.com';
	}
	return '';
}

/**
 * Write to WooCommerce → Status → Logs (source store-features-qikink).
 *
 * @param string $level   info|warning|error.
 * @param string $message Message.
 */
function store_features_qikink_log( $level, $message ) {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, $message, array( 'source' => STORE_FEATURES_QIKINK_LOG ) );
	}
}

/**
 * The Qikink SKU for one product or variation.
 *
 * @param WC_Product $product Variation (or simple product).
 * @return string|WP_Error
 */
function store_features_qikink_sku( $product ) {
	$override = (string) $product->get_meta( '_sf_qikink_sku' );
	if ( '' !== $override ) {
		return $override;
	}

	$parent   = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
	$style    = strtoupper( (string) $parent->get_meta( '_sf_qikink_style' ) );
	$prefixes = store_features_qikink_style_prefixes();
	if ( ! isset( $prefixes[ $style ] ) ) {
		return new WP_Error( 'qikink_style', sprintf( '%s has no Qikink style.', $parent->get_name() ) );
	}

	if ( $product->is_type( 'variation' ) ) {
		$attributes = $product->get_variation_attributes( false );
		$colour     = isset( $attributes['pa_color'] ) ? (string) $attributes['pa_color'] : '';
		$size       = isset( $attributes['pa_size'] ) ? (string) $attributes['pa_size'] : '';
	} else {
		$colour = sanitize_title( (string) $product->get_attribute( 'pa_color' ) );
		$size   = sanitize_title( (string) $product->get_attribute( 'pa_size' ) );
	}

	$colours = store_features_qikink_colour_codes();
	$sizes   = store_features_qikink_size_codes();
	if ( ! isset( $colours[ $colour ] ) || ! isset( $sizes[ $size ] ) ) {
		return new WP_Error( 'qikink_variant', sprintf( 'No Qikink code for colour "%s" or size "%s" (%s).', $colour, $size, $parent->get_name() ) );
	}

	return $prefixes[ $style ] . '-' . $colours[ $colour ] . '-' . $sizes[ $size ];
}

/**
 * Build the Qikink "create order" request for a WooCommerce order.
 *
 * @param WC_Order $order Order.
 * @return array|WP_Error
 */
function store_features_qikink_payload( $order ) {
	$items = array();

	foreach ( $order->get_items() as $item ) {
		/** @var WC_Order_Item_Product $item */
		$product = $item->get_product();
		if ( ! $product ) {
			return new WP_Error( 'qikink_product', sprintf( 'Order line "%s" no longer has a product.', $item->get_name() ) );
		}
		$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;

		$sku = store_features_qikink_sku( $product );
		if ( is_wp_error( $sku ) ) {
			return $sku;
		}

		$custom = function_exists( 'store_features_custom_item_design' ) ? store_features_custom_item_design( $item ) : null;
		if ( $custom ) {
			// The customer's own design, made on the product page.
			$code   = $custom['code'];
			$link   = $custom['print_url'];
			$width  = $custom['w'];
			$high   = $custom['h'];
			$mockup = $custom['mockup_url'];
		} else {
			$code     = substr( preg_replace( '/[^A-Za-z0-9]/', '', (string) $parent->get_meta( '_sf_qikink_design_code' ) ), 0, 20 );
			$link     = (string) $parent->get_meta( '_sf_qikink_design_url' );
			$width    = (float) $parent->get_meta( '_sf_qikink_design_w' );
			$high     = (float) $parent->get_meta( '_sf_qikink_design_h' );
			$image_id = $product->get_image_id() ? $product->get_image_id() : $parent->get_image_id();
			$mockup   = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
		}
		if ( '' === $code || '' === $link || $width <= 0 || $high <= 0 ) {
			return new WP_Error( 'qikink_design', sprintf( '%s is missing its print file details.', $parent->get_name() ) );
		}
		$quantity = max( 1, (int) $item->get_quantity() );

		$items[] = array(
			'search_from_my_products' => 0,
			'quantity'                => $quantity,
			'print_type_id'           => (int) store_features_qikink_setting( 'print_type_id', '1' ),
			'price'                   => round( ( (float) $item->get_total() + (float) $item->get_total_tax() ) / $quantity, 2 ),
			'sku'                     => $sku,
			'designs'                 => array(
				array(
					'design_code'   => $code,
					'width_inches'  => round( $width, 2 ),
					'height_inches' => round( $high, 2 ),
					'placement_sku' => 'fr',
					'design_link'   => $link,
					'mockup_link'   => $mockup ? $mockup : $link,
				),
			),
		);
	}

	if ( ! $items ) {
		return new WP_Error( 'qikink_empty', 'The order has no products.' );
	}

	$phone = preg_replace( '/\D/', '', (string) ( $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone() ) );
	$phone = substr( $phone, -10 );
	$zip   = preg_replace( '/\D/', '', (string) $order->get_shipping_postcode() );
	if ( 10 !== strlen( $phone ) ) {
		return new WP_Error( 'qikink_phone', 'The customer phone number needs 10 digits.' );
	}
	if ( 6 !== strlen( $zip ) ) {
		return new WP_Error( 'qikink_zip', 'The delivery PIN code needs 6 digits.' );
	}

	$country = $order->get_shipping_country() ? $order->get_shipping_country() : 'IN';
	$state   = (string) $order->get_shipping_state();
	if ( function_exists( 'WC' ) && WC()->countries ) {
		$states = WC()->countries->get_states( $country );
		if ( is_array( $states ) && isset( $states[ $state ] ) ) {
			$state = html_entity_decode( $states[ $state ], ENT_QUOTES );
		}
	}

	$address1 = trim( (string) $order->get_shipping_address_1() );

	return array(
		'order_number'      => 'W' . $order->get_order_number(),
		'qikink_shipping'   => 1,
		'gateway'           => 'cod' === $order->get_payment_method() ? 'COD' : 'Prepaid',
		'total_order_value' => round( (float) $order->get_total(), 2 ),
		'line_items'        => $items,
		'shipping_address'  => array(
			'first_name'   => (string) $order->get_shipping_first_name(),
			'last_name'    => (string) $order->get_shipping_last_name(),
			'address1'     => function_exists( 'mb_substr' ) ? mb_substr( $address1, 0, 90 ) : substr( $address1, 0, 90 ),
			'address2'     => (string) $order->get_shipping_address_2(),
			'phone'        => $phone,
			'email'        => (string) $order->get_billing_email(),
			'city'         => (string) $order->get_shipping_city(),
			'zip'          => $zip,
			'province'     => $state,
			'country_code' => $country,
		),
	);
}

/**
 * Access token, cached for a little less than its lifetime.
 *
 * @param bool $fresh Ignore the cache.
 * @return string|WP_Error
 */
function store_features_qikink_token( $fresh = false ) {
	$base   = store_features_qikink_base_url();
	$id     = store_features_qikink_setting( 'client_id' );
	$secret = store_features_qikink_setting( 'client_secret' );
	if ( '' === $base || '' === $id || '' === $secret ) {
		return new WP_Error( 'qikink_setup', 'Qikink is not set up: choose Sandbox or Live and add the Client ID and Secret.' );
	}

	$cache_key = 'sf_qikink_token_' . md5( $base . $id );
	if ( ! $fresh ) {
		$cached = get_transient( $cache_key );
		if ( $cached ) {
			return $cached;
		}
	}

	$response = wp_remote_post(
		$base . '/api/token',
		array(
			'timeout' => 20,
			'body'    => array(
				'ClientId'      => $id,
				'client_secret' => $secret,
			),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$body  = json_decode( wp_remote_retrieve_body( $response ), true );
	$token = is_array( $body ) && ! empty( $body['Accesstoken'] ) ? (string) $body['Accesstoken'] : '';
	if ( '' === $token ) {
		return new WP_Error( 'qikink_token', 'Qikink did not return an access token (HTTP ' . wp_remote_retrieve_response_code( $response ) . '). Check the Client ID and Secret.' );
	}

	$ttl = is_array( $body ) && ! empty( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
	set_transient( $cache_key, $token, max( 60, $ttl - 300 ) );

	return $token;
}

/**
 * Send one order to Qikink and record the result on the order.
 *
 * @param int|WC_Order $order Order or order ID.
 * @param bool         $force Send again even if it was sent before.
 * @return true|WP_Error
 */
function store_features_qikink_send( $order, $force = false ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
	if ( ! $order ) {
		return new WP_Error( 'qikink_order', 'Order not found.' );
	}
	if ( ! $force && 'sent' === $order->get_meta( '_sf_qikink_status' ) ) {
		return true;
	}

	$payload = store_features_qikink_payload( $order );
	$result  = is_wp_error( $payload ) ? $payload : store_features_qikink_post_order( $payload );

	if ( is_wp_error( $result ) ) {
		$order->update_meta_data( '_sf_qikink_status', 'error' );
		$order->update_meta_data( '_sf_qikink_error', $result->get_error_message() );
		$order->add_order_note( 'Qikink: not sent. ' . $result->get_error_message() );
		$order->save();
		store_features_qikink_log( 'error', 'Order ' . $order->get_order_number() . ': ' . $result->get_error_message() );
		return $result;
	}

	$order->update_meta_data( '_sf_qikink_status', 'sent' );
	$order->update_meta_data( '_sf_qikink_order_id', (string) $result );
	$order->update_meta_data( '_sf_qikink_mode', store_features_qikink_setting( 'mode', 'off' ) );
	$order->delete_meta_data( '_sf_qikink_error' );
	$order->add_order_note( sprintf( 'Qikink: sent (%1$s), Qikink order ID %2$s.', store_features_qikink_setting( 'mode' ), $result ) );
	$order->save();
	store_features_qikink_log( 'info', 'Order ' . $order->get_order_number() . ' sent, Qikink order ' . $result );

	return true;
}

/**
 * POST the order to Qikink. Retries once with a fresh token.
 *
 * @param array $payload Request body.
 * @return string|WP_Error Qikink order ID.
 */
function store_features_qikink_post_order( $payload ) {
	for ( $attempt = 0; $attempt < 2; $attempt++ ) {
		$token = store_features_qikink_token( $attempt > 0 );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post(
			store_features_qikink_base_url() . '/api/order/create',
			array(
				'timeout' => 30,
				'headers' => array(
					'ClientId'     => store_features_qikink_setting( 'client_id' ),
					'Accesstoken'  => $token,
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code || 403 === $code ) {
			continue; // Token expired or replaced: try once more with a new one.
		}
		if ( $code >= 200 && $code < 300 && is_array( $body ) && ! empty( $body['order_id'] ) ) {
			return (string) $body['order_id'];
		}

		$error = is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : wp_remote_retrieve_body( $response );
		return new WP_Error( 'qikink_api', 'Qikink said: ' . ( is_string( $error ) ? wp_strip_all_tags( substr( $error, 0, 300 ) ) : wp_json_encode( $error ) ) . ' (HTTP ' . $code . ')' );
	}

	return new WP_Error( 'qikink_auth', 'Qikink refused the access token twice. Check the Client ID and Secret.' );
}

/**
 * Automatic sending: queue the order when it is ready to make (paid, or confirmed COD).
 *
 * @param int $order_id Order ID.
 */
function store_features_qikink_maybe_queue( $order_id ) {
	if ( function_exists( 'store_features_custom_order_has_design' ) && store_features_custom_order_has_design( $order_id ) ) {
		store_features_custom_hold_for_review( $order_id );
		return;
	}
	if ( 'yes' !== store_features_qikink_setting( 'auto_send', 'no' ) || '' === store_features_qikink_base_url() ) {
		return;
	}
	if ( function_exists( 'as_enqueue_async_action' ) ) {
		as_enqueue_async_action( 'store_features_qikink_send_async', array( 'order_id' => (int) $order_id ), 'store-features' );
	} else {
		store_features_qikink_send( $order_id );
	}
}
add_action( 'woocommerce_order_status_processing', 'store_features_qikink_maybe_queue' );

/**
 * Action Scheduler callback.
 *
 * @param int $order_id Order ID.
 */
function store_features_qikink_send_async( $order_id ) {
	store_features_qikink_send( $order_id );
}
add_action( 'store_features_qikink_send_async', 'store_features_qikink_send_async' );

/**
 * Order screen: "Send to Qikink" and "Check Qikink request" in Order actions.
 *
 * @param array $actions Order actions.
 * @return array
 */
function store_features_qikink_order_actions( $actions ) {
	$actions['sf_qikink_send']    = __( 'Send to Qikink', 'store-features' );
	$actions['sf_qikink_preview'] = __( 'Check Qikink request (nothing is sent)', 'store-features' );
	return $actions;
}
add_filter( 'woocommerce_order_actions', 'store_features_qikink_order_actions' );

/**
 * Manual send from the order screen.
 *
 * @param WC_Order $order Order.
 */
function store_features_qikink_action_send( $order ) {
	store_features_qikink_send( $order, true );
}
add_action( 'woocommerce_order_action_sf_qikink_send', 'store_features_qikink_action_send' );

/**
 * Build the request without sending it and show it as a private order note.
 *
 * @param WC_Order $order Order.
 */
function store_features_qikink_action_preview( $order ) {
	$payload = store_features_qikink_payload( $order );
	if ( is_wp_error( $payload ) ) {
		$order->add_order_note( 'Qikink check: ' . $payload->get_error_message() );
		return;
	}
	$order->add_order_note( "Qikink check: ready to send.\n" . wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}
add_action( 'woocommerce_order_action_sf_qikink_preview', 'store_features_qikink_action_preview' );

/**
 * Show the Qikink status next to the order's billing details.
 *
 * @param WC_Order $order Order.
 */
function store_features_qikink_order_status_box( $order ) {
	$status = (string) $order->get_meta( '_sf_qikink_status' );
	$label  = 'Not sent yet';
	if ( 'sent' === $status ) {
		$label = 'Sent, Qikink order ' . $order->get_meta( '_sf_qikink_order_id' ) . ' (' . $order->get_meta( '_sf_qikink_mode' ) . ')';
	} elseif ( 'error' === $status ) {
		$label = 'Not sent: ' . $order->get_meta( '_sf_qikink_error' );
	} elseif ( 'review' === $status ) {
		$label = 'Waiting for you. This order has a customer\'s own design: check it under the products below, then choose Order actions → Send to Qikink.';
	}
	echo '<p class="form-field form-field-wide"><strong>Qikink:</strong> ' . esc_html( $label ) . '</p>';
}
add_action( 'woocommerce_admin_order_data_after_billing_address', 'store_features_qikink_order_status_box' );
