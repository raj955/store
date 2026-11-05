<?php
/**
 * Small, fast animations that make the shop feel alive (plain CSS + ~2 KB of JavaScript, no
 * libraries, nothing that delays loading):
 *  - Add to cart: the product photo flies into the cart icon and the cart count bounces.
 *  - Wishlist heart: a little burst when it is added.
 *  - Product cards: lift on hover, and on computers the back photo shows on hover.
 *  - Product cards slide in softly as you scroll (only the ones below the first screen).
 *  - Buttons press in when tapped.
 *  - Home: a moving "free delivery • COD" strip and floating hero photos (see the theme).
 * Everything is switched off for people who turn animations off on their phone.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * Second photo for a product card (the back of the first colour, or the next gallery photo).
 *
 * @param WC_Product $product Product.
 * @return int Attachment ID or 0.
 */
function store_features_motion_alt_image( $product ) {
	$main = (int) $product->get_image_id();
	$map  = $product->get_meta( '_sf_qikink_colour_images' );
	if ( is_array( $map ) && $map ) {
		$first = reset( $map );
		foreach ( (array) $first as $id ) {
			if ( (int) $id && (int) $id !== $main ) {
				return (int) $id;
			}
		}
	}
	foreach ( $product->get_gallery_image_ids() as $id ) {
		if ( (int) $id !== $main ) {
			return (int) $id;
		}
	}
	return 0;
}

// The back photo's address rides along in the card; it is only downloaded on hover.
add_action(
	'woocommerce_after_shop_loop_item_title',
	function () {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$alt = store_features_motion_alt_image( $product );
		$src = $alt ? wp_get_attachment_image_url( $alt, 'woocommerce_thumbnail' ) : '';
		if ( $src ) {
			echo '<span class="pw-alt" data-src="' . esc_url( $src ) . '" hidden></span>';
		}
	},
	30
);

