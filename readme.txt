=== Email Router ===
Contributors: mjoslyn
Tags: email, wp_mail, routing, smtp, notifications
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Intercept outgoing WordPress emails and route or replace recipients based on target addresses, subject-line patterns, and a blacklist.

== Description ==

Email Router hooks into `wp_mail` and rewrites the recipient list before mail leaves the site. It is useful for redirecting mail during development, routing notifications to the right team based on subject, and blocking addresses that should never receive mail.

It adds a **Tools &rarr; Email Router** admin page with four tabs:

* **Email Replacements** — replace a target address with one or more recipients (comma-separated).
* **Subject Patterns** — route mail to specific recipients when the subject matches a regular expression.
* **Blacklist** — strip specific addresses from every outgoing message.
* **Tools** — bulk-remove an address, scan the site for where an address is used, and export/import settings as JSON.

All rules are stored in a single option (`email_router_settings`).

== Frequently Asked Questions ==

= Does this send email itself? =

No. It only modifies the recipient list of mail already being sent through `wp_mail`. Pair it with an SMTP plugin for delivery.

= Will it affect plugins that send mail directly (not via wp_mail)? =

No. Only mail routed through the `wp_mail` filter is intercepted.

== Changelog ==

= 1.0.0 =
* Initial release.
