<?php
/**
 * Two clear ways to shop, and add to cart straight from the product cards.
 *
 * - "Ready designs" and "Design your own" are offered side by side (home page shortcode
 *   [sf_choice], and at the top of the shop and category pages). The shop's main grid shows only
 *   ready designs; the blanks for your own design live in their own category.
 * - Each ready-design card has an "Add to cart" button that opens a small panel with colour and
 *   size, so shoppers can buy without opening the product. Tapping the photo or name still opens
 *   the full product page. "Design your own" cards say "Design yours" and open the designer.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

const STORE_FEATURES_CUSTOM_CAT = 'design-your-own';

/**
 * The shop's main grid (and "All") shows ready designs only.
 *
 * @param WP_Query $query Product query.
 */
function store_features_shop_ready_only( $query ) {
	if ( is_shop() && ! is_search() ) {
		$tax   = (array) $query->get( 'tax_query' );
		$tax[] = array(
			'taxonomy' => 'product_cat',
			'field'    => 'slug',
			'terms'    => array( STORE_FEATURES_CUSTOM_CAT ),
			'operator' => 'NOT IN',
		);
		$query->set( 'tax_query', $tax );
	}
}
add_action( 'woocommerce_product_query', 'store_features_shop_ready_only' );

/**
 * Featured image of a product, by slug.
 *
 * @param string $slug Product slug.
 * @param string $size Image size.
 * @return string
 */
function store_features_product_image_by_slug( $slug, $size = 'woocommerce_thumbnail' ) {
	$post = get_page_by_path( $slug, OBJECT, 'product' );
	return $post && has_post_thumbnail( $post ) ? (string) get_the_post_thumbnail_url( $post, $size ) : '';
}

/**
 * The two choices.
 *
 * @param string $current ready|own|'' (which one is open now).
 * @param bool   $compact Smaller version for the top of shop pages.
 * @return string
 */
function store_features_choice_html( $current = '', $compact = false ) {
	$choices = array(
		'ready' => array(
			'url'   => wc_get_page_permalink( 'shop' ),
			'img'   => store_features_product_image_by_slug( 'befikr-oversized-tee' ),
			'title' => __( 'Ready designs', 'store-features' ),
			'text'  => __( 'Our own graphics and lines, ready to order. Cash on delivery.', 'store-features' ),
			'cta'   => __( 'Shop designs', 'store-features' ),
		),
		'own'   => array(
			'url'   => get_term_link( STORE_FEATURES_CUSTOM_CAT, 'product_cat' ),
			'img'   => store_features_product_image_by_slug( 'design-your-own-t-shirt' ),
			'title' => __( 'Design your own', 'store-features' ),
			'text'  => __( 'Your name, your line or your photo. See it before you order.', 'store-features' ),
			'cta'   => __( 'Start designing', 'store-features' ),
		),
	);
	$html = '<nav class="sf-choice' . ( $compact ? ' sf-choice--compact' : '' ) . '" aria-label="' . esc_attr__( 'Ways to shop', 'store-features' ) . '">';
	foreach ( $choices as $key => $c ) {
		if ( is_wp_error( $c['url'] ) ) {
			continue;
		}
		$html .= sprintf(
			'<a class="sf-choice__card sf-choice__card--%1$s" href="%2$s"%3$s>%4$s<span class="sf-choice__body"><span class="sf-choice__title">%5$s</span><span class="sf-choice__text">%6$s</span><span class="sf-choice__cta">%7$s</span></span></a>',
			esc_attr( $key ),
			esc_url( $c['url'] ),
			$current === $key ? ' aria-current="page"' : '',
			$c['img'] ? '<img class="sf-choice__img" src="' . esc_url( $c['img'] ) . '" alt="" width="300" height="375" loading="lazy">' : '',
			esc_html( $c['title'] ),
			esc_html( $c['text'] ),
			esc_html( $c['cta'] )
		);
	}
	return $html . '</nav>';
}

/**
 * [sf_choice] for the home page.
 *
 * @return string
 */
function store_features_choice_shortcode() {
	return store_features_choice_html();
}
add_shortcode( 'sf_choice', 'store_features_choice_shortcode' );

/**
 * The choice at the top of the shop and category pages.
 */
