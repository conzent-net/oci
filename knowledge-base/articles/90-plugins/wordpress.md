---
id: plugins.wordpress
title: WordPress plugin
area: Plugins
knowledgebase: Plugins
url: /sites
menu_path: (WordPress admin) GetConzent CMP > Settings
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [wordpress, wp, plugin, cookie-banner, shortcode, consent-api, site-kit, install, website-key, automatic-setup, mainwp, wp-cli]
related: [plugins.overview, sites.install-script, banner.content, policies.overview]
source_files:
  - plugins/getconzent_wp/readme.txt
  - plugins/getconzent_wp/conzent.php
  - src/Site/Service/SiteClaimService.php
questions:
  - How do I install Conzent on WordPress?
  - Where do I enter my website key in WordPress?
  - Does the WordPress plugin find the website key by itself?
  - How do I install Conzent on many WordPress sites at once?
  - Does Conzent work with WordPress Consent API?
  - How do I show the cookie list on a WordPress page?
  - Does the WordPress plugin work with self-hosted Conzent?
  - Is the plugin compatible with Google Site Kit?
  - The banner is not showing on my WordPress site
---

# WordPress plugin

## Where to find it

**WordPress admin → GetConzent CMP → Settings.** Install from the WordPress plugin directory by
searching for "Conzent Cookie Banner".

## What it does

Injects the Conzent consent script into every page of your WordPress site. Since plugin 2.2.0 it
fetches the Website Key itself after activation when the site already exists in your Conzent
account (see Automatic setup below); before that, or when you prefer, you paste the key on the
settings page and the plugin verifies it on save. All banner configuration stays in the Conzent
app — the plugin only connects the two.

## Settings fields

| Field | Required | What it does |
|---|---|---|
| **Website Key** | Yes | From **General → Sites** in Conzent, the Website Key column |
| **Server URL** | No | Leave empty for Conzent Cloud. Set it to your own installation for self-hosted OCI, e.g. `https://consent.example.com` |

On save the plugin verifies the key and reports one of:

- *Settings saved and website verified.*
- *Settings saved. Could not verify website key — the banner will still load.*
- *Settings saved.*

The middle message means the browser-side script will work; only the server-to-server
verification call failed, usually because your host blocks outbound requests.

## Requirements

| | |
|---|---|
| WordPress | 5.8 or later, tested to 7.0.3 |
| PHP | 7.4 or later |
| Licence | GPLv3 |

## Installing

**Create the site in Conzent first** (General → Sites, the domain is all that is required). Then:

**From the directory** — Plugins → Add New Plugin → search "Conzent Cookie Banner" → Install Now →
Activate. The plugin fetches the Website Key for your domain by itself within a few minutes; if it
cannot, open **GetConzent CMP → Settings**, paste the Website Key and Save.

**Manually** — download the ZIP, Plugins → Add New Plugin → Upload Plugin → Install Now →
Activate, or upload the extracted folder to `/wp-content/plugins/` by FTP.

**Many sites at once** — MainWP, ManageWP, WP Umbrella and similar tools can install and activate
the plugin across every site you manage in one action; each site then picks up its own key. With
WP-CLI, `wp plugin install getconzent-cmp-cookie-banner-consent-management --activate` returns
with the result printed, and `wp conzent status` shows the state of a site.

## Automatic setup

After activation the plugin asks Conzent for the Website Key of the site's domain (the address
visitors use, so `home_url()`, not the WordPress install path). To prove that the plugin really
runs on that domain, it publishes a random one-time token at `https://your-domain/?conzent_claim=1`
while the request is pending; the Conzent server reads the token back before it answers. Nothing is
ever created from WordPress: a domain with no site in Conzent is told so.

The order does not matter. Until the site has a key the plugin keeps checking on its own, every
5 minutes for the first hour, every 30 minutes for the rest of the day, then hourly, for as long
as it takes. Create the site in Conzent after installing the plugin and it configures itself on
the next check; **Retry now** on the notice or the settings page skips the wait.

