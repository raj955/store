/* Panuwear reel renderer: draws a 1080x1920 vertical video of one product on a canvas and
   encodes it to MP4 (H.264) with WebCodecs. Runs inside headless Chromium (see render.mjs).
   window.renderReel(data) -> base64 MP4 (video only; the voice is added with ffmpeg). */
(function () {
	'use strict';
	var W = 1080, H = 1920, FPS = 30;
	var clamp = function (x, a, b) { a = a === undefined ? 0 : a; b = b === undefined ? 1 : b; return Math.max(a, Math.min(b, x)); };
	var ease = function (x) { x = clamp(x); return x < .5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2; };
	var eout = function (x) { x = clamp(x); return 1 - Math.pow(1 - x, 3); };
	var seg = function (t, a, b) { return clamp((t - a) / (b - a)); };

	var THEMES = [
		{ top: '#0E1424', mid: '#1A1F3A', bot: '#2A1B2E', glow: '246,185,59', accent: '#F6B93B', ink: '#14182B' },
		{ top: '#10201A', mid: '#1C3328', bot: '#2B2A1A', glow: '255,164,27', accent: '#FFB23F', ink: '#13201A' },
		{ top: '#22101A', mid: '#3A1A2A', bot: '#1E1430', glow: '255,120,150', accent: '#FF8FAB', ink: '#22101A' }
	];

	function rr(x, c, y, w, h, r) { x.beginPath(); x.moveTo(c + r, y); x.arcTo(c + w, y, c + w, y + h, r); x.arcTo(c + w, y + h, c, y + h, r); x.arcTo(c, y + h, c, y, r); x.arcTo(c, y, c + w, y, r); x.closePath(); }
	function text(x, s, px, y, font, color, alpha, shadow) {
		if (!s) { return; }
		x.save(); x.globalAlpha = alpha === undefined ? 1 : alpha; x.font = font; x.fillStyle = color; x.textAlign = 'center'; x.textBaseline = 'alphabetic';
		if (shadow !== false) { x.shadowColor = 'rgba(0,0,0,.45)'; x.shadowBlur = 18; x.shadowOffsetY = 4; }
		// Shrink long lines to fit the width.
		var size = parseFloat(font.match(/(\d+)px/)[1]);
		while (x.measureText(s).width > W - 120 && size > 30) { size -= 4; x.font = font.replace(/\d+px/, size + 'px'); }
		x.fillText(s, px, y); x.restore();
	}
	function card(x, img, cx, cy, w, h, s, fx, fy, alpha, rot, rad) {
		if (!img) { return; }
		x.save(); x.globalAlpha = alpha === undefined ? 1 : alpha; x.translate(cx, cy); x.rotate(rot || 0);
		x.shadowColor = 'rgba(0,0,0,.5)'; x.shadowBlur = 60; x.shadowOffsetY = 24; rr(x, -w / 2, -h / 2, w, h, rad || 34); x.fillStyle = '#EFEFEF'; x.fill(); x.shadowColor = 'transparent'; x.clip();
		var base = Math.max(w / img.width, h / img.height) * s, dw = img.width * base, dh = img.height * base;
		var dx = -w / 2 - (fx * dw - w / 2), dy = -h / 2 - (fy * dh - h / 2);
		dx = Math.min(-w / 2, Math.max(w / 2 - dw, dx)); dy = Math.min(-h / 2, Math.max(h / 2 - dh, dy));
		x.drawImage(img, dx, dy, dw, dh); x.restore();
	}
	function pill(x, label, cx, cy, bg, fg, alpha, size) {
		x.save(); x.globalAlpha = alpha; x.font = '800 ' + (size || 52) + 'px "Baloo 2"';
		var w = x.measureText(label).width + 90, h = (size || 52) * 1.9;
		rr(x, cx - w / 2, cy - h / 2, w, h, h / 2); x.fillStyle = bg; x.fill();
		x.fillStyle = fg; x.textAlign = 'center'; x.textBaseline = 'middle'; x.fillText(label, cx, cy + 3); x.restore();
	}
	function badge(x, icon, title, sub, cx, cy, alpha, accent) {
		x.save(); x.globalAlpha = alpha; var w = 440, h = 150;
		rr(x, cx - w / 2, cy - h / 2, w, h, 26); x.fillStyle = 'rgba(255,255,255,.10)'; x.fill(); x.strokeStyle = 'rgba(255,255,255,.25)'; x.lineWidth = 2; x.stroke();
		x.font = '60px sans-serif'; x.textAlign = 'left'; x.textBaseline = 'middle'; x.fillText(icon, cx - w / 2 + 28, cy);
		x.fillStyle = accent; x.font = '800 44px "Baloo 2"'; x.fillText(title, cx - w / 2 + 112, cy - 22);
		x.fillStyle = 'rgba(255,255,255,.85)'; x.font = '500 30px "Baloo 2"'; x.fillText(sub, cx - w / 2 + 112, cy + 30);
		x.restore();
	}

	function makeDraw(d, imgs) {
		var th = THEMES[(d.theme || 0) % THEMES.length];
		var D = d.duration;
		// Scene boundaries scale with the voice length.
		var k = D / 15;
		var A = 2.7 * k, B = 6.4 * k, C = 9.3 * k, E = D - 3.4;
		var colours = imgs.slice(0, 3);
		return function (x, t) {
			var g = x.createLinearGradient(0, 0, 0, H); g.addColorStop(0, th.top); g.addColorStop(.6, th.mid); g.addColorStop(1, th.bot); x.fillStyle = g; x.fillRect(0, 0, W, H);
			var sy = 1150 - 220 * eout(t / 3), r = x.createRadialGradient(W / 2, sy, 0, W / 2, sy, 640); r.addColorStop(0, 'rgba(' + th.glow + ',.30)'); r.addColorStop(1, 'rgba(' + th.glow + ',0)'); x.fillStyle = r; x.fillRect(0, 0, W, H);

			// 1. Hook
			if (t < A + .3) {
				var a = seg(t, 0, .5) * (1 - seg(t, A - .2, A + .3)), b = seg(t, A * .4, A * .4 + .5) * (1 - seg(t, A - .2, A + .3));
				text(x, d.hook[0], W / 2, 820 - 40 * (1 - eout(seg(t, 0, .6))), '120px "Rozha One"', '#FFFFFF', a);
				text(x, d.hook[1], W / 2, 1010 - 40 * (1 - eout(seg(t, A * .4, A * .4 + .6))), '96px "Rozha One"', th.accent, b);
			}
			// 2. Main photo, slow zoom into the print
			if (t >= A - .1 && t < B + .3) {
				var p = seg(t, A, B), a2 = eout(seg(t, A - .1, A + .4)) * (1 - seg(t, B - .1, B + .3));
				card(x, imgs[0], W / 2, 860 + 80 * (1 - eout(seg(t, A - .1, A + .5))), 900, 1150, 1 + .5 * ease(p), .5, .42, a2, 0);
				text(x, d.line, W / 2, 1560, '110px "Rozha One"', th.accent, eout(seg(t, A + .5, A + 1.1)) * (1 - seg(t, B - .1, B + .3)));
				text(x, d.sub, W / 2, 1650, '52px "Kalam"', '#FFFFFF', eout(seg(t, A + .9, A + 1.5)) * (1 - seg(t, B - .1, B + .3)));
			}
			// 3. Second photo with the fabric line
			if (t >= B && t < C + .3) {
				var a3 = eout(seg(t, B, B + .5)) * (1 - seg(t, C - .1, C + .3)), sl = eout(seg(t, B, B + .7));
				text(x, d.middle || 'हर दिन का comfort', W / 2, 330, '70px "Rozha One"', '#FFFFFF', a3);
				card(x, imgs[1] || imgs[0], W / 2 - 245 - 120 * (1 - sl), 900, 460, 640, 1, .5, .45, a3, -.04, 30);
				card(x, imgs[2] || imgs[0], W / 2 + 245 + 120 * (1 - sl), 980, 460, 640, 1, .5, .45, a3, .04, 30);
				text(x, d.fabric || '100% cotton · Made in India', W / 2, 1450, '500 48px "Baloo 2"', 'rgba(255,255,255,.92)', eout(seg(t, B + .6, B + 1.1)) * (1 - seg(t, C - .1, C + .3)));
			}
			// 4. Colours / more photos
			if (t >= C && t < E + .3) {
				var a4 = eout(seg(t, C, C + .4)) * (1 - seg(t, E - .1, E + .3)), n = colours.length;
				text(x, n > 1 ? (n + ' रंग, एक मूड') : 'आपका नया favourite', W / 2, 330, '84px "Rozha One"', '#FFFFFF', a4);
				var span = Math.max(.6, (E - C - .3) / Math.max(1, n)), fi = Math.min(n - 1, Math.max(0, Math.floor((t - C - .2) / span)));
				for (var i = 0; i < n; i++) { if (i !== fi) { card(x, colours[i], W / 2 + (i - fi) * 330, 1000, 520, 680, 1, .5, .42, a4 * .7, (i - fi) * .06, 32); } }
				card(x, colours[fi], W / 2, 960, 640, 830, 1 + .06 * seg(t, C + fi * span, C + (fi + 1) * span), .5, .42, a4, 0, 32);
			}
			// 5. End card: price, free delivery, COD, site
			if (t >= E) {
				var a5 = eout(seg(t, E, E + .5));
				card(x, imgs[0], W / 2, 640, 520, 660, 1.04, .5, .42, a5, 0, 32);
				text(x, d.title, W / 2, 1090, '800 66px "Baloo 2"', '#FFFFFF', a5);
				text(x, d.price, W / 2, 1210, '800 110px "Baloo 2"', th.accent, eout(seg(t, E + .3, E + .8)));
				var b5 = eout(seg(t, E + .6, E + 1.1));
				badge(x, '🚚', 'फ्री डिलीवरी', 'पूरे भारत में', W / 2 - 232, 1380, b5, th.accent);
				badge(x, '💵', 'कैश ऑन डिलीवरी', 'सामान मिले तब पैसे दो', W / 2 + 232, 1380, b5, th.accent);
				pill(x, 'panuwear.com', W / 2, 1580, th.accent, th.ink, eout(seg(t, E + .9, E + 1.4)), 56);
			}
			x.save(); x.globalAlpha = .7; x.font = '800 44px "Baloo 2"'; x.fillStyle = '#FFFFFF'; x.textAlign = 'center'; x.fillText('PANUWEAR', W / 2, 170); x.restore();
		};
	}

	function loadImage(src) {
		return new Promise(function (res, rej) { var i = new Image(); i.onload = function () { res(i); }; i.onerror = rej; i.src = src; });
	}

	window.renderReel = async function (d) {
		await Promise.all(['120px "Rozha One"', '800 60px "Baloo 2"', '500 40px "Baloo 2"', '700 40px Kalam'].map(function (f) { return document.fonts.load(f, 'चल निकलते हैं Abc ₹'); }));
		var imgs = [];
		for (var i = 0; i < d.images.length; i++) { try { imgs.push(await loadImage(d.images[i])); } catch (e) { /* skip */ } }
		if (!imgs.length) { throw new Error('no images'); }
		var draw = makeDraw(d, imgs);
		var c = new OffscreenCanvas(W, H), x = c.getContext('2d');
		// H.264 where the browser has it, else VP9 (ffmpeg re-encodes to H.264 afterwards anyway).
		var cfg = { codec: 'avc1.640028', width: W, height: H, bitrate: 12000000, framerate: FPS };
		var mcodec = 'avc';
		if (!(await VideoEncoder.isConfigSupported(cfg)).supported) { cfg.codec = 'vp09.00.40.08'; mcodec = 'vp9'; }
		var target = new Mp4Muxer.ArrayBufferTarget();
		var muxer = new Mp4Muxer.Muxer({ target: target, video: { codec: mcodec, width: W, height: H, frameRate: FPS }, fastStart: 'in-memory' });
		var err = null;
		var enc = new VideoEncoder({ output: function (ch, meta) { muxer.addVideoChunk(ch, meta); }, error: function (e) { err = e; } });
		enc.configure(cfg);
		var n = Math.round(d.duration * FPS);
		for (var f = 0; f < n; f++) {
			draw(x, f / FPS);
			var vf = new VideoFrame(c, { timestamp: Math.round(f * 1e6 / FPS), duration: Math.round(1e6 / FPS) });
			enc.encode(vf, { keyFrame: f % 60 === 0 }); vf.close();
			if (enc.encodeQueueSize > 8) { await new Promise(function (r) { setTimeout(r, 1); }); }
			if (err) { throw err; }
		}
		await enc.flush(); muxer.finalize();
		// Cover image: the end card.
		draw(x, d.duration - .2);
		var cover = await c.convertToBlob({ type: 'image/jpeg', quality: .88 });
		var toB64 = async function (buf) { var b = new Uint8Array(buf), s = ''; for (var j = 0; j < b.length; j += 32768) { s += String.fromCharCode.apply(null, b.subarray(j, j + 32768)); } return btoa(s); };
		return { video: await toB64(target.buffer), cover: await toB64(await cover.arrayBuffer()) };
	};
})();
