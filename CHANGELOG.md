# Changelog

All notable changes to the Conzent OCI core will be documented in this file.

This project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **Google Additional Consent v2** — the CMP now emits AC v2 (`2~<consented>~dv.<disclosed>`) instead of v1 (`1~<consented>`). The new third segment declares which Google ad tech providers were *disclosed* to the visitor, which is what evidences transparency for the ~700 ATPs that sit outside the IAB Global Vendor List. v2 has been Google's standard since December 2023. Existing visitors are **not** re-prompted: a stored v1 consent list is read and upgraded in place on the next page view, and the `euconsent-v2` cookie payload is unchanged.
  - The cookie continues to store only the consented ATP ids. The disclosed segment is derived at runtime from the current vendor list, because persisting it would push a reject-all cookie past the 4096-byte browser limit, where it is dropped silently and consent is lost.
  - **Expect more unchecked Google vendors over time.** A visitor who accepted everything last month has consented to the list as it was then; providers Google has added since correctly appear as disclosed-but-not-consented until the visitor revisits their choices. This is a fidelity improvement over v1, which could not express the distinction.
- **Google Additional Consent toggle in banner settings** — the setting was seeded on at site creation but had no control in the UI (see the fix below).

### Fixed
- **Additional Consent was silently switched off by saving banner settings** — `google_additional_consent` was seeded to `1` when a site was created but was missing from the banner settings form, so every save dropped the key and the runtime fell back to off. Sites could lose Google Additional Consent signalling without anyone changing it. The setting now has a toggle under IAB TCF support and survives a save.
- **Customising preferences on a GDPR + CCPA site rejected everything** — the save path compared the configured law to the bare string `gdpr`, so `gdpr_ccpa` fell through to the reject-all branch: every customised selection was discarded and the TC string recorded a full rejection. `gdpr_ccpa` is now correctly treated as a GDPR configuration in both the save path and TCF applicability signalling.
- **Preference centre could fail to render from a malformed consent cookie** — the Google vendor list read the consent string with an unguarded `split("~")[1].split(".")`, which threw on any value without a `~` and took the whole modal down. It also showed the "select all" toggle as checked when the provider list was empty.
- **Cookie values containing `=` were truncated on read** — `Conzent_Cookie.read` split on every `=` and kept only the second field. It also decoded values that are written raw, so a stray `%` in any unrelated cookie on the domain threw a `URIError` and broke every cookie read on the page.
- **Vendor tab and vendor count ignored publisher vendor narrowing** — the preference centre and the `{vendor_count}` placeholder were built before `narrowGoogleVendorsTo()` was applied, so publishers using an ATP allow-list saw the full list rendered while the consent signal used the narrowed one.

### Changed
- **License corrected to Apache 2.0** — Conzent OCI is open source under the Apache License 2.0, and Apache 2.0 is **authoritative**. Earlier releases were published with conflicting license text: the repository carried a Business Source License 1.1 grant with a four-year conversion clause, while getconzent.com stated Apache 2.0. That conflict is resolved in favour of Apache 2.0, and the resolution applies to code obtained under those earlier releases as well.
- **BSL-era restrictions withdrawn** — the Business Source License forbade offering the code as a competing hosted or managed service. Apache 2.0 grants commercial use, modification, and redistribution, including as a hosted service, with no field-of-use restriction and no conversion date. That restriction is not enforced and has been removed from all project copy.
- **"Powered by Conzent" branding is optional** — previously stated as a license obligation. Apache 2.0 cannot impose a visible-badge requirement, so the badge is now a request, not a condition, and is freely removable in self-hosted installs. Attribution obligations are limited to what Apache 2.0 §4 requires (preserving copyright, patent, trademark, and attribution notices, and any `NOTICE` file).
- **Trademarks and CMP ID remain reserved** — the license grant does not extend to the Conzent name, logos, or Conzent's IAB Europe CMP ID. TCF registration is per-operator: register your own CMP ID with IAB Europe. See [`NOTICE`](NOTICE).

---

## [v2.7.1] - 2026-08-31

### Fixed