What you see:

- **Configured automatically for example.com on <date>** on the settings page: done, nothing to do.
- **No site found**: create the site in Conzent; the plugin picks it up on the next check.
- **More than one site matches**: the domain exists twice in Conzent (typically once with and once
  without `www.`); remove the duplicate, or paste the Website Key of the right one.
- **The site is suspended** (plan limit or no subscription): resolve it in Conzent; the plugin picks
  it up on the next check.
- **Could not reach your site** or **wrong response**: a coming-soon plugin, HTTP authentication, a
  firewall, or a page cache is answering `?conzent_claim=1` instead of the plugin. Allow that
  address or purge the cache; the next check succeeds.

Rules worth knowing:

- Installs that already hold a Website Key are never touched. A key typed in by hand switches
  automatic setup off for that site until you choose **Configure automatically**; clearing the key
  switches it back on.
- Multisite: every sub-site claims its own domain on its first request after network activation.
- `wp-config.php` constants: `CONZENT_SERVER_URL` pins the server for self-hosted installs (the
  Server URL field becomes read-only); `CONZENT_AUTO_SETUP` set to `false` switches automatic setup
  off.
- Hosts that block outbound HTTP (`WP_HTTP_BLOCK_EXTERNAL`) get a notice naming the host to allow;
  the manual path still works.

## What it integrates with

| Integration | Notes |
|---|---|
| **WordPress Consent API** | Supported since plugin 1.0.11. Other Consent-API-aware plugins read Conzent's consent state and behave accordingly |
| **Google Site Kit** | Supported since 2.0.9 |
| Cookie only | The plugin sets one cookie, `conzentConsent`, holding the visitor's own preferences. No personal data |

## Showing the cookie list on a page

When the site is connected to the Conzent app, use the **HTML embed** rather than the plugin's
legacy shortcode:

```html
<div class="cnz-cookie-policy"></div>
```

Paste it into a page or a Custom HTML block. Get it from
**Banners → Content Settings → Cookie List → Embed Code** or from
**Compliance → Policies → Embed Codes**.

## Data sent to Conzent

Your domain name, the plugin version, a random one-time token during automatic setup, and the
Website Key you enter. No visitor personal data is sent by the plugin itself; consent records are
written by the script, as described in Knowledgebase: Consent - Document: consent-logs.md.

## Common questions

**The banner is not showing.**
Check, in order: the Website Key matches the one on `/sites`; the site is Active, not Disabled or
Suspended; a caching plugin or CDN is not serving stale HTML (purge it); geo targeting in
**Banners → General Settings** is not excluding you; and you have not already consented in that
browser — try a private window.

**"Could not verify website key" but the banner works.**
Expected on hosts that block outbound HTTP. The script loads in the visitor's browser regardless.

**Does it conflict with other cookie plugins?**
Yes, if another consent plugin is active. Two CMPs will both try to block and both show a banner.
Deactivate the other one.

**Does it work with WP Rocket / W3 Total Cache / Cloudflare?**
Yes, but purge the cache after changing settings in the Conzent app — cached HTML can hold the old
script reference. Also use **Banners → Advanced Settings → Purge & Regenerate**.

**Which languages does the plugin interface support?**
The plugin ships with translation files; banner text is translated in the Conzent app under
**Banners → Banner Content & Translations**.

**Where do I change colours and text?**
In the Conzent app, not WordPress. The plugin has only the two fields above.

**Does installing this make my site GDPR compliant?**
No — as the plugin's own readme states. Every site uses different cookies; you still need to
scan, classify and configure the banner appropriately.

## Related

- Knowledgebase: Plugins - Document: overview.md — all platforms
- Knowledgebase: Sites - Document: install-script.md — the manual alternative
- Knowledgebase: Banner - Document: banner-content.md — the cookie list embed code
- Knowledgebase: Compliance - Document: policies-overview.md — publishing policies
