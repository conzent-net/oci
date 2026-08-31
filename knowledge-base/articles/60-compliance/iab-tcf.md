---
id: compliance.tcf
title: IAB TCF v2.4 and Google Additional Consent
area: Compliance
knowledgebase: Compliance
url: /banners
menu_path: Configuration > Banners > General Settings > IAB TCF v2.4 Support
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: paid
tags: [tcf, iab, cmp-id, tc-string, gvl, vendor-list, additional-consent, programmatic, advertising, publisher]
related: [banner.general, platform.editions, consent.detail, compliance.gcm]
source_files:
  - templates/pages/banners/index.html.twig
  - src/Compliance/
  - .env.example
  - README.md
questions:
  - How do I enable IAB TCF?
  - Why is the TCF toggle locked?
  - What is a CMP ID and how do I get one?
  - Do I need TCF?
  - What is the Global Vendor List?
  - What is Google Additional Consent?
  - What TCF version does Conzent support?
  - Can I use TCF on a self-hosted install?
---

# IAB TCF v2.4 and Google Additional Consent

## Where to find it

**Configuration → Banners → General Settings → IAB TCF v2.4 Support**. When it is on, a second
toggle appears: **Google Additional Consent**.

Two dashboard templates also bundle TCF: **GCM Basic + TCF** and **GCM Advanced + TCF**.

## What it does

The IAB Transparency & Consent Framework is the advertising industry's standard for passing
consent to programmatic ad vendors. With TCF on, Conzent shows the IAB purpose and vendor
disclosures in the preference centre and publishes a **TC string** that every TCF-compliant
vendor on your page reads.

Conzent supports **TCF v2.4** (and the v2.2 / v2.3 strings still in circulation).

## Do you need it?

| You | TCF? |
|---|---|
| Run programmatic display advertising through IAB vendors, an SSP or an ad exchange | Yes — vendors will not bid without a valid TC string |
| Use Google AdSense / Ad Manager and want full EEA monetisation | Yes — Google requires a certified CMP for EEA and UK traffic |
| Only run Google Analytics, Ads conversion tracking, Meta or Microsoft pixels | No — Google Consent Mode v2 is enough |
| Have no advertising at all | No |

TCF adds meaningful complexity to the banner: hundreds of vendors, ten purposes and the
legitimate-interest disclosures all have to be presented. Do not enable it "just in case" — it
lowers consent rates and creates obligations you must then meet.

## CMP ID — the gate

TCF requires a **CMP ID** issued by IAB Europe to a registered CMP. That ID is encoded into every
TC string you issue, so it must be your own.

| Edition | How TCF is enabled |
|---|---|
| **Cloud** | Conzent is registered as **CMP ID 401**. Enable the toggle on a paid plan |
| **Self-hosted** | Register with IAB Europe yourself, then set `CMP_ID` in your environment to your assigned ID. Set `CMP_VERSION` and bump it when you materially change your consent UI |

Until `CMP_ID` is a valid registered ID, the toggle shows a padlock and explains this. You cannot
reuse another CMP's ID — the TC strings would be attributed to them, and IAB audits it.

Setting `CMP_ID` is also what flips the install into Cloud Edition behaviour generally. See
Knowledgebase: Platform - Document: editions.md.

## The Global Vendor List

The GVL is IAB's registry of vendors, purposes and features, and it drives every disclosure the
banner shows. Conzent refreshes it daily between 06:00 and 08:00.

| Setting | Purpose | Default |
|---|---|---|
| `GVL_AUTO_UPDATE` | Refresh the list daily. Set false to pin the shipped list (air-gapped setups) | `true` |
| `GVL_SOURCE_URL` | Where to fetch it. Change only for a mirror | `https://vendor-list.consensu.org/v3/` |
| `GVL_LANGUAGES` | Comma-separated TCF language codes to cache. Empty caches all 36 (~1 MB per refresh) | all |
| `GVL_UPDATE_ATP` | Also refresh Google's Additional Consent provider list | `true` |
| `GVL_ARCHIVE_KEEP` | How many historical vendor-list versions to retain | `10` |

Keeping the GVL current is an IAB expectation, not an optimisation — stale vendor lists mean
vendors you disclose no longer match the ones on the list.

## Google Additional Consent

Only visible when TCF is on. TCF covers vendors on the IAB Global Vendor List; many Google ad
tech providers are not on it. Additional Consent signals those separately, as an **AC string**
alongside the TC string.

Turn it on if you monetise with Google ad products in the EEA or UK. It has no effect without
TCF.

## Verifying it works

- **Dashboard → Run Verification** includes an **IAB TCF v2.4** check.
- Open any record in **Consent Logs**; the **IAB TCF Data** section shows the TC string issued.
- Decode a TC string in any public TCF decoder to confirm your CMP ID and vendor decisions.
- The Conzent browser extension shows the live TCF and Consent Mode state on any page. See
  Knowledgebase: Plugins - Document: browser-extension.md.

## Common questions

**Why is the toggle greyed out with a padlock?**
No valid `CMP_ID` on the server. On Cloud that means your plan does not include TCF; on
self-hosted it means you have not registered with IAB Europe and set the ID.

**How do I get a CMP ID?**
Apply for IAB Europe CMP membership. It involves a fee, a compliance commitment and a technical
validation. Only worth it if you genuinely operate a CMP or self-host at scale.

**What happens to existing consents when I enable TCF?**
They stay in the log, but records from before TCF was on carry no TC string. Consider **Renew
User Consents** in Banner Advanced Settings so visitors re-consent under the TCF disclosures.

**Does TCF replace Google Consent Mode?**
No. They are complementary and usually run together — that is why the dashboard offers combined
templates. Consent Mode talks to Google tags; TCF talks to IAB vendors.

**Will TCF lower my consent rate?**
Usually a little, because the disclosures are longer and more detailed. That is the trade for
programmatic monetisation.

## Related

- Knowledgebase: Banner - Document: banner-general.md — the toggles
- Knowledgebase: Compliance - Document: google-consent-mode.md — Google Consent Mode v2
- Knowledgebase: Consent - Document: consent-detail.md — reading a TC string
- Knowledgebase: Platform - Document: editions.md — CMP_ID and edition behaviour
