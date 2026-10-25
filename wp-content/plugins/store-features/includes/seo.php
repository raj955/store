<?php
/**
 * Search and sharing.
 *
 * - A description for every page (what Google shows under the title).
 * - Link previews with a photo when a page is shared on WhatsApp, Instagram, Facebook or X.
 * - Canonical links on the shop and category pages, which WordPress leaves out.
 * - Brand details for Google: who the store is, a search box, breadcrumbs, and on products the
 *   brand, free shipping and the return policy.
 * - A cleaner sitemap (no user list, no cart or account pages) and no indexing of private pages.
 * - Alt text for product photos.
 *
 * Every description can be overridden: post meta `_sf_seo_description` on a page or product,
 * term meta `sf_seo_description` / `sf_seo_title` on a category.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plain text, cut at a word boundary.
 *
 * @param string $text Text or HTML.
 * @param int    $max  Longest length in characters.
 * @return string
 */
function store_features_seo_clip( $text, $max = 158 ) {
	// Fill in the store's own [store_*] tags first, so a description never reads "run by , , India".
	$text = preg_replace_callback( '/\[store_[a-z]+\]/', function ( $m ) { return do_shortcode( $m[0] ); }, (string) $text );
	$text = wp_strip_all_tags( strip_shortcodes( $text ) );
	$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut   = mb_substr( $text, 0, $max - 1 );
	$space = mb_strrpos( $cut, ' ' );
	if ( false !== $space && $space > $max * 0.6 ) {
		$cut = mb_substr( $cut, 0, $space );
	}
	return rtrim( $cut, " ,.;:–-" ) . '…';
}

/**
 * Sentences from the start of a text, as many as fit.
 *
 * @param string $text Text.
 * @param int    $max  Longest length.
 * @return string
 */
function store_features_seo_sentences( $text, $max ) {
	$text = store_features_seo_clip( $text, 2000 );
	$out  = '';
	foreach ( preg_split( '/(?<=[.!?।])\s+/u', $text ) as $sentence ) {
		$next = '' === $out ? $sentence : $out . ' ' . $sentence;
		if ( mb_strlen( $next ) > $max ) {
			break;
		}
		$out = $next;
	}
	return '' !== $out ? $out : store_features_seo_clip( $text, $max );
}

/**
 * Titles and descriptions for the product categories.
 *
 * @return array slug => array( title, description )
 */
function store_features_seo_category_copy() {
	return array(
		'oversized-t-shirts' => array(
			__( 'Printed Oversized T-shirts', 'store-features' ),
			__( 'Boxy oversized tees with graphic prints and desi lines, in 240 GSM super-combed cotton. Free shipping and cash on delivery across India.', 'store-features' ),
		),
		't-shirts'           => array(
			__( 'Printed T-shirts', 'store-features' ),
			__( 'Regular-fit tees with graphic prints and desi lines, in 180 GSM super-combed cotton. Free shipping and cash on delivery across India.', 'store-features' ),
		),
		'sweatshirts'        => array(
			__( 'Printed Sweatshirts', 'store-features' ),
			__( 'Crew neck sweatshirts with graphic prints and desi lines, in warm 300 GSM brushed fleece. Free shipping and cash on delivery across India.', 'store-features' ),
		),
		'hoodies'            => array(
			__( 'Printed Hoodies', 'store-features' ),
			__( 'Hoodies with graphic prints and desi lines, in warm 300 GSM brushed fleece. Free shipping and cash on delivery across India.', 'store-features' ),
		),
		'men'                => array(
			__( 'Men\'s T-shirts, Hoodies and Oversized Tees', 'store-features' ),
			__( 'Printed T-shirts, oversized tees, hoodies and sweatshirts for men, with graphic prints and desi lines. Free shipping and cash on delivery across India.', 'store-features' ),
		),
		'women'              => array(
			__( 'Women\'s T-shirts, Crop Tops and Hoodies', 'store-features' ),
			__( 'Printed T-shirts, crop tops, oversized tees and hoodies for women, with graphic prints and desi lines. Free shipping and cash on delivery across India.', 'store-features' ),
		),
		'design-your-own'    => array(
			__( 'Design Your Own T-shirt, Hoodie or Sweatshirt', 'store-features' ),
			__( 'Print your own text in Hindi or English, a photo or a logo on a T-shirt, oversized tee, sweatshirt or hoodie. See it before you order. Free shipping across India.', 'store-features' ),
		),
	);
}

