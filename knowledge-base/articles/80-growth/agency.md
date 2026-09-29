---
id: agency.customers
title: Agency — dashboard, customers and invites
area: Agency
knowledgebase: Growth
url: /agency
menu_path: Agency > Dashboard / Customers
edition: [cloud]
audience: [agency, admin, customer]
plan: any
tags: [agency, reseller, customers, invite, impersonation, login-as-customer, customer-health, commission, white-label]
related: [platform.roles, account.billing, dashboard.customer, platform.editions]
source_files:
  - src/Modules/Agency/templates/dashboard.html.twig
  - src/Modules/Agency/templates/customers/index.html.twig
  - src/Modules/Agency/templates/invites/index.html.twig
  - src/Modules/Agency/config/routes.php
  - src/Modules/Agency/module.php
questions:
  - How do I manage client websites as an agency?
  - How do I add a customer to my agency account?
  - What is the difference between inviting and creating a customer?
  - How do I log in as one of my customers?
  - An agency invited me — what happens if I accept?
  - How do I stop an agency managing my account?
  - How do I remove a customer?
  - Do my customers need their own subscription?
---

# Agency — dashboard, customers and invites

## Where to find it

| Page | URL | Who sees it |
|---|---|---|
| Agency Dashboard | `/agency` | agency, admin |
| My Customers | `/agency/customers` | agency, admin |
| Agency Invites | `/agency/invites` | customers who have been invited |

> **Cloud only.** The agency module is not part of self-hosted OCI.

## What it does

Lets an agency or reseller manage consent for client websites from one account: create or invite
customer accounts, watch their compliance health, and sign in as them to configure their setup
directly.

## Agency Dashboard

| Section | What it shows |
|---|---|
| **Customer Health** | A per-customer overview of compliance state, so you can spot which client needs attention |
| **Quick Actions** | Shortcuts, including **Invite Customer** |
| Empty state | "No customers yet" until you add one |

## My Customers

The customer list with two ways to add one.

### Invite User

For someone who already has a Conzent account.

| Field | Required |
|---|---|
| User Email | Yes |

They receive the invite on their **Account → Agency Invites** page and must accept it. Nothing
changes until they do. A pending invite can be withdrawn.

### Create Customer

For a new client who has no account. You create it outright and own it from the start.

**Account**

| Field | Required |
|---|---|
| Email | Yes |
| First Name | No |
| Last Name | No |
| Password | Yes — minimum 8 characters |
| Confirm Password | Yes |

**Company (optional)**

Company Name, VAT Number, Address, City, ZIP, State, Country Code, Phone.

### Row actions

| Action | What it does |
|---|---|
| **Log in as customer** (impersonate) | Signs you into their account. A banner with a return arrow appears on every page until you exit |
| **Withdraw Invite** | Cancels a pending invitation |
| **Remove Customer** | Ends the relationship. Confirmation required |

## Agency Invites (the customer's view)

Customers who have been invited see this under **Account → Agency Invites**. Each pending invite
shows the agency's name with accept and decline actions. Declining leaves the account untouched.

## Impersonation

While impersonating, you see and can change everything the customer can — their sites, banners,
cookies, consent logs, policies. A persistent banner at the top of every page marks the session
and provides the return arrow. Impersonation is recorded in the audit log.

Tell your clients this capability exists. It is standard for agency access, but it is their data.

## Common questions

**Do my customers need their own subscription?**
No. The agency plan covers the sites you manage. Customer accounts you create do not each need a
subscription. See Knowledgebase: Account - Document: billing.md.

**Invite or Create — which do I use?**
Create for a brand-new client with no Conzent account. Invite when they already have one and you
want to take over management without moving their data.

**What does the customer see when I accept on their behalf?**
You cannot accept for them. An invite must be accepted by the account holder on their Agency
Invites page. Only **Create Customer** gives you an account you own from the outset.

**How does a customer end the relationship?**
They ask you to remove them, or an admin does it. There is no self-service "leave my agency"
button on the customer side; declining future invites prevents new relationships.

**Can I white-label the banner for clients?**
Yes — **Remove "Powered by Conzent" Branding** in **Banners → Content Settings**, which the
Agencies and E-commerce plan includes. The app itself is not white-labelled.

**I removed a customer by accident.**
Their account and data survive; only the management link is gone. Invite them again and have
them accept.

**Is there an agency view on self-hosted?**
No. `EditionService::isAgencyEnabled()` is false without a `CMP_ID`, so the module and its menu
never load.

## Related

- Knowledgebase: Platform - Document: roles.md — what the agency role can do
- Knowledgebase: Account - Document: billing.md — agency plans
- Knowledgebase: Account - Document: dashboard-customer.md — what you see while impersonating
- Knowledgebase: Platform - Document: editions.md — why this is Cloud only
