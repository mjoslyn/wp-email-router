# Email Router

Rewrite the recipients of outgoing WordPress email — by address, by subject, or by blacklist — from an admin screen instead of a filter in `functions.php`.

- **Slug:** `email-router`
- **Main class:** `EmailRouter` (singleton, global namespace)
- **Option:** `email_router_settings`
- **Text domain:** `email-router`
- **Requires:** WordPress 5.8+, PHP 7.4+
- **License:** GPL‑2.0‑or‑later

## Why

Every site eventually needs mail to go somewhere other than where the code says. A staging environment must not email real customers. An order notification hard-coded to one address should reach a team. A departed employee's address is still buried in a dozen plugin settings.

The usual answer is a `wp_mail` filter in the theme, which means a deploy for every change and no visibility into what is being rewritten. This plugin moves those rules into the database and gives them a UI, so the routing table is something you edit rather than something you ship.

## Features

- **Address replacement** — swap a target address for one or more recipients, with an optional title so a rule explains itself.
- **Subject routing** — match the subject line against a regular expression and redirect the mail to a set of recipients.
- **Blacklist** — strip addresses from every outgoing message, whatever produced them.
- **Tag inputs with autocomplete** — recipients are chips, and the autocomplete is sourced from every address already in use across your rules.
- **"Where used?" scan** — for any address, search WordPress core, WooCommerce, Gravity Forms, and this plugin's own rules for literal uses of it.
- **System email report** — every email the site is configured to send, who it is addressed to, and who the router actually delivers it to, across WordPress core, WooCommerce, Gravity Forms, Contact Form 7, and WPForms. Downloadable as CSV.
- **Bulk removal** — strip an address from the recipient list of every replacement rule at once.
- **Export / import** — settings round-trip as JSON, so a routing table can move between environments.
- **Query Monitor integration** — every rewrite fires `qm/debug` with the before and after recipients.
- **Degrades gracefully** — WooCommerce, Gravity Forms, Contact Form 7, and WPForms scanning is guarded by `class_exists`/`function_exists`, so the plugin runs fine without them.
- **No runtime dependencies** — a single PHP file; Composer is dev-only.

## Concepts

### The three rule types

| Type | Matches on | Effect on `to` |
|---|---|---|
| Replacement | An exact target address appearing in `to` | The target is substituted with the rule's recipients |
| Subject pattern | A regex match against `subject` | `to` is **replaced entirely** by the rule's recipients |
| Blacklist | An exact address in `to` | The address is removed |

### Filter order

Both rule types hook `wp_mail`, at different priorities:

1. **`replace_by_subject`** (priority 10) — checks every subject pattern. Each match overwrites `to` wholesale, so with multiple matching patterns the last one in the list wins.
2. **`replace_emails`** (priority 20) — checks every replacement rule against the recipients left by step 1. Substitution is textual, so a subject rule's recipients are themselves eligible for replacement.

The blacklist is applied at the end of *both* callbacks, so a blacklisted address cannot survive either path.

### Subject patterns are regular expressions

The pattern is interpolated into `preg_match('/' . $pattern . '/i', $subject)`. It is a case-insensitive regex, not a shell-style glob — `Order #\d+` works, and `.` matches any character. A `/` in the pattern must be escaped. An invalid pattern makes `preg_match` warn and match nothing.

### What is not intercepted

Only mail that passes through the `wp_mail` filter. Plugins that talk to an SMTP library or a transactional API directly are invisible to this plugin, and this plugin never sends mail itself — pair it with an SMTP plugin for delivery.

### The usage scan is literal

`find_email_usage` matches addresses as strings. Merge tags, `{admin_email}`-style placeholders, and recipients computed at send time will not be found. Treat a clean scan as "no hard-coded uses", not "this address is unreachable".

## Install

