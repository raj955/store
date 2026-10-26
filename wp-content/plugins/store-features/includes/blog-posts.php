<?php
/**
 * Blog posts that arrive with the code: each file in store-features/blog/*.json becomes a
 * published post (once, matched by slug). This lets new posts be published automatically
 * every week without anyone logging in.
 *
 * File: { "slug", "title", "excerpt", "content" (HTML), "tags": [], "products": [product slugs],
 *         "date": "YYYY-MM-DD" (optional) }
 * The first product's photo becomes the post's picture, and the products are shown at the end
 * of the post as "Shop the look".
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * Publish posts from new or changed files.
 */
function store_features_blog_import() {
	$dir   = STORE_FEATURES_DIR . 'blog/';
	$files = glob( $dir . '*.json' );
	if ( ! $files ) {
		return;
	}
	$stamp = md5( 'v2|' . implode( '|', array_map( function ( $f ) { return basename( $f ) . filemtime( $f ); }, $files ) ) );
	if ( get_option( 'store_features_blog_stamp' ) === $stamp ) {
		return;
	}
	if ( get_transient( 'store_features_blog_lock' ) ) {
		return;
	}
	set_transient( 'store_features_blog_lock', 1, 5 * MINUTE_IN_SECONDS );

	// A "Blog" page that lists the posts.
	if ( ! (int) get_option( 'page_for_posts' ) ) {
		$page = get_page_by_path( 'blog' );
		$id   = $page ? $page->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Blog', 'post_name' => 'blog' ) );
		if ( $id && ! is_wp_error( $id ) && 'page' === get_option( 'show_on_front' ) ) {
			update_option( 'page_for_posts', (int) $id );
		}
	}
	$cat = get_term_by( 'slug', 'style-notes', 'category' );
	if ( ! $cat ) {
		$made = wp_insert_term( 'Style notes', 'category', array( 'slug' => 'style-notes' ) );
		$cat  = is_wp_error( $made ) ? null : get_term( $made['term_id'], 'category' );
	}

	foreach ( $files as $file ) {
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $data ) || empty( $data['slug'] ) || empty( $data['title'] ) || empty( $data['content'] ) ) {
			continue;
		}
		$slug     = sanitize_title( $data['slug'] );
		$existing = get_page_by_path( $slug, OBJECT, 'post' );
		$hash     = md5( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $existing && get_post_meta( $existing->ID, '_sf_blog_hash', true ) === $hash ) {
			if ( 'future' === $existing->post_status ) {
				wp_update_post( array( 'ID' => $existing->ID, 'post_status' => 'publish', 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', true ) ) );
			}
			continue;
		}
		$post = array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => wp_strip_all_tags( $data['title'] ),
			'post_excerpt' => wp_strip_all_tags( (string) ( $data['excerpt'] ?? '' ) ),
			'post_content' => wp_kses_post( $data['content'] ),
			'post_author'  => (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 ),
		);
		// A back-dated post keeps its date; today's or a later date publishes now (never "scheduled").
		if ( ! empty( $data['date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['date'] ) && ! $existing && $data['date'] < current_time( 'Y-m-d' ) ) {
			$post['post_date'] = $data['date'] . ' 10:00:00';
		}
		if ( $existing ) {
			$post['ID'] = $existing->ID;
			$id         = wp_update_post( $post, true );
		} else {
			$id = wp_insert_post( $post, true );
		}
		if ( is_wp_error( $id ) || ! $id ) {
			continue;
		}
		if ( $cat ) {
			wp_set_post_categories( $id, array( (int) $cat->term_id ) );
		}
		wp_set_post_tags( $id, array_map( 'sanitize_text_field', (array) ( $data['tags'] ?? array() ) ) );
		$products = array();
		foreach ( (array) ( $data['products'] ?? array() ) as $pslug ) {
			$p = get_page_by_path( sanitize_title( $pslug ), OBJECT, 'product' );
			if ( $p && 'publish' === $p->post_status ) {
				$products[] = (int) $p->ID;
			}
		}
		update_post_meta( $id, '_sf_blog_products', $products );
		update_post_meta( $id, '_sf_blog_hash', $hash );
		if ( $products && ! has_post_thumbnail( $id ) ) {
			$thumb = get_post_thumbnail_id( $products[0] );
			if ( $thumb ) {
				set_post_thumbnail( $id, $thumb );
			}
		}
	}
	update_option( 'store_features_blog_stamp', $stamp, false );
	delete_transient( 'store_features_blog_lock' );
}
add_action(
	'wp_loaded',
	function () {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		store_features_blog_import();
	},
	40
);

/**
 * "Shop the look" under a post.
 *
 * @param string $content Post content.
 * @return string
 */
function store_features_blog_shop_the_look( $content ) {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$ids = array_filter( array_map( 'intval', (array) get_post_meta( get_the_ID(), '_sf_blog_products', true ) ) );
	if ( ! $ids ) {
		return $content;
	}
	$cards = '';
	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );
		if ( ! $product || 'publish' !== $product->get_status() ) {
			continue;
		}
		$cards .= sprintf(
			'<a class="sf-look__card" href="%1$s">%2$s<span class="sf-look__name">%3$s</span><span class="sf-look__price">%4$s</span></a>',
			esc_url( get_permalink( $id ) ),
			$product->get_image( 'woocommerce_thumbnail', array( 'class' => 'sf-look__img', 'loading' => 'lazy' ) ),
			esc_html( $product->get_name() ),
			wp_kses_post( $product->get_price_html() )
		);
	}
	if ( ! $cards ) {
		return $content;
	}
	$css = '<style>.sf-look{margin:36px 0 8px}.sf-look h2{font-size:22px;margin:0 0 14px}.sf-look__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px}.sf-look__card{display:flex;flex-direction:column;gap:6px;color:inherit;text-decoration:none}.sf-look__img{width:100%;height:auto;aspect-ratio:4/5;object-fit:cover;border-radius:10px;background:#F0EEE8}.sf-look__name{font-weight:600;line-height:1.3}.sf-look__price{font-weight:700}</style>';
	return $content . $css . '<section class="sf-look"><h2>Shop the look</h2><div class="sf-look__grid">' . $cards . '</div></section>';
}
add_filter( 'the_content', 'store_features_blog_shop_the_look', 30 );

/**
 * Blog posts: the photo sits above the title (not behind it), square, and posts take no
 * comments (they only attract spam; questions go to WhatsApp).
 */
add_filter(
	'option_theme_mods_' . get_option( 'stylesheet' ),
	function ( $mods ) {
		if ( is_array( $mods ) ) {
			$mods['post_feature_position'] = 'above';
			$mods['post_feature_ratio']    = '1-1';
		}
		return $mods;
	}
);
add_filter(
	'comments_open',
	function ( $open, $post_id ) {
		return 'post' === get_post_type( $post_id ) ? false : $open;
	},
	10,
	2
);
