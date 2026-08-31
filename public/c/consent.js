/* Conzent CMP Loader v1.6.0 */
(function(){var s=document.currentScript;if(!s)return;var k=s.getAttribute("data-key");if(!k)return;
/* CSP nonce relay: under a nonce policy the browser exposes the embed tag's
   nonce only via the IDL property (the content attribute reads "");
   data-nonce is the fallback for frameworks that strip nonce attributes.
   Every script this loader or the bundle injects inherits it. */
var N=s.nonce||s.getAttribute("data-nonce")||"";window._cnzNonce=N;
function NN(e){if(N)e.nonce=N;return e}
var h=location.hostname,o="";try{o=new URL(s.src).hostname}catch(e){}
/* ── IAB TCF stub ─────────────────────────────────────────────────────────
   Vendors (GPT, Prebid, ad exchanges) look for __tcfapi the moment they
   initialise. If it is absent they do not retry — GPT logs "An IAB TCF signal
   was not received" and drops to non-personalised ads. The real CMP lives in
   script.js, two round trips behind this file, so the stub must be installed
   here, synchronously, before anything else on the page runs. It queues every
   call; CmpApi drains that queue when script.js takes over (see
   purgeQueuedCalls in resources/consent/js/iab-script.js).

   Installed optimistically: the site's TCF setting is not known until
   version.json arrives. _cnzDropStub() below removes it again for sites that
   do not run TCF, so they behave exactly as they did before. */
var TL="__tcfapiLocator",_stubQ=null,_stubbed=false;
/* Async install: the inline-defaults snippet (docs/embed-snippet.md, "Async
   install") plants a minimal stub marked cnzStub=1 before this loader runs.
   Adopt it — same live queue, same teardown path — so `async` on the loader
   tag keeps the __tcfapi-presence guarantee without a second stub fighting
   this one. A real CMP (no marker) is left alone exactly as before. */
if(typeof window.__tcfapi==="function"&&window.__tcfapi.cnzStub===1){_stubbed=true;try{_stubQ=window.__tcfapi()||[]}catch(e){_stubQ=[]}}
else if(typeof window.__tcfapi!=="function"){var ga,cmpFrame,W=window;
for(var fw=W;fw;){try{if(fw.frames[TL]){cmpFrame=fw;break}}catch(e){}if(fw===W.top)break;fw=fw.parent}
if(!cmpFrame){_stubbed=true;_stubQ=[];
/* The retry has to re-check _stubbed: when the loader is in <head> there is no
   document.body yet, so this is still pending when version.json comes back and
   would otherwise plant the locator iframe after the stub was torn down. */
(function mkFrame(){if(!_stubbed)return;var d=W.document;if(W.frames[TL])return;if(d.body){var f=d.createElement("iframe");f.style.cssText="display:none";f.name=TL;d.body.appendChild(f)}else setTimeout(mkFrame,5)})();
W.__tcfapi=function(){var a=arguments;if(!a.length)return _stubQ;
if(a[0]==="setGdprApplies"){if(a.length>3&&parseInt(a[1],10)===2&&typeof a[3]==="boolean"){ga=a[3];if(typeof a[2]==="function")a[2]("set",true)}}
else if(a[0]==="ping"){if(typeof a[2]==="function")a[2]({gdprApplies:ga,cmpLoaded:false,cmpStatus:"stub"})}
else _stubQ.push(a)};
W.addEventListener("message",function(ev){var str=typeof ev.data==="string",p={};if(str){try{p=JSON.parse(ev.data)}catch(e){}}else p=ev.data;
var c=(typeof p==="object"&&p!==null)?p.__tcfapiCall:null;if(!c)return;
/* This listener is the permanent cross-frame bridge — CmpApi installs no
   message handler of its own, so it keeps delegating to whatever __tcfapi is
   current, first the stub and later the real API. After a teardown there is
   none, so bail rather than throw. */
if(typeof W.__tcfapi!=="function")return;
W.__tcfapi(c.command,c.version,function(rv,ok){var r={__tcfapiReturn:{returnValue:rv,success:ok,callId:c.callId}};if(ev.source&&ev.source.postMessage)ev.source.postMessage(str?JSON.stringify(r):r,"*")},c.parameter)},false)}}
/* Remove the stub on sites that do not run TCF. Queued callers are answered
   with (null,false) rather than left hanging, then __tcfapi and the locator
   iframe go away so vendors see no CMP at all — the pre-stub behaviour. */
function _cnzDropStub(){if(!_stubbed)return;_stubbed=false;
var q=_stubQ||[];_stubQ=[];
try{delete window.__tcfapi}catch(e){window.__tcfapi=undefined}
for(var i=0;i<q.length;i++){try{if(typeof q[i][2]==="function")q[i][2](null,false)}catch(e){}}
var fr=document.getElementsByName(TL);for(var j=fr.length-1;j>=0;j--){if(fr[j].parentNode)fr[j].parentNode.removeChild(fr[j])}}
/* Exposed so script.js can retract the stub when it bails out — a domain
   mismatch or any other config error means the CMP never loads, and a stub left
   answering "cmpStatus: stub" keeps vendors waiting forever. */
window._cnzDropTcfStub=_cnzDropStub;
var G="googletagmanager.com google-analytics.com googleadservices.com googleads.g.doubleclick.net pagead2.googlesyndication.com".split(" ");
window._cnzAllowedScripts=[];
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
function _cnzIsAllowed(u){for(var i=0;i<window._cnzAllowedScripts.length;i++){if(u.indexOf(window._cnzAllowedScripts[i])!==-1)return true}return false}
function P(u){if(!u||u==="about:blank")return false;try{var a=new URL(u,location.href),n=a.hostname;if(!n||n===h||(o&&n===o))return false;for(var i=0;i<G.length;i++)if(n===G[i]||n==="www."+G[i])return false;if(_cnzIsAllowed(u))return false;return true}catch(e){return false}}
window._cnzEarlyObserver=new MutationObserver(function(m){if(window._cnzConsentGiven)return;for(var i=0;i<m.length;i++)for(var n=m[i].addedNodes,j=0;j<n.length;j++){var e=n[j],t=e.tagName;if(!t)continue;
if(t==="IFRAME"){var c=e.getAttribute("src")||"";if(c&&c!=="about:blank"&&P(c)){e.setAttribute("data-cnz-src",c);e.setAttribute("data-cnz-blocked","pre-consent");e.setAttribute("data-blocked","yes");var w=e.getAttribute("width")||e.style.width,g=e.getAttribute("height")||e.style.height;if(w)e.setAttribute("data-cnz-width",w);if(g)e.setAttribute("data-cnz-height",g);e.hasAttribute("data-consent")||e.setAttribute("data-consent","marketing");e.src="about:blank";e.style.display="none";window._cnzBlockedEls.push(e)}}
if(t==="SCRIPT"){var d=e.getAttribute("src")||"";if(d&&P(d)){e.setAttribute("data-cnz-src",d);e.setAttribute("data-cnz-blocked","pre-consent");e.type="text/plain";window._cnzBlockedEls.push(e)}}}});
window._cnzEarlyObserver.observe(document.documentElement,{childList:true,subtree:true})}
/* Google Consent Mode defaults.
   Google discards a `consent default` that arrives after a Google tag has
   initialised — the tag then behaves as if consent had been granted. This push
   is the only one on the page: the embed snippet used to carry a duplicate
   inline stub because this file was async and could lose that race, but the tag
   is parser-blocking now, so this runs before the browser reaches anything
   below it. Keeping it here also means data-dl works — a renamed GTM dataLayer
   (GTM's &l=) that a hardcoded inline stub could never know about. */
var _dl=s.getAttribute("data-dl")||"dataLayer";window[_dl]=window[_dl]||[];
var _dls=[window[_dl]];if(_dl!=="dataLayer"){window.dataLayer=window.dataLayer||[];_dls.push(window.dataLayer)}
function _g(){for(var i=0;i<_dls.length;i++)_dls[i].push(arguments)}
_g("consent","default",{ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",analytics_storage:"denied",functionality_storage:"denied",personalization_storage:"denied",security_storage:"granted",wait_for_update:500});
if(typeof fbq==="function"){fbq("consent","revoke")}
var b=s.src.replace(/\/c\/consent\.js.*$/,"")+"/sites_data/"+k+"/",LSK="cnzv_"+k;
/* Last-good version.json cache. Written only for live sites (never when the
   pageview kill switch is on, and the kill deletes it — a paused site must not
   resurrect while the origin is down). Age does not matter: script.js?v= is
   immutable, so any cached version boots from the browser's HTTP cache. */
function LG(){try{var r=localStorage.getItem(LSK);return r?JSON.parse(r):null}catch(e){return null}}
function LP(v){try{localStorage.setItem(LSK,JSON.stringify(v))}catch(e){}}
function LD(){try{localStorage.removeItem(LSK)}catch(e){}}
/* Release early-blocked elements that are on the site's script whitelist */
function AW(al){if(!al||!al.length)return;window._cnzAllowedScripts=al;var kept=[];for(var i=0;i<window._cnzBlockedEls.length;i++){var el=window._cnzBlockedEls[i],src=el.getAttribute("data-cnz-src")||el.getAttribute("src")||"";if(src&&_cnzIsAllowed(src)){if(el.tagName==="SCRIPT"){var ns=NN(document.createElement("script"));ns.src=src;ns.async=true;if(el.parentNode){el.parentNode.insertBefore(ns,el);el.parentNode.removeChild(el)}else{(document.head||document.documentElement).appendChild(ns)}}else if(el.tagName==="IFRAME"){el.src=el.getAttribute("data-cnz-src");el.style.display="";el.removeAttribute("data-cnz-blocked");el.removeAttribute("data-blocked")}}else{kept.push(el)}}window._cnzBlockedEls=kept}
/* Load script.js; alt is the version string for one retry with the other URL
   form (versioned <-> unversioned), null for no retry. Both failing means no
   CMP is coming — tear the stub down so queued vendors get an answer instead
   of hanging on "cmpStatus: stub". */
function SL(v,alt){var e=NN(document.createElement("script"));e.src=b+"script.js"+(v?"?v="+v:"");e.async=true;
e.onerror=function(){if(alt!==null){SL(alt,null)}else{_cnzDropStub()}};
(document.head||document.documentElement).appendChild(e);if(e.type==="text/plain")e.type="text/javascript"}
/* Origin unreachable — boot the banner from the last-good version. */
function DG(){var c=LG();if(c&&c.v){if(c.tcf!==1)_cnzDropStub();AW(c.a);SL(c.v,"")}else{_cnzDropStub();SL("",null)}}
/* Kill switch (pageview limit): the console message promises the page "runs
   without consent management", so the early blocker must actually stand down
   — before this, blocked scripts stayed dead forever with no banner to ever
   release them. Releases everything and stops intercepting. */
function RB(){window._cnzConsentGiven=true;
if(window._cnzEarlyObserver)window._cnzEarlyObserver.disconnect();
if(typeof window._cnzReplayBlockedCookies==="function")window._cnzReplayBlockedCookies(["functional","analytics","marketing"]);
var els=window._cnzBlockedEls||[];window._cnzBlockedEls=[];
for(var i=0;i<els.length;i++){var el=els[i],src=el.getAttribute("data-cnz-src")||"";
if(el.tagName==="SCRIPT"&&src){var ns=NN(document.createElement("script"));ns.src=src;ns.async=true;if(el.parentNode){el.parentNode.insertBefore(ns,el);el.parentNode.removeChild(el)}else{(document.head||document.documentElement).appendChild(ns)}}
else if(el.tagName==="IFRAME"&&src){el.src=src;el.style.display="";el.removeAttribute("data-cnz-blocked");el.removeAttribute("data-blocked")}}}
/* Watchdog: blocking with no banner is the one state that must never pass
   silently. If the CMP has not arrived 20s in and blocking is still holding
   the page, say so loudly. Blocking stays ON — an outage must never release
   trackers (fail closed is the promise). */
setTimeout(function(){if(window.Conzent||window._cnzConsentGiven)return;
var n=(window._cnzBlockedEls||[]).length;
console.warn("[Conzent] The consent script has not loaded. Pre-consent blocking stays ACTIVE (fail closed): "+n+" third-party element(s) held and tracking cookies intercepted — embeds may not display until the CMP loads. This is the compliance-safe failure mode; check the installation and server status.")},20000);
var x=new XMLHttpRequest();x.open("GET",b+"version.json",true);x.timeout=3000;
x.onload=function(){if(x.status<200||x.status>=300){DG();return}
var v="",al=[];try{var j=JSON.parse(x.responseText);v=j.v;al=j.a||[];if(j.tcf!==1)_cnzDropStub();if(j.x){_cnzDropStub();LD();RB();console.log("%c Conzent CMP — Pageview Limit Exceeded ","display:inline-block;font-size:14px;background:linear-gradient(to right,#f14668,#c0392b,#f14668);color:white;padding:4px;border-radius:4px");console.warn("[Conzent] Monthly pageview limit exceeded");console.warn("[Conzent] Consent banner is paused");console.warn("[Conzent] All cookies and scripts are running without consent management");console.warn("[Conzent] Your website may not be GDPR compliant");console.warn("[Conzent] Upgrade your plan at https://app.getconzent.com/billing");return}
LP({v:v,a:al,tcf:j.tcf===1?1:0,t:Date.now()})}catch(e){_cnzDropStub()}
AW(al);SL(v,v?"":null)};
x.onerror=DG;x.ontimeout=DG;x.send()})();