- **Consent was never stored on sites registered with a `www.` domain but served on the apex** — the banner reappeared on every page load, indefinitely. `getRootDomain()` stripped `www.` from the visitor's hostname but not from the configured domain, so the comparison failed, the lookup fell through to the configured host, and the runtime tried to set the consent cookie on `.www.example.com` while the browser was on `example.com`. Per [RFC 6265](https://datatracker.ietf.org/doc/html/rfc6265#section-5.1.3) a browser must reject that, because `www` is a *child* of the apex rather than a parent, so every consent decision was silently discarded.

  The cookie domain is now always the apex, which is correct in both directions: `.example.com` is sent to `www.example.com` and to every other subdomain. Cookie deletion used the same lookup, so consent *withdrawal* was affected on these sites too.

  **Are you affected?** Only if your site's configured domain begins with `www.`. Subdomain setups (`de.example.com` sharing consent from a site registered as `example.com`) were never affected, because a parent-domain cookie is always accepted. **After updating, regenerate your site scripts** — the fix is applied at generation as well as at runtime, and existing deployed bundles carry the old code until they are rebuilt.

- **An associated domain on a subdomain overwrote the primary domain's privacy policy link** — the policy list was keyed by the last two labels of each domain, so `shop.example.com` collapsed onto `example.com` and replaced the site's own policy URL. Keys are now the full host, which is also what the runtime looks up, so `example.co.uk` is no longer truncated to the public suffix `co.uk` either.

- **A privacy policy link could be set to the literal string `undefined`** — the runtime looked up the policy list by full hostname while it was generated with a truncated key, and guarded with `!= ""`, which is true for a missing entry. A host with no entry now leaves the existing link untouched.

- **An associated domain with no privacy policy URL stored an empty link** — the per-domain policy field is optional, and a blank value now inherits the site's own privacy policy instead.

- **Adding `www.<your-domain>` as an associated domain reported a confusing error** — the form correctly refused it, because `www` is already covered by the primary domain, but the message read as though a different domain had been entered. It now explains that subdomains inherit consent automatically and do not need to be added.

---

## [v2.4.1] - 2026-03-25

### Fixed
- **Stale code after update** — `--update` now forces a full Docker rebuild with `--no-cache` to prevent cached layers from serving old code
- **Stale public assets** — The `app-public` Docker volume is now removed before rebuild on updates, ensuring CSS/JS/media files are refreshed
- **Cache flush on update** — Redis and Twig template caches are now flushed automatically after an update

### Added
- **Force update script** — New `scripts/update.sh` for a one-command clean rebuild: pulls latest code, rebuilds containers without cache, runs migrations, and flushes all caches

---

## [v2.4.0] - 2026-03-25

### Added
- **Internationalization** — Extracted ~194 hardcoded English strings from templates into locale YAML files for full i18n support
- **Danish translations** — Complete Danish language support across the platform

### Changed
- **Twig locale rendering** — Fixed locale context to pass via `render()` instead of `addGlobal()`, ensuring correct per-request translations
- **Twig environment** — Creates fresh Twig environment per language to prevent stale globals
- **Pricing configuration** — Updated plan pricing

### Fixed
- **CCPA/GDPR templates** — Fixed framework-specific consent template rendering
- **Site deletion** — Script files are now properly deleted when a site is removed
- **Translation frontmatter** — Fixed broken frontmatter in translated markdown files

---

## [v2.3.0] - 2026-03-24

### Added
- **Privacy Framework** — Full privacy/cookie policy framework with multi-step generation, template support, and per-site customization
- **Pageview usage bar** — Dashboard now shows pageview usage with exceeded notice and plan upgrade prompts
- **Compliance score & recommendations** — Dashboard compliance score widget with actionable recommendation checklist
- **Help & support pages** — In-app help center with support documentation

### Changed
- **Dashboard layout** — Redesigned dashboard with improved layout and richer statistics
- **Banner script engine** — Script minification, GTM integration fixes, IAB TCF error handling improvements
- **Branding** — Updated logo and branding across the platform
- **Site limitations** — Improved plan-based site and domain limit enforcement
- **Custom layouts** — Fixed duplicate layout handling and custom layout editing

