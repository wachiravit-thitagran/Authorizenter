# Contributing to Autorizenter

Thanks for your interest in improving Autorizenter!

## Repository layout

This is a **monorepo** containing two WordPress plugins under `plugins/`:

- `autorizenter-core` — the engine (required)
- `autorizenter-ui` — optional front-end

The Core exposes a stable **contract** (REST endpoints under `autorizenter/v1`,
plus action/filter hooks prefixed `autorizenter_`). The UI must only ever talk to
Core through that contract — never reach into Core internals. Changes that alter
the contract must update **both** plugins and the docs in the same PR.

## Development setup

```bash
composer install            # installs JWT lib + PHPCS + PHPUnit
```

Symlink the plugin(s) you are working on into a local WordPress
`wp-content/plugins/` directory and activate **Core first**.

## Coding standards

- Follow the **WordPress Coding Standards** (WPCS). Run `composer lint`.
- Prefix everything: functions/options `autorizenter_`, internal classes
  `Autorizenter\\` namespace.
- **Sanitize all input, escape all output.** Use nonces on state-changing requests.
- Keep all user-facing strings translatable with the `autorizenter` text domain.
- Never commit secrets. Configuration is entered via the admin UI / DB only.

## Security

OAuth callback handling and token/JWT verification live in **Core only** and are
security-critical. Please open a private report for any vulnerability rather than
a public issue.

## Pull requests

1. Branch from `main`.
2. Add/update tests where practical and run `composer test`.
3. Update `CHANGELOG.md` under **Unreleased**.
4. Ensure CI passes (PHPCS + PHPUnit).
