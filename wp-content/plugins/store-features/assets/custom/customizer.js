/**
 * "Design your own" customizer.
 *
 * Draws the customer's text and photo on a photo of the blank, then, on Add to cart, makes the
 * print file (transparent PNG, 300 DPI, tagged with its DPI) and a preview JPEG, uploads both in
 * pieces and adds the item with a token naming them. Settings come from window.sfCustom.
 */
(function ($) {
	'use strict';

	var cfg = window.sfCustom;
	var form = document.querySelector('.single-product .summary form.cart');
	var canvas = document.querySelector('.sf-cz-canvas');
	var panel = document.querySelector('.sf-cz-panel');
	if (!cfg || !form || !canvas || !panel) {
		return;
	}

	var t = cfg.i18n;
	var A = cfg.area; /* x, y: print area corner on the blank photo (px); ppi; w, h in inches. */
	var ctx = canvas.getContext('2d');
	var MAX_FILE = 25 * 1024 * 1024;
	var MAX_PIXELS = 16000000; /* Safe canvas size on phones. */
	var STEP = 0.25; /* Inches per arrow press. */
	var INKS = [
		{ hex: '#FFFFFF', label: 'White' },
		{ hex: '#111111', label: 'Black' },
		{ hex: '#F2E6C9', label: 'Cream' },
		{ hex: '#D9A62E', label: 'Gold' },
		{ hex: '#C8232C', label: 'Red' },
		{ hex: '#1F2A55', label: 'Navy' },
		{ hex: '#F28DB2', label: 'Pink' },
		{ hex: '#8FD3B6', label: 'Mint' }
	];

	var state = {
		colour: cfg.colour,
		active: 'text',
		inkAuto: true,
		busy: false,
		text: { value: '', lines: [], font: 0, ink: '#FFFFFF', size: 2.2, cx: A.w / 2, top: 0.6, w: 0, h: 0, asc: 1, lh: 1.3 },
		photo: null,
		side: 'front',
		sides: { front: null, back: null }
	};
	function newText(ink) {
		return { value: '', lines: [], font: 0, ink: ink || state.text.ink, size: 2.2, cx: A.w / 2, top: 0.6, w: 0, h: 0, asc: 1, lh: 1.3 };
	}

	var el = {
		frame: document.querySelector('.sf-cz-frame'),
		empty: document.querySelector('.sf-cz-empty'),
		tabs: panel.querySelectorAll('[role="tab"]'),
		input: panel.querySelector('.sf-cz-input'),
		fontList: panel.querySelector('.sf-cz-fontlist'),
		inkList: panel.querySelector('.sf-cz-inklist'),
		file: panel.querySelector('.sf-cz-file-input'),
		fileText: panel.querySelector('.sf-cz-file-text'),
		quality: panel.querySelector('.sf-cz-quality'),
		remove: panel.querySelector('.sf-cz-remove'),
		adjust: panel.querySelector('.sf-cz-adjust'),
		size: panel.querySelector('.sf-cz-size'),
		sizeLabel: panel.querySelector('.sf-cz-size-label'),
		printSize: panel.querySelector('.sf-cz-printsize'),
		agree: form.querySelector('.sf-cz-agree-input'),
		error: form.querySelector('.sf-cz-error'),
		button: form.querySelector('.single_add_to_cart_button')
	};

	function fmt(str) {
		var args = Array.prototype.slice.call(arguments, 1);
		var n = 0;
		return str.replace(/%(\d\$)?[sd]/g, function (m, pos) {
			var i = pos ? parseInt(pos, 10) - 1 : n++;
			return args[i];
		}).replace(/%%/g, '%');
	}
	function round2(v) {
		return Math.round(v * 100) / 100;
	}
	function colourInfo() {
		return cfg.colours[state.colour] || { hex: '#1E1E21', dark: true, label: '' };
	}

	/* ------------------------------------------------------------ blank photos */

	var blanks = {};
	function blankFor(slug) {
		if (!blanks[slug]) {
			var img = new Image();
			img.decoding = 'async';
			img.onload = render;
			img.src = cfg.blankUrl + slug + '.webp';
			blanks[slug] = img;
		}
		return blanks[slug];
	}

	/* ------------------------------------------------------------ text layout */

	function fontCss(px) {
		var f = cfg.fonts[state.text.font];
		return f.weight + ' ' + px + 'px "' + f.family + '"';
	}

	/* Measure the text at 100px and turn it into a box in inches. */
	function layoutText() {
		var tx = state.text;
		tx.lines = tx.value.split('\n').map(function (s) {
			return s.trim();
		}).filter(Boolean).slice(0, 3);
		if (!tx.lines.length) {
			tx.w = tx.h = 0;
			return;
		}
		ctx.save();
		ctx.font = fontCss(100);
		ctx.textAlign = 'center';
		var w = 0, asc = 0, desc = 0;
		tx.lines.forEach(function (line) {
			var m = ctx.measureText(line);
			var lw = (m.actualBoundingBoxLeft !== undefined) ? m.actualBoundingBoxLeft + m.actualBoundingBoxRight : m.width;
			w = Math.max(w, lw, m.width);
			asc = Math.max(asc, m.actualBoundingBoxAscent || 80);
			desc = Math.max(desc, m.actualBoundingBoxDescent || 25);
		});
		ctx.restore();
		tx.asc = asc / 100 + 0.04;
		tx.desc = desc / 100 + 0.04;
		tx.lh = Math.max(1.15, tx.asc + tx.desc + 0.08);
		tx.wEm = w / 100 + 0.08;
		tx.hEm = (tx.lines.length - 1) * tx.lh + tx.asc + tx.desc;
		tx.size = Math.min(tx.size, maxTextSize());
		tx.w = tx.wEm * tx.size;
		tx.h = tx.hEm * tx.size;
		clamp(tx);
	}
	function maxTextSize() {
		var tx = state.text;
		if (!tx.wEm) {
			return 4;
		}
		return Math.min(A.w / tx.wEm, A.h / tx.hEm, 6);
	}

	/* ------------------------------------------------------------ layers */

	function layers() {
		var out = [];
		if (state.photo) {
			out.push(state.photo);
		}
		if (state.text.lines.length) {
			out.push(state.text);
		}
		return out;
	}
	function hasDesign() {
		return layers().length > 0;
	}
	function clamp(l) {
		l.w = Math.min(l.w, A.w);
		l.h = Math.min(l.h, A.h);
		l.cx = Math.min(Math.max(l.cx, l.w / 2), A.w - l.w / 2);
		l.top = Math.min(Math.max(l.top, 0), A.h - l.h);
	}
	function activeLayer() {
		if (state.active === 'photo') {
			return state.photo;
		}
		return state.text.lines.length ? state.text : null;
	}

	/* Draw the layers with the print area's top-left at (ox, oy) and k pixels per inch. */
	function drawLayers(c, k, ox, oy) {
		var p = state.photo;
		if (p) {
			c.save();
			c.imageSmoothingEnabled = true;
			c.imageSmoothingQuality = 'high';
			c.drawImage(p.img, ox + (p.cx - p.w / 2) * k, oy + p.top * k, p.w * k, p.h * k);
			c.restore();
		}
		var tx = state.text;
		if (tx.lines.length) {
			c.save();
			c.fillStyle = tx.ink;
			c.textAlign = 'center';
			c.textBaseline = 'alphabetic';
			c.font = fontCss(tx.size * k);
			tx.lines.forEach(function (line, i) {
				c.fillText(line, ox + tx.cx * k, oy + (tx.top + tx.asc * tx.size + i * tx.lh * tx.size) * k);
			});
			c.restore();
		}
	}

	/* ------------------------------------------------------------ preview */

	var pending = false;
	function render() {
		if (pending) {
			return;
		}
		pending = true;
		window.requestAnimationFrame(function () {
			pending = false;
			paint();
		});
	}
	function paint() {
		var info = colourInfo();
		var blank = blankFor(state.colour);
		ctx.clearRect(0, 0, canvas.width, canvas.height);
		drawBlank(ctx, blank, canvas.width, canvas.height, state.side === 'back', '#EDEEF2');
		/* Print area. */
		ctx.save();
		ctx.setLineDash([10, 8]);
		ctx.lineWidth = 2;
		ctx.strokeStyle = info.dark ? 'rgba(255,255,255,.45)' : 'rgba(23,28,58,.35)';
		ctx.strokeRect(A.x, A.y, A.w * A.ppi, A.h * A.ppi);
		ctx.restore();

		ctx.save();
		ctx.globalAlpha = 0.96;
		drawLayers(ctx, A.ppi, A.x, A.y);
		ctx.restore();

		/* Outline the layer being edited. */
		var a = activeLayer();
		if (a && layers().length > 1) {
			ctx.save();
			ctx.lineWidth = 2;
			ctx.strokeStyle = '#C2185B';
			ctx.strokeRect(A.x + (a.cx - a.w / 2) * A.ppi - 4, A.y + a.top * A.ppi - 4, a.w * A.ppi + 8, a.h * A.ppi + 8);
			ctx.restore();
		}

		var any = hasDesign();
		paintMini(any);
		el.empty.hidden = any || anyDesign();
		updateSides();
		canvas.classList.toggle('has-design', any);
		canvas.setAttribute('aria-label', fmt(t.previewOf, (info.label || '').toLowerCase()) +
			(state.text.lines.length ? '. ' + fmt(t.textPreview, state.text.lines.join(' / ')) : '') +
			(state.photo ? '. ' + t.photoLabel : ''));
		updateControls();
	}

	/* The garment photo; the back view is the same photo mirrored, with a label. */
	function drawBlank(c, blank, w, h, back, fill) {
		if (blank.complete && blank.naturalWidth) {
			c.save();
			if (back) {
				c.translate(w, 0);
				c.scale(-1, 1);
			}
			c.drawImage(blank, 0, 0, w, h);
			c.restore();
		} else {
			c.fillStyle = fill;
			c.fillRect(0, 0, w, h);
		}
		if (back) {
			c.save();
			var fs = Math.round(w * 0.034);
			c.font = '800 ' + fs + 'px sans-serif';
			var label = t.backLabel || 'BACK';
			var tw = c.measureText(label).width;
			c.fillStyle = 'rgba(23,23,26,.82)';
			c.fillRect(w * 0.04, w * 0.04, tw + fs * 1.2, fs * 1.8);
			c.fillStyle = '#FFFFFF';
			c.textBaseline = 'middle';
			c.fillText(label, w * 0.04 + fs * 0.6, w * 0.04 + fs * 0.9);
			c.restore();
		}
	}

	/* ------------------------------------------------------------ phone preview */

	/* On phones the controls sit below the preview, so a small copy follows you while you edit. */
	var mini = document.createElement('button');
	mini.type = 'button';
	mini.className = 'sf-cz-mini';
	mini.setAttribute('aria-label', t.showPreview);
	mini.innerHTML = '<canvas width="240" height="300" aria-hidden="true"></canvas>';
	document.body.appendChild(mini);
	var miniCtx = mini.querySelector('canvas').getContext('2d');
	var seen = { stage: true, controls: false };
	function paintMini(any) {
		var show = any && !seen.stage && seen.controls;
		mini.classList.toggle('is-visible', show);
		if (show) {
			miniCtx.drawImage(canvas, 0, 0, 240, 300);
		}
	}
	mini.addEventListener('click', function () {
		canvas.scrollIntoView({ behavior: 'smooth', block: 'center' });
	});
	if ('IntersectionObserver' in window) {
		new IntersectionObserver(function (entries) {
			seen.stage = entries[0].isIntersecting;
			render();
		}, { threshold: 0.35 }).observe(canvas);
		var controls = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				entry.target.sfSeen = entry.isIntersecting;
			});
			seen.controls = !!(panel.sfSeen || form.sfSeen);
			render();
		});
		controls.observe(panel);
		controls.observe(form);
	}

	/* ------------------------------------------------------------ controls */

	function updateControls() {
		var a = activeLayer();
		el.adjust.hidden = !a;
		if (a) {
			if (a === state.text) {
				el.sizeLabel.textContent = t.textSize;
				var max = maxTextSize(), min = Math.min(0.4, max);
				el.size.value = max > min ? Math.round((state.text.size - min) / (max - min) * 100) : 100;
			} else {
				el.sizeLabel.textContent = t.photoSize;
				var pmax = photoMaxW(), pmin = Math.min(1, pmax);
				el.size.value = pmax > pmin ? Math.round((a.w - pmin) / (pmax - pmin) * 100) : 100;
			}
		}
		var box = printBox();
		el.printSize.textContent = box ? fmt(t.printSize, round2(box.w), round2(box.h)) : '';
		updateQuality();
	}

	function photoMaxW() {
		var p = state.photo;
		return p ? Math.min(A.w, A.h * p.nw / p.nh) : A.w;
	}

	function updateQuality() {
		var p = state.photo;
		if (!p) {
			el.quality.textContent = '';
			el.quality.className = 'sf-cz-quality';
			return;
		}
		var dpi = p.nw / p.w;
		var level = dpi >= 200 ? 'good' : (dpi >= 120 ? 'ok' : 'low');
		el.quality.textContent = level === 'good' ? t.qGood : (level === 'ok' ? t.qOk : t.qLow);
		el.quality.className = 'sf-cz-quality is-' + level;
	}

	function selectTab(name, focus) {
		state.active = name;
		el.tabs.forEach(function (tab) {
			var on = tab.getAttribute('data-tab') === name;
			tab.setAttribute('aria-selected', on ? 'true' : 'false');
			tab.tabIndex = on ? 0 : -1;
			document.getElementById(tab.getAttribute('aria-controls')).hidden = !on;
			if (on && focus) {
				tab.focus();
			}
		});
		render();
	}

	el.tabs.forEach(function (tab, i) {
		tab.addEventListener('click', function () {
			selectTab(tab.getAttribute('data-tab'));
		});
		tab.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
				e.preventDefault();
				var next = el.tabs[(i + (e.key === 'ArrowRight' ? 1 : el.tabs.length - 1)) % el.tabs.length];
				selectTab(next.getAttribute('data-tab'), true);
			}
		});
	});

	/* Fonts. */
	cfg.fonts.forEach(function (f, i) {
		var label = document.createElement('label');
		label.className = 'sf-cz-font';
		label.innerHTML = '<input type="radio" name="sf_cz_font"><span class="sf-cz-font__sample" lang="hi"></span><span class="sf-cz-font__name"></span>';
		var input = label.querySelector('input');
		input.value = String(i);
		input.checked = i === 0;
		var sample = label.querySelector('.sf-cz-font__sample');
		sample.textContent = 'नमस्ते Aa';
		sample.style.fontFamily = '"' + f.family + '", sans-serif';
		sample.style.fontWeight = f.weight;
		label.querySelector('.sf-cz-font__name').textContent = f.label;
		input.addEventListener('change', function () {
			state.text.font = i;
			loadFont(i).then(function () {
				layoutText();
				render();
			});
		});
		el.fontList.appendChild(label);
	});

	function loadFont(i) {
		var f = cfg.fonts[i];
		if (!document.fonts || !document.fonts.load) {
			return Promise.resolve();
		}
		return document.fonts.load(f.weight + ' 60px "' + f.family + '"', 'नमस्ते Aa').catch(function () {});
	}

	/* Text colours. */
	INKS.forEach(function (ink) {
		var label = document.createElement('label');
		label.className = 'sf-cz-ink';
		label.title = ink.label;
		label.innerHTML = '<input type="radio" name="sf_cz_ink"><span class="sf-cz-ink__dot" aria-hidden="true"></span><span class="screen-reader-text"></span>';
		var input = label.querySelector('input');
		input.value = ink.hex;
		label.querySelector('.sf-cz-ink__dot').style.background = ink.hex;
		label.querySelector('.screen-reader-text').textContent = ink.label;
		input.addEventListener('change', function () {
			state.text.ink = ink.hex;
			state.inkAuto = false;
			render();
		});
		el.inkList.appendChild(label);
	});
	function syncInk() {
		el.inkList.querySelectorAll('input').forEach(function (input) {
			input.checked = input.value === state.text.ink;
		});
	}
	function autoInk() {
		if (state.inkAuto) {
			state.text.ink = colourInfo().dark ? '#FFFFFF' : '#111111';
			syncInk();
		}
	}

	/* Text input: up to three lines. */
	el.input.addEventListener('input', function () {
		var lines = el.input.value.split('\n');
		if (lines.length > 3) {
			el.input.value = lines.slice(0, 3).join('\n');
		}
		var hadText = state.text.lines.length > 0;
		state.text.value = el.input.value;
		layoutText();
		if (!hadText && state.text.lines.length && state.photo) {
			/* Put new text below the photo when there is room. */
			state.text.top = Math.min(state.photo.top + state.photo.h + 0.4, A.h - state.text.h);
			clamp(state.text);
		}
		state.active = 'text';
		track('design_started', { type: 'text' });
		render();
	});

	/* Photo. */
	el.file.addEventListener('change', function () {
		var file = el.file.files && el.file.files[0];
		el.file.value = '';
		if (!file) {
			return;
		}
		if (file.size > MAX_FILE) {
			showQualityError(t.tooBig);
			return;
		}
		var url = URL.createObjectURL(file);
		var img = new Image();
		img.onload = function () {
			var nw = img.naturalWidth, nh = img.naturalHeight;
			if (!nw || !nh) {
				showQualityError(t.notImage);
				return;
			}
			var p = { img: img, name: file.name, nw: nw, nh: nh, cx: A.w / 2, top: 0.3 };
			p.w = Math.min(A.w * 0.8, A.h * 0.75 * nw / nh);
			p.h = p.w * nh / nw;
			if (state.photo && state.photo.url) {
				URL.revokeObjectURL(state.photo.url);
			}
			p.url = url;
			state.photo = p;
			clamp(p);
			el.remove.hidden = false;
			el.fileText.textContent = t.change;
			selectTab('photo');
			track('design_photo', { dpi: Math.round(nw / p.w) });
		};
		img.onerror = function () {
			URL.revokeObjectURL(url);
			showQualityError(t.notImage);
		};
		img.src = url;
	});
	function showQualityError(msg) {
		el.quality.textContent = msg;
		el.quality.className = 'sf-cz-quality is-low';
	}
	el.remove.addEventListener('click', function () {
		if (state.photo && state.photo.url) {
			URL.revokeObjectURL(state.photo.url);
		}
		state.photo = null;
		el.remove.hidden = true;
		el.fileText.textContent = t.choose;
		el.file.focus();
		render();
	});

	/* Size slider. */
	el.size.addEventListener('input', function () {
		var a = activeLayer();
		if (!a) {
			return;
		}
		var v = parseInt(el.size.value, 10) / 100;
		var cy = a.top + a.h / 2;
		if (a === state.text) {
			var max = maxTextSize(), min = Math.min(0.4, max);
			a.size = min + (max - min) * v;
			a.w = a.wEm * a.size;
			a.h = a.hEm * a.size;
		} else {
			var pmax = photoMaxW(), pmin = Math.min(1, pmax);
			a.w = pmin + (pmax - pmin) * v;
			a.h = a.w * a.nh / a.nw;
		}
		a.top = cy - a.h / 2;
		clamp(a);
		render();
	});

	/* Move buttons. */
	el.adjust.querySelectorAll('[data-move]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var a = activeLayer();
			if (!a) {
				return;
			}
			switch (btn.getAttribute('data-move')) {
				case 'up': a.top -= STEP; break;
				case 'down': a.top += STEP; break;
				case 'left': a.cx -= STEP; break;
				case 'right': a.cx += STEP; break;
				default: a.cx = A.w / 2;
			}
			clamp(a);
			render();
		});
	});

	/* Drag on the preview. */
	var drag = null;
	function toInches(e) {
		var r = canvas.getBoundingClientRect();
		var px = (e.clientX - r.left) * canvas.width / r.width;
		var py = (e.clientY - r.top) * canvas.height / r.height;
		return { x: (px - A.x) / A.ppi, y: (py - A.y) / A.ppi };
	}
	function hit(l, pt) {
		var pad = 0.3;
		return l && pt.x >= l.cx - l.w / 2 - pad && pt.x <= l.cx + l.w / 2 + pad && pt.y >= l.top - pad && pt.y <= l.top + l.h + pad;
	}
	canvas.addEventListener('pointerdown', function (e) {
		if (state.busy) {
			return;
		}
		var pt = toInches(e);
		var target = null;
		if (state.text.lines.length && hit(state.text, pt)) {
			target = 'text';
		} else if (state.photo && hit(state.photo, pt)) {
			target = 'photo';
		}
		if (!target) {
			return;
		}
		e.preventDefault();
		if (state.active !== target) {
			selectTab(target);
		}
		var l = activeLayer();
		drag = { id: e.pointerId, dx: pt.x - l.cx, dy: pt.y - l.top };
		canvas.setPointerCapture(e.pointerId);
		canvas.classList.add('is-dragging');
	});
	canvas.addEventListener('pointermove', function (e) {
		if (!drag || e.pointerId !== drag.id) {
			return;
		}
		var l = activeLayer();
		var pt = toInches(e);
		l.cx = pt.x - drag.dx;
		l.top = pt.y - drag.dy;
		if (Math.abs(l.cx - A.w / 2) < 0.25) {
			l.cx = A.w / 2; /* Snap to the centre line. */
		}
		clamp(l);
		render();
	});
	function endDrag() {
		drag = null;
		canvas.classList.remove('is-dragging');
	}
	canvas.addEventListener('pointerup', endDrag);
	canvas.addEventListener('pointercancel', endDrag);

	/* Garment colour from the variation form. */
	var $form = $(form);
	function readColour() {
		var select = form.querySelector('select[name="attribute_pa_color"]');
		if (select && select.value && cfg.colours[select.value]) {
			state.colour = select.value;
			blankFor(state.colour);
			autoInk();
			refreshArt();
			render();
		}
	}
	$form.on('change', 'select[name="attribute_pa_color"]', readColour);
	$form.on('woocommerce_variation_has_changed reset_data', readColour);

	/* ------------------------------------------------------------ print file */

	/* The area that will be printed: centred, from the top of the print area down. */
	function printBox() {
		var ls = layers();
		if (!ls.length) {
			return null;
		}
		var half = 0, bottom = 0, mid = A.w / 2;
		ls.forEach(function (l) {
			half = Math.max(half, Math.abs(l.cx - l.w / 2 - mid), Math.abs(l.cx + l.w / 2 - mid));
			bottom = Math.max(bottom, l.top + l.h);
		});
		half = Math.min(half, mid);
		return { half: half, w: 2 * half, h: Math.min(bottom, A.h) };
	}

	function canvasBlob(c, type, quality) {
		return new Promise(function (resolve, reject) {
			c.toBlob(function (b) {
				if (b) {
					resolve(b);
				} else {
					reject(new Error(t.failed));
				}
			}, type, quality);
		});
	}

	var CRC_TABLE = (function () {
		var table = new Uint32Array(256);
		for (var n = 0; n < 256; n++) {
			var c = n;
			for (var k = 0; k < 8; k++) {
				c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
			}
			table[n] = c >>> 0;
		}
		return table;
	})();
	function crc32(bytes) {
		var c = 0xFFFFFFFF;
		for (var i = 0; i < bytes.length; i++) {
			c = CRC_TABLE[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8);
		}
		return (c ^ 0xFFFFFFFF) >>> 0;
	}

	/* Write the DPI into the PNG (pHYs chunk), so the printer reads the right size. */
	function withDpi(buffer, dpi) {
		var src = new Uint8Array(buffer);
		var view = new DataView(buffer);
		var ppm = Math.round(dpi / 0.0254);
		var chunk = new Uint8Array(21);
		var cv = new DataView(chunk.buffer);
		cv.setUint32(0, 9);
		chunk.set([0x70, 0x48, 0x59, 0x73], 4); /* "pHYs" */
		cv.setUint32(8, ppm);
		cv.setUint32(12, ppm);
		chunk[16] = 1;
		cv.setUint32(17, crc32(chunk.subarray(4, 17)));

		/* Drop an existing pHYs, then put ours right after IHDR. */
		var parts = [src.subarray(0, 8)];
		var pos = 8;
		while (pos < src.length) {
			var len = view.getUint32(pos);
			var type = String.fromCharCode(src[pos + 4], src[pos + 5], src[pos + 6], src[pos + 7]);
			var end = pos + 12 + len;
			if (type !== 'pHYs') {
				parts.push(src.subarray(pos, end));
			}
			if (type === 'IHDR') {
				parts.push(chunk);
			}
			pos = end;
		}
		return new Blob(parts, { type: 'image/png' });
	}

	function makePrintFile() {
		var box = printBox();
		var dpi = 300;
		if (box.w * box.h * dpi * dpi > MAX_PIXELS) {
			dpi = Math.floor(Math.sqrt(MAX_PIXELS / (box.w * box.h)));
		}
		var c = document.createElement('canvas');
		c.width = Math.max(1, Math.round(box.w * dpi));
		c.height = Math.max(1, Math.round(box.h * dpi));
		drawLayers(c.getContext('2d'), dpi, -(A.w / 2 - box.half) * dpi, 0);
		return canvasBlob(c, 'image/png').then(function (blob) {
			return blob.arrayBuffer ? blob.arrayBuffer() : new Response(blob).arrayBuffer();
		}).then(function (buf) {
			c.width = c.height = 0; /* Free the memory. */
			return { blob: withDpi(buf, dpi), w: round2(box.w), h: round2(box.h) };
		});
	}

	function makeMockup() {
		var scale = 0.8;
		var c = document.createElement('canvas');
		c.width = canvas.width * scale;
		c.height = canvas.height * scale;
		var x = c.getContext('2d');
		var blank = blankFor(state.colour);
		drawBlank(x, blank, c.width, c.height, state.side === 'back', colourInfo().hex);
		x.globalAlpha = 0.96;
		drawLayers(x, A.ppi * scale, A.x * scale, A.y * scale);
		return canvasBlob(c, 'image/jpeg', 0.86);
	}

	function newToken() {
		var bytes = new Uint8Array(16);
		window.crypto.getRandomValues(bytes);
		return Array.prototype.map.call(bytes, function (b) {
			return ('0' + b.toString(16)).slice(-2);
		}).join('');
	}

	function upload(token, kind, blob, progress) {
		var size = cfg.chunk;
		var total = Math.max(1, Math.ceil(blob.size / size));
		var index = 0;
		function send(retry) {
			var fd = new FormData();
			fd.append('action', 'sf_custom_upload');
			fd.append('nonce', cfg.nonce);
			fd.append('token', token);
			fd.append('kind', kind);
			fd.append('index', String(index));
			fd.append('total', String(total));
			fd.append('chunk', blob.slice(index * size, (index + 1) * size), kind + '.part');
			return fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) {
					return r.json().catch(function () {
						return { success: false };
					});
				}, function (err) {
					if (retry) {
						return send(false).then(function () {
							return null;
						});
					}
					throw err;
				})
				.then(function (res) {
					if (res === null) {
						return null; /* Handled by the retry. */
					}
					if (!res || !res.success) {
						throw new Error(res && res.data && res.data.message ? res.data.message : t.failed);
					}
					index++;
					if (progress) {
						progress(index / total);
					}
					return index < total ? send(true) : res.data;
				});
		}
		return send(true);
	}

	function summary() {
		var out = [];
		['front', 'back'].forEach(function (name) {
			useSide(name, function () {
				var s = sideSummary();
				if (s) {
					out.push((name === 'front' ? 'Front: ' : 'Back: ') + s);
				}
			});
		});
		return out.join(' | ');
	}
	function sideSummary() {
		var parts = [];
		if (state.text.lines.length) {
			var ink = INKS.filter(function (i) {
				return i.hex === state.text.ink;
			})[0];
			parts.push('Text "' + state.text.lines.join(' / ') + '" (' + cfg.fonts[state.text.font].label + ', ' + (ink ? ink.label.toLowerCase() : state.text.ink) + ')');
		}
		if (state.photo) {
			parts.push('Photo ' + state.photo.name.slice(0, 60) + ' (' + state.photo.nw + '×' + state.photo.nh + ' px)');
		}
		return parts.join('; ');
	}

	/* ------------------------------------------------------------ add to cart */

	function showError(msg) {
		el.error.textContent = msg;
		el.error.hidden = !msg;
	}
	var buttonText = el.button ? el.button.textContent : '';
	function setBusy(on, label) {
		state.busy = on;
		if (!el.button) {
			return;
		}
		el.button.classList.toggle('loading', on);
		el.button.disabled = on;
		el.button.setAttribute('aria-busy', on ? 'true' : 'false');
		el.button.textContent = on ? label : buttonText;
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		e.stopPropagation();
		if (state.busy) {
			return;
		}
		showError('');
		if (!anyDesign()) {
			showError(t.needDesign);
			panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
			return;
		}
		var variation = form.querySelector('input[name="variation_id"]');
		var variationId = variation ? parseInt(variation.value, 10) : 0;
		if (!variationId) {
			showError(t.needOptions);
			return;
		}
		if (!el.agree || !el.agree.checked) {
			showError(t.needAgree);
			if (el.agree) {
				el.agree.focus();
			}
			return;
		}
		var qtyField = form.querySelector('input.qty');
		var token = newToken();
		var dims = { front: { w: 0, h: 0 }, back: { w: 0, h: 0 } };
		var jobs = [];
		['front', 'back'].forEach(function (name) {
			useSide(name, function () {
				if (hasDesign()) {
					/* Drawing happens now, while this side is loaded; the files finish later. */
					jobs.push({ side: name, print: makePrintFile(), mockup: makeMockup() });
				}
			});
		});
		var finalLabel = summary();
		setBusy(true, t.preparing);
		var chain = Promise.resolve();
		jobs.forEach(function (job, i) {
			var suffix = job.side === 'back' ? '_back' : '';
			chain = chain.then(function () {
				return job.print;
			}).then(function (file) {
				dims[job.side] = { w: file.w, h: file.h };
				return upload(token, 'print' + suffix, file.blob, function (f) {
					setBusy(true, fmt(t.uploading, Math.round((i + f) / jobs.length * 95)));
				});
			}).then(function () {
				return job.mockup;
			}).then(function (mockup) {
				return upload(token, 'mockup' + suffix, mockup);
			});
		});
		chain.then(function () {
			return $.post(cfg.addToCartUrl, {
				product_id: variationId,
				quantity: qtyField ? qtyField.value : 1,
				sf_token: token,
				sf_w: dims.front.w,
				sf_h: dims.front.h,
				sf_bw: dims.back.w,
				sf_bh: dims.back.h,
				sf_summary: finalLabel,
				sf_agree: 1
			});
		}).then(function (res) {
			setBusy(false);
			if (!res || res.error) {
				showError(t.cartFailed);
				return;
			}
			$(document.body).trigger('added_to_cart', [res.fragments, res.cart_hash, $(el.button)]);
		}, function (err) {
			setBusy(false);
			showError(err && err.message ? err.message : t.failed);
		});
	}, true);

	/* ------------------------------------------------------------ front and back */

	function saveSide() {
		state.sides[state.side] = { text: state.text, photo: state.photo };
	}
	function loadSide(name) {
		var d = state.sides[name] || { text: newText(), photo: null };
		state.text = d.text;
		state.photo = d.photo;
		state.side = name;
	}
	/* Run fn with another side loaded, then come back (fn must draw synchronously). */
	function useSide(name, fn) {
		var was = state.side;
		saveSide();
		loadSide(name);
		try {
			fn();
		} finally {
			saveSide();
			loadSide(was);
		}
	}
	function sideHas(name) {
		if (name === state.side) {
			return hasDesign();
		}
		var d = state.sides[name];
		return !!(d && (d.photo || (d.text && d.text.lines.length)));
	}
	function anyDesign() {
		return sideHas('front') || sideHas('back');
	}
	function syncInputs() {
		el.input.value = state.text.value;
		el.remove.hidden = !state.photo;
		el.fileText.textContent = state.photo ? t.change : t.choose;
		el.fontList.querySelectorAll('input').forEach(function (input) {
			input.checked = parseInt(input.value, 10) === state.text.font;
		});
		syncInk();
	}
	function switchSide(name) {
		if (name === state.side || state.busy) {
			return;
		}
		saveSide();
		loadSide(name);
		syncInputs();
		layoutText();
		state.active = state.text.lines.length || !state.photo ? 'text' : 'photo';
		if (state.active === 'photo') {
			selectTab('photo');
		}
		render();
	}
	var sideBox = document.createElement('div');
	sideBox.className = 'sf-cz-sides';
	sideBox.setAttribute('role', 'group');
	sideBox.setAttribute('aria-label', t.sidesLabel || 'Print side');
	['front', 'back'].forEach(function (name) {
		var b = document.createElement('button');
		b.type = 'button';
		b.setAttribute('data-side', name);
		b.innerHTML = '<span class="sf-cz-side__name"></span><span class="sf-cz-side__note"></span>';
		b.querySelector('.sf-cz-side__name').textContent = name === 'front' ? (t.front || 'Front') : (t.back || 'Back');
		b.querySelector('.sf-cz-side__note').textContent = name === 'back' && cfg.backPrice ? fmt(t.backPrice || '+₹%s', cfg.backPrice) : '';
		b.addEventListener('click', function () {
			switchSide(name);
		});
		sideBox.appendChild(b);
	});
	var titleEl = panel.querySelector('.sf-cz-title');
	titleEl.parentNode.insertBefore(sideBox, titleEl.nextSibling);
	function updateSides() {
		sideBox.querySelectorAll('button').forEach(function (b) {
			var name = b.getAttribute('data-side');
			b.setAttribute('aria-pressed', name === state.side ? 'true' : 'false');
			b.classList.toggle('has-design', sideHas(name));
		});
	}

	/* ------------------------------------------------------------ ideas */

	var ideasBox = panel.querySelector('.sf-cz-ideas');
	if (ideasBox && cfg.ideas) {
		(cfg.ideas.text || []).forEach(function (idea) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'sf-cz-idea sf-cz-idea--text';
			b.lang = 'hi';
			b.textContent = idea.text.replace(/\n/g, ' ');
			var f = cfg.fonts[idea.font || 0];
			b.style.fontFamily = '"' + f.family + '", sans-serif';
			b.style.fontWeight = f.weight;
			b.addEventListener('click', function () {
				state.text.font = idea.font || 0;
				loadFont(state.text.font).then(function () {
					syncInputs();
					el.input.value = idea.text;
					el.input.dispatchEvent(new Event('input'));
					selectTab('text');
					el.input.focus();
				});
			});
			ideasBox.querySelector('.sf-cz-ideas__text').appendChild(b);
		});
		(cfg.ideas.art || []).forEach(function (art) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'sf-cz-idea sf-cz-idea--art';
			b.setAttribute('aria-label', art.label);
			b.title = art.label;
			b.innerHTML = '<img alt="" loading="lazy">';
			b.querySelector('img').src = cfg.artUrl + 'art-' + art.key + '-dark.png';
			b.addEventListener('click', function () {
				var variant = colourInfo().dark ? 'dark' : 'light';
				var img = new Image();
				img.onload = function () {
					var nw = img.naturalWidth, nh = img.naturalHeight;
					var p = { img: img, name: art.label + ' (ready art)', nw: nw, nh: nh, cx: A.w / 2, top: 0.3, art: art.key };
					p.w = Math.min(A.w * 0.55, A.h * 0.55 * nw / nh);
					p.h = p.w * nh / nw;
					state.photo = p;
					clamp(p);
					el.remove.hidden = false;
					el.fileText.textContent = t.change;
					selectTab('photo');
				};
				img.src = cfg.artUrl + 'art-' + art.key + '-' + variant + '.png';
			});
			ideasBox.querySelector('.sf-cz-ideas__art').appendChild(b);
		});
	}
	/* Ready art follows the garment colour (light art on dark cloth and the other way). */
	function refreshArt() {
		['front', 'back'].forEach(function (name) {
			var d = name === state.side ? { photo: state.photo } : state.sides[name];
			var p = d && d.photo;
			if (p && p.art) {
				var want = cfg.artUrl + 'art-' + p.art + '-' + (colourInfo().dark ? 'dark' : 'light') + '.png';
				if (p.img.src.indexOf(want) === -1) {
					var img = new Image();
					img.onload = function () {
						p.img = img;
						render();
					};
					img.src = want;
				}
			}
		});
	}

	/* ------------------------------------------------------------ tracking */

	var tracked = {};
	function track(name, params) {
		if (!tracked[name] && window.sfTrack) {
			tracked[name] = true;
			window.sfTrack(name, params || {});
		}
	}

	/* ------------------------------------------------------------ steps */

	/* Number the three choices, so the order is obvious: design, colour, size. */
	(function () {
		var steps = [
			panel.querySelector('.sf-cz-title'),
			form.querySelector('label[for="pa_color"]'),
			form.querySelector('label[for="pa_size"]')
		];
		steps.forEach(function (heading, i) {
			if (heading && !heading.querySelector('.sf-cz-step')) {
				var num = document.createElement('span');
				num.className = 'sf-cz-step';
				num.textContent = String(i + 1);
				num.setAttribute('aria-hidden', 'true');
				heading.insertBefore(num, heading.firstChild);
			}
		});
	})();

	/* ------------------------------------------------------------ start */

	syncInk();
	readColour();
	autoInk();
	blankFor(state.colour);
	loadFont(0).then(function () {
		layoutText();
		render();
	});
	if (document.fonts && document.fonts.ready) {
		document.fonts.ready.then(function () {
			layoutText();
			render();
		});
	}
	render();
})(jQuery);
