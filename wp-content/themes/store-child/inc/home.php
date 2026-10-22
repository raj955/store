<?php
/**
 * The home page, section by section. Everything shown comes from the store itself:
 * real categories, real products, the offer settings in Store Features.
 *
 * @package store-child
 */

defined( 'ABSPATH' ) || exit;

/**
 * Categories shown in "आज का mood?", in order, with the words and colour for each card.
 *
 * @return array
 */
function store_child_home_collections() {
	// Apparel and custom apparel always come first; every other populated category follows.
	$collections = array(
		'oversized-t-shirts' => array( 'Oversized tees', '240 GSM · relaxed oversized fit', '#161618' ),
		't-shirts'           => array( 'T-shirts', '180 GSM · everyday regular fit', '#1C2340' ),
		'women'              => array( "Women's fits", 'Crop fits · oversized styles', '#4B3566' ),
		'sweatshirts'        => array( 'Sweatshirts', '300 GSM · brushed fleece feel', '#4A3426' ),
		'hoodies'            => array( 'Hoodies', '300 GSM · cosy hoodie layer', '#2F4A36' ),
		'design-your-own'    => array( 'अपना design', 'अपनी line या photo · अपना design', '#3A1D2A' ),
	);

	$terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			if ( isset( $collections[ $term->slug ] ) || 'uncategorized' === $term->slug ) {
				continue;
			}
			$description = trim( wp_strip_all_tags( $term->description ) );
			$collections[ $term->slug ] = array(
				$term->name,
				$description ? wp_trim_words( $description, 7, '…' ) : 'Explore the collection',
				'#4A3426',
			);
		}
	}

	return $collections;
}
/**
 * Image for a category card: the category's own image, else its first product's.
 *
 * @param WP_Term $term Category.
 * @return string Image HTML, or ''.
 */
function store_child_home_term_image( $term ) {
	$thumb = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
	if ( $thumb ) {
		return wp_get_attachment_image( $thumb, 'woocommerce_thumbnail', false, array( 'alt' => '', 'loading' => 'lazy' ) );
	}
	$ids = wc_get_products(
		array(
			'status'   => 'publish',
			'limit'    => 1,
			'category' => array( $term->slug ),
			'orderby'  => 'menu_order',
			'order'    => 'ASC',
			'return'   => 'ids',
		)
	);
	if ( $ids && has_post_thumbnail( $ids[0] ) ) {
		return get_the_post_thumbnail( $ids[0], 'woocommerce_thumbnail', array( 'alt' => '', 'loading' => 'lazy' ) );
	}
	return '';
}

/**
 * Ready-design products for the home page (no "Design your own" pieces).
 *
 * @param int $limit How many.
 * @return WC_Product[]
 */
function store_child_home_products( $limit = 8 ) {
	// A mix across the range, in this order, so every kind of shopper sees something for them.
	$plan = array( 'oversized-t-shirts', 'women', 'hoodies', 'oversized-t-shirts', 'women', 'sweatshirts', 't-shirts', 'oversized-t-shirts', 'hoodies', 'women', 't-shirts', 'oversized-t-shirts' );
	$pools = array();
	$picked = array();
	foreach ( $plan as $slug ) {
		if ( count( $picked ) >= $limit ) {
			break;
		}
		if ( ! isset( $pools[ $slug ] ) ) {
			$pools[ $slug ] = wc_get_products(
				array(
					'status'       => 'publish',
					'limit'        => 6,
					'visibility'   => 'catalog',
					'stock_status' => 'instock',
					'category'     => array( $slug ),
					'orderby'      => array(
						'menu_order' => 'ASC',
						'date'       => 'DESC',
					),
				)
			);
		}
		while ( $pools[ $slug ] ) {
			$product = array_shift( $pools[ $slug ] );
			if ( ! isset( $picked[ $product->get_id() ] ) ) {
				$picked[ $product->get_id() ] = $product;
				break;
			}
		}
	}
	return array_values( $picked );
}

/**
 * Price of one ready tee, for the bundle examples: the most common price among home products.
 *
 * @param WC_Product[] $products Products.
 * @return float
 */
function store_child_home_example_price( $products ) {
	$oversized = wc_get_products(
		array(
			'status'   => 'publish',
			'limit'    => 1,
			'category' => array( 'oversized-t-shirts' ),
			'orderby'  => 'menu_order',
			'order'    => 'ASC',
		)
	);
	if ( $oversized && (float) $oversized[0]->get_price() > 0 ) {
		return (float) $oversized[0]->get_price();
	}
	$prices = array();
	foreach ( $products as $product ) {
		$price = (float) $product->get_price();
		if ( $price > 0 ) {
			$key            = (string) $price;
			$prices[ $key ] = ( $prices[ $key ] ?? 0 ) + 1;
		}
	}
	if ( ! $prices ) {
		return 0.0;
	}
	arsort( $prices );
	return (float) array_key_first( $prices );
}

