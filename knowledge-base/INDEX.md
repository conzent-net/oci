# Knowledge base index

64 documents across **11 knowledge bases**, covering every regular-user area of
Conzent plus plugins and self-hosting. Each folder under `articles/` ships as its own
knowledge base.

`E` = edition: **C** Cloud · **S** self-hosted · **C/S** both.
Regenerate this file with the incremental prompt in [UPDATE-PROMPT.md](UPDATE-PROMPT.md).

> Articles cross-reference each other as `Knowledgebase: <Name> - Document: <file.md>`, never
> by relative path. The **id** and **Document** columns below are the two join keys: `related:`
> in frontmatter uses the id, prose references use the document name. The markdown links here
> are for browsing the repo — this file sits above the knowledge bases and is not ingested.

| Folder | Knowledge base | Docs |
|---|---|---|
| `00-platform/` | **Platform** | 8 |
| `10-account/` | **Account** | 6 |
| `20-sites/` | **Sites** | 7 |
| `30-banner/` | **Banner** | 10 |
| `40-cookies/` | **Cookies** | 4 |
| `50-consent/` | **Consent** | 3 |
| `60-compliance/` | **Compliance** | 6 |
| `70-integrations/` | **Integrations** | 3 |
| `80-growth/` | **Growth** | 3 |
| `90-plugins/` | **Plugins** | 10 |
| `95-self-hosting/` | **Self-Hosting** | 4 |

---

## Knowledgebase: Platform

| id | Document | Title | URL | E |
|---|---|---|---|---|
| `platform.admin` | `admin-area.md` | [Admin area — platform administration (summary)](articles/00-platform/admin-area.md) | `/admin` | C/S |
| `platform.editions` | `editions.md` | [Editions — Conzent Cloud vs self-hosted (OCI)](articles/00-platform/editions.md) | `/` | C/S |
| `platform.glossary` | `glossary.md` | [Glossary — Conzent and privacy terminology](articles/00-platform/glossary.md) | `/` | C/S |
| `platform.navigation` | `navigation.md` | [Navigation — the complete menu map](articles/00-platform/navigation.md) | `/` | C/S |
| `platform.roles` | `roles.md` | [Roles and permissions](articles/00-platform/roles.md) | `/account` | C/S |
| `platform.site-context` | `site-context.md` | [The current site — how site context and switching work](articles/00-platform/site-context.md) | `/sites` | C/S |
| `platform.troubleshooting` | `troubleshooting.md` | [Troubleshooting — the most common problems](articles/00-platform/troubleshooting.md) | `/` | C/S |
| `platform.overview` | `what-is-conzent.md` | [What Conzent is and how the pieces fit together](articles/00-platform/what-is-conzent.md) | `/` | C/S |

## Knowledgebase: Account

| id | Document | Title | URL | E |
|---|---|---|---|---|
| `account.billing` | `billing.md` | [Billing and subscription](articles/10-account/billing.md) | `/billing` | C |
| `dashboard.customer` | `dashboard-customer.md` | [Dashboard — setup wizard, stats and recommendations](articles/10-account/dashboard-customer.md) | `/` | C/S |
| `account.notifications` | `notifications.md` | [Notifications and the guided tour](articles/10-account/notifications.md) | `/` | C/S |
| `account.profile` | `profile.md` | [Account Settings — profile, password, company, delete account](articles/10-account/profile.md) | `/account` | C/S |
| `account.setup` | `setup.md` | [First-run account setup](articles/10-account/setup.md) | `/account/setup` | C/S |
| `account.signup-login` | `signup-and-login.md` | [Signing up, signing in and password reset](articles/10-account/signup-and-login.md) | `/login` | C/S |

## Knowledgebase: Sites

| id | Document | Title | URL | E |
|---|---|---|---|---|
| `sites.associated-domains` | `associated-domains.md` | [Associated Domains — one banner across domain aliases](articles/20-sites/associated-domains.md) | `/sites/associated` | C/S |
| `sites.frameworks` | `frameworks.md` | [Privacy Frameworks — choosing which laws apply](articles/20-sites/frameworks.md) | `/sites/frameworks` | C/S |
| `sites.install-script` | `install-script.md` | [Installing the consent script on your website](articles/20-sites/install-script.md) | `/` | C/S |
| `sites.js-api` | `js-api.md` | [JavaScript API — reading consent from your own code](articles/20-sites/js-api.md) | `/` | C/S |
| `sites.languages` | `languages.md` | [Languages — which languages the banner supports](articles/20-sites/languages.md) | `/languages` | C/S |
| `sites.create` | `sites-create.md` | [Add a site — the three-step wizard](articles/20-sites/sites-create.md) | `/sites/create` | C/S |
| `sites.list` | `sites-list.md` | [My Sites — the site list](articles/20-sites/sites-list.md) | `/sites` | C/S |

## Knowledgebase: Banner

