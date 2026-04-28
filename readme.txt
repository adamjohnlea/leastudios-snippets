=== leaStudios Snippets ===
Contributors: leastudios
Tags: code snippets, custom code, php, css, javascript
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage custom code snippets (PHP, JS, CSS, HTML) with auto-insert locations, safe error handling, and a pre-built library of leaStudios suite hooks.

== Description ==

leaStudios Snippets lets you add custom PHP, JavaScript, CSS, and HTML code to your WordPress site without editing theme files. Snippets are managed through a clean admin interface with features designed for safety and flexibility.

**Key features:**

* Add PHP, JavaScript, CSS, and HTML snippets via the admin dashboard
* Auto-insert locations: header, footer, before content, after content, and more
* Conditional logic: control where snippets run based on page type, user role, post type, and specific pages
* Safe mode: snippets that cause PHP errors are automatically deactivated to prevent site crashes
* Pre-built snippet library with ready-to-use hooks for leaStudios suite plugins
* Priority control for snippet execution order

**Safe by design:**

Snippets that throw fatal errors are automatically deactivated, with an admin notice to let you know what happened. You can review and fix the snippet, then reactivate it when ready.

== Installation ==

1. Upload the `leastudios-snippets` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to Snippets in the admin menu to create your first snippet.

== Frequently Asked Questions ==

= Is it safe to run PHP snippets? =

Only administrators with `manage_options` capability can create or edit snippets. Safe mode automatically deactivates any snippet that causes a PHP error, preventing site crashes.

= Can I control where snippets appear? =

Yes. Each snippet supports conditional logic based on page type, user role, post type, and specific page/post IDs. You can also choose auto-insert locations like header, footer, or before/after content.

== Changelog ==

= 1.0.0 =
* Initial release.
