=== Authorizenter Core ===
Contributors: authorizenter
Tags: oauth2, oidc, sso, login, google
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Flexible OAuth2/OIDC Single Sign-On engine with organization restriction and customizable post-login questions.

== Description ==

Authorizenter Core is the engine behind Authorizenter. It authenticates users via
Google, LINE, Facebook, or any generic OAuth2/OIDC provider (Azure AD, Keycloak,
Okta, university SSO, ...), can restrict sign-in to your organization, and can gate
access behind customizable questions.

Core ships no opinionated front-end — it exposes a REST API (namespace `authorizenter/v1`) plus action/filter hooks. Install **Authorizenter UI** for ready login buttons and a question form, or build your own.

Features:

* Authorization Code flow with PKCE, state, and nonce.
* Generic OIDC adapter driven by a discovery URL.
* Built-in presets: Google, LINE, Facebook.
* Organization policy: email-domain allowlist, Google `hd` claim, trust-by-IdP.
* Auto-provisioning and account linking by verified email.
* Customizable post-login questions stored in user meta.

== Installation ==

1. Install and activate the plugin.
2. Run `composer install` in the plugin (or monorepo root) to provide its
   dependencies (jumbojett/openid-connect-php for OIDC; firebase/php-jwt). The
   GitHub release ZIPs bundle `vendor/`, so this step is only needed when running
   from source.
3. Go to Settings → Authorizenter, enable a provider, and paste its credentials.
4. Register the displayed callback URL with each provider.

Note: OIDC providers (Google, LINE, generic) require the server to make outbound
HTTPS requests to the IdP (token exchange and JWKS). If the server cannot reach
the internet, sign-in will fail regardless of configuration.

== Changelog ==

= Unreleased =
* OIDC sign-in (Google, LINE, generic) now uses the maintained
  jumbojett/openid-connect-php library for the code exchange and token
  verification; state/nonce/PKCE are managed in the PHP session. Facebook keeps
  the built-in OAuth2 path.
* Login contexts — named login profiles with per-context providers, a capability
  gate (`user_can()`), policy overrides, redirects, and questions.
* Deny-redirect fallback chain (context → global → context login page).
* Organization policy is now an explicit opt-in toggle (global + per-context
  override); off by default, any authenticated user is allowed.
* Access control parity with Authorizer: approved/blocked/pending access lists
  (per email or domain), role mapping (`domain:` / `provider:` / `email:` / `*`),
  failed-login throttling with progressive lockout, and private-site mode.
* Option to disable WordPress username/password sign-in (force SSO), with an
  administrator bypass to prevent lockout and a login-form notice.
* HTTPS enforcement for generic OIDC discovery URLs.
* Answer reporting: indexed per-question mirror meta, a Reports aggregator,
  Settings → Authorizenter Report (counts, drill-down, CSV export), and
  `GET /answers/report`.
* Logout: `/logout` REST route and optional RP-initiated (single) logout at the IdP.
* Self-hosted updates from GitHub Releases via `Github_Updater`, configurable with
  `AUTHORIZENTER_GITHUB_REPO` / `authorizenter_github_repo`. The plugin details
  screen now shows this changelog and full description.
* Structured admin editors for contexts and questions (replacing raw JSON).
* Translation template (`languages/authorizenter.pot`).
* PHPUnit unit test suite runnable without a full WordPress install.

= 0.1.0 =
* OAuth2 Authorization Code engine with PKCE, `state`, and `nonce`.
* Provider base class and adapters: Generic OIDC, Google, LINE, Facebook.
* Organization policy: email-domain allowlist, Google `hd` claim, trust-by-IdP.
* User mapper with auto-provisioning and account linking by verified email.
* Customizable post-login question system stored in user meta.
* REST API (`authorizenter/v1`) and action/filter hooks.
* Admin settings page.
