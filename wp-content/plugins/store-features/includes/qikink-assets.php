<?php
/**
 * Copies Qikink's garment photos (the blank shirts its own mockup tool uses) onto this site,
 * so product photos can be made from them in the browser without cross-site image problems.
 *
 * Admin only. Only files under products.qikink.com/assets/admin/uploads/mockup-<n>/ are fetched.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'store-features/v1',
			'/qikink-asset',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'args'                => array(
					'path' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
				'callback'            => 'store_features_qikink_asset',
			)
		);
	}
);

/**
 * Fetch one Qikink mockup asset into uploads/sf-qikink-assets/ (once) and return its local URL.
 *
 * @param WP_REST_Request $request Request with `path`, e.g. "mockup-3/1723443427front%20color.png".
 * @return WP_REST_Response|WP_Error
 */
function store_features_qikink_asset( $request ) {
	$path = (string) $request['path'];
	if ( ! preg_match( '~^mockup-\d+/[A-Za-z0-9%._ ()-]+\.(png|webp|jpe?g)$~i', $path ) || false !== strpos( $path, '..' ) ) {
		return new WP_Error( 'sf_bad_path', 'Not a Qikink mockup file.', array( 'status' => 400 ) );
	}

	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'sf-qikink-assets';
	$name    = sanitize_file_name( str_replace( array( '/', '%20' ), array( '-', '-' ), $path ) );
	$file    = $dir . '/' . $name;
	$url     = trailingslashit( $uploads['baseurl'] ) . 'sf-qikink-assets/' . rawurlencode( $name );

	if ( file_exists( $file ) && filesize( $file ) > 0 ) {
		return rest_ensure_response( array( 'url' => $url, 'cached' => true ) );
	}

	$remote   = 'https://products.qikink.com/assets/admin/uploads/' . str_replace( ' ', '%20', $path );
	$response = wp_safe_remote_get( $remote, array( 'timeout' => 30 ) );
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'sf_fetch_failed', $response->get_error_message(), array( 'status' => 502 ) );
	}
	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	if ( 200 !== $code || '' === $body || strlen( $body ) > 15 * MB_IN_BYTES ) {
		return new WP_Error( 'sf_fetch_failed', 'Qikink answered ' . $code . '.', array( 'status' => 502 ) );
	}
	$info = function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( $body ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $info ) {
		return new WP_Error( 'sf_not_image', 'That file is not an image.', array( 'status' => 502 ) );
	}

	if ( ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'sf_no_dir', 'Could not create the folder.', array( 'status' => 500 ) );
	}
	if ( ! file_exists( $dir . '/index.php' ) ) {
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	file_put_contents( $file, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	return rest_ensure_response(
		array(
			'url'    => $url,
			'width'  => $info[0],
			'height' => $info[1],
		)
	);
}
