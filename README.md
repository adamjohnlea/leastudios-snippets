# leaStudios Snippets

Manage custom PHP, JavaScript, CSS, and HTML snippets without editing theme files. Auto-insert locations, conditional logic, safe-mode error handling, and a pre-built library of hooks for the leaStudios suite.

- **Requires WordPress:** 6.4+
- **Requires PHP:** 8.1+
- **License:** GPL-2.0-or-later

## Features

- **PHP, JS, CSS, HTML snippets** managed from the admin.
- **Auto-insert locations** — header, footer, before/after content, and more.
- **Conditional logic** — page type, user role, post type, specific page IDs.
- **Safe mode** — snippets that throw fatal errors are auto-deactivated with an admin notice.
- **Pre-built library** of hooks for leaStudios suite plugins.
- **Priority control** for execution order.

## Safety model

Only administrators with `manage_options` can create or edit snippets. A custom error handler captures fatal errors during snippet execution; the offending snippet is auto-deactivated and a safe-mode flag prevents re-execution until you fix and re-enable it.

## Installation

1. Upload `leastudios-snippets` to `/wp-content/plugins/`.
2. Activate via Plugins → Installed Plugins.
3. Go to **Snippets** in the admin menu and create your first snippet.

## Related plugins

This plugin is part of the leaStudios plugin family. It works on its own, and ships pre-built snippets that hook into the other leaStudios plugins (payments, email-templates, forms, mailer) under the **Library** tab. Browse there if you want quick examples of customising the rest of the suite.

- **[leastudios-payments](../leastudios-payments)**
- **[leastudios-email-templates](../leastudios-email-templates)**
- **[leastudios-forms](../leastudios-forms)**
- **[leastudios-mailer](../leastudios-mailer)**

## Development

This plugin is self-contained — it can be cloned, linted, tested, and packaged on its own.

```bash
composer install            # install dependencies (incl. dev tools)
composer lint               # phpcs + phpstan
composer test               # phpunit (requires the WP test library)
composer phpcbf             # auto-fix WPCS issues
```

To run the test suite, install the WordPress test library once:

```bash
bash ../leastudios-dev-tools/bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

The shared scaffold, packaging script, and project-wide development conventions live in **[leastudios-dev-tools](../leastudios-dev-tools)** — start there when bootstrapping a new plugin or making cross-plugin tooling changes.

## License

GPL-2.0-or-later. See `readme.txt` for the WordPress.org-style header.
