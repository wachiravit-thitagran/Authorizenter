# Autorizenter Implementation Guide

Autorizenter is an advanced, extensible authentication and user-provisioning orchestration framework for WordPress. This guide explains the core concepts and how to configure them for your application.

## Core Concepts

### 1. Providers (Identity Providers)
Providers are the external services authenticating your users. Autorizenter supports standard OAuth2 and OpenID Connect (OIDC) providers.
- **Built-in Providers**: Google, Facebook, LINE.
- **Generic OIDC**: You can connect to any OpenID Connect compliant service (e.g. Auth0, Keycloak, Azure AD) by configuring the discovery endpoints.

### 2. Contexts
A "Context" defines *where* and *how* the user is logging in. For example, logging into the frontend of an e-commerce store is different from logging into the `wp-admin` dashboard.
- **Default Context**: General site login.
- **Admin Context**: Can be configured to strictly require a specific provider (like Google) and enforce that the logged-in user possesses the `manage_options` capability.

### 3. Identity Resolution & User Mapping
When a user returns from a provider, Autorizenter converts their data into a normalized `Identity` object. The `User_Mapper` then links this identity to a WordPress account:
1. **Link by Subject**: Matches the provider's unique ID (`sub`) to a previously linked account.
2. **Link by Email**: If enabled, matches a *verified* email address to an existing WP user.
3. **Auto-Provision**: If enabled, creates a new WordPress user automatically.

### 4. Org Policies (Access Control)
You can enforce strict organizational policies before a user is allowed in:
- **Allowed Domains**: Only allow emails ending in `@yourcompany.com`.
- **Verified Email Requirement**: Reject identities whose email address hasn't been verified by the provider.
- **Access Lists**: Maintain an explicit allow-list of approved emails.

### 5. Role Mapping
Instead of everyone becoming a `Subscriber`, Role Mapping allows you to assign WP roles dynamically based on the user's identity.
- You can match by `domain:yourcompany.com`, `email:admin@site.com`, `provider:google`, or custom regular expressions.
- This allows powerful workflows like: "Anyone from `@staff.com` becomes an Editor, everyone else becomes a Customer."

### 6. Pre-Approval Questions
If users need manual vetting, you can redirect them to a questions form (`[authorizenter_questions]`). Their account remains in a "Pending" state until an administrator reviews their answers and approves them.

## Setup Workflow
1. **Configure Providers**: Enter client IDs and secrets in the settings.
2. **Define Contexts**: Set up context rules (e.g. redirect URLs, capability gates).
3. **Set Security Policies**: Enable domain restrictions or email verification if needed.
4. **Deploy UI**: Use the provided shortcodes (`[authorizenter_login]`) on your front-end pages.
