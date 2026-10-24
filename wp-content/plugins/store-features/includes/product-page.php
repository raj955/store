<?php
/**
 * Product page polish, borrowed from what works on the best Indian merch stores:
 *
 * - After a size is picked, one line says how it fits ("M fits chest 44 in, length 29 in"),
 *   read from the size chart in the product description. The same line shows in the
 *   Add to cart panel on product cards.
 * - Details, size chart, delivery, returns and reviews sit in tidy expandable sections
 *   instead of tabs, which are cramped on phones.
 * - A WhatsApp chat button once a support number is set.
 * - The small "Category:" line under the button is dropped.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * Size chart of a product, read from the table in its description.
 *
 * @param WC_Product $product Product (or variation).
 * @return array<string, string> Size slug => "chest 44 in, length 29 in".
 */
function store_features_size_fit( $product ) {
	$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
	if ( ! $parent ) {
		return array();
	}
	$html = (string) $parent->get_description();
	if ( false === strpos( $html, 'size-chart' ) || ! class_exists( 'DOMDocument' ) ) {
		return array();
	}
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="UTF-8"?>' . $html );
	libxml_clear_errors();
	$xpath = new DOMXPath( $doc );
	$table = $xpath->query( "//table[contains(concat(' ', normalize-space(@class), ' '), ' size-chart ')]" )->item( 0 );
	if ( ! $table ) {
		return array();
	}
	$heads = array();
	foreach ( $xpath->query( './/thead//th', $table ) as $th ) {
		$heads[] = strtolower( trim( $th->textContent ) );
	}
	$fit = array();
	foreach ( $xpath->query( './/tbody/tr', $table ) as $tr ) {
		$cells = array();
		foreach ( $tr->childNodes as $cell ) {
			if ( XML_ELEMENT_NODE === $cell->nodeType ) {
				$cells[] = trim( $cell->textContent );
			}
		}
		if ( count( $cells ) < 2 ) {
			continue;
		}
		$parts = array();
		for ( $i = 1; $i < count( $cells ); $i++ ) {
			if ( '' !== $cells[ $i ] && isset( $heads[ $i ] ) ) {
				$parts[] = $heads[ $i ] . ' ' . $cells[ $i ] . ' in';
			}
		}
		$fit[ sanitize_title( $cells[0] ) ] = implode( ', ', $parts );
	}
	return $fit;
}

/**
 * Product page settings for the script below.
 */
function store_features_product_page_assets() {
	if ( is_admin() ) {
		return;
	}
	wp_add_inline_style( 'store-features', store_features_product_page_style() );
	$phone = preg_replace( '/\D/', '', (string) get_option( 'store_features_phone', '' ) );
	if ( strlen( $phone ) >= 10 ) {
		wp_add_inline_script( 'store-features', 'window.sfWhatsApp=' . wp_json_encode( array( 'number' => ( 10 === strlen( $phone ) ? '91' : '' ) . $phone ) ) . ';', 'before' );
	}
	if ( is_product() ) {
		$product = wc_get_product( get_queried_object_id() );
		if ( $product ) {
			wp_add_inline_script( 'store-features', 'window.sfSizeFit=' . wp_json_encode( store_features_size_fit( $product ) ) . ';', 'before' );
		}
	}
	wp_add_inline_script( 'store-features', store_features_product_page_script() );
}
add_action( 'wp_enqueue_scripts', 'store_features_product_page_assets', 34 );

/**
 * A short brand hook and practical reassurance directly under the product title.
 * Keep this copy about the design and shopping experience; don't invent fabric claims.
 */
