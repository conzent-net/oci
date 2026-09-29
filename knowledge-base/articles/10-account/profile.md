---
id: account.profile
title: Account Settings — profile, password, company, delete account
area: Account
knowledgebase: Account
url: /account
menu_path: Account > Profile
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [profile, account, password, company, vat, delete-account, legacy-migration, email]
related: [account.setup, account.billing, account.signup-login, platform.roles]
source_files:
  - templates/pages/account/profile.html.twig
  - src/Identity/Controller/AccountProfileHandler.php
  - src/Identity/Controller/AccountDeleteHandler.php
  - src/Identity/Controller/LegacyMigrateHandler.php
questions:
  - How do I change my password?
  - How do I change my email address?
  - Where do I update my company details or VAT number?
  - How do I delete my Conzent account?
  - What happens to my data if I delete my account?
  - How do I import my sites from my old Conzent account?
  - Can I change my account email after signing up with Google?
---

# Account Settings — profile, password, company, delete account

## Where to find it

**Account → Profile** in the sidebar, or the account menu in the top bar. URL: `/account`.

## What it does

One page holding everything about your login and your organisation: personal details, password,
company and billing address, the legacy-account import, and account deletion.

## Sections and fields

### Personal Information

| Field | Required | What it does |
|---|---|---|
| First Name | Yes | Your display name in the app |
| Last Name | Yes | — |
| Email Address | Yes | Your login. Changing it changes what you sign in with |

### Change Password

| Field | Required | What it does |
|---|---|---|
| New Password | No | Minimum 8 characters. Leave blank to keep your current password |
| Confirm Password | No | Must match |

Both fields blank means "no change" — you can save other sections without touching your
password.

### Company Information

| Field | What it does |
|---|---|
| Company Name | Named as the data controller in generated privacy policies; appears on invoices |
| VAT Number | e.g. `DK12345678`. Shown on Cloud invoices; may zero-rate EU VAT |
| Address | Street address. Pre-fills the privacy policy wizard |
| City | — |
| ZIP / Postal Code | — |
| State / Region | — |
| Country Code | Two-letter code, e.g. `DK`. Max 10 characters |
| Phone | Contact number; also offered as a policy contact point |

**Save Changes** at the bottom commits all three sections at once.

### Migrate from Legacy Account

Shown only when a legacy Conzent account matches your email and you have not already imported.
It previews the domains that would come across, each marked **Ready** or **Domain exists
(skip)**, and imports on confirmation.

Imports: company information, website domains.
Does not import: banner design, colours, content, or any site-specific settings — those start
from defaults. On Cloud, imported sites are suspended until you subscribe.

Once imported, the section is replaced by a line noting the date.

### Danger Zone — Delete My Account

Hidden for admin accounts. Opens a confirmation modal listing exactly what is destroyed:

- Your profile and company information
- All your sites and their configurations
- All consent logs and scan data

This cannot be undone. Deleting the account stops every banner you have installed from being
configured — the script will no longer resolve.

## How to change your login email

1. Open **Account → Profile**.
2. Edit **Email Address**.
3. **Save Changes**. Sign in with the new address from then on.

If you originally signed in with Google, changing this field changes the password-login address;
the Google button still matches on your Google account.

## Common questions

**I want to change my password but the form keeps my old one.**
Fill in both New Password and Confirm Password, then Save Changes. Leaving them blank is what
keeps the existing password.

**Where do I cancel my subscription?**
Not here — **Account → Billing** (Cloud only). See Knowledgebase: Account - Document: billing.md.

**Does deleting my account cancel my subscription?**
Cancel the subscription on the Billing page first, then delete. Deleting removes account data;
it is not a billing action.

**Can I get my data back after deletion?**
No. Export what you need first — consent logs can be exported from **Consent Logs**, and reports
from **Reports**.

**Why don't I see the Danger Zone?**
Admin accounts cannot self-delete.

**I don't see the legacy migration section.**
It only appears when a legacy account matches your email and you have not imported yet. The
server also needs `LEGACY_DATABASE_URL` configured.

## Related

- Knowledgebase: Account - Document: setup.md — the same details at first run
- Knowledgebase: Account - Document: billing.md — subscription and invoices
- Knowledgebase: Account - Document: signup-and-login.md — password reset when locked out