function store_features_choice_on_archives() {
	if ( is_shop() || is_product_category() ) {
		$own = is_product_category( STORE_FEATURES_CUSTOM_CAT );
		echo store_features_choice_html( $own ? 'own' : 'ready', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
add_action( 'woocommerce_archive_description', 'store_features_choice_on_archives', 15 );

/**
 * Everything the quick-add panel needs for one product.
 *
 * @param WC_Product_Variable $product Product.
 * @return array|null
 */
/**
 * Clear the short-lived quick-add cache when a product or variation changes.
 *
 * @param mixed $product Product object or product ID.
 */
function store_features_clear_quick_add_cache( $product ) {
	$product_id = $product instanceof WC_Product ? $product->get_id() : absint( $product );
	if ( ! $product_id ) {
		return;
	}
	delete_transient( 'sf_quick_add_' . $product_id );
	$parent_id = wp_get_post_parent_id( $product_id );
	if ( $parent_id ) {
		delete_transient( 'sf_quick_add_' . $parent_id );
	}
}
add_action( 'woocommerce_product_object_updated_props', 'store_features_clear_quick_add_cache', 10, 1 );
add_action( 'woocommerce_product_set_stock', 'store_features_clear_quick_add_cache', 10, 1 );
add_action( 'woocommerce_variation_set_stock', 'store_features_clear_quick_add_cache', 10, 1 );
add_action( 'woocommerce_product_set_stock_status', 'store_features_clear_quick_add_cache', 10, 1 );
add_action( 'woocommerce_variation_set_stock_status', 'store_features_clear_quick_add_cache', 10, 1 );
add_action( 'save_post_product', 'store_features_clear_quick_add_cache', 10, 1 );
add_action( 'save_post_product_variation', 'store_features_clear_quick_add_cache', 10, 1 );

/**
 * Build the quick-add payload for a product card. Cache briefly because variation data is
 * expensive to assemble for every product on every shop/archive request. Product and stock
 * update hooks invalidate the affected product payload immediately.
 *
 * @param WC_Product $product Product.
 * @return array|null
 */
/**
 * Recalculate customer-context-sensitive price and stock fields on every request.
 *
 * Product metadata can be cached, but prices may vary by tax display, currency, or
 * pricing extensions, so never serve a cached price to a different request context.
 *
 * @param array      $data    Cached quick-add metadata.
 * @param WC_Product $product Product.
 * @return array
 */
function store_features_quick_add_live_values( $data, $product ) {
	$data['price'] = html_entity_decode( wp_strip_all_tags( $product->get_price_html() ), ENT_QUOTES, 'UTF-8' );

	foreach ( (array) $data['variations'] as $key => $row ) {
		$variation = wc_get_product( absint( $row['id'] ?? 0 ) );
		if ( ! $variation || ! $variation->is_purchasable() ) {
			unset( $data['variations'][ $key ] );
			continue;
		}

		$data['variations'][ $key ]['stock'] = $variation->is_in_stock();
		$data['variations'][ $key ]['price'] = html_entity_decode(
			wp_strip_all_tags( wc_price( wc_get_price_to_display( $variation ) ) ),
			ENT_QUOTES,
			'UTF-8'
		);
	}

	return $data;
}

/**
 * Build the quick-add payload for a product card. Cache only product metadata briefly;
 * calculate customer-context-sensitive prices and live stock state for every request.
 *
 * @param WC_Product $product Product.
 * @return array|null
 */
function store_features_quick_add_data( $product ) {
	$cache_key = 'sf_quick_add_v2_' . $product->get_id();
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return store_features_quick_add_live_values( $cached, $product );
	}

	$attributes = $product->get_variation_attributes();
	if ( ! isset( $attributes['pa_color'], $attributes['pa_size'] ) ) {
		return null;
	}
	$colours = array();
	foreach ( wc_get_product_terms( $product->get_id(), 'pa_color', array( 'fields' => 'all' ) ) as $term ) {
		if ( in_array( $term->slug, (array) $attributes['pa_color'], true ) ) {
			$colours[] = array(
				'slug' => $term->slug,
				'name' => $term->name,
				'hex'  => (string) get_term_meta( $term->term_id, 'cfvsw_color', true ),
			);
		}
	}
	$sizes = array();
	foreach ( wc_get_product_terms( $product->get_id(), 'pa_size', array( 'fields' => 'all' ) ) as $term ) {
		if ( in_array( $term->slug, (array) $attributes['pa_size'], true ) ) {
			$sizes[] = array( 'slug' => $term->slug, 'name' => $term->name );
		}
	}

	$variations = array();
	foreach ( $product->get_children() as $child_id ) {
		$variation = wc_get_product( $child_id );
		if ( ! $variation || ! $variation->is_purchasable() ) {
			continue;
		}
		$va  = $variation->get_variation_attributes( false );
		$key = ( $va['pa_color'] ?? '' ) . '|' . ( $va['pa_size'] ?? '' );
		$img = $variation->get_image_id() ? wp_get_attachment_image_url( $variation->get_image_id(), 'woocommerce_thumbnail' ) : '';
		$variations[ $key ] = array(
			'id'    => $child_id,
			'stock' => $variation->is_in_stock(),
			'img'   => $img ? $img : '',
		);
	}

	$defaults = $product->get_default_attributes();
	$data = array(
		'id'         => $product->get_id(),
		'name'       => $product->get_name(),
		'url'        => $product->get_permalink(),
		'img'        => (string) get_the_post_thumbnail_url( $product->get_id(), 'woocommerce_thumbnail' ),
		'colours'    => $colours,
		'sizes'      => $sizes,
		'variations' => $variations,
		'colour'     => $defaults['pa_color'] ?? ( $colours[0]['slug'] ?? '' ),
		'fit'        => function_exists( 'store_features_size_fit' ) ? store_features_size_fit( $product ) : array(),
	);
	set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );
	return store_features_quick_add_live_values( $data, $product );
}

/**
 * The card button: "Add to cart" (opens the colour and size panel) or "Design yours".
 *
 * @param string     $html    Default button.
 * @param WC_Product $product Product.
 * @return string
 */
function store_features_card_button( $html, $product ) {
	if ( store_features_is_custom_product( $product ) ) {
		return sprintf( '<a href="%s" class="button sf-card-btn sf-card-btn--design">%s</a>', esc_url( $product->get_permalink() ), esc_html__( 'Design yours', 'store-features' ) );
	}
	if ( ! $product->is_type( 'variable' ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
		return $html;
	}
	$data = store_features_quick_add_data( $product );
	if ( ! $data ) {
		return $html;
	}
	return sprintf(
		'<button type="button" class="button sf-card-btn sf-quick-add" aria-haspopup="dialog" data-sf-quick="%1$s" aria-label="%2$s">%3$s</button>',
		esc_attr( wp_json_encode( $data ) ),
		/* translators: %s: product name. */
		esc_attr( sprintf( __( 'Add %s to cart', 'store-features' ), $product->get_name() ) ),
		esc_html__( 'Add to cart', 'store-features' )
	);
}
add_filter( 'woocommerce_loop_add_to_cart_link', 'store_features_card_button', 20, 2 );

/**
 * Script and styles.
 */
function store_features_quick_add_assets() {
	if ( is_admin() ) {
		return;
	}
	wp_add_inline_style( 'store-features', store_features_quick_add_style() );
	wp_add_inline_script( 'store-features', store_features_quick_add_script() );
}
add_action( 'wp_enqueue_scripts', 'store_features_quick_add_assets', 33 );

/**
 * Quick-add panel.
 *
 * @return string
 */
function store_features_quick_add_script() {
	return <<<'JS'
(function ($) {
	'use strict';
	var cfg = window.storeFeatures || {};
	var dialog, current, state;
	var CLOSE = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text) { e.textContent = text; }
		return e;
	}

	function build() {
		dialog = el('dialog', 'sf-qa');
		dialog.setAttribute('aria-labelledby', 'sf-qa-name');
		dialog.innerHTML =
			'<div class="sf-qa__inner">' +
			'<button type="button" class="sf-qa__close" aria-label="Close">' + CLOSE + '</button>' +
			'<div class="sf-qa__head"><img class="sf-qa__img" alt="" width="96" height="120">' +
			'<div><h2 class="sf-qa__name" id="sf-qa-name"></h2><p class="sf-qa__price"></p>' +
			'<a class="sf-qa__details">View details</a></div></div>' +
			'<fieldset class="sf-qa__group"><legend class="sf-qa__legend">Colour: <span class="sf-qa__colour-name"></span></legend><div class="sf-qa__colours"></div></fieldset>' +
			'<fieldset class="sf-qa__group"><legend class="sf-qa__legend sf-qa__legend--row">Size <a class="sf-qa__chart" href="/size-guide/">Size chart</a></legend><div class="sf-qa__sizes"></div><p class="sf-qa__fit" aria-live="polite"></p></fieldset>' +
			'<p class="sf-qa__msg" role="alert" hidden></p>' +
			'<button type="button" class="button sf-qa__add">Add to cart</button>' +
			'</div>';
		document.body.appendChild(dialog);
		dialog.querySelector('.sf-qa__close').addEventListener('click', function () { dialog.close(); });
		dialog.addEventListener('click', function (e) { if (e.target === dialog) { dialog.close(); } });
		dialog.querySelector('.sf-qa__add').addEventListener('click', add);
	}

	function variation() {
		return current.variations[state.colour + '|' + state.size];
	}

	function msg(text) {
		var m = dialog.querySelector('.sf-qa__msg');
		m.textContent = text || '';
		m.hidden = !text;
	}

	function refresh() {
		var colour = current.colours.filter(function (c) { return c.slug === state.colour; })[0];
		dialog.querySelector('.sf-qa__colour-name').textContent = colour ? colour.name : '';
		var anyForColour = null;
		dialog.querySelectorAll('.sf-qa__size input').forEach(function (input) {
			var v = current.variations[state.colour + '|' + input.value];
			var ok = !!(v && v.stock);
			input.disabled = !ok;
			input.closest('label').classList.toggle('is-out', !ok);
			if (!input.checked) { return; }
			if (!ok) { input.checked = false; state.size = ''; }
		});
		Object.keys(current.variations).some(function (key) {
			if (key.indexOf(state.colour + '|') === 0) { anyForColour = current.variations[key]; return true; }
			return false;
		});
		var v = variation() || anyForColour;
		var img = (v && v.img) || current.img;
		if (img) { dialog.querySelector('.sf-qa__img').src = img; }
		dialog.querySelector('.sf-qa__price').textContent = (variation() && variation().price) || current.price;
		var size = current.sizes.filter(function (s) { return s.slug === state.size; })[0];
		dialog.querySelector('.sf-qa__fit').innerHTML = size && window.sfFitText ? window.sfFitText(current.fit, size.slug, size.name) : '';
	}

	function open(btn) {
		if (!dialog) { build(); }
		try { current = JSON.parse(btn.getAttribute('data-sf-quick')); } catch (e) { return; }
		var card = btn.closest('li.product');
		var cardColour = card && card.querySelector('select[name="attribute_pa_color"]');
		state = { colour: (cardColour && cardColour.value) || current.colour, size: '', btn: btn };
		dialog.querySelector('.sf-qa__name').textContent = current.name;
		dialog.querySelector('.sf-qa__details').href = current.url;
		dialog.querySelector('.sf-qa__chart').href = current.url + '#size-chart';
		msg('');

		var colours = dialog.querySelector('.sf-qa__colours');
		colours.innerHTML = '';
		current.colours.forEach(function (c) {
			var label = el('label', 'sf-qa__colour');
			label.title = c.name;
			var input = el('input');
			input.type = 'radio';
			input.name = 'sf_qa_colour';
			input.value = c.slug;
			input.checked = c.slug === state.colour;
			var dot = el('span', 'sf-qa__dot');
			dot.style.background = c.hex || '#ccc';
			label.appendChild(input);
			label.appendChild(dot);
			label.appendChild(el('span', 'screen-reader-text', c.name));
			input.addEventListener('change', function () { state.colour = c.slug; msg(''); refresh(); });
			colours.appendChild(label);
		});

		var sizes = dialog.querySelector('.sf-qa__sizes');
		sizes.innerHTML = '';
		current.sizes.forEach(function (s) {
			var label = el('label', 'sf-qa__size');
			var input = el('input');
			input.type = 'radio';
			input.name = 'sf_qa_size';
			input.value = s.slug;
			label.appendChild(input);
			label.appendChild(el('span', '', s.name));
			input.addEventListener('change', function () { state.size = s.slug; msg(''); refresh(); });
			sizes.appendChild(label);
		});

		refresh();
		dialog.showModal();
		var first = dialog.querySelector('.sf-qa__size input:not(:disabled)');
		if (first) { first.focus(); }
	}

	function add() {
		var addBtn = dialog.querySelector('.sf-qa__add');
		if (!state.size) {
			msg('Please choose your size.');
			return;
		}
		var v = variation();
		if (!v || !v.stock) {
			msg('This size is sold out in this colour.');
			return;
		}
		addBtn.classList.add('loading');
		addBtn.disabled = true;
		$.post(cfg.addToCartUrl, { product_id: v.id, quantity: 1 })
			.done(function (res) {
				if (!res || res.error) {
					window.location.href = current.url;
					return;
				}
				dialog.close();
				$(document.body).trigger('added_to_cart', [res.fragments, res.cart_hash, $(state.btn)]);
			})
			.fail(function () { msg('That did not work. Please try again.'); })
			.always(function () { addBtn.classList.remove('loading'); addBtn.disabled = false; });
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest && e.target.closest('.sf-quick-add');
		if (btn && typeof HTMLDialogElement !== 'undefined') {
			e.preventDefault();
			open(btn);
		} else if (btn) {
			window.location.href = JSON.parse(btn.getAttribute('data-sf-quick')).url;
		}
	});
})(jQuery);
JS;
}

