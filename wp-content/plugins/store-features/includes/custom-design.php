<?php
/**
 * "Design your own": customers put their own text or photo on a blank and order it.
 *
 * How it fits together:
 * - A product is a custom product when its meta _sf_custom is "yes". It also has
 *   _sf_qikink_style (UC21 | UC22 | UH26 | UH24), so the Qikink SKU is built as usual.
 * - On the product page the customizer (assets/custom/customizer.js) draws the design on a
 *   photo of the blank, makes the 300 DPI print PNG and a preview JPEG in the browser, uploads
 *   both here in pieces, then adds the item to the cart with a random token that names them.
 * - Files live in uploads/sf-custom/pending/<token>/ until an order is made from the cart, then
 *   in uploads/sf-custom/orders/<token>/. Pending files older than two days are removed daily.
 * - Custom pieces can't be paid by cash on delivery, can't be returned, and are never sent to
 *   Qikink automatically: the order waits until someone checks the design and sends it.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

const STORE_FEATURES_CUSTOM_CHUNK = 3145728;   // Largest upload piece, 3 MB.
const STORE_FEATURES_CUSTOM_MAX   = 62914560;  // Largest print file, 60 MB.

/**
 * Blank photos, print areas and colours, written by the render script.
 *
 * @return array
 */
function store_features_custom_config() {
	static $config = null;
	if ( null === $config ) {
		$file   = STORE_FEATURES_DIR . 'assets/custom/config.json';
		$config = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$config = is_array( $config ) ? $config : array();
	}
	return $config;
}

/**
 * Fonts offered for text, all with Devanagari and Latin letters.
 *
 * @return array<int, array{family: string, weight: int, label: string}>
 */
function store_features_custom_fonts() {
	return array(
		array( 'family' => 'Yatra One', 'weight' => 400, 'label' => 'Yatra' ),
		array( 'family' => 'Rozha One', 'weight' => 400, 'label' => 'Rozha' ),
		array( 'family' => 'Baloo 2', 'weight' => 800, 'label' => 'Baloo' ),
		array( 'family' => 'Kalam', 'weight' => 700, 'label' => 'Kalam' ),
		array( 'family' => 'Tiro Devanagari Hindi', 'weight' => 400, 'label' => 'Tiro' ),
		array( 'family' => 'Mukta', 'weight' => 800, 'label' => 'Mukta' ),
	);
}

/**
 * Is this product (or the parent of this variation) a "Design your own" product?
 *
 * @param WC_Product|int|null $product Product.
 * @return bool
 */
function store_features_is_custom_product( $product ) {
	$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
	if ( ! $product instanceof WC_Product ) {
		return false;
	}
	$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
	return $parent && 'yes' === $parent->get_meta( '_sf_custom' );
}

/**
 * The style settings for a custom product.
 *
 * @param WC_Product $product Product.
 * @return array|null
 */
function store_features_custom_style( $product ) {
	$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
	$style  = strtoupper( (string) $parent->get_meta( '_sf_qikink_style' ) );
	$config = store_features_custom_config();
	return isset( $config['styles'][ $style ] ) ? array_merge( $config['styles'][ $style ], array( 'code' => $style ) ) : null;
}

/* ------------------------------------------------------------------ files */

/**
 * Folder (and URL) for a design.
 *
 * @param string $stage pending|orders.
 * @param string $token Design token.
 * @return array{dir: string, url: string}
 */
function store_features_custom_location( $stage, $token = '' ) {
	$uploads = wp_get_upload_dir();
	$rel     = 'sf-custom/' . $stage . ( $token ? '/' . $token : '' );
	return array(
		'dir' => trailingslashit( $uploads['basedir'] ) . $rel,
		'url' => trailingslashit( $uploads['baseurl'] ) . $rel,
	);
}

/**
 * Valid token: 32 lowercase hex characters.
 *
 * @param mixed $token Token.
 * @return bool
 */
function store_features_custom_valid_token( $token ) {
	return is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $token );
}

/**
 * Where a design's files are now (moved from pending to orders when an order is made).
 *
 * @param string $token Design token.
 * @return array{dir: string, url: string}|null
 */
function store_features_custom_find( $token ) {
	if ( ! store_features_custom_valid_token( $token ) ) {
		return null;
	}
	foreach ( array( 'orders', 'pending' ) as $stage ) {
		$loc = store_features_custom_location( $stage, $token );
		if ( is_file( $loc['dir'] . '/print.png' ) && is_file( $loc['dir'] . '/mockup.jpg' ) ) {
			return $loc;
		}
	}
	return null;
}

/**
 * Create the storage folders, with blank index files so they can't be listed.
 */