/**
 * The default picture for link previews (set by store_features_og_image, else the logo).
 *
 * @return array|null url, width, height, alt
 */
function store_features_seo_default_image() {
	$id = (int) get_option( 'store_features_og_image', 0 );
	if ( ! $id ) {
		$id = (int) get_theme_mod( 'custom_logo' );
	}
	return $id ? store_features_seo_image( $id ) : null;
}

/**
 * An attachment as a preview picture.
 *
 * @param int    $id  Attachment ID.
 * @param string $alt Fallback alt text.
 * @return array|null
 */
function store_features_seo_image( $id, $alt = '' ) {
	$src = $id ? wp_get_attachment_image_src( $id, 'full' ) : false;
	if ( ! $src ) {
		return null;
	}
	$meta_alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	return array(
		'url'    => $src[0],
		'width'  => (int) $src[1],
		'height' => (int) $src[2],
		'alt'    => '' !== $meta_alt ? $meta_alt : $alt,
	);
}

/**
 * Everything the head needs for the current page.
 *
 * @return array type, title, description, url, image, price
 */
function store_features_seo_context() {
	static $context = null;
	if ( null !== $context ) {
		return $context;
	}
	$site    = get_bloginfo( 'name' );
	$context = array(
		'type'        => 'website',
		'title'       => wp_get_document_title(),
		'description' => '',
		'url'         => '',
		'image'       => null,
		'price'       => null,
	);

	if ( is_front_page() ) {
		$context['description'] = __( 'Printed T-shirts, oversized tees, sweatshirts and hoodies with our own graphics and desi lines, or your own design printed for you. Free shipping and cash on delivery across India.', 'store-features' );
		$context['url']         = home_url( '/' );
	} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
		$context['description'] = sprintf(
			/* translators: %s: store name */
			__( 'All %s designs: T-shirts, oversized tees, sweatshirts and hoodies with our own graphic prints and desi lines. Free shipping and cash on delivery across India.', 'store-features' ),
			$site
		);
		$context['url'] = get_permalink( wc_get_page_id( 'shop' ) );
	} elseif ( function_exists( 'is_product_category' ) && ( is_product_category() || is_product_tag() ) ) {
		$term  = get_queried_object();
		$copy  = store_features_seo_category_copy();
		$given = trim( (string) get_term_meta( $term->term_id, 'sf_seo_description', true ) );
		if ( '' !== $given ) {
			$context['description'] = $given;
		} elseif ( isset( $copy[ $term->slug ] ) ) {
			$context['description'] = $copy[ $term->slug ][1];
		} else {
			$context['description'] = store_features_seo_clip( term_description( $term ) );
		}
		$context['url'] = get_term_link( $term );
		$thumb          = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
		if ( $thumb ) {
			$context['image'] = store_features_seo_image( $thumb, $term->name );
		}
	} elseif ( is_singular() ) {
		$post           = get_queried_object();
		$given          = trim( (string) get_post_meta( $post->ID, '_sf_seo_description', true ) );
		$context['url'] = get_permalink( $post );
		$context['type'] = 'article';

		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product         = wc_get_product( $post->ID );
			$context['type'] = 'product';
			if ( $product ) {
				$custom = function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product );
				$tail   = $custom
					? __( 'Free shipping across India.', 'store-features' )
					: __( 'Free shipping and cash on delivery across India.', 'store-features' );
				$price  = (float) $product->get_price();
				if ( $price > 0 ) {
					/* translators: 1: price, 2: shipping line */
					$tail             = sprintf( __( '₹%1$s. %2$s', 'store-features' ), number_format_i18n( $price ), $tail );
					$context['price'] = $price;
				}
				$about = $product->get_short_description() ? $product->get_short_description() : $product->get_description();
				if ( '' === $given ) {
					$room  = 158 - mb_strlen( $tail ) - 1;
					$given = trim( store_features_seo_sentences( $about, $room ) . ' ' . $tail );
				}
				$context['image'] = store_features_seo_image( $product->get_image_id(), $product->get_name() );
			}
		} elseif ( '' === $given ) {
			$given = has_excerpt( $post ) ? get_the_excerpt( $post ) : $post->post_content;
		}
		$context['description'] = store_features_seo_clip( $given );
	}

	if ( '' === $context['description'] ) {
		$context['description'] = get_bloginfo( 'description' );
	}
	if ( ! $context['image'] ) {
		$context['image'] = store_features_seo_default_image();
	}
	return $context;
}

