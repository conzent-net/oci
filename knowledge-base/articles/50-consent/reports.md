---
id: consent.reports
title: Reports — generating and scheduling compliance reports
area: Consent
knowledgebase: Consent
url: /reports
menu_path: Compliance > Reports
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [reports, compliance-report, consent-report, scan-report, schedule, email, export, download, pdf]
related: [consent.logs, cookies.scans, compliance.checklist, account.notifications]
source_files:
  - templates/pages/reports/index.html.twig
  - templates/pages/reports/_report_table.html.twig
  - src/Report/Controller/ReportListHandler.php
  - src/Report/Controller/ReportGenerateHandler.php
  - src/Report/Controller/ReportScheduleHandler.php
  - src/Report/Controller/ReportSendHandler.php
  - src/Report/Controller/ReportDeleteHandler.php
  - templates/emails/report.html.twig
questions:
  - How do I export my consent data?
  - How do I generate a compliance report?
  - Can Conzent email me a monthly report?
  - What is in a full compliance report?
  - How do I send a report to my DPO?
  - Where do I download a report?
  - My scheduled report never arrived
---

# Reports — generating and scheduling compliance reports

## Where to find it

**Compliance → Reports** in the sidebar. URL: `/reports`. A report opens at `/reports/{id}`.

## What it does

Packages a period's consent and cookie data into a document you can download, email, or hand to
a DPO, auditor or client. Reports can be generated on demand or delivered monthly by email.

## Report types

| Type | Contents |
|---|---|
| **Full Compliance** | Consent statistics and cookie scan findings together, plus the site's compliance posture. The one to send an auditor or a client |
| **Consent** | Consent records and rates for the period only |
| **Cookie Scan** | The latest scan's cookies and third-party scripts only |

Scan and Full Compliance reports also include a **Cookie register changes** section listing
what the period's scans added, removed or recategorised — the same data as the timeline at
`/cookies/changes`. See Knowledgebase: Cookies - Document: register-changes.md.

## Generate Report modal

| Field | Options | Notes |
|---|---|---|
| **Report Type** | Full Compliance / Consent / Cookie Scan | — |
| **Date Range** | Last 30 days / Last month / Last quarter / Custom range | Presets fill the dates for you |
| **Start Date** | Date picker | Only when Date Range is Custom |
| **End Date** | Date picker | Only when Date Range is Custom |

**Last month** means the previous calendar month — the right choice for a monthly compliance
pack, since "last 30 days" straddles two months.

## Scheduled Reports card

A single schedule per site, toggled on or off at the top of the page.

| Field | Options | Notes |
|---|---|---|
| **Enabled / Disabled** | Toggle | The rest of the card is hidden when off |
| **Report Type** | Full Compliance / Consent Only / Cookie Scan Only | — |
| **Frequency** | Monthly (1st of month) | The only option currently |
| **Email Recipient** | Email address | Where the report is delivered |

**Save Schedule** commits it.

## Reports table

| Column | Meaning |
|---|---|
| Title | Report name, generated from type and period |
| Type | Full / Consent / Scan |
| Period | The date range covered |
| Status | Generating, ready, or failed |
| Created | When it was generated |
| Actions | View, Send via email, Delete |

## Send modal

| Field | Notes |
|---|---|
| **Recipient Email** | Leave blank to fall back to the schedule email, then your account email |

## Delete

A confirmation modal. Deleting removes the generated document; the underlying consent and scan
data is untouched, so you can regenerate the same period.

## How to

**Send a monthly pack to a client** — turn on Scheduled Reports, set Report Type to Full
Compliance, put the client's address in Email Recipient, and Save Schedule. It goes out on the
1st of each month.

**Produce evidence for an audit** — Generate Report → Full Compliance → Custom range covering the
audited period → Generate. Open it from the table when its status is ready.

**Send an existing report to someone else** — the Send action on its row, with their address.

## Common questions

**How do I export raw consent logs?**
Use **Export CSV** on the Consent Logs page — it downloads the currently filtered records
directly. Generate a **Consent** report instead when you want the same period packaged as a
formatted document. See Knowledgebase: Consent - Document: consent-logs.md.

**My scheduled report never arrived.**
Check the recipient address on the Scheduled Reports card and your spam folder. On self-hosted
installs, mail requires `MAIL_HOST` in `.env` — an empty value means outbound mail is silently
skipped. Test with `php bin/oci test:email`.

**Can I schedule more than one report per site?**
One schedule per site. For several recipients, use one schedule and forward, or generate
manually and use Send for each address.

**Can I schedule weekly or daily?**
Monthly is the only frequency. Generate on demand for anything else.

**Is the report per site or for my whole account?**
Per site — the site selector at the top of the page controls which one. Agencies wanting an
account-level view should use the agency dashboard.

**Does a report include the cookie policy?**
No. Policies are generated separately under **Compliance → Policies** and published on your
website. A Full Compliance report covers consent statistics and scan findings.

**A report is stuck at "generating".**
Large periods take longer. If it does not resolve, delete it and regenerate with a narrower date
range.

## Related

- Knowledgebase: Consent - Document: consent-logs.md — the data behind consent reports
- Knowledgebase: Cookies - Document: scans.md — the data behind scan reports
- Knowledgebase: Compliance - Document: checklist.md — the manual side of compliance
