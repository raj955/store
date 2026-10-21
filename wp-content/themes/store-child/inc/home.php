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
	return array(
		'oversized-t-shirts' => array( 'Oversized tees', '240 GSM, ढीली boxy fit', '#161618' ),
		't-shirts'           => array( 'T-shirts', '180 GSM, रोज़ वाली regular fit', '#1C2340' ),
		'women'              => array( "Women's fits", 'Crop tops और oversized fits', '#4B3566' ),
		'sweatshirts'        => array( 'Sweatshirts', '300 GSM brushed fleece', '#4A3426' ),
		'hoodies'            => array( 'Hoodies', '300 GSM, पहाड़ की ठंड के लिए', '#2F4A36' ),
		'design-your-own'    => array( 'अपना design', 'अपनी line या photo, अपनी tee', '#3A1D2A' ),
	);
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
	$products = wc_get_products(
		array(
			'status'       => 'publish',
			'limit'        => 12,
			'visibility'   => 'catalog',
			'stock_status' => 'instock',
			'category'     => array( $term->slug ),
			'orderby'      => 'menu_order',
			'order'        => 'ASC',
		)
	);
	foreach ( $products as $product ) {
		if ( function_exists( 'store_child_home_is_apparel_product' ) && ! store_child_home_is_apparel_product( $product ) ) {
			continue;
		}
		if ( $product->get_image_id() ) {
			return wp_get_attachment_image( $product->get_image_id(), 'woocommerce_thumbnail', false, array( 'alt' => '', 'loading' => 'lazy' ) );
		}
	}
	return '';
}

/**
 * Strict homepage eligibility: apparel only, with a usable product image and a real buy option.
 * This also protects the homepage when an accessory is accidentally tagged into a clothing category.
 *
 * @param mixed $product Candidate.
 * @return bool
 */
function store_child_home_is_apparel_product( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return false;
	}

	$id = $product->get_id();
	$clothing_categories = array( 'oversized-t-shirts', 't-shirts', 'women', 'sweatshirts', 'hoodies' );
	$non_clothing_categories = array( 'mugs', 'tote-bags', 'accessories', 'bags', 'home-lifestyle' );

	if ( ! has_term( $clothing_categories, 'product_cat', $id ) || has_term( $non_clothing_categories, 'product_cat', $id ) ) {
		return false;
	}

	// Guard against an accessory being incorrectly assigned to a clothing category.
	if ( preg_match( '/\\b(?:mugs?|tote(?:\\s+bags?)?|bags?|bottles?|keychains?|posters?|cushions?|puzzles?|phone\\s+cases?)\\b/i', $product->get_name() ) ) {
		return false;
	}

	return $product->is_visible() && $product->is_purchasable() && $product->is_in_stock() && (bool) $product->get_image_id();
}

/**
 * Ready-design apparel for the homepage. Accessories and unavailable/incomplete products never
 * appear in the hero or featured grid, even if they were miscategorised in WooCommerce.
 *
 * @param int $limit How many.
 * @return WC_Product[]
 */
