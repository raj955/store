// Product photos and "Design your own" blanks from Qikink's blank-garment photos.
// Not enqueued: load it by hand in a theonlinefood.com admin tab, then use window.SFQ.
(() => {
  const Q = (p) => p; // paths are relative to products.qikink.com/assets/admin/uploads/
  const STYLES = {
    UC22: { stage: [620, 796], box: { x: 180, y: 240, width: 260, height: 335 }, maxW: 14,
      shirt: { src: Q('mockup-3/1723443427front%20color.png'), l: 310, t: 400, s: 0.6 },
      photos: { 1: 'mockup-157/1741675235OversizedfrontWhite.webp', 4: 'mockup-3/1740714110Oversizedteefullgrey.webp', 61: 'mockup-157/1745571911OversizedOffwhite.webp' },
      shadow: { src: 'mockup-3/1723443441front%20shadow.png', l: 310, t: 400, s: 0.6, o: 1 },
      rope: [{ src: 'mockup-3/1740714187bgShadow.webp', l: 310, t: 400, s: 0.6, o: 0.5, tint: true }] },
    UC21: { stage: [620, 796], box: { x: 195, y: 246, width: 232, height: 299 }, maxW: 14,
      shirt: { src: 'mockup-139/1740810920newBaseWhite.png', l: 310, t: 400, s: 0.45 },
      photos: { 1: 'mockup-6/1741001677newfrontfullWhite2.webp', 4: 'mockup-139/1740812698newfrontfullGrey.webp', 5: 'mockup-6/1740988319newfrontfullCharcoal4.webp', 61: 'mockup-6/1745584624CrewteeOffwhitefront.webp' },
      shadow: { src: 'mockup-139/1740811283FrontShadowTex.webp', l: 310, t: 400, s: 0.45, o: 0.9 },
      rope: [{ src: 'mockup-155/1741665748BGShadow.webp', l: 310, t: 400, s: 0.45, o: 0.5, tint: true }] },
    UH26: { stage: [620, 796], box: { x: 188, y: 240, width: 255, height: 295 }, maxW: 14,
      shirt: { src: 'mockup-4/1724818890Fr%20base%201500.png', l: 315, t: 400, s: 0.45 },
      photos: { 1: 'mockup-146/1742533556SwfrontWhite.webp', 4: 'mockup-146/1742533630SwfrontGrey.webp' },
      shadow: { src: 'mockup-4/1724818888Fr%201500.png', l: 315, t: 400, s: 0.45, o: 0.7 },
      rope: [{ src: 'mockup-146/1742533668SwfrontShadow.webp', l: 315, t: 400, s: 0.45, o: 0.5, tint: true }] },
    UH24: { stage: [620, 796], box: { x: 222, y: 270, width: 157, height: 180 }, maxW: 10,
      shirt: { src: 'mockup-5/1724155123Base%20col.png', l: 300, t: 400, s: 0.9 },
      photos: { 1: 'mockup-167/1741945114HoodiefrontWhite.webp', 4: 'mockup-167/1741945692HoodiefrontGrey.webp' },
      shadow: { src: 'mockup-5/17241551151.png', l: 300, t: 400, s: 0.9, o: 0.9 },
      rope: [
        { src: 'mockup-167/1741946308RopeShadow2.webp', l: 300, t: 400, s: 0.9, o: 0.5, tint: true },
      ],
      over: [
        { src: 'mockup-5/1724155141Rope%20base.png', l: 300, t: 400, s: 0.9, o: 0.8, tint: true },
        { src: 'mockup-5/1724155155Rope.png', l: 300, t: 400, s: 0.9, o: 0.8 },
      ] },
  };
  // our colour slug -> [Qikink colour id, hex per style]
  const QHEX = {
    // from products.qikink.com/assetsroot/admin/mockups/<id>/colors.json (UC22 3, UC21 6, UH26 4, UH24 5)
    UC22: { 1: '#ffffff', 2: '#151515', 3: '#000b17', 4: '#C3C3C3', 8: '#083717', 9: '#141c4f', 10: '#900001', 25: '#290005', 41: '#26260a', 43: '#B6840D', 45: '#ffd4e9', 49: '#e0d2fc', 52: '#b34946', 53: '#adfff0', 54: '#abebff', 61: '#fffae7' },
    UC21: { 1: '#ffffff', 2: '#000000', 3: '#00080f', 4: '#C3C3C3', 8: '#021705', 9: '#0b1442', 10: '#a6050a', 25: '#290005', 41: '#1c1c09', 43: '#b36e00', 45: '#ffd6ea', 49: '#ebdeff', 51: '#ebc078', 52: '#a33c3e', 53: '#c7fff8', 54: '#bff0ff', 61: '#fffae7' },
    UH26: { 1: '#ffffff', 2: '#000000', 3: '#00080f', 4: '#C3C3C3', 25: '#290005', 41: '#22220D', 43: '#B6840D', 45: '#FFD9E1', 49: '#E6E6FA', 52: '#e57470', 53: '#c3ffe9', 54: '#9ecaed' },
    UH24: { 1: '#ffffff', 2: '#000000', 3: '#0e1723', 4: '#C3C3C3', 8: '#021705', 9: '#1F286A', 10: '#A50303', 25: '#230007', 41: '#2A2A16', 43: '#B6840D', 45: '#ffd4e9', 49: '#ebdeff', 52: '#e57470', 53: '#c3ffe9', 54: '#9ecaed' },
  };
  const CID = { black: 2, navy: 3, maroon: 25, 'bottle-green': 8, olive: 41, 'royal-blue': 9, lavender: 49, 'off-white': 61, white: 1, mint: 53, beige: 51, mustard: 43, 'grey-melange': 4, 'baby-blue': 54, red: 10, 'light-pink': 45, coral: 52 };

  let W = 1200, H = 1500;
  function size(w, h) { W = w; H = h; }
  let NONCE = null;
  const cache = {};
  async function nonce() {
    if (!NONCE) NONCE = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) => r.text());
    return NONCE;
  }
  async function asset(path) {
    if (cache[path]) return cache[path];
    const r = await fetch('/wp-json/store-features/v1/qikink-asset', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-WP-Nonce': await nonce(), 'Content-Type': 'application/json' },
      body: JSON.stringify({ path }),
    }).then((x) => x.json());
    if (!r.url) throw new Error('asset ' + path + ': ' + JSON.stringify(r));
    cache[path] = load(r.url);
    return cache[path];
  }
  function load(url) {
    return new Promise((ok, no) => { const i = new Image(); i.onload = () => ok(i); i.onerror = () => no(new Error('load ' + url)); i.src = url; });
  }
  function canvas(w, h) { const c = document.createElement('canvas'); c.width = w; c.height = h; return c; }
  function tint(img, hex) {
    const c = canvas(img.naturalWidth, img.naturalHeight), x = c.getContext('2d');
    x.drawImage(img, 0, 0); x.globalCompositeOperation = 'source-in'; x.fillStyle = hex; x.fillRect(0, 0, c.width, c.height);
    return c;
  }
  function stageTf(style) {
    const st = STYLES[style].stage, s = H / st[1];
    return { s, ox: (W - st[0] * s) / 2, oy: 0 };
  }
  function place(ctx, tf, img, el) {
    const w = (img.naturalWidth || img.width) * el.s * tf.s, h = (img.naturalHeight || img.height) * el.s * tf.s;
    ctx.globalAlpha = el.o == null ? 1 : el.o;
    ctx.drawImage(img, tf.ox + el.l * tf.s - w / 2, tf.oy + el.t * tf.s - h / 2, w, h);
    ctx.globalAlpha = 1;
  }

  // The garment alone (no print), 1200 x 1500.
  async function garment(style, colour, opts = {}) {
    const S = STYLES[style], id = CID[colour], hex = (QHEX[style] || {})[id];
    if (!id || !hex) throw new Error(style + ' has no ' + colour);
    const tf = stageTf(style), c = canvas(W, H), x = c.getContext('2d');
    x.fillStyle = opts.bg || '#f2f2f2'; x.fillRect(0, 0, W, H);
    for (const r of S.rope || []) {
      const img = await asset(r.src);
      place(x, tf, r.tint ? tint(img, opts.ropeHex || hex) : img, r);
    }
    const photo = S.photos[id];
    if (photo) {
      place(x, tf, await asset(photo), { ...S.shirt, o: 1 });
    } else {
      place(x, tf, tint(await asset(S.shirt.src), hex), { ...S.shirt, o: 1 });
      place(x, tf, await asset(S.shadow.src), S.shadow);
    }
    // Things that hang over the print (hoodie strings) also go on their own layer, so
    // compose() can put them back on top of the print.
    if ((S.over || []).length) {
      const ov = canvas(W, H), ovx = ov.getContext('2d');
      for (const r of S.over) {
        const img = await asset(r.src);
        if (photo && r.tint) continue;
        place(ovx, tf, r.tint ? tint(img, hex) : img, r);
      }
      x.drawImage(ov, 0, 0);
      c._over = ov;
    }
    return c;
  }

  // Print placed on the garment with the fabric's folds showing through it.
  function compose(g, art, style, wIn, opts = {}) {
    const S = STYLES[style], tf = stageTf(style);
    const ppi = (S.box.width / S.maxW) * tf.s;
    const w = Math.round(wIn * ppi), h = Math.round((art.naturalHeight / art.naturalWidth) * w);
    const cx = tf.ox + (S.box.x + S.box.width / 2) * tf.s;
    const top = tf.oy + (S.box.y + (opts.gapIn == null ? 0.5 : opts.gapIn) * S.box.width / S.maxW) * tf.s;
    const bx = Math.round(cx - w / 2), by = Math.round(top);
    // fold shading from the garment: brightness against its blurred self
    const gx = g.getContext('2d');
    const base = gx.getImageData(bx, by, w, h);
    const bl = canvas(W, H), blx = bl.getContext('2d');
    blx.filter = 'blur(' + Math.round(18 * W / 1200) + 'px)'; blx.drawImage(g, 0, 0);
    const blur = blx.getImageData(bx, by, w, h);
    const a = canvas(w, h), ax = a.getContext('2d');
    ax.imageSmoothingQuality = 'high'; ax.drawImage(art, 0, 0, w, h);
    const ad = ax.getImageData(0, 0, w, h), A = ad.data, B = base.data, L = blur.data;
    const k = opts.k == null ? 0.85 : opts.k;
    for (let i = 0; i < A.length; i += 4) {
      const lg = 0.299 * B[i] + 0.587 * B[i + 1] + 0.114 * B[i + 2];
      const lb = 0.299 * L[i] + 0.587 * L[i + 1] + 0.114 * L[i + 2];
      let r = (lg + 8) / (lb + 8); r = Math.min(1.18, Math.max(0.72, r));
      const m = 1 + (r - 1) * k;
      A[i] = Math.min(255, A[i] * m); A[i + 1] = Math.min(255, A[i + 1] * m); A[i + 2] = Math.min(255, A[i + 2] * m);
      A[i + 3] = A[i + 3] * 0.95;
    }
    ax.putImageData(ad, 0, 0);
    const out = canvas(W, H), ox = out.getContext('2d');
    ox.drawImage(g, 0, 0); ox.drawImage(a, bx, by);
    if (g._over) ox.drawImage(g._over, 0, 0);
    return out;
  }

  // Print area on a garment photo of the current size: corner (x, y) and pixels per inch.
  function area(style, wIn, gapIn) {
    const S = STYLES[style], tf = stageTf(style), ppi = (S.box.width / S.maxW) * tf.s;
    return { ppi, x: tf.ox + (S.box.x + S.box.width / 2) * tf.s - (wIn * ppi) / 2, y: tf.oy + (S.box.y + (gapIn == null ? 0.5 : gapIn) * S.box.width / S.maxW) * tf.s };
  }
  window.SFQ = { STYLES, QHEX, CID, asset, load, garment, compose, canvas, size, stageTf, area };
})();
'ok';
