<?php
/**
 * Order tracking for customers, like the big stores have.
 *
 * - Track page (/track-order/): order number + phone or email, then a step timeline:
 *   Order placed → Printing → Shipped → Delivered, with courier, AWB and a "Track live" button.
 * - Every order email has a "Track your order" button that opens the timeline directly
 *   (secured by the order's secret key, no login needed).
 * - My account → Orders: a "Track" button on every order; the order page shows the timeline.
 * - Admin (order edit screen): a "Shipping / tracking" box. Paste the courier and AWB from the
 *   Qikink dashboard and save: the customer gets an email with the live tracking link.
 *   If Qikink writes an AWB or tracking link into the order notes, it is picked up by itself.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * Couriers Qikink ships with, and their public tracking pages.
 *
 * @return array slug => array( name, url with %s for the AWB )
 */
function store_features_couriers() {
	return array(
		'delhivery'  => array( 'Delhivery', 'https://www.delhivery.com/track-v2/package/%s' ),
		'xpressbees' => array( 'Xpressbees', 'https://www.xpressbees.com/shipment/tracking?awbNo=%s' ),
		'bluedart'   => array( 'Blue Dart', 'https://www.bluedart.com/web/guest/trackdartresultthirdparty?trackFor=0&trackNo=%s' ),
		'dtdc'       => array( 'DTDC', 'https://www.dtdc.in/trace.asp?strCnno=%s' ),
		'ekart'      => array( 'Ekart', 'https://ekartlogistics.com/shipmenttrack/%s' ),
		'shadowfax'  => array( 'Shadowfax', 'https://tracker.shadowfax.in/#/track/%s' ),
		'indiapost'  => array( 'India Post', 'https://www.indiapost.gov.in/_layouts/15/dop.portal.tracking/trackconsignment.aspx' ),
		'shiprocket' => array( 'Shiprocket', 'https://shiprocket.co/tracking/%s' ),
		'other'      => array( 'Other courier', '' ),
	);
}

/**
 * Shipping details saved on an order.
 *
 * @param WC_Order $order Order.
 * @return array{courier: string, courier_name: string, awb: string, url: string, delivered: bool}
 */
function store_features_shipment( $order ) {
	$courier  = (string) $order->get_meta( '_sf_courier' );
	$awb      = (string) $order->get_meta( '_sf_awb' );
	$url      = (string) $order->get_meta( '_sf_track_url' );
	$couriers = store_features_couriers();
	if ( '' === $url && '' !== $awb && isset( $couriers[ $courier ] ) && '' !== $couriers[ $courier ][1] ) {
		$url = sprintf( $couriers[ $courier ][1], rawurlencode( $awb ) );
	}
	return array(
		'courier'      => $courier,
		'courier_name' => isset( $couriers[ $courier ] ) && 'other' !== $courier ? $couriers[ $courier ][0] : '',
		'awb'          => $awb,
		'url'          => $url,
		'delivered'    => 'yes' === $order->get_meta( '_sf_delivered' ),
	);
}

/**
 * Link that opens this order's timeline without logging in.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function store_features_track_url( $order ) {
	$page = get_page_by_path( 'track-order' );
	$base = $page ? get_permalink( $page ) : home_url( '/track-order/' );
	return add_query_arg(
		array(
			'o' => $order->get_id(),
			'k' => $order->get_order_key(),
		),
		$base
	);
}

/**
 * The steps and which one the order is on.
 *
 * @param WC_Order $order Order.
 * @return array{steps: array, current: int, stopped: string}
 */
