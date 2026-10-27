// Builds the reels for the posts in marketing/queue/*.json that don't have a video yet:
// product photos from the live store + Hindi voice (edge-tts) -> MP4 (H.264 + AAC, 1080x1920),
// uploads video and cover to wp-content/uploads/sf-social/ over FTP, and writes the posting
// manifest wp-content/plugins/store-features/social/queue.json that the site posts from.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(process.cwd(), '../..');
const QUEUE_DIR = path.join(ROOT, 'marketing/queue');
const MANIFEST = path.join(ROOT, 'wp-content/plugins/store-features/social/queue.json');
const SITE = 'https://panuwear.com';
const OUT = path.join(process.cwd(), 'out');
const FORCE = (process.env.FORCE || '').split(/[ ,]+/).filter(Boolean);
fs.mkdirSync(OUT, { recursive: true });
fs.mkdirSync(path.dirname(MANIFEST), { recursive: true });

const manifest = fs.existsSync(MANIFEST) ? JSON.parse(fs.readFileSync(MANIFEST, 'utf8')) : { posts: [] };
const known = new Map(manifest.posts.map((p) => [p.id, p]));

const items = fs.readdirSync(QUEUE_DIR).filter((f) => f.endsWith('.json')).sort()
	.map((f) => ({ file: f, ...JSON.parse(fs.readFileSync(path.join(QUEUE_DIR, f), 'utf8')) }));

const todo = items.filter((it) => !known.has(it.id) || !known.get(it.id).video_url || FORCE.includes(it.id) || FORCE.includes('all'));
console.log(`${items.length} queued, ${todo.length} to render`);

const ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
async function getJson(url) {
	const r = await fetch(url, { headers: { 'User-Agent': ua, Accept: 'application/json' } });
	if (!r.ok) throw new Error(`${url} -> ${r.status}`);
	return r.json();
}
async function dataUrl(url) {
	const r = await fetch(url, { headers: { 'User-Agent': ua, Accept: 'image/webp,image/*' } });
	if (!r.ok) throw new Error(`${url} -> ${r.status}`);
	const type = r.headers.get('content-type') || 'image/jpeg';
	if (!type.startsWith('image/')) throw new Error(`${url} is ${type}`);
	return `data:${type};base64,${Buffer.from(await r.arrayBuffer()).toString('base64')}`;
}
function ftpUpload(local, remote) {
	const { FTP_SERVER, FTP_USERNAME, FTP_PASSWORD, FTP_PORT = '21', FTP_BASE_DIR = '' } = process.env;
	if (!FTP_SERVER) throw new Error('FTP secrets missing');
	execFileSync('curl', ['-sS', '--ssl', '-k', '--ftp-create-dirs', '-T', local, '--user', `${FTP_USERNAME}:${FTP_PASSWORD}`,
		`ftp://${FTP_SERVER}:${FTP_PORT}/${FTP_BASE_DIR}${remote}`], { stdio: 'inherit' });
}
const sh = (cmd, args) => execFileSync(cmd, args, { stdio: ['ignore', 'pipe', 'inherit'] }).toString().trim();

const browser = todo.length ? await chromium.launch() : null;
let page;
if (browser) {
	page = await browser.newPage();
	await page.setContent(`<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Rozha+One&family=Baloo+2:wght@500;700;800&family=Kalam:wght@700&display=swap">
</head><body style="font-family:'Baloo 2'">Panuwear चल</body></html>`, { waitUntil: 'networkidle' });
	await page.addScriptTag({ path: 'node_modules/mp4-muxer/build/mp4-muxer.js' });
	await page.addScriptTag({ path: 'reel.js' });
}

