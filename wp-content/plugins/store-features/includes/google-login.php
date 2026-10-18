<?php
/**
 * Sign in with Google for customers.
 *
 * Uses Google's own button (Google Identity Services). Google hands the browser a signed ID token;
 * the server checks it with Google, then logs in the customer with that email, or creates their
 * account. Only the public Client ID is needed: WooCommerce → Settings → Accounts & Privacy →
 * "Sign in with Google". In Google Cloud the OAuth client is a "Web application" whose
 * "Authorised JavaScript origins" list the store's address (https://theonlinefood.com, and the
 * brand domain later).
 *
 * Staff accounts (anyone who can edit the shop) can't sign in this way; they use their password.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Google OAuth Client ID, or '' when the feature is off.
 *
 * @return string
 */
function store_features_google_client_id() {
	$id = trim( (string) get_option( 'store_features_google_client_id', '' ) );
	return preg_match( '/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/', $id ) ? $id : '';
}

/**
 * Setting under WooCommerce → Settings → Accounts & Privacy.
 *
 * @param array $settings Account settings.
 * @return array
 */
function store_features_google_settings( $settings ) {
	$settings[] = array(
		'title' => __( 'Sign in with Google', 'store-features' ),
		'desc'  => __( 'Shows a "Continue with Google" button on the login, register and checkout pages. Paste the Client ID of a Google Cloud OAuth client (type "Web application") whose authorised JavaScript origins include this site\'s address. Leave empty to hide the button.', 'store-features' ),
		'type'  => 'title',
		'id'    => 'store_features_google',
	);
	$settings[] = array(
		'title'       => __( 'Google Client ID', 'store-features' ),
		'id'          => 'store_features_google_client_id',
		'type'        => 'text',
		'default'     => '',
		'placeholder' => '1234567890-abc123.apps.googleusercontent.com',
		'css'         => 'min-width: 420px;',
	);
	$settings[] = array(
		'type' => 'sectionend',
		'id'   => 'store_features_google',
	);
	return $settings;
}
add_filter( 'woocommerce_get_settings_account', 'store_features_google_settings' );

/**
 * The button's placeholder; the script turns it into Google's button.
 *
 * @param string $where account|checkout.
 * @return string
 */
function store_features_google_button_html( $where ) {
	$note = 'checkout' === $where
		? __( 'Have a Google account? Sign in to fill in your details faster, or carry on below as a guest.', 'store-features' )
		: __( 'One tap, no password to remember.', 'store-features' );
	return '<div class="sf-google sf-google--' . esc_attr( $where ) . '">'
		. '<div class="sf-google__button" aria-live="polite"></div>'
		. '<p class="sf-google__note">' . esc_html( $note ) . '</p>'
		. '<p class="sf-google__error" role="alert" hidden></p>'
		. ( 'account' === $where ? '<p class="sf-google__or"><span>' . esc_html__( 'or use your email', 'store-features' ) . '</span></p>' : '' )
		. '</div>';
}

/**
 * Above the login and register forms on My account.
 */
function store_features_google_account_button() {
	if ( store_features_google_client_id() && ! is_user_logged_in() ) {
		echo store_features_google_button_html( 'account' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
add_action( 'woocommerce_before_customer_login_form', 'store_features_google_account_button' );

/**
 * Above the checkout block, for visitors who aren't signed in.
 *
 * @param string $content Block HTML.
 * @return string
 */
function store_features_google_checkout_button( $content ) {
	if ( store_features_google_client_id() && ! is_user_logged_in() ) {
		return store_features_google_button_html( 'checkout' ) . $content;
	}
	return $content;
}
add_filter( 'render_block_woocommerce/checkout', 'store_features_google_checkout_button', 5 );

/**
 * Load Google's script and ours where the button appears.
 */
function store_features_google_assets() {
	$client_id = store_features_google_client_id();
	if ( ! $client_id || is_user_logged_in() || ! ( is_account_page() || is_checkout() ) || is_wc_endpoint_url( 'order-received' ) ) {
		return;
	}
	wp_enqueue_script( 'google-gsi', 'https://accounts.google.com/gsi/client', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_add_inline_script( 'google-gsi', store_features_google_script(), 'after' );
	wp_localize_script(
		'google-gsi',
		'sfGoogle',
		array(
			'clientId' => $client_id,
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'sf-google' ),
			'redirect' => is_checkout() ? wc_get_checkout_url() : wc_get_page_permalink( 'myaccount' ),
			'failed'   => __( 'Google sign-in did not work. Please try again, or use your email.', 'store-features' ),
		)
	);
	wp_add_inline_style( 'store-features', store_features_google_style() );
}
add_action( 'wp_enqueue_scripts', 'store_features_google_assets', 32 );

/**
 * Render Google's button and send its token to the server.
 *
 * @return string
 */
function store_features_google_script() {
	return <<<'JS'
(function () {
	'use strict';
	var cfg = window.sfGoogle;
	var boxes = document.querySelectorAll('.sf-google');
	if (!cfg || !boxes.length || !window.google || !google.accounts || !google.accounts.id) {
		return;
	}
	function showError(msg) {
		boxes.forEach(function (box) {
			var el = box.querySelector('.sf-google__error');
			el.textContent = msg;
			el.hidden = !msg;
		});
	}
	google.accounts.id.initialize({
		client_id: cfg.clientId,
		ux_mode: 'popup',
		context: 'signin',
		callback: function (response) {
			showError('');
			var body = new FormData();
			body.append('action', 'sf_google_login');
			body.append('nonce', cfg.nonce);
			body.append('credential', response.credential);
			fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.success) {
						if (window.sfTrack) {
							window.sfTrack(res.data.created ? 'sign_up' : 'login', { method: 'Google' });
						}
						window.location.href = cfg.redirect;
					} else {
						showError(res && res.data && res.data.message ? res.data.message : cfg.failed);
					}
				})
				.catch(function () { showError(cfg.failed); });
		}
	});
	boxes.forEach(function (box) {
		var slot = box.querySelector('.sf-google__button');
		google.accounts.id.renderButton(slot, {
			type: 'standard',
			theme: 'outline',
			size: 'large',
			text: 'continue_with',
			shape: 'rectangular',
			logo_alignment: 'left',
			width: Math.min(400, Math.max(240, slot.offsetWidth || 320))
		});
	});
})();
JS;
}