### Fixed
- **Banner defaults** — Fixed default banner settings not applying correctly on new sites
- **Script generation** — Fixed ASCII encoding issues, minification errors, and cache invalidation
- **Modal dialogs** — Fixed modal display and interaction issues
- **Color settings** — Fixed color picker and theme application bugs
- **Scan page** — Fixed scan display and recommendation rendering
- **Login flow** — Fixed login edge cases and font rendering

---

## [v2.2.1] - 2026-03-17

### Added
- **User registration** — Public registration page with email/password signup and automatic login
- **Scan card redesign** — Simplified scan index page with cleaner card layout

### Fixed
- **Docker volume stale assets** — Public assets (CSS, JS, media) are now synced into Docker volumes on every container start, fixing updates not appearing after `--update`
- **Dashboard recommendations** — Fixed recommendation checklist display and consent stats
- **Scan repository** — Fixed scan queries and detail page
- **Logo display** — Fixed logo rendering issues

### Changed
- **Dashboard** — Enhanced customer dashboard with richer consent statistics and scan summary
- **README** — Added full installer options table with `--update`, `--config`, `--uninstall` and usage examples

---

## [v2.2.0] - 2026-03-17

### Added
- **Notification system** — In-app notifications with bell icon in navbar, mark-as-read, mark-all-read, and detail view
- **Onboarding flow** — Persistent onboarding checklist for new users with completion tracking
- **Layout duplication** — Duplicate existing banner layouts from the layouts page
- **Agency invite withdrawal** — Agency users can withdraw pending customer invitations
- **Scan service** — New scan orchestration service layer for cookie scanning
- **Dashboard enhancements** — Expanded customer dashboard with richer consent stats and recommendations
- **Banner page improvements** — Enhanced banner list with better status display and inline actions
- **App screenshots** — Added dashboard, banner settings, consent logs, cookie scanner, and policy generator screenshots