function store_features_product_hook() {
	if ( ! is_product() ) {
		return;
	}

	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$custom = function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product );
	if ( $custom ) {
		$headline = __( 'Tumhara idea. Tumhara design.', 'store-features' );
		$note     = __( 'Apni line, photo ya idea ko apne style mein lao.', 'store-features' );
	} else {
		$headline = __( 'Apna mood pehno. Apni story dikhao.', 'store-features' );
		$note     = __( 'Desi humour, pahाड़ी feels aur everyday moods—pick the print that feels like you.', 'store-features' );
	}

	echo '<div class="sf-product-hook">';
	echo '<p class="sf-product-hook__headline">' . esc_html( $headline ) . '</p>';
	echo '<p class="sf-product-hook__note">' . esc_html( $note ) . '</p>';
	echo '<ul class="sf-product-promises">';
	echo '<li>' . esc_html__( 'Free shipping across India', 'store-features' ) . '</li>';
	if ( $custom ) {
		echo '<li>' . esc_html__( 'Made for your design', 'store-features' ) . '</li>';
		echo '<li>' . esc_html__( 'Pay online', 'store-features' ) . '</li>';
	} else {
		echo '<li>' . esc_html__( 'Cash on delivery available', 'store-features' ) . '</li>';
		echo '<li>' . esc_html__( 'Size chart before you choose', 'store-features' ) . '</li>';
	}
	echo '</ul>';
	echo '</div>';
}
add_action( 'woocommerce_single_product_summary', 'store_features_product_hook', 6 );

/**
 * Expandable sections instead of tabs.
 */
function store_features_product_sections() {
	$tabs = apply_filters( 'woocommerce_product_tabs', array() );
	if ( empty( $tabs ) ) {
		return;
	}
	echo '<div class="sf-sections">';
	$first = true;
	foreach ( $tabs as $key => $tab ) {
		if ( empty( $tab['callback'] ) ) {
			continue;
		}
		$title = isset( $tab['title'] ) ? wp_strip_all_tags( apply_filters( 'woocommerce_product_' . $key . '_tab_title', $tab['title'], $key ) ) : $key;
		printf( '<details class="sf-section sf-section--%1$s" id="tab-%1$s"%2$s><summary>%3$s</summary><div class="sf-section__body woocommerce-Tabs-panel woocommerce-Tabs-panel--%1$s">', esc_attr( $key ), $first ? ' open' : '', esc_html( $title ) );
		call_user_func( $tab['callback'], $key, $tab );
		echo '</div></details>';
		$first = false;
	}
	echo '</div>';
}

/**
 * Swap the tabs for the sections, and drop the category line.
 */
function store_features_product_page_setup() {
	if ( ! is_product() ) {
		return;
	}
	remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10 );
	add_action( 'woocommerce_after_single_product_summary', 'store_features_product_sections', 10 );
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
}
add_action( 'wp', 'store_features_product_page_setup', 20 );

/**
 * Delivery and returns as their own sections, so the answers are on the page.
 *
 * @param array $tabs Tabs.
 * @return array
 */
function store_features_delivery_section( $tabs ) {
	$tabs['sf_delivery'] = array(
		'title'    => __( 'Delivery and returns', 'store-features' ),
		'priority' => 25,
		'callback' => 'store_features_delivery_section_content',
	);
	return $tabs;
}
add_filter( 'woocommerce_product_tabs', 'store_features_delivery_section', 97 );

/**
 * Content of "Delivery and returns".
 */
function store_features_delivery_section_content() {
	global $product;
	$custom = function_exists( 'store_features_is_custom_product' ) && store_features_is_custom_product( $product );
	echo '<ul>';
	echo '<li>' . esc_html__( 'Free shipping anywhere in India.', 'store-features' ) . '</li>';
	echo '<li>' . esc_html__( 'Packed in 2 to 3 working days; most orders arrive 5 to 7 working days after you order.', 'store-features' ) . '</li>';
	if ( $custom ) {
		echo '<li>' . esc_html__( 'Paid online. Made just for you, so it can\'t be returned or exchanged.', 'store-features' ) . '</li>';
	} else {
		echo '<li>' . esc_html__( 'Cash on delivery available.', 'store-features' ) . '</li>';
		echo '<li>' . esc_html__( 'No returns or size exchanges, so please check the size chart.', 'store-features' ) . '</li>';
	}
	echo '<li>' . esc_html__( 'Damaged or misprinted? Tell us within 7 days with an unboxing video and we replace it free.', 'store-features' ) . '</li>';
	echo '</ul>';
	printf(
		'<p><a href="%1$s">%2$s</a> · <a href="%3$s">%4$s</a></p>',
		esc_url( home_url( '/shipping/' ) ),
		esc_html__( 'Shipping policy', 'store-features' ),
		esc_url( home_url( '/returns/' ) ),
		esc_html__( 'Returns and replacements', 'store-features' )
	);
}