function store_child_home_products( $limit = 8 ) {
	// Menu order remains the editorial ranking; this plan spreads the first row across apparel.
	$plan = array(
		'oversized-t-shirts', 'women', 'hoodies', 'oversized-t-shirts',
		'women', 'sweatshirts', 't-shirts', 'oversized-t-shirts',
		'hoodies', 'women', 't-shirts', 'sweatshirts',
	);
	$pools  = array();
	$picked = array();

	foreach ( $plan as $slug ) {
		if ( count( $picked ) >= $limit ) {
			break;
		}
		if ( ! isset( $pools[ $slug ] ) ) {
			$pools[ $slug ] = wc_get_products(
				array(
					'status'       => 'publish',
					'limit'        => 18,
					'visibility'   => 'catalog',
					'stock_status' => 'instock',
					'category'     => array( $slug ),
					'orderby'      => 'menu_order',
					'order'        => 'ASC',
				)
			);
		}
		while ( $pools[ $slug ] ) {
			$product = array_shift( $pools[ $slug ] );
			$id      = $product->get_id();
			if ( isset( $picked[ $id ] ) || ! store_child_home_is_apparel_product( $product ) ) {
				continue;
			}
			$picked[ $id ] = $product;
			break;
		}
	}

	// Fill any gaps from the rest of the clothing range without ever using accessories.
	if ( count( $picked ) < $limit ) {
		foreach ( array( 'oversized-t-shirts', 't-shirts', 'women', 'hoodies', 'sweatshirts' ) as $slug ) {
			$candidates = wc_get_products(
				array(
					'status'       => 'publish',
					'limit'        => 30,
					'visibility'   => 'catalog',
					'stock_status' => 'instock',
					'category'     => array( $slug ),
					'orderby'      => 'menu_order',
					'order'        => 'ASC',
				)
			);
			foreach ( $candidates as $product ) {
				if ( count( $picked ) >= $limit ) {
					break 2;
				}
				$id = $product->get_id();
				if ( ! isset( $picked[ $id ] ) && store_child_home_is_apparel_product( $product ) ) {
					$picked[ $id ] = $product;
				}
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
	// Use the first visible oversized garment in the curated grid, not a possibly miscategorised item.
	foreach ( $products as $product ) {
		if ( store_child_home_is_apparel_product( $product ) && has_term( 'oversized-t-shirts', 'product_cat', $product->get_id() ) && (float) $product->get_price() > 0 ) {
			return (float) $product->get_price();
		}
	}
	$prices = array();
	foreach ( $products as $product ) {
		if ( ! store_child_home_is_apparel_product( $product ) ) {
			continue;
		}
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
	$rows = array(
		't-shirts'           => array( 'T-shirt', '180 GSM' ),
		'oversized-t-shirts' => array( 'Oversized', '240 GSM' ),
		'sweatshirts'        => array( 'Sweatshirt', '300 GSM' ),
		'hoodies'            => array( 'Hoodie', '300 GSM' ),
	);
	$out = array();
	foreach ( $rows as $slug => $row ) {
		$min = 0;
		foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => 30, 'visibility' => 'catalog', 'stock_status' => 'instock', 'category' => array( $slug ), 'orderby' => 'menu_order', 'order' => 'ASC' ) ) as $product ) {
			if ( ! store_child_home_is_apparel_product( $product ) || ( function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product ) ) ) {
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
	return $out;
}

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
				<p class="pw-hero__kicker">PANUWEAR · EVERYDAY GRAPHIC STREETWEAR</p>
				<h1 id="pw-hero-title" class="pw-hero__title">अपनी बात।<br>अपना <span>अंदाज़।</span></h1>
				<p class="pw-hero__lede">Original graphic tees, oversized fits और hoodies—पहाड़ की vibe और रोज़मर्रा की बातों से inspired. अपना favourite print चुनो, या अपना design बनाओ।</p>
				<div class="pw-hero__actions">
					<a class="pw-btn pw-btn--haldi" href="<?php echo esc_url( $shop ); ?>">कलेक्शन देखो</a>
					<a class="pw-btn pw-btn--ghost" href="<?php echo esc_url( $custom ); ?>">अपना design बनाओ</a>
				</div>
				<ul class="pw-hero__facts">
					<li>Free shipping</li>
					<li>Cash on delivery</li>
					<li>5–7 working days</li>
				</ul>
			</div>
			<?php if ( $hero ) : ?>
			<div class="pw-hero__art" aria-hidden="true">
				<?php foreach ( $hero as $i => $product ) : ?>
					<a class="pw-hero__card pw-hero__card--<?php echo (int) $i + 1; ?>" href="<?php echo esc_url( $product->get_permalink() ); ?>" tabindex="-1">
						<?php echo wp_get_attachment_image( $product->get_image_id(), 'woocommerce_single', false, array( 'alt' => '', 'loading' => 0 === $i ? 'eager' : 'lazy', 'fetchpriority' => 0 === $i ? 'high' : 'auto' ) ); ?>
					</a>
				<?php endforeach; ?>
							</div>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( $products ) : ?>
	<section class="pw-new" aria-labelledby="pw-new-title">
		<div class="pw-wrap">
			<div class="pw-head">
				<h2 id="pw-new-title" class="pw-h2">चुनिंदा designs</h2>
				<a class="pw-link" href="<?php echo esc_url( $shop ); ?>">सारे designs देखो</a>
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
				<h2 id="pw-bundle-title" class="pw-h2">मोटा कपड़ा,<br>सही दाम।</h2>
				<p>हर दाम में free shipping और cash on delivery शामिल।</p>
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
			<h2 id="pw-why-title" class="pw-h2">Panuwear ही क्यों?</h2>
			<ul class="pw-why__grid">
				<li><h3>अपने designs</h3><p>हर print हमारा अपना है, graphic हो या line। किसी film, meme या brand की नकल नहीं।</p></li>
				<li><h3>Order पर छपती है</h3><p>तुम्हारी tee तुम्हारे order के बाद छपती है, गोदाम में पड़ी नहीं रहती।</p></li>
				<li><h3>Free shipping और COD</h3><p>पूरे India में। ज़्यादातर orders 5 से 7 working days में पहुँचते हैं।</p></li>
				<li><h3>गड़बड़ हुई? हम बदलेंगे</h3><p>Damage या गलत print हो तो 7 दिन में unboxing video भेजो, free में बदल देंगे।</p></li>
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
				<h2 id="pw-story-title" class="pw-h2">हल्द्वानी से,<br>पूरे India तक।</h2>
				<p>Panuwear पहाड़ में शुरू हुआ। हमारे prints में पहाड़, रोज़ की ज़िंदगी और चाय वाली बातें हैं, कहीं तस्वीर में, कहीं शब्दों में। और जो design तुम्हारे दिमाग में है, वो भी हम छाप देते हैं।</p>
				<a class="pw-link pw-link--light" href="<?php echo esc_url( $shop ); ?>">Collection देखो</a>
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
