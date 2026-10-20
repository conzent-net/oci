---
id: platform.roles
title: Roles and permissions
area: Platform
knowledgebase: Platform
url: /account
menu_path: Account > Profile
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [roles, permissions, admin, agency, customer, impersonation, access]
related: [platform.navigation, agency.customers, platform.editions]
source_files:
  - templates/components/sidebar.html.twig
  - src/Modules/Agency/module.php
  - src/Modules/Agency/config/routes.php
  - config/middleware.php
  - config/routes.php
questions:
  - What roles does Conzent have?
  - How do I make someone an admin?
  - Can I invite a colleague to my account?
  - What can an agency do to my account?
  - How do I stop an agency managing my sites?
  - What is impersonation?
  - Why can't I access the admin area?
---

# Roles and permissions

## Where to find it

Your own role is not shown as a field — you can tell it from the sidebar. Admins see an
**Admin** entry at the bottom; agencies see an **Agency** section.

## What it does

Conzent has three roles. A role is set on the user account and decides which menu sections and
routes are available.

## Roles

| Role | Who it is | Gets |
|---|---|---|
| `customer` | The default. Owns websites and configures consent for them | Everything under General, Configuration, Compliance and Account, scoped to their own sites. Sees **Agency Invites** if an agency has invited them |
| `agency` | Resellers and agencies managing consent for clients | Everything a customer gets for their own sites, plus the **Agency** section: customer list, create/invite customers, and "log in as customer" |
| `admin` | Platform operators (Conzent staff, or the person who installed OCI) | Everything, plus the **Admin** section: users, plans, scan servers, cookie/beacon classification queues, knowledge base, chat transcripts, billing overview, audit log |

Routes are protected by middleware groups: `guest` (login, register, password reset), `web`
(any signed-in user), `admin` (admin role only), `webhook` (server-to-server, no session).

## What each role cannot do

- A **customer** cannot reach any `/admin/*` or `/agency/*` page except `/agency/invites`.
- An **agency** cannot reach `/admin/*`. It can only see customers linked to it, not all users.
- Nothing except an **admin** can change plans, manage scan servers, or approve cookie
  reclassification requests.

## Agency relationships

An agency gets a customer in one of two ways:

1. **Create Customer** — the agency creates the account outright (email, password, optional
   company details). The agency owns it from the start.
2. **Invite User** — the agency emails an existing Conzent user. The invite lands on that user's
   **Account → Agency Invites** page, where they accept or decline. Nothing happens until they
   accept.

Once linked, the agency can use **log in as customer** (impersonation) to see and change that
customer's configuration. While impersonating, a banner sits at the top of every page with a
return arrow to end the session. Impersonation is logged in the audit log.

A customer can end the relationship by asking the agency to remove them, or by declining future
invites. Agency features are Cloud only.

## Multi-user accounts

Conzent does not have team seats — one login owns its sites. If several people need access,
either share one login or, on Cloud, use the agency model: one agency account with each
colleague's sites as customers.

## Common questions

**How do I add a second user to my account?**
There is no team-member invite for a plain customer account. Options: share credentials, or on
Cloud have an agency account manage the sites.

**Can an agency see my consent logs and change my banner?**
Yes, once you accept their invite (or if they created your account). They get the same view you
do. Removing the relationship removes that access.

**How do I become an admin?**
On self-hosted, the first account created by the installer is the admin; further admins are set
by an existing admin under **Admin → Users**. On Cloud, admin is Conzent staff only.

**I clicked an /admin link and got redirected.**
The `admin` middleware bounced you. Your account is not an admin.

**What is logged?**
Admin actions, impersonation, and manual consent-configuration changes are written to the audit
log at `/admin/audit-log`.

## Related

- Knowledgebase: Platform - Document: navigation.md — which menus each role sees
- Knowledgebase: Growth - Document: agency.md — managing customers as an agency
- Knowledgebase: Account - Document: profile.md — your own account
