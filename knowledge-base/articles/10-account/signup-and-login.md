---
id: account.signup-login
title: Signing up, signing in and password reset
area: Account
knowledgebase: Account
url: /login
menu_path: (not in menu) — /login, /register, /forgot-password
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [login, signup, register, password, reset, google, verification, locked-out, 2fa]
related: [account.setup, account.profile, selfhost.cli]
source_files:
  - config/routes.php
  - templates/pages/auth/login.html.twig
  - templates/pages/auth/register.html.twig
  - templates/pages/auth/verify-code.html.twig
  - templates/pages/auth/forgot-password.html.twig
  - templates/pages/auth/reset-password.html.twig
  - templates/pages/auth/blocked.html.twig
questions:
  - How do I create a Conzent account?
  - I forgot my password
  - The password reset email never arrived
  - My account is locked, what do I do?
  - Can I sign in with Google?
  - I never got my verification code
  - How do I reset my password on a self-hosted install?
  - How long is the reset link valid?
---

# Signing up, signing in and password reset

## Where to find it

| Page | URL |
|---|---|
| Sign in | `/login` |
| Create account | `/register` |
| Verify your email | `/register/verify` |
| Request a reset | `/forgot-password` |
| Set a new password | `/reset-password` (from the emailed link) |
| Sign out | `/logout`, or Account menu → Logout |

## What it does

Handles account creation and access. Sign-in is email plus password, with "Sign in with Google"
as an alternative when the server has Google OAuth configured. New registrations confirm their
email with a short code before the account becomes usable.

## Fields

### Register

| Field | What it does | Notes |
|---|---|---|
| Email | Your login and where verification and reset mail goes | Must be unique |
| Password | Account password | Minimum 8 characters |
| Confirm password | Typo guard | Must match |
| Sign in with Google | Creates the account from your Google profile instead | Only shown if the server has `GOOGLE_CLIENT_ID` set |

### Verify your email

| Field | What it does |
|---|---|
| Verification code | The code emailed to you |
| Resend code | Sends a fresh code |
| Cancel | Abandons the verification and returns to registration |

### Sign in

| Field | What it does |
|---|---|
| Email | Your account email |
| Password | Your password |
| Sign in with Google | OAuth sign-in, if configured |

### Forgot / reset password

| Field | What it does |
|---|---|
| Email (forgot) | Sends a reset link if an account exists. The confirmation is deliberately the same either way |
| New password | Minimum 8 characters |
| Confirm password | Must match |

## How to reset a forgotten password

1. Go to `/forgot-password`.
2. Enter your account email and submit.
3. Open the email and click the link. The token expires after **60 minutes**.
4. Set a new password and sign in.

## Account lockout

Repeated failed sign-ins lock the account and show a lockout screen. The lock clears after the
cooldown; a password reset also gets you back in. If you are locked out of a self-hosted
install with no email configured, reset from the host instead — see below.

## Edition differences

| | Cloud | Self-hosted |
|---|---|---|
| Sign in with Google | Available | Only if you set `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` |
| Verification + reset email | Always works | Needs `MAIL_HOST` in `.env`. With it empty, outbound mail is silently skipped and password reset will not work |
| Locked out with no SMTP | Contact support | `php bin/oci user:password <email> <new-password>` on the host |

## Common questions

**The reset email never arrived.**
Check spam first. On Cloud, confirm you used the address the account was created with. On
self-hosted, an empty `MAIL_HOST` means no mail is sent at all — set SMTP in `.env`, or reset
from the CLI with `php bin/oci user:password`.

**How long is the reset link good for?**
60 minutes. Request a new one if it expired.

**Can I use Google sign-in on my own install?**
Yes. Create OAuth credentials in Google Cloud Console, set `GOOGLE_CLIENT_ID` and
`GOOGLE_CLIENT_SECRET`, and add `{APP_URL}/auth/google/callback` as an authorised redirect URI.

**Does Conzent support two-factor authentication?**
Not currently. Email verification runs at registration only.

**Where do I change my password once I am signed in?**
**Account → Profile**, in the Change Password section. Leaving both fields blank keeps your
current password.

**I registered but never received the code.**
Use **Resend code** on the verification screen. If nothing arrives, the sending domain may be
blocking you — on self-hosted, verify SMTP with `php bin/oci test:email`.

## Related

- Knowledgebase: Account - Document: setup.md — the first-run onboarding form
- Knowledgebase: Account - Document: profile.md — changing password and email later
- Knowledgebase: Self-Hosting - Document: cli.md — `user:password`, `test:email`