function store_features_custom_prepare_dirs() {
	foreach ( array( '', '/pending', '/orders' ) as $sub ) {
		$base = store_features_custom_location( 'x' );
		$dir  = dirname( $base['dir'] ) . $sub;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
}

/**
 * Upload one piece of a print file or preview. Pieces arrive in order; the last one is checked.
 */
function store_features_custom_upload() {
	if ( ! check_ajax_referer( 'sf-custom', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'This page has expired. Reload the page and try again.', 'store-features' ) ), 403 );
	}

	$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	$kind  = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
	$index = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0;
	$total = isset( $_POST['total'] ) ? absint( $_POST['total'] ) : 0;
	$file  = isset( $_FILES['chunk'] ) && is_array( $_FILES['chunk'] ) ? $_FILES['chunk'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	$limit = 'print' === $kind ? STORE_FEATURES_CUSTOM_MAX : 3145728;
	if ( ! store_features_custom_valid_token( $token ) || ! in_array( $kind, array( 'print', 'mockup' ), true )
		|| $total < 1 || $index >= $total || $total > (int) ceil( $limit / STORE_FEATURES_CUSTOM_CHUNK )
		|| ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > STORE_FEATURES_CUSTOM_CHUNK
		|| ! is_uploaded_file( $file['tmp_name'] ) ) {
		wp_send_json_error( array( 'message' => __( 'The upload did not work. Please try again.', 'store-features' ) ), 400 );
	}

	// At most 40 designs an hour from one address.
	if ( 'print' === $kind && 0 === $index ) {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key   = 'sf_custom_rate_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= 40 ) {
			wp_send_json_error( array( 'message' => __( 'Too many designs in a short time. Please wait a while and try again.', 'store-features' ) ), 429 );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	}

	store_features_custom_prepare_dirs();
	$loc  = store_features_custom_location( 'pending', $token );
	$part = $loc['dir'] . '/' . $kind . '.part';
	if ( ! is_dir( $loc['dir'] ) ) {
		if ( $index > 0 ) {
			wp_send_json_error( array( 'message' => __( 'The upload did not work. Please try again.', 'store-features' ) ), 400 );
		}
		wp_mkdir_p( $loc['dir'] );
	}

	$data = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$ok   = false !== $data && false !== file_put_contents( $part, $data, 0 === $index ? 0 : FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	clearstatcache( true, $part );
	if ( ! $ok || filesize( $part ) > $limit ) {
		wp_delete_file( $part );
		wp_send_json_error( array( 'message' => __( 'The file is too large.', 'store-features' ) ), 400 );
	}

	if ( $index < $total - 1 ) {
		wp_send_json_success( array( 'next' => $index + 1 ) );
	}

	// Last piece: check it really is the image we expect.
	$info     = @getimagesize( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$expected = 'print' === $kind ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
	$max      = 'print' === $kind ? array( 6000, 7500 ) : array( 1600, 2000 );
	if ( ! $info || $expected !== $info[2] || $info[0] > $max[0] || $info[1] > $max[1] || $info[0] < 50 || $info[1] < 50 ) {
		wp_delete_file( $part );
		wp_send_json_error( array( 'message' => __( 'The design file could not be read. Please try again.', 'store-features' ) ), 400 );
	}
	$final = $loc['dir'] . '/' . ( 'print' === $kind ? 'print.png' : 'mockup.jpg' );
	rename( $part, $final ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename

	wp_send_json_success( array( 'url' => $loc['url'] . '/' . basename( $final ) ) );
}
add_action( 'wp_ajax_sf_custom_upload', 'store_features_custom_upload' );
add_action( 'wp_ajax_nopriv_sf_custom_upload', 'store_features_custom_upload' );

/**
 * Remove a folder we created (files only, one level deep).
 *
 * @param string $dir Folder.
 */
function store_features_custom_remove_dir( $dir ) {
	foreach ( (array) glob( $dir . '/*' ) as $file ) {
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

/**
 * Daily: remove uploads that never became an order (older than two days).
 */
function store_features_custom_cleanup() {
	$pending = store_features_custom_location( 'pending' );
	foreach ( (array) glob( $pending['dir'] . '/*', GLOB_ONLYDIR ) as $dir ) {
		if ( store_features_custom_valid_token( basename( $dir ) ) && filemtime( $dir ) < time() - 2 * DAY_IN_SECONDS ) {
			store_features_custom_remove_dir( $dir );
		}
	}
}
add_action( 'store_features_custom_cleanup', 'store_features_custom_cleanup' );

/**
 * Schedule the daily clean-up.
 */
function store_features_custom_schedule() {
	if ( ! wp_next_scheduled( 'store_features_custom_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'store_features_custom_cleanup' );
	}
}
add_action( 'init', 'store_features_custom_schedule' );

/* ------------------------------------------------------------------ cart */

/**
 * Read and check the design sent with "Add to cart".
 *
 * @param WC_Product $product Product being added.
 * @return array|WP_Error
 */
function store_features_custom_posted_design( $product ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce's own add-to-cart request.
	$token   = isset( $_POST['sf_token'] ) ? sanitize_text_field( wp_unslash( $_POST['sf_token'] ) ) : '';
	$width   = isset( $_POST['sf_w'] ) ? (float) $_POST['sf_w'] : 0;
	$height  = isset( $_POST['sf_h'] ) ? (float) $_POST['sf_h'] : 0;
	$agree   = ! empty( $_POST['sf_agree'] );
	$summary = isset( $_POST['sf_summary'] ) ? sanitize_text_field( wp_unslash( $_POST['sf_summary'] ) ) : '';
	// phpcs:enable

	if ( ! store_features_custom_find( $token ) ) {
		return new WP_Error( 'sf_custom', __( 'Add your text or photo first, then add to cart.', 'store-features' ) );
	}
	if ( ! $agree ) {
		return new WP_Error( 'sf_custom', __( 'Please confirm you have checked your design.', 'store-features' ) );
	}
	$style = store_features_custom_style( $product );
	if ( ! $style || $width <= 0 || $height <= 0 || $width > $style['w_in'] + 0.05 || $height > $style['h_in'] + 0.05 ) {
		return new WP_Error( 'sf_custom', __( 'The design is larger than the print area. Make it smaller and try again.', 'store-features' ) );
	}

	return array(
		'token'   => $token,
		'w'       => round( $width, 2 ),
		'h'       => round( $height, 2 ),
		'summary' => function_exists( 'mb_substr' ) ? mb_substr( $summary, 0, 300 ) : substr( $summary, 0, 300 ),
	);
}

/**
 * A custom product can only be added with a finished design.
 *
 * @param bool $passed       Passed so far.
 * @param int  $product_id   Product (or variation) ID.
 * @param int  $quantity     Quantity.
 * @param int  $variation_id Variation ID.
 * @return bool
 */
function store_features_custom_validate_add( $passed, $product_id, $quantity = 1, $variation_id = 0 ) {
	$product = wc_get_product( $variation_id ? $variation_id : $product_id );
	if ( ! $passed || ! store_features_is_custom_product( $product ) ) {
		return $passed;
	}
	$design = store_features_custom_posted_design( $product );
	if ( is_wp_error( $design ) ) {
		wc_add_notice( $design->get_error_message(), 'error' );
		return false;
	}
	return true;
}
add_filter( 'woocommerce_add_to_cart_validation', 'store_features_custom_validate_add', 20, 4 );

/**
 * Keep the design with the cart line.
 *
 * @param array $data         Cart item data.
 * @param int   $product_id   Product ID.
 * @param int   $variation_id Variation ID.
 * @return array
 */
function store_features_custom_cart_item_data( $data, $product_id, $variation_id = 0 ) {
	$product = wc_get_product( $variation_id ? $variation_id : $product_id );
	if ( store_features_is_custom_product( $product ) ) {
		$design = store_features_custom_posted_design( $product );
		if ( ! is_wp_error( $design ) ) {
			$data['sf_custom'] = $design;
		}
	}
	return $data;
}
add_filter( 'woocommerce_add_cart_item_data', 'store_features_custom_cart_item_data', 10, 3 );

/**
 * Drop a cart line whose design files are gone (for example an old cart after clean-up).
 *
 * @param WC_Cart $cart Cart.
 */
function store_features_custom_check_cart( $cart ) {
	foreach ( $cart->get_cart() as $key => $item ) {
		if ( isset( $item['sf_custom'] ) && ! store_features_custom_find( $item['sf_custom']['token'] ) ) {
			$cart->remove_cart_item( $key );
			wc_add_notice( __( 'A custom design in your cart has expired. Please make it again.', 'store-features' ), 'notice' );
		} elseif ( ! isset( $item['sf_custom'] ) && isset( $item['data'] ) && store_features_is_custom_product( $item['data'] ) ) {
			$cart->remove_cart_item( $key );
		}
	}
}
add_action( 'woocommerce_cart_loaded_from_session', 'store_features_custom_check_cart' );

/**
 * Show the design on the cart line.
 *
 * @param array $data Item data shown under the product name.
 * @param array $item Cart item.
 * @return array
 */
function store_features_custom_item_data( $data, $item ) {
	if ( isset( $item['sf_custom'] ) ) {
		$data[] = array(
			'key'   => __( 'Design', 'store-features' ),
			'value' => $item['sf_custom']['summary'] ? $item['sf_custom']['summary'] : __( 'Your own design', 'store-features' ),
		);
		$data[] = array(
			'key'   => __( 'Note', 'store-features' ),
			'value' => __( 'Made for you: online payment only, no returns', 'store-features' ),
		);
	}
	return $data;
}
add_filter( 'woocommerce_get_item_data', 'store_features_custom_item_data', 10, 2 );

/**
 * The design preview as the cart thumbnail (cart drawer).
 *
 * @param string $html Thumbnail HTML.
 * @param array  $item Cart item.
 * @return string
 */
function store_features_custom_cart_thumbnail( $html, $item ) {
	if ( isset( $item['sf_custom'] ) ) {
		$loc = store_features_custom_find( $item['sf_custom']['token'] );
		if ( $loc ) {
			return '<img src="' . esc_url( $loc['url'] . '/mockup.jpg' ) . '" alt="' . esc_attr__( 'Your design', 'store-features' ) . '" width="300" height="375" loading="lazy" />';
		}
	}
	return $html;
}
add_filter( 'woocommerce_cart_item_thumbnail', 'store_features_custom_cart_thumbnail', 10, 2 );

/**
 * The design preview as the cart thumbnail (cart and checkout blocks).
 *
 * @param array $images Product images.
 * @param array $item   Cart item.
 * @return array
 */
function store_features_custom_block_images( $images, $item ) {
	if ( isset( $item['sf_custom'] ) ) {
		$loc = store_features_custom_find( $item['sf_custom']['token'] );
		if ( $loc ) {
			$url = $loc['url'] . '/mockup.jpg';
			return array(
				(object) array(
					'id'        => 0,
					'src'       => $url,
					'thumbnail' => $url,
					'srcset'    => '',
					'sizes'     => '',
					'name'      => __( 'Your design', 'store-features' ),
					'alt'       => __( 'Your design', 'store-features' ),
				),
			);
		}
	}
	return $images;
}
add_filter( 'woocommerce_store_api_cart_item_images', 'store_features_custom_block_images', 10, 2 );

/**
 * Does the cart hold a custom piece?
 *
 * @return bool
 */
function store_features_custom_cart_has_design() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return false;
	}
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( isset( $item['sf_custom'] ) ) {
			return true;
		}
	}
	return false;
}

/**
 * No cash on delivery for custom pieces.
 *
 * @param array $gateways Available gateways.
 * @return array
 */
function store_features_custom_gateways( $gateways ) {
	if ( ! is_admin() && isset( $gateways['cod'] ) && store_features_custom_cart_has_design() ) {
		unset( $gateways['cod'] );
	}
	return $gateways;
}
add_filter( 'woocommerce_available_payment_gateways', 'store_features_custom_gateways' );

/**
 * The note shown above the cart and checkout when a custom piece is in the cart.
 *
 * @return string
 */
function store_features_custom_notice_html() {
	return '<div class="sf-custom-notice" role="note"><strong>' . esc_html__( 'Your cart has a piece made with your own design.', 'store-features' ) . '</strong> '
		. esc_html__( 'It is made just for you, so it is paid online (no cash on delivery) and can\'t be returned or exchanged. If it arrives damaged or printed wrong, we replace it free.', 'store-features' ) . '</div>';
}

/**
 * Put the note above the cart and checkout blocks.
 *
 * @param string $content Block HTML.
 * @return string
 */
function store_features_custom_block_notice( $content ) {
	if ( store_features_custom_cart_has_design() ) {
		return store_features_custom_notice_html() . $content;
	}
	return $content;
}
add_filter( 'render_block_woocommerce/cart', 'store_features_custom_block_notice' );
add_filter( 'render_block_woocommerce/checkout', 'store_features_custom_block_notice' );

/* ------------------------------------------------------------------ orders */

/**
 * Copy the design onto the order line and move its files out of the clean-up folder.
 *
 * @param WC_Order_Item_Product $item   Order line.
 * @param string                $key    Cart item key.
 * @param array                 $values Cart item.
 * @param WC_Order|null         $order  Order being made.
 */
function store_features_custom_order_line( $item, $key, $values, $order = null ) {
	if ( empty( $values['sf_custom'] ) ) {
		return;
	}
	if ( $order instanceof WC_Order ) {
		$order->update_meta_data( '_sf_has_custom', 'yes' ); // For the Custom designs list.
	}
	$design = $values['sf_custom'];
	$token  = $design['token'];
	$from   = store_features_custom_location( 'pending', $token );
	$to     = store_features_custom_location( 'orders', $token );
	if ( is_dir( $from['dir'] ) && ! is_dir( $to['dir'] ) ) {
		store_features_custom_prepare_dirs();
		rename( $from['dir'], $to['dir'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
	}
	$item->update_meta_data( '_sf_custom_token', $token );
	$item->update_meta_data( '_sf_design_w', $design['w'] );
	$item->update_meta_data( '_sf_design_h', $design['h'] );
	$item->update_meta_data( __( 'Design', 'store-features' ), $design['summary'] ? $design['summary'] : __( 'Your own design', 'store-features' ) );
}
add_action( 'woocommerce_checkout_create_order_line_item', 'store_features_custom_order_line', 10, 4 );

/**
 * The print file details of a custom order line, for Qikink.
 *
 * @param WC_Order_Item_Product $item Order line.
 * @return array{code: string, print_url: string, mockup_url: string, w: float, h: float}|null
 */
function store_features_custom_item_design( $item ) {
	$token = (string) $item->get_meta( '_sf_custom_token' );
	if ( '' === $token ) {
		return null;
	}
	$loc = store_features_custom_find( $token );
	if ( ! $loc ) {
		return array( 'code' => '', 'print_url' => '', 'mockup_url' => '', 'w' => 0, 'h' => 0 );
	}
	return array(
		'code'       => 'C' . strtoupper( substr( $token, 0, 12 ) ),
		'print_url'  => $loc['url'] . '/print.png',
		'mockup_url' => $loc['url'] . '/mockup.jpg',
		'w'          => (float) $item->get_meta( '_sf_design_w' ),
		'h'          => (float) $item->get_meta( '_sf_design_h' ),
	);
}

/**
 * Does the order have a custom line?
 *
 * @param int|WC_Order $order Order.
 * @return bool
 */
function store_features_custom_order_has_design( $order ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
	if ( ! $order ) {
		return false;
	}
	foreach ( $order->get_items() as $item ) {
		if ( '' !== (string) $item->get_meta( '_sf_custom_token' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Hold a custom order for a person to check before it goes to Qikink.
 *
 * @param int $order_id Order ID.
 */
function store_features_custom_hold_for_review( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || in_array( $order->get_meta( '_sf_qikink_status' ), array( 'sent', 'review' ), true ) ) {
		return;
	}
	$order->update_meta_data( '_sf_qikink_status', 'review' );
	$order->add_order_note( 'Custom design: check the design under each product (preview and print file), then choose Order actions → Send to Qikink. It is not sent automatically.' );
	$order->save();
}

/**
 * Order screen: preview and print file links under a custom line.
 *
 * @param int                   $item_id Item ID.
 * @param WC_Order_Item_Product $item    Order line.
 */
function store_features_custom_admin_item( $item_id, $item ) {
	if ( ! $item instanceof WC_Order_Item_Product ) {
		return;
	}
	$design = store_features_custom_item_design( $item );
	if ( ! $design ) {
		return;
	}
	if ( '' === $design['print_url'] ) {
		echo '<p style="color:#b32d2e"><strong>' . esc_html__( 'Design files not found.', 'store-features' ) . '</strong></p>';
		return;
	}
	printf(
		'<div class="sf-custom-admin" style="display:flex;gap:12px;align-items:flex-start;margin-top:8px"><a href="%1$s" target="_blank" rel="noopener"><img src="%1$s" alt="" width="96" style="border:1px solid #ddd;border-radius:4px"></a><p style="margin:0">%2$s<br><a href="%3$s" target="_blank" rel="noopener">%4$s</a> &middot; <a href="%1$s" target="_blank" rel="noopener">%5$s</a></p></div>',
		esc_url( $design['mockup_url'] ),
		esc_html( sprintf( /* translators: 1: width, 2: height in inches. */ __( 'Print size %1$s × %2$s in, front.', 'store-features' ), $design['w'], $design['h'] ) ),
		esc_url( $design['print_url'] ),
		esc_html__( 'Print file (PNG)', 'store-features' ),
		esc_html__( 'Preview', 'store-features' )
	);
}
add_action( 'woocommerce_after_order_itemmeta', 'store_features_custom_admin_item', 10, 2 );

/**
 * Tell the shop owner, in the new order email, that the order waits for them.
 *
 * @param WC_Order $order         Order.
 * @param bool     $sent_to_admin Email goes to the shop.
 */
function store_features_custom_admin_email( $order, $sent_to_admin ) {
	if ( $sent_to_admin && store_features_custom_order_has_design( $order ) ) {
		echo '<p><strong>' . esc_html__( 'This order has a customer\'s own design.', 'store-features' ) . '</strong> '
			. esc_html__( 'Check it on the order screen, then choose Order actions → Send to Qikink.', 'store-features' ) . '</p>';
	}
}
add_action( 'woocommerce_email_order_meta', 'store_features_custom_admin_email', 5, 2 );

/* ------------------------------------------------------------------ product page */

/**
 * Body class on custom product pages.
 *
 * @param array $classes Classes.
 * @return array
 */
function store_features_custom_body_class( $classes ) {
	if ( is_product() && store_features_is_custom_product( get_queried_object_id() ) ) {
		$classes[] = 'sf-custom-product';
	}
	return $classes;
}
add_filter( 'body_class', 'store_features_custom_body_class' );

/**
 * Swap the photo gallery for the design preview, and add the design controls.
 */
function store_features_custom_page_setup() {
	if ( ! is_product() || ! store_features_is_custom_product( get_queried_object_id() ) ) {
		return;
	}
	remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );
	add_action( 'woocommerce_before_single_product_summary', 'store_features_custom_stage', 20 );
	add_action( 'woocommerce_before_add_to_cart_form', 'store_features_custom_panel', 5 );
	add_action( 'woocommerce_before_add_to_cart_button', 'store_features_custom_agree', 5 );
	// Keep the page about the design: no "related products" grid underneath.
	remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
	remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15 );
}
add_action( 'wp', 'store_features_custom_page_setup' );

/**
 * The preview, where the gallery normally is.
 */
function store_features_custom_stage() {
	?>
	<div class="sf-cz-stage woocommerce-product-gallery images">
		<div class="sf-cz-frame">
			<canvas class="sf-cz-canvas" width="1000" height="1250" role="img" aria-label="<?php esc_attr_e( 'Preview of your design', 'store-features' ); ?>"></canvas>
			<p class="sf-cz-empty"><?php esc_html_e( 'Type your text or add a photo to see it here.', 'store-features' ); ?></p>
		</div>
		<p class="sf-cz-hint"><?php esc_html_e( 'Drag the design to move it. The dotted box is the print area.', 'store-features' ); ?></p>
	</div>
	<?php
}

/**
 * Text and photo controls.
 */
function store_features_custom_panel() {
	?>
	<section class="sf-cz-panel" aria-labelledby="sf-cz-title">
		<h2 id="sf-cz-title" class="sf-cz-title"><?php esc_html_e( 'Your design', 'store-features' ); ?></h2>
		<div class="sf-cz-tabs" role="tablist" aria-label="<?php esc_attr_e( 'What to add', 'store-features' ); ?>">
			<button type="button" role="tab" id="sf-cz-tab-text" aria-controls="sf-cz-text" aria-selected="true" data-tab="text"><?php esc_html_e( 'Text', 'store-features' ); ?></button>
			<button type="button" role="tab" id="sf-cz-tab-photo" aria-controls="sf-cz-photo" aria-selected="false" tabindex="-1" data-tab="photo"><?php esc_html_e( 'Photo or logo', 'store-features' ); ?></button>
		</div>

		<div class="sf-cz-pane" id="sf-cz-text" role="tabpanel" aria-labelledby="sf-cz-tab-text">
			<label class="sf-cz-label" for="sf-cz-input"><?php esc_html_e( 'Your text', 'store-features' ); ?></label>
			<textarea id="sf-cz-input" class="sf-cz-input" rows="2" maxlength="60" lang="hi" placeholder="<?php esc_attr_e( 'अपना नाम या कोई लाइन', 'store-features' ); ?>" aria-describedby="sf-cz-input-help"></textarea>
			<p class="sf-cz-help" id="sf-cz-input-help"><?php esc_html_e( 'Hindi or English, up to three lines.', 'store-features' ); ?></p>
			<fieldset class="sf-cz-fonts">
				<legend class="sf-cz-label"><?php esc_html_e( 'Font', 'store-features' ); ?></legend>
				<div class="sf-cz-fontlist"></div>
			</fieldset>
			<fieldset class="sf-cz-inks">
				<legend class="sf-cz-label"><?php esc_html_e( 'Text colour', 'store-features' ); ?></legend>
				<div class="sf-cz-inklist"></div>
			</fieldset>
		</div>

		<div class="sf-cz-pane" id="sf-cz-photo" role="tabpanel" aria-labelledby="sf-cz-tab-photo" hidden>
			<label class="sf-cz-file button">
				<input type="file" class="sf-cz-file-input" accept="image/png,image/jpeg,image/webp">
				<span class="sf-cz-file-text"><?php esc_html_e( 'Choose a photo', 'store-features' ); ?></span>
			</label>
			<p class="sf-cz-quality" aria-live="polite"></p>
			<button type="button" class="sf-cz-remove" hidden><?php esc_html_e( 'Remove photo', 'store-features' ); ?></button>
			<p class="sf-cz-help"><?php esc_html_e( 'JPG or PNG up to 25 MB. A PNG with a transparent background prints best. Only use photos and logos you have the right to print.', 'store-features' ); ?></p>
		</div>

		<div class="sf-cz-adjust" hidden>
			<label class="sf-cz-label" for="sf-cz-size"><span class="sf-cz-size-label"><?php esc_html_e( 'Size', 'store-features' ); ?></span></label>
			<input type="range" id="sf-cz-size" class="sf-cz-size" min="0" max="100" step="1">
			<div class="sf-cz-move" role="group" aria-label="<?php esc_attr_e( 'Move the design', 'store-features' ); ?>">
				<button type="button" data-move="up" aria-label="<?php esc_attr_e( 'Move up', 'store-features' ); ?>"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
				<button type="button" data-move="down" aria-label="<?php esc_attr_e( 'Move down', 'store-features' ); ?>"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 5v14M6 13l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
				<button type="button" data-move="left" aria-label="<?php esc_attr_e( 'Move left', 'store-features' ); ?>"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
				<button type="button" data-move="right" aria-label="<?php esc_attr_e( 'Move right', 'store-features' ); ?>"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
				<button type="button" data-move="center" class="sf-cz-center"><?php esc_html_e( 'Centre', 'store-features' ); ?></button>
			</div>
			<p class="sf-cz-printsize" aria-live="polite"></p>
		</div>
	</section>
	<?php
}

/**
 * The "I've checked my design" box, just above Add to cart.
 */
function store_features_custom_agree() {
	?>
	<div class="sf-cz-agree-wrap">
		<label class="sf-cz-agree"><input type="checkbox" name="sf_agree" value="1" class="sf-cz-agree-input"> <span><?php esc_html_e( 'I have checked my design. It is made just for me, so it can\'t be returned or exchanged.', 'store-features' ); ?></span></label>
		<p class="sf-cz-terms"><?php esc_html_e( 'Online payment only. If it arrives damaged or printed wrong, we replace it free.', 'store-features' ); ?></p>
		<p class="sf-cz-error" role="alert" hidden></p>
	</div>
	<?php
}

/**
 * Scripts, styles and settings for the customizer.
 */
function store_features_custom_assets() {
	if ( ! is_product() ) {
		return;
	}
	$product = wc_get_product( get_queried_object_id() );
	if ( ! store_features_is_custom_product( $product ) ) {
		return;
	}
	$style = store_features_custom_style( $product );
	if ( ! $style ) {
		return;
	}
	$config  = store_features_custom_config();
	$base    = plugins_url( 'assets/custom/', STORE_FEATURES_DIR . 'store-features.php' );
	$fonts   = store_features_custom_fonts();
	$queries = array();
	foreach ( $fonts as $font ) {
		$queries[] = 'family=' . str_replace( ' ', '+', $font['family'] ) . ( 400 !== $font['weight'] ? ':wght@' . $font['weight'] : '' );
	}
	sort( $queries );

	wp_enqueue_style( 'store-features-custom-fonts', 'https://fonts.googleapis.com/css2?' . implode( '&', $queries ) . '&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	$dir = STORE_FEATURES_DIR . 'assets/custom/';
	wp_enqueue_style( 'store-features-custom', $base . 'customizer.css', array(), (string) filemtime( $dir . 'customizer.css' ) );
	wp_enqueue_script( 'store-features-custom', $base . 'customizer.js', array( 'jquery', 'wc-add-to-cart-variation' ), (string) filemtime( $dir . 'customizer.js' ), true );

	$colours = array();
	foreach ( $style['colours'] as $slug ) {
		if ( isset( $config['colours'][ $slug ] ) ) {
			$colours[ $slug ] = $config['colours'][ $slug ];
		}
	}
	$defaults = $product->is_type( 'variable' ) ? $product->get_default_attributes() : array();

	// Photo blanks (made from Qikink's garment photos) when they exist, else the drawn ones.
	$photo = function_exists( 'store_features_photo_blank_area' ) ? store_features_photo_blank_area( $style['code'], $style['colours'][0] ?? 'black' ) : null;

	wp_localize_script(
		'store-features-custom',
		'sfCustom',
		array(
			'style'        => $style['code'],
			'area'         => array(
				'x'   => $photo ? $photo['x'] : $style['x'],
				'y'   => $photo ? $photo['y'] : $style['y'],
				'ppi' => $photo ? $photo['ppi'] : $style['ppi'],
				'w'   => $style['w_in'],
				'h'   => $style['h_in'],
			),
			'blankUrl'     => $photo ? $photo['url'] : $base . 'blanks/' . $style['code'] . '-',
			'colours'      => $colours,
			'colour'       => isset( $defaults['pa_color'] ) ? $defaults['pa_color'] : ( $style['colours'][0] ?? 'black' ),
			'fonts'        => $fonts,
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'sf-custom' ),
			'chunk'        => STORE_FEATURES_CUSTOM_CHUNK,
			'addToCartUrl' => WC_AJAX::get_endpoint( 'add_to_cart' ),
			'cartUrl'      => wc_get_cart_url(),
			'i18n'         => array(
				'needDesign'  => __( 'Add your text or a photo first.', 'store-features' ),
				'needAgree'   => __( 'Tick the box to confirm you have checked your design.', 'store-features' ),
				'needOptions' => __( 'Choose a colour and size first.', 'store-features' ),
				'preparing'   => __( 'Preparing your print…', 'store-features' ),
				'uploading'   => __( 'Uploading %d%%…', 'store-features' ),
				'failed'      => __( 'That did not work. Check your connection and try again.', 'store-features' ),
				'cartFailed'  => __( 'Could not add to cart. Check the colour and size and try again.', 'store-features' ),
				'printSize'   => __( 'Print size %1$s × %2$s in', 'store-features' ),
				'textSize'    => __( 'Text size', 'store-features' ),
				'photoSize'   => __( 'Photo size', 'store-features' ),
				'qGood'       => __( 'Sharp: this photo will print well.', 'store-features' ),
				'qOk'         => __( 'Good: fine for printing at this size.', 'store-features' ),
				'qLow'        => __( 'Low resolution: it may print blurry at this size. Make it smaller or use a larger photo.', 'store-features' ),
				'tooBig'      => __( 'That file is over 25 MB. Choose a smaller one.', 'store-features' ),
				'notImage'    => __( 'That file could not be opened. Choose a JPG or PNG.', 'store-features' ),
				'change'      => __( 'Change photo', 'store-features' ),
				'choose'      => __( 'Choose a photo', 'store-features' ),
				'textPreview' => __( 'Text: %s', 'store-features' ),
				'photoLabel'  => __( 'Photo', 'store-features' ),
				'previewOf'   => __( 'Preview of your design on the %s garment', 'store-features' ),
				'showPreview' => __( 'Show the preview', 'store-features' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'store_features_custom_assets', 40 );

/* ------------------------------------------------------------------ setup */

/**
 * One-time setup: colour terms used by the custom products (with their swatch colour).
 */
function store_features_custom_setup() {
	if ( '2' === get_option( 'store_features_custom_setup' ) || ! taxonomy_exists( 'pa_color' ) ) {
		return;
	}
	$config = store_features_custom_config();
	foreach ( (array) ( $config['colours'] ?? array() ) as $slug => $colour ) {
		$term = get_term_by( 'slug', $slug, 'pa_color' );
		if ( ! $term ) {
			$made = wp_insert_term( $colour['label'], 'pa_color', array( 'slug' => $slug ) );
			if ( is_wp_error( $made ) ) {
				continue;
			}
			$term_id = (int) $made['term_id'];
		} else {
			$term_id = (int) $term->term_id;
		}
		if ( '' === (string) get_term_meta( $term_id, 'cfvsw_color', true ) ) {
			update_term_meta( $term_id, 'cfvsw_color', $colour['hex'] );
		}
	}
	update_option( 'store_features_custom_setup', '2', false );
}
add_action( 'admin_init', 'store_features_custom_setup' );

/* ------------------------------------------------------------ theme wording */

/**
 * Replace text inside the theme's saved settings (Customizer), recursively.
 *
 * @param mixed    $value Setting value.
 * @param callable $fix   Gets a string, returns the new string.
 * @return mixed
 */
function store_features_custom_fix_strings( $value, $fix ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $key => $item ) {
			$value[ $key ] = store_features_custom_fix_strings( $item, $fix );
		}
		return $value;
	}
	return is_string( $value ) ? $fix( $value ) : $value;
}

/**
 * On custom product pages, the theme's "cash on delivery" line under Add to cart becomes
 * "paid online".
 *
 * @param mixed $mods Theme settings.
 * @return mixed
 */
function store_features_custom_theme_wording( $mods ) {
	if ( is_admin() || ! function_exists( 'is_product' ) || ! did_action( 'wp' ) || ! is_product() || ! store_features_is_custom_product( get_queried_object_id() ) ) {
		return $mods;
	}
	return store_features_custom_fix_strings(
		$mods,
		function ( $text ) {
			return false !== stripos( $text, 'cash on delivery' ) && strlen( $text ) < 120
				? __( 'Free shipping across India. Paid online.', 'store-features' )
				: $text;
		}
	);
}
add_filter( 'option_theme_mods_' . get_option( 'stylesheet' ), 'store_features_custom_theme_wording' );

/**
 * IDs of the "Design your own" products.
 *
 * @return int[]
 */
function store_features_custom_product_ids() {
	static $ids = null;
	if ( null === $ids ) {
		$ids = array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => 50,
					'meta_key'       => '_sf_custom', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}
	return $ids;
}

/**
 * Ready-made products don't suggest the "Design your own" blanks as related products.
 *
 * @param int[] $related Related product IDs.
 * @return int[]
 */
function store_features_custom_not_related( $related ) {
	return array_values( array_diff( array_map( 'intval', (array) $related ), store_features_custom_product_ids() ) );
}
add_filter( 'woocommerce_related_products', 'store_features_custom_not_related' );
