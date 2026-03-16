/* Conzent CMP Loader v1.3.0 */
(function(){var s=document.currentScript;if(!s)return;var k=s.getAttribute("data-key");if(!k)return;
var h=location.hostname,o="";try{o=new URL(s.src).hostname}catch(e){}
var G="googletagmanager.com google-analytics.com googleadservices.com googleads.g.doubleclick.net pagead2.googlesyndication.com".split(" ");
if(typeof window._cnzConsentGiven==="undefined"){window._cnzBlockedEls=[];window._cnzConsentGiven=false;window.is_consent_loaded=true;
/* Cookie-name interceptor — blocks known tracking cookies before consent */
window._cnzBlockedCookies=[];window._cnzCookieBlockerLoaded=true;
var _cnzBlockedCookiePatterns=[
{p:"^(_fbp|_fbc|fr)$",c:"marketing"},
{p:"^(_gcl_au|_gcl_aw|_gcl_dc|_gcl_gb|_gcl_gs|_gcl_ha|_gcl_gf)$",c:"marketing"},
{p:"^(_ga|_ga_|_gid|_gat|__utm)",c:"analytics"},
{p:"^(_tt_|_ttp)$",c:"marketing"},
{p:"^(_uet|_uetsid|_uetvid|MUID|_clck|_clsk)$",c:"marketing"},
{p:"^(_scid|_sctr|sc_at)$",c:"marketing"},
{p:"^(_pin_unauth|_pinterest_)$",c:"marketing"},
{p:"^(_rdt_uuid|_rdt_cid)$",c:"marketing"},
{p:"^(IDE|test_cookie|DSID)$",c:"marketing"},
{p:"^(_hjid|_hjSession)",c:"analytics"},
{p:"^(mp_|amplitude_id)",c:"analytics"},
{p:"^(ajs_anonymous_id|ajs_user_id)",c:"analytics"},
{p:"^(_li_ss|li_sugr|bcookie|lidc|UserMatchHistory)$",c:"marketing"}
];
var _cnzOrigCookieDesc=Object.getOwnPropertyDescriptor(Document.prototype,"cookie")||Object.getOwnPropertyDescriptor(HTMLDocument.prototype,"cookie");
if(_cnzOrigCookieDesc&&_cnzOrigCookieDesc.set){Object.defineProperty(document,"cookie",{get:function(){return _cnzOrigCookieDesc.get.call(this)},set:function(val){if(!window._cnzConsentGiven){var name=val.split("=")[0].trim();for(var i=0;i<_cnzBlockedCookiePatterns.length;i++){try{if(new RegExp(_cnzBlockedCookiePatterns[i].p).test(name)){window._cnzBlockedCookies.push({v:val,c:_cnzBlockedCookiePatterns[i].c});return}}catch(e){}}}
_cnzOrigCookieDesc.set.call(this,val)},configurable:true});
window._cnzReplayBlockedCookies=function(gc){if(!window._cnzBlockedCookies.length)return;var r=[];for(var i=0;i<window._cnzBlockedCookies.length;i++){var e=window._cnzBlockedCookies[i];if(gc.indexOf(e.c)!==-1){_cnzOrigCookieDesc.set.call(document,e.v)}else{r.push(e)}}window._cnzBlockedCookies=r}}
/* Script/iframe blocker */
function P(u){if(!u||u==="about:blank")return false;try{var a=new URL(u,location.href),n=a.hostname;if(!n||n===h||(o&&n===o))return false;for(var i=0;i<G.length;i++)if(n===G[i]||n==="www."+G[i])return false;return true}catch(e){return false}}
window._cnzEarlyObserver=new MutationObserver(function(m){if(window._cnzConsentGiven)return;for(var i=0;i<m.length;i++)for(var n=m[i].addedNodes,j=0;j<n.length;j++){var e=n[j],t=e.tagName;if(!t)continue;
if(t==="IFRAME"){var c=e.getAttribute("src")||"";if(c&&c!=="about:blank"&&P(c)){e.setAttribute("data-cnz-src",c);e.setAttribute("data-cnz-blocked","pre-consent");e.setAttribute("data-blocked","yes");var w=e.getAttribute("width")||e.style.width,g=e.getAttribute("height")||e.style.height;if(w)e.setAttribute("data-cnz-width",w);if(g)e.setAttribute("data-cnz-height",g);e.hasAttribute("data-consent")||e.setAttribute("data-consent","marketing");e.src="about:blank";e.style.display="none";window._cnzBlockedEls.push(e)}}
if(t==="SCRIPT"){var d=e.getAttribute("src")||"";if(d&&P(d)){e.setAttribute("data-cnz-src",d);e.setAttribute("data-cnz-blocked","pre-consent");e.type="text/plain";window._cnzBlockedEls.push(e)}}}});
window._cnzEarlyObserver.observe(document.documentElement,{childList:true,subtree:true})}
window.dataLayer=window.dataLayer||[];function _g(){window.dataLayer.push(arguments)}
_g("consent","default",{ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",analytics_storage:"denied",functionality_storage:"denied",personalization_storage:"denied",security_storage:"granted",wait_for_update:500});
if(typeof fbq==="function"){fbq("consent","revoke")}
var b=s.src.replace(/\/c\/consent\.js.*$/,"")+"/sites_data/"+k+"/";
var x=new XMLHttpRequest();x.open("GET",b+"version.json",true);x.onload=function(){var v="";try{v=JSON.parse(x.responseText).v}catch(e){}var e=document.createElement("script");e.src=b+"script.js"+(v?"?v="+v:"");e.async=true;(document.head||document.documentElement).appendChild(e);if(e.type==="text/plain")e.type="text/javascript"};x.onerror=function(){var e=document.createElement("script");e.src=b+"script.js";e.async=true;(document.head||document.documentElement).appendChild(e);if(e.type==="text/plain")e.type="text/javascript"};x.send()})();
