---
id: plugins.umbraco
title: Umbraco package
area: Plugins
knowledgebase: Plugins
url: /sites
menu_path: (Umbraco backoffice) Settings > Conzent CMP
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [umbraco, dotnet, nuget, tag-helper, appsettings, install, website-key, cms]
related: [plugins.overview, sites.install-script, integrations.gtm]
source_files:
  - plugins/getconzent_umbraco/README.md
  - plugins/getconzent_umbraco/Conzent.Umbraco/ConzentOptions.cs
  - plugins/getconzent_umbraco/Conzent.Umbraco/ConzentScriptTagHelper.cs
  - plugins/getconzent_umbraco/Conzent.Umbraco/ConzentDashboard.cs
questions:
  - How do I install Conzent on Umbraco?
  - Where do I put my website key in Umbraco?
  - What is the conzent-scripts tag helper?
  - Can I configure Conzent with environment variables in Umbraco?
  - Does the Umbraco package work with self-hosted Conzent?
  - How do I turn the banner off temporarily in Umbraco?
---

# Umbraco package

## Where to find it

Configuration lives in `appsettings.json` under the `Conzent` section. After installation a
**Conzent CMP** dashboard appears in the Umbraco backoffice under **Settings**, showing your
current configuration and the JSON snippet to paste.

## What it does

A NuGet package that adds a tag helper injecting the Conzent consent script into your layout,
plus a backoffice dashboard for reference.

## Configuration

```json
{
  "Conzent": {
    "WebsiteKey": "YOUR-WEBSITE-KEY",
    "Enabled": true
  }
}
```

| Setting | Default | What it does |
|---|---|---|
| `WebsiteKey` | *(required)* | From **General → Sites** in Conzent |
| `ServerUrl` | `https://cdn.getconzent.com` | Conzent server URL. Leave empty or default for Cloud |
| `Enabled` | `true` | Toggles the consent banner on or off |

Environment variables work too, using .NET's double-underscore convention:

```
Conzent__WebsiteKey=your-key-here
Conzent__Enabled=true
```

Handy for keeping the key out of source control and varying it per environment.

## Installing

**1. Add the package**

```bash
dotnet add package Conzent.Umbraco
```

or `Install-Package Conzent.Umbraco` in the Visual Studio Package Manager.

**2. Register the tag helpers** — in `_ViewImports.cshtml`:

```razor
@addTagHelper *, Conzent.Umbraco
```

**3. Add to your layout** — in `_Layout.cshtml` (or your master layout):

```html
<head>
  ...
  <conzent-scripts />
</head>
```

Put `<conzent-scripts />` as early in `<head>` as you can — before analytics, ad tags and any
tag manager — so blocking happens before those scripts run.

**4. Configure** `appsettings.json` as above, and restart the site.

## Self-hosted OCI

```json
{
  "Conzent": {
    "WebsiteKey": "YOUR-WEBSITE-KEY",
    "ServerUrl": "https://consent.yourdomain.com",
    "Enabled": true
  }
}
```

## Features

- GDPR, CCPA and ePrivacy compliant banner
- IAB TCF certified (CMP #446 for this package's registration) and Google CMP Partner
- Google Consent Mode v2
- Automatic cookie blocking before consent
- Backoffice dashboard showing current configuration

There is also a GTM noscript tag helper for the `<body>` fallback, alongside the main script tag
helper.

## Common questions

**Nothing renders where I put `<conzent-scripts />`.**
Check `@addTagHelper *, Conzent.Umbraco` is in `_ViewImports.cshtml` — without it the element is
emitted as literal markup or dropped. Then confirm `Enabled` is `true` and `WebsiteKey` is set.

**How do I disable the banner on staging?**
Set `Conzent__Enabled=false` as an environment variable in that environment, leaving
`appsettings.json` untouched.

**Where is the backoffice dashboard?**
Umbraco backoffice → **Settings** → **Conzent CMP**. It is informational: it shows your current
configuration and the JSON snippet. Banner design is configured in the Conzent app.

**Which Umbraco versions?**
The package targets modern .NET Umbraco CMS. Check the NuGet listing for the exact supported
range before upgrading.

**Can I use it with self-hosted Conzent?**
Yes — set `ServerUrl` to your installation.

**Where do I configure the banner itself?**
The Conzent app, under **Configuration → Banners**.

## Related

- Knowledgebase: Plugins - Document: overview.md — all platforms
- Knowledgebase: Sites - Document: install-script.md — the manual alternative
- Knowledgebase: Integrations - Document: gtm-wizard.md — Tag Manager
