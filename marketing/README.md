# Social media (Instagram + Facebook), automatic

1. `queue/*.json` – one file per post: product slug, time (`post_at`, IST), on-screen Hindi lines, the
   voice script, caption and hashtags. A weekly scheduled task writes next week's 7 posts here.
2. `.github/workflows/social-render.yml` – makes each reel (product photos from the live store +
   Hindi voice via edge-tts, 1080×1920 H.264/AAC), uploads it to `wp-content/uploads/sf-social/`
   and updates `wp-content/plugins/store-features/social/queue.json`.
3. The website (`store-features/includes/social.php`) posts each item at its time to the Instagram
   business account and the Facebook Page, using the token saved in WooCommerce → Social posting.
