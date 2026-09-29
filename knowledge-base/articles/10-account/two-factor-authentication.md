---
id: account.two-factor
title: Two-factor authentication — authenticator app, recovery codes and trusted devices
area: Account
knowledgebase: Account
url: /account/2fa
menu_path: Account > Two-factor authentication
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [2fa, two-factor, mfa, totp, authenticator, google-authenticator, qr-code, recovery-codes, trusted-device, security, locked-out, login]
related: [account.signup-login, account.profile, selfhost.cli, platform.roles]
source_files:
  - src/Identity/Service/TwoFactorService.php
  - src/Identity/Service/TotpService.php
  - src/Identity/Controller/TwoFactorSettingsHandler.php
  - src/Identity/Controller/TwoFactorVerifyHandler.php
  - src/Http/Middleware/TwoFactorMiddleware.php
  - templates/pages/account/two-factor.html.twig
  - templates/pages/auth/two-factor-verify.html.twig
questions:
  - How do I turn on two-factor authentication?
  - Which authenticator apps work with Conzent?
  - I lost my phone and cannot sign in
  - What are recovery codes and where do I get new ones?
  - How do I stop being asked for a code every time I sign in?
  - How do I turn two-factor off?
  - My code is not being accepted
  - Can an administrator reset my two-factor?
  - Does two-factor work when an agency logs in as me?
  - How do I enable two-factor on a self-hosted install?
---

# Two-factor authentication

## Where to find it

**Account → Two-factor authentication** in the dashboard. URL: `/account/2fa`.

## What it does

Adds a second step at sign-in: after your password, you enter a six-digit code from an
authenticator app on your phone. A stolen or guessed password is then not enough on its own.

## Turning it on

1. Go to **Account → Two-factor authentication**.
2. Scan the QR code with your authenticator app. Google Authenticator, 1Password, Bitwarden,
   Authy, Microsoft Authenticator and any other TOTP app all work. If you cannot scan it, the
   key is shown as text underneath to type in by hand.
3. Enter the six-digit code the app shows, and select **Turn on two-factor**.
4. **Save the ten recovery codes** that appear. This is the only time they are shown.

Two-factor is not switched on until step 3 succeeds. If you scan the code and then close the
page, nothing changes and you can start again — you cannot lock yourself out part-way through
setup.

## Recovery codes

Ten single-use codes, shown once when you turn two-factor on. They are your way back in if you
lose the phone, so keep them somewhere you can reach **without** that phone: a password manager,
or printed.

Enter a recovery code in the same box as a normal code at sign-in. Each one works once. You can
see how many remain on the two-factor page, and generate a fresh set at any time by entering a
current code — doing so immediately invalidates the old set.

## Trusted devices

Tick **Trust this device for 30 days** when you verify, and that browser stops asking for a code
for 30 days. Useful on your own laptop; do not use it on a shared or public computer.

The two-factor page lists your trusted devices and lets you revoke all of them at once. They are
also revoked automatically whenever the protection they skip changes: if you turn two-factor off
and on again, if an administrator resets it, or if you reset your password.

## Turning it off

On the two-factor page, enter a current code and select **Turn off**. A code is required on
purpose: without it, anyone who found your screen unlocked and already signed in could quietly
remove the second factor.

Turning it off also deletes your recovery codes and trusted devices.

## Common questions

**My code is not being accepted.**
Codes change every 30 seconds, so make sure you are entering the current one. If it still fails,
your phone's clock is probably out of step with real time — turn on automatic date and time in
the phone's settings, which is what generates the code. A code can only be used once, so if you
have just used one, wait for the next.

**I lost my phone and I have my recovery codes.**
Enter a recovery code instead of a six-digit code at sign-in, then go to
**Account → Two-factor authentication**, turn it off, and set it up again on the new phone.

**I lost my phone and I do not have my recovery codes.**
You cannot restore access yourself, by design. On Conzent Cloud, ask us to reset it: write to
support@getconzent.com from the email address on the account. On a self-hosted install, whoever
operates the server runs `php bin/oci user:2fa-reset --email=you@example.com`.

**Can an administrator reset my two-factor?**
Yes. On Conzent Cloud an administrator can reset it for you, which clears your authenticator,
your recovery codes and your trusted devices, and signs you out everywhere. You then set it up
again from scratch. Administrators can never *see* your authenticator secret, only remove it.

**Does two-factor still work if an agency or administrator logs in as me?**
Yes, and the important part is what it prevents. When someone logs in as your account they are
not asked for your code — they cannot have it. But they can only do that if **their own**
two-factor is switched on and satisfied first. Turning two-factor on therefore also raises the
bar for anyone who could reach your account that way; it is not a door left open.

**Does resetting my password remove two-factor?**
No. If it did, anyone who could read your email would defeat the second factor, which is the
exact attack it exists to stop. A password reset does revoke your trusted devices, so every
device asks for a code again.

**Is it required?**
No. It is available on every account and every plan, and switching it on is your choice.

**How do I enable it on a self-hosted install?**
Set `TOTP_ENCRYPTION_KEY` in your `.env` first — generate one with
`php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` — then restart the application. Without that
key the platform refuses to enable two-factor rather than storing authenticator secrets in a
readable form. **Back the key up and do not change it:** every enrolled user would have to set
two-factor up again.

## Related

- Knowledgebase: Account - Document: signup-and-login.md — passwords, sign-in and account lockout
- Knowledgebase: Account - Document: profile.md — changing your password
- Knowledgebase: Self-hosting - Document: cli.md — the operator reset command
