# Autorizenter Use Cases

Here are a few common scenarios illustrating how Autorizenter's powerful feature set can be utilized to solve complex authentication requirements.

## 1. Corporate Intranet (Strict Domain Enforcement)

**Scenario:** A company wants their WordPress site to be used exclusively by employees.

**Configuration:**
- **Providers:** Enable Google Workspace (or Azure AD via Generic OIDC).
- **Policy:** Set the Allowed Domain to `@company.com`.
- **User Mapper:** Enable Auto-Provisioning.
- **Role Mapping:** Map `domain:company.com` to `Contributor` or `Editor` depending on needs. Disable "Link by Email" for public providers.

**Result:** Only users with a `@company.com` Google account can log in. Unauthorized users are immediately rejected. New employees are automatically provisioned.

## 2. Membership / E-Commerce Site

**Scenario:** A public website wants to reduce friction by allowing social logins, but needs to map existing customers correctly.

**Configuration:**
- **Providers:** Enable Facebook, LINE, and Google.
- **Policy:** Do not restrict domains. Require Verified Emails.
- **User Mapper:** Enable Auto-Provisioning. Enable **Link by Email**.
- **Role Mapping:** Default everyone to `Customer` or `Subscriber`.

**Result:** Users can register quickly using social accounts. If they previously created an account using `john@example.com` via WooCommerce, logging in with a Google account using the same email will securely link the accounts without creating duplicates.

## 3. High-Security Admin Portal

**Scenario:** A site wants public users to use standard passwords, but administrators *must* use a secure SSO provider (like Keycloak) to access `wp-admin`.

**Configuration:**
- **Contexts:** Create an `admin` context.
- **Providers:** Restrict the `admin` context so it only permits the generic OIDC provider.
- **Capability Gate:** Set the `admin` context to require the `manage_options` capability.
- **SSO Logout:** Enable Single Logout for the OIDC provider so logging out of WordPress also terminates the central IdP session.

**Result:** Administrators are forced through your secure IdP. If a standard user attempts to use the OIDC login, they will be rejected because they lack the `manage_options` capability, protecting the backend.

## 4. Multi-Tenant Education Platform

**Scenario:** A university platform serving both students and alumni with different permissions based on their ID structure.

**Configuration:**
- **User Mapper:** Use regex in Role Mapping.
  - Map `local:^\d{10}$` (10-digit student ID) to the `Student` role.
  - Map `domain:alumni.univ.edu` to the `Alumni` role.
- **Custom Hooks:** Use `authorizenter_custom_role_condition` to inspect claims returned from the OIDC provider (e.g. `department:engineering`) to assign granular WordPress roles or capabilities.

**Result:** Completely automated role management based on identity metadata and email structures.
