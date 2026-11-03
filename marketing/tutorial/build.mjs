// Builds the "how to order on Panuwear" tutorial (1080x1920, Hindi voice) from the screenshots
// made by capture.mjs.  node build.mjs <shots dir> <out dir> [--frames] [--novoice]
//   --frames   only draw a few check frames per scene (JPEG), no video
//   --novoice  estimate timings without text-to-speech (for local checks)
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const SHOTS = path.resolve(process.argv[2] || 'shots');
const OUT = path.resolve(process.argv[3] || 'out');
const FRAMES = process.argv.includes('--frames');
const NOVOICE = process.argv.includes('--novoice');
fs.mkdirSync(OUT, { recursive: true });
const meta = JSON.parse(fs.readFileSync(path.join(SHOTS, 'shots.json'), 'utf8'));
const sh = (cmd, args) => execFileSync(cmd, args, { stdio: ['ignore', 'pipe', 'inherit'] }).toString().trim();

// The script. Times in shots are fractions of the voice (0 = voice starts, 1 = voice ends).
const S = [
	{ kind: 'intro', title: 'Panuwear पर order कैसे करें?', sub: 'पूरा तरीका, बस 2 मिनट में',
		voice: 'नमस्ते! Panuwear पर order करना बहुत आसान है। आइए, दो मिनट में पूरा तरीका देखते हैं।' },
	{ kind: 'browser', title: 'Website खोलें', sub: 'Chrome या Google में लिखें: panuwear.com', typeAt: .3,
		shots: [{ name: 'home', at: .92 }],
		voice: 'सबसे पहले अपने फ़ोन में Chrome या Google खोलिए, और लिखिए, panuwear dot com.' },
	{ title: 'होम पेज', sub: 'ऊपर categories, नीचे नए designs',
		shots: [{ name: 'home_tall', at: 0, scrollTo: 1250, scrollFrom: .1, scrollEnd: .95 }],
		voice: 'होम पेज पर सबसे ऊपर सारी categories हैं, जैसे oversized tees, hoodies और women\'s fits. नीचे हमारे नए designs हैं।' },
	{ title: 'Design चुनें', sub: 'Category खोलें और पसंद का design tap करें',
		shots: [{ name: 'category_tall', at: 0, tapKey: 'category_tall', scrollFrom: .05, scrollEnd: .55, tapAt: .8 }],
		voice: 'अपनी पसंद की category खोलिए, और जो design अच्छा लगे, उस पर tap कीजिए।' },
	{ title: 'अपना रंग चुनें', sub: 'Photo भी उसी रंग की दिखेगी',
		shots: [{ name: 'product_colour_before', at: 0, tapAt: .45 }, { name: 'product_colour_after', at: .58 }],
		voice: 'Product page पर अपना पसंदीदा रंग चुनिए। Photo भी उसी रंग की हो जाएगी, आगे और पीछे दोनों।' },
	{ title: 'Size चुनें', sub: 'Size guide से सही नाप देख लें',
		shots: [{ name: 'product_size_before', at: 0, tapAt: .45 }],
		voice: 'अब अपना size चुनिए। सही नाप के लिए size guide भी देख सकते हैं।' },
	{ title: 'Add to cart', sub: 'Product cart में आ जाएगा',
		shots: [{ name: 'product_add', at: 0, tapAt: .3 }, { name: 'product_added', at: .5 }],
		voice: 'Add to cart दबाइए। आपका product cart में आ गया।' },
	{ title: 'Cart देखें', sub: 'पूरे भारत में delivery FREE',
		shots: [{ name: 'cart', at: 0 }, { name: 'cart_checkout', at: .5, tapAt: .82 }],
		voice: 'Cart में quantity बदल सकते हैं। Delivery पूरे भारत में बिल्कुल free है। अब checkout दबाइए।' },
	{ title: 'अपनी details भरें', sub: 'Email, नाम, मोबाइल और पूरा पता',
		shots: [{ name: 'checkout_contact', at: 0, tapAt: .22 }, { name: 'checkout_address', at: .45, tapAt: .72 }],
		voice: 'अब अपना email, नाम, मोबाइल नंबर और पूरा पता भरिए, ताकि courier आप तक पहुँच सके।' },
	{ title: 'Payment चुनें', sub: 'Online पर ₹20 off · या Cash on Delivery',
		shots: [{ name: 'checkout_payment', at: 0 }, { name: 'checkout_cod', at: .55, tapAt: .68 }],
		voice: 'अब payment चुनिए। UPI या card से online देने पर बीस रुपये की छूट मिलती है। या cash on delivery चुनिए, और सामान मिलने पर पैसे दीजिए।' },
	{ kind: 'confirm', title: 'Place Order', sub: 'Order हो गया, confirmation email पर',
		shots: [{ name: 'checkout_place', at: 0, tapAt: .25 }], confirmAt: .38,
		voice: 'Place order दबाते ही आपका order हो गया। Confirmation आपके email पर आ जाएगा।' },
	{ title: 'Order track करें', sub: 'Order number और मोबाइल नंबर डालें',
		shots: [{ name: 'track', at: 0, tapAt: .55 }],
		voice: 'Order कहाँ तक पहुँचा, ये देखने के लिए website के नीचे track your order खोलिए, और order number व मोबाइल नंबर डालिए।' },
	{ kind: 'timeline', title: 'हर step की खबर', sub: 'Ship होते ही tracking link email पर',
		voice: 'यहाँ आपको हर step दिखेगा। Printing, shipped और delivered. Ship होते ही courier का tracking link भी email पर आ जाता है।' },
	{ title: 'अपना design बनाएँ', sub: 'Design your own: नाम या कोई भी line',
		shots: [{ name: 'design', at: 0 }, { name: 'design_text', at: .3, tapAt: .45 }, { name: 'design_preview', at: .78 }],
		voice: 'अपना design बनाना है? Design your own खोलिए, और अपना नाम या कोई भी line लिखिए। Preview तुरंत दिखेगा।' },
	{ title: 'Photo या logo भी', sub: 'छोटा-बड़ा करें, फिर Add to cart',
		shots: [{ name: 'design_photo_tab', at: 0, tapAt: .3 }],
		voice: 'चाहें तो अपनी photo या logo भी डाल सकते हैं। Design को छोटा बड़ा कीजिए, और add to cart कीजिए। बाकी तरीका वही है।' },
	{ kind: 'outro',
		voice: 'Free delivery, cash on delivery, और कोई भी सवाल हो तो WhatsApp पर पूछिए। Panuwear dot com. कपड़े नहीं, अपनी vibe पहनो।' },
];

