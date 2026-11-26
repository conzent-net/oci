---
id: consent.logs
title: Consent Logs — the audit trail
area: Consent
knowledgebase: Consent
url: /consents
menu_path: General > Consent Logs
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [consent-logs, audit-trail, accepted, rejected, partial, consent-rate, filter, proof, analytics, trend, csv, export, pseudonym, retention, gdpr]
related: [consent.detail, consent.reports, growth.impact, dashboard.customer]
source_files:
  - templates/pages/consent/list.html.twig
  - templates/pages/consent/_consent_table.html.twig
  - src/Consent/Controller/ConsentListHandler.php
  - src/Consent/Controller/ConsentAnalyticsHandler.php
questions:
  - Where can I see who consented to cookies?
  - How do I prove a visitor consented?
  - What is my consent rate?
  - What does "partial" consent mean?
  - How do I filter consent logs by date?
  - Is my consent rate good or bad?
  - How long are consent records kept?
  - Can I export my consent logs?
---

# Consent Logs — the audit trail

## Where to find it

**General → Consent Logs** in the sidebar. URL: `/consents`. A single record opens at
`/consents/{id}`.

## What it does

Every consent decision made on the selected site, with the evidence needed to demonstrate lawful
consent to a regulator: what was chosen, when, from where, and in what form. The top of the page
turns the same data into rates and a trend chart.

## Analytics cards

Three cards showing the share of each outcome over the selected period, each with the change
against the previous period of equal length.

| Card | Meaning |
|---|---|
| **Accepted** | Consented to everything |
| **Rejected** | Refused everything non-essential |
| **Partial** | Accepted some categories and refused others |

An up arrow on Rejected or Partial is shown in red, since more refusals is not an improvement.
The period selector in the header offers **Last 7 / 30 / 90 days** and updates both the cards and
the chart.

## Consent Trend chart

A stacked area chart of Accepted, Rejected and Partial per day across the selected period.

## Filters

| Filter | Options |
|---|---|
| Status | All statuses / Accepted / Rejected / Partial |
| Date from → Date to | Two date pickers for an arbitrary range |
| Site selector | Which site's records to show |
| **Export CSV** | Downloads the currently filtered records as a CSV file — status, dates, session, pseudonymized IP, country, language, domain, TCF and GCM data, per-category breakdown |

The record count and the "Showing X–Y of Z" line update with the filters. Filters are held in
the URL, so a filtered view can be bookmarked or shared — and **Export CSV** respects the same
filters, so a supervisory-authority request for a period is a two-click job.

## Table columns

| Column | Meaning |
|---|---|
| Consent ID | Unique record ID. Opens the consent proof view |
| Status | Accepted / Rejected / Partial |
| Date | When consent was recorded |
| Country | Resolved from the visitor's IP |
| Domain | Which domain the consent was given on — useful with associated domains |
| Language | The language the banner was shown in |

Click any row to open the full record. See Knowledgebase: Consent - Document: consent-detail.md.

## Reading your consent rate

There is no universal target. Useful reference points:

| Rate | Reading |
|---|---|
| Below 40% accepted | Low. Often a design problem — the banner is easy to dismiss, the value exchange is not explained, or Reject is far more prominent than Accept |
| 50–70% | Typical for a compliant opt-in banner in the EU |
| Above 85% | Check for a dark pattern. If Reject is hidden, harder to reach, or visually weaker, regulators treat the consent as invalid |

If you want to improve the rate legitimately, test variants rather than guess — see
Knowledgebase: Growth - Document: ab-tests.md — and read the financial side in
Knowledgebase: Growth - Document: revenue-impact.md.

## Common questions

**How do I prove someone consented?**
Open the record. The consent proof view shows session, status, server time, the visitor's local
time, domain, IP, country, language, the per-category breakdown and — when enabled — the IAB TCF
string and Google Consent Mode state. That is the evidence pack regulators ask for.

**Can I export the logs?**
Yes — **Export CSV** in the filter bar downloads exactly what the current filters show,
including the per-category breakdown and TCF/GCM data per record. For a formatted document
instead, generate a **Consent Report** under **Compliance → Reports**. See
Knowledgebase: Consent - Document: reports.md.

**How long are records kept?**
For a configurable retention window — 3 years by default — after which old records are purged
automatically (self-hosted installs set `CONSENT_LOG_RETENTION_DAYS`; 0 disables purging).
Deleting a site permanently destroys its consent records immediately — export first if you need
them.

**What counts as "partial"?**
The visitor opened the preference centre and enabled some categories but not all. It usually
means analytics was accepted and marketing refused.

**My log is empty.**
Either the script is not installed or reachable, the site is disabled or suspended, geo targeting
is excluding your visitors, or nobody has interacted with the banner yet. Run **Run
Verification** from the dashboard.

**Does this contain personal data?**
The records are **pseudonymous**: the visitor's IP is replaced by a keyed hash at the moment of
recording — the raw address is never stored — alongside country, session identifier, timestamps
and the consent choices. That is the industry-standard evidence bundle: enough correlation to
prove the consent, no raw identifier lying in the database. Mention the consent log in your own
privacy policy regardless.

**Does "Renew User Consents" wipe the log?**
No. It invalidates stored consent in visitors' browsers so they are asked again; the historical
records stay.

## Related

- Knowledgebase: Consent - Document: consent-detail.md — a single consent record
- Knowledgebase: Consent - Document: reports.md — exporting and scheduling
- Knowledgebase: Growth - Document: revenue-impact.md — what the rate costs you
- Knowledgebase: Growth - Document: ab-tests.md — improving the rate
