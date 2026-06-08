=== Autorizenter Core ===
Contributors: autorizenter
Tags: oauth2, oidc, sso, login, google
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Flexible OAuth2/OIDC Single Sign-On engine with organization restriction and customizable post-login questions.

== Description ==

Autorizenter Core is the engine behind Autorizenter. It authenticates users via
Google, LINE, Facebook, or any generic OAuth2/OIDC provider (Azure AD, Keycloak,
Okta, university SSO, ...), can restrict sign-in to your organization, and can gate
access behind customizable questions.

Core ships no opinionated front-end — it exposes a REST API (namespace
`autorizenter/v1`) plus action/filter hooks. Install **Autorizenter UI** for ready
login buttons and a question form, or build your own.

Features:

* Authorization Code flow with PKCE, state, and nonce.
* Generic OIDC adapter driven by a discovery URL.
* Built-in presets: Google, LINE, Facebook.
* Organization policy: email-domain allowlist, Google `hd` claim, trust-by-IdP.
* Auto-provisioning and account linking by verified email.
* Customizable post-login questions stored in user meta.

== Installation ==

1. Install and activate the plugin.
2. Run `composer install` in the plugin (or monorepo root) to provide firebase/php-jwt.
3. Go to Settings → Autorizenter, enable a provider, and paste its credentials.
4. Register the displayed callback URL with each provider.

== Changelog ==

= 0.1.0 =
* Initial release.