| id | Document | Title | URL | E |
|---|---|---|---|---|
| `banner.accessibility` | `accessibility.md` | [Accessibility — WCAG 2.1 AA, keyboard and screen readers](articles/30-banner/accessibility.md) | `/banners` | C/S |
| `banner.advanced` | `banner-advanced.md` | [Banner Settings — Advanced Settings](articles/30-banner/banner-advanced.md) | `/banners` | C/S |
| `banner.colors` | `banner-colors.md` | [Banner Settings — Color Settings](articles/30-banner/banner-colors.md) | `/banners` | C/S |
| `banner.content` | `banner-content.md` | [Banner Settings — Content Settings](articles/30-banner/banner-content.md) | `/banners` | C/S |
| `banner.custom-css` | `banner-custom-css.md` | [Banner Settings — Custom CSS](articles/30-banner/banner-custom-css.md) | `/banners` | C/S |
| `banner.general` | `banner-general.md` | [Banner Settings — General Settings](articles/30-banner/banner-general.md) | `/banners` | C/S |
| `banner.layout` | `banner-layout.md` | [Banner Settings — Banner Layout and Layout Settings](articles/30-banner/banner-layout.md) | `/banners` | C/S |
| `banner.translations` | `banner-translations.md` | [Banner Settings — Banner Content and Translations](articles/30-banner/banner-translations.md) | `/banners` | C/S |
| `banner.layout-editor` | `layout-editor.md` | [Layout editor — editing a custom layout](articles/30-banner/layout-editor.md) | `/layouts/{id}/edit` | C/S |
| `banner.layouts-library` | `layouts-library.md` | [Layouts — the layout library](articles/30-banner/layouts-library.md) | `/layouts` | C/S |

## Knowledgebase: Cookies

| id | Document | Title | URL | E |
|---|---|---|---|---|
| `cookies.categories` | `categories.md` | [Cookie Categories](articles/40-cookies/categories.md) | `/categories` | C/S |
| `cookies.list` | `cookies-list.md` | [Cookies — the detected cookie list](articles/40-cookies/cookies-list.md) | `/cookies` | C/S |
| `cookies.register-changes` | `register-changes.md` | [Register changes — the cookie change timeline and alerts](articles/40-cookies/register-changes.md) | `/cookies/changes` | C/S |
| `cookies.scans` | `scans.md` | [Cookie Scans — running, scheduling and reading results](articles/40-cookies/scans.md) | `/scans` | C/S |

## Knowledgebase: Consent

| id | Document | Title | URL | E |
|---|---|---|---|---|
| `consent.detail` | `consent-detail.md` | [Consent Proof — a single consent record](articles/50-consent/consent-detail.md) | `/consents/{id}` | C/S |
| `consent.logs` | `consent-logs.md` | [Consent Logs — the audit trail](articles/50-consent/consent-logs.md) | `/consents` | C/S |
| `consent.reports` | `reports.md` | [Reports — generating and scheduling compliance reports](articles/50-consent/reports.md) | `/reports` | C/S |

## Knowledgebase: Compliance

| id | Document | Title | URL | E |
|---|---|---|---|---|
| `compliance.checklist` | `checklist.md` | [Compliance Checklist](articles/60-compliance/checklist.md) | `/compliance` | C/S |
| `policies.cookie-wizard` | `cookie-policy-wizard.md` | [Cookie Policy Wizard](articles/60-compliance/cookie-policy-wizard.md) | `/policies/cookie` | C/S |
| `compliance.gcm` | `google-consent-mode.md` | [Google Consent Mode v2](articles/60-compliance/google-consent-mode.md) | `/banners` | C/S |
| `compliance.tcf` | `iab-tcf.md` | [IAB TCF v2.4 and Google Additional Consent](articles/60-compliance/iab-tcf.md) | `/banners` | C/S |
| `policies.overview` | `policies-overview.md` | [Policies — cookie and privacy policies](articles/60-compliance/policies-overview.md) | `/policies` | C/S |
| `policies.privacy-wizard` | `privacy-policy-wizard.md` | [Privacy Policy Wizard](articles/60-compliance/privacy-policy-wizard.md) | `/policies/privacy` | C/S |

## Knowledgebase: Integrations

| id | Document | Title | URL | E |
|---|---|---|---|---|
| `integrations.signals` | `consent-signals.md` | [Consent signals — Meta, Microsoft, Amazon](articles/70-integrations/consent-signals.md) | `/banners` | C/S |
| `integrations.gtm` | `gtm-wizard.md` | [Google Tag Manager Wizard](articles/70-integrations/gtm-wizard.md) | `/gtm/wizard` | C/S |
| `integrations.matomo` | `matomo-wizard.md` | [Matomo Tag Manager Wizard](articles/70-integrations/matomo-wizard.md) | `/matomo/wizard` | C/S |

## Knowledgebase: Growth  *(Cloud modules)*

