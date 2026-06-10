# Autorizenter — Hooks & REST reference

The Core plugin is the contract. The UI plugin (or any front-end you build) must
only depend on what is documented here.

## REST API

Namespace: `autorizenter/v1`

| Method | Route | Auth | Purpose |
|--------|-------|------|---------|
| GET | `/providers?context=` | public | List providers allowed in a context, with `authorize_url`. |
| GET | `/authorize/{provider}?context=&return_to=` | public | 302 redirect into the provider. Browser entry point. |
| GET | `/callback` | public | Provider redirect target; completes login, then redirects. |
| GET | `/logout?return_to=` | public | Ends the session; optionally redirects via the IdP (see `autorizenter_sso_logout`). |
| GET | `/questions` | logged-in | Pending questions for the current user. |
| POST | `/answers` | logged-in (nonce) | Submit `{ "answers": { id: value } }`. |
| GET | `/answers/report` | `list_users` | Per-question aggregate counts and breakdowns. |

The `context` parameter is read on `/authorize` and stored server-side; the
`/callback` derives the context from that stored flow state, never from the query.

`POST /answers` requires the `X-WP-Nonce` header (`wp_rest` nonce).

### Example: list providers

```js
fetch('/wp-json/autorizenter/v1/providers')
  .then(r => r.json())
  .then(d => console.log(d.providers));
// [{ id:'google', label:'Google', authorize_url:'…/authorize/google' }, …]
```

## Action hooks

| Hook | Args | Fires |
|------|------|-------|
| `autorizenter_login_success` | `WP_User $user, string $provider, Identity $identity, array $context` | After a successful login. |
| `autorizenter_user_provisioned` | `WP_User $user, Identity $identity` | When a new user is created. |
| `autorizenter_context_denied` | `WP_User $user, array $context` | When a user fails a context's capability gate. |
| `autorizenter_answers_saved` | `int $user_id, array $answers` | After answers are stored. |
| `autorizenter_questions_completed` | `int $user_id` | When all required questions are answered. |

## Filter hooks

| Filter | Signature | Use |
|--------|-----------|-----|
| `autorizenter_provider_classes` | `array $classes` | Register custom provider adapters (`id => class`). |
| `autorizenter_oidc_client` | `OpenIDConnectClient $client, array $config` | Tune the jumbojett OIDC client before the flow runs (e.g. `setHttpProxy`, `setVerifyHost`, provider-config overrides). OIDC providers only. |
| `autorizenter_authorization_args` | `array $args, string $provider_id` | Tweak the authorization request query. |
| `autorizenter_allowed_domains` | `string[] $domains` | Programmatically extend the domain allowlist. |
| `autorizenter_is_allowed` | `true\|WP_Error $result, Identity $identity` | Final allow/deny decision. |
| `autorizenter_post_login_redirect` | `string $url, WP_User $user` | Change the post-login destination. |
| `autorizenter_questions_url` | `string $url, string $return_to` | Where the question gate redirects. |
| `autorizenter_login_url` | `string $url` | Login page used for error redirects. |
| `autorizenter_context` | `array $context, string $id` | Modify a resolved login context. |
| `autorizenter_context_capability` | `bool $ok, WP_User $user, array $context` | Override the per-context capability decision. |
| `autorizenter_context_login_url` | `string $url, string $context_id` | Login page used as a context's deny fallback. |
| `autorizenter_sso_logout` | `bool $enabled, string $provider_id` | Enable RP-initiated logout at the IdP (OIDC `end_session_endpoint`). Default off. |
| `autorizenter_disable_password_auth` | `bool $disabled` | Force-disable WordPress username/password sign-in (overrides the setting). |
| `autorizenter_provision_role` | `string $role, Identity $identity` | Adjust the role assigned to a newly provisioned user. |
| `autorizenter_existing_account_skips_approval` | `bool $allow, Identity $identity` | Whether an identity that already has a WordPress account bypasses the approved-list/pending gate. Mirrors the "Existing accounts" setting (default on). |
| `autorizenter_private_allow` | `bool $allowed` | Let a specific front-end request through while private-site mode is on. |
| `autorizenter_login_page_id` | `int $id` | The login page id (used to allow it under private-site mode). |

## SSO button / URL shortcodes

Display and logic are split between the two plugins:

| Shortcode | Owner | Attributes | Returns |
|-----------|-------|------------|---------|
| `[autorizenter_url]` | Core | `provider`, `context` (default `default`), `return_to` | The bare authorize URL string only (no markup) — for custom links, redirects, or feeding other shortcodes/templates. Works with Core alone. |
| `[autorizenter_button]` | UI | `provider`, `context` (default `default`), `return_to` | Styled single-provider login link (brand icon + label). Requires the **Autorizenter UI** plugin. |

Both resolve identically: empty output when `provider` is missing, the provider
is not enabled in the `context`, or the visitor is already logged in. `return_to`
defaults to the current URL.

Because Core never renders markup, the **Label** and **Logo URL** provider
settings only appear in the admin once the UI plugin is active; with Core alone,
authentication still works but those display settings are hidden.

## Access control & security (Authorizer-style)

