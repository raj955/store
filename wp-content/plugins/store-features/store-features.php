<?php
/**
 * Plugin Name:       Store Features
 * Description:       Custom store features: size chart pop-up, delivery estimate, add to cart without a page reload, mobile add-to-cart bar, store contact details for the policy pages, order hand-off to Qikink, "Design your own" customizer. Deployed from GitHub.
 * Version:           0.5.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Text Domain:       store-features
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

define( 'STORE_FEATURES_VERSION', '0.5.0' );
define( 'STORE_FEATURES_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Read the deploy info written by the GitHub Actions pipeline.
 *
 * @return array{sha?: string, time?: string}
 */
function store_features_build_info() {
	$file = STORE_FEATURES_DIR . 'build-info.php';

	if ( ! is_readable( $file ) ) {
		return array();
	}

	$info = include $file;

	return is_array( $info ) ? $info : array();
}

/**
 * Show which commit is live in the admin footer, so you can confirm a deploy worked.
 *
 * @param string $text Default footer text.
 * @return string
 */
function store_features_admin_footer( $text ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return $text;
	}

	$info = store_features_build_info();

	if ( empty( $info['sha'] ) ) {
		return $text;
	}

	return sprintf(
		'Store Features %1$s &middot; deploy <code>%2$s</code> &middot; %3$s',
		esc_html( STORE_FEATURES_VERSION ),
		esc_html( $info['sha'] ),
		esc_html( isset( $info['time'] ) ? $info['time'] : '' )
	);
}
add_filter( 'admin_footer_text', 'store_features_admin_footer' );

/**
 * Product tabs: drop the repeated colour/size table and call the description "Details".
 *
 * @param array $tabs WooCommerce product tabs.
 * @return array
 */
function store_features_product_tabs( $tabs ) {
	unset( $tabs['additional_information'] );

	if ( isset( $tabs['description'] ) ) {
		$tabs['description']['title'] = __( 'Details', 'store-features' );
	}

	return $tabs;
}
add_filter( 'woocommerce_product_tabs', 'store_features_product_tabs', 98 );

/**
 * Small wording fixes in strings that come from the theme and the swatches plugin.
 *
 * @param string $translation Translated text.
 * @param string $text        Original text.
 * @param string $domain      Text domain.
 * @return string
 */
function store_features_wording( $translation, $text, $domain ) {
	if ( 'kadence' === $domain && 'Review Cart' === $text ) {
		return __( 'Your cart', 'store-features' );
	}
	if ( 'variation-swatches-woo' === $domain && 'Add to Cart' === $text ) {
		return __( 'Add to cart', 'store-features' );
	}
	return $translation;
}
add_filter( 'gettext', 'store_features_wording', 10, 3 );

/**
 * A short reassurance line in the cart drawer, above the View cart and Checkout buttons.
 */
function store_features_mini_cart_note() {
	if ( function_exists( 'store_features_custom_cart_has_design' ) && store_features_custom_cart_has_design() ) {
		echo '<p class="sf-cart-note">' . esc_html__( 'Free shipping across India. Pieces with your own design are paid online.', 'store-features' ) . '</p>';
		return;
	}
	echo '<p class="sf-cart-note">' . esc_html__( 'Free shipping and cash on delivery across India.', 'store-features' ) . '</p>';
}
add_action( 'woocommerce_widget_shopping_cart_before_buttons', 'store_features_mini_cart_note' );

/**
 * Store contact details, kept in one place.
 *
 * They are edited in WooCommerce → Settings → General → "Store contact" and shown on the
 * Contact, Terms and Privacy pages through shortcodes, so a new brand name, phone number or
 * address only ever has to be changed once. The store name is the Site Title and the address
 * is WooCommerce's own Store Address.
 *
 * @return array<string, array{label: string, type: string, default: string}>
 */
