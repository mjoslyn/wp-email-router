=== Email Router ===
Contributors: mjoslyn
Tags: email, wp_mail, routing, smtp, notifications
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Intercept outgoing WordPress emails and route or replace recipients based on target addresses, subject-line patterns, and a blacklist.

== Description ==

Email Router hooks into `wp_mail` and rewrites the recipient list before mail leaves the site. It is useful for redirecting mail during development, routing notifications to the right team based on subject, and blocking addresses that should never receive mail.

It adds a **Tools &rarr; Email Router** admin page with four tabs:

* **Email Replacements** — replace a target address with one or more recipients (comma-separated).
* **Subject Patterns** — route mail to specific recipients when the subject matches a regular expression.
* **Blacklist** — strip specific addresses from every outgoing message.
* **Tools** — bulk-remove an address, scan the site for where an address is used, report every system email the site sends and who receives it, and export/import settings as JSON.

All rules are stored in a single option (`email_router_settings`).

== Frequently Asked Questions ==

= Does this send email itself? =

No. It only modifies the recipient list of mail already being sent through `wp_mail`. Pair it with an SMTP plugin for delivery.

= Will it affect plugins that send mail directly (not via wp_mail)? =

No. Only mail routed through the `wp_mail` filter is intercepted.

= What does the system email report cover? =

WordPress core notifications, WooCommerce transactional emails, Gravity Forms notifications, Contact Form 7 mail templates, and WPForms notifications. Other plugins can add their own rows with the `email_router_system_emails` filter. Recipients resolved at send time — a customer, a form field, a merge tag — are reported as dynamic, because they cannot be known in advance.

== Changelog ==

= 1.4.0 =
* Route CC and BCC headers: address replacement rules and the blacklist now apply to every `Cc:` and `Bcc:` line, not just `to`. An alias used as a copy expands to its replacement list, and a blacklisted address no longer receives mail as a copy. Subject patterns still rewrite `to` only.
* The system email report routes CC and BCC the same way. Rows use a `copies` key; `unrouted` from 1.3 is still accepted. The CSV column is now CC/BCC recipients.

= 1.3.1 =
* Fix replacement rules matching inside longer addresses: a `sales@example.com` rule rewrote `vehiclesales@example.com` into `vehiclerep@example.com`. Rules now match whole addresses, case-insensitively, including addresses written as `Name <address>`.

= 1.3.0 =
* Report CC and BCC addresses as recipients that the router never rewrites, instead of listing them as dynamic recipients. Report rows gain an `unrouted` key.
* A row whose routed recipients are all blacklisted now says so explicitly and still lists its CC and BCC as delivered, because the blacklist only filters the `to` field.
* Add an Unrouted recipients column to the system email report CSV.

= 1.2.0 =
* Confirm before removing a blacklist entry, which puts that address back into circulation.
* Confirm before "Remove from all rules" in the Tools tab, which saves immediately across every replacement rule.
* Confirm before importing settings, naming the file and counting the replacements, patterns, and blacklist entries it would replace.

= 1.1.1 =
* Fix a fatal error on the Tools tab for WooCommerce stores: the system email report asked every WC_Email for its subject, which WC_Email_Customer_Invoice cannot supply without an order.

= 1.1.0 =
* Add a system email report to the Tools tab: every email the site is configured to send, its configured recipients, and the recipients this plugin actually delivers to.
* Cover WordPress core, WooCommerce, Gravity Forms, Contact Form 7, and WPForms, with an `email_router_system_emails` filter for anything else.
* Download the report as CSV.

= 1.0.0 =
* Initial release.