add_action(
	'wp_footer',
	function () {
		if ( is_admin() ) {
			return;
		}
		?>
<style id="pw-motion">
@media (prefers-reduced-motion:no-preference){
.pw2 .single_add_to_cart_button,.pw2 .add_to_cart_button,.pw2 .pw-btn,.pw2 .wc-block-components-button{transition:transform .12s ease,filter .2s ease}
.pw2 .single_add_to_cart_button:active,.pw2 .add_to_cart_button:active,.pw2 .pw-btn:active,.pw2 .wc-block-components-button:active{transform:scale(.96)}
.pw-fly{position:fixed;z-index:2147483001;pointer-events:none;border-radius:14px;box-shadow:0 12px 30px rgba(0,0,0,.35);object-fit:cover;transition:transform .75s cubic-bezier(.5,-0.25,.4,1),opacity .75s ease;will-change:transform}
.pw-cart-bump{animation:pwBump .5s cubic-bezier(.3,1.8,.5,1)}
@keyframes pwBump{0%{transform:scale(1)}35%{transform:scale(1.35) rotate(-8deg)}70%{transform:scale(.92) rotate(4deg)}100%{transform:scale(1)}}
.pw-burst{position:fixed;z-index:2147483001;pointer-events:none;width:8px;height:8px;margin:-4px 0 0 -4px;border-radius:50%;animation:pwBurst .6s ease-out forwards}
@keyframes pwBurst{to{transform:translate(var(--dx),var(--dy)) scale(.2);opacity:0}}
.yith-wcwl-add-to-wishlist-button--added svg{animation:pwHeart .45s cubic-bezier(.3,1.8,.5,1)}
@keyframes pwHeart{0%{transform:scale(.6)}60%{transform:scale(1.3)}100%{transform:scale(1)}}
@media (hover:hover){
.pw2 ul.products li.product,.pw2 .wc-block-grid__product{transition:transform .25s ease}
.pw2 ul.products li.product:hover,.pw2 .wc-block-grid__product:hover{transform:translateY(-4px)}
.pw2 .woocommerce-loop-image-link img{transition:transform .5s ease}
.pw2 li.product:hover .woocommerce-loop-image-link img:not(.pw-alt-img){transform:scale(1.04)}
.pw-alt-img{position:absolute;inset:0;width:100%!important;height:100%!important;object-fit:cover;opacity:0;transition:opacity .35s ease;z-index:1}
li.product:hover .pw-alt-img.is-ready,.wc-block-grid__product:hover .pw-alt-img.is-ready{opacity:1}
}
.pw-reveal{opacity:0;transform:translateY(18px)}
.pw-reveal.is-in{opacity:1;transform:none;transition:opacity .5s ease var(--d,0s),transform .5s cubic-bezier(.2,.8,.2,1) var(--d,0s)}
}
</style>
<script id="pw-motion-js">
(function(){
if(window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches){return;}
var $=function(s,r){return (r||document).querySelector(s)};
function cartIcon(){var list=document.querySelectorAll('.header-cart-button, .header-mobile-cart-wrap .header-cart-button, a.cart-contents');for(var i=0;i<list.length;i++){var b=list[i].getBoundingClientRect();if(b.width&&b.top<innerHeight&&b.bottom>0){return list[i];}}return list[0]||null;}
function bump(){var c=cartIcon();if(!c){return;}var t=c.querySelector('.header-cart-total')||c;t.classList.remove('pw-cart-bump');void t.offsetWidth;t.classList.add('pw-cart-bump');}
function fly(img){var c=cartIcon();if(!img||!c){bump();return;}var a=img.getBoundingClientRect(),b=c.getBoundingClientRect();if(!a.width){bump();return;}
var size=Math.min(a.width,180),f=document.createElement('img');f.src=img.currentSrc||img.src;f.className='pw-fly';f.style.cssText='left:'+(a.left+a.width/2-size/2)+'px;top:'+(a.top+a.height/2-size/2)+'px;width:'+size+'px;height:'+size+'px';
document.body.appendChild(f);requestAnimationFrame(function(){requestAnimationFrame(function(){var dx=b.left+b.width/2-(a.left+a.width/2),dy=b.top+b.height/2-(a.top+a.height/2);f.style.transform='translate('+dx+'px,'+dy+'px) scale(.12) rotate(20deg)';f.style.opacity='.4';});});
setTimeout(function(){f.remove();bump();},780);}
document.addEventListener('click',function(e){var btn=e.target.closest&&e.target.closest('.single_add_to_cart_button, .add_to_cart_button, .wc-block-components-product-button__button');if(!btn){return;}
if(btn.classList.contains('disabled')||btn.classList.contains('wc-variation-selection-needed')||btn.classList.contains('product_type_variable')){return;}
var card=btn.closest('li.product, .wc-block-grid__product, .product');var img=card&&(card.querySelector('.flex-active-slide img, .woocommerce-product-gallery__image img, .woocommerce-loop-image-link img, img'));fly(img);},true);
document.addEventListener('click',function(e){var h=e.target.closest&&e.target.closest('.yith-wcwl-add-to-wishlist-button');if(!h||h.getAttribute('aria-pressed')==='true'){return;}
var r=h.getBoundingClientRect(),cx=r.left+r.width/2,cy=r.top+r.height/2,cols=['#E0335F','#FFA41B','#F6B93B','#ff8eb6'];
for(var i=0;i<10;i++){var p=document.createElement('span'),ang=i/10*Math.PI*2,d=26+Math.random()*16;p.className='pw-burst';p.style.left=cx+'px';p.style.top=cy+'px';p.style.background=cols[i%4];p.style.setProperty('--dx',Math.cos(ang)*d+'px');p.style.setProperty('--dy',Math.sin(ang)*d+'px');document.body.appendChild(p);(function(n){setTimeout(function(){n.remove();},650);})(p);}},true);
if(window.matchMedia&&matchMedia('(hover: hover)').matches){document.addEventListener('mouseover',function(e){var card=e.target.closest&&e.target.closest('li.product, .wc-block-grid__product');if(!card||card.dataset.pwAlt){return;}card.dataset.pwAlt='1';
var s=card.querySelector('.pw-alt'),link=card.querySelector('.woocommerce-loop-image-link');if(!s||!link){return;}var im=new Image();im.className='pw-alt-img';im.alt='';im.decoding='async';im.onload=function(){im.classList.add('is-ready');};im.src=s.getAttribute('data-src');if(getComputedStyle(link).position==='static'){link.style.position='relative';}link.appendChild(im);});}
if('IntersectionObserver' in window){var cards=document.querySelectorAll('ul.products li.product, .wc-block-grid__product, .pw-why li, .pw-bundle .pw-wrap > *');var io=new IntersectionObserver(function(es){es.forEach(function(en){if(en.isIntersecting){en.target.classList.add('is-in');io.unobserve(en.target);}});},{rootMargin:'0px 0px -8% 0px'});
var k=0;cards.forEach(function(c){if(c.getBoundingClientRect().top>innerHeight){c.classList.add('pw-reveal');c.style.setProperty('--d',((k++%4)*0.07)+'s');io.observe(c);}});}
})();
</script>
		<?php
	},
	60
);