/**
 * Lowest live price in each category, for the price band.
 *
 * @return array[] name, gsm, price, url
 */
function store_child_price_ladder() {
	$cached = get_transient( 'store_child_price_ladder_v1' );
	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}
	$rows = array(
		't-shirts'           => array( 'T-shirt', '180 GSM' ),
		'oversized-t-shirts' => array( 'Oversized', '240 GSM' ),
		'sweatshirts'        => array( 'Sweatshirt', '300 GSM' ),
		'hoodies'            => array( 'Hoodie', '300 GSM' ),
	);
	$out = array();
	foreach ( $rows as $slug => $row ) {
		$min = 0;
		foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => 20, 'category' => array( $slug ) ) ) as $product ) {
			if ( function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product ) ) {
				continue;
			}
			$p = (float) ( $product->is_type( 'variable' ) ? $product->get_variation_price( 'min', true ) : $product->get_price() );
			if ( $p > 0 && ( ! $min || $p < $min ) ) {
				$min = $p;
			}
		}
		if ( $min ) {
			$out[] = array(
				'name'  => $row[0],
				'gsm'   => $row[1],
				'price' => $min,
				'url'   => store_child_cat_url( $slug ),
			);
		}
	}
	// Short cache only; product save/price/stock hooks below invalidate it.
	set_transient( 'store_child_price_ladder_v1', $out, 5 * MINUTE_IN_SECONDS );
	return $out;
}

/** Clear the homepage price cache whenever a product changes. */
function store_child_clear_price_ladder_cache( $product ) {
	delete_transient( 'store_child_price_ladder_v1' );
}
add_action( 'woocommerce_product_object_updated_props', 'store_child_clear_price_ladder_cache', 10, 1 );
add_action( 'woocommerce_product_set_stock', 'store_child_clear_price_ladder_cache', 10, 1 );
add_action( 'woocommerce_variation_set_stock', 'store_child_clear_price_ladder_cache', 10, 1 );
add_action( 'woocommerce_product_set_stock_status', 'store_child_clear_price_ladder_cache', 10, 1 );
add_action( 'woocommerce_variation_set_stock_status', 'store_child_clear_price_ladder_cache', 10, 1 );
add_action( 'save_post_product', 'store_child_clear_price_ladder_cache', 10, 1 );
add_action( 'save_post_product_variation', 'store_child_clear_price_ladder_cache', 10, 1 );

/**
 * Plain rupee amount, e.g. ₹1,618.
 *
 * @param float $amount Amount.
 * @return string
 */
function store_child_rupees( $amount ) {
	return '₹' . number_format_i18n( round( $amount ) );
}

/**
 * WhatsApp chat link from the store's phone number, or ''.
 *
 * @param string $text Message to start with.
 * @return string
 */
function store_child_whatsapp_url( $text = '' ) {
	$phone = preg_replace( '/\D/', '', (string) get_option( 'store_features_phone', '' ) );
	if ( strlen( $phone ) === 10 ) {
		$phone = '91' . $phone;
	}
	if ( strlen( $phone ) < 11 ) {
		return '';
	}
	return 'https://wa.me/' . $phone . ( $text ? '?text=' . rawurlencode( $text ) : '' );
}

/**
 * Category link by slug, falling back to the shop.
 *
 * @param string $slug Category slug.
 * @return string
 */
function store_child_cat_url( $slug ) {
	$link = get_term_link( $slug, 'product_cat' );
	return is_wp_error( $link ) ? wc_get_page_permalink( 'shop' ) : $link;
}

/**
 * Print the home page.
 */