for (const [n, it] of todo.entries()) {
	console.log(`\n== ${it.id} (${it.product})`);
	const prod = (await getJson(`${SITE}/wp-json/wc/store/v1/products?slug=${encodeURIComponent(it.product)}`))[0];
	if (!prod) { console.log('product not found, skipped'); continue; }
	const minor = prod.prices.currency_minor_unit || 0;
	const price = `₹${Math.round(Number(prod.prices.price) / 10 ** minor)}`;
	const images = [];
	for (const im of prod.images.slice(0, it.images || 6)) {
		try { images.push(await dataUrl(im.src)); } catch (e) { console.log('image skipped', e.message); }
	}
	if (!images.length) { console.log('no images, skipped'); continue; }

	// Voice
	const voice = path.join(OUT, `${it.id}.mp3`);
	try {
		sh('edge-tts', ['--voice', it.voice_name || 'hi-IN-MadhurNeural', '--rate', it.voice_rate || '+6%', '--text', it.voice, '--write-media', voice]);
	} catch (e) {
		// Fallback voice (Google), a little slower to read but always available.
		console.log('edge-tts failed, using gTTS');
		sh('gtts-cli', ['--lang', 'hi', '--output', voice, it.voice]);
		const fast = voice.replace('.mp3', '-fast.mp3');
		sh('ffmpeg', ['-y', '-loglevel', 'error', '-i', voice, '-filter:a', 'atempo=1.15', fast]);
		fs.renameSync(fast, voice);
	}
	const vdur = Number(sh('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', voice]));
	const duration = Math.min(30, Math.max(13, Math.ceil((vdur + 2.8) * 10) / 10));
	console.log(`voice ${vdur.toFixed(1)}s -> video ${duration}s, ${images.length} photos, ${price}`);

	const res = await page.evaluate((d) => window.renderReel(d), {
		duration, images, theme: it.theme ?? n, title: it.title || prod.name, price,
		hook: it.hook, line: it.line, sub: it.sub, middle: it.middle, fabric: it.fabric,
	});
	const raw = path.join(OUT, `${it.id}-raw.mp4`);
	const cover = path.join(OUT, `${it.id}.jpg`);
	const final = path.join(OUT, `${it.id}.mp4`);
	fs.writeFileSync(raw, Buffer.from(res.video, 'base64'));
	fs.writeFileSync(cover, Buffer.from(res.cover, 'base64'));
	execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-i', raw, '-i', voice,
		'-filter_complex', '[1:a]adelay=500|500,apad,volume=1.6[a]', '-map', '0:v', '-map', '[a]',
		'-c:v', 'libx264', '-preset', 'medium', '-crf', '21', '-pix_fmt', 'yuv420p', '-profile:v', 'high', '-r', '30',
		'-c:a', 'aac', '-b:a', '128k', '-ar', '44100', '-t', String(duration), '-movflags', '+faststart', final], { stdio: 'inherit' });
	console.log(`video ${(fs.statSync(final).size / 1048576).toFixed(1)} MB`);

	const stamp = Date.now().toString(36);
	ftpUpload(final, `uploads/sf-social/${it.id}-${stamp}.mp4`);
	ftpUpload(cover, `uploads/sf-social/${it.id}-${stamp}.jpg`);
	known.set(it.id, {
		id: it.id,
		post_at: it.post_at,
		product: it.product,
		link: prod.permalink,
		caption: it.caption,
		hashtags: it.hashtags || [],
		video_url: `${SITE}/wp-content/uploads/sf-social/${it.id}-${stamp}.mp4`,
		cover_url: `${SITE}/wp-content/uploads/sf-social/${it.id}-${stamp}.jpg`,
		duration,
	});
}
if (browser) await browser.close();

// Keep only posts that are still queued; newest last.
const ids = new Set(items.map((i) => i.id));
manifest.posts = [...known.values()].filter((p) => ids.has(p.id)).sort((a, b) => a.post_at.localeCompare(b.post_at));
manifest.updated = new Date().toISOString();
fs.writeFileSync(MANIFEST, JSON.stringify(manifest, null, 1));
ftpUpload(MANIFEST, 'plugins/store-features/social/queue.json');
console.log(`\nmanifest: ${manifest.posts.length} posts`);
