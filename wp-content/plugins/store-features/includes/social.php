<?php
/**
 * Automatic Instagram + Facebook posting.
 *
 * The reels are made outside the site (marketing/ in the repo, GitHub Actions) and listed in
 * social/queue.json: id, post_at, caption, hashtags, video_url, cover_url. Every 15 minutes the
 * site posts whatever is due:
 * - Instagram: a Reel (also shown in the feed) on the Instagram business account.
 * - Facebook: a video on the Facebook Page.
 *
 * Setup (once): WooCommerce → Social posting, paste a Page access token. The page and the
 * Instagram account linked to it are found from the token. The token is stored in the database
 * only (not autoloaded) and never shown again.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

const STORE_FEATURES_SOCIAL_GRAPH = 'https://graph.facebook.com/v21.0/';

/**
 * Settings: token, page id/name, instagram id/username.
 *
 * @return array
 */
function store_features_social_settings() {
	$s = get_option( 'store_features_social', array() );
	return is_array( $s ) ? $s : array();
}

/**
 * Posts waiting in social/queue.json.
 *
 * @return array[]
 */
function store_features_social_queue() {
	$file = STORE_FEATURES_DIR . 'social/queue.json';
	if ( ! is_readable( $file ) ) {
		return array();
	}
	$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return isset( $data['posts'] ) && is_array( $data['posts'] ) ? $data['posts'] : array();
}

/**
 * What happened to each post.
 *
 * @return array
 */
function store_features_social_state() {
	$s = get_option( 'store_features_social_state', array() );
	return is_array( $s ) ? $s : array();
}

/**
 * Save one post's state.
 *
 * @param string $id   Post id.
 * @param string $net  ig|fb.
 * @param array  $data State.
 */
function store_features_social_set( $id, $net, $data ) {
	$all                = store_features_social_state();
	$all[ $id ][ $net ] = array_merge( $all[ $id ][ $net ] ?? array(), $data, array( 'at' => gmdate( 'c' ) ) );
	update_option( 'store_features_social_state', $all, false );
}

/**
 * Call the Graph API.
 *
 * @param string $method GET|POST.
 * @param string $path   Path after the version.
 * @param array  $args   Parameters (access_token added).
 * @param string $base   API base.
 * @return array Decoded body, or array( 'error' => array( 'message' => ... ) ).
 */
function store_features_social_api( $method, $path, $args = array(), $base = STORE_FEATURES_SOCIAL_GRAPH ) {
	$s = store_features_social_settings();
	if ( empty( $args['access_token'] ) ) {
		$args['access_token'] = $s['token'] ?? '';
	}
	$url = $base . ltrim( $path, '/' );
	$res = 'GET' === $method
		? wp_remote_get( add_query_arg( array_map( 'rawurlencode', $args ), $url ), array( 'timeout' => 45 ) )
		: wp_remote_post( $url, array( 'timeout' => 90, 'body' => $args ) );
	if ( is_wp_error( $res ) ) {
		return array( 'error' => array( 'message' => $res->get_error_message() ) );
	}
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $body ) ) {
		return array( 'error' => array( 'message' => 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) );
	}
	if ( isset( $body['error']['code'] ) && 190 === (int) $body['error']['code'] ) {
		store_features_social_token_problem( (string) $body['error']['message'] );
	}
	return $body;
}

/**
 * The token stopped working: remember it and email the business address once a day.
 *
 * @param string $message Error.
 */
function store_features_social_token_problem( $message ) {
	update_option( 'store_features_social_token_error', $message, false );
	if ( get_transient( 'store_features_social_token_mail' ) ) {
		return;
	}
	set_transient( 'store_features_social_token_mail', 1, DAY_IN_SECONDS );
	$to = defined( 'STORE_FEATURES_BUSINESS_EMAIL' ) ? STORE_FEATURES_BUSINESS_EMAIL : get_option( 'admin_email' );
	wp_mail( $to, 'Panuwear: Instagram/Facebook posting is paused', "Automatic posting stopped because Facebook no longer accepts the saved token:\n\n" . $message . "\n\nOpen WooCommerce → Social posting on the website and paste a new Page access token." );
}

/**
 * Caption with hashtags.
 *
 * @param array $p Post.
 * @return string
 */
function store_features_social_caption( $p ) {
	$tags = implode( ' ', array_map( 'sanitize_text_field', (array) ( $p['hashtags'] ?? array() ) ) );
	return trim( (string) ( $p['caption'] ?? '' ) . ( $tags ? "\n\n" . $tags : '' ) );
}

