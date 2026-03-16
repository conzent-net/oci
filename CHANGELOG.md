# Changelog

All notable changes to the Conzent OCI core will be documented in this file.

This project adheres to [Semantic Versioning](https://semver.org/).

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
