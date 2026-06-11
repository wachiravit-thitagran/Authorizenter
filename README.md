# Authorizenter

[![CI](https://github.com/authorizenter/authorizenter/actions/workflows/ci.yml/badge.svg)](https://github.com/authorizenter/authorizenter/actions/workflows/ci.yml)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

> Flexible OAuth2 / OIDC Single Sign-On for WordPress — with organization restriction and customizable post-login questions.

Authorizenter lets a WordPress site authenticate users via **Google, Facebook, LINE, or any generic OAuth2/OIDC provider** (Azure AD, Keycloak, Google Workspace, university SSO such as PSU Passport, etc.). It can **restrict sign-in to your organization** (by email domain, Google `hd` claim, or by trusting your org's own IdP) and can **gate access behind a customizable question form** after login (e.g. checkboxes, radios, free text).

It is built as a **monorepo with two plugins**:

| Plugin | Folder | Required | Responsibility |
|--------|--------|----------|----------------|
| **Authorizenter Core** | `plugins/authorizenter-core` | ✅ | OAuth2/OIDC engine, providers, org policy, user provisioning, questions, REST API + hooks. No opinionated UI. |
| **Authorizenter UI** | `plugins/authorizenter-ui` | optional | Login buttons, question form, shortcodes/blocks, settings convenience pages — consumes Core only. |

This separation means you can ship the Core engine and build your **own** front-end (React, Elementor, a custom theme) on top of the documented hooks and REST API, or just install the UI plugin for a working experience out of the box.

## Features

- Authorization Code flow with **PKCE**, `state`, and `nonce` protection
- **Generic OIDC adapter** driven by a discovery URL (`.well-known/openid-configuration`) — works with virtually any compliant IdP
- Built-in **presets**: Google, LINE, Facebook
- **Organization restriction**: email-domain allowlist, Google Workspace `hd` claim, or trust-by-IdP
- **Auto-provisioning** of WordPress users on first login, with account linking by verified email
- **Customizable questions** shown after login, stored in user meta, exportable
- **REST API + action/filter hooks** for full extensibility
- i18n-ready, GPL-2.0+, no secrets in source

## Installation (development)

```bash
git clone https://github.com/<you>/authorizenter.git
# Symlink or copy the plugin you want into wp-content/plugins/
ln -s "$(pwd)/authorizenter/plugins/authorizenter-core" /path/to/wp-content/plugins/authorizenter-core
ln -s "$(pwd)/authorizenter/plugins/authorizenter-ui"   /path/to/wp-content/plugins/authorizenter-ui
# Install dev dependencies (JWT lib + tooling)
composer install
```

Activate **Authorizenter Core** first, then **Authorizenter UI** (optional).

## Configuration

All configuration lives in **Settings → Authorizenter** (added by Core). Nothing is hardcoded to any single organization.

### Example: restrict to a university domain

Organization policy is **opt-in** — by default any authenticated user is allowed,
so turn it on to restrict.

1. Enable the **Google** provider, paste its Client ID / Secret.
2. Under **Organization policy**, tick **Enforce organization policy** and set
   allowed domains to `psu.ac.th`.
3. (Optional) Require the Google `hd` claim to equal `psu.ac.th` for stronger guarantees.

Each login context can override this (inherit / on / off), so you can enforce the
policy on one context and leave another open.

### Example: trust your org IdP (PSU Passport / Azure AD / Keycloak)

1. Enable the **Generic OIDC** provider.
2. Paste the discovery URL, e.g. `https://idp.example.ac.th/.well-known/openid-configuration`.
3. Paste Client ID / Secret. Users who authenticate through this IdP are treated as in-org.

### Example: a custom question

In **Settings → Authorizenter → Questions**, add:

```json
{
  "id": "is_bia_volunteer",
  "type": "checkbox",
  "label": "Are you a volunteer from bia.psu.ac.th?",
  "required": true
}
```

After login, users must answer required questions before reaching the site.

### Example: separate login pages (`/auth/` and `/auth-admin/`)

Authorizenter supports **login contexts** — named profiles you attach to any page.
Each context can show different providers, apply its own policy, require a
capability, and redirect differently.

1. In **Settings → Authorizenter → Login contexts**, configure two contexts using
   the form (each context is a fieldset; blank rows add new ones):

   - `default` — providers Google/LINE/Facebook, required capability `read`, redirect `/`
   - `admin` — provider OIDC only, required capability `manage_options`, OIDC trusted, redirect `/wp-admin/`

2. Place each on its own page:

   ```
   /auth/        →  [authorizenter_login context="default"]
   /auth-admin/  →  [authorizenter_login context="admin"]
   ```

The UI plugin auto-creates a page per context for you. Access is enforced by
**capability** after authentication, so a `subscriber` who opens `/auth-admin/`
is refused even though the page is public. See [`docs/hooks.md`](docs/hooks.md).

## Redirect / Callback URL

Register this callback in each provider's console:

```
https://your-site.example/wp-json/authorizenter/v1/callback
```

## For developers

See [`docs/`](docs/) for the full hook & REST reference. Quick taste:

```php
// React to a successful login
add_action( 'authorizenter_login_success', function ( $user, $provider ) {
    // ...
}, 10, 2 );

// Programmatically extend allowed domains
add_filter( 'authorizenter_allowed_domains', function ( $domains ) {
    $domains[] = 'alumni.psu.ac.th';
    return $domains;
} );
```

REST endpoints (namespace `authorizenter/v1`): `GET /providers`, `GET /authorize/{provider}`, `GET /callback`, `GET /questions`, `POST /answers`.

## Updates from GitHub Releases

Both plugins update themselves from this repository's **GitHub Releases** — the
WordPress *Plugins* screen shows an available update when a newer release tag
exists, and installs it like any other plugin.

Setup:

1. Point the plugins at your repository. Either edit the `AUTHORIZENTER_GITHUB_REPO`
   constant in `authorizenter-core.php` (default `authorizenter/authorizenter`), or
   filter it:

   ```php
   add_filter( 'authorizenter_github_repo', fn() => 'your-org/your-repo' );
   ```

2. Cut a release with a version tag (e.g. `v0.2.0`). The bundled workflow
   (`.github/workflows/release.yml`) builds and attaches `authorizenter-core.zip`
   and `authorizenter-ui.zip` (with `vendor/` bundled) to the release. The updater
   downloads the asset matching each plugin's slug.

3. Bump the `Version:` header in each plugin's main file to match the tag so
   WordPress detects the new version.

For private repos or higher API rate limits, add a token:

```php
add_filter( 'authorizenter_github_request_args', function ( $args ) {
    $args['headers']['Authorization'] = 'Bearer ' . MY_GITHUB_TOKEN;
    return $args;
} );
```

Update checks are cached for 6 hours.

## Roadmap / TODO

Planned but not yet implemented. Contributions welcome.

### Authentication providers

- [ ] **Native LDAP / Active Directory adapter** — direct bind authentication
      (requires the PHP `ldap` extension; LDAPS/TLS for production). Highest
      priority — many organizations already run AD.
- [ ] **CAS adapter** — for sites with an Apereo CAS server. Newer CAS versions can
      also act as an OIDC provider, so the existing **Generic OIDC** provider may
      cover many CAS deployments today without a native adapter.

> Tip: until native LDAP/CAS land, you can bridge them through the existing OIDC
> provider using **Keycloak** (LDAP/AD user federation → OIDC), which needs no code
> changes here.

### Developer experience

- [ ] **docker-compose test fixtures** — OpenLDAP + Keycloak with seeded test users
      so contributors can exercise the auth flows locally.
- [ ] Deeper **multisite** support (network-level settings, per-site overrides).

### Housekeeping

- [ ] Replace the placeholder [LICENSE](LICENSE) with the full GPL-2.0 text before
      publishing.
- [ ] Run `composer test` and `composer lint` on a real PHP environment and wire up
      green CI badges.
- [ ] Set `AUTHORIZENTER_GITHUB_REPO` to the canonical repository.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
