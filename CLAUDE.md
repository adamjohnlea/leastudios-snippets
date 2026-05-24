# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Scope of this file

`leastudios-snippets` is one plugin in the leaStudios suite. Suite-wide conventions — coding
standards, security rules (escape/sanitize/nonce/capability), shared-by-duplication classes,
git safety — live in two parent files and are **not** repeated here:

- `../../CLAUDE.md` — the WordPress install / suite layout.
- `../leastudios-dev-tools/CLAUDE.md` — the "mother" file with the inherited conventions.

This plugin is its own git repository (`main` branch). The repo root is not version-controlled.

## Commands

Run from this plugin directory:

```bash
composer install                       # one-time setup
composer lint                          # phpcs + phpstan
composer phpcs                         # WordPress Coding Standards check
composer phpcbf                        # auto-fix WPCS issues
composer phpstan                       # PHPStan level 6, scans src/ only
composer test                          # PHPUnit 9.6
vendor/bin/phpunit --filter ConditionCheckerTest    # run a single test class
```

PHPUnit needs the WordPress test library installed once (shared across the suite):

```bash
bash ../leastudios-dev-tools/bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

`tests/bootstrap.php` looks for it under `$WP_TESTS_DIR` or `<temp>/wordpress-tests-lib`.

## What this plugin does

Lets administrators manage custom PHP / JS / CSS / HTML snippets from the admin without
editing theme files. PHP snippets are executed via `eval()` — intentional and central to the
design. Do not try to remove it; understand the safe-mode containment (below) instead.

## Architecture

### Data model — no custom table

A snippet is a `leastudios_snippet` custom post type. Every attribute is post-meta, with keys
defined as `META_*` constants on `Snippet_Post_Type` (the single source of truth for meta keys
and the `LOCATIONS` map). The snippet code itself is stored raw in `META_CODE` — deliberately
**not** sanitized (sanitizing would corrupt code); it is only capped at 256 KB on save.

### Capability model — why everything maps to `manage_options`

Publishing a snippet means executing arbitrary code on the site, so the CPT uses a custom
`capability_type` (`leastudios_snippet` / `leastudios_snippets`) instead of inheriting the
generic `post` caps. `Snippet_Post_Type::map_capabilities()` is hooked into `map_meta_cap`
and routes *every* derived snippet capability to `manage_options`. `Plugin::init()` registers
this filter — before any cap check fires.

### Execution flow

`Plugin::init()` runs at `plugins_loaded` and wires everything. It calls
`Snippet_Executor::init()` synchronously, so the steps below also happen at `plugins_loaded`
(the CPT itself is registered separately, on the `init` hook):

1. Reads active snippet IDs — cached in the object cache (group `leastudios_snippets`, key
   `leastudios_snippets_active_ids`) to avoid a `meta_query` on every front-end request. The
   cache is invalidated on `save_post_leastudios_snippet` and `deleted_post`.
2. Skips snippets listed in safe mode (`leastudios_snippets_safe_mode` option).
3. Groups remaining snippets by location and registers WordPress hooks per location.
   `everywhere` snippets execute immediately (at `plugins_loaded`); others register a callback
   on the relevant hook — `wp_head`, `wp_footer`, `wp_body_open`, `the_content`, the
   admin/login head & footer hooks — or on a `[leastudios_snippet]` shortcode or a user-named
   `custom_hook`. `Snippet_Post_Type::LOCATIONS` is the full location-key list.
4. `maybe_execute_snippet()` is the single gate: conditions → `leastudios_snippets_should_execute`
   filter → `before_execute` action → `eval()` (PHP) or tag-wrapped echo (JS/CSS/HTML) →
   `after_execute` action.

### Safe mode — fatal-error containment

`Execution/Safe_Mode` owns all error-containment state. `Snippet_Executor` delegates to it:
it wraps every snippet dispatch in `Safe_Mode::begin()`/`end()` (an "executing snippet"
marker), and `Safe_Mode` registers a `shutdown` handler. If the request dies with a
fatal-class error while the marker is set, that snippet crashed it → `Safe_Mode::deactivate()`
sets `META_ACTIVE` → `'0'`, appends the ID to the `leastudios_snippets_safe_mode` option,
stores the message in a `leastudios_snippets_error_{id}` transient, and invalidates the
active-IDs cache. This covers uncatchable fatals (out-of-memory, timeout) *and* fatals in
deferred hook callbacks — the common case, since most snippets register hooks.

A snippet that legitimately ends the request (`wp_safe_redirect(); exit;`) leaves the marker
set but produces no fatal error, so it is **not** deactivated — `Safe_Mode::decide_culprit()`
requires a fatal-class `error_get_last()`.

`execute_php()` additionally wraps `eval()` in `try/catch (\Throwable)` plus a custom error
handler for immediate-execution code: a thrown error or an error-class errno deactivates the
snippet; non-fatal warnings are recorded via `Safe_Mode::record_warnings()` (a
`leastudios_snippets_warnings_{id}` transient) and surfaced as a non-blocking admin notice
without deactivating. `Safe_Mode_Notice` renders both the deactivation and warning notices.

### Conditions

`Condition_Checker::check()` uses AND logic over a JSON-encoded conditions array
(`META_CONDITIONS`). Note: the page-type, post-type, and page/post-ID checks return **false**
when called before the `wp` action has fired (e.g. an `everywhere` snippet, which runs at
`plugins_loaded`) — the main query is not yet resolved, so the gate denies rather than
silently passes.

### Snippet library

`Snippet_Library` holds pre-built snippet definitions, each keyed by a locale-independent
`slug` (do **not** look up by translated `title`). `get_available_snippets()` filters out
entries whose `requires_plugin` sibling is not active. `install_snippet()` creates the snippet
as an **inactive** CPT post. `Suite_Detector` detects sibling plugins primarily via their
`LEASTUDIOS_*_VERSION` constants (works at `plugins_loaded`, survives file renames), falling
back to `is_plugin_active()`.

## Extension points

The plugin exposes a full set of actions and filters (`leastudios_snippets_*`). They are
exhaustively documented with examples and execution order in
[`docs/developer-handbook.md`](docs/developer-handbook.md) — consult it before adding or
changing any hook.

## Plugin-specific gotchas

- The activation hook in `leastudios-snippets.php` is registered **above** the
  `vendor/autoload.php` existence check on purpose, so a botched install still gets a working
  (degraded) activation. Do not "tidy" this ordering.
- `Snippet_Editor` saves conditions from a hidden JSON `<textarea>`
  (`leastudios_snippets_conditions_json`) populated by `assets/js/admin.js`, not from the
  visible condition-row inputs. `get_condition_types()` is the single source of truth shared
  by the PHP metabox renderer and the JS editor.
- `src/Security/Nonce.php` is a shared-by-duplication file: it must stay byte-identical with
  the copies in sibling plugins. Edit all copies together and run
  `../leastudios-dev-tools/bin/check-shared.sh` before release.
- PHPStan runs at level 6 with no baseline file. `phpstan-bootstrap.php` declares the plugin
  constants so analysis does not need to boot WordPress.
- `uninstall.php` deletes all `leastudios_snippet` posts in batches of 100, drops every
  `_transient_leastudios_snippets_*` transient (error, warning, and oversize flags), and
  removes the `leastudios_snippets_safe_mode` and `leastudios_snippets_warnings` options.
  Extend it whenever the plugin starts writing a new option or transient.
- `Snippet_Post_Type::map_capabilities()` inspects **both** the `$cap` argument and the
  resolved `$caps` array. WordPress passes a generic `$cap` (`edit_post`) for per-post
  meta-cap checks while putting the snippet-specific primitive in `$caps`; matching on
  `$cap` alone leaves the edit screen and save flow unreachable. Do not "simplify" it back.
- Snippet editing is gated on `DISALLOW_FILE_MODS` / `DISALLOW_FILE_EDIT` via
  `Snippet_Post_Type::is_editing_disabled()` (filterable through
  `leastudios_snippets_editing_disabled`). When disabled, write capabilities map to
  `do_not_allow` and library install is blocked; already-saved snippets still execute.
- `readme.txt` is the WordPress.org-format header, distinct from `README.md`. Keep its
  `Stable tag` and the version in `leastudios-snippets.php` in sync on every release.

## Releases

This plugin uses a tag-triggered release workflow (`.github/workflows/release.yml`) that auto-generates release notes from the commit log between the previous and current tag.

**To cut a release:** bump the `Version:` header in the main plugin file, commit, then:

```bash
git tag vX.Y.Z && git push origin vX.Y.Z
```

The workflow verifies the tag matches the header, builds the zip with `composer install --no-dev`, and publishes the release.

**Commit-prefix → release-notes section:**

- `feat:` → `## Added`
- `fix:` → `## Fixed`
- `refactor:` → `## Changed`
- `perf:` → `## Performance`

**Hidden from release notes** (use these prefixes for changes you don't want surfaced): `ci:`, `chore:`, `docs:`, `test:`, `style:`, `build:`, `release:`.

The subject text after the prefix becomes the bullet verbatim, with the first letter capitalized. To override auto-notes for a specific release, edit the body in the GitHub UI after publish.