/**
 * Clearer titles on the home, shop and category pages.
 *
 * @param array $parts Title parts.
 * @return array
 */
function store_features_seo_title_parts( $parts ) {
	if ( is_front_page() ) {
		$parts['tagline'] = __( 'Printed Oversized Tees, Hoodies and Custom T-shirts', 'store-features' );
	} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
		$parts['title'] = __( 'Shop Printed T-shirts, Oversized Tees and Hoodies', 'store-features' );
	} elseif ( function_exists( 'is_product_category' ) && is_product_category() ) {
		$term  = get_queried_object();
		$given = trim( (string) get_term_meta( $term->term_id, 'sf_seo_title', true ) );
		$copy  = store_features_seo_category_copy();
		if ( '' !== $given ) {
			$parts['title'] = $given;
		} elseif ( isset( $copy[ $term->slug ] ) ) {
			$parts['title'] = $copy[ $term->slug ][0];
		}
	}
	return $parts;
}
add_filter( 'document_title_parts', 'store_features_seo_title_parts' );

/**
 * Pages that should stay out of search results: wishlist, order tracking and site search.
 *
 * @param array $robots Robots directives.
 * @return array
 */
function store_features_seo_robots( $robots ) {
	$private = array_filter(
		array(
			(int) get_option( 'yith_wcwl_wishlist_page_id' ),
			(int) get_option( 'woocommerce_myaccount_page_id' ),
		)
	);
	$track   = get_page_by_path( 'track-order' );
	if ( $track ) {
		$private[] = (int) $track->ID;
	}
	if ( is_search() || ( is_page() && in_array( (int) get_queried_object_id(), $private, true ) ) ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['max-image-preview'] );
	}
	return $robots;
}
add_filter( 'wp_robots', 'store_features_seo_robots', 20 );

/**
 * The tags in <head>: description, canonical, link previews, Search Console code, structured data.
 */
function store_features_seo_head() {
	if ( is_admin() || is_feed() || is_404() ) {
		return;
	}
	$c    = store_features_seo_context();
	$site = get_bloginfo( 'name' );

	echo "\n<!-- Panuwear SEO -->\n";
	if ( $c['description'] ) {
		printf( "<meta name=\"description\" content=\"%s\">\n", esc_attr( $c['description'] ) );
	}

	// WordPress adds canonical links only on single pages.
	if ( ! is_singular() && $c['url'] && ! is_wp_error( $c['url'] ) ) {
		$canonical = $c['url'];
		$paged     = (int) get_query_var( 'paged' );
		if ( $paged > 1 ) {
			$canonical = get_pagenum_link( $paged );
		}
		printf( "<link rel=\"canonical\" href=\"%s\">\n", esc_url( $canonical ) );
	}

	$settings = get_option( 'woocommerce_store_features_tracking_settings', array() );
	$gsc      = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $settings['gsc_token'] ?? '' ) );
	if ( $gsc && is_front_page() ) {
		printf( "<meta name=\"google-site-verification\" content=\"%s\">\n", esc_attr( $gsc ) );
	}

	// Link previews (Open Graph for WhatsApp, Instagram, Facebook; Twitter cards for X).
	$og = array(
		'og:locale'      => 'en_IN',
		'og:site_name'   => $site,
		'og:type'        => 'product' === $c['type'] ? 'product' : ( 'article' === $c['type'] ? 'article' : 'website' ),
		'og:title'       => $c['title'],
		'og:description' => $c['description'],
		'og:url'         => is_wp_error( $c['url'] ) ? '' : $c['url'],
	);
	if ( $c['image'] ) {
		$og['og:image']        = $c['image']['url'];
		$og['og:image:width']  = $c['image']['width'];
		$og['og:image:height'] = $c['image']['height'];
		$og['og:image:alt']    = $c['image']['alt'] ? $c['image']['alt'] : $c['title'];
	}
	if ( null !== $c['price'] ) {
		$og['product:price:amount']   = wc_format_decimal( $c['price'], 2 );
		$og['product:price:currency'] = get_woocommerce_currency();
	}
	foreach ( $og as $property => $content ) {
		if ( '' !== (string) $content ) {
			printf( "<meta property=\"%s\" content=\"%s\">\n", esc_attr( $property ), esc_attr( $content ) );
		}
	}
	printf( "<meta name=\"twitter:card\" content=\"%s\">\n", $c['image'] ? 'summary_large_image' : 'summary' );

	$graph = store_features_seo_graph();
	if ( $graph ) {
		echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n";
	}
	echo "<!-- /Panuwear SEO -->\n";
}
add_action( 'wp_head', 'store_features_seo_head', 2 );

