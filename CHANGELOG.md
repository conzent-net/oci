# Changelog

All notable changes to the Conzent OCI core will be documented in this file.

This project adheres to [Semantic Versioning](https://semver.org/).

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
