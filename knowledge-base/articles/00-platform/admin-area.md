---
id: platform.admin
title: Admin area — platform administration (summary)
area: Platform
knowledgebase: Platform
url: /admin
menu_path: Admin
edition: [cloud, self-hosted]
audience: [admin]
plan: any
tags: [admin, platform-admin, users, plans, scan-servers, cookie-requests, audit-log, knowledge-base, transcripts]
related: [platform.roles, selfhost.scanner, cookies.list, account.billing]
source_files:
  - src/Modules/Admin/module.php
  - src/Modules/Admin/config/routes.php
  - src/Modules/Billing/config/routes.php
  - src/Modules/Blog/config/routes.php
  - src/Modules/Agency/config/routes.php
  - config/routes.php
questions:
  - What can a Conzent admin do?
  - Where do I approve cookie reclassification requests?
  - Where is the audit log?
  - How do I manage users as an admin?
  - What is the admin knowledge base page?
  - Where do I see chat transcripts?
  - How do I manage scan servers?
---

# Admin area — platform administration (summary)

## Where to find it

A single **Admin** entry pinned to the bottom of the sidebar, visible only to accounts with the
`admin` role. Hovering opens a flyout of the tools; clicking swaps in the full admin menu. On
Cloud it opens `/admin`; on a self-hosted install it opens **Backup and restore**.

> **Admin only.** These pages are not part of the end-user product. This article is a map, not a
> field-level reference — a support agent should recognise these names, not walk a customer
> through them.

## What it does

Platform operations: the users, plans, classification queues and infrastructure behind everyone's
sites. On self-hosted installs the person who ran the installer is the admin; on Cloud this is
Conzent staff only.

## Admin pages

| Page | URL | Edition | Purpose |
|---|---|---|---|
| **Dashboard** | `/admin` | Cloud | Platform overview |
| **Users** | `/admin/users` | Cloud | Create, edit, delete and act on user accounts; set roles |
| **Scan Servers** | `/admin/servers` | Cloud | Registered scanners, health and actions |
| **Cookie Requests** | `/admin/cookie-requests` | Cloud | Approve or reject customer reclassification requests for cookies |
| **Unclassified Cookies** | `/admin/unclassified-cookies` | Cloud | The global backlog. Lookup and bulk-apply classifications |
| **Beacon Requests** | `/admin/beacon-requests` | Cloud | Same queue, for third-party scripts and beacons |
| **Unclassified Beacons** | `/admin/unclassified-beacons` | Cloud | The beacon backlog, with lookup and apply |
| **Plans** | `/admin/plans` | Cloud | Plan definitions and limits |
| **Billing** | `/admin/billing` | Cloud | Subscriptions and payments across the platform |
| **News** | `/app/news` | Cloud | AI-generated privacy-news blog articles |
| **News Sources** | `/app/news/sources` | Cloud | RSS feeds the blog generator reads |
| **Knowledge Base** | `/admin/kb` | Cloud | The Elasticsearch-backed support KB the chat agent searches |
| **Chat Transcripts** | `/admin/transcripts` | Cloud | Support chat conversations |
| **Agencies** | `/admin/agencies` | Cloud | All agencies on the platform |
| **Backup and restore** | `/admin/backup` | Cloud and self-hosted | Take, download, upload and restore backups of the install |
| **Audit Log** | `/admin/audit-log` | Cloud and self-hosted | Admin actions, impersonation and manual consent-configuration changes |

A self-hosted install runs the open-source edition, which does not include the modules behind the
Cloud pages. Its admin menu shows **Backup and restore** and **Audit Log**. For the rest, see
Knowledgebase: Self-Hosting - Document: cli.md, "Managing a self-hosted install without the Admin
area".

## The classification queues (Cloud)

The four cookie and beacon queues are the operational heart of the Cloud admin area, and the
reason customers cannot simply reclassify an already-classified cookie themselves.

| Queue | Contents |
|---|---|
| **Cookie / Beacon Requests** | Customer-submitted "this is in the wrong category" requests, with the reason they gave |
| **Unclassified Cookies / Beacons** | Items no site has classified yet. A lookup tool suggests a category; apply propagates it globally |

Because classifications are central, approving one request improves every site that sees that
cookie. It also means a customer's request stays pending — shown as a **Change requested** tag on
their cookie list — until an admin acts.

## The Knowledge Base page (Cloud)

`/admin/kb` lists, creates, edits and deletes the articles the support chat agent retrieves from
Elasticsearch. It shows an "unavailable" state when Elasticsearch is not reachable, and supports
search plus a source filter.

The articles in `knowledge-base/articles/` are imported into that same index — see
`knowledge-base/elasticsearch/README.md`.

## Common questions

**A customer asks why their reclassification request has not been actioned.**
On Cloud, it is in the admin **Cookie Requests** queue awaiting review. Their site's category does
not change until it is approved. They can see the pending state on **Compliance → Cookies**.

**How do I make someone an admin?**
On Cloud, an existing admin sets the role under **Admin → Users**. On a self-hosted install, the
account the installer creates is the admin.

**Can an admin see customer data?**
Yes. Admins have full platform access, and admin actions are recorded in the audit log.

**What is in the audit log?**
Admin actions, agency impersonation sessions, and manual consent-configuration changes.

**Do self-hosted installs get all of these?**
No. A self-hosted install has **Backup and restore** and the **Audit Log**. The other pages come
from modules that are not part of the open-source edition.

## Related

- Knowledgebase: Platform - Document: roles.md — who gets the admin role
- Knowledgebase: Cookies - Document: cookies-list.md — the customer side of the classification queues
- Knowledgebase: Self-Hosting - Document: cli.md — managing a self-hosted install from the command line
- Knowledgebase: Self-Hosting - Document: scanner.md — scan server management
