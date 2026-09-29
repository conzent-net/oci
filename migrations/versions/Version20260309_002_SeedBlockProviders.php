<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Add default_category column to oci_block_providers and seed known
 * tracking / beacon providers so they are blocked before consent.
 *
 * Each provider has a URL pattern (pipe-delimited for regex matching in the
 * consent script) and a default consent category. The consent script will
 * block any script/iframe whose src matches a provider pattern until the
 * visitor grants consent for the corresponding category.
 */
final class Version20260309_002_SeedBlockProviders extends Migration
{
    public function getDescription(): string
    {
        return 'Add default_category to block_providers and seed known tracking beacons';
    }

    public function up(): void
    {
        // Add default_category column (maps to cookie category slug)
        $this->sql("
            ALTER TABLE oci_block_providers
            ADD COLUMN `default_category` VARCHAR(50) NULL DEFAULT NULL AFTER `default_action`
        ");

        // ── Known tracking providers / beacons ──────────────────────────
        // Format: provider_url uses pipe (|) as OR separator for regex matching
        // in the consent script's _BlockProvider function.

        $providers = [
            // ── Analytics ────────────────────────────────────────────────
            [
                'name' => 'Google Analytics',
                'url' => 'google-analytics.com|analytics.google.com|googletagmanager.com/gtag',
                'desc' => 'Google Analytics (GA4/Universal). Collects anonymised browsing data.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Google Tag Manager',
                'url' => 'googletagmanager.com',
                'desc' => 'Google Tag Manager container script. May load analytics and marketing tags.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Matomo / Piwik',
                'url' => 'matomo.js|piwik.js|matomo.php|piwik.php',
                'desc' => 'Matomo (formerly Piwik) web analytics.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Plausible Analytics',
                'url' => 'plausible.io',
                'desc' => 'Plausible privacy-friendly analytics.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Hotjar',
                'url' => 'hotjar.com|static.hotjar.com|script.hotjar.com',
                'desc' => 'Hotjar heatmaps, session recordings and feedback tools.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Microsoft Clarity',
                'url' => 'clarity.ms',
                'desc' => 'Microsoft Clarity session recording and heatmaps.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Mixpanel',
                'url' => 'mixpanel.com|cdn.mxpnl.com',
                'desc' => 'Mixpanel product analytics.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Amplitude',
                'url' => 'amplitude.com|cdn.amplitude.com',
                'desc' => 'Amplitude product analytics.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Heap Analytics',
                'url' => 'heap.io|heapanalytics.com|cdn.heapanalytics.com',
                'desc' => 'Heap auto-capture analytics.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Segment',
                'url' => 'segment.com|segment.io|cdn.segment.com',
                'desc' => 'Segment CDP (customer data platform).',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Adobe Analytics',
                'url' => 'omtrdc.net|demdex.net|adobedtm.com|2o7.net|sc.omtrdc.net',
                'desc' => 'Adobe Analytics (formerly Omniture SiteCatalyst).',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Clicky',
                'url' => 'static.getclicky.com|in.getclicky.com',
                'desc' => 'Clicky real-time web analytics.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Statcounter',
                'url' => 'statcounter.com',
                'desc' => 'StatCounter web analytics.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Yandex Metrica',
                'url' => 'mc.yandex.ru|metrika.yandex.ru',
                'desc' => 'Yandex Metrica analytics and heatmaps.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Baidu Analytics',
                'url' => 'hm.baidu.com',
                'desc' => 'Baidu Tongji web analytics.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Gauges',
                'url' => 'secure.gaug.es',
                'desc' => 'Gauges real-time analytics.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'FullStory',
                'url' => 'fullstory.com|rs.fullstory.com',
                'desc' => 'FullStory session replay and analytics.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Lucky Orange',
                'url' => 'luckyorange.com|luckyorange.net',
                'desc' => 'Lucky Orange session recordings and heatmaps.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Mouseflow',
                'url' => 'mouseflow.com|cdn.mouseflow.com',
                'desc' => 'Mouseflow session replay and heatmaps.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'Crazy Egg',
                'url' => 'crazyegg.com|script.crazyegg.com',
                'desc' => 'Crazy Egg heatmaps and A/B testing.',
                'cat' => 'analytics',
            ],
            [
                'name' => 'PostHog',
                'url' => 'posthog.com|app.posthog.com|us.posthog.com|eu.posthog.com',
                'desc' => 'PostHog product analytics and session replay.',
                'cat' => 'analytics',
            ],

            // ── Marketing / Advertising ──────────────────────────────────
            [
                'name' => 'Facebook Pixel',
                'url' => 'connect.facebook.net|facebook.com/tr',
                'desc' => 'Meta (Facebook) Pixel for conversion tracking and retargeting.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Facebook SDK',
                'url' => 'connect.facebook.net/en_US/sdk.js|connect.facebook.net/sdk.js',
                'desc' => 'Meta (Facebook) JavaScript SDK for social plugins and login.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Google Ads Conversion',
                'url' => 'googleads.g.doubleclick.net|googlesyndication.com|googleadservices.com|pagead2.googlesyndication.com',
                'desc' => 'Google Ads conversion tracking and remarketing.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Google DoubleClick',
                'url' => 'doubleclick.net|ad.doubleclick.net|cm.g.doubleclick.net',
                'desc' => 'Google DoubleClick / DV360 ad serving.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Twitter / X Pixel',
                'url' => 'static.ads-twitter.com|analytics.twitter.com|t.co/i/adsct',
                'desc' => 'Twitter / X conversion tracking pixel.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'LinkedIn Insight Tag',
                'url' => 'snap.licdn.com|linkedin.com/li.lms-analytics',
                'desc' => 'LinkedIn Insight Tag for conversion tracking.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Pinterest Tag',
                'url' => 's.pinimg.com/ct/core.js|ct.pinterest.com',
                'desc' => 'Pinterest conversion tracking tag.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'TikTok Pixel',
                'url' => 'analytics.tiktok.com|tiktok.com/i18n/pixel',
                'desc' => 'TikTok tracking pixel for ad conversion.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Snapchat Pixel',
                'url' => 'sc-static.net/scevent.min.js|tr.snapchat.com',
                'desc' => 'Snapchat conversion tracking pixel.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Reddit Pixel',
                'url' => 'alb.reddit.com/snoo.js|redditmedia.com',
                'desc' => 'Reddit conversion tracking pixel.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Microsoft Advertising / Bing UET',
                'url' => 'bat.bing.com|bat.r.msn.com',
                'desc' => 'Microsoft Advertising (Bing) Universal Event Tracking.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Criteo',
                'url' => 'static.criteo.net|dis.criteo.com|sslwidget.criteo.com',
                'desc' => 'Criteo retargeting and display ads.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Taboola',
                'url' => 'cdn.taboola.com|trc.taboola.com',
                'desc' => 'Taboola content recommendation and native advertising.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Outbrain',
                'url' => 'outbrain.com|widgets.outbrain.com',
                'desc' => 'Outbrain content recommendation and native advertising.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'AdRoll',
                'url' => 'd.adroll.com|s.adroll.com',
                'desc' => 'AdRoll retargeting and display advertising.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Amazon Ads',
                'url' => 'amazon-adsystem.com|aax.amazon-adsystem.com',
                'desc' => 'Amazon advertising pixel.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Quora Pixel',
                'url' => 'quora.com/_/ad|a.quora.com',
                'desc' => 'Quora conversion tracking pixel.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'HubSpot Tracking',
                'url' => 'js.hs-scripts.com|js.hsforms.net|js.hs-analytics.net|js.hs-banner.com|js.hubspot.com',
                'desc' => 'HubSpot marketing analytics and tracking code.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Marketo / Munchkin',
                'url' => 'munchkin.marketo.net',
                'desc' => 'Marketo Munchkin web tracking.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Pardot',
                'url' => 'pi.pardot.com|go.pardot.com',
                'desc' => 'Salesforce Pardot B2B marketing tracking.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'ActiveCampaign',
                'url' => 'trackcmp.net|actv.st',
                'desc' => 'ActiveCampaign email marketing tracking.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Mailchimp',
                'url' => 'chimpstatic.com|list-manage.com|mailchimp.com',
                'desc' => 'Mailchimp email marketing and pop-up forms.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Klaviyo',
                'url' => 'static.klaviyo.com|a.klaviyo.com',
                'desc' => 'Klaviyo e-commerce marketing automation tracking.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Google Publisher Tag (GPT)',
                'url' => 'securepubads.g.doubleclick.net|adservice.google.com',
                'desc' => 'Google Ad Manager / Publisher Tags for display ad serving.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Yandex Direct',
                'url' => 'an.yandex.ru|yandex.ru/ads',
                'desc' => 'Yandex Direct advertising.',
                'cat' => 'marketing',
            ],
            [
                'name' => 'Trade Desk',
                'url' => 'js.dstillery.com|match.adsrvr.org|insight.adsrvr.org',
                'desc' => 'The Trade Desk programmatic advertising.',
                'cat' => 'marketing',
            ],

            // ── Functional / Preferences ─────────────────────────────────
            [
                'name' => 'Google Maps',
                'url' => 'maps.googleapis.com|maps.google.com|maps.gstatic.com',
                'desc' => 'Google Maps embed and APIs.',
                'cat' => 'functional',
            ],
            [
                'name' => 'Google Fonts',
                'url' => 'fonts.googleapis.com|fonts.gstatic.com',
                'desc' => 'Google Fonts web font loading.',
                'cat' => 'functional',
            ],
            [
                'name' => 'Google reCAPTCHA',
                'url' => 'google.com/recaptcha|gstatic.com/recaptcha',
                'desc' => 'Google reCAPTCHA anti-bot verification.',
                'cat' => 'functional',
            ],
            [
                'name' => 'YouTube Embed',
                'url' => 'youtube.com/embed|youtube-nocookie.com/embed|ytimg.com',
                'desc' => 'YouTube embedded video player.',
                'cat' => 'functional',
            ],
            [
                'name' => 'Vimeo Embed',
                'url' => 'player.vimeo.com|vimeo.com/video',
                'desc' => 'Vimeo embedded video player.',
                'cat' => 'functional',
            ],
            [
                'name' => 'Intercom',
                'url' => 'widget.intercom.io|intercomcdn.com|js.intercomcdn.com',
                'desc' => 'Intercom live chat and customer messaging.',
                'cat' => 'functional',
            ],
            [
                'name' => 'Drift',
                'url' => 'js.driftt.com|drift.com',
                'desc' => 'Drift conversational marketing and live chat.',
                'cat' => 'functional',
            ],
            [
                'name' => 'Zendesk',
                'url' => 'static.zdassets.com|zopim.com|zendesk.com',
                'desc' => 'Zendesk customer support widget.',
                'cat' => 'functional',
            ],
            [
                'name' => 'Freshdesk / Freshchat',
                'url' => 'wchat.freshchat.com|assets.freshdesk.com',
                'desc' => 'Freshworks live chat and support widget.',
                'cat' => 'functional',
            ],
            [
                'name' => 'Tawk.to',
                'url' => 'embed.tawk.to',
                'desc' => 'Tawk.to live chat widget.',
                'cat' => 'functional',
            ],
            [
                'name' => 'Crisp Chat',
                'url' => 'client.crisp.chat',
                'desc' => 'Crisp live chat and messaging.',
                'cat' => 'functional',
            ],
            [
                'name' => 'LiveChat',
                'url' => 'cdn.livechatinc.com',
                'desc' => 'LiveChat customer communication widget.',
                'cat' => 'functional',
            ],
            [
                'name' => 'Calendly',
                'url' => 'assets.calendly.com|calendly.com/assets',
                'desc' => 'Calendly scheduling embed.',
                'cat' => 'functional',
            ],
            [
                'name' => 'Typeform',
                'url' => 'embed.typeform.com',
                'desc' => 'Typeform embedded forms.',
                'cat' => 'functional',
            ],
            [
                'name' => 'SoundCloud',
                'url' => 'w.soundcloud.com|api.soundcloud.com',
                'desc' => 'SoundCloud embedded audio player.',
                'cat' => 'functional',
            ],
            [
                'name' => 'Spotify Embed',
                'url' => 'open.spotify.com/embed',
                'desc' => 'Spotify embedded audio player.',
                'cat' => 'functional',
            ],
            [
                'name' => 'Instagram Embed',
                'url' => 'instagram.com/embed|cdninstagram.com',
                'desc' => 'Instagram embedded posts.',
                'cat' => 'marketing',
            ],

            // ── Performance ──────────────────────────────────────────────
            [
                'name' => 'Cloudflare Web Analytics',
                'url' => 'static.cloudflareinsights.com|cloudflareinsights.com/beacon',
                'desc' => 'Cloudflare Web Analytics beacon.',
                'cat' => 'performance',
            ],
            [
                'name' => 'New Relic',
                'url' => 'js-agent.newrelic.com|bam.nr-data.net',
                'desc' => 'New Relic browser monitoring agent.',
                'cat' => 'performance',
            ],
            [
                'name' => 'Datadog RUM',
                'url' => 'datadoghq.com|browser-intake-datadoghq.com',
                'desc' => 'Datadog Real User Monitoring.',
                'cat' => 'performance',
            ],
            [
                'name' => 'Sentry',
                'url' => 'browser.sentry-cdn.com|sentry.io',
                'desc' => 'Sentry error tracking and performance monitoring.',
                'cat' => 'performance',
            ],
            [
                'name' => 'Bugsnag',
                'url' => 'd2wy8f7a9ursnm.cloudfront.net/bugsnag|notify.bugsnag.com',
                'desc' => 'Bugsnag error monitoring.',
                'cat' => 'performance',
            ],
            [
                'name' => 'SpeedCurve LUX',
                'url' => 'cdn.speedcurve.com|lux.speedcurve.com',
                'desc' => 'SpeedCurve LUX real user monitoring.',
                'cat' => 'performance',
            ],
        ];

        foreach ($providers as $p) {
            $name = $this->escape($p['name']);
            $url = $this->escape($p['url']);
            $desc = $this->escape($p['desc']);
            $cat = $this->escape($p['cat']);

            $this->sql("
                INSERT IGNORE INTO oci_block_providers
                    (provider_name, provider_url, description, default_action, default_category)
                VALUES
                    ('{$name}', '{$url}', '{$desc}', 'block', '{$cat}')
            ");
        }
    }

    public function down(): void
    {
        $this->sql('DELETE FROM oci_block_providers WHERE default_category IS NOT NULL');
        $this->sql('ALTER TABLE oci_block_providers DROP COLUMN default_category');
    }

    private function escape(string $value): string
    {
        return addslashes($value);
    }
}
