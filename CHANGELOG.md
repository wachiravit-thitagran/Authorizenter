# Changelog

All notable changes to Autorizenter are documented here. Format based on
[Keep a Changelog](https://keepachangelog.com/), and this project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **Login contexts** — named login profiles (`[autorizenter_login context="…"]`)
  with per-context providers, capability gate, policy overrides, redirects, and
  questions. Capability checks use `user_can()` (not role names).
- Deny-redirect fallback chain (context → global → context login page).
- Structured admin editors for contexts and questions (replace raw JSON), with a
  type dropdown (checkbox/radio/select/text/textarea) and per-line options.
- HTTPS enforcement for generic OIDC discovery URLs.
- `SECURITY.md` threat model and `docs/providers.md` setup guide.
- PHPUnit unit test suite (context resolver, org policy + capability gate,
  questions validation, provider filtering, PKCE vector) runnable without a full
  WordPress install.
- Logout: `/logout` REST route, `[autorizenter_logout]` shortcode, and optional
  RP-initiated (single) logout at the IdP via `autorizenter_sso_logout`.
- Translation templates (`languages/autorizenter.pot`) for both plugins.
- Self-hosted updates from GitHub Releases: `Github_Updater` integrates with the
  WordPress Plugins screen, configurable via `AUTORIZENTER_GITHUB_REPO` /
  `autorizenter_github_repo`, plus a release workflow that builds per-plugin ZIPs
  (with `vendor/` bundled) and attaches them on tag.
- Organization policy is now an explicit **opt-in** toggle (global + per-context
  override); off by default, any authenticated user is allowed.
- Gutenberg blocks: **Autorizenter Login** and **Autorizenter Logout** (dynamic,
  server-rendered via the shortcodes, with editor preview).
- Option to **disable WordPress username/password sign-in** (force SSO), with an
  administrator bypass to prevent lockout and a login-form notice.
- **Access control parity with Authorizer**: approved/blocked/pending access lists
  (per email or domain), role mapping (`domain:`/`provider:`/`email:`/`*` → role),
  failed-login throttling with progressive lockout, and private-site mode (require
  login to view the front-end).
- Answer reporting: indexed per-question mirror meta (`autorizenter_answer_{id}`),
  a `Reports` aggregator, **Settings → Autorizenter Report** (counts, drill-down
  respondent lists, CSV export), and `GET /answers/report` (`list_users`).

### Initial scaffold
- **Autorizenter Core 0.1.0** — initial scaffold.
  - OAuth2 Authorization Code engine with PKCE, `state`, and `nonce`.
  - Provider base class and adapters: Generic OIDC, Google, LINE, Facebook.
  - Organization policy: email-domain allowlist, Google `hd` claim, trust-by-IdP.
  - User mapper with auto-provisioning and account linking by verified email.
  - Customizable post-login question system stored in user meta.
  - REST API (`autorizenter/v1`) and action/filter hooks.
  - Admin settings page.
- **Autorizenter UI 0.1.0** — initial scaffold.
  - `[autorizenter_login]` and `[autorizenter_questions]` shortcodes.
  - Auto-created login page on activation.
  - Default templates and assets consuming Core.