/**
 * Styles.
 *
 * @return string
 */
function store_features_product_page_style() {
	return <<<'CSS'
.sf-sections { clear: both; max-width: 860px; margin: 8px 0 0; border-top: 1px solid var(--line, #DCDEE6); }
.sf-section { border-bottom: 1px solid var(--line, #DCDEE6); }
.sf-section > summary { position: relative; display: flex; align-items: center; min-height: 56px; padding: 14px 44px 14px 0; color: var(--heading, #171C3A); font-size: 18px; font-weight: 700; list-style: none; cursor: pointer; }
.sf-section > summary::-webkit-details-marker { display: none; }
.sf-section > summary::after { content: ""; position: absolute; right: 6px; top: 50%; width: 10px; height: 10px; margin-top: -7px; border-right: 2px solid currentColor; border-bottom: 2px solid currentColor; transform: rotate(45deg); transition: transform .2s ease; }
.sf-section[open] > summary::after { margin-top: -2px; transform: rotate(-135deg); }
.sf-section > summary:focus-visible { outline: 2px solid var(--neel, #1E2650); outline-offset: 2px; }
.sf-section__body { padding: 0 0 22px; }
.sf-section__body.woocommerce-Tabs-panel { display: block !important; margin: 0 !important; padding: 0 0 22px !important; border: 0 !important; }
.sf-section__body > h2:first-child { display: none; }
.sf-section__body ul { margin: 0 0 12px 1.2em; }
.sf-section__body li { margin-bottom: 6px; }
.sf-product-hook { margin: 10px 0 18px; padding: 13px 15px; border-left: 3px solid var(--pw-accent, #A96546); background: var(--pw-surface, #F6F2EE); }
.sf-product-hook__headline { margin: 0 0 3px; color: var(--heading, #171C3A); font-size: 17px; font-weight: 800; line-height: 1.35; }
.sf-product-hook__note { margin: 0 0 10px; color: var(--ink, #2B3150); font-size: 14px; line-height: 1.5; }
.sf-product-promises { display: flex; flex-wrap: wrap; gap: 6px 14px; margin: 0 !important; padding: 0 !important; list-style: none; }
.sf-product-promises li { margin: 0 !important; color: var(--ink, #2B3150); font-size: 12px; line-height: 1.4; }
.sf-product-promises li::before { content: "✓"; margin-right: 5px; color: var(--pw-accent, #A96546); font-weight: 800; }
@media (max-width: 767px) { .sf-product-hook { padding: 11px 12px; } .sf-product-hook__headline { font-size: 16px; } .sf-product-promises { gap: 6px 10px; } }
.sf-fit { margin: 10px 0 0; color: var(--ink, #2B3150); font-size: 14.5px; }
.sf-fit strong { color: var(--heading, #171C3A); }
.sf-fit:empty { display: none; }
.sf-wa { position: fixed; right: 16px; bottom: calc(16px + env(safe-area-inset-bottom)); z-index: 89; display: flex; align-items: center; justify-content: center; width: 52px; height: 52px; border-radius: 50%; background: #1FAF54; color: #fff; box-shadow: 0 8px 22px rgba(23, 28, 58, .25); }
.sf-wa:hover { background: #179446; color: #fff; }
.sf-wa:focus-visible { outline: 3px solid var(--neel, #1E2650); outline-offset: 3px; }
.sf-sticky-on .sf-wa { bottom: calc(84px + env(safe-area-inset-bottom)); }
.sf-has-wa #kt-scroll-up { right: 80px !important; }
@media (max-width: 767px) {
	.sf-section > summary { font-size: 17px; }
	.sf-wa { width: 48px; height: 48px; right: 12px; }
}
CSS;
}

/**
 * Size-fit line and WhatsApp button.
 *
 * @return string
 */
function store_features_product_page_script() {
	return <<<'JS'
(function ($) {
	'use strict';

	/* "M fits chest 44 in, length 29 in" under the size options. */
	function fitText(fit, slug, label) {
		var text = fit && fit[slug];
		return text ? '<strong>' + label + '</strong> fits ' + text + '.' : '';
	}
	window.sfFitText = fitText;
	var fit = window.sfSizeFit;
	var form = document.querySelector('.single-product .summary form.variations_form');
	if (form && fit && Object.keys(fit).length) {
		var select = form.querySelector('select[name="attribute_pa_size"]');
		var row = select && select.closest('tr');
		if (row) {
			var line = document.createElement('p');
			line.className = 'sf-fit';
			line.setAttribute('aria-live', 'polite');
			(row.querySelector('td.value') || row).appendChild(line);
			var update = function () {
				var opt = select.options[select.selectedIndex];
				line.innerHTML = select.value ? fitText(fit, select.value, opt ? opt.text : select.value) : '';
			};
			$(form).on('change', 'select[name="attribute_pa_size"]', update);
			$(form).on('reset_data', update);
			update();
		}
	}

	/* Picking a colour shows that colour's photo straight away, before a size is chosen.
	   WooCommerce only swaps the photo once colour and size are both picked, and resets it
	   whenever the choice is incomplete, so this runs just after its own handlers. */
	var vform = document.querySelector('.single-product .summary form.variations_form');
	if (vform && !document.body.classList.contains('sf-custom-product') && $.fn.wc_variations_image_update) {
		var $vform = $(vform);
		var showColour = function () {
			var sel = vform.querySelector('select[name="attribute_pa_color"]');
			var all = $vform.data('product_variations');
			if (!sel || !sel.value || !Array.isArray(all)) {
				return;
			}
			var size = vform.querySelector('select[name="attribute_pa_size"]');
			if (size && size.value) {
				return; /* a full choice: WooCommerce shows that variation's photo itself */
			}
			for (var i = 0; i < all.length; i++) {
				var v = all[i];
				if (v.attributes && v.attributes.attribute_pa_color === sel.value && v.image && v.image.src) {
					$vform.wc_variations_image_update(v);
					return;
				}
			}
		};
		$vform.on('change', 'select[name="attribute_pa_color"]', function () { setTimeout(showColour, 30); });
		$vform.on('reset_data', function () { setTimeout(showColour, 30); });
		setTimeout(showColour, 300);
	}

	/* Qikink variable products: filter gallery after WooCommerce has built its thumbnails.
	   Qikink adoption stores one product photo per colour; variation events are the reliable
	   source when WooCommerce omits the full variations array for large products. */
	function initQikinkColourGallery() {
		var attempts = 0;
		function bindWhenReady() {
			var gallery = document.querySelector('.single-product .woocommerce-product-gallery');
			var form = document.querySelector('.single-product form.variations_form');
			var colour = form && form.querySelector('select[name="attribute_pa_color"]');
			var slides = gallery && gallery.querySelector('.woocommerce-product-gallery__wrapper');
			var thumbs = gallery && gallery.querySelector('.flex-control-thumbs');
			if (!gallery || !form || !colour || !slides || !thumbs || !thumbs.children.length) {
				if (++attempts < 24) { window.setTimeout(bindWhenReady, 250); }
				return;
			}
			if (form.dataset.sfColourGalleryBound === '1') { return; }
			form.dataset.sfColourGalleryBound = '1';
			if (document.body.classList.contains('sf-custom-product')) { return; }

			var variations = $(form).data('product_variations');
			var activeVariation = null;
			var normalise = function (url) {
				if (!url) { return ''; }
				try {
					var parsed = new URL(url, window.location.href);
					return parsed.origin + parsed.pathname.replace(/-[0-9]+x[0-9]+(?=\\.[^.]+$)/, '');
				} catch (e) { return String(url).split('?')[0]; }
			};
			function imageUrl(el) {
				var img = el && el.querySelector('img');
				return normalise(img && (img.dataset.large_image || img.dataset.src || img.currentSrc || img.src));
			}
			function variationForColour() {
				if (activeVariation && activeVariation.image && activeVariation.attributes &&
					activeVariation.attributes.attribute_pa_color === colour.value) {
					return activeVariation;
				}
				if (Array.isArray(variations)) {
					for (var i = 0; i < variations.length; i++) {
						var v = variations[i];
						if (v.attributes && v.attributes.attribute_pa_color === colour.value && v.image && v.image.src) {
							return v;
						}
					}
				}
				return null;
			}
			function applyFilter() {
				var variation = colour.value ? variationForColour() : null;
				var selectedUrl = variation && variation.image ? normalise(variation.image.src) : '';
				var slideItems = Array.prototype.slice.call(slides.children);
				var thumbItems = Array.prototype.slice.call(thumbs.children);
				if (!selectedUrl) {
					slideItems.forEach(function (el) { el.style.display = ''; });
					thumbItems.forEach(function (el) { el.style.display = ''; });
					return;
				}
				var matchingSlides = slideItems.filter(function (el) { return imageUrl(el) === selectedUrl; });
				var matchingThumbs = thumbItems.filter(function (el) { return imageUrl(el) === selectedUrl; });
				/* Never blank the gallery if the variation image cannot be matched safely. */
				if (!matchingSlides.length) { return; }
				slideItems.forEach(function (el) { el.style.display = matchingSlides.indexOf(el) !== -1 ? '' : 'none'; });
				thumbItems.forEach(function (el) { el.style.display = matchingThumbs.indexOf(el) !== -1 ? '' : 'none'; });
				if (window.jQuery && jQuery.fn.flexslider) {
					try {
						var sliderEl = gallery.querySelector('.flexslider');
						var slider = sliderEl && jQuery(sliderEl).data('flexslider');
						if (slider) { slider.flexAnimate(slideItems.indexOf(matchingSlides[0]), true); slider.resize(); }
					} catch (e) {}
				}
			}
			$(form).on('change', 'select[name="attribute_pa_color"]', function () {
				activeVariation = null;
				window.setTimeout(applyFilter, 120);
			});
			$(form).on('found_variation', function (event, variation) {
				activeVariation = variation || null;
				window.setTimeout(applyFilter, 120);
			});
			$(form).on('reset_data hide_variation', function () {
				activeVariation = null;
				window.setTimeout(applyFilter, 120);
			});
			window.setTimeout(applyFilter, 500);
		}
		bindWhenReady();
	}
	initQikinkColourGallery();

	/* Size chart links point at the size chart section: open it first. */
	document.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('a[href="#size-chart"]');
		var section = document.getElementById('tab-description');
		if (a && section && section.tagName === 'DETAILS') {
			section.open = true;
		}
	}, true);

	/* WhatsApp chat. */
	if (window.sfWhatsApp && window.sfWhatsApp.number) {
		var title = document.querySelector('.product_title');
		var msg = 'Hi! ' + (title ? 'I have a question about ' + title.textContent.trim() + ' (' + location.href.split('?')[0] + ')' : 'I have a question.');
		var a = document.createElement('a');
		a.className = 'sf-wa';
		a.href = 'https://wa.me/' + window.sfWhatsApp.number + '?text=' + encodeURIComponent(msg);
		a.target = '_blank';
		a.rel = 'noopener';
		a.setAttribute('aria-label', 'Chat with us on WhatsApp');
		a.innerHTML = '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2c-1.6 0-3.1-.4-4.4-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2s.2-1.1.2-1.2-.2-.2-.5-.3z"/></svg>';
		document.body.appendChild(a);
		document.body.classList.add('sf-has-wa');
	}
})(jQuery);
JS;
}

/**
 * Mugs and bags: no size chart lines or links.
 *
 * @param array $classes Body classes.
 * @return array
 */
function store_features_not_wearable_class( $classes ) {
	if ( function_exists( 'is_product' ) && is_product() && function_exists( 'store_features_is_wearable' ) && ! store_features_is_wearable( get_queried_object_id() ) ) {
		$classes[] = 'sf-not-wearable';
	}
	return $classes;
}
add_filter( 'body_class', 'store_features_not_wearable_class' );

add_action(
	'wp_enqueue_scripts',
	function () {
		wp_add_inline_style( 'store-features', '.sf-not-wearable li:has(a[href*="size-chart"]),.sf-not-wearable a[href="#size-chart"],.sf-not-wearable .sf-sizehelp{display:none!important}' );
	},
	40
);