/**
 * The reel files are only for Instagram and Facebook to fetch: delete each one from the server as
 * soon as it is posted everywhere it should go, and any file older than 7 days in any case.
 */
function store_features_social_cleanup() {
	$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'sf-social';
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$s     = store_features_social_settings();
	$state = store_features_social_state();
	$done  = array();
	foreach ( store_features_social_queue() as $p ) {
		$st     = $state[ $p['id'] ?? '' ] ?? array();
		$ig_ok  = empty( $s['ig_id'] ) || ! empty( $st['ig']['media_id'] );
		$fb_ok  = empty( $s['page_id'] ) || ! empty( $st['fb']['video_id'] );
		$posted = ( ! empty( $s['ig_id'] ) || ! empty( $s['page_id'] ) ) && $ig_ok && $fb_ok;
		if ( $posted ) {
			foreach ( array( 'video_url', 'cover_url' ) as $k ) {
				if ( ! empty( $p[ $k ] ) ) {
					$done[ basename( (string) wp_parse_url( $p[ $k ], PHP_URL_PATH ) ) ] = true;
				}
			}
		}
	}
	foreach ( (array) glob( $dir . '/*.{mp4,jpg}', GLOB_BRACE ) as $file ) {
		if ( isset( $done[ basename( $file ) ] ) || filemtime( $file ) < time() - WEEK_IN_SECONDS ) {
			wp_delete_file( $file );
		}
	}
}

/**
 * Post what is due (runs every 15 minutes).
 */
function store_features_social_run() {
	$s = store_features_social_settings();
	store_features_social_cleanup();
	if ( empty( $s['token'] ) || get_transient( 'store_features_social_lock' ) ) {
		return;
	}
	set_transient( 'store_features_social_lock', 1, 10 * MINUTE_IN_SECONDS );
	$state = store_features_social_state();
	$now   = time();
	foreach ( store_features_social_queue() as $p ) {
		$id  = (string) ( $p['id'] ?? '' );
		$due = strtotime( (string) ( $p['post_at'] ?? '' ) );
		if ( '' === $id || ! $due || $due > $now || $now - $due > 2 * DAY_IN_SECONDS || empty( $p['video_url'] ) ) {
			continue;
		}
		$st = $state[ $id ] ?? array();

		// Instagram: create the Reel container, wait until Instagram has processed it, publish.
		if ( ! empty( $s['ig_id'] ) && empty( $st['ig']['media_id'] ) && ( $st['ig']['tries'] ?? 0 ) < 6 ) {
			$tries = (int) ( $st['ig']['tries'] ?? 0 );
			if ( empty( $st['ig']['container'] ) ) {
				$args  = array(
					'media_type'    => 'REELS',
					'video_url'     => $p['video_url'],
					'cover_url'     => $p['cover_url'] ?? '',
					'caption'       => store_features_social_caption( $p ),
					'share_to_feed' => 'true',
				);
				$extra = array();
				$tags  = store_features_social_tags( $s );
				if ( $tags ) {
					$extra['user_tags'] = wp_json_encode(
						array_map(
							function ( $u ) {
								return array( 'username' => $u );
							},
							$tags
						)
					);
				}
				// Trial reel: shown to non-followers first; Instagram makes it a normal reel if it does well.
				if ( ! isset( $s['trial'] ) || ! empty( $s['trial'] ) ) {
					$extra['trial_params'] = wp_json_encode( array( 'graduation_strategy' => 'SS_PERFORMANCE' ) );
				}
				$r    = store_features_social_api( 'POST', $s['ig_id'] . '/media', $args + $extra );
				$note = implode( ' + ', array_filter( array( isset( $extra['trial_params'] ) ? 'trial' : '', $tags ? 'tagged' : '' ) ) );
				if ( ! isset( $r['id'] ) && isset( $extra['trial_params'] ) ) {
					// Trial reels not available for this account yet: post it as a normal reel.
					unset( $extra['trial_params'] );
					$r    = store_features_social_api( 'POST', $s['ig_id'] . '/media', $args + $extra );
					$note = $tags ? 'tagged, trial not available' : 'trial not available';
				}
				if ( ! isset( $r['id'] ) && $extra ) {
					$r    = store_features_social_api( 'POST', $s['ig_id'] . '/media', $args );
					$note = 'posted without tag';
				}
				store_features_social_set( $id, 'ig', isset( $r['id'] ) ? array( 'container' => $r['id'], 'error' => '', 'note' => $note ) : array( 'error' => $r['error']['message'] ?? 'unknown', 'tries' => $tries + 1 ) );
			} else {
				$c      = store_features_social_api( 'GET', $st['ig']['container'], array( 'fields' => 'status_code,status' ) );
				$status = $c['status_code'] ?? '';
				if ( 'FINISHED' === $status ) {
					$r = store_features_social_api( 'POST', $s['ig_id'] . '/media_publish', array( 'creation_id' => $st['ig']['container'] ) );
					store_features_social_set( $id, 'ig', isset( $r['id'] ) ? array( 'media_id' => $r['id'], 'error' => '' ) : array( 'error' => $r['error']['message'] ?? 'unknown', 'tries' => $tries + 1 ) );
				} elseif ( 'ERROR' === $status || 'EXPIRED' === $status ) {
					store_features_social_set( $id, 'ig', array( 'container' => '', 'error' => (string) ( $c['status'] ?? $status ), 'tries' => $tries + 1 ) );
				}
			}
		}

		// Facebook Page: one call with the video URL.
		if ( ! empty( $s['page_id'] ) && empty( $st['fb']['video_id'] ) && ( $st['fb']['tries'] ?? 0 ) < 3 ) {
			$r = store_features_social_api(
				'POST',
				$s['page_id'] . '/videos',
				array(
					'file_url'    => $p['video_url'],
					'description' => store_features_social_caption( $p ) . "\n\n" . ( $p['link'] ?? home_url( '/' ) ),
				),
				'https://graph-video.facebook.com/v21.0/'
			);
			store_features_social_set( $id, 'fb', isset( $r['id'] ) ? array( 'video_id' => $r['id'], 'error' => '' ) : array( 'error' => $r['error']['message'] ?? 'unknown', 'tries' => (int) ( $st['fb']['tries'] ?? 0 ) + 1 ) );
		}
	}
	delete_transient( 'store_features_social_lock' );
}
add_action( 'store_features_social_run', 'store_features_social_run' );