function store_features_contact_fields() {
	return array(
		'store_features_owner' => array(
			'label'   => __( 'Owner and grievance officer', 'store-features' ),
			'type'    => 'text',
			'default' => '',
		),
		'store_features_email' => array(
			'label'   => __( 'Support email', 'store-features' ),
			'type'    => 'email',
			'default' => '',
		),
		'store_features_phone' => array(
			'label'   => __( 'Support phone and WhatsApp', 'store-features' ),
			'type'    => 'text',
			'default' => '',
		),
		'store_features_hours' => array(
			'label'   => __( 'Support hours', 'store-features' ),
			'type'    => 'text',
			'default' => 'Monday to Saturday, 10 am to 6 pm',
		),
	);
}

/**
 * Register the contact options so they can also be read and set through the REST API.
 */
function store_features_register_contact_settings() {
	foreach ( store_features_contact_fields() as $option => $field ) {
		register_setting(
			'store_features',
			$option,
			array(
				'type'              => 'string',
				'description'       => $field['label'],
				'default'           => $field['default'],
				'sanitize_callback' => 'email' === $field['type'] ? 'sanitize_email' : 'sanitize_text_field',
				'show_in_rest'      => true,
			)
		);
	}
}
add_action( 'init', 'store_features_register_contact_settings' );

/**
 * Add the "Store contact" section under WooCommerce → Settings → General.
 *
 * @param array $settings General settings.
 * @return array
 */
function store_features_contact_settings( $settings ) {
	$section = array(
		array(
			'title' => __( 'Store contact', 'store-features' ),
			'desc'  => __( 'Shown on the Contact, Terms and Privacy pages. The store name comes from Settings → General → Site Title, and the address from Store Address above.', 'store-features' ),
			'type'  => 'title',
			'id'    => 'store_features_contact',
		),
	);

	foreach ( store_features_contact_fields() as $option => $field ) {
		$section[] = array(
			'title'   => $field['label'],
			'id'      => $option,
			'type'    => $field['type'],
			'default' => $field['default'],
		);
	}

	$section[] = array(
		'type' => 'sectionend',
		'id'   => 'store_features_contact',
	);

	return array_merge( $settings, $section );
}
add_filter( 'woocommerce_general_settings', 'store_features_contact_settings' );

/**
 * The store's address from WooCommerce → Settings → General → Store Address.
 *
 * @param bool $city_only Return only "City, State".
 * @return string
 */
function store_features_store_address( $city_only = false ) {
	$country_state = (string) get_option( 'woocommerce_default_country', '' );
	$parts         = explode( ':', $country_state );
	$country       = $parts[0];
	$state         = isset( $parts[1] ) ? $parts[1] : '';
	$state_name    = $state;
	$country_name  = $country;

	if ( function_exists( 'WC' ) && WC()->countries ) {
		$states = WC()->countries->get_states( $country );
		if ( $state && is_array( $states ) && isset( $states[ $state ] ) ) {
			$state_name = $states[ $state ];
		}
		$countries = WC()->countries->get_countries();
		if ( isset( $countries[ $country ] ) ) {
			$country_name = $countries[ $country ];
		}
	}

	$city = trim( (string) get_option( 'woocommerce_store_city', '' ) );

	if ( $city_only ) {
		return implode( ', ', array_filter( array( $city, $state_name ) ) );
	}

	$lines = array(
		trim( (string) get_option( 'woocommerce_store_address', '' ) ),
		trim( (string) get_option( 'woocommerce_store_address_2', '' ) ),
		$city,
		trim( $state_name . ' ' . trim( (string) get_option( 'woocommerce_store_postcode', '' ) ) ),
		$country_name,
	);

	return implode( ', ', array_filter( $lines ) );
}

/**
 * Shortcodes for the policy pages: [store_name], [store_owner], [store_email], [store_phone],
 * [store_hours], [store_city] and [store_address].
 *
 * @param array  $atts    Unused.
 * @param string $content Unused.
 * @param string $tag     Shortcode name.
 * @return string
 */
