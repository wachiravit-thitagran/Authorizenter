# Security Policy

## Reporting a vulnerability

Please report security issues **privately** (e.g. via a GitHub security advisory)
rather than opening a public issue. Include reproduction steps and the affected
version. We aim to acknowledge reports promptly and coordinate a fix before public
disclosure.

## Threat model & design notes

Authorizenter authenticates users against external identity providers and maps them
to WordPress accounts. The security-critical surface lives entirely in
**Authorizenter Core**; the UI plugin only renders buttons and forms.

### Authentication flow

- **Authorization Code + PKCE.** Every flow uses a 256-bit `state`, a `nonce`, and
  an S256 PKCE `code_verifier`/`code_challenge`. Flow state is stored server-side
  in a transient keyed by a SHA-256 of `state`; the `/callback` reads provider,
  context, nonce, and verifier from that record, never from the query string.
- **Single-use flows.** The transient is deleted as soon as the callback consumes
  it, preventing replay.
- **id_token verification.** OIDC `id_token`s are verified with `firebase/php-jwt`
  against the provider JWKS, checking signature, `iss`, `aud`, and `nonce` with a
  60-second clock leeway. If the JWT library is missing, verification **fails
  closed** (no token is trusted).
- **HTTPS enforcement.** Generic OIDC discovery URLs must be HTTPS (localhost is
  allowed for development). Google/LINE/Facebook endpoints are hardcoded to HTTPS.

### Organization & access policy

- **Email-domain allowlist** with exact and subdomain matching, plus an optional
  **verified-email** requirement and Google Workspace **`hd`** claim check.
- **Trusted providers** (e.g. your own org IdP) may bypass domain checks, since the
  IdP itself vouches for the user.
- **Per-context capability gate.** Access to a context is enforced with WordPress
  **capabilities** via `user_can()` (not role-name comparison), so it is robust to
  custom roles and multisite super admins. The check runs *after* authentication —
  separate login pages are not themselves a security boundary.

### Account integrity

- **Account linking by email only when verified.** Auto-linking an OAuth identity
  to an existing WordPress user requires `email_verified`, preventing takeover via a
  provider that does not verify emails. Facebook emails are treated as unverified.
- **Auto-provisioning** can be disabled globally or per context. For privileged
  contexts (e.g. `admin`), set `auto_provision: false` so only pre-existing,
  capable accounts can enter. Note: when auto-provisioning is enabled, a new user
  is created before the capability gate runs; that account simply cannot access the
  privileged context.

### Secrets

- Provider client secrets are stored **encrypted at rest** (AES-256-CBC with a key
  derived from `AUTH_KEY`/`AUTH_SALT`). This protects against casual DB exposure; it
  is not a substitute for securing the database and WordPress salts. If OpenSSL is
  unavailable the value is stored base64-encoded — install OpenSSL in production.
- No secrets are written to source. `.env` is git-ignored; credentials are entered
  through the admin UI only.

### Redirects

- Post-login and deny redirects use `wp_safe_redirect()` (same-host only). The
  `return_to` parameter is passed through `wp_validate_redirect()` before storage,
  preventing open-redirect abuse. Redirects to the external IdP use `wp_redirect()`
  by necessity and target only the configured provider authorization endpoint.

### REST endpoints

- `/providers`, `/authorize`, `/callback` are intentionally public (they are the
  login entry/return points). `/questions` and `/answers` require an authenticated
  user; `/answers` is a cookie-authenticated POST protected by the REST nonce.

## Residual risks / recommendations

- Login CSRF (an attacker initiating a login) is not specifically mitigated beyond
  `state` binding the callback; this is standard for SSO and low-impact.
- Keep `firebase/php-jwt` updated (Dependabot recommended).
- Always serve the WordPress site over HTTPS so auth cookies and redirects are safe.
- Rotate WordPress salts carefully: doing so invalidates stored encrypted secrets,
  which must then be re-entered.

### Link ownership and cleanup

Verified-email linking remains supported. Automatic email/username matching can
create an initial provider association, but cannot replace a different existing
subject. Changing an established association requires an administrator to review
and remove the old link; changing an issuer behind the same provider ID also
requires an explicit migration. Provider IDs namespace the stored subjects.

New associations use MySQL/MariaDB connection-scoped `GET_LOCK` locks for both the
provider/subject and the user/provider slot. Core and compatible account-linking
extensions must use `User_Mapper::claim_link()` rather than writing link meta.
Unavailable/contended locks and failed writes reject the sign-in before issuing
cookies. Database proxies must keep lock/check/write operations on the same
primary connection. Existing duplicate subjects fail closed and need manual
reconciliation; this update does not silently rewrite existing ownership.

Uninstall removes stored provider claims, question answers, provider links and
pending access tokens, as well as settings and authentication caches. User
accounts remain. UI uninstall deletes only pages carrying explicit creation
provenance whose content still matches the generated content; reused, legacy
unmarked and subsequently customized pages remain. The pending page follows the
same rule. Core user identity metadata is global to WordPress's user table;
option/transient cleanup applies to the blog where uninstall runs. Network-wide
retention/removal must be reviewed before multisite uninstall.

CSV exports prefix formula-like cells with an apostrophe, including cells with
leading control whitespace and negative numbers. Stored answers are unchanged.