function store_features_order_steps( $order ) {
	$ship    = store_features_shipment( $order );
	$status  = $order->get_status();
	$placed  = $order->get_date_created();
	$steps   = array(
		array( 'Order placed', $placed ? wc_format_datetime( $placed, 'j M' ) : '' ),
		array( 'Printing', 'आपका design छप रहा है' ),
		array( 'Shipped', $ship['awb'] ? trim( $ship['courier_name'] . ' · AWB ' . $ship['awb'], ' ·' ) : 'Courier को दिया जाएगा' ),
		array( 'Delivered', '' ),
	);
	$current = 0;
	if ( in_array( $status, array( 'processing', 'on-hold' ), true ) ) {
		$current = 1;
	}
	if ( $ship['awb'] || 'completed' === $status ) {
		$current = 2;
	}
	if ( $ship['delivered'] ) {
		$current = 3;
	}
	$stopped = '';
	if ( in_array( $status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
		$stopped = array(
			'cancelled' => 'यह order cancel हो चुका है।',
			'refunded'  => 'इस order का refund हो चुका है।',
			'failed'    => 'Payment पूरा नहीं हुआ, इसलिए order नहीं बना। आप दोबारा order कर सकते हैं।',
		)[ $status ];
	} elseif ( 'pending' === $status ) {
		$stopped = 'Payment अभी बाकी है।';
	}
	return array(
		'steps'   => $steps,
		'current' => $current,
		'stopped' => $stopped,
	);
}

/**
 * Timeline HTML.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function store_features_order_timeline( $order ) {
	$data = store_features_order_steps( $order );
	$ship = store_features_shipment( $order );
	ob_start();
	?>
	<section class="sf-track" aria-label="Order status">
		<div class="sf-track__head">
			<p class="sf-track__no">Order #<?php echo esc_html( $order->get_order_number() ); ?></p>
			<?php if ( $ship['url'] && ! $data['stopped'] ) : ?>
				<a class="sf-track__live" href="<?php echo esc_url( $ship['url'] ); ?>" target="_blank" rel="noopener">Live tracking देखो</a>
			<?php endif; ?>
		</div>
		<?php if ( $data['stopped'] ) : ?>
			<p class="sf-track__stopped"><?php echo esc_html( $data['stopped'] ); ?></p>
		<?php else : ?>
			<ol class="sf-track__steps">
				<?php foreach ( $data['steps'] as $i => $step ) : ?>
					<li class="<?php echo esc_attr( $i < $data['current'] ? 'is-done' : ( $i === $data['current'] ? 'is-now' : '' ) ); ?>">
						<span class="sf-track__dot" aria-hidden="true"></span>
						<span class="sf-track__name"><?php echo esc_html( $step[0] ); ?></span>
						<?php if ( '' !== $step[1] && $i <= max( $data['current'], 2 ) ) : ?>
							<span class="sf-track__meta"><?php echo esc_html( $step[1] ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
			<?php if ( $data['current'] < 2 ) : ?>
				<p class="sf-track__note">ज़्यादातर orders 2-3 working days में ship होते हैं और 5-7 working days में पहुँचते हैं। Ship होते ही आपको email पर tracking link आ जाएगा।</p>
			<?php endif; ?>
		<?php endif; ?>
	</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Styles (small, inline, only where the timeline shows).
 *
 * @return string
 */
function store_features_track_css() {
	return '.sf-track{border:1px solid #E3DDD2;border-radius:16px;background:#fff;padding:18px 20px;margin:0 0 24px;color:#17171A}'
		. '.sf-track__head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px}'
		. '.sf-track__no{margin:0;font-weight:800;font-size:17px}'
		. '.sf-track__live{display:inline-block;padding:9px 16px;border-radius:999px;background:#17171A;color:#fff!important;font-weight:700;font-size:14px;text-decoration:none!important}'
		. '.sf-track__steps{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(4,1fr);gap:8px;position:relative}'
		. '.sf-track__steps li{position:relative;display:flex;flex-direction:column;align-items:center;text-align:center;gap:4px;padding-top:2px}'
		. '.sf-track__steps li:not(:last-child)::after{content:"";position:absolute;top:10px;left:calc(50% + 14px);right:calc(-50% + 14px);height:3px;border-radius:2px;background:#E3DDD2}'
		. '.sf-track__steps li.is-done:not(:last-child)::after{background:#1F9D55}'
		. '.sf-track__dot{width:20px;height:20px;border-radius:50%;border:3px solid #E3DDD2;background:#fff;box-sizing:border-box}'
		. '.sf-track__steps li.is-done .sf-track__dot{background:#1F9D55;border-color:#1F9D55}'
		. '.sf-track__steps li.is-now .sf-track__dot{border-color:#FFA41B;background:#FFA41B;box-shadow:0 0 0 5px rgba(255,164,27,.22)}'
		. '.sf-track__name{font-weight:700;font-size:14px}'
		. '.sf-track__meta{font-size:12.5px;color:#5C574E;line-height:1.35}'
		. '.sf-track__note,.sf-track__stopped{margin:14px 0 0;font-size:14px;color:#4A4740}'
		. '.sf-track__stopped{margin:0;font-weight:600}'
		. '@media (max-width:600px){.sf-track__steps{grid-template-columns:1fr;gap:0}.sf-track__steps li{flex-direction:row;flex-wrap:wrap;align-items:center;text-align:left;gap:4px 12px;padding:0 0 18px 0}'
		. '.sf-track__steps li:not(:last-child)::after{top:20px;bottom:0;left:9px;right:auto;width:3px;height:auto}'
		. '.sf-track__meta{flex-basis:100%;padding-left:32px;margin-top:-2px}}'
		. '.sf-track-form{display:grid;gap:12px;max-width:420px}.sf-track-form label{display:grid;gap:6px;font-weight:600}'
		. '.sf-track-form input{padding:12px 14px;border:1px solid #CFC8BB;border-radius:10px;font-size:16px}'
		. '.sf-track-form button{padding:13px 18px;border:0;border-radius:999px;background:#17171A;color:#fff;font-weight:700;font-size:16px;cursor:pointer}'
		. '.sf-track-err{color:#B42318;font-weight:600}';
}

/**
 * The track page: form, or the timeline for a valid order.
 *
 * @return string
 */
function store_features_track_page() {
	$out = '<style>' . store_features_track_css() . '</style>';
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only lookup, protected by the order key / matching contact.
	$id  = isset( $_GET['o'] ) ? absint( $_GET['o'] ) : 0;
	$key = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';
	if ( $id && $key ) {
		$order = wc_get_order( $id );
		if ( $order && hash_equals( $order->get_order_key(), $key ) ) {
			return $out . store_features_order_timeline( $order ) . store_features_track_items( $order );
		}
		$out .= '<p class="sf-track-err">यह link सही नहीं है। नीचे order number डालकर देखें।</p>';
	}

	$error = '';
	if ( isset( $_POST['sf_track_order'] ) ) {
		$error = 'Order नहीं मिला। Order number और वही phone या email डालें जो order में दिया था।';
		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sf_track_nonce'] ?? '' ) ), 'sf_track' ) ) {
			$number  = absint( preg_replace( '/\D/', '', (string) wp_unslash( $_POST['sf_track_order'] ) ) );
			$contact = strtolower( trim( sanitize_text_field( wp_unslash( $_POST['sf_track_contact'] ?? '' ) ) ) );
			$order   = $number ? wc_get_order( $number ) : null;
			if ( $order && $contact && store_features_track_contact_matches( $order, $contact ) ) {
				wp_safe_redirect( store_features_track_url( $order ) );
				exit;
			}
		}
	}
	// phpcs:enable

	ob_start();
	?>
	<p>Order number और order में दिया phone number या email डालो, status तुरंत दिखेगा। Order number आपके order email में है (जैसे #2945)।</p>
	<?php if ( $error ) : ?>
		<p class="sf-track-err"><?php echo esc_html( $error ); ?></p>
	<?php endif; ?>
	<form class="sf-track-form" method="post">
		<label>Order number <input name="sf_track_order" inputmode="numeric" required placeholder="2945"></label>
		<label>Phone या email <input name="sf_track_contact" required placeholder="98XXXXXXXX"></label>
		<?php wp_nonce_field( 'sf_track', 'sf_track_nonce' ); ?>
		<button type="submit">Order track करो</button>
	</form>
	<?php
	return $out . ob_get_clean();
}

/**
 * Does the phone (last 10 digits) or email match the order?
 *
 * @param WC_Order $order   Order.
 * @param string   $contact What the visitor typed.
 * @return bool
 */
function store_features_track_contact_matches( $order, $contact ) {
	if ( false !== strpos( $contact, '@' ) ) {
		return strtolower( (string) $order->get_billing_email() ) === $contact;
	}
	$digits = substr( preg_replace( '/\D/', '', $contact ), -10 );
	$phones = array( $order->get_billing_phone(), method_exists( $order, 'get_shipping_phone' ) ? $order->get_shipping_phone() : '' );
	foreach ( $phones as $phone ) {
		if ( strlen( $digits ) === 10 && substr( preg_replace( '/\D/', '', (string) $phone ), -10 ) === $digits ) {
			return true;
		}
	}
	return false;
}

/**
 * Short list of what was ordered, under the timeline.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function store_features_track_items( $order ) {
	$rows = '';
	foreach ( $order->get_items() as $item ) {
		$rows .= '<li>' . esc_html( $item->get_name() ) . ' × ' . (int) $item->get_quantity() . '</li>';
	}
	return '<div class="sf-track-items"><h3>आपका order</h3><ul>' . $rows . '</ul><p>Total: ' . wp_kses_post( $order->get_formatted_order_total() ) . ' · ' . esc_html( $order->get_payment_method_title() ) . '</p>'
		. '<p>कोई सवाल? <a href="mailto:' . esc_attr( defined( 'STORE_FEATURES_BUSINESS_EMAIL' ) ? STORE_FEATURES_BUSINESS_EMAIL : get_option( 'admin_email' ) ) . '">हमें लिखें</a>।</p></div>';
}

/**
 * Replace WooCommerce's basic tracking shortcode with ours (the Track order page uses it).
 */
add_action(
	'init',
	function () {
		remove_shortcode( 'woocommerce_order_tracking' );
		add_shortcode( 'woocommerce_order_tracking', 'store_features_track_page' );
		add_shortcode( 'sf_track_order', 'store_features_track_page' );
	},
	20
);

/**
 * The form posts to the same page; handle the redirect before any output.
 */
add_action(
	'template_redirect',
	function () {
		if ( isset( $_POST['sf_track_order'] ) && is_page( 'track-order' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked inside.
			ob_start(); // So wp_safe_redirect inside the shortcode can still send headers.
		}
	}
);

/**
 * Timeline at the top of My account → order, and on the thank-you page.
 *
 * @param WC_Order $order Order.
 */
function store_features_timeline_on_order_page( $order ) {
	if ( ! $order instanceof WC_Order || is_admin() ) {
		return;
	}
	echo '<style>' . store_features_track_css() . '</style>' . store_features_order_timeline( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'woocommerce_order_details_before_order_table', 'store_features_timeline_on_order_page', 5 );

/**
 * "Track" button in My account → Orders.
 *
 * @param array    $actions Actions.
 * @param WC_Order $order   Order.
 * @return array
 */
function store_features_track_action( $actions, $order ) {
	$actions = array_merge(
		array(
			'sf_track' => array(
				'url'  => store_features_track_url( $order ),
				'name' => 'Track',
			),
		),
		$actions
	);
	return $actions;
}
add_filter( 'woocommerce_my_account_my_orders_actions', 'store_features_track_action', 10, 2 );

/**
 * "Track your order" button in customer order emails.
 *
 * @param WC_Order $order         Order.
 * @param bool     $sent_to_admin Sent to admin.
 * @param bool     $plain_text    Plain text.
 */
function store_features_track_in_email( $order, $sent_to_admin, $plain_text ) {
	if ( $sent_to_admin || ! $order instanceof WC_Order || in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) {
		return;
	}
	$url  = store_features_track_url( $order );
	$ship = store_features_shipment( $order );
	if ( $plain_text ) {
		echo "\nTrack your order: " . esc_url_raw( $url ) . "\n\n";
		return;
	}
	echo '<p style="margin:0 0 18px"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 22px;border-radius:999px;background:#17171A;color:#ffffff;font-weight:bold;text-decoration:none">Track your order</a></p>';
	if ( $ship['awb'] ) {
		echo '<p style="margin:0 0 18px">' . esc_html( trim( $ship['courier_name'] . ' AWB: ' . $ship['awb'] ) ) . '</p>';
	}
}
add_action( 'woocommerce_email_before_order_table', 'store_features_track_in_email', 5, 3 );

/**
 * Admin: Shipping / tracking box on the order screen.
 */
add_action(
	'add_meta_boxes',
	function () {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'sf-shipping', 'Shipping / tracking', 'store_features_shipping_box', array( $screen, 'shop_order' ), 'side', 'high' );
	}
);

/**
 * Box content.
 *
 * @param WP_Post|WC_Order $object Order or post.
 */
function store_features_shipping_box( $object ) {
	$order = $object instanceof WC_Order ? $object : wc_get_order( $object->ID );
	if ( ! $order ) {
		return;
	}
	$ship = store_features_shipment( $order );
	wp_nonce_field( 'sf_ship', 'sf_ship_nonce' );
	echo '<p><label>Courier<br><select name="sf_courier" style="width:100%">';
	foreach ( store_features_couriers() as $slug => $c ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $slug ), selected( $ship['courier'], $slug, false ), esc_html( $c[0] ) );
	}
	echo '</select></label></p>';
	printf( '<p><label>AWB / tracking number<br><input name="sf_awb" value="%s" style="width:100%%"></label></p>', esc_attr( $ship['awb'] ) );
	printf( '<p><label>Tracking link (optional)<br><input name="sf_track_url" value="%s" style="width:100%%" placeholder="https://"></label></p>', esc_attr( (string) $order->get_meta( '_sf_track_url' ) ) );
	printf( '<p><label><input type="checkbox" name="sf_delivered" value="yes"%s> Delivered</label></p>', checked( $ship['delivered'], true, false ) );
	echo '<p class="description">Qikink dashboard → Orders में AWB दिखता है। यहाँ डालकर Update करो, customer को tracking link वाला email चला जाएगा।</p>';
	printf( '<p><a href="%s" target="_blank">Customer वाला tracking page देखो</a></p>', esc_url( store_features_track_url( $order ) ) );
}

