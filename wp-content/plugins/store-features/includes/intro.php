<?php
/**
 * Opening animation: the first time someone opens the site in a visit, the Panuwear emblem
 * draws itself, the hanger swings, the name rises in, then the curtain lifts (about 1.8 s).
 * The page loads underneath the whole time, so it costs no loading time. Once per visit
 * (sessionStorage), never on cart/checkout/account pages, tap to skip, and a plain short fade
 * for people who turn animations off on their phone.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pages where it may show.
 *
 * @return bool
 */
function store_features_intro_wanted() {
	if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() ) {
		return false;
	}
	if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
		return false;
	}
	return ! is_page( 'track-order' );
}

// Before anything paints: skip it at once when this visit has already seen it.
add_action(
	'wp_head',
	function () {
		if ( ! store_features_intro_wanted() ) {
			return;
		}
		echo "<script>try{if(sessionStorage.getItem('pwIntro')){document.documentElement.classList.add('pw-intro-seen')}}catch(e){}</script>\n";
	},
	1
);

add_action(
	'wp_body_open',
	function () {
		if ( ! store_features_intro_wanted() ) {
			return;
		}
		?>
<div id="pw-intro" aria-hidden="true">
	<div class="pw-intro__stage">
		<svg class="pw-intro__mark" viewBox="0 0 160 160" width="150" height="150">
			<defs>
				<linearGradient id="pwiGold" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fff0b3"/><stop offset=".28" stop-color="#d99a25"/><stop offset=".55" stop-color="#fff0a8"/><stop offset=".78" stop-color="#b97812"/><stop offset="1" stop-color="#f8d875"/></linearGradient>
				<linearGradient id="pwiPink" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#ff8eb6"/><stop offset=".5" stop-color="#d4145a"/><stop offset="1" stop-color="#8f093b"/></linearGradient>
			</defs>
			<circle class="pw-intro__ring" cx="80" cy="80" r="73" fill="none" stroke="url(#pwiGold)" stroke-width="3"/>
			<path class="pw-intro__p" d="M48 124V38h37c27 0 42 13 42 34 0 23-17 37-44 37H67v15H48zm19-32h16c16 0 25-7 25-20 0-12-9-18-25-18H67v38z" fill="url(#pwiGold)"/>
			<path class="pw-intro__flame" d="M82 80c18-8 26-23 27-38 8 20-2 34-16 45-10 8-14 16-13 27 13-11 26-12 38-4-6 20-21 32-39 34 8-12 8-23 3-32-7-12-6-22 0-32z" fill="url(#pwiPink)"/>
			<g class="pw-intro__hanger">
				<path d="M91 69l26-14 26 14-26 14z" fill="none" stroke="url(#pwiGold)" stroke-width="2.4" stroke-linejoin="round"/>
				<path d="M117 55v-8c0-7 10-7 10 0" fill="none" stroke="url(#pwiGold)" stroke-width="2.4" stroke-linecap="round"/>
			</g>
		</svg>
		<p class="pw-intro__name"><span>P</span><span>a</span><span>n</span><span>u</span><span class="pw-intro__w">w</span><span class="pw-intro__w">e</span><span class="pw-intro__w">a</span><span class="pw-intro__w">r</span></p>
		<p class="pw-intro__line" lang="hi">कपड़े नहीं, अपनी vibe पहनो।</p>
	</div>
</div>
<style>
#pw-intro{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;background:radial-gradient(120% 80% at 50% 45%,#24201c 0%,#141416 60%,#0c0c0e 100%);color:#fff;cursor:pointer;animation:pwiLift .55s cubic-bezier(.7,0,.2,1) 1.75s forwards}
.pw-intro-seen #pw-intro{display:none}
.pw-intro__stage{display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center}
.pw-intro__mark{overflow:visible;filter:drop-shadow(0 10px 30px rgba(217,154,37,.25))}
.pw-intro__ring{stroke-dasharray:460;stroke-dashoffset:460;animation:pwiDraw .7s ease-out .05s forwards}
.pw-intro__p{opacity:0;transform:translateY(10px);transform-origin:80px 80px;animation:pwiRise .45s cubic-bezier(.2,.8,.2,1) .3s forwards}
.pw-intro__flame{opacity:0;transform:scale(.3);transform-origin:96px 100px;animation:pwiPop .5s cubic-bezier(.3,1.6,.5,1) .55s forwards}
.pw-intro__hanger{opacity:0;transform-origin:122px 47px;animation:pwiHang .2s ease-out .7s forwards,pwiSwing 1s cubic-bezier(.4,0,.3,1) .75s}
.pw-intro__name{margin:8px 0 0;font:800 44px/1 "Baloo 2","Poppins",system-ui,sans-serif;letter-spacing:.5px}
.pw-intro__name span{display:inline-block;opacity:0;transform:translateY(18px);animation:pwiRise .4s cubic-bezier(.2,.8,.2,1) forwards}
.pw-intro__name .pw-intro__w{color:#E0335F}
.pw-intro__name span:nth-child(1){animation-delay:.8s}.pw-intro__name span:nth-child(2){animation-delay:.84s}.pw-intro__name span:nth-child(3){animation-delay:.88s}.pw-intro__name span:nth-child(4){animation-delay:.92s}
.pw-intro__name span:nth-child(5){animation-delay:.96s}.pw-intro__name span:nth-child(6){animation-delay:1s}.pw-intro__name span:nth-child(7){animation-delay:1.04s}.pw-intro__name span:nth-child(8){animation-delay:1.08s}
.pw-intro__line{margin:6px 0 0;font:600 17px/1.3 "Baloo 2",system-ui,sans-serif;color:#F6B93B;opacity:0;animation:pwiFade .4s ease-out 1.2s forwards}
@keyframes pwiDraw{to{stroke-dashoffset:0}}
@keyframes pwiRise{to{opacity:1;transform:none}}
@keyframes pwiPop{to{opacity:1;transform:scale(1)}}
@keyframes pwiFade{to{opacity:1}}
@keyframes pwiHang{to{opacity:1}}
@keyframes pwiSwing{0%{transform:rotate(0)}20%{transform:rotate(16deg)}45%{transform:rotate(-11deg)}68%{transform:rotate(6deg)}86%{transform:rotate(-3deg)}100%{transform:rotate(0)}}
@keyframes pwiLift{to{transform:translateY(-100%);visibility:hidden}}
#pw-intro.is-skip{animation:pwiOut .25s ease-in forwards}
@keyframes pwiOut{to{opacity:0;visibility:hidden}}
@media (prefers-reduced-motion:reduce){#pw-intro{animation:pwiOut .3s ease-in .5s forwards}#pw-intro *{animation-duration:.01s!important;animation-delay:0s!important}}
</style>
<script>
(function(){var el=document.getElementById('pw-intro');if(!el){return;}
try{if(sessionStorage.getItem('pwIntro')){el.remove();return;}sessionStorage.setItem('pwIntro','1');}catch(e){}
var done=function(){if(el&&el.parentNode){el.parentNode.removeChild(el);}};
el.addEventListener('click',function(){el.classList.add('is-skip');setTimeout(done,260);});
el.addEventListener('animationend',function(e){if(e.target===el){done();}});
setTimeout(done,3200);})();
</script>
		<?php
	},
	1
);

// Cached pages were saved before the intro existed: clear the page cache once so it shows.
add_action(
	'init',
	function () {
		if ( '2' === get_option( 'store_features_intro_v' ) ) {
			return;
		}
		update_option( 'store_features_intro_v', '2', false );
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
	}
);
