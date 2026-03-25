# Changelog

All notable changes to the Conzent OCI core will be documented in this file.

This project adheres to [Semantic Versioning](https://semver.org/).

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

First public release of Conzent OCI as a self-hosted, source-available Consent Management Platform.

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
