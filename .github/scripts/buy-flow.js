// Walks the buying path like a customer: product page -> pick colour & size -> add to cart ->
// cart -> checkout (stops before placing an order). Saves screenshots + a log of every error.
const { chromium } = require('playwright');
const fs = require('fs');
const base = 'https://panuwear.com';
const out = process.argv[2] || 'out';
const product = process.env.PRODUCT || '/product/unisex-pullover-hoodie/';
fs.mkdirSync(out, { recursive: true });
const log = [];
const note = (s) => { log.push(s); console.log(s); };
(async () => {
  const browser = await chromium.launch();
  for (const v of [{ name: 'mob', w: 390, h: 844, m: true }, { name: 'desk', w: 1366, h: 900, m: false }]) {
    const ctx = await browser.newContext({ viewport: { width: v.w, height: v.h }, isMobile: v.m, deviceScaleFactor: v.m ? 2 : 1 });
    const page = await ctx.newPage();
    page.on('console', (m) => { if (m.type() === 'error') note(`[${v.name}] console: ${m.text().slice(0, 300)}`); });
    page.on('framenavigated', (f) => { if (f === page.mainFrame()) note(`[${v.name}] NAV ${f.url()}`); });
    page.on('response', (r) => { if (r.request().isNavigationRequest()) note(`[${v.name}] doc ${r.status()} ${r.url()} cache=${r.headers()['cf-cache-status'] || ''} wpsc=${/WP-Super-Cache/i.test(JSON.stringify(r.headers())) ? 'y' : ''} loc=${r.headers()['location'] || ''}`); });
    page.on('pageerror', (e) => note(`[${v.name}] pageerror: ${e.message.slice(0, 300)}`));
    page.on('response', (r) => { if (r.status() >= 400 && /panuwear/.test(r.url())) note(`[${v.name}] HTTP ${r.status()} ${r.url().slice(0, 160)}`); });
    const shot = (n) => page.screenshot({ path: `${out}/${v.name}-${n}.png`, fullPage: true }).catch(() => {});
    try {
      await page.goto(base + product, { waitUntil: 'load', timeout: 60000 });
      await page.waitForTimeout(1500);
      // Pick the first colour and size through the real selects (swatches update them too).
      const picked = await page.evaluate(() => {
        const out = [];
        document.querySelectorAll('form.variations_form select[name^="attribute_"]').forEach((s) => {
          const o = [...s.options].find((x) => x.value); if (o) { s.value = o.value; jQuery(s).trigger('change'); out.push(s.name + '=' + o.value); }
        });
        return out;
      });
      note(`[${v.name}] picked ${picked.join(', ')}`);
      await page.waitForTimeout(1200);
      await shot('1-product');
      await Promise.all([
        page.waitForLoadState('load').catch(() => {}),
        page.click('form.cart button.single_add_to_cart_button'),
      ]);
      await page.waitForTimeout(3500);
      note(`[${v.name}] after add: ${page.url()}`);
      await shot('2-after-add');
      await page.goto(base + '/cart/', { waitUntil: 'load', timeout: 60000 });
      await page.waitForTimeout(6000);
      const cart = await page.evaluate(() => ({ text: (document.querySelector('main') || document.body).innerText.slice(0, 500), items: document.querySelectorAll('.wc-block-cart-items__row, .cart_item').length, spinner: !!document.querySelector('.wc-block-components-skeleton, .wc-block-components-spinner') }));
      note(`[${v.name}] cart: ${JSON.stringify(cart)}`);
      await shot('3-cart');
      const pend = new Map();
      const onReq = (r) => { if (/wp-json|admin-ajax|wc-ajax/.test(r.url())) pend.set(r, Date.now()); };
      const onFin = async (r) => { const req = r.request(); if (pend.has(req)) { note(`[${v.name}] api ${req.method()} ${r.status()} ${req.url().replace(base, '').slice(0, 140)}`); if (/batch-off/.test(req.url())) { note(`[${v.name}] batch request: ${(req.postData() || '').slice(0, 800)}`); note(`[${v.name}] batch response: ${(await r.text().catch(() => '')).slice(0, 2500)}`); } pend.delete(req); } };
      const onFail = (req) => { if (pend.has(req)) { note(`[${v.name}] api FAILED ${req.method()} ${req.url().replace(base, '').slice(0, 140)} ${req.failure() && req.failure().errorText}`); pend.delete(req); } };
      page.on('request', onReq); page.on('response', onFin); page.on('requestfailed', onFail);
      await page.goto(base + '/checkout/', { waitUntil: 'load', timeout: 60000 });
      await page.waitForTimeout(15000);
      const co = await page.evaluate(() => ({ url: location.pathname, skeleton: document.querySelectorAll('.wc-block-components-skeleton__element, .wc-block-checkout.is-loading, [class*=skeleton]').length, form: !!document.querySelector('.wc-block-checkout__form, form.checkout'), scripts: [...document.scripts].map((x) => x.src.split('/').pop().split('?')[0]).filter((x) => /checkout|razorpay|cod|cashfree|phonepe|payment/i.test(x)), globals: ['wc','wcSettings','wp'].filter((g) => window[g]).join(','), wcKeys: window.wc ? Object.keys(window.wc).join(',') : '', text: (document.querySelector('main') || document.body).innerText.slice(0, 700), pay: [...document.querySelectorAll('input[name="radio-control-wc-payment-method-options"], input[name="payment_method"]')].map((i) => i.value) }));
      note(`[${v.name}] checkout: ${JSON.stringify(co)}`);
      for (const [r] of pend) note(`[${v.name}] api NEVER FINISHED ${r.method()} ${r.url().replace(base, '').slice(0, 140)}`);
      const html = await page.content(); fs.writeFileSync(`${out}/${v.name}-checkout.html`, html);
      await shot('4-checkout');
    } catch (e) { note(`[${v.name}] ERROR ${e.message.slice(0, 300)}`); await shot('error'); }
    const cookies = (await ctx.cookies()).map((c) => c.name).filter((n) => /woocommerce|wp_/.test(n));
    note(`[${v.name}] cookies: ${cookies.join(', ')}`);
    await ctx.close();
  }
  fs.writeFileSync(`${out}/log.txt`, log.join('\n') + '\n');
  await browser.close();
})();