| id | Document | Title | URL | E |
|---|---|---|---|---|
| `growth.abtest` | `ab-tests.md` | [A/B Split Tests](articles/80-growth/ab-tests.md) | `/ab-tests` | C |
| `agency.customers` | `agency.md` | [Agency — dashboard, customers and invites](articles/80-growth/agency.md) | `/agency` | C |
| `growth.impact` | `revenue-impact.md` | [Revenue Impact — what your consent rate costs](articles/80-growth/revenue-impact.md) | `/impact` | C |

## Knowledgebase: Plugins

| id | Document | Title | URL | E |
|---|---|---|---|---|
| `plugins.extension` | `browser-extension.md` | [Consent Mode Inspector — browser extension](articles/90-plugins/browser-extension.md) | `/sites` | C/S |
| `plugins.drupal` | `drupal.md` | [Drupal module](articles/90-plugins/drupal.md) | `/sites` | C/S |
| `plugins.joomla` | `joomla.md` | [Joomla plugin](articles/90-plugins/joomla.md) | `/sites` | C/S |
| `plugins.matomo` | `matomo.md` | [Matomo Tag Manager plugin](articles/90-plugins/matomo.md) | `/sites` | C/S |
| `plugins.other-tools` | `other-tools.md` | [Companion WordPress tools — Compliance Audit and Whitepaper Gateway](articles/90-plugins/other-tools.md) | `/sites` | C/S |
| `plugins.overview` | `overview.md` | [Plugins and extensions — overview](articles/90-plugins/overview.md) | `/sites` | C/S |
| `plugins.typo3` | `typo3.md` | [TYPO3 extension](articles/90-plugins/typo3.md) | `/sites` | C/S |
| `plugins.umbraco` | `umbraco.md` | [Umbraco package](articles/90-plugins/umbraco.md) | `/sites` | C/S |
| `plugins.wix` | `wix.md` | [Wix app](articles/90-plugins/wix.md) | `/sites` | C/S |
| `plugins.wordpress` | `wordpress.md` | [WordPress plugin](articles/90-plugins/wordpress.md) | `/sites` | C/S |

## Knowledgebase: Self-Hosting

| id | Document | Title | URL | E |
|---|---|---|---|---|
| `selfhost.cli` | `cli.md` | [CLI commands (bin/oci)](articles/95-self-hosting/cli.md) | `/` | S |
| `selfhost.configuration` | `configuration.md` | [Configuration reference (.env)](articles/95-self-hosting/configuration.md) | `/` | S |
| `selfhost.install` | `install.md` | [Installing Conzent OCI (self-hosted)](articles/95-self-hosting/install.md) | `/` | S |
| `selfhost.scanner` | `scanner.md` | [Cookie scanner — setup, scaling and troubleshooting](articles/95-self-hosting/scanner.md) | `/scans` | S |

---

## Coverage against the app

Every route with `web` middleware in `config/routes.php` and the module route files is covered.

| Route | Knowledge base | Document |
|---|---|---|
| `/` | Account | `dashboard-customer.md` |
| `/sites`, `/sites/create`, `/sites/frameworks` | Sites | `sites-list.md`, `sites-create.md`, `frameworks.md` |
| `/sites/associated` | Sites | `associated-domains.md` |
| `/languages` | Sites | `languages.md` |
| `/banners`, `/banners/content` | Banner | the seven banner settings documents, plus `accessibility.md` |
| `/layouts`, `/layouts/{id}/edit` | Banner | `layouts-library.md`, `layout-editor.md` |
| `/cookies`, `/categories` | Cookies | `cookies-list.md`, `categories.md` |
| `/cookies/changes` | Cookies | `register-changes.md` |
| `/scans`, `/scans/{id}` | Cookies | `scans.md` |
| `/consents`, `/consents/{id}` | Consent | `consent-logs.md`, `consent-detail.md` |
| `/policies`, `/policies/cookie`, `/policies/privacy` | Compliance | the three policy documents |
| `/reports`, `/reports/{id}` | Consent | `reports.md` |
| `/compliance` | Compliance | `checklist.md` |
| `/gtm/wizard`, `/matomo/wizard` | Integrations | `gtm-wizard.md`, `matomo-wizard.md` |
| `/account`, `/account/setup` | Account | `profile.md`, `setup.md` |
| `/login`, `/register`, `/forgot-password`, `/reset-password` | Account | `signup-and-login.md` |
| `/billing` (module) | Account | `billing.md` |
| `/ab-tests`, `/ab-tests/{id}` (module) | Growth | `ab-tests.md` |
| `/impact` (module) | Growth | `revenue-impact.md` |
| `/agency`, `/agency/customers`, `/agency/invites` (module) | Growth | `agency.md` |
| `/admin/*` (module) | Platform | `admin-area.md` — summary level, admin audience |

Not documented as articles, by design: `/api/v1/*` public API endpoints, `/app/*` AJAX
endpoints, webhooks, `/health` and `/ping`. Those belong in `development/oci/api.md`.