=== Authorizenter UI ===
Contributors: authorizenter
Tags: oauth2, oidc, sso, login form, social login
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Front-end for Authorizenter Core: login buttons, customizable question form, and auto-created pages.

== Description ==

Authorizenter UI provides a ready-made front-end on top of Authorizenter Core:

* `[authorizenter_login]` — renders sign-in buttons for every enabled provider.
* `[authorizenter_logout]` — renders a sign-out link (supports SSO logout).
* `[authorizenter_questions]` — renders the post-login question form.
* `[authorizenter_answers]` — displays the current user's submitted answers.
* `[authorizenter_stats]` — returns a plain aggregate count for a question (display it however you like).
* Block editor: **Authorizenter Login** and **Authorizenter Logout** blocks (same
  output as the shortcodes, with live preview).
* Auto-creates a "Sign in" page and a "A few questions" page on activation.
* Redirects users with pending required questions to the form automatically.

Requires **Authorizenter Core**. The UI talks to Core only through its REST API and
hooks, so you can replace it with your own front-end at any time.

== Installation ==

1. Install and activate **Authorizenter Core** first.
2. Install and activate **Authorizenter UI**.
3. Two pages are created automatically; or place the shortcodes on your own pages.

== Changelog ==

= Unreleased =
* `[authorizenter_login]` supports named login contexts (`context="…"`) with
  per-context providers, redirects, and questions.
* `[authorizenter_logout]` shortcode and the Gutenberg **Authorizenter Login** and
  **Authorizenter Logout** blocks (server-rendered, with live editor preview).
* Auto-creates a "A few questions" page and redirects users with pending required
  questions to the form automatically.
* Translation template (`languages/authorizenter.pot`).

= 0.1.0 =
* `[authorizenter_login]` and `[authorizenter_questions]` shortcodes.
* Auto-created login page on activation.
* Default templates and assets consuming Core.