add_action(
	'init',
	function () {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'store_features_social_run', array(), 'store-features' ) ) {
			as_schedule_recurring_action( time() + 60, 15 * MINUTE_IN_SECONDS, 'store_features_social_run', array(), 'store-features' );
		}
	}
);

/* ---------------------------------------------------------------- admin page */

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'woocommerce', 'Social posting', 'Social posting', 'manage_woocommerce', 'sf-social', 'store_features_social_page' );
	},
	70
);

/**
 * Save the token: find the Page and its Instagram account from it.
 */
add_action(
	'admin_post_sf_social_save',
	function () {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'sf_social' ) ) {
			wp_die( 'Not allowed' );
		}
		$token = trim( (string) wp_unslash( $_POST['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$msg   = '';
		if ( '' !== $token ) {
			$token = preg_replace( '/[^A-Za-z0-9_\-|.]/', '', $token );
			// A user token lists the Pages it manages (each with its own Page token); a Page token is the Page.
			$page = null;
			$acc  = store_features_social_api( 'GET', 'me/accounts', array( 'fields' => 'id,name,access_token,instagram_business_account{id,username}', 'access_token' => $token ) );
			foreach ( (array) ( $acc['data'] ?? array() ) as $a ) {
				if ( ! $page || ( empty( $page['instagram_business_account'] ) && ! empty( $a['instagram_business_account'] ) ) ) {
					$page = $a;
				}
			}
			if ( $page && ! empty( $page['access_token'] ) ) {
				$token = $page['access_token'];
			}
			if ( ! $page ) {
				$me   = store_features_social_api( 'GET', 'me', array( 'fields' => 'id,name,instagram_business_account{id,username}', 'access_token' => $token ) );
				$page = isset( $me['id'] ) ? $me : null;
			}
			$me = $me ?? array();
			if ( $page && empty( $page['error'] ) ) {
				update_option(
					'store_features_social',
					array(
						'token'     => $token,
						'page_id'   => (string) $page['id'],
						'page_name' => (string) ( $page['name'] ?? '' ),
						'ig_id'     => (string) ( $page['instagram_business_account']['id'] ?? '' ),
						'ig_name'   => (string) ( $page['instagram_business_account']['username'] ?? '' ),
						'saved'     => gmdate( 'c' ),
					),
					false
				);
				delete_option( 'store_features_social_token_error' );
				$msg = 'saved';
			} else {
				$msg = 'error:' . ( $me['error']['message'] ?? $acc['error']['message'] ?? 'Token not accepted' );
			}
		}
		if ( isset( $_POST['tag'] ) ) {
			$cur          = store_features_social_settings();
			$cur['tag']   = sanitize_text_field( wp_unslash( $_POST['tag'] ) );
			$cur['trial'] = empty( $_POST['trial'] ) ? 0 : 1;
			update_option( 'store_features_social', $cur, false );
			$msg = $msg ? $msg : 'options';
		}
		if ( ! empty( $_POST['run_now'] ) ) {
			store_features_social_run();
			$msg = $msg ? $msg : 'ran';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'sf-social', 'm' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}
);

/**
 * Instagram usernames to tag in every reel.
 *
 * @param array $s Settings.
 * @return string[]
 */
function store_features_social_tags( $s ) {
	$out = array();
	foreach ( preg_split( '/[\s,]+/', (string) ( $s['tag'] ?? '' ) ) as $u ) {
		$u = preg_replace( '/[^a-z0-9._]/', '', strtolower( ltrim( $u, '@' ) ) );
		if ( '' !== $u ) {
			$out[] = $u;
		}
	}
	return array_slice( array_unique( $out ), 0, 5 );
}

/**
 * The page.
 */
function store_features_social_page() {
	$s     = store_features_social_settings();
	$state = store_features_social_state();
	$m     = isset( $_GET['m'] ) ? sanitize_text_field( wp_unslash( $_GET['m'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	echo '<div class="wrap"><h1>Social posting (Instagram + Facebook)</h1>';
	if ( 'saved' === $m ) {
		echo '<div class="notice notice-success"><p>Connected.</p></div>';
	} elseif ( 'options' === $m ) {
		echo '<div class="notice notice-success"><p>Saved.</p></div>';
	} elseif ( 0 === strpos( $m, 'error:' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( substr( $m, 6 ) ) . '</p></div>';
	}
	$err = get_option( 'store_features_social_token_error' );
	if ( $err ) {
		echo '<div class="notice notice-error"><p>Posting is paused: ' . esc_html( $err ) . ' Paste a new token below.</p></div>';
	}
	echo '<p><strong>Facebook Page:</strong> ' . esc_html( $s['page_name'] ?? 'not connected' ) . ' &nbsp; <strong>Instagram:</strong> ' . esc_html( ! empty( $s['ig_name'] ) ? '@' . $s['ig_name'] : 'not connected' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'sf_social' );
	echo '<input type="hidden" name="action" value="sf_social_save">';
	echo '<p><label>Page access token<br><input type="password" name="token" autocomplete="off" style="width:560px" placeholder="' . esc_attr( empty( $s['token'] ) ? 'Paste the token here' : 'Saved. Paste a new one only to replace it.' ) . '"></label></p>';
	echo '<p><label>Tag in every reel (Instagram username)<br><input type="text" name="tag" value="' . esc_attr( (string) ( $s['tag'] ?? '' ) ) . '" placeholder="@yourname" style="width:300px"></label></p>';
	echo '<p><label><input type="checkbox" name="trial" value="1"' . checked( ! isset( $s['trial'] ) || ! empty( $s['trial'] ), true, false ) . '> Instagram trial reels: new reels go to non-followers first, and Instagram shares them with followers automatically when they do well</label></p>';
	echo '<p><button class="button button-primary">Save and connect</button> <button class="button" name="run_now" value="1">Post anything due now</button></p></form>';
	echo '<h2>Posts</h2><table class="widefat striped"><thead><tr><th>Time (IST)</th><th>Post</th><th>Instagram</th><th>Facebook</th><th>Video</th></tr></thead><tbody>';
	foreach ( store_features_social_queue() as $p ) {
		$st   = $state[ $p['id'] ] ?? array();
		$cell = function ( $x, $key ) {
			if ( ! empty( $x[ $key ] ) ) {
				return '✅ posted' . ( ! empty( $x['note'] ) ? ' (' . esc_html( $x['note'] ) . ')' : '' );
			}
			if ( ! empty( $x['error'] ) ) {
				return '⚠️ ' . esc_html( $x['error'] );
			}
			return ! empty( $x['container'] ) ? '⏳ processing' : 'waiting';
		};
		$time = strtotime( (string) $p['post_at'] );
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s" target="_blank" rel="noopener">watch</a></td></tr>',
			esc_html( $time ? wp_date( 'D j M, g:i a', $time, new DateTimeZone( 'Asia/Kolkata' ) ) : '' ),
			esc_html( (string) $p['product'] ),
			$cell( $st['ig'] ?? array(), 'media_id' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$cell( $st['fb'] ?? array(), 'video_id' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_url( (string) $p['video_url'] )
		);
	}
	echo '</tbody></table></div>';
}
