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
	$args = array(
		'status'     => 'publish',
		'limit'      => $limit,
		'visibility' => 'catalog',
		'orderby'    => array(
			'menu_order' => 'ASC',
			'date'       => 'DESC',
		),
		'stock_status' => 'instock',
	);
	$custom = get_term_by( 'slug', 'design-your-own', 'product_cat' );
	if ( $custom ) {
		$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => array( $custom->term_id ),
				'operator' => 'NOT IN',
			),
		);
	}
	return wc_get_products( $args );
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

	<section class="pw-hero" aria-labelledby="pw-hero-title">
		<div class="pw-wrap pw-hero__inner">
			<div class="pw-hero__copy">
				<p class="pw-hand pw-hero__kicker">Printed tees, hoodies और sweatshirts</p>
				<h1 id="pw-hero-title" class="pw-hero__title">Design चुनो,<br>या <span>अपना</span> बनाओ।</h1>
				<p class="pw-hero__lede">हमारे अपने graphic prints और देसी lines, oversized tees, hoodies और sweatshirts पर। पसंद का design न मिले तो अपनी photo, logo या line डालो, हम वही छाप देंगे। Free shipping, cash on delivery भी।</p>
				<div class="pw-hero__actions">
					<a class="pw-btn pw-btn--haldi" href="<?php echo esc_url( $shop ); ?>">Designs देखो</a>
					<a class="pw-btn pw-btn--ghost" href="<?php echo esc_url( $custom ); ?>">अपना design बनाओ</a>
				</div>
				<ul class="pw-hero__facts">
					<li>Free shipping</li>
					<li>Cash on delivery</li>
					<li>5 से 7 दिन में delivery</li>
				</ul>
			</div>
			<?php if ( $hero ) : ?>
			<div class="pw-hero__art" aria-hidden="true">
				<?php foreach ( $hero as $i => $product ) : ?>
					<a class="pw-hero__card pw-hero__card--<?php echo (int) $i + 1; ?>" href="<?php echo esc_url( $product->get_permalink() ); ?>" tabindex="-1">
						<?php echo wp_get_attachment_image( $product->get_image_id(), 'woocommerce_single', false, array( 'alt' => '', 'loading' => 0 === $i ? 'eager' : 'lazy', 'fetchpriority' => 0 === $i ? 'high' : 'auto' ) ); ?>
					</a>
				<?php endforeach; ?>
				<p class="pw-hand pw-hero__note">पहन के देखो, फिर बोलना</p>
			</div>
			<?php endif; ?>
		</div>
	</section>

	<section class="pw-moods" aria-labelledby="pw-moods-title">
		<div class="pw-wrap">
			<h2 id="pw-moods-title" class="pw-h2">आज का mood?</h2>
			<p class="pw-sub">Fit चुनो, design हम दिखाते हैं।</p>
			<ul class="pw-moods__grid">
				<?php
				foreach ( store_child_home_collections() as $slug => $card ) :
					$term = get_term_by( 'slug', $slug, 'product_cat' );
					if ( ! $term || ( ! $term->count && 'design-your-own' !== $slug ) ) {
						continue;
					}
					?>
					<li>
						<a class="pw-mood" href="<?php echo esc_url( get_term_link( $term ) ); ?>" style="--card:<?php echo esc_attr( $card[2] ); ?>">
							<span class="pw-mood__img"><?php echo store_child_home_term_image( $term ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="pw-mood__name"><?php echo esc_html( $card[0] ); ?></span>
							<span class="pw-mood__line"><?php echo esc_html( $card[1] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</section>

	<?php if ( $products ) : ?>
	<section class="pw-new" aria-labelledby="pw-new-title">
		<div class="pw-wrap">
			<div class="pw-head">
				<h2 id="pw-new-title" class="pw-h2">सबके favourite</h2>
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

	<?php if ( $tiers && $price > 0 ) : ?>
	<section class="pw-bundle" aria-labelledby="pw-bundle-title">
		<div class="pw-wrap pw-bundle__inner">
			<div class="pw-bundle__copy">
				<h2 id="pw-bundle-title" class="pw-h2">जितनी ज़्यादा,<br>उतना सस्ता।</h2>
				<p>कोई भी design, कोई भी size मिलाओ। Discount cart में अपने-आप लगता है, कोई code नहीं।</p>
			</div>
			<ul class="pw-bundle__tiers">
				<li><span class="pw-bundle__qty">1 oversized tee</span><span class="pw-bundle__price"><?php echo esc_html( store_child_rupees( $price ) ); ?></span></li>
				<?php
				foreach ( $tiers as $qty => $pct ) :
					$full = $price * $qty;
					$off  = round( $full * $pct / 100 );
					?>
					<li class="<?php echo $qty === $last ? 'is-best' : ''; ?>">
						<span class="pw-bundle__qty"><?php echo esc_html( $qty . ( $qty === $last ? '+' : '' ) . ' tees, ' . $pct . '% off' ); ?></span>
						<span class="pw-bundle__price"><?php echo esc_html( store_child_rupees( $full - $off ) ); ?></span>
						<span class="pw-bundle__save"><?php echo esc_html( store_child_rupees( $off ) . ' बचे' ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! empty( $offer['coupon'] ) ) : ?>
				<p class="pw-bundle__code">पहला order? Code <strong><?php echo esc_html( strtoupper( $offer['coupon'] ) ); ?></strong> से <?php echo esc_html( store_child_rupees( $offer['coupon_off'] ) ); ?> और कम (<?php echo esc_html( store_child_rupees( $offer['coupon_min'] ) ); ?> या ज़्यादा के order पर)।</p>
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
