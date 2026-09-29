---
id: account.notifications
title: Notifications and the guided tour
area: Account
knowledgebase: Account
url: /
menu_path: Top bar > Bell icon
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [notifications, announcements, bell, unread, tour, onboarding, help, dark-mode]
related: [platform.navigation, dashboard.customer]
source_files:
  - templates/components/navbar.html.twig
  - src/Notification/Controller/NotificationListHandler.php
  - src/Notification/Controller/NotificationMarkReadHandler.php
  - src/Notification/Controller/NotificationMarkAllReadHandler.php
  - notifications/
questions:
  - What is the bell icon in the top bar?
  - How do I mark all notifications as read?
  - Where do I see product announcements?
  - How do I replay the guided tour?
  - How do I turn on dark mode?
  - Can I turn off notifications?
---

# Notifications and the guided tour

## Where to find it

The top bar, on every page. Three controls sit to the right of the sidebar toggle: the guided
tour, the theme switch and the notification bell.

## What it does

The bell delivers product announcements — new features, changes to privacy regulations that
affect your setup, and maintenance notices. The tour icon replays the walkthrough for whichever
page you are on. The theme switch flips the app between light and dark.

## Controls

| Control | Icon | What it does |
|---|---|---|
| Sidebar toggle | ☰ | Collapses the sidebar to give the page more width |
| Guided tour | Route/path | Replays the step-by-step walkthrough for the current page |
| Theme | Moon / Sun | Switches the **app** between light and dark mode. Your choice is remembered |
| Notifications | Bell | Opens the announcement list. A badge shows the unread count |
| Account menu | Your name | Profile, Billing (Cloud), Admin (admins), Logout |

## The notification panel

Each row shows a title, a date and a short excerpt. Clicking a row opens the full announcement
in a modal and marks it read. **Mark all as read** clears the badge in one action. When there is
nothing to show, the panel displays an empty state.

Notifications are broadcast by Conzent — they are not per-site alerts and there is nothing to
configure.

## The guided tour

The tour uses in-page callouts to point at the controls that matter on the current screen. It
runs automatically the first time you reach a page that has one, and can be replayed from the
route icon. Pages with a tour include the dashboard and banner settings.

## Emails you may receive separately

Not part of the notification panel, but worth knowing about when a user asks "why did I get
this email":

| Email | Trigger | Configured at |
|---|---|---|
| Verification code | Registration | — |
| Password reset | `/forgot-password` | — |
| Compliance report | A scheduled report ran | **Reports → Scheduled Reports** |
| Scanner down / recovered | Self-hosted scan server health alerts | `SCANNER_ALERT_EMAIL` |

## Common questions

**Can I turn notifications off?**
No. They are low-volume product announcements. Mark all as read to clear the badge.

**The theme toggle didn't change my banner.**
It changes the *app* only. The banner's own light and dark colours are set under
**Configuration → Banners → Color Settings**, on the Light Theme / Dark Theme tabs.

**How do I get the tour back?**
Click the route icon in the top bar while on the page you want the tour for.

**I am not receiving report emails.**
On Cloud, check spam and the recipient address in **Reports → Scheduled Reports**. On
self-hosted, mail needs `MAIL_HOST` set in `.env` — test with `php bin/oci test:email`.

## Related

- Knowledgebase: Platform - Document: navigation.md — the full menu map
- Knowledgebase: Consent - Document: reports.md — scheduled report emails
- Knowledgebase: Account - Document: dashboard-customer.md — where the tour starts