/**
 * Styles for the choice cards, card buttons and quick-add panel.
 *
 * @return string
 */
function store_features_quick_add_style() {
	return <<<'CSS'
.sf-choice { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin: 0 0 28px; }
.sf-choice__card { display: flex; align-items: center; gap: 18px; min-height: 100%; padding: 16px; border: 1px solid var(--line, #DCDEE6); border-radius: var(--radius, 4px); background: #fff; color: var(--ink, #2B3150); text-decoration: none !important; transition: border-color .15s ease, box-shadow .15s ease; }
.sf-choice__card:hover { border-color: var(--neel, #1E2650); }
.sf-choice__card:focus-visible { outline: 2px solid var(--neel, #1E2650); outline-offset: 2px; }
.sf-choice__card[aria-current] { border-color: var(--neel, #1E2650); box-shadow: inset 0 0 0 1px var(--neel, #1E2650); }
.sf-choice__img { flex: 0 0 auto; width: 120px; height: auto; aspect-ratio: 4 / 5; object-fit: contain; border-radius: 10px; background: var(--studio, #F0EEE8); }
.sf-choice__body { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.sf-choice__title { color: var(--heading, #171C3A); font-size: 20px; font-weight: 700; line-height: 1.25; }
.sf-choice__text { color: var(--muted, #565D7A); font-size: 15px; line-height: 1.45; }
.sf-choice__cta { margin-top: 6px; color: var(--neel, #1E2650); font-size: 15px; font-weight: 700; text-decoration: underline; text-underline-offset: 3px; }
.sf-choice__card[aria-current] .sf-choice__cta { text-decoration: none; }
.sf-choice--compact { margin: 18px 0 16px; gap: 10px; }
.sf-choice--compact .sf-choice__card { gap: 12px; padding: 10px 12px; }
.sf-choice--compact .sf-choice__img { width: 56px; }
.sf-choice--compact .sf-choice__title { font-size: 16px; }
.sf-choice--compact .sf-choice__text, .sf-choice--compact .sf-choice__cta { display: none; }
@media (max-width: 767px) {
	.sf-choice { grid-template-columns: 1fr; gap: 12px; }
	.sf-choice__img { width: 88px; }
	.sf-choice__title { font-size: 18px; }
	.sf-choice--compact { grid-template-columns: repeat(2, minmax(0, 1fr)); }
	.sf-choice--compact .sf-choice__card { flex-direction: column; align-items: flex-start; gap: 8px; padding: 10px; }
	.sf-choice--compact .sf-choice__img { display: none; }
	.sf-choice--compact .sf-choice__title { font-size: 15px; }
}

li.product .sf-card-btn { display: flex; align-items: center; justify-content: center; width: 100%; min-height: 44px; margin-top: 10px; padding: 10px 12px; font-size: 15px; text-align: center; }
li.product .sf-card-btn--design { background: #fff; border: 1px solid var(--neel, #1E2650); color: var(--neel, #1E2650); }
li.product .sf-card-btn--design:hover { background: var(--neel, #1E2650); color: #fff; }

.sf-qa { width: min(440px, calc(100vw - 32px)); max-height: calc(100vh - 32px); padding: 0; border: 0; border-radius: 8px; color: var(--ink, #2B3150); box-shadow: 0 22px 60px rgba(25, 29, 24, .18); }
.sf-qa::backdrop { background: rgba(24, 27, 23, .62); }
.sf-qa__inner { position: relative; padding: 20px 20px 18px; }
.sf-qa__close { position: absolute; top: 8px; right: 8px; display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; padding: 0; border: 0; background: transparent; color: var(--heading, #171C3A); cursor: pointer; }
.sf-qa__head { display: flex; gap: 14px; align-items: flex-start; padding-right: 36px; }
.sf-qa__img { flex: 0 0 auto; width: 84px; height: auto; aspect-ratio: 4 / 5; object-fit: cover; border-radius: 10px; background: var(--studio, #F0EEE8); }
.sf-qa__name { margin: 2px 0 4px; font-size: 18px; line-height: 1.3; }
.sf-qa__price { margin: 0 0 6px; color: var(--heading, #171C3A); font-weight: 700; }
.sf-qa__details { color: var(--neel, #1E2650); font-size: 14px; font-weight: 600; text-decoration: underline; text-underline-offset: 3px; }
.sf-qa__group { position: relative; margin: 18px 0 0; padding: 0; border: 0; }
.sf-qa__legend { margin: 0 0 10px; padding: 0; color: var(--heading, #171C3A); font-size: 15px; font-weight: 600; }
.sf-qa__legend span { color: var(--muted, #565D7A); font-weight: 400; }
.sf-qa__legend--row { display: flex; align-items: baseline; justify-content: space-between; width: 100%; }
.sf-qa__chart { color: var(--neel, #1E2650); font-size: 14px; font-weight: 600; text-decoration: underline; text-underline-offset: 3px; }
.sf-qa__colours, .sf-qa__sizes { display: flex; flex-wrap: wrap; gap: 8px; }
.sf-qa__colour, .sf-qa__size { position: relative; cursor: pointer; }
.sf-qa__colour input, .sf-qa__size input { position: absolute; opacity: 0; pointer-events: none; }
.sf-qa__dot { display: block; width: 36px; height: 36px; border: 1px solid rgba(23, 28, 58, .2); border-radius: 50%; box-shadow: 0 0 0 3px #fff; }
.sf-qa__colour input:checked + .sf-qa__dot { box-shadow: 0 0 0 3px #fff, 0 0 0 5px var(--neel, #1E2650); }
.sf-qa__colour input:focus-visible + .sf-qa__dot { outline: 2px solid var(--neel, #1E2650); outline-offset: 6px; }
.sf-qa__size span { display: flex; align-items: center; justify-content: center; min-width: 48px; height: 44px; padding: 0 8px; border: 1px solid var(--line, #DCDEE6); border-radius: var(--radius, 4px); color: var(--heading, #171C3A); font-size: 15px; font-weight: 600; }
.sf-qa__size input:checked + span { border-color: var(--neel, #1E2650); background: var(--neel, #1E2650); color: #fff; }
.sf-qa__size input:focus-visible + span { outline: 2px solid var(--neel, #1E2650); outline-offset: 2px; }
.sf-qa__size.is-out span { color: var(--subtle, #8B90A6); text-decoration: line-through; cursor: not-allowed; }
.sf-qa__fit { margin: 10px 0 0; color: var(--ink, #2B3150); font-size: 14px; }
.sf-qa__fit:empty { display: none; }
.sf-qa__fit strong { color: var(--heading, #171C3A); }
.sf-qa__msg { margin: 14px 0 0; color: #A3122F; font-size: 14px; font-weight: 600; }
.sf-qa__msg[hidden] { display: none; }
.sf-qa__add.button { width: 100%; min-height: 50px; margin-top: 18px; font-size: 16px; }
@media (max-width: 767px) {
	.sf-qa { width: 100%; max-width: none; max-height: 88vh; margin: auto 0 0; border-radius: 14px 14px 0 0; }
	.sf-qa__inner { padding-bottom: calc(18px + env(safe-area-inset-bottom)); }
}
CSS;
}
