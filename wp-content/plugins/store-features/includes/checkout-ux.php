<?php
/**
 * Checkout loading feedback for classic and block-based WooCommerce checkout.
 *
 * This is presentation-only: it never changes payment, validation, or order submission.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * Load checkout feedback only where a customer is placing an order.
 */
/**
 * Show a small, accessible loading state on AJAX add-to-cart buttons.
 * It is cleared by WooCommerce's normal success/error events; no cart logic changes.
 */
function store_features_card_loader_assets() {
	if ( is_admin() ) {
		return;
	}
	wp_add_inline_style(
		'store-features',
		'.sf-card-btn.sf-is-loading { position: relative; pointer-events: none; opacity: .78; }' .
		'.sf-card-btn.sf-is-loading::after { content: ""; display: inline-block; width: 13px; height: 13px; margin-inline-start: 8px; vertical-align: -2px; border: 2px solid currentColor; border-inline-end-color: transparent; border-radius: 50%; animation: sf-card-spin .7s linear infinite; }' .
		'@keyframes sf-card-spin { to { transform: rotate(360deg); } }' .
		'@media (prefers-reduced-motion: reduce) { .sf-card-btn.sf-is-loading::after { animation-duration: 1.8s; } }'
	);
	wp_add_inline_script(
		'jquery',
		<<<'JS'
(function () {
	'use strict';
	function clear() {
		document.querySelectorAll('.sf-card-btn.sf-is-loading').forEach(function (button) {
			button.classList.remove('sf-is-loading');
			button.removeAttribute('aria-busy');
			button.disabled = false;
		});
	}
	document.addEventListener('click', function (event) {
		var button = event.target.closest && event.target.closest('.sf-card-btn');
		if (!button || button.classList.contains('sf-card-btn--design')) { return; }
		button.classList.add('sf-is-loading');
		button.setAttribute('aria-busy', 'true');
	}, true);
	if (window.jQuery) {
		window.jQuery(document.body).on('added_to_cart wc_cart_button_updated ajaxComplete', clear);
	}
	window.addEventListener('pageshow', clear);
})();
JS
	);
}
add_action( 'wp_enqueue_scripts', 'store_features_card_loader_assets', 35 );

function store_features_checkout_ux_assets() {
	if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
		return;
	}

	wp_add_inline_style(
		'store-features',
		'
		#place_order.sf-is-loading,
		.wc-block-components-checkout-place-order-button.sf-is-loading { opacity: .85; cursor: progress; }
		#place_order.sf-is-loading::after,
		.wc-block-components-checkout-place-order-button.sf-is-loading::after {
			content: "";
			display: inline-block;
			width: 15px;
			height: 15px;
			margin-inline-start: 10px;
			vertical-align: -2px;
			border: 2px solid currentColor;
			border-inline-end-color: transparent;
			border-radius: 50%;
			animation: sf-checkout-spin .7s linear infinite;
		}
		@keyframes sf-checkout-spin { to { transform: rotate(360deg); } }
		@media (prefers-reduced-motion: reduce) {
			#place_order.sf-is-loading::after,
			.wc-block-components-checkout-place-order-button.sf-is-loading::after { animation-duration: 1.8s; }
		}'
	);

	wp_add_inline_script(
		'store-features',
		<<<'JS'
(function () {
	'use strict';
	var selectors = '#place_order, .wc-block-components-checkout-place-order-button';
	function start(button) {
		if (!button || button.dataset.sfLoading === '1') { return; }
		button.dataset.sfLoading = '1';
		button.classList.add('sf-is-loading');
		button.setAttribute('aria-busy', 'true');
	}
	function stop(button) {
		if (!button) { return; }
		delete button.dataset.sfLoading;
		button.classList.remove('sf-is-loading');
		button.removeAttribute('aria-busy');
	}
	document.addEventListener('click', function (event) {
		var button = event.target.closest && event.target.closest(selectors);
		if (button) { start(button); }
	}, true);
	document.addEventListener('submit', function (event) {
		if (!event.target.matches('form.checkout, form.wc-block-checkout__form')) { return; }
		var button = event.target.querySelector(selectors);
		if (button) { start(button); }
	}, true);
	if (window.jQuery) {
		window.jQuery(document.body).on('checkout_error wc-blocks_checkout_error', function () {
			document.querySelectorAll(selectors).forEach(stop);
		});
		window.jQuery(document.body).on('updated_checkout', function () {
			document.querySelectorAll(selectors).forEach(stop);
		});
	}
	/* If the browser returns to the checkout via Back, never leave a stale spinner. */
	window.addEventListener('pageshow', function () {
		document.querySelectorAll(selectors).forEach(stop);
	});
})();
JS
	);
}
add_action( 'wp_enqueue_scripts', 'store_features_checkout_ux_assets', 40 );