/**
 * The store as an organisation, for Google.
 *
 * @return array
 */
function store_features_seo_organization() {
	$home = home_url( '/' );
	$org  = array(
		'@type' => 'Organization',
		'@id'   => $home . '#organization',
		'name'  => get_bloginfo( 'name' ),
		'url'   => $home,
	);
	$logo = store_features_seo_image( (int) get_theme_mod( 'custom_logo' ) );
	if ( $logo ) {
		$org['logo'] = array(
			'@type'  => 'ImageObject',
			'url'    => $logo['url'],
			'width'  => $logo['width'],
			'height' => $logo['height'],
		);
	}
	$email = sanitize_email( get_option( 'store_features_email', '' ) );
	$phone = trim( (string) get_option( 'store_features_phone', '' ) );
	if ( $email ) {
		$org['email'] = $email;
	}
	if ( $phone ) {
		$org['telephone']    = preg_replace( '/[^0-9+]/', '', $phone );
		$org['contactPoint'] = array(
			'@type'             => 'ContactPoint',
			'contactType'       => 'customer support',
			'telephone'         => preg_replace( '/[^0-9+]/', '', $phone ),
			'areaServed'        => 'IN',
			'availableLanguage' => array( 'Hindi', 'English' ),
		);
		if ( $email ) {
			$org['contactPoint']['email'] = $email;
		}
	}
	$country_state = explode( ':', (string) get_option( 'woocommerce_default_country', 'IN' ) );
	$region        = '';
	if ( isset( $country_state[1] ) && function_exists( 'WC' ) && WC()->countries ) {
		$states = WC()->countries->get_states( $country_state[0] );
		$region = is_array( $states ) && isset( $states[ $country_state[1] ] ) ? $states[ $country_state[1] ] : $country_state[1];
	}
	$street = trim( implode( ', ', array_filter( array( get_option( 'woocommerce_store_address', '' ), get_option( 'woocommerce_store_address_2', '' ) ) ) ) );
	if ( $street || get_option( 'woocommerce_store_city', '' ) ) {
		$org['address'] = array_filter(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $street,
				'addressLocality' => (string) get_option( 'woocommerce_store_city', '' ),
				'addressRegion'   => $region,
				'postalCode'      => (string) get_option( 'woocommerce_store_postcode', '' ),
				'addressCountry'  => $country_state[0],
			)
		);
	}
	$instagram = esc_url_raw( (string) get_option( 'store_features_instagram', '' ) );
	if ( $instagram ) {
		$org['sameAs'] = array( $instagram );
	}
	return $org;
}

/**
 * Structured data for the current page: organisation and site search on the home page,
 * breadcrumbs on shop, category and product pages.
 *
 * @return array
 */