function store_features_contact_shortcode( $atts, $content, $tag ) {
	switch ( $tag ) {
		case 'store_name':
			return esc_html( get_bloginfo( 'name' ) );
		case 'store_owner':
			return esc_html( get_option( 'store_features_owner', '' ) );
		case 'store_email':
			$email = sanitize_email( get_option( 'store_features_email', '' ) );
			if ( ! $email ) {
				return '';
			}
			$shown = antispambot( $email );
			return '<a href="mailto:' . $shown . '">' . $shown . '</a>';
		case 'store_phone':
			$phone = trim( (string) get_option( 'store_features_phone', '' ) );
			if ( '' === $phone ) {
				return '';
			}
			$digits = preg_replace( '/[^0-9+]/', '', $phone );
			return '<a href="tel:' . esc_attr( $digits ) . '">' . esc_html( $phone ) . '</a>';
		case 'store_hours':
			return esc_html( get_option( 'store_features_hours', 'Monday to Saturday, 10 am to 6 pm' ) );
		case 'store_city':
			return esc_html( store_features_store_address( true ) );
		case 'store_address':
			return esc_html( store_features_store_address() );
	}
	return '';
}
foreach ( array( 'store_name', 'store_owner', 'store_email', 'store_phone', 'store_hours', 'store_city', 'store_address' ) as $store_features_tag ) {
	add_shortcode( $store_features_tag, 'store_features_contact_shortcode' );
}
unset( $store_features_tag );

require_once STORE_FEATURES_DIR . 'includes/qikink.php';
require_once STORE_FEATURES_DIR . 'includes/custom-design.php';

/**
 * Declare support for WooCommerce's order tables (orders are read and written through the CRUD API).
 */
