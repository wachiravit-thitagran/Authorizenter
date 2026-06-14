# Autorizenter Shortcodes

This document provides a comprehensive list of all shortcodes available in the Autorizenter plugin and their intended use cases.

## Authentication & Buttons

### `[authorizenter_login]`
Renders a container with login buttons for all enabled and permitted identity providers. It automatically respects the context it is being rendered in.
**Example:** `[authorizenter_login context="default"]`

### `[authorizenter_button]`
Renders a single login button for a specific provider.
**Attributes:**
- `provider` (string, required): The ID of the provider (e.g., `google`, `facebook`, `line`).
- `context` (string, optional): The login context ID. Default is `default`.
**Example:** `[authorizenter_button provider="google" context="admin"]`

### `[authorizenter_logout]`
Renders a logout link or button for the currently authenticated user.
**Attributes:**
- `return_to` (string, optional): URL to redirect to after logout.
**Example:** `[authorizenter_logout return_to="/goodbye"]`

### `[authorizenter_url]`
Outputs the raw URL for logging in or logging out. Useful for embedding in theme templates or custom buttons.
**Attributes:**
- `action` (string): `login` or `logout`.
- `provider` (string): For login, which provider to use.
**Example:** `<a href="[authorizenter_url action='login' provider='google']">Sign in with Google</a>`

## User Workflow & Questions

### `[authorizenter_questions]`
Renders the pre-approval questions form. This is used when a user registers but the organization policy requires answering specific questions before their account is fully approved.
**Example:** `[authorizenter_questions]`

### `[authorizenter_answers]`
Renders a read-only view of the answers submitted by the current user. Useful for user profile pages.
**Example:** `[authorizenter_answers]`

### `[authorizenter_pending_form]`
Renders the "pending approval" screen or status message. This shortcode is typically placed on the designated "Pending Page" where users are redirected after successful authentication but before admin approval.
**Example:** `[authorizenter_pending_form]`

## Miscellaneous

### `[authorizenter_stats]`
Displays basic statistics regarding user logins or account approvals (depends on configuration and user capabilities).
**Example:** `[authorizenter_stats]`