function store_child_home() {
	$products = store_child_home_products( 8 );
	$shop     = wc_get_page_permalink( 'shop' );
	$custom   = store_child_cat_url( 'design-your-own' );
	$wa       = store_child_whatsapp_url( 'Hi Panuwear, mujhe ek sawal hai' );
	$offer    = function_exists( 'store_features_offer_config' ) ? store_features_offer_config() : array();
	$tiers    = isset( $offer['tiers'] ) ? $offer['tiers'] : array();
	ksort( $tiers );
	$last  = $tiers ? array_key_last( $tiers ) : 0;
	$price = store_child_home_example_price( $products );

	// Three product photos for the hero, from different products.
	$hero = array();
	foreach ( $products as $product ) {
		if ( $product->get_image_id() ) {
			$hero[] = $product;
		}
		if ( count( $hero ) === 3 ) {
			break;
		}
	}
	?>
	<div id="primary" class="content-area pw-home">

	<nav class="pw-cats" aria-label="Categories">
		<ul class="pw-cats__row">
			<?php
			foreach ( store_child_home_collections() as $slug => $card ) :
				$term = get_term_by( 'slug', $slug, 'product_cat' );
				if ( ! $term || ( ! $term->count && 'design-your-own' !== $slug ) ) {
					continue;
				}
				?>
				<li>
					<a class="pw-cat" href="<?php echo esc_url( get_term_link( $term ) ); ?>">
						<span class="pw-cat__img"><?php echo store_child_home_term_image( $term ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="pw-cat__name"><?php echo esc_html( $card[0] ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>

	<section class="pw-hero" aria-labelledby="pw-hero-title">
		<div class="pw-wrap pw-hero__inner">
			<div class="pw-hero__copy">
				<p class="pw-hero__kicker">PANUWEAR · DESI GRAPHIC STREETWEAR</p>
				<h1 id="pw-hero-title" class="pw-hero__title">कपड़े नहीं,<br>अपनी <span>vibe पहनो।</span></h1>
				<p class="pw-hero__lede">Desi humour, pahाड़ी feels और everyday moods—अब तुम्हारे favourite fits पर। अपना print चुनो, अपना रंग चुनो, और अपनी vibe में निकलो।</p>
				<div class="pw-hero__actions">
					<a class="pw-btn pw-btn--haldi" href="<?php echo esc_url( $shop ); ?>">अपना fit ढूँढो</a>
					<a class="pw-btn pw-btn--ghost" href="<?php echo esc_url( $custom ); ?>">अपना design बनाओ</a>
				</div>
				<ul class="pw-hero__facts">
					<li>Free shipping</li>
					<li>Cash on delivery</li>
					<li>5 से 7 दिन में delivery</li>
				</ul>
			</div>
			<?php if ( $hero ) :
				$hero_image = wp_get_attachment_image_src( $hero[0]->get_image_id(), 'woocommerce_single' );
				if ( $hero_image && ! empty( $hero_image[0] ) ) :
				?>
				<link rel="preload" as="image" href="<?php echo esc_url( $hero_image[0] ); ?>" fetchpriority="high">
				<?php endif; ?>
			<div class="pw-hero__art" aria-hidden="true">
				<?php foreach ( $hero as $i => $product ) : ?>
					<a class="pw-hero__card pw-hero__card--<?php echo (int) $i + 1; ?>" href="<?php echo esc_url( $product->get_permalink() ); ?>" tabindex="-1">
						<?php echo wp_get_attachment_image( $product->get_image_id(), 'woocommerce_single', false, array( 'alt' => '', 'loading' => 0 === $i ? 'eager' : 'lazy', 'fetchpriority' => 0 === $i ? 'high' : 'auto' ) ); ?>
					</a>
				<?php endforeach; ?>
				<p class="pw-hand pw-hero__note">Fit check. Vibe check.</p>
			</div>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( $products ) : ?>
	<section class="pw-new" aria-labelledby="pw-new-title">
		<div class="pw-wrap">
			<div class="pw-head">
				<h2 id="pw-new-title" class="pw-h2">जो तुम्हारी vibe से match करें</h2>
				<a class="pw-link" href="<?php echo esc_url( $shop ); ?>">पूरा collection देखो</a>
			</div>
			<div class="woocommerce columns-4">
				<?php
				wc_set_loop_prop( 'name', 'pw_home' );
				wc_set_loop_prop( 'columns', 4 );
				woocommerce_product_loop_start();
				foreach ( $products as $product ) {
					$GLOBALS['post'] = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					setup_postdata( $GLOBALS['post'] );
					wc_get_template_part( 'content', 'product' );
				}
				woocommerce_product_loop_end();
				wp_reset_postdata();
				wc_reset_loop();
				?>
			</div>
		</div>
	</section>
	<?php endif; ?>

	<?php $ladder = store_child_price_ladder(); ?>
	<?php if ( $ladder ) : ?>
	<section class="pw-bundle" aria-labelledby="pw-bundle-title">
		<div class="pw-wrap pw-bundle__inner">
			<div class="pw-bundle__copy">
				<h2 id="pw-bundle-title" class="pw-h2">अपना fit.<br>अपनी वाली vibe.</h2>
				<p>अपना fit चुनो, अपना print चुनो—shipping free, payment में COD भी।</p>
			</div>
			<ul class="pw-bundle__tiers">
				<?php foreach ( $ladder as $i => $row ) : ?>
					<li>
						<a href="<?php echo esc_url( $row['url'] ); ?>" class="pw-bundle__link">
							<span class="pw-bundle__qty"><?php echo esc_html( $row['name'] . ' · ' . $row['gsm'] ); ?></span>
							<span class="pw-bundle__price"><?php echo esc_html( store_child_rupees( $row['price'] ) ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $tiers ) : ?>
				<p class="pw-bundle__code">
					<?php
					$bits = array();
					foreach ( $tiers as $qty => $amount ) {
						$bits[] = $qty . ( $qty === $last ? ' या ज़्यादा' : '' ) . ' लो तो ' . store_child_rupees( $amount ) . ' कम';
					}
					echo esc_html( implode( ', ', $bits ) . '।' );
					if ( ! empty( $offer['coupon'] ) ) {
						echo ' पहला order? Code <strong>' . esc_html( strtoupper( $offer['coupon'] ) ) . '</strong> से ' . esc_html( store_child_rupees( $offer['coupon_off'] ) ) . ' और कम।';
					}
					?>
				</p>
			<?php endif; ?>
		</div>
	</section>
	<?php endif; ?>

	<section class="pw-why" aria-labelledby="pw-why-title">
		<div class="pw-wrap">
			<h2 id="pw-why-title" class="pw-h2">तुम्हारा fit. तुम्हारे rules.</h2>
			<ul class="pw-why__grid">
				<li><h3>Copy-paste नहीं, original</h3><p>हमारे graphics और lines अपनी सोच से आते हैं—ताकि तुम्हारा fit किसी और जैसा न लगे।</p></li>
				<li><h3>हर fit का अपना feel</h3><p>180 GSM regular tees, 240 GSM oversized tees और 300 GSM fleece hoodies/sweatshirts—अपनी पसंद का fit चुनो। GSM fabric के वजन को बताता है; feel और drape fabric blend व construction पर भी depend करते हैं।</p></li>
				<li><h3>Made for your order</h3><p>तुम्हारा piece order के बाद print होता है—कम random stock, ज़्यादा तुम्हारी पसंद।</p></li>
				<li><h3>Easy checkout</h3><p>पूरे India में free shipping और COD available. आम तौर पर delivery 5–7 working days में।</p></li>
				<li><h3>Print में issue? बताओ</h3><p>Damage या गलत print मिले? 7 दिन के अंदर unboxing video के साथ हमसे contact करो—हम replacement में help करेंगे।</p></li>
			</ul>
		</div>
	</section>

	<section class="pw-story" aria-labelledby="pw-story-title">
		<div class="pw-wrap pw-story__inner">
			<?php
			$hoodies = get_term_by( 'slug', 'hoodies', 'product_cat' );
			$story   = $hoodies ? store_child_home_term_image( $hoodies ) : '';
			?>
			<div class="pw-story__mark"><?php echo $story ? $story : '<img src="' . esc_url( get_stylesheet_directory_uri() . '/assets/brand/panuwear-icon-512.png' ) . '" alt="" width="256" height="256" loading="lazy">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div>
				<h2 id="pw-story-title" class="pw-h2">पहाड़ की feel,<br>हर शहर की vibe.</h2>
				<p>Panuwear की inspiration पहाड़, रोज़ की ज़िंदगी और chai-time वाली बातों से आती है। कभी graphic, कभी एक line—हर print में अपनी-सी feel. और तुम्हारे दिमाग में कोई design है? उसे भी अपनी tee पर लाओ।</p>
				<a class="pw-link pw-link--light" href="<?php echo esc_url( $shop ); ?>">अपनी vibe चुनो</a>
			</div>
		</div>
	</section>

	<section class="pw-diy" aria-labelledby="pw-diy-title">
		<div class="pw-wrap">
			<div class="pw-diy__box">
				<div>
					<h2 id="pw-diy-title" class="pw-h2">अपनी लाइन, अपनी tee।</h2>
					<p>Hindi या English में लिखो, या photo डालो। Order से पहले tee पर देख लो कैसी दिखेगी।</p>
				</div>
				<a class="pw-btn pw-btn--rani" href="<?php echo esc_url( $custom ); ?>">Design शुरू करो</a>
			</div>
		</div>
	</section>

	<?php if ( $wa ) : ?>
	<section class="pw-help" aria-labelledby="pw-help-title">
		<div class="pw-wrap pw-help__inner">
			<div>
				<h2 id="pw-help-title" class="pw-help__title">Size या order पर सवाल?</h2>
				<p>WhatsApp करो, हम खुद जवाब देते हैं।</p>
			</div>
			<a class="pw-btn pw-btn--paper" href="<?php echo esc_url( $wa ); ?>" target="_blank" rel="noopener">WhatsApp पर बात करो</a>
		</div>
	</section>
	<?php endif; ?>

	</div>
	<?php
}
