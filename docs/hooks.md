# Autorizenter Hooks Reference

Autorizenter provides a robust set of WordPress Actions (`do_action`) and Filters (`apply_filters`) allowing developers to deeply customize the authentication flow, user provisioning, and redirect logic.

## 1. Authentication Flow (Actions)

These actions fire at various stages of the login and logout lifecycle.

- **`authorizenter_before_login`**: Fires immediately before a user is authenticated into WordPress (before `wp_set_auth_cookie`).
  *Parameters:* `WP_User $user`, `string $provider_id`, `Identity $identity`, `array $context`
- **`authorizenter_login_success`**: Fires after the user is successfully logged in.
  *Parameters:* `WP_User $user`, `string $provider_id`, `Identity $identity`, `array $context`
- **`authorizenter_login_failed`**: Fires when a login attempt fails at the provider level or during token exchange.
  *Parameters:* `WP_Error $error`, `string $provider_id`
- **`authorizenter_context_denied`**: Fires when an authenticated user is denied access to a specific context due to lack of capabilities.
  *Parameters:* `WP_User $user`, `array $context`
- **`authorizenter_before_logout`**: Fires immediately before local WordPress logout and SSO logout processes begin.
  *Parameters:* `int $user_id`, `string $provider_id`

## 2. User Provisioning & Mapping

- **`authorizenter_user_provisioned`** (Action): Fires when a completely new user is created in the WordPress database.
  *Parameters:* `WP_User $user`, `Identity $identity`
- **`authorizenter_user_linked`** (Action): Fires when an existing WordPress account is linked to a new identity provider.
  *Parameters:* `int $user_id`, `Identity $identity`
- **`authorizenter_user_name_updated`** (Action): Fires when user profile data (like first_name, last_name) is synced from the identity provider.
  *Parameters:* `WP_User $user`, `Identity $identity`, `array $update`
- **`authorizenter_provision_userdata`** (Filter): Filter the arguments passed to `wp_insert_user()` when auto-provisioning.
- **`authorizenter_provision_role`** (Filter): Modify the calculated user role before assigning it to a new user.
- **`authorizenter_generate_username`** (Filter): Change the base username generation logic before auto-incrementing numbers are added.
- **`authorizenter_pre_resolve_user`** (Filter): Short-circuit the user mapping process. Return a `WP_User` to bypass built-in logic.
- **`authorizenter_custom_role_condition`** (Filter): Add custom role mapping rules (e.g. mapping `group:admin` from an external system).
- **`authorizenter_sync_user_name_data`** (Filter): Modify the user data array before it updates the user profile name.

## 3. URLs, Redirects, and TTLs

- **`authorizenter_login_return_to`** (Filter): Filter the initial post-login destination URL.
- **`authorizenter_post_login_redirect`** (Filter): Final filter for the redirect URL after successful login.
- **`authorizenter_authorization_url`** (Filter): Modify the authorization URL before sending the user to the Identity Provider (useful for adding custom OAuth parameters like `login_hint`).
- **`authorizenter_flow_ttl`** (Filter): Change the transient expiration time for the OAuth flow (default: 600 seconds).
- **`authorizenter_pending_redirect`** (Filter): Change where unapproved users are sent.
- **`authorizenter_login_url`** / **`authorizenter_context_login_url`** (Filters): Override the default fallback login URLs.

## 4. Identities & Policies

- **`authorizenter_identity`** (Filter): Inspect or modify the `Identity` object immediately after it's received from the provider but before any policy checks.
- **`authorizenter_is_allowed`** (Filter): Override whether an identity is allowed to log in (bypass domain or email verification restrictions).
- **`authorizenter_allowed_domains`** (Filter): Dynamically modify the list of allowed email domains.
- **`authorizenter_context_capability`** (Filter): Adjust whether a user meets the capability requirements for a context.

## 5. System Configuration

- **`authorizenter_provider_classes`** (Filter): Register custom identity provider classes.
- **`authorizenter_oidc_client`** (Filter): Intercept the `Oidc_Client` instance to adjust underlying OpenID Connect configurations.
- **`authorizenter_sso_logout`** (Filter): Enable or disable Single Logout (RP-Initiated Logout) for specific providers.
