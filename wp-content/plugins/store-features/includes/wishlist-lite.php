<?php
/**
 * Light wishlist hearts.
 *
 * YITH Wishlist draws its heart buttons with a React app that pulls in React, ReactDOM, lodash,
 * moment and its own UI kit (about 200 KB of JavaScript) on every page. Here the same hearts are
 * drawn with a few lines of plain JavaScript that talk to YITH's own REST API, so the wishlist
 * itself (and its page) works exactly as before. The full YITH script still loads on the wishlist
 * page.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * Is this the wishlist page?
 *
 * @return bool
 */
function store_features_wishlist_is_page() {
	$id = (int) get_option( 'yith_wcwl_wishlist_page_id' );
	return $id && is_page( $id );
}

/**
 * Drop YITH's React button script (and with it React & co.) everywhere but the wishlist page.
 */
function store_features_wishlist_dequeue() {
	if ( is_admin() || ! defined( 'YITH_WCWL' ) || store_features_wishlist_is_page() ) {
		return;
	}
	wp_dequeue_script( 'yith-wcwl-add-to-wishlist' );
}
add_action( 'wp_enqueue_scripts', 'store_features_wishlist_dequeue', 999 );
add_action( 'wp_print_scripts', 'store_features_wishlist_dequeue', 0 );
add_action( 'wp_print_footer_scripts', 'store_features_wishlist_dequeue', 0 );

/**
 * The light script.
 */
add_action(
	'wp_footer',
	function () {
		if ( is_admin() || ! defined( 'YITH_WCWL' ) || store_features_wishlist_is_page() ) {
			return;
		}
		$id   = (int) get_option( 'yith_wcwl_wishlist_page_id' );
		$conf = array(
			'rest'  => esc_url_raw( rest_url( 'yith/wishlist/v1/' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'page'  => $id ? get_permalink( $id ) : home_url( '/wishlist/' ),
			'login' => is_user_logged_in(),
		);
		?>
<script id="sf-wishlist-lite">
(function () {
	var C = <?php echo wp_json_encode( $conf ); ?>;
	var OUT = '<path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z"/>';
	function icon(on) {
		return '<svg class="yith-wcwl-icon yith-wcwl-icon-svg yith-wcwl-add-to-wishlist-button-icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" fill="' + (on ? 'currentColor' : 'none') + '" stroke="currentColor" stroke-width="1.5">' + OUT + '</svg>';
	}
	function api(method, path, body) {
		var opt = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce } };
		if (body) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
		return fetch(C.rest + path, opt).then(function (r) { return r.json(); });
	}
	function toast(text, link) {
		var t = document.createElement('div');
		t.className = 'sf-wl-toast'; t.setAttribute('role', 'status');
		t.innerHTML = '<span></span>' + (link ? ' <a href="' + C.page + '">Wishlist dekho</a>' : '');
		t.firstChild.textContent = text;
		document.body.appendChild(t);
		setTimeout(function () { t.classList.add('is-on'); }, 10);
		setTimeout(function () { t.classList.remove('is-on'); setTimeout(function () { t.remove(); }, 300); }, 3200);
	}
	function paint(btn, on) {
		btn.classList.toggle('yith-wcwl-add-to-wishlist-button--added', on);
		btn.setAttribute('aria-pressed', on ? 'true' : 'false');
		btn.setAttribute('aria-label', on ? 'Remove from wishlist' : 'Add to wishlist');
		var label = btn.querySelector('.sf-wl-label');
		btn.querySelector('svg').outerHTML = icon(on);
		if (label) { label.textContent = on ? 'Wishlist mein hai' : 'Add to wishlist'; }
	}
	var buttons = {};
	document.querySelectorAll('.yith-add-to-wishlist-button-block[data-product-id]').forEach(function (block) {
		var id = block.getAttribute('data-product-id');
		var card = block.closest('li.product, .wc-block-grid__product, .wc-block-product');
		var btn = document.createElement('button');
		btn.type = 'button';
		if (card) {
			btn.className = 'yith-wcwl-add-to-wishlist-button yith-wcwl-add-to-wishlist-button--icon-button yith-wcwl-add-to-wishlist-button-over-image yith-wcwl-add-to-wishlist-button-over-image--top-left';
			btn.innerHTML = icon(false);
			var link = card.querySelector('a.woocommerce-loop-product__link, a.woocommerce-loop-image-link') || block;
			link.appendChild(btn);
		} else {
			btn.className = 'yith-wcwl-add-to-wishlist-button yith-wcwl-add-to-wishlist-button--anchor sf-wl-inline';
			btn.innerHTML = icon(false) + '<span class="sf-wl-label">Add to wishlist</span>';
			block.appendChild(btn);
		}
		paint(btn, false);
		(buttons[id] = buttons[id] || []).push(btn);
		btn.addEventListener('click', function (e) {
			e.preventDefault(); e.stopPropagation();
			if (btn.disabled) { return; }
			var on = btn.getAttribute('aria-pressed') === 'true';
			btn.disabled = true;
			api(on ? 'DELETE' : 'POST', 'items', { product_id: parseInt(id, 10) }).then(function (res) {
				if (res && res.success === false) { throw new Error(res.message || 'error'); }
				(buttons[id] || []).forEach(function (b) { paint(b, !on); });
				toast(on ? 'Wishlist se hata diya' : 'Wishlist mein add ho gaya', !on);
			}).catch(function () {
				toast('Abhi nahi ho paya, dobara try karein');
			}).then(function () { btn.disabled = false; });
		});
	});
	// Show which ones are already saved (only when this visitor has a wishlist).
	var ids = Object.keys(buttons);
	if (ids.length && (C.login || /yith_wcwl_session/.test(document.cookie))) {
		api('GET', 'products?' + ids.map(function (i) { return 'product_ids[]=' + i; }).join('&')).then(function (res) {
			var list = res && (res.products || res);
			Object.keys(list || {}).forEach(function (k) {
				var d = list[k], pid = d && (d.id || d.product_id) ? String(d.id || d.product_id) : k;
				if (d && d.isAdded && buttons[pid]) { buttons[pid].forEach(function (b) { paint(b, true); }); }
			});
		}).catch(function () {});
	}
})();
</script>
<style>
.yith-wcwl-add-to-wishlist-button--added { color: #E0335F; }
.sf-wl-inline { display: inline-flex; align-items: center; gap: 8px; padding: 6px 0; border: 0; background: none; color: inherit; font: inherit; font-weight: 700; cursor: pointer; }
.sf-wl-inline svg { width: 22px; height: 22px; }
.sf-wl-toast { position: fixed; left: 50%; bottom: 24px; z-index: 99999; transform: translate(-50%, 20px); padding: 12px 18px; border-radius: 12px; background: #17171A; color: #fff; font-size: 14px; font-weight: 600; opacity: 0; transition: opacity .25s, transform .25s; box-shadow: 0 10px 30px rgba(0,0,0,.25); }
.sf-wl-toast.is-on { opacity: 1; transform: translate(-50%, 0); }
.sf-wl-toast a { margin-left: 8px; color: #FFB23F; text-decoration: underline; }
</style>
		<?php
	},
	50
);
