# Provider setup guide

This guide covers configuring each supported provider. In every case you will:

1. Create an OAuth client / app in the provider's console.
2. Register Autorizenter's **callback (redirect) URI**.
3. Paste the **Client ID** and **Client Secret** into **Settings → Autorizenter**.

## Callback / redirect URI

All providers use the **same** callback URL:

```
https://YOUR-SITE/wp-json/autorizenter/v1/callback
```

Replace `YOUR-SITE` with your domain. The exact URL is also shown at the top of
the Autorizenter settings screen. It must be served over **HTTPS** in production.

---

## Google

1. Go to the [Google Cloud Console](https://console.cloud.google.com/) → **APIs &
   Services → Credentials**.
2. **Create Credentials → OAuth client ID → Web application**.
3. Under **Authorized redirect URIs**, add the callback URL above.
4. Copy the **Client ID** and **Client secret** into the Google provider fields.

**Organization restriction (Workspace):** set your domain in **Allowed email
domains** (e.g. `psu.ac.th`) and enable **Require Google `hd` claim** for a
stronger check than email parsing alone.

Scopes used: `openid email profile`.

---

## LINE

1. Go to the [LINE Developers Console](https://developers.line.biz/console/).
2. Create a **Provider**, then a **LINE Login** channel.
3. Under the channel's **LINE Login** settings, add the callback URL above to
   **Callback URL**.
4. Use the channel's **Channel ID** as the Client ID and **Channel secret** as the
   Client Secret.

**Email:** LINE only returns an email if you have applied for and been granted the
**email permission** on the channel, and the user consents. Without it, LINE acts
as an identity-only provider — email-domain policy cannot apply, so either treat
LINE as a non-org login or add it to **Trusted providers** deliberately.

Scopes used: `openid profile email`.

---

## Facebook

1. Go to [Meta for Developers](https://developers.facebook.com/) → **My Apps →
   Create App** → **Consumer** (or as appropriate).
2. Add the **Facebook Login** product.
3. Under **Facebook Login → Settings**, add the callback URL above to **Valid OAuth
   Redirect URIs**.
4. From **App Settings → Basic**, copy **App ID** (Client ID) and **App Secret**
   (Client Secret).

**Note:** Facebook does not assert a standardized verified-email claim, so
Autorizenter treats Facebook emails as **unverified**. They will not satisfy an
email-domain policy or auto-link to existing accounts unless you explicitly trust
Facebook. Prefer Facebook only for non-org, public sign-in.

Scopes used: `public_profile email`.

---

## Generic OIDC (Azure AD / Entra ID, Keycloak, Okta, Auth0, university SSO…)

The **OIDC** provider works with any OpenID Connect compliant IdP via its discovery
document.

1. Register a new application / client in your IdP.
2. Set the **redirect URI** to the callback URL above.
3. Note the **Client ID** and **Client Secret**.
4. In the OIDC provider fields, paste those plus the **Discovery URL**
   (`.well-known/openid-configuration`). The URL **must be HTTPS**.

### Azure AD / Microsoft Entra ID

- Discovery URL:
  `https://login.microsoftonline.com/<TENANT_ID>/v2.0/.well-known/openid-configuration`
- Register the app in **Entra ID → App registrations**; add the redirect URI under
  **Authentication → Web**.
- Create a secret under **Certificates & secrets**.

### Keycloak

- Discovery URL:
  `https://<KEYCLOAK_HOST>/realms/<REALM>/.well-known/openid-configuration`
- Create a confidential client in the realm; set **Valid redirect URIs** to the
  callback URL; copy the client secret from the **Credentials** tab.

### Okta / Auth0

- Discovery URL: `https://<YOUR_DOMAIN>/.well-known/openid-configuration`
  (Auth0: `https://<TENANT>.auth0.com/.well-known/openid-configuration`).
- Create a **Web** application; add the callback URL; copy client id/secret.

**Custom button label & logo:** the generic OIDC provider shows "Continue with SSO"
with a lock icon by default. Set a **Label** (e.g. "PSU Passport") and a **Logo URL**
(a square image such as a 20×20 SVG/PNG) in the provider settings to brand the
button for your organization.

**Organization restriction:** because users authenticate through your own IdP,
add `oidc` to **Trusted providers** so they bypass the email-domain gate — the IdP
itself vouches for them. For privileged contexts, also set the context's
**Required capability** (e.g. `manage_options`) and **Auto-provision → No**.

Scopes used: `openid email profile` (override per provider if your IdP needs more,
e.g. to receive group/role claims).