/**
 * Styles for the Google box.
 *
 * @return string
 */
function store_features_google_style() {
	return <<<'CSS'
.sf-google { max-width: 420px; margin: 0 0 28px; }
.sf-google__button { min-height: 44px; }
.sf-google__note { margin: 8px 0 0; color: var(--muted, #565D7A); font-size: 14px; }
.sf-google__error { margin: 8px 0 0; color: #A3122F; font-size: 14px; font-weight: 600; }
.sf-google__error[hidden] { display: none; }
.sf-google__or { display: flex; align-items: center; gap: 12px; margin: 22px 0 0; color: var(--muted, #565D7A); font-size: 14px; }
.sf-google__or::before, .sf-google__or::after { content: ""; flex: 1; height: 1px; background: var(--line, #DCDEE6); }
.sf-google--checkout { max-width: none; margin-bottom: 24px; padding: 16px; border: 1px solid var(--line, #DCDEE6); border-radius: var(--radius, 4px); }
CSS;
}

/**
 * Check the ID token with Google, then sign the customer in (or create their account).
 */
function store_features_google_login() {
	if ( ! check_ajax_referer( 'sf-google', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'This page has expired. Reload it and try again.', 'store-features' ) ), 403 );
	}
	$client_id  = store_features_google_client_id();
	$credential = isset( $_POST['credential'] ) ? sanitize_text_field( wp_unslash( $_POST['credential'] ) ) : '';
	if ( ! $client_id || '' === $credential || strlen( $credential ) > 4096 ) {
		wp_send_json_error( array(), 400 );
	}

	$response = wp_remote_get( 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $credential ), array( 'timeout' => 10 ) );
	$claims   = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response )
		? null
		: json_decode( wp_remote_retrieve_body( $response ), true );

	$valid = is_array( $claims )
		&& isset( $claims['aud'], $claims['iss'], $claims['exp'], $claims['email'], $claims['sub'] )
		&& hash_equals( $client_id, (string) $claims['aud'] )
		&& in_array( $claims['iss'], array( 'accounts.google.com', 'https://accounts.google.com' ), true )
		&& (int) $claims['exp'] > time()
		&& in_array( $claims['email_verified'] ?? '', array( true, 'true' ), true )
		&& is_email( $claims['email'] );
	if ( ! $valid ) {
		wp_send_json_error( array(), 401 );
	}

	$email   = sanitize_email( $claims['email'] );
	$user    = get_user_by( 'email', $email );
	$created = false;

	if ( $user ) {
		if ( user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'This account signs in with its password.', 'store-features' ) ), 403 );
		}
		$user_id = (int) $user->ID;
	} else {
		$user_id = wc_create_new_customer(
			$email,
			wc_create_new_customer_username( $email, array( 'first_name' => $claims['given_name'] ?? '', 'last_name' => $claims['family_name'] ?? '' ) ),
			wp_generate_password( 24 ),
			array(
				'first_name' => sanitize_text_field( $claims['given_name'] ?? '' ),
				'last_name'  => sanitize_text_field( $claims['family_name'] ?? '' ),
			)
		);
		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ), 400 );
		}
		$created = true;
	}

	update_user_meta( $user_id, '_sf_google_sub', sanitize_text_field( $claims['sub'] ) );
	wc_set_customer_auth_cookie( $user_id );

	wp_send_json_success( array( 'created' => $created ) );
}
add_action( 'wp_ajax_nopriv_sf_google_login', 'store_features_google_login' );
add_action( 'wp_ajax_sf_google_login', 'store_features_google_login' );