/**
 * Save the box. A new AWB emails the customer (as a WooCommerce customer note).
 *
 * @param int $order_id Order ID.
 */
function store_features_shipping_save( $order_id ) {
	if ( ! isset( $_POST['sf_ship_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sf_ship_nonce'] ) ), 'sf_ship' ) || ! current_user_can( 'edit_shop_orders' ) ) {
		return;
	}
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	$old_awb = (string) $order->get_meta( '_sf_awb' );
	$was_del = 'yes' === $order->get_meta( '_sf_delivered' );
	$courier = sanitize_key( wp_unslash( $_POST['sf_courier'] ?? '' ) );
	$awb     = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) wp_unslash( $_POST['sf_awb'] ?? '' ) );
	$url     = esc_url_raw( wp_unslash( $_POST['sf_track_url'] ?? '' ) );
	$order->update_meta_data( '_sf_courier', isset( store_features_couriers()[ $courier ] ) ? $courier : 'other' );
	$order->update_meta_data( '_sf_awb', $awb );
	$order->update_meta_data( '_sf_track_url', $url );
	$order->update_meta_data( '_sf_delivered', ! empty( $_POST['sf_delivered'] ) ? 'yes' : '' );
	$order->save();
	if ( $awb && $awb !== $old_awb ) {
		store_features_notify_shipped( $order );
	}
	if ( ! $was_del && ! empty( $_POST['sf_delivered'] ) && 'completed' !== $order->get_status() ) {
		$order->update_status( 'completed', 'Marked delivered.' );
	}
}
add_action( 'woocommerce_process_shop_order_meta', 'store_features_shipping_save', 50 );

