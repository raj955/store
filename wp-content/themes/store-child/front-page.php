<?php
/**
 * Home page. Built in code (inc/home.php) so the design lives in the repo.
 * When the new design is switched off (store_child_design option = "v1"),
 * the page made in the editor is shown the normal Kadence way.
 *
 * @package store-child
 */

defined( 'ABSPATH' ) || exit;

if ( ! store_child_v2() || ! function_exists( 'store_child_home' ) || ! function_exists( 'wc_get_products' ) ) {
	$store_child_tpl = is_page() ? get_page_template() : '';
	require $store_child_tpl ? $store_child_tpl : get_template_directory() . '/index.php';
	return;
}

get_header();
store_child_home();
get_footer();
