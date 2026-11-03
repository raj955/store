/* Panuwear "how to order" tutorial: draws one scene at a time on a 1080x1920 canvas (real site
   screenshots inside a phone, captions, finger taps) and encodes it to MP4 with WebCodecs.
   window.renderTutorialScene(spec) -> base64 MP4 (video only). window.drawTutorialFrame(spec, t)
   draws a single frame (for checking). Runs in headless Chromium (see build.mjs). */
(function () {
	'use strict';
	var W = 1080, H = 1920, FPS = 30;
	var INK = '#141417', INK2 = '#1F1F25', GOLD = '#F6B93B', CREAM = '#FFF7E6', GREEN = '#2F9E5B', PINK = '#E0335F';
	var clamp = function (x, a, b) { a = a === undefined ? 0 : a; b = b === undefined ? 1 : b; return Math.max(a, Math.min(b, x)); };
	var ease = function (x) { x = clamp(x); return x < .5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2; };
	var eout = function (x) { x = clamp(x); return 1 - Math.pow(1 - x, 3); };
	var seg = function (t, a, b) { return clamp((t - a) / (b - a)); };

	// Phone geometry: screen 700 wide, 390x844 css viewport, so 1 css px = 700/390 canvas px.
	var SW = 700, SH = Math.round(700 * 844 / 390), PAD = 20;
	var PX = (W - SW) / 2, PY = 520;
	var K = SW / 390;

	function rr(x, l, t, w, h, r) { x.beginPath(); x.moveTo(l + r, t); x.arcTo(l + w, t, l + w, t + h, r); x.arcTo(l + w, t + h, l, t + h, r); x.arcTo(l, t + h, l, t, r); x.arcTo(l, t, l + w, t, r); x.closePath(); }
	function wrap(x, s, maxW) {
		var words = String(s).split(' '), lines = [], cur = '';
		for (var i = 0; i < words.length; i++) {
			var test = cur ? cur + ' ' + words[i] : words[i];
			if (x.measureText(test).width > maxW && cur) { lines.push(cur); cur = words[i]; } else { cur = test; }
		}
		if (cur) { lines.push(cur); }
		return lines;
	}
	function para(x, s, cx, y, font, color, alpha, lh, maxW) {
		if (!s) { return y; }
		x.save(); x.globalAlpha = alpha; x.font = font; x.fillStyle = color; x.textAlign = 'center'; x.textBaseline = 'alphabetic';
		var lines = wrap(x, s, maxW || W - 140);
		for (var i = 0; i < lines.length; i++) { x.fillText(lines[i], cx, y + i * lh); }
		x.restore();
		return y + lines.length * lh;
	}

	function background(x, t) {
		var g = x.createLinearGradient(0, 0, 0, H);
		g.addColorStop(0, '#1B1A20'); g.addColorStop(.55, INK); g.addColorStop(1, '#0E0E11');
		x.fillStyle = g; x.fillRect(0, 0, W, H);
		// Soft warm glow behind the phone, drifting slowly.
		var gx = W / 2 + Math.sin(t * .35) * 60, gy = 1150;
		var rg = x.createRadialGradient(gx, gy, 40, gx, gy, 760);
		rg.addColorStop(0, 'rgba(246,185,59,.20)'); rg.addColorStop(1, 'rgba(246,185,59,0)');
		x.fillStyle = rg; x.fillRect(0, 0, W, H);
	}

	function header(x, sp, t) {
		var a = eout(seg(t, .05, .55));
		// Step chip.
		if (sp.step) {
			x.save(); x.globalAlpha = a; x.font = '800 34px "Baloo 2"';
			var label = 'STEP ' + sp.step + ' / ' + sp.total;
			var w = x.measureText(label).width + 56;
			rr(x, W / 2 - w / 2, 92, w, 58, 29); x.fillStyle = GOLD; x.fill();
			x.fillStyle = INK; x.textAlign = 'center'; x.textBaseline = 'middle'; x.fillText(label, W / 2, 123);
			x.restore();
		}
		var y = para(x, sp.title, W / 2, 245 + 18 * (1 - a), '800 64px "Baloo 2"', '#FFFFFF', a, 76, W - 120);
		para(x, sp.sub, W / 2, y + 8, '500 38px "Baloo 2"', 'rgba(255,255,255,.72)', eout(seg(t, .3, .8)), 50, W - 160);
	}

	function phoneFrame(x) {
		x.save();
		x.shadowColor = 'rgba(0,0,0,.6)'; x.shadowBlur = 70; x.shadowOffsetY = 30;
		rr(x, PX - PAD, PY - PAD, SW + PAD * 2, SH + PAD * 2, 78); x.fillStyle = '#0A0A0C'; x.fill();
		x.restore();
		x.save(); rr(x, PX - PAD, PY - PAD, SW + PAD * 2, SH + PAD * 2, 78); x.strokeStyle = 'rgba(255,255,255,.14)'; x.lineWidth = 3; x.stroke(); x.restore();
	}
	function screenClip(x) { rr(x, PX, PY, SW, SH, 58); x.clip(); }

	// A screenshot inside the screen; scroll = css px from the top (for tall shots).
	function drawShot(x, img, alpha, scroll) {
		if (!img) { return; }
		x.save(); screenClip(x); x.globalAlpha = alpha;
		var s = SW / img.width;
		x.drawImage(img, PX, PY - (scroll || 0) * K, SW, img.height * s);
		x.restore();
	}

	function tapMark(x, tap, t0, t, scroll) {
		if (!tap) { return; }
		var cx = PX + (tap.x + tap.w / 2) * K, cy = PY + (tap.y - (scroll || 0) + tap.h / 2) * K;
		var appear = eout(seg(t, t0 - .7, t0 - .25)), press = seg(t, t0, t0 + .6), fade = 1 - seg(t, t0 + .9, t0 + 1.3);
		if (appear <= 0 || fade <= 0) { return; }
		x.save();
		// Highlight box around what to tap.
		x.globalAlpha = appear * fade;
		rr(x, PX + tap.x * K - 10, PY + (tap.y - (scroll || 0)) * K - 10, tap.w * K + 20, tap.h * K + 20, 18);
		x.strokeStyle = GOLD; x.lineWidth = 6; x.stroke();
		// Ripple when the finger presses.
		if (press > 0 && press < 1) {
			x.globalAlpha = (1 - press) * fade;
			x.beginPath(); x.arc(cx, cy, 30 + press * 90, 0, Math.PI * 2); x.strokeStyle = '#FFFFFF'; x.lineWidth = 6; x.stroke();
		}
		// Finger dot.
		x.globalAlpha = appear * fade;
		var r = 34 - 8 * Math.sin(Math.PI * clamp(press * 2));
		x.beginPath(); x.arc(cx, cy, r, 0, Math.PI * 2); x.fillStyle = 'rgba(255,255,255,.6)'; x.fill();
		x.lineWidth = 4; x.strokeStyle = 'rgba(20,20,23,.55)'; x.stroke();
		x.restore();
	}

	/* ---------- drawn screens (no screenshot) ---------- */
	function browserScreen(x, t, sp) {
		x.save(); screenClip(x);
		x.fillStyle = '#F4F4F6'; x.fillRect(PX, PY, SW, SH);
		// Generic browser: address bar.
		rr(x, PX + 40, PY + 110, SW - 80, 92, 46); x.fillStyle = '#FFFFFF'; x.fill(); x.strokeStyle = '#D9D9DE'; x.lineWidth = 3; x.stroke();
		var full = 'panuwear.com', n = Math.floor(clamp((t - sp.typeAt) / 1.4) * full.length);
		x.font = '500 40px "Baloo 2"'; x.fillStyle = '#1B1B1F'; x.textAlign = 'left'; x.textBaseline = 'middle';
		var typed = full.slice(0, n);
		x.fillText(typed || '', PX + 140, PY + 158);
		if (!typed) { x.fillStyle = '#9A9AA2'; x.fillText('Search or type URL', PX + 140, PY + 158); }
		if (Math.floor(t * 2) % 2 === 0 && n < full.length) { var cw = x.measureText(typed).width; x.fillStyle = '#1B1B1F'; x.fillRect(PX + 142 + cw, PY + 132, 3, 52); }
		// Search icon.
		x.strokeStyle = '#6B6B73'; x.lineWidth = 5; x.beginPath(); x.arc(PX + 98, PY + 152, 15, 0, Math.PI * 2); x.stroke(); x.beginPath(); x.moveTo(PX + 109, PY + 163); x.lineTo(PX + 121, PY + 175); x.stroke();
		// Suggestion row once typed.
		var s = eout(seg(t, sp.typeAt + 1.5, sp.typeAt + 1.9));
		if (s > 0) {
			x.globalAlpha = s;
			rr(x, PX + 40, PY + 230, SW - 80, 110, 24); x.fillStyle = '#FFFFFF'; x.fill();
			x.fillStyle = '#1B1B1F'; x.font = '700 38px "Baloo 2"'; x.fillText('panuwear.com', PX + 90, PY + 272);
			x.fillStyle = '#6B6B73'; x.font = '500 28px "Baloo 2"'; x.fillText('Panuwear – Printed Oversized Tees, Hoodies', PX + 90, PY + 312);
		}
		x.restore();
	}
	function confirmScreen(x, t, t0) {
		var a = eout(seg(t, t0, t0 + .5));
		if (a <= 0) { return; }
		x.save(); screenClip(x); x.globalAlpha = a;
		x.fillStyle = '#FAFAF7'; x.fillRect(PX, PY, SW, SH);
		var cy = PY + 470, pop = eout(seg(t, t0 + .2, t0 + .8));
		x.beginPath(); x.arc(W / 2, cy, 110 * pop, 0, Math.PI * 2); x.fillStyle = GREEN; x.fill();
		x.strokeStyle = '#fff'; x.lineWidth = 18; x.lineCap = 'round'; x.lineJoin = 'round';
		var k = seg(t, t0 + .6, t0 + 1.1);
		x.beginPath(); x.moveTo(W / 2 - 48, cy + 4); x.lineTo(W / 2 - 48 + 34 * Math.min(1, k * 2), cy + 4 + 34 * Math.min(1, k * 2));
		if (k > .5) { x.lineTo(W / 2 - 14 + 70 * (k - .5) * 2, cy + 38 - 80 * (k - .5) * 2); }
		x.stroke();
		x.textAlign = 'center'; x.fillStyle = '#141417'; x.font = '800 60px "Baloo 2"'; x.fillText('Order placed!', W / 2, cy + 230);
		x.fillStyle = '#55555C'; x.font = '500 36px "Baloo 2"'; x.fillText('Order #1234 · ₹690 · Cash on Delivery', W / 2, cy + 300);
		rr(x, PX + 60, cy + 360, SW - 120, 150, 26); x.fillStyle = '#EEF6EF'; x.fill();
		x.fillStyle = '#173A22'; x.font = '700 34px "Baloo 2"'; x.fillText('Confirmation email पर भेज दिया गया', W / 2, cy + 425);
		x.fillStyle = '#3C5A44'; x.font = '500 30px "Baloo 2"'; x.fillText('2-3 दिन में ship, 5-7 दिन में delivery', W / 2, cy + 472);
		x.fillStyle = '#9A9AA2'; x.font = '500 26px "Baloo 2"'; x.fillText('(Demo order)', W / 2, cy + 590);
		x.restore();
	}
	function timelineScreen(x, t) {
		x.save(); screenClip(x);
		x.fillStyle = '#FAFAF7'; x.fillRect(PX, PY, SW, SH);
		x.fillStyle = '#141417'; x.textAlign = 'left'; x.font = '800 54px "Baloo 2"'; x.fillText('Order #1234', PX + 60, PY + 180);
		x.fillStyle = '#6B6B73'; x.font = '500 30px "Baloo 2"'; x.fillText('Demo order', PX + 60, PY + 230);
		var steps = [['Order placed', '25 Oct'], ['Printing', 'आपका design छप रहा है'], ['Shipped', 'Delhivery · AWB 1234567890'], ['Delivered', 'आपके दरवाज़े पर']];
		var y0 = PY + 340, gap = 210;
		for (var i = 0; i < steps.length; i++) {
			var on = seg(t, .8 + i * 1.1, 1.2 + i * 1.1), y = y0 + i * gap;
			if (i < steps.length - 1) {
				x.fillStyle = '#E3E1DA'; x.fillRect(PX + 104, y + 30, 8, gap - 60);
				x.fillStyle = GREEN; x.fillRect(PX + 104, y + 30, 8, (gap - 60) * seg(t, 1.2 + i * 1.1, 1.9 + i * 1.1));
			}
			x.beginPath(); x.arc(PX + 108, y, 30, 0, Math.PI * 2); x.fillStyle = on > 0 ? (i === 3 ? GREEN : GREEN) : '#FFFFFF'; x.globalAlpha = 1; x.fill();
			x.lineWidth = 6; x.strokeStyle = on > 0 ? GREEN : '#D5D3CC'; x.stroke();
			x.globalAlpha = .45 + .55 * on;
			x.fillStyle = '#141417'; x.font = '800 44px "Baloo 2"'; x.fillText(steps[i][0], PX + 170, y + 4);
			x.fillStyle = '#55555C'; x.font = '500 30px "Baloo 2"'; x.fillText(steps[i][1], PX + 170, y + 52);
			x.globalAlpha = 1;
		}
		var b = eout(seg(t, 5.2, 5.7));
		if (b > 0) {
			x.globalAlpha = b;
			rr(x, PX + 60, PY + 1220, SW - 120, 96, 48); x.fillStyle = '#141417'; x.fill();
			x.fillStyle = '#fff'; x.textAlign = 'center'; x.font = '800 38px "Baloo 2"'; x.fillText('Track live  →  courier', W / 2, PY + 1280);
		}
		x.restore();
	}

	/* ---------- full-canvas cards ---------- */
	function logo(x, cx, cy, size, alpha) {
		x.save(); x.globalAlpha = alpha; x.textAlign = 'center'; x.textBaseline = 'alphabetic';
		x.font = '800 ' + size + 'px "Baloo 2"';
		var a = 'Panu', b = 'wear', wa = x.measureText(a).width, wb = x.measureText(b).width, l = cx - (wa + wb) / 2;
		x.textAlign = 'left'; x.fillStyle = '#FFFFFF'; x.fillText(a, l, cy); x.fillStyle = PINK; x.fillText(b, l + wa, cy);
		x.restore();
	}
	function introCard(x, t, sp) {
		logo(x, W / 2, 700, 150, eout(seg(t, .1, .7)));
		x.save(); x.globalAlpha = eout(seg(t, .4, .9)); x.font = '600 34px "Baloo 2"'; x.fillStyle = 'rgba(255,255,255,.6)'; x.textAlign = 'center'; x.fillText('CLOTHES THAT FEEL LIKE YOU', W / 2, 770); x.restore();
		var y = para(x, sp.title, W / 2, 960 + 20 * (1 - eout(seg(t, .7, 1.3))), '800 78px "Baloo 2"', '#FFFFFF', eout(seg(t, .7, 1.3)), 92, W - 140);
		para(x, sp.sub, W / 2, y + 20, '600 44px "Baloo 2"', GOLD, eout(seg(t, 1.1, 1.7)), 58, W - 160);
		var items = ['Order करना', 'Payment', 'Order track', 'अपना design'];
		for (var i = 0; i < items.length; i++) {
			var a = eout(seg(t, 1.6 + i * .25, 2.1 + i * .25));
			x.save(); x.globalAlpha = a; x.font = '700 38px "Baloo 2"';
			var w = x.measureText(items[i]).width + 70, cx = W / 2 + (i % 2 ? 1 : -1) * 220, cy = 1330 + Math.floor(i / 2) * 120 + 20 * (1 - a);
			rr(x, cx - w / 2, cy - 42, w, 84, 42); x.fillStyle = 'rgba(255,255,255,.08)'; x.fill(); x.strokeStyle = 'rgba(255,255,255,.22)'; x.lineWidth = 2; x.stroke();
			x.fillStyle = '#FFFFFF'; x.textAlign = 'center'; x.textBaseline = 'middle'; x.fillText(items[i], cx, cy + 2); x.restore();
		}
	}
	function outroCard(x, t) {
		logo(x, W / 2, 560, 130, eout(seg(t, .1, .7)));
		var tiles = [['🚚', 'फ्री डिलीवरी', 'पूरे भारत में'], ['💵', 'कैश ऑन डिलीवरी', 'सामान मिले, तब पैसे'], ['📲', 'WhatsApp support', 'कोई भी सवाल पूछें'], ['🔁', 'आसान replacement', 'गलत/खराब आए तो']];
		for (var i = 0; i < tiles.length; i++) {
			var a = eout(seg(t, .5 + i * .35, 1 + i * .35)), y = 760 + i * 190 + 20 * (1 - a);
			x.save(); x.globalAlpha = a;
			rr(x, 150, y, W - 300, 160, 30); x.fillStyle = 'rgba(255,255,255,.07)'; x.fill(); x.strokeStyle = 'rgba(255,255,255,.18)'; x.lineWidth = 2; x.stroke();
			x.font = '70px sans-serif'; x.textAlign = 'left'; x.textBaseline = 'middle'; x.fillText(tiles[i][0], 200, y + 82);
			x.fillStyle = '#FFFFFF'; x.font = '800 48px "Baloo 2"'; x.fillText(tiles[i][1], 320, y + 60);
			x.fillStyle = 'rgba(255,255,255,.7)'; x.font = '500 34px "Baloo 2"'; x.fillText(tiles[i][2], 320, y + 112);
			x.restore();
		}
		var p = eout(seg(t, 2.2, 2.7));
		x.save(); x.globalAlpha = p; x.font = '800 70px "Baloo 2"';
		var w = x.measureText('panuwear.com').width + 120;
		rr(x, W / 2 - w / 2, 1580, w, 130, 65); x.fillStyle = GOLD; x.fill();
		x.fillStyle = INK; x.textAlign = 'center'; x.textBaseline = 'middle'; x.fillText('panuwear.com', W / 2, 1647); x.restore();
		para(x, 'कपड़े नहीं, अपनी vibe पहनो।', W / 2, 1810, '700 44px "Baloo 2"', 'rgba(255,255,255,.85)', eout(seg(t, 2.6, 3.1)), 56);
	}

	/* ---------- one frame ---------- */
	function frame(x, sp, imgs, t) {
		background(x, t);
		if (sp.kind === 'intro') { introCard(x, t, sp); return; }
		if (sp.kind === 'outro') { outroCard(x, t); return; }
		header(x, sp, t);
		// Gentle push towards the tap (camera).
		var shots = sp.shots || [], zoom = 1, fx = W / 2, fy = PY + SH / 2;
		var tapShot = null;
		for (var i = 0; i < shots.length; i++) { if (shots[i].tap && shots[i].tapAt) { tapShot = shots[i]; } }
		if (tapShot) {
			var tc = tapShot.tap, k = ease(seg(t, tapShot.tapAt - 1.4, tapShot.tapAt - .4)) * (1 - ease(seg(t, tapShot.tapAt + .9, tapShot.tapAt + 1.6)));
			zoom = 1 + .10 * k; fx = PX + (tc.x + tc.w / 2) * K; fy = PY + (tc.y + tc.h / 2 - (tapShot.scrollTo || 0)) * K;
		}
		var rise = eout(seg(t, 0, .6));
		x.save();
		x.translate(0, 60 * (1 - rise));
		x.translate(fx, fy); x.scale(zoom, zoom); x.translate(-fx, -fy);
		phoneFrame(x);
		x.save(); screenClip(x); x.fillStyle = '#FFFFFF'; x.fillRect(PX, PY, SW, SH); x.restore();
		if (sp.kind === 'browser') { browserScreen(x, t, sp); }
		for (var j = 0; j < shots.length; j++) {
			var s = shots[j], img = imgs[s.name];
			var a = s.at <= 0 ? 1 : eout(seg(t, s.at, s.at + .4));
			var scroll = 0;
			if (s.scrollTo) { scroll = s.scrollTo * ease(seg(t, s.scrollFrom || s.at, s.scrollEnd || (s.at + 3))); }
			if (a > 0) { drawShot(x, img, a, scroll); }
		}
		if (sp.kind === 'confirm') { confirmScreen(x, t, sp.confirmAt); }
		if (sp.kind === 'timeline') { timelineScreen(x, t); }
		for (var m = 0; m < shots.length; m++) {
			var sh = shots[m];
			if (sh.tap && sh.tapAt) {
				var sc = sh.scrollTo ? sh.scrollTo * ease(seg(t, sh.scrollFrom || sh.at, sh.scrollEnd || (sh.at + 3))) : 0;
				tapMark(x, sh.tap, sh.tapAt, t, sc);
			}
		}
		x.restore();
		// Fade the whole scene in/out a little so cuts feel soft.
		var fade = Math.min(seg(t, 0, .25), 1 - seg(t, sp.duration - .25, sp.duration));
		if (fade < 1) { x.save(); x.globalAlpha = 1 - fade; x.fillStyle = INK; x.fillRect(0, 0, W, H); x.restore(); }
	}

	function loadImage(src) { return new Promise(function (res, rej) { var i = new Image(); i.onload = function () { res(i); }; i.onerror = rej; i.src = src; }); }
	async function prep(sp) {
		await Promise.all(['800 64px "Baloo 2"', '500 38px "Baloo 2"', '700 40px "Baloo 2"'].map(function (f) { return document.fonts.load(f, 'ऑर्डर Abc ₹'); }));
		var imgs = {};
		var shots = sp.shots || [];
		for (var i = 0; i < shots.length; i++) { imgs[shots[i].name] = await loadImage(shots[i].src); }
		return imgs;
	}

	window.drawTutorialFrame = async function (sp, t) {
		var imgs = await prep(sp);
		var c = new OffscreenCanvas(W, H), x = c.getContext('2d');
		frame(x, sp, imgs, t);
		var blob = await c.convertToBlob({ type: 'image/jpeg', quality: .9 });
		var b = new Uint8Array(await blob.arrayBuffer()), s = '';
		for (var j = 0; j < b.length; j += 32768) { s += String.fromCharCode.apply(null, b.subarray(j, j + 32768)); }
		return btoa(s);
	};

	window.renderTutorialScene = async function (sp) {
		var imgs = await prep(sp);
		var c = new OffscreenCanvas(W, H), x = c.getContext('2d');
		var cfg = { codec: 'avc1.640028', width: W, height: H, bitrate: 10000000, framerate: FPS };
		var mcodec = 'avc';
		if (!(await VideoEncoder.isConfigSupported(cfg)).supported) { cfg.codec = 'vp09.00.40.08'; mcodec = 'vp9'; }
		var target = new Mp4Muxer.ArrayBufferTarget();
		var muxer = new Mp4Muxer.Muxer({ target: target, video: { codec: mcodec, width: W, height: H, frameRate: FPS }, fastStart: 'in-memory' });
		var err = null;
		var enc = new VideoEncoder({ output: function (ch, meta) { muxer.addVideoChunk(ch, meta); }, error: function (e) { err = e; } });
		enc.configure(cfg);
		var n = Math.round(sp.duration * FPS);
		for (var f = 0; f < n; f++) {
			frame(x, sp, imgs, f / FPS);
			var vf = new VideoFrame(c, { timestamp: Math.round(f * 1e6 / FPS), duration: Math.round(1e6 / FPS) });
			enc.encode(vf, { keyFrame: f % 60 === 0 }); vf.close();
			if (enc.encodeQueueSize > 8) { await new Promise(function (r) { setTimeout(r, 1); }); }
			if (err) { throw err; }
		}
		await enc.flush(); muxer.finalize();
		var b = new Uint8Array(target.buffer), s = '';
		for (var j = 0; j < b.length; j += 32768) { s += String.fromCharCode.apply(null, b.subarray(j, j + 32768)); }
		return btoa(s);
	};
})();