const LEAD = .45; // seconds of picture before the voice starts
const steps = S.filter((s) => !['intro', 'outro'].includes(s.kind)).length;
let stepNo = 0;

const dataUrl = (f) => 'data:image/jpeg;base64,' + fs.readFileSync(f).toString('base64');

function voiceFor(i, text) {
	const mp3 = path.join(OUT, `v${String(i).padStart(2, '0')}.mp3`);
	if (NOVOICE) { return { mp3: null, dur: Math.max(2.5, text.length * .062) }; }
	const voices = ['hi-IN-SwaraNeural'];
	let ok = false;
	for (let k = 0; k < 3 && !ok; k++) {
		try { sh('edge-tts', ['--voice', voices[0], '--rate', '+3%', '--text', text, '--write-media', mp3]); ok = true; } catch (e) { console.log('edge-tts retry', k); }
	}
	if (!ok) {
		console.log('edge-tts failed, using gTTS');
		sh('gtts-cli', ['--lang', 'hi', '--output', mp3, text]);
	}
	const dur = Number(sh('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', mp3]));
	return { mp3, dur };
}

const browser = await chromium.launch();
const page = await browser.newPage();
await page.route('https://tut.panuwear.local/**', (r) => r.fulfill({ contentType: 'text/html', body: '<!doctype html><title>t</title>' }));
await page.goto('https://tut.panuwear.local/');
await page.setContent(`<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@500;600;700;800&display=swap">
</head><body style="font-family:'Baloo 2'">Panuwear ऑर्डर</body></html>`, { waitUntil: 'networkidle' }).catch(() => {});
await page.addScriptTag({ path: 'node_modules/mp4-muxer/build/mp4-muxer.js' });
await page.addScriptTag({ path: path.resolve(path.dirname(new URL(import.meta.url).pathname), 'tutorial.js') });