function store_features_declare_wc_compatibility() {
	if ( class_exists( 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'store_features_declare_wc_compatibility' );

/**
 * Load the small front-end layer (inline, so there is nothing extra to deploy or cache).
 */
function store_features_assets() {
	if ( is_admin() || ! class_exists( 'WC_AJAX' ) ) {
		return;
	}

	wp_register_script( 'store-features', false, array( 'jquery' ), STORE_FEATURES_VERSION, true );
	wp_enqueue_script( 'store-features' );
	wp_localize_script(
		'store-features',
		'storeFeatures',
		array(
			'addToCartUrl' => WC_AJAX::get_endpoint( 'add_to_cart' ),
			'sizeGuideUrl' => home_url( '/size-guide/' ),
		)
	);
	wp_add_inline_script( 'store-features', store_features_script() );

	wp_register_style( 'store-features', false, array(), STORE_FEATURES_VERSION );
	wp_enqueue_style( 'store-features' );
	wp_add_inline_style( 'store-features', store_features_style() );
}
add_action( 'wp_enqueue_scripts', 'store_features_assets', 30 );

/**
 * Front-end behaviour.
 *
 * @return string
 */
function store_features_script() {
	return <<<'JS'
(function ($) {
	'use strict';
	var cfg = window.storeFeatures || {};

	/* Open the cart drawer whenever something is added to the cart. */
	function openCartDrawer() {
		var toggles = document.querySelectorAll('[data-toggle-target="#cart-drawer"]');
		for (var i = 0; i < toggles.length; i++) {
			if (toggles[i].offsetParent !== null) {
				toggles[i].click();
				return;
			}
		}
	}
	$(document.body).on('added_to_cart', function () {
		setTimeout(openCartDrawer, 150);
	});

	/* Add to cart from the product page without a page reload. Falls back to a normal submit. */
	$(document).on('submit', '.single-product .summary form.cart', function (e) {
		var form = this;
		if (document.body.classList.contains('sf-custom-product')) {
			return; /* The customizer adds these itself, with the design. */
		}
		var btn = form.querySelector('.single_add_to_cart_button');
		if (!cfg.addToCartUrl || !btn || btn.classList.contains('disabled')) {
			return;
		}
		var variation = form.querySelector('input[name="variation_id"]');
		var addField = form.querySelector('[name="add-to-cart"]');
		var id = variation ? variation.value : (addField ? addField.value : '');
		if (!id || id === '0') {
			return;
		}
		e.preventDefault();
		var qtyField = form.querySelector('input.qty');
		btn.classList.add('loading');
		btn.setAttribute('aria-busy', 'true');
		$.post(cfg.addToCartUrl, { product_id: id, quantity: qtyField ? qtyField.value : 1 })
			.done(function (res) {
				if (!res || res.error) {
					form.submit();
					return;
				}
				$(document.body).trigger('added_to_cart', [res.fragments, res.cart_hash, $(btn)]);
			})
			.fail(function () {
				form.submit();
			})
			.always(function () {
				btn.classList.remove('loading');
				btn.removeAttribute('aria-busy');
			});
	});

	/* Size chart in a dialog, opened from next to the Size label. */
	function initSizeChart() {
		var table = document.querySelector('.single-product .woocommerce-Tabs-panel--description table.size-chart');
		if (!table || typeof HTMLDialogElement === 'undefined') {
			return;
		}
		var note = document.querySelector('.single-product .woocommerce-Tabs-panel--description .size-chart-note');
		var dialog = document.createElement('dialog');
		dialog.className = 'sf-dialog';
		dialog.setAttribute('aria-labelledby', 'sf-size-title');
		dialog.innerHTML =
			'<div class="sf-dialog__head"><h2 id="sf-size-title">Size chart</h2>' +
			'<button type="button" class="sf-dialog__close" aria-label="Close size chart">' +
			'<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button></div>' +
			'<p class="sf-dialog__unit">Garment measurements, in inches</p>' +
			'<div class="size-chart-wrap">' + table.outerHTML + '</div>' +
			(note ? '<p class="sf-dialog__note">' + note.textContent + '</p>' : '') +
			'<p class="sf-dialog__more"><a href="' + cfg.sizeGuideUrl + '">How to measure, and charts for every style</a></p>';
		document.body.appendChild(dialog);
		dialog.querySelector('.sf-dialog__close').addEventListener('click', function () {
			dialog.close();
		});
		dialog.addEventListener('click', function (e) {
			if (e.target === dialog) {
				dialog.close();
			}
		});
		function open(e) {
			if (e) {
				e.preventDefault();
			}
			dialog.showModal();
		}
		var sizeLabel = document.querySelector('.single-product form.variations_form label[for="pa_size"]');
		if (sizeLabel) {
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'sf-size-link';
			btn.textContent = 'Size chart';
			btn.setAttribute('aria-haspopup', 'dialog');
			btn.addEventListener('click', open);
			sizeLabel.parentNode.appendChild(btn);
		}
		document.querySelectorAll('.single-product a[href="#size-chart"]').forEach(function (a) {
			a.addEventListener('click', open);
		});
	}

	/* Delivery estimate: 5 to 7 working days (Monday to Saturday) from today. */
	function addWorkingDays(start, days) {
		var d = new Date(start.getTime());
		while (days > 0) {
			d.setDate(d.getDate() + 1);
			if (d.getDay() !== 0) {
				days--;
			}
		}
		return d;
	}
	function initDelivery() {
		var anchor = document.querySelector('.single-product .summary form.variations_form table.variations') ||
			document.querySelector('.single-product .summary form.cart');
		if (!anchor || !window.Intl) {
			return;
		}
		var now = new Date();
		var fmt = new Intl.DateTimeFormat('en-IN', { weekday: 'short', day: 'numeric', month: 'short' });
		var p = document.createElement('p');
		p.className = 'sf-delivery';
		p.innerHTML = '<span>Estimated delivery</span> <strong>' + fmt.format(addWorkingDays(now, 5)) +
			' to ' + fmt.format(addWorkingDays(now, 7)) + '</strong>';
		if (anchor.tagName === 'TABLE') {
			anchor.parentNode.insertBefore(p, anchor.nextSibling);
		} else {
			anchor.parentNode.insertBefore(p, anchor);
		}
	}

	/* Mobile add-to-cart bar, shown once the real button scrolls out of view. */
	function initStickyBar() {
		var form = document.querySelector('.single-product .summary form.cart');
		var btn = form && form.querySelector('.single_add_to_cart_button');
		if (!btn || !('IntersectionObserver' in window)) {
			return;
		}
		var title = document.querySelector('.single-product .product_title');
		var price = document.querySelector('.single-product .summary > .price');
		var bar = document.createElement('div');
		bar.className = 'sf-sticky';
		bar.inert = true;
		bar.innerHTML = '<div class="sf-sticky__info"><span class="sf-sticky__name"></span><span class="sf-sticky__price price"></span></div>' +
			'<button type="button" class="button sf-sticky__btn">Add to cart</button>';
		bar.querySelector('.sf-sticky__name').textContent = title ? title.textContent : '';
		if (price) {
			bar.querySelector('.sf-sticky__price').innerHTML = price.innerHTML;
		}
		document.body.appendChild(bar);
		bar.querySelector('.sf-sticky__btn').addEventListener('click', function () {
			if (btn.classList.contains('disabled')) {
				form.scrollIntoView({ behavior: 'smooth', block: 'center' });
				return;
			}
			btn.click();
		});
		new IntersectionObserver(function (entries) {
			var show = !entries[0].isIntersecting && entries[0].boundingClientRect.top < 0;
			bar.classList.toggle('is-visible', show);
			document.body.classList.toggle('sf-sticky-on', show);
			bar.inert = !show;
		}).observe(btn);
	}

	$(function () {
		if (!document.body.classList.contains('single-product')) {
			return;
		}
		initSizeChart();
		initDelivery();
		initStickyBar();
	});
})(jQuery);
JS;
}

/**
 * Styles for the pieces added above. Colours come from the theme's custom properties.
 *
 * @return string
 */
function store_features_style() {
	return <<<'CSS'
.sf-size-link { margin-left: 14px; padding: 0; border: 0; background: none; color: var(--neel, #1E2650); font: inherit; font-size: 14px; font-weight: 600; text-decoration: underline; text-underline-offset: 3px; cursor: pointer; }
.sf-dialog { width: min(560px, calc(100vw - 32px)); max-height: calc(100vh - 48px); padding: 22px 24px 18px; border: 0; border-radius: 6px; color: var(--ink, #2B3150); box-shadow: 0 24px 64px rgba(23, 28, 58, .28); }
.sf-dialog::backdrop { background: rgba(23, 28, 58, .55); }
.sf-dialog__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.sf-dialog__head h2 { margin: 0; font-size: 24px; }
.sf-dialog__close { display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; margin-right: -8px; padding: 0; border: 0; background: transparent; color: var(--heading, #171C3A); cursor: pointer; }
.sf-dialog__unit, .sf-dialog__note { margin: 4px 0 14px; color: var(--muted, #565D7A); font-size: 14px; }
.sf-dialog__more { margin: 6px 0 0; }
.sf-dialog__more a { color: var(--neel, #1E2650); font-weight: 600; text-decoration: underline; text-underline-offset: 3px; }
.sf-custom-notice { margin: 0 0 20px; padding: 14px 16px; border-left: 3px solid var(--neel, #1E2650); background: var(--wash, #F6F7FA); color: var(--ink, #2B3150); font-size: 15px; line-height: 1.5; }
.sf-cart-note { margin: 0 0 12px; color: var(--muted, #565D7A); font-size: 14px; }
.sf-delivery { margin: -2px 0 16px; font-size: 15px; color: var(--ink, #2B3150); }
.sf-delivery span { margin-right: 4px; color: var(--muted, #565D7A); }
.sf-sticky { position: fixed; left: 0; right: 0; bottom: 0; z-index: 90; display: none; align-items: center; gap: 12px; padding: 10px 16px calc(10px + env(safe-area-inset-bottom)); background: #fff; border-top: 1px solid var(--line, #DCDEE6); box-shadow: 0 -6px 20px rgba(23, 28, 58, .08); transform: translateY(110%); transition: transform .25s ease; }
.sf-sticky.is-visible { transform: none; }
.sf-sticky__info { display: flex; flex: 1; flex-direction: column; min-width: 0; }
.sf-sticky__name { overflow: hidden; color: var(--heading, #171C3A); font-size: 14.5px; font-weight: 600; white-space: nowrap; text-overflow: ellipsis; }
.sf-sticky__price { font-size: 14px; }
.sf-sticky__btn.button { flex: 0 0 auto; min-height: 46px; }
@media (max-width: 767px) {
	.sf-sticky { display: flex; }
	.sf-sticky-on #kt-scroll-up { bottom: 84px; }
}
@media (prefers-reduced-motion: reduce) {
	.sf-sticky { transition: none; }
}
CSS;
}