/**
 * Tell the customer it has shipped (WooCommerce sends customer notes by email).
 *
 * @param WC_Order $order Order.
 */
function store_features_notify_shipped( $order ) {
	$ship = store_features_shipment( $order );
	$note = sprintf(
		"आपका order ship हो गया है! %s\nAWB: %s\nLive tracking: %s\nOrder status: %s",
		$ship['courier_name'] ? 'Courier: ' . $ship['courier_name'] . '.' : '',
		$ship['awb'],
		$ship['url'],
		store_features_track_url( $order )
	);
	$order->add_order_note( $note, true );
}

/**
 * If Qikink (or anyone) writes an AWB or tracking link into an order note, save it.
 *
 * @param int      $note_id Comment ID.
 * @param WC_Order $order   Order.
 */
function store_features_awb_from_note( $note_id, $order ) {
	if ( ! $order instanceof WC_Order || $order->get_meta( '_sf_awb' ) ) {
		return;
	}
	$note = get_comment( $note_id );
	$text = $note ? (string) $note->comment_content : '';
	if ( '' === $text || false !== strpos( $text, 'आपका order ship हो गया है' ) ) {
		return;
	}
	$awb = '';
	if ( preg_match( '/\b(?:AWB|Tracking(?:\s*(?:No|Number|ID))?|Waybill)\s*[:#\-]?\s*([A-Z0-9\-]{8,25})\b/i', $text, $m ) ) {
		$awb = $m[1];
	}
	$url = '';
	if ( preg_match( '~https?://[^\s<>"\']*track[^\s<>"\']*~i', $text, $m ) ) {
		$url = $m[0];
	}
	if ( ! $awb && ! $url ) {
		return;
	}
	$courier = 'other';
	foreach ( store_features_couriers() as $slug => $c ) {
		if ( 'other' !== $slug && false !== stripos( $text . ' ' . $url, str_replace( ' ', '', $c[0] ) ) ) {
			$courier = $slug;
			break;
		}
	}
	$order->update_meta_data( '_sf_courier', $courier );
	$order->update_meta_data( '_sf_awb', $awb );
	$order->update_meta_data( '_sf_track_url', $url );
	$order->save();
	if ( $awb || $url ) {
		store_features_notify_shipped( $order );
	}
}
add_action( 'woocommerce_order_note_added', 'store_features_awb_from_note', 10, 2 );