### Changed
- License page removed from app menu — license information now lives on the public website at [getconzent.com/license](https://getconzent.com/license/)
- Navbar updated with notification bell and unread count badge
- Base layout updated with notification CSS and onboarding support
- Language management handlers improved with better validation

### Fixed
- Session middleware edge cases
- Dark mode logo display in navbar

---

## [v2.1.8] - 2026-03-16

### Added
- **Agency domain** — Repository layer for agency management (customer lists, invitations, health data)
- **Sidebar role arrays** — Menu items can now target multiple roles (e.g. `['agency', 'admin']`)
- **Impersonation role awareness** — "Return to Agency/Admin" button text and redirect now match the original user's role
- **Session impersonator role** — Stores originating role during impersonation for correct return routing

### Changed
- Agency users now see the standard customer dashboard at `/` instead of the legacy commission dashboard
- Site creation onboarding improved with guided first-site flow

### Fixed
- Impersonation stop redirect now correctly returns agency users to `/agency/customers` instead of `/admin/users`
- Banner list handler edge cases

---

## [v2.1.7] - 2026-03-16

### Added
- **Safe update flow** — running the installer on an existing installation now preserves data (no more `down -v`)
- **`--update` flag** — explicit update mode that pulls latest code, rebuilds containers, and runs new migrations without data loss

### Fixed
- Running the one-liner twice no longer wipes the database
- Admin account creation skipped on updates (existing credentials preserved)

---

## [v2.1.6] - 2026-03-16

### Fixed
- Include `oci_reports` and `oci_user_checklist_items` table migrations (used by core code)
- Guard A/B test routes with `class_exists` so they're skipped when the module isn't installed
- Prevent 500 errors from missing cloud module classes in self-hosted edition

---

## [v2.1.5] - 2026-03-16

### Fixed
- OCI self-hosted: resolved missing Monetization module dependency (moved PlanRepositoryInterface to Shared, added NullPlanRepository for unlimited self-hosted mode)

---

## [v2.1.4] - 2026-03-16

### Changed
- Default port changed from 8100 to 80 for simpler access (`http://localhost`)

---

## [v2.1.3] - 2026-03-16

### Fixed
- Installer now prompts for admin email when run via `curl | sh` (reads from /dev/tty)
- Uninstall confirmation prompt works correctly in piped mode

---

## [v2.1.2] - 2026-03-16

### Fixed
- Installer now always cleans stale Docker volumes before starting (fixes DB auth failure on reinstall)

---

## [v2.1.1] - 2026-03-16

### Fixed
- Reject-all button now properly deletes cookies on consent withdrawal
- Installer script improvements for reliability on fresh systems

---

## [v2.1.0] - 2026-03-16

### Added
- **One-Line Installer** — `curl -sSL https://getconzent.com/install | sh` with auto-install of Docker, Git, and Docker Compose on all major Linux distros (Debian, Ubuntu, Raspbian, CentOS, Fedora, Alpine, Arch, Amazon Linux, SUSE)
- **Installer animations** — Spinner progress indicators for long-running tasks
- **`--config` flag** — View saved admin credentials anytime via `bash scripts/install.sh --config`
- **`--uninstall` flag** — Clean removal of containers, volumes, and installation directory
- **Auto-generated admin credentials** — Installer prompts for email and generates a secure random password, saved to `.conzent-credentials`
- **LAN IP detection** — Success message shows both localhost and network URL for headless/Pi installs
- **Stop impersonation** — New handler to end admin-as-user sessions
- **`bin/oci setup` command** — CLI command for initial admin account creation

### Changed
- Docker Nginx config updated for production environments
- Test site analytics and tracking page configs updated

### Fixed
- Consent banner save flow and script regeneration
- New site creation redirect
- Custom layouts table migration
- Clarity and Amazon consent column migration
- Installer Docker permission handling (sudo fallback for fresh installs)

## [v2.0.0] - 2026-03-15

First public release of Conzent OCI as a self-hosted, open-source Consent Management Platform.

### Added
- **Consent Management** — Full consent collection, logging, and audit trail with date-range filtering and export
- **Cookie Detection** — Automatic cookie scanning with categorization (necessary, analytics, marketing, preferences)
- **Customizable Banners** — Multiple layout types (popup, banner, box), 7 position options, light/dark themes, full CSS control
- **IAB TCF v2.2 / v2.3** — Transparent Consent Framework support with self-registered CMP ID
- **Google Consent Mode v2** — Native integration with Google consent signals
- **Multi-Site Management** — Manage consent across unlimited websites from a single dashboard
- **Multi-Language Support** — Full i18n for banner content, cookie descriptions, and policies
- **Privacy & Cookie Policy Generator** — Built-in policy wizard auto-populated from detected cookies
- **Consent Reporting** — Trend visualization, acceptance/rejection stats, pageview tracking
- **Cookie Scanning** — On-demand and scheduled scans for cookies, scripts, and trackers
- **Associated Domains** — Share consent state across related domains
- **Compliance Checklists** — Guided setup for GDPR, GCM, CCPA, IAB/TCF
- **Google Tag Manager Integration** — OAuth-based GTM wizard for container setup
- **Google OAuth Sign-In** — Sign in with Google support
- **Script Generation Pipeline** — Auto-generated, minified consent scripts with cache-busting and CDN support
- **AI-Powered Translation** — Auto-translate banner content via OpenRouter
- **Audit Logging** — Comprehensive audit trail for administrative actions
- **Native Tracker Support** — Built-in support for Microsoft Clarity and Amazon tracking
- **Module System** — Extensible architecture for custom integrations
- **CLI Tools** — Health check, migration runner, cache clearing, queue worker, scheduler, script regeneration
- **Redis Integration** — Caching, sessions, and background job queues
- **Cloudflare Integration** — Edge cache purge on consent script regeneration

### Fixed
- GTM wizard connection detection (no longer falsely reports as connected)
- Policy template rendering errors
- Consent ID tracking in audit logs
- Script blocking using clean element creation (bypasses monkey-patched `createElement`)
- Reports page layout (full-width rendering)
- Site creation redirect flow
- JSON parsing in consent data handling
