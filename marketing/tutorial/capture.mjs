// Films the real Panuwear site on a phone-sized browser for the "how to order" tutorial:
// screenshots of each step plus where the finger taps. Never places an order; checkout fields
// are filled for the picture only (no events, so nothing is saved or sent).
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const BASE = 'https://panuwear.com';
const OUT = path.resolve(process.argv[2] || 'shots');
fs.mkdirSync(OUT, { recursive: true });
const meta = {};
const log = (s) => console.log(s);

const browser = await chromium.launch();
const ctx = await browser.newContext({
	viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true,
	userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
	locale: 'en-IN',
});
const page = await ctx.newPage();
const tidy = async () => {
	await page.addStyleTag({ content: '.sf-wl-toast,#wpadminbar{display:none!important} *{scroll-behavior:auto!important;caret-color:transparent!important}' }).catch(() => {});
};
const settle = (ms = 1500) => page.waitForTimeout(ms);
async function go(url) {
	await page.goto(BASE + url, { waitUntil: 'load', timeout: 60000 });
	await tidy();
	await settle(2500);
	// Load lazy images.
	await page.evaluate(async () => { for (let y = 0; y < Math.min(document.body.scrollHeight, 5000); y += 400) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 80)); } window.scrollTo(0, 0); });
	await settle(800);
}
async function rectOf(sel) {
	const el = typeof sel === 'string' ? page.locator(sel).first() : sel;
	await el.scrollIntoViewIfNeeded({ timeout: 5000 }).catch(() => {});
	const b = await el.boundingBox().catch(() => null);
	return b ? { x: b.x, y: b.y, w: b.width, h: b.height } : null;
}
async function shot(name, extra = {}) {
	await settle(400);
	await page.screenshot({ path: path.join(OUT, name + '.jpg'), type: 'jpeg', quality: 92 });
	meta[name] = { ...(meta[name] || {}), ...extra };
	log('shot ' + name + ' ' + JSON.stringify(extra));
}
async function tall(name, maxCss = 2600) {
	const h = await page.evaluate(() => document.documentElement.scrollHeight);
	await page.evaluate(() => window.scrollTo(0, 0));
	await page.screenshot({ path: path.join(OUT, name + '.jpg'), type: 'jpeg', quality: 90, fullPage: true, clip: { x: 0, y: 0, width: 390, height: Math.min(h, maxCss) } });
	meta[name] = { tall: true, cssHeight: Math.min(h, maxCss) };
	log('tall ' + name);
}
async function step(label, fn) { try { await fn(); } catch (e) { log(`STEP FAILED ${label}: ${e.message.split('\n')[0]}`); } }
// Show a value in a field for the picture only.
async function paint(sel, value) {
	await page.evaluate(([s, v]) => { const el = document.querySelector(s); if (el) { el.value = v; el.closest('.wc-block-components-text-input, .wc-blocks-components-select')?.classList.add('is-active'); } }, [sel, value]);
}

// 1. Home
await step('home', async () => {
	await go('/');
	await shot('home');
	await tall('home_tall', 3000);
});

// 2. Category list (the menu's "Oversized tees")
let productHref = '/product/chal-nikalte-hain-oversized-tee/';
await step('category', async () => {
	const href = await page.evaluate(() => { const a = [...document.querySelectorAll('a')].find((x) => /oversized tees/i.test(x.textContent) && /product-category|shop/.test(x.href)); return a ? a.getAttribute('href') : ''; });
	await go(href ? new URL(href, 'https://panuwear.com').pathname : '/shop/');
	await tall('category_tall', 2400);
	const r = await page.evaluate(() => { const a = document.querySelector('a[href*="chal-nikalte-hain"] img') || document.querySelector('li.product a img'); if (!a) return null; const b = a.getBoundingClientRect(); return { x: b.x, y: b.y + window.scrollY, w: b.width, h: b.height, href: a.closest('a').getAttribute('href') }; });
	if (r) { meta.category_tall.tap = r; productHref = new URL(r.href, 'https://panuwear.com').pathname; }
});

// 3. Product page: colour, size, add to cart
await step('product', async () => {
	await go(productHref);
	await shot('product');
	const colours = page.locator('.cfvsw-swatches-container[swatches-attr*="olor" i] .cfvsw-swatches-option');
	const n = await colours.count();
	const pick = n > 1 ? colours.nth(1) : colours.first();
	const tapC = await rectOf(pick);
	await shot('product_colour_before', { tap: tapC });
	await pick.click();
	await settle(1600);
	await shot('product_colour_after');
	const size = page.locator('.cfvsw-swatches-container[swatches-attr*="size" i] .cfvsw-swatches-option').nth(2);
	const tapS = await rectOf(size);
	await shot('product_size_before', { tap: tapS });
	await size.click();
	await settle(800);
	const btn = page.locator('form.cart button.single_add_to_cart_button');
	const tapB = await rectOf(btn);
	await shot('product_add', { tap: tapB });
	await btn.click();
	await settle(3500);
	await shot('product_added');
});

// 4. Cart
await step('cart', async () => {
	await go('/cart/');
	await settle(3000);
	await shot('cart');
	const tap = await rectOf('.wc-block-cart__submit-button, a.checkout-button');
	await shot('cart_checkout', { tap });
});

// 5. Checkout (fields painted for the picture only, nothing submitted)
await step('checkout', async () => {
	await go('/checkout/');
	await page.waitForSelector('.wc-block-checkout__form', { timeout: 30000 });
	await settle(2500);
	await paint('#email', 'aman.demo@gmail.com');
	await paint('#shipping-first_name, #billing-first_name', 'Aman');
	await paint('#shipping-last_name, #billing-last_name', 'Sharma');
	await paint('#shipping-address_1, #billing-address_1', 'House 12, Gandhi Nagar');
	await paint('#shipping-city, #billing-city', 'Dehradun');
	await paint('#shipping-postcode, #billing-postcode', '248001');
	await paint('#shipping-phone, #billing-phone', '98765 43210');
	const tapF = await rectOf('#email');
	await shot('checkout_contact', { tap: tapF });
	const addr = await rectOf('#shipping-address_1, #billing-address_1');
	await shot('checkout_address', { tap: addr });
	const pay = await rectOf('.wc-block-checkout__payment-method');
	await shot('checkout_payment', { tap: pay });
	const cod = page.locator('label[for="radio-control-wc-payment-method-options-cod"], #radio-control-wc-payment-method-options-cod').first();
	const tapCod = await rectOf(cod);
	await cod.click().catch(() => {});
	await settle(1500);
	await shot('checkout_cod', { tap: tapCod });
	const place = await rectOf('.wc-block-components-checkout-place-order-button');
	await shot('checkout_place', { tap: place });
});

// 6. Track page
await step('track', async () => {
	await go('/track-order/');
	const num = page.locator('input[name*="order" i]').first();
	const tap = await rectOf(num);
	await page.evaluate(() => {
		const ins = [...document.querySelectorAll('form input:not([type=hidden]):not([type=submit])')];
		if (ins[0]) ins[0].value = '1234';
		if (ins[1]) ins[1].value = '98765 43210';
	});
	await shot('track', { tap });
});

// 7. Design your own
await step('design', async () => {
	await go('/product/design-your-own-oversized-t-shirt/');
	await shot('design');
	const input = page.locator('#sf-cz-input');
	const tap = await rectOf(input);
	await input.fill('मेरा पहाड़');
	await settle(1500);
	await shot('design_text', { tap });
	await page.evaluate(() => window.scrollTo(0, 0));
	await settle(600);
	await shot('design_preview');
	const photoTab = await rectOf('#sf-cz-tab-photo');
	await shot('design_photo_tab', { tap: photoTab });
});

fs.writeFileSync(path.join(OUT, 'shots.json'), JSON.stringify(meta, null, 1));
await browser.close();
log('done ' + Object.keys(meta).length + ' shots');
