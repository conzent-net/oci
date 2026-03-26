/**
 * Conzent CMP Loader
 *
 * Tiny loader that:
 *   1. Immediately starts blocking third-party iframes/scripts (MutationObserver)
 *   2. Fetches version.json (never cached) to get the current script hash
 *   3. Loads script.js?v={hash} (immutably cached)
 *
 * The early blocker ensures iframes are intercepted BEFORE the main script loads,
 * even though script.js is loaded asynchronously.
 *
 * Usage:
 *   <script src="https://example.com/cmp/conzent-cmp.js"
 *       data-key="WEBSITE_KEY"></script>
 */
(function() {
    var s = document.currentScript;
    if (!s) return;
    var key = s.getAttribute('data-key');
    if (!key) return;

    // ── Early Blocker ──────────────────────────────────────────
    // Block third-party iframes and scripts immediately during HTML parsing,
    // BEFORE the main consent script loads. The main script's
    // Conzent_Blocker.runScripts() restores them after consent.
    if (typeof window._cnzConsentGiven === 'undefined') {
        window._cnzBlockedEls = [];
        window._cnzConsentGiven = false;
        window.is_consent_loaded = true; // Prevent script.js from resetting _cnzBlockedEls

        var ownOrigin = location.hostname;
        var ownScript = s.src;
        var cmpOrigin = '';
        try { cmpOrigin = new URL(ownScript).hostname; } catch(e) {}

        function _cnzIsThirdParty(src) {
            if (!src || src === '' || src === 'about:blank') return false;
            try {
                var url = new URL(src, location.href);
                if (url.hostname === ownOrigin || url.hostname === '') return false;
                // Don't block scripts from the same origin as the CMP loader
                if (cmpOrigin && url.hostname === cmpOrigin) return false;
                return true;
            } catch(e) {
                return false;
            }
        }

        window._cnzEarlyObserver = new MutationObserver(function(mutations) {
            if (window._cnzConsentGiven) return;
            for (var i = 0; i < mutations.length; i++) {
                var nodes = mutations[i].addedNodes;
                for (var j = 0; j < nodes.length; j++) {
                    var el = nodes[j];
                    if (!el.tagName) continue;
                    var tag = el.tagName.toLowerCase();

                    // Block iframes with third-party src
                    if (tag === 'iframe') {
                        var src = el.getAttribute('src') || '';
                        if (src && src !== 'about:blank' && _cnzIsThirdParty(src)) {
                            var iw = el.getAttribute('width') || el.style.width || '';
                            var ih = el.getAttribute('height') || el.style.height || '';
                            el.setAttribute('data-cnz-src', src);
                            el.setAttribute('data-cnz-blocked', 'pre-consent');
                            el.setAttribute('data-blocked', 'yes');
                            if (iw) el.setAttribute('data-cnz-width', iw);
                            if (ih) el.setAttribute('data-cnz-height', ih);
                            if (!el.hasAttribute('data-consent')) {
                                el.setAttribute('data-consent', 'marketing');
                            }
                            el.setAttribute('src', 'about:blank');
                            el.style.display = 'none';
                            window._cnzBlockedEls.push(el);
                        }
                    }

                    // Block third-party scripts
                    if (tag === 'script') {
                        var scriptSrc = el.getAttribute('src') || '';
                        if (scriptSrc && _cnzIsThirdParty(scriptSrc)) {
                            el.setAttribute('data-cnz-src', scriptSrc);
                            el.setAttribute('data-cnz-blocked', 'pre-consent');
                            el.type = 'text/plain';
                            window._cnzBlockedEls.push(el);
                        }
                    }
                }
            }
        });

        window._cnzEarlyObserver.observe(document.documentElement, {
            childList: true,
            subtree: true
        });
    }

    // ── Script Loader ──────────────────────────────────────────
    var base = s.src.replace(/\/cmp\/conzent-cmp\.js.*$/, '');
    var versionUrl = base + '/sites_data/' + key + '/version.json';
    var scriptUrl  = base + '/sites_data/' + key + '/script.js';

    var xhr = new XMLHttpRequest();
    xhr.open('GET', versionUrl + '?_=' + Date.now(), true);
    xhr.onload = function() {
        var v = '';
        try { v = JSON.parse(xhr.responseText).v || ''; } catch(e) {}
        loadScript(scriptUrl + (v ? '?v=' + v : '?_=' + Date.now()));
    };
    xhr.onerror = function() {
        loadScript(scriptUrl + '?_=' + Date.now());
    };
    xhr.send();

    function loadScript(url) {
        var el = document.createElement('script');
        el.src = url;
        el.async = true;
        (document.head || document.documentElement).appendChild(el);
    }
})();