- **Access lists** (`Settings → Access control`): approve or block individual
  emails or domains. Blocked entries are always denied; with enforcement on, only
  approved identities may sign in and others are collected as **pending** for
  review. Blocking applies even when organization policy is off. By default
  **existing WordPress accounts skip approval** (the "Existing accounts" toggle /
  `autorizenter_existing_account_skips_approval` filter), since they were vetted
  when the account was created; blocked entries still win.
- **Role mapping** (`Settings → User provisioning`): `matcher = role` lines map new
  users to roles. Conditions: `domain:`, `provider:`, `email:`, `username:`,
  `regex:` (full-email regex), `local:` (regex on the part before `@`), or `*`.
  Build boolean expressions with standard precedence: `()` highest, then `!` (NOT),
  `&&` (AND), `||` (OR) lowest. Quote an atom whose value contains operator
  characters (regex with parens/alternation). Example — a 10- or 13-digit student ID
  from the org IdP, or any alumni address, gets `student`:
  `( provider:oidc && "local:^(\d{10}|\d{13})$" ) || domain:alumni.example.org = student`.
- **Failed-login throttling** (`Settings → Login security`): locks an IP after N
  failed password attempts, with a progressively longer lockout.
- **Private site** (`Settings → Login security`): redirects anonymous visitors to
  sign in before viewing any front-end content.

## Disabling password sign-in

**Settings → Autorizenter → Login security** can disable WordPress
username/password login so users must authenticate via a provider. An
**Administrator bypass** (on by default) keeps `manage_options` users able to log
in with a password, preventing lockout if the IdP becomes unreachable; turn it off
once SSO is verified. Only interactive password logins are affected — cookie auth,
application passwords, and the SSO flow use separate paths.
| `autorizenter_identity` | `Identity $identity, array $context` | Inspect/modify identity (now context-aware). |

## Login contexts

A **context** is a named login profile. Pages opt into one via the shortcode
attribute `[autorizenter_login context="admin"]`. Each context can:

- show a subset of providers (`providers`, empty = all enabled),
- require a WordPress **capability** (`required_capability`, default `read`),
- enable/disable org policy enforcement (`policy_enabled`: `null` inherit global,
  `true` on, `false` off) — policy is **opt-in**; off by default,
- override `allowed_domains` / `trusted_providers` / `auto_provision`
  (set to `null` to inherit the global policy),
- send users to its own `redirect` on success and `deny_redirect` on refusal,
- limit which `questions` apply.

Access control uses **capabilities**, not role names, so it survives custom roles
and multisite. Example: a `default` context for everyone at `/auth/`, and an
`admin` context requiring `manage_options` at `/auth-admin/`:

```json
{
  "default": { "label": "Sign in", "providers": ["google","line","facebook"], "required_capability": "read", "redirect": "/" },
  "admin":   { "label": "Admin sign in", "providers": ["oidc"], "required_capability": "manage_options", "trusted_providers": ["oidc"], "redirect": "/wp-admin/" }
}
```

Deny fallback chain when a context refuses a user: the context's `deny_redirect`
→ the global `deny_redirect` → the context's own login page with
`?autorizenter_error=…`.

## Answer storage & reporting

Each user's answers are stored two ways:

- `autorizenter_answers` (user meta) — the full array `{ id: value }`. Checkboxes
  are stored as booleans.
- `autorizenter_answer_{id}` (user meta) — a per-question **indexed mirror** (`'1'`
  / `'0'` for checkboxes, the string value otherwise), written so reports can query
  with an index instead of `LIKE`-matching the serialized blob.

Query examples (e.g. "who are the volunteers?"):

```php
// Indexed, fast:
$q = new WP_User_Query( array(
    'meta_key'   => 'autorizenter_answer_is_bia_volunteer',
    'meta_value' => '1',
) );
echo $q->get_total();            // count
$people = $q->get_results();     // WP_User[]
```

Or use the aggregator directly:

```php
$reports = \Autorizenter\Core\autorizenter_core()->reports;
$reports->summary();                          // per-question counts + breakdown
$reports->respondents( 'is_bia_volunteer', '1' ); // who answered "yes"
$reports->matrix();                           // full grid for CSV export
```

Admins also get **Settings → Autorizenter Report** (per-question counts, drill-down
respondent lists, CSV export) and the REST route `GET /answers/report`
(`list_users` capability).

## Writing a custom provider

Extend `Autorizenter\Core\Provider_Base` (or `Providers\OIDC` for an OIDC IdP) and
register it:

```php
add_filter( 'autorizenter_provider_classes', function ( $classes ) {
    $classes['github'] = My_GitHub_Provider::class;
    return $classes;
} );
```

Your `exchange()` must return an `Autorizenter\Core\Identity` (or `WP_Error`).

## Restricting to an organization in code

```php
// Allow only a domain, and trust your org IdP outright.
add_filter( 'autorizenter_allowed_domains', fn( $d ) => array_merge( $d, [ 'psu.ac.th' ] ) );

add_filter( 'autorizenter_is_allowed', function ( $ok, $identity ) {
    if ( 'oidc' === $identity->provider ) {
        return true; // already authenticated by the org IdP.
    }
    return $ok;
}, 10, 2 );
```