function store_features_seo_graph() {
	$home  = home_url( '/' );
	$graph = array();

	if ( is_front_page() ) {
		$graph[] = store_features_seo_organization();
		$graph[] = array(
			'@type'           => 'WebSite',
			'@id'             => $home . '#website',
			'url'             => $home,
			'name'            => get_bloginfo( 'name' ),
			'inLanguage'      => 'en-IN',
			'publisher'       => array( '@id' => $home . '#organization' ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => $home . '?s={search_term_string}&post_type=product',
				),
				'query-input' => 'required name=search_term_string',
			),
		);
		return $graph;
	}

	if ( ! function_exists( 'wc_get_page_id' ) ) {
		return $graph;
	}
	$crumbs = array( array( __( 'Home', 'store-features' ), $home ) );
	$shop   = wc_get_page_id( 'shop' );
	if ( is_shop() ) {
		$crumbs[] = array( get_the_title( $shop ), get_permalink( $shop ) );
	} elseif ( is_product_category() ) {
		$term     = get_queried_object();
		$crumbs[] = array( get_the_title( $shop ), get_permalink( $shop ) );
		foreach ( array_reverse( get_ancestors( $term->term_id, 'product_cat' ) ) as $ancestor ) {
			$a        = get_term( $ancestor, 'product_cat' );
			$crumbs[] = array( $a->name, get_term_link( $a ) );
		}
		$crumbs[] = array( $term->name, get_term_link( $term ) );
	} elseif ( is_product() ) {
		$crumbs[] = array( get_the_title( $shop ), get_permalink( $shop ) );
		$terms    = wc_get_product_terms( get_queried_object_id(), 'product_cat', array( 'orderby' => 'parent', 'order' => 'DESC' ) );
		if ( $terms ) {
			$crumbs[] = array( $terms[0]->name, get_term_link( $terms[0] ) );
		}
		$crumbs[] = array( get_the_title(), get_permalink() );
	} else {
		return $graph;
	}

	$items = array();
	foreach ( $crumbs as $i => $crumb ) {
		if ( is_wp_error( $crumb[1] ) ) {
			continue;
		}
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => count( $items ) + 1,
			'name'     => wp_strip_all_tags( $crumb[0] ),
			'item'     => $crumb[1],
		);
	}
	$graph[] = array(
		'@type'           => 'BreadcrumbList',
		'itemListElement' => $items,
	);
	return $graph;
}

/**
 * Product data for Google: brand, free shipping with delivery time, and the return policy.
 *
 * @param array      $markup  WooCommerce's product markup.
 * @param WC_Product $product Product.
 * @return array
 */
function store_features_seo_product_markup( $markup, $product ) {
	$markup['brand'] = array(
		'@type' => 'Brand',
		'name'  => get_bloginfo( 'name' ),
	);
	$markup['description'] = store_features_seo_clip( $product->get_short_description() ? $product->get_short_description() : $product->get_description(), 5000 );

	$images = array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) );
	if ( count( $images ) > 1 ) {
		$markup['image'] = array_values( array_filter( array_map( 'wp_get_attachment_url', array_slice( $images, 0, 8 ) ) ) );
	}

	$shipping = array(
		'@type'               => 'OfferShippingDetails',
		'shippingRate'        => array(
			'@type'    => 'MonetaryAmount',
			'value'    => 0,
			'currency' => get_woocommerce_currency(),
		),
		'shippingDestination' => array(
			'@type'          => 'DefinedRegion',
			'addressCountry' => 'IN',
		),
		'deliveryTime'        => array(
			'@type'        => 'ShippingDeliveryTime',
			'handlingTime' => array(
				'@type'    => 'QuantitativeValue',
				'minValue' => 2,
				'maxValue' => 3,
				'unitCode' => 'DAY',
			),
			'transitTime'  => array(
				'@type'    => 'QuantitativeValue',
				'minValue' => 3,
				'maxValue' => 4,
				'unitCode' => 'DAY',
			),
		),
	);
	$returns  = array(
		'@type'                => 'MerchantReturnPolicy',
		'applicableCountry'    => 'IN',
		'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
	);
	if ( ! empty( $markup['offers'] ) && is_array( $markup['offers'] ) ) {
		foreach ( $markup['offers'] as $i => $offer ) {
			if ( is_array( $offer ) ) {
				$markup['offers'][ $i ]['shippingDetails']         = $shipping;
				$markup['offers'][ $i ]['hasMerchantReturnPolicy'] = $returns;
			}
		}
	}
	return $markup;
}
add_filter( 'woocommerce_structured_data_product', 'store_features_seo_product_markup', 10, 2 );