const parts = [];
for (const [i, s] of S.entries()) {
	const v = voiceFor(i, s.voice);
	const span = v.dur;
	const duration = Math.round((LEAD + span + (s.kind === 'outro' ? 1.6 : 0.9)) * 30) / 30;
	const T = (f) => LEAD + f * span;
	const sp = { kind: s.kind || 'screens', title: s.title, sub: s.sub, duration };
	if (!['intro', 'outro'].includes(s.kind)) { sp.step = ++stepNo; sp.total = steps; }
	if (s.typeAt !== undefined) { sp.typeAt = T(s.typeAt); }
	if (s.confirmAt !== undefined) { sp.confirmAt = T(s.confirmAt); }
	sp.shots = (s.shots || []).map((x) => {
		const m = meta[x.name] || {};
		const o = { name: x.name, src: dataUrl(path.join(SHOTS, x.name + '.jpg')), at: T(x.at) };
		if (x.at === 0) { o.at = 0; }
		if (m.tap && x.tapAt !== undefined) { o.tap = m.tap; o.tapAt = T(x.tapAt); }
		if (x.scrollTo) { o.scrollTo = x.scrollTo; }
		if (x.tapKey && m.tap) { o.scrollTo = Math.max(0, m.tap.y - 330); }
		if (x.scrollFrom !== undefined) { o.scrollFrom = T(x.scrollFrom); o.scrollEnd = T(x.scrollEnd); }
		return o;
	});
	if (s.kind === 'timeline') { sp.duration = Math.max(duration, 7.2); }
	console.log(`scene ${i} ${s.kind || 'screens'} ${sp.duration.toFixed(2)}s`);
	if (FRAMES) {
		const ts = [sp.duration * .2, sp.duration * .55, sp.duration * .85];
		for (const [k, t] of ts.entries()) {
			const b64 = await page.evaluate(([a, b]) => window.drawTutorialFrame(a, b), [sp, t]);
			fs.writeFileSync(path.join(OUT, `f${String(i).padStart(2, '0')}-${k}.jpg`), Buffer.from(b64, 'base64'));
		}
		continue;
	}
	const b64 = await page.evaluate((a) => window.renderTutorialScene(a), sp);
	const raw = path.join(OUT, `s${String(i).padStart(2, '0')}-raw.mp4`);
	fs.writeFileSync(raw, Buffer.from(b64, 'base64'));
	// Same encoder settings for every scene, so the parts join without re-encoding.
	const tmp = path.join(OUT, `s${String(i).padStart(2, '0')}.part.mp4`), fin = tmp.replace('.part', '');
	const aIn = v.mp3 ? ['-i', v.mp3] : ['-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=stereo'];
	execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-i', raw, ...aIn,
		'-filter_complex', `[1:a]adelay=${Math.round(LEAD * 1000)}|${Math.round(LEAD * 1000)},apad,volume=1.5,aresample=44100[a]`,
		'-map', '0:v', '-map', '[a]', '-c:v', 'libx264', '-preset', 'medium', '-crf', '20', '-pix_fmt', 'yuv420p', '-profile:v', 'high', '-r', '30',
		'-c:a', 'aac', '-b:a', '160k', '-ar', '44100', '-ac', '2', '-t', String(sp.duration), tmp], { stdio: 'inherit' });
	fs.renameSync(tmp, fin);
	fs.rmSync(raw);
	parts.push(fin);
}
await browser.close();

if (!FRAMES) {
	const list = path.join(OUT, 'parts.txt');
	fs.writeFileSync(list, parts.map((p) => `file '${p}'`).join('\n'));
	const final = path.join(OUT, 'panuwear-how-to-order.mp4');
	execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', list, '-c', 'copy', '-movflags', '+faststart', final], { stdio: 'inherit' });
	for (const p of parts) { fs.rmSync(p); }
	fs.rmSync(list);
	const dur = sh('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', final]);
	console.log(`video ${final} ${Number(dur).toFixed(1)}s ${(fs.statSync(final).size / 1048576).toFixed(1)} MB`);
	// Cover: the intro card.
	execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-ss', '2.5', '-i', final, '-frames:v', '1', '-q:v', '3', path.join(OUT, 'cover.jpg')]);
}
