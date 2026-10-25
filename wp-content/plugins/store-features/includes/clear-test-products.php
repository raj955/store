<?php
/**
 * Products > Clear test products.
 *
 * Shows which products would stay (Mugs, Tote bags, Design your own) and which would be
 * moved to the Trash, and moves the rest to the Trash after one confirm. The Trash keeps
 * them for 30 days, so nothing is lost for good by this page.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * Split all products into those to keep and those to trash.
 *
 * @return array{keep: WP_Post[], trash: WP_Post[]}
 */
function store_features_clear_test_split() {
	$posts = get_posts(
		array(
			'post_type'   => 'product',
			'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		)
	);
	$out = array( 'keep' => array(), 'trash' => array() );
	foreach ( $posts as $post ) {
		$custom = function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $post->ID );
		$keep   = $custom || has_term( array( 'mugs', 'tote-bags', 'design-your-own' ), 'product_cat', $post->ID );
		$out[ $keep ? 'keep' : 'trash' ][] = $post;
	}
	return $out;
}

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'edit.php?post_type=product', 'Clear test products', 'Clear test products', 'manage_woocommerce', 'sf-clear-test', 'store_features_clear_test_page' );
	}
);

/**
 * The page.
 */
function store_features_clear_test_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$split = store_features_clear_test_split();
	echo '<div class="wrap"><h1>Clear test products</h1>';
	if ( isset( $_GET['moved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		printf( '<div class="notice notice-success"><p>%d products moved to the Trash. Check Products &gt; Trash, then empty it.</p></div>', (int) $_GET['moved'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}
	printf( '<h2>Will stay (%d)</h2><ul style="list-style:disc;margin-left:20px">', count( $split['keep'] ) );
	foreach ( $split['keep'] as $p ) {
		echo '<li>' . esc_html( $p->post_title ) . '</li>';
	}
	printf( '</ul><h2>Will move to the Trash (%d)</h2><ul style="list-style:disc;margin-left:20px">', count( $split['trash'] ) );
	foreach ( $split['trash'] as $p ) {
		echo '<li>' . esc_html( $p->post_title ) . ' <em>(' . esc_html( $p->post_status ) . ')</em></li>';
	}
	echo '</ul>';
	if ( $split['trash'] ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Move these products to the Trash?\');">';
		echo '<input type="hidden" name="action" value="sf_clear_test">';
		wp_nonce_field( 'sf_clear_test' );
		submit_button( 'Move these ' . count( $split['trash'] ) . ' products to the Trash', 'primary' );
		echo '</form>';
	}
	echo '</div>';
}

add_action(
	'admin_post_sf_clear_test',
	function () {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( 'sf_clear_test' );
		$n = 0;
		foreach ( store_features_clear_test_split()['trash'] as $p ) {
			if ( wp_trash_post( $p->ID ) ) {
				++$n;
			}
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=product&page=sf-clear-test&moved=' . $n ) );
		exit;
	}
);
