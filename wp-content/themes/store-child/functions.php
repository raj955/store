<?php
/**
 * Store Child theme setup.
 *
 * Deployed automatically from GitHub. Edit this file in the repo, not on the server,
 * or the next deploy will overwrite your change.
 *
 * @package store-child
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is the 2026 design (dark, Sarpanch headlines, new home page) on?
 * Switch it off with the option store_child_design = "v1" to get the old look back at once.
 *
 * @return bool
 */
function store_child_v2() {
	return 'v1' !== get_option( 'store_child_design', 'v2' );
}

require_once get_stylesheet_directory() . '/inc/home.php';

/**
 * Body class that every rule of the new design hangs off.
 *
 * @param array $classes Body classes.
 * @return array
 */
function store_child_body_class( $classes ) {
	if ( store_child_v2() ) {
		$classes[] = 'pw2';
	}
	return $classes;
}
add_filter( 'body_class', 'store_child_body_class' );

/**
 * Load the brand font, then the child stylesheet after Kadence's own styles.
 * The stylesheet's modified time is used as the version, so every deploy busts the browser cache.
 */
function store_child_enqueue_styles() {
	$path = get_stylesheet_directory() . '/style.css';

	wp_enqueue_style(
		'store-child-fonts',
		store_child_v2()
			? 'https://fonts.googleapis.com/css2?family=Anek+Devanagari:wdth,wght@75..125,100..800&family=Kalam:wght@700&family=Sarpanch:wght@700;900&display=swap'
			: 'https://fonts.googleapis.com/css2?family=Anek+Devanagari:wdth,wght@75..125,100..800&display=swap',
		array(),
		null
	);

	wp_enqueue_style(
		'store-child',
		get_stylesheet_uri(),
		array( 'store-child-fonts' ),
		file_exists( $path ) ? (string) filemtime( $path ) : null
	);

	if ( store_child_v2() ) {
		$v2 = get_stylesheet_directory() . '/assets/v2.css';
		wp_enqueue_style(
			'store-child-v2',
			get_stylesheet_directory_uri() . '/assets/v2.css',
			array( 'store-child' ),
			file_exists( $v2 ) ? (string) filemtime( $v2 ) : null
		);
	}
}
add_action( 'wp_enqueue_scripts', 'store_child_enqueue_styles', 20 );

/**
 * Open the connection to Google Fonts early so text appears sooner.
 *
 * @param array  $urls          URLs to print for resource hints.
 * @param string $relation_type The relation type the URLs are printed for.
 * @return array
 */
function store_child_resource_hints( $urls, $relation_type ) {
	if ( 'preconnect' === $relation_type ) {
		$urls[] = array( 'href' => 'https://fonts.googleapis.com' );
		$urls[] = array(
			'href' => 'https://fonts.gstatic.com',
			'crossorigin',
		);
	}

	return $urls;
}
add_filter( 'wp_resource_hints', 'store_child_resource_hints', 10, 2 );

/**
 * The header sits on a dark background in the new design, so the logo uses its light version.
 *
 * @param array   $attr       Image attributes.
 * @param WP_Post $attachment Image.
 * @return array
 */
function store_child_light_logo( $attr, $attachment ) {
	if ( is_admin() || ! store_child_v2() || ! $attachment || (int) get_theme_mod( 'custom_logo' ) !== (int) $attachment->ID ) {
		return $attr;
	}
	$attr['src'] = get_stylesheet_directory_uri() . '/assets/brand/panuwear-logo-light.png';
	unset( $attr['srcset'], $attr['sizes'] );
	return $attr;
}
add_filter( 'wp_get_attachment_image_attributes', 'store_child_light_logo', 10, 2 );

/**
 * Phones get an account icon in the header, beside the cart: login and sign-up are one tap away.
 * Kadence's phone header has no account item, so a small script puts the link in place.
 */
function store_child_mobile_account_icon() {
	if ( ! store_child_v2() || ! function_exists( 'wc_get_page_permalink' ) ) {
		return;
	}
	$label = is_user_logged_in() ? 'My account' : 'Login or register';
	$link  = '<a class="pw-m-account" href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '" aria-label="' . esc_attr( $label ) . '">'
		. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20.5c1.4-3.6 4.4-5.5 8-5.5s6.6 1.9 8 5.5" stroke-linecap="round"/></svg></a>';
	?>
	<script>
	(function () {
		var cart = document.querySelector('#mobile-header [data-section="kadence_customizer_mobile_cart"]');
		if (!cart || document.querySelector('.pw-m-account')) { return; }
		var item = document.createElement('div');
		item.className = 'site-header-item site-header-focus-item';
		item.innerHTML = <?php echo wp_json_encode( $link ); ?>;
		cart.parentNode.insertBefore(item, cart);
	})();
	</script>
	<?php
}
add_action( 'wp_footer', 'store_child_mobile_account_icon', 5 );

/**
 * Footer blurb: Panuwear is graphics, lines and your own design, not words only.
 *
 * @param string $content Block HTML.
 * @param array  $block   Block.
 * @return string
 */
function store_child_footer_about( $content, $block ) {
	if ( store_child_v2() && 'core/paragraph' === ( $block['blockName'] ?? '' ) && false !== strpos( $content, 'footer-about' ) ) {
		$content = preg_replace( '/(<p[^>]*footer-about[^>]*>).*?(<\/p>)/s', '$1' . esc_html__( 'Printed tees, hoodies and sweatshirts with our own graphics and desi lines, or your own design printed for you. Delivered anywhere in India.', 'store-child' ) . '$2', $content );
	}
	return $content;
}
add_filter( 'render_block', 'store_child_footer_about', 10, 2 );

/**
 * Footer copyright line also names the owner (payment providers check for it).
 *
 * @param mixed $mods Theme settings.
 * @return mixed
 */
function store_child_footer_owner( $mods ) {
	if ( is_admin() || ! is_array( $mods ) || empty( $mods['footer_html_content'] ) ) {
		return $mods;
	}
	$owner = trim( (string) get_option( 'store_features_owner', '' ) );
	if ( $owner && false === stripos( $mods['footer_html_content'], $owner ) ) {
		$mods['footer_html_content'] = str_replace( 'Made in India.', 'Made in India. Owned by ' . esc_html( $owner ) . '.', $mods['footer_html_content'] );
	}
	return $mods;
}
add_filter( 'option_theme_mods_store-child', 'store_child_footer_owner', 12 );

/**
 * Small fit label on product cards ("Oversized fit", "Crop fit"...), like the big stores show.
 */
function store_child_card_fit_badge() {
	global $product;
	if ( ! store_child_v2() || ! $product instanceof WC_Product ) {
		return;
	}
	$id    = $product->get_id();
	$label = '';
	if ( has_term( 'design-your-own', 'product_cat', $id ) ) {
		$label = 'अपना design';
	} elseif ( false !== stripos( $product->get_name(), 'crop top' ) ) {
		$label = 'Crop fit';
	} elseif ( has_term( 'oversized-t-shirts', 'product_cat', $id ) ) {
		$label = 'Oversized fit';
	} elseif ( has_term( array( 'hoodies', 'sweatshirts' ), 'product_cat', $id ) ) {
		$label = '300 GSM fleece';
	} elseif ( has_term( 't-shirts', 'product_cat', $id ) ) {
		$label = 'Regular fit';
	}
	if ( $label ) {
		echo '<span class="pw-fit">' . esc_html( $label ) . '</span>';
	}
}
add_action( 'woocommerce_before_shop_loop_item', 'store_child_card_fit_badge', 5 );