/**
 * Order emails say "contact us at orders@…" (the sending address, which nobody reads).
 * Point that line at the business mailbox instead.
 *
 * @param string $content Email HTML.
 * @return string
 */
function store_features_contact_address_in_mail( $content ) {
	if ( ! defined( 'STORE_FEATURES_BUSINESS_EMAIL' ) ) {
		return $content;
	}
	$from = (string) get_option( 'woocommerce_email_from_address' );
	if ( '' === $from || STORE_FEATURES_BUSINESS_EMAIL === $from ) {
		return $content;
	}
	return preg_replace( '/(contact us at\s*(?:<a[^>]*>)?)\s*' . preg_quote( $from, '/' ) . '/i', '$1' . STORE_FEATURES_BUSINESS_EMAIL, str_replace( 'mailto:' . $from, 'mailto:' . STORE_FEATURES_BUSINESS_EMAIL, $content ) );
}
add_filter( 'woocommerce_mail_content', 'store_features_contact_address_in_mail', 20 );

/**
 * Track order page without the shortcode in it: show the tracker anyway.
 *
 * @param string $content Page content.
 * @return string
 */
function store_features_track_page_content( $content ) {
	if ( ! is_page( 'track-order' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$post = get_post();
	$raw  = $post ? (string) $post->post_content : '';
	if ( has_shortcode( $raw, 'woocommerce_order_tracking' ) || has_shortcode( $raw, 'sf_track_order' ) ) {
		return $content;
	}
	return store_features_track_page();
}
add_filter( 'the_content', 'store_features_track_page_content', 5 );
