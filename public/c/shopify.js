/**
 * Conzent → Shopify Customer Privacy bridge
 *
 * Forwards the visitor's Conzent consent choices into Shopify's Customer
 * Privacy API (setTrackingConsent), so Shopify's own tracking and any
 * privacy-aware apps honour the banner. Without this file a Shopify store
 * shows the banner but Shopify never learns the outcome.
 *
 * Install in theme.liquid, in <head>, AFTER the Conzent loader tag:
 *   <script src="https://…/c/consent.js" data-key="YOUR-KEY"></script>
 *   <script src="https://…/c/shopify.js"></script>
 *
 * Mapping (per the site's configured framework in _bannerConfig.default_laws):
 *   gdpr : analytics → analytics, advertisement → marketing,
 *          preferences OR functional → preferences
 *   ccpa : sale_of_data granted only when every category is accepted
 *   ""   : no framework — everything granted
 *
 * Shopify loads the Customer Privacy API on request and asynchronously. The
 * runtime can announce consent before it exists: for a returning visitor it
 * fires on window load, the same moment this bridge used to ask Shopify for
 * the API, and calling it then threw "Cannot read properties of undefined
 * (reading 'setTrackingConsent')" into the console. So the latest consent is
 * held until the API is there, and nothing here may throw into the page.
 *
 * Ported from the legacy platform (legacy/app/js/shopify.js). The consent
 * events and _Store internals it relies on are produced by
 * resources/consent/js/conzent.script.js.
 */
(function () {
    "use strict";

    // Categories the runtime reports as true/false. The payload also carries
    // `meta` ("grant"/"revoke"), which is not a category.
    var CATEGORIES = [
        "necessary", "analytics", "advertisement", "functional",
        "preferences", "performance", "unclassified"
    ];

    var pending = null;
    var apiRequested = false;
    var pollsLeft = 0;

    function noop() {}

    function privacyApi() {
        var shopify = window.Shopify;
        var api = shopify && shopify.customerPrivacy;
        return api && typeof api.setTrackingConsent === "function" ? api : null;
    }

    function currentLaw() {
        try {
            var config = window.conzent && window.conzent._Store && window.conzent._Store._bannerConfig;
            return config ? config.default_laws : undefined;
        } catch (e) {
            return undefined;
        }
    }

    function trackingConsentFor(law, consent) {
        if (!consent || typeof consent !== "object") return null;

        if (law === "gdpr") {
            return {
                analytics: consent.analytics === true,
                marketing: consent.advertisement === true,
                preferences: consent.preferences === true || consent.functional === true
            };
        }
        if (law === "ccpa") {
            var reported = CATEGORIES.filter(function (name) {
                return typeof consent[name] === "boolean";
            });
            var allAccepted = reported.length > 0 && reported.every(function (name) {
                return consent[name] === true;
            });
            return { sale_of_data: allAccepted };
        }
        if (law === "") {
            return { analytics: true, marketing: true, preferences: true, sale_of_data: true };
        }

        return null;
    }

    function deliver() {
        var api = privacyApi();
        if (!api || !pending) return false;

        var consent = pending;
        pending = null;
        try {
            api.setTrackingConsent(consent, noop);
        } catch (e) {
            // Shopify refused the call; the banner's own choice still stands.
        }
        return true;
    }

    function waitForApi() {
        if (deliver() || !pending) return;

        if (!apiRequested && window.Shopify && typeof window.Shopify.loadFeatures === "function") {
            apiRequested = true;
            try {
                window.Shopify.loadFeatures([{ name: "consent-tracking-api", version: "0.1" }], function (error) {
                    if (!error) deliver();
                });
            } catch (e) {}
        }

        // In case the callback never comes (the theme loaded the API itself,
        // or Shopify answered with an error): look again for about ten seconds.
        if (pollsLeft === 0) {
            pollsLeft = 40;
            var poll = function () {
                if (deliver() || !pending || --pollsLeft <= 0) {
                    pollsLeft = 0;
                    return;
                }
                setTimeout(poll, 250);
            };
            setTimeout(poll, 250);
        }
    }

    function forwardConsent(event) {
        var consent = trackingConsentFor(currentLaw(), event && event.detail);
        if (!consent) return;

        pending = consent;
        waitForApi();
    }

    document.addEventListener("conzentck_consent_update", forwardConsent);
    document.addEventListener("conzentck_cookie_banner_load", forwardConsent);
})();
