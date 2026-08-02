# Changelog

All notable changes to Authorizenter are documented here. Format based on
[Keep a Changelog](https://keepachangelog.com/), and this project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed
- **Logout works on sites that block `wp-login.php`.** Every logout link in
  WordPress (theme templates, the admin bar, Tutor LMS, plugins) is built from
  `wp_logout_url()`, which targets `wp-login.php`. Where a server-level hardening
  rule blocks that file, users could not log out at all. `wp_logout_url()` is now
  filtered to the `/logout` REST route; opt out with the
  `authorizenter_rest_logout` filter. Routing through the endpoint also means the
  REST cookie layer resolves the current user, so the session token is destroyed
  and RP-initiated SSO logout can run.
- `[authorizenter_logout]` and the logout block now build their link from
  `wp_logout_url()` instead of hard-coding the REST URL, so they inherit the
  nonce and any `logout_url` filtering.

### Security
- `/logout` now requires a valid `wp_rest` nonce when a session exists, closing a
  logout CSRF hole (previously `permission_callback` was `__return_true`).
  Unauthenticated requests are redirected without touching any session.
- **The administrator password bypass is scoped to `wp-login.php?external=wordpress`.**
  It used to be a capability check alone, so with password sign-in disabled an
  administrator password still worked on *any* form that submits credentials — a
  theme's or an LMS plugin's login form, REST, XML-RPC, application passwords —
  which quietly left the site's strongest account reachable by password from
  several public endpoints. The marker is now carried through the form POST as a
  hidden field and verified together with `$GLOBALS['pagenow']`, so a third-party
  form cannot smuggle it in.
- Away from that URL, a blocked login answers identically whether the password was
  correct, wrong, or the account does not exist. Previously a correct non-admin
  password produced a distinct "password sign-in is disabled" message, which
  confirmed valid credentials to anyone replaying a stolen list. On the escape
  hatch an administrator still gets WordPress's real verdict.

### Added
- **Login contexts** — named login profiles (`[authorizenter_login context="…"]`)
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
- Logout: `/logout` REST route, `[authorizenter_logout]` shortcode, and optional
  RP-initiated (single) logout at the IdP via `authorizenter_sso_logout`.
- Translation templates (`languages/authorizenter.pot`) for both plugins.
- Self-hosted updates from GitHub Releases: `Github_Updater` integrates with the
  WordPress Plugins screen, configurable via `AUTHORIZENTER_GITHUB_REPO` /
  `authorizenter_github_repo`, plus a release workflow that builds per-plugin ZIPs
  (with `vendor/` bundled) and attaches them on tag.
- Organization policy is now an explicit **opt-in** toggle (global + per-context
  override); off by default, any authenticated user is allowed.
- Gutenberg blocks: **Authorizenter Login** and **Authorizenter Logout** (dynamic,
  server-rendered via the shortcodes, with editor preview).
- Option to **disable WordPress username/password sign-in** (force SSO), with an
  administrator bypass to prevent lockout and a login-form notice.
- **Access control parity with Authorizer**: approved/blocked/pending access lists
  (per email or domain), role mapping (`domain:`/`provider:`/`email:`/`*` → role),
  failed-login throttling with progressive lockout, and private-site mode (require
  login to view the front-end).
- Answer reporting: indexed per-question mirror meta (`authorizenter_answer_{id}`),
  a `Reports` aggregator, **Settings → Authorizenter Report** (counts, drill-down
  respondent lists, CSV export), and `GET /answers/report` (`list_users`).

### Initial scaffold
- **Authorizenter Core 0.1.0** — initial scaffold.
  - OAuth2 Authorization Code engine with PKCE, `state`, and `nonce`.
  - Provider base class and adapters: Generic OIDC, Google, LINE, Facebook.
  - Organization policy: email-domain allowlist, Google `hd` claim, trust-by-IdP.
  - User mapper with auto-provisioning and account linking by verified email.
  - Customizable post-login question system stored in user meta.
  - REST API (`authorizenter/v1`) and action/filter hooks.
  - Admin settings page.
- **Authorizenter UI 0.1.0** — initial scaffold.
  - `[authorizenter_login]` and `[authorizenter_questions]` shortcodes.
  - Auto-created login page on activation.
  - Default templates and assets consuming Core.