1. Download the zip from [Releases](https://github.com/mjoslyn/wp-email-router/releases).
2. In WP admin, *Plugins → Add New → Upload Plugin*, then activate.
3. Configure at *Tools → Email Router*.

No Composer install is needed to run the plugin.

## Quick start

Redirect all mail on a staging site to yourself:

1. Go to *Tools → Email Router → Email Replacements*.
2. **Add New Replacement**. The row is created with a placeholder target like `a8f3c1@emailrouter.local`.
3. Set **Target** to the address you want to intercept and **Replacement** to your own, pressing Enter after each recipient to turn it into a chip.
4. Give the rule a **Title** so the next person knows why it exists.
5. **Save**. Send a test email and confirm the rewrite in Query Monitor's debug panel.

To route by subject instead, use the *Subject Patterns* tab with a pattern like `^New Order` and the recipients who should get it.

## Settings shape

Everything lives in one option, `email_router_settings`, which is also the exact shape of the export JSON:

```php
[
  'email_replacement_pairs' => [
    ['title' => 'Staging catch-all', 'target' => 'sales@example.com', 'replacement' => 'me@example.com,qa@example.com'],
  ],
  'subject_pattern_pairs' => [
    ['pattern' => '^New Order', 'recipients' => 'orders@example.com'],
  ],
  'email_blacklist' => ['former.employee@example.com'],
]
```

Recipients are stored as a comma-separated string; the chip UI is a presentation layer over that field. Rules with an empty `target` (or `pattern`) are dropped on save.

## Tools

The *Tools* tab holds four utilities:

| Tool | What it does |
|---|---|
| Remove from all replacements | Strips an address from the **recipient list** of every replacement rule. Targets are left alone, so a rule can be left with no recipients. |
| Find where an email is used | Runs the site-wide literal scan (also available per-rule via **Where used?**). |
| System email report | Lists every email the site sends, its configured recipients, and where the router delivers it. Downloads as CSV. |
| Export / Import | Export downloads the option as JSON via a nonce-protected `admin-post` handler. Import validates, sanitizes, and **replaces** all settings. |

All of it requires `manage_options`.

### The system email report

The report answers the question the routing table cannot: *what does this site send, and who gets it?* It walks the mail-sending configuration of everything it knows about and puts the result in one table.

| Source | What is read |
|---|---|
| WordPress core | Registration, welcome, password reset, comment moderation and notification, automatic updates, fatal-error recovery, admin email change, personal data requests |
| WooCommerce | Every `WC_Email` (recipient, subject, enabled state), the stock notification recipient, and the "from" address |
| Gravity Forms | Every notification on every form — `to`, `cc`, `bcc`, plus field-based and conditional-routing recipients — on active and inactive forms alike |
| Contact Form 7 | Each form's *Mail* and *Mail (2)* recipients, the second only when it is active |
| WPForms | Each form's notifications, including CC and Reply-To |

Two recipient columns sit side by side:

- **Configured recipients** — what the sending plugin is set to. Literal addresses are listed plainly; anything resolved at send time (the customer on an order, a form field, the user resetting their password, a merge tag such as `{admin_email}`) is listed in italics as a dynamic recipient.
- **Delivered to** — the literal recipients after this plugin's own rules run. This is computed by calling `replace_by_subject()` and `replace_emails()` in their hooked order, so the report cannot drift from what happens at send time. A row whose routed recipients all end up blacklisted reads *Blocked*.

Subject routing is only simulated when the email's subject is known, since an empty subject would match patterns it never matches in practice. Subjects taken from WooCommerce and Gravity Forms still contain their placeholders (`{order_number}`, `{site_title}`), so a subject pattern that depends on an expanded placeholder may route in practice while the report shows it does not.

Dynamic recipients are never routed. The router *does* rewrite those addresses at send time — it just cannot know in advance what they will be.

### CC and BCC are not routed

The `wp_mail` filters rewrite `to` and nothing else. CC and BCC travel in the message headers, so a blacklisted address still receives mail as a CC, and a replacement rule never expands one.

The report says so rather than hiding it. Such addresses are listed in both recipient columns marked **not routed**, which also means a row can read *Blocked (all routed recipients blacklisted)* and still deliver — to its CC list. Treat the blacklist as a `to` filter, not a site-wide block.

### Reporting emails from another plugin

A plugin the report does not know about can add its own rows:

```php
add_filter( 'email_router_system_emails', function ( $rows ) {
    $rows[] = [
        'source'     => 'My Plugin',
        'name'       => 'Nightly digest',
        'subject'    => 'Your nightly digest',
        'status'     => 'Enabled',
        'recipients' => [ 'digest@example.com' ],   // literal addresses, routed
        'unrouted'   => [                           // literal, never routed (CC/BCC)
            [ 'address' => 'archive@example.com', 'label' => 'BCC' ],
        ],
        'dynamic'    => [ 'Each subscriber' ],      // resolved at send time
        'link'       => admin_url( 'admin.php?page=my-plugin' ),
    ];
    return $rows;
} );
```

Every key is optional. Routing and the *Delivered to* column are applied to `recipients` after the filter runs; `unrouted` is passed through untouched and reported as delivered but not routed. An `unrouted` entry may be a bare address string when there is no useful label.

## Architecture

A single file, deliberately. The plugin is small enough that a class-per-concern layout would cost more in navigation than it returns in structure.

| Piece | Responsibility |
|---|---|
| `replace_by_subject()` | `wp_mail` @ 10 — subject regex routing |
| `replace_emails()` | `wp_mail` @ 20 — target/replacement substitution |
| `apply_blacklist()` | Recipient stripping; called at the end of both filters |
| `sanitize_settings()` | `register_setting` callback; merges each tab's POST over the stored option so tabs don't clobber each other |
| `find_email_usage()` | Cross-plugin literal address scan |
| `get_system_email_report()` | Cross-plugin inventory of outgoing mail, routed through `route_recipients()` |
| `handle_system_emails_export()` | `admin_post_email_router_system_emails_export` — CSV download of the report |
| `ajax_usage()` | `wp_ajax_email_router_usage` — backs the "Where used?" modal |
| `handle_export()` | `admin_post_email_router_export` — JSON download |

## Development

```bash
composer install
```

The test suite uses the standard WordPress test library, which needs `svn` and a `mysql` client. Since most machines have neither, the Docker runner is the supported path — it builds a PHP 8.3 image, joins your site's compose network, and uses the existing `db-test` service. The WordPress test library is cached in a named volume, so reruns are fast.

```bash
bin/docker-test.sh                       # full suite (default + ajax groups)
bin/docker-test.sh --filter Blacklist    # args pass straight through to phpunit
bin/docker-test.sh --group ajax          # just the ajax tests
EIR_REINSTALL=1 bin/docker-test.sh       # rebuild the cached test library and db
```

The runner creates an isolated `wp_eir_tests` database and does not touch your site's data. Override the network with `EIR_TEST_NETWORK` if your compose project is named differently.

If you do have `svn` and a `mysql` client locally:

```bash
bin/install-wp-tests.sh wp_eir_tests root root_password 127.0.0.1:3307 latest
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit
vendor/bin/phpunit --group ajax          # ajax tests are excluded by default
```

Ajax tests are grouped separately because `WP_Ajax_UnitTestCase` is slow to bootstrap.

### Coding standard

The plugin conforms to the WordPress Coding Standards (`WordPress` + `WordPress-Docs`), enforced by `phpcs.xml.dist`:

```bash
vendor/bin/phpcs      # check
vendor/bin/phpcbf     # auto-fix what is fixable
```

The ruleset carries three documented exceptions: the main file keeps its slug-based name rather than a `class-` prefix, because WordPress requires that of a plugin bootstrap; test methods are exempt from the docblock sniff, since their names are already full sentences; and `do_action( 'qm/debug' )` is exempt from the hook-naming sniffs, because that hook belongs to Query Monitor.

## Provenance

Extracted from the `hello-elementor-child` theme's `inc/email-interceptor.php`, where it lived as an include called Email Interceptor Router and stored its rules under `email_interceptor_replacer_settings`.

This plugin uses a different option key, so a site coming from the theme version starts with an empty rule set. To carry the old rules across:

```bash
wp option get email_interceptor_replacer_settings --format=json | wp option set email_router_settings --format=json
```

Remove the theme's `require` of `inc/email-interceptor.php` before activating. The theme copy declares a global `unique_flatten()` without a guard, so loading both at once fatals the site.

## License

[GPL‑2.0‑or‑later](LICENSE).