/**
 * Sitemap: no list of user accounts (it would show the admin's login name).
 *
 * @param WP_Sitemaps_Provider $provider Provider.
 * @param string               $name     Provider name.
 * @return WP_Sitemaps_Provider|false
 */
function store_features_seo_sitemap_providers( $provider, $name ) {
	return 'users' === $name ? false : $provider;
}
add_filter( 'wp_sitemaps_add_provider', 'store_features_seo_sitemap_providers', 10, 2 );

/**
 * Sitemap: leave out cart, checkout, account, wishlist and order tracking.
 *
 * @param array  $args      Query args.
 * @param string $post_type Post type.
 * @return array
 */
function store_features_seo_sitemap_pages( $args, $post_type ) {
	if ( 'page' !== $post_type || ! function_exists( 'wc_get_page_id' ) ) {
		return $args;
	}
	$skip  = array( wc_get_page_id( 'cart' ), wc_get_page_id( 'checkout' ), wc_get_page_id( 'myaccount' ), (int) get_option( 'yith_wcwl_wishlist_page_id' ) );
	$track = get_page_by_path( 'track-order' );
	if ( $track ) {
		$skip[] = $track->ID;
	}
	$args['post__not_in'] = array_values( array_filter( array_map( 'intval', array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), $skip ) ), function ( $id ) {
		return $id > 0;
	} ) );
	return $args;
}
add_filter( 'wp_sitemaps_posts_query_args', 'store_features_seo_sitemap_pages', 10, 2 );

/**
 * The page language: English as used in India.
 *
 * @param string $output Attributes.
 * @return string
 */
function store_features_seo_language( $output ) {
	return str_replace( 'lang="en-US"', 'lang="en-IN"', $output );
}
add_filter( 'language_attributes', 'store_features_seo_language' );

/**
 * Product photos without alt text get the product's name (for example the second photo
 * that shows on hover in the shop).
 *
 * @param array        $attr       Image attributes.
 * @param WP_Post      $attachment Attachment.
 * @return array
 */
function store_features_seo_image_alt( $attr, $attachment ) {
	if ( isset( $attr['alt'] ) && '' !== trim( $attr['alt'] ) ) {
		return $attr;
	}
	global $product;
	if ( $product instanceof WC_Product ) {
		$attr['alt'] = $product->get_name();
	} elseif ( $attachment->post_parent && 'product' === get_post_type( $attachment->post_parent ) ) {
		$attr['alt'] = get_the_title( $attachment->post_parent );
	}
	return $attr;
}
add_filter( 'wp_get_attachment_image_attributes', 'store_features_seo_image_alt', 20, 2 );

/**
 * Write alt text into the media library for every product photo that has none:
 * "Product name, Colour" for colour photos, the product name for the rest.
 *
 * @return array Counts.
 */
function store_features_seo_fill_alt() {
	$done     = array(
		'colour' => 0,
		'plain'  => 0,
	);
	$set      = function ( $id, $alt ) {
		if ( $id && '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
			return true;
		}
		return false;
	};
	$products = wc_get_products(
		array(
			'status' => 'publish',
			'limit'  => -1,
		)
	);
	foreach ( $products as $product ) {
		$name = $product->get_name();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( ! $variation ) {
					continue;
				}
				$slug   = $variation->get_attribute( 'pa_color' );
				$colour = $slug ? $slug : '';
				if ( $colour && $set( $variation->get_image_id(), $name . ', ' . $colour ) ) {
					++$done['colour'];
				}
			}
		}
		foreach ( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) as $image_id ) {
			if ( $set( $image_id, $name ) ) {
				++$done['plain'];
			}
		}
	}
	return $done;
}

/**
 * Settings readable and writable through the REST API (and so by the admin tools).
 */
function store_features_seo_register_settings() {
	register_setting(
		'store_features',
		'store_features_og_image',
		array(
			'type'              => 'integer',
			'description'       => 'Default link-preview picture (attachment ID).',
			'default'           => 0,
			'sanitize_callback' => 'absint',
			'show_in_rest'      => true,
		)
	);
}
add_action( 'init', 'store_features_seo_register_settings' );

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'store-features/v1',
			'/seo-alt-fill',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'callback'            => function () {
					return rest_ensure_response( store_features_seo_fill_alt() );
				},
			)
		);
	}
);
