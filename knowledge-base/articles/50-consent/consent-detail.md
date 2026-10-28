---
id: consent.detail
title: Consent Proof — a single consent record
area: Consent
knowledgebase: Consent
url: /consents/{id}
menu_path: General > Consent Logs > (click a row)
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [consent-proof, consent-record, evidence, tcf-string, gcm, category-breakdown, ip, pseudonym, dsar, audit]
related: [consent.logs, compliance.tcf, compliance.gcm, growth.abtest]
source_files:
  - templates/pages/consent/detail.html.twig
  - src/Consent/Controller/ConsentDetailHandler.php
questions:
  - How do I prove a specific visitor gave consent?
  - What is stored in a consent record?
  - Where do I find the TCF string for a consent?
  - What does the category breakdown show?
  - A regulator asked for consent evidence
  - What is the A/B variant field on a consent record?
  - Does Conzent store IP addresses?
---

# Consent Proof — a single consent record

## Where to find it

Click any row in **General → Consent Logs**. URL: `/consents/{id}`.

## What it does

The full evidence for one consent decision. This is the page you open when a regulator, a DPO or
a data-subject request asks you to demonstrate that consent was validly obtained.

## Consent Details

| Field | What it proves |
|---|---|
| **Session** | The visitor session the consent belongs to |
| **Status** | Accepted, Rejected or Partial |
| **Server Time** | When Conzent recorded it — the authoritative timestamp |
| **User Local Time** | The visitor's own clock at the moment of consent |
| **Domain** | Which domain it was given on. Distinguishes associated domains |
| **IP Pseudonym** | A keyed hash of the visitor's IP — the raw address is not stored |
| **Country** | Resolved from the IP at recording time. Establishes which framework applied |
| **Language** | The language the banner was shown in — evidence the visitor could read it |
| **A/B Variant** | Which experiment variant was displayed, when a split test was running |

Server time plus user local time together answer the awkward question of *when* consent happened
across time zones.

## Category Breakdown

A row per cookie category with the visitor's decision:

| Column | Meaning |
|---|---|
| **Category** | Necessary, Functional, Analytics, Marketing, or a custom category |
| **Consent** | Whether that specific category was accepted |

This is what makes "partial" consent auditable — it shows exactly which purposes were agreed to.

## IAB TCF Data

Present when TCF was enabled at the time. Contains the TC string issued for this consent — the
encoded payload IAB vendors read, carrying your CMP ID, the vendor list version, and the
per-purpose and per-vendor decisions. Paste it into any TCF decoder to read it back.

Absent when TCF was off. See Knowledgebase: Compliance - Document: iab-tcf.md.

## Google Consent Mode Data

The Consent Mode v2 state sent to Google for this visitor — `ad_storage`, `analytics_storage`,
`ad_user_data`, `ad_personalization` and the rest, each granted or denied. Evidence that the
choice actually reached Google's tags. See Knowledgebase: Compliance - Document: google-consent-mode.md.

## How to answer an evidence request

1. **General → Consent Logs**, with the right site selected.
2. Filter by date range and, if you have it, status.
3. Open the record.
4. Capture the page — Consent Details, Category Breakdown, and the TCF/GCM blocks if present.
5. For a broader request, generate a **Consent Report** for the period instead of individual
   records. See Knowledgebase: Consent - Document: reports.md.

## Common questions

**Does Conzent store IP addresses?**
No — not the raw address. The record carries an **IP pseudonym**: a keyed hash computed at the
moment of recording. The same visitor produces the same pseudonym, so records correlate for
proof, but the address itself cannot be read back. The country was resolved before hashing.
Disclose the consent log in your own privacy policy regardless; the privacy policy wizard
covers it.

**How do I look up one specific visitor?**
There is no per-person search — consent is not linked to an account. Narrow by date range,
country and status in Consent Logs, and match on the session identifier if you have it from your
own systems.

**Why is the TCF section missing?**
TCF was not enabled when this consent was recorded, or the site never had it on. Records are not
retro-filled.

**What is the A/B Variant field?**
When a split test is running, this records which banner variant the visitor saw. It is how the
experiment attributes consent outcomes to variants, and it also means you can show which exact
banner wording a given visitor was shown.

**Can I delete a single consent record?**
Not from this page. Records are retained as the audit trail. Permanently removing the site
removes all of them.

**The user local time and server time differ by hours.**
Expected — the visitor was in a different time zone, or their device clock is off. Server time is
the authoritative one.

## Related

- Knowledgebase: Consent - Document: consent-logs.md — finding the record
- Knowledgebase: Consent - Document: reports.md — bulk evidence for a period
- Knowledgebase: Compliance - Document: iab-tcf.md — reading the TC string
- Knowledgebase: Compliance - Document: google-consent-mode.md — the Consent Mode signals
