---
id: compliance.checklist
title: Compliance Checklist
area: Compliance
knowledgebase: Compliance
url: /compliance
menu_path: Configuration > Compliance Checklist
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [checklist, compliance-score, gdpr, ccpa, regulations, us-states, progress, tasks, audit]
related: [sites.frameworks, dashboard.customer, policies.overview, consent.reports]
source_files:
  - templates/pages/compliance/checklist.html.twig
  - src/Compliance/Controller/ComplianceChecklistHandler.php
  - src/Compliance/Controller/ComplianceChecklistToggleHandler.php
  - config/conzent-compliance-checklists.json
questions:
  - What is the compliance checklist?
  - How do I improve my compliance score?
  - Which regulations does the checklist cover?
  - Does ticking a checklist item change my banner?
  - Is the checklist per site or per account?
  - Where do I check CCPA compliance?
  - Does Conzent check these automatically?
---

# Compliance Checklist

## Where to find it

**Configuration → Compliance Checklist** in the sidebar. URL: `/compliance`.

## What it does

A per-regulation task list covering the parts of compliance that software cannot verify for you —
your policies, your internal processes, your vendor contracts. You tick items off; Conzent tracks
the score.

This is deliberately **manual**. The automatic checks live on the dashboard's compliance score
and the framework warnings. The checklist is the human half.

## Page layout

**Category tabs** across the top, and a two-column body:

- **Left** — the selected regulation's checklist, with a progress bar, a "N% Complete" tag and a
  "X of Y items completed" count.
- **Right** — the regulation list for the active category, each with its own score and a slim
  progress bar. Click one to switch.

Ticking an item saves immediately — a spinner then a green check appears on the row, and the
score and progress bar update without a page reload.

## Categories and coverage

| Category | Regulations |
|---|---|
| **Europe & International** | GDPR, ePrivacy, DMA, EU AI Act, UK GDPR, FADP (Switzerland), LGPD, POPIA, PIPEDA, PIPL, DPDP (India), APPI, PIPA (Korea), PDPA Thailand, PDP Indonesia, PDPA Singapore, PDPA Malaysia, Privacy Act Australia, UAE PDPL, Vietnam PDPL, plus TCF 2.3, Google Consent Mode, Amazon Consent Signal, Microsoft UET Consent Mode and Microsoft Clarity Consent Mode |
| **United States** | CCPA, CPRA, and the state laws: Colorado CPA, Connecticut CTDPA, Virginia VCDPA, Utah UCPA, Montana MTCDPA, Oregon OCPA, Texas TDPSA, Florida FDBR, Iowa ICDPA, Delaware DPDPA, New Hampshire NHDPA, New Jersey NJDPA, Nebraska NDPA, Tennessee TIPA, Minnesota MNCDPA, Maryland MODPA, Indiana INCDPA, Kentucky KCDPA, Rhode Island RIDTPPA |

Note this is broader than the **Privacy Frameworks** list. Frameworks are the ones Conzent
actively enforces in the banner; the checklist covers the wider obligations each law places on
you as an organisation.

## Score colours

| Score | Colour |
|---|---|
| 80–100% | Green |
| 50–79% | Amber |
| 0–49% | Red |

Applied to both the tag and the progress bars.

## How to work through it

1. Pick the category, then the regulation that actually applies to you — start with the ones you
   enabled under **Compliance → Privacy Frameworks**.
2. Read each item and tick only what is genuinely true. The score is for your own tracking; an
   inflated one helps nobody.
3. Items that point at Conzent features link back to the page that configures them.
4. Move to the next regulation. Each keeps its own independent score.

## Common questions

**Does ticking an item change my banner?**
No. The checklist records what *you* have done. Banner behaviour comes from **Privacy
Frameworks** and the banner settings.

**Is this the same as the dashboard's compliance score?**
No, and they are worth keeping apart. The dashboard score is computed automatically from your
configuration — script installed, frameworks satisfied, signals firing. The checklist score is
whatever you have ticked here.

**Is the checklist per site or per account?**
Per account. It records your organisation's compliance posture, not one website's configuration.

**Why are there regulations here that are not in Privacy Frameworks?**
The framework list is what the banner enforces technically. The checklist covers the full legal
obligation — records of processing, DPO appointment, vendor contracts, breach procedures — which
no CMP can do for you.

**Can I export the checklist?**
Not directly. Generate a **Full Compliance** report under **Compliance → Reports** for the
technical evidence, and screenshot the checklist for the procedural side.

**Do I have to complete every regulation?**
No. Only the ones that apply to your business and your visitors. Twenty-plus US state laws are
listed so that whichever ones apply to you are there — not because you need them all.

**Does Conzent verify any of this automatically?**
Not here. Automatic verification is on the dashboard (**Run Verification** and the compliance
gauge) and in the framework warnings.

## Related

- Knowledgebase: Sites - Document: frameworks.md — what the banner enforces
- Knowledgebase: Account - Document: dashboard-customer.md — the automatic score
- Knowledgebase: Compliance - Document: policies-overview.md — generating the policies items refer to
- Knowledgebase: Consent - Document: reports.md — evidence for an auditor
