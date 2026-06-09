=== Autorizenter UI ===
Contributors: autorizenter
Tags: oauth2, oidc, sso, login form, social login
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Front-end for Autorizenter Core: login buttons, customizable question form, and auto-created pages.

== Description ==

Autorizenter UI provides a ready-made front-end on top of Autorizenter Core:

* `[autorizenter_login]` — renders sign-in buttons for every enabled provider.
* `[autorizenter_logout]` — renders a sign-out link (supports SSO logout).
* `[autorizenter_questions]` — renders the post-login question form.
* `[autorizenter_answers]` — displays the current user's submitted answers.
* `[autorizenter_stats]` — returns a plain aggregate count for a question (display it however you like).
* Block editor: **Autorizenter Login** and **Autorizenter Logout** blocks (same
  output as the shortcodes, with live preview).
* Auto-creates a "Sign in" page and a "A few questions" page on activation.
* Redirects users with pending required questions to the form automatically.

Requires **Autorizenter Core**. The UI talks to Core only through its REST API and
hooks, so you can replace it with your own front-end at any time.

== Installation ==

1. Install and activate **Autorizenter Core** first.
2. Install and activate **Autorizenter UI**.
3. Two pages are created automatically; or place the shortcodes on your own pages.

== Changelog ==

= Unreleased =
* `[autorizenter_login]` supports named login contexts (`context="…"`) with
  per-context providers, redirects, and questions.
* `[autorizenter_logout]` shortcode and the Gutenberg **Autorizenter Login** and
  **Autorizenter Logout** blocks (server-rendered, with live editor preview).
* Auto-creates a "A few questions" page and redirects users with pending required
  questions to the form automatically.
* Translation template (`languages/autorizenter.pot`).

= 0.1.0 =
* `[autorizenter_login]` and `[autorizenter_questions]` shortcodes.
* Auto-created login page on activation.
* Default templates and assets consuming Core.
