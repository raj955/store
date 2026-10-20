<?php
/**
 * Email delivery: easier sign-up, a record of failed emails, and a test.
 *
 * - Customers choose their own password when they register, so they can shop straight away
 *   instead of waiting for a "set your password" email.
 * - A simpler password rule (WooCommerce's default demands a "medium" password, which blocks
 *   many people at checkout and sign-up).
 * - Every email WordPress fails to send is written to WooCommerce → Status → Logs (source
 *   store-features-mail), with the reason.
 * - Admin only: POST /wp-json/store-features/v1/mail-test { to } sends a short test email and
 *   returns what the mail system said.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customers pick their own password at sign-up (set once, on first load of the admin).
 */
add_action(
	'admin_init',
	function () {
		if ( '1' === get_option( 'store_features_signup_v1' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		update_option( 'store_features_signup_v1', '1', false );
		update_option( 'woocommerce_registration_generate_password', 'no' );
		update_option( 'woocommerce_registration_generate_username', 'yes' );
	}
);

/**
 * A weak-but-not-trivial password is enough (WooCommerce: 0 very weak … 4 strong; default 3).
 *
 * @return int
 */
function store_features_min_password_strength() {
	return 1;
}
add_filter( 'woocommerce_min_password_strength', 'store_features_min_password_strength' );

/**
 * One sender for every email (WordPress's own "wordpress@…" ones too): the WooCommerce sender,
 * "Panuwear <orders@panuwear.com>". The domain's SPF and DKIM records make Gmail trust it.
 *
 * @param string $from Address.
 * @return string
 */
function store_features_mail_from( $from ) {
	$woo = sanitize_email( (string) get_option( 'woocommerce_email_from_address', '' ) );
	return ( $woo && 0 === strpos( $from, 'wordpress@' ) ) ? $woo : $from;
}
add_filter( 'wp_mail_from', 'store_features_mail_from' );

/**
 * Sender name for WordPress's own emails.
 *
 * @param string $name Name.
 * @return string
 */
function store_features_mail_from_name( $name ) {
	return 'WordPress' === $name ? wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) : $name;
}
add_filter( 'wp_mail_from_name', 'store_features_mail_from_name' );

/**
 * Write failed emails to the WooCommerce log.
 *
 * @param WP_Error $error Error.
 */
function store_features_mail_failed( $error ) {
	if ( ! function_exists( 'wc_get_logger' ) ) {
		return;
	}
	$data = $error->get_error_data();
	$to   = isset( $data['to'] ) ? implode( ', ', (array) $data['to'] ) : '';
	wc_get_logger()->error(
		sprintf( 'Email to %s not sent: %s (subject: %s)', $to, $error->get_error_message(), $data['subject'] ?? '' ),
		array( 'source' => 'store-features-mail' )
	);
}
add_action( 'wp_mail_failed', 'store_features_mail_failed' );

/**
 * Which way mail leaves this site (filled while sending).
 *
 * @var array
 */
$GLOBALS['store_features_mail_probe'] = array();

add_action(
	'phpmailer_init',
	function ( $phpmailer ) {
		$GLOBALS['store_features_mail_probe'] = array(
			'mailer'   => $phpmailer->Mailer,
			'host'     => $phpmailer->Host,
			'from'     => $phpmailer->From,
			'sendmail' => $phpmailer->Sendmail,
		);
	},
	999
);

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'store-features/v1',
			'/mail-test',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => function ( WP_REST_Request $request ) {
					$to = sanitize_email( (string) $request['to'] );
					if ( ! $to ) {
						return new WP_Error( 'sf_mail', 'Give an address in "to".', array( 'status' => 400 ) );
					}
					$failed = null;
					$catch  = function ( $error ) use ( &$failed ) {
						$failed = $error->get_error_message();
					};
					add_action( 'wp_mail_failed', $catch );
					$sent = wp_mail(
						$to,
						get_bloginfo( 'name' ) . ': test email',
						"This is a test from your store's email settings. If you can read this, emails from the store reach this inbox.\n\n" . home_url( '/' )
					);
					remove_action( 'wp_mail_failed', $catch );
					global $phpmailer;
					return rest_ensure_response(
						array(
							'sent'         => (bool) $sent,
							'error'        => $failed,
							'phpmailer'    => isset( $phpmailer ) && is_object( $phpmailer ) ? $phpmailer->ErrorInfo : null,
							'probe'        => $GLOBALS['store_features_mail_probe'],
							'from_setting' => get_option( 'woocommerce_email_from_address' ),
							'php_mail'     => function_exists( 'mail' ),
							'sendmail_ini' => ini_get( 'sendmail_path' ),
						)
					);
				},
			)
		);
	}
);
