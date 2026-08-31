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
 * Ported from the legacy platform (legacy/app/js/shopify.js); behaviour is
 * unchanged. The consent events and _Store internals it relies on are still
 * produced by resources/consent/js/conzent.script.js.
 */
(function () {
    "use strict";

    window.addEventListener("load", function () {
        function noop() {}

        function forwardConsent(event) {
            var law = window.conzent._Store._bannerConfig.default_laws;
            var consent = event.detail;

            if (law === "gdpr") {
                var analytics = consent.analytics === true;
                var marketing = consent.advertisement === true;
                var preferences = consent.preferences === true;
                var functional = consent.functional === true;

                window.Shopify.customerPrivacy.setTrackingConsent({
                    analytics: analytics,
                    marketing: marketing,
                    preferences: preferences || functional
                }, noop);
            } else if (law === "ccpa") {
                var allAccepted = Object.values(consent).every(function (granted) {
                    return granted === true;
                });

                window.Shopify.customerPrivacy.setTrackingConsent({
                    sale_of_data: allAccepted
                }, noop);
            } else if (law === "") {
                window.Shopify.customerPrivacy.setTrackingConsent({
                    analytics: true,
                    marketing: true,
                    preferences: true,
                    sale_of_data: true
                }, noop);
            }
        }

        if (window.Shopify && window.Shopify.loadFeatures) {
            window.Shopify.loadFeatures([{
                name: "consent-tracking-api",
                version: "0.1"
            }], noop);

            document.addEventListener("conzentck_consent_update", forwardConsent);
            document.addEventListener("conzentck_cookie_banner_load", forwardConsent);
        }
    });
})();
