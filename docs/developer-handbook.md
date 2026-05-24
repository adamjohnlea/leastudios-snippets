# leaStudios Snippets — Developer Handbook

leaStudios Snippets lets WordPress administrators manage custom PHP, JS, CSS, and HTML
snippets from the admin without touching theme files or `functions.php`. Every snippet is
stored as a custom post type, executed at a configurable insertion point, and protected by a
safe-mode system that automatically deactivates any snippet that causes a fatal error. The
plugin exposes 21 named hooks covering execution control, output filtering, condition
evaluation, post-type configuration, snippet saves, and the built-in snippet library —
giving extension authors a clean seam to add custom logic, inject library snippets, and
integrate with external systems.

---

## Table of Contents

1. [Overview](#1-overview)
2. [Architecture](#2-architecture)
3. [Development Setup](#3-development-setup)
4. [Concepts](#4-concepts)
5. [Data Model](#5-data-model)
6. [Hooks Reference](#6-hooks-reference)
7. [Hook Execution Order](#7-hook-execution-order)
8. [Extension Recipes](#8-extension-recipes)
9. [Testing](#9-testing)
10. [Release Process](#10-release-process)
11. [Where to Read More](#11-where-to-read-more)

---

## 1. Overview

leaStudios Snippets gives WordPress site owners a safe, structured alternative to editing
`functions.php`. Administrators create snippets in the WordPress admin, assign each one a
language type (PHP, JS, CSS, or HTML), choose an insertion location, and optionally attach
display conditions. The plugin handles execution at the right point in the WordPress request
lifecycle and wraps every PHP snippet in a safe-mode containment layer.

PHP snippets are executed via `eval()` — this is intentional and central to the design.
The safe-mode system (not `eval()` removal) is the containment strategy: any snippet that
causes a fatal error is automatically deactivated, added to the safe-mode blocklist, and
reported via an admin notice. Do not attempt to remove `eval()`; understand the safe-mode
architecture instead (see Section 2).

For extension authors the most important entry points are:

- **Hooks** — 21 named `do_action` and `apply_filters` hooks covering execution control,
  output filtering, condition evaluation, post-type configuration, snippet saves, and library
  management (see Section 6).
- **`leastudios_snippets_library_snippets`** — register your own pre-built snippet
  definitions that users can install with one click from the Library admin page.
- **`leastudios_snippets_condition_result`** — add support for custom condition types (e.g.
  device type, geo-location, feature flag) evaluated per snippet.

The plugin detects sibling plugins (`leastudios-mailer`, `leastudios-payments`, etc.) via
`Suite_Detector` at runtime and degrades gracefully when they are absent.

---

## 2. Architecture

### Component map

```
leastudios-snippets.php
    └── Plugin::init()   (runs at plugins_loaded)
            |
            ├── Execution\Snippet_Executor::init()   load + execute active snippets
            |       |
            |       ├── get_active_snippet_ids()      object-cache lookup
            |       ├── Skips safe-mode blocklist      leastudios_snippets_safe_mode option
            |       ├── Groups snippets by location
            |       └── register_location_hooks()     hooks per WP location
            |               |
            |               ├── "everywhere" snippets run immediately at plugins_loaded
            |               └── Others register callbacks on wp_head, wp_footer, etc.
            |
            ├── Execution\Safe_Mode                   fatal-error containment
            |       |
            |       ├── begin() / end()               "executing" marker
            |       ├── shutdown handler              detects uncatchable fatals
            |       └── deactivate()                  sets META_ACTIVE=0, stores transient
            |
            ├── Execution\Condition_Checker           AND-logic condition evaluation
            |
            ├── CPT\Snippet_Post_Type                 registers leastudios_snippet CPT
            |       └── map_capabilities()            all caps → manage_options
            |
            ├── Library\Snippet_Library               pre-built snippet definitions
            |       └── install_snippet()             creates inactive CPT post
            |
            └── Admin\{Snippet_Editor,               admin UI (is_admin only)
                        Safe_Mode_Notice,
                        Library_Page}
```

### Execution flow

`Plugin::init()` runs at `plugins_loaded` and calls `Snippet_Executor::init()` synchronously.
The CPT itself is registered separately on the `init` hook. `maybe_execute_snippet()` is the
single execution gate:

```
Condition_Checker::check()
    |
    +-- [filter] leastudios_snippets_condition_result  (per condition)
    |
[filter] leastudios_snippets_should_execute
    |
[action] leastudios_snippets_before_execute
    |
eval() / tag-wrapped echo
    |
    +-- [filter] leastudios_snippets_output    (non-PHP only)
    +-- [action] leastudios_snippets_php_error (PHP fatal/exception)
    |
[action] leastudios_snippets_after_execute
```

---

## 3. Development Setup

```bash
cd wp-content/plugins/leastudios-snippets
composer install
composer lint              # phpcs + phpstan
composer test              # PHPUnit (requires WP test library — see below)
composer phpcbf            # auto-fix WPCS issues
```

### WordPress test library (one-time, shared across all plugins)

```bash
bash ../leastudios-dev-tools/bin/install-wp-tests.sh \
    wordpress_test root '' 127.0.0.1 latest
```

The library installs to `/tmp/wordpress-tests-lib/`. All plugin `tests/bootstrap.php` files
look there automatically.

---

## 4. Concepts

### Snippet

A snippet is a `leastudios_snippet` custom post type. Each snippet has a language type
(`php`, `js`, `css`, `html`), an insertion location, a priority, an active flag, and an
optional set of display conditions. The snippet code is stored raw in `META_CODE` —
deliberately not sanitized (sanitizing would corrupt code); it is only capped at 256 KB on
save.

### Snippet location

The location determines at which point in the WordPress request lifecycle a snippet is
injected. Built-in location slugs are defined in `Snippet_Post_Type::LOCATIONS`:
`everywhere`, `wp_head`, `wp_footer`, `wp_body_open`, `the_content_before`,
`the_content_after`, `admin_head`, `admin_footer`, `login_head`, `custom_hook`,
`shortcode`, `frontend_header`, and `frontend_footer`. Custom locations can be registered
via the `leastudios_snippets_locations` filter.

The `everywhere` location is special: PHP snippets assigned to it run immediately at
`plugins_loaded`, before the main query is resolved. This means condition types that depend
on query state (`page_type`, `post_type`, `page_id`) will deny rather than silently pass
for `everywhere` snippets — the check returns `false` when called before the `wp` action
has fired.

### Condition

A condition is a single rule in a snippet's condition set, stored as an element of the JSON
array in `META_CONDITIONS`. Each condition has `type`, `value`, and `operator` keys. The
built-in condition types are `page_type`, `user_logged_in`, `user_role`, `post_type`, and
`page_id`. `Condition_Checker::check()` evaluates all conditions with AND logic. Custom
condition types can be added via the `leastudios_snippets_condition_result` filter.

### Safe mode

Safe mode is the fatal-error containment system. `Safe_Mode` wraps every snippet dispatch
in `begin()`/`end()` markers and registers a `shutdown` handler. If a request dies with a
fatal-class error while a snippet is executing, that snippet is automatically deactivated
(`META_ACTIVE = '0'`), its ID is appended to the `leastudios_snippets_safe_mode` option,
and the error message is stored in a `leastudios_snippets_error_{id}` transient. Non-fatal
warnings are stored in a `leastudios_snippets_warnings_{id}` transient and surfaced as
non-blocking admin notices without deactivating the snippet.

### Shortcode location

The `shortcode` location registers a `[leastudios_snippet id="…"]` shortcode. The snippet
runs inline wherever the shortcode is placed, obeying the same condition and execution gate
as all other locations.

### Snippet library

The snippet library is a curated collection of pre-built snippet definitions, each keyed by
a locale-independent slug. Definitions are filtered through `leastudios_snippets_library_snippets`
before being displayed on the Library admin page. `Snippet_Library::install_snippet()` creates
a new `leastudios_snippet` post from a definition; the snippet is created as inactive by
default. The `requires_plugin` key in a definition gates visibility to sites where a
specific sibling plugin is active.

---

## 5. Data Model

### `leastudios_snippet` CPT

Snippets are stored as WordPress posts with `post_type = 'leastudios_snippet'`. The post
title is the snippet name; all other attributes are post meta.

| Meta key (constant) | Type | Description |
|---|---|---|
| `_leastudios_snippets_code` (`META_CODE`) | `string` | The raw snippet source code. Not sanitized. Capped at 256 KB on save. |
| `_leastudios_snippets_type` (`META_TYPE`) | `string` | Language type: `php`, `js`, `css`, or `html`. |
| `_leastudios_snippets_location` (`META_LOCATION`) | `string` | Insertion location slug (must match a key in `Snippet_Post_Type::LOCATIONS` or a custom-registered location). |
| `_leastudios_snippets_active` (`META_ACTIVE`) | `string` | `'1'` = active, `'0'` = inactive. |
| `_leastudios_snippets_priority` (`META_PRIORITY`) | `int` | Execution priority within the location. Lower numbers run earlier. |
| `_leastudios_snippets_conditions` (`META_CONDITIONS`) | `string` | JSON array of condition objects (`type`, `value`, `operator`). |
| `_leastudios_snippets_custom_hook` (`META_CUSTOM_HOOK`) | `string` | Hook name when location is `custom_hook`. |

`META_*` constants are the single source of truth for meta keys. Always use the constants
when reading or writing these keys; do not hard-code the raw strings.

### Capabilities

All read and write capabilities for `leastudios_snippet` posts map to `manage_options`.
`Snippet_Post_Type::map_capabilities()` is hooked into `map_meta_cap` and routes every
derived snippet capability accordingly. Publish access is deliberately restricted to
administrators because publishing a snippet means executing arbitrary code on the site.

When `DISALLOW_FILE_MODS` or `DISALLOW_FILE_EDIT` is defined and truthy, write capabilities
map to `do_not_allow` instead (filterable via `leastudios_snippets_editing_disabled`).

### Options

| Option key | Type | Description |
|---|---|---|
| `leastudios_snippets_safe_mode` | `array<int>` | IDs of snippets deactivated by safe mode. Managed by `Safe_Mode::deactivate()`. |
| `leastudios_snippets_warnings` | `array<int, bool>` | Index of snippet IDs that have recorded warnings. Managed by `Safe_Mode`. |

### Transients

| Transient key | TTL | Description |
|---|---|---|
| `leastudios_snippets_error_{id}` | 1 day | Error message from the last fatal that deactivated snippet `{id}`. |
| `leastudios_snippets_warnings_{id}` | (none set) | Array of non-fatal warning messages recorded for snippet `{id}`. |
| `leastudios_snippets_oversize_{id}` | 1 minute | Flag set when a snippet's code exceeds the 256 KB cap on save. |

### Object cache

| Cache key | Group | Description |
|---|---|---|
| `leastudios_snippets_active_ids` | `leastudios_snippets` | Array of active snippet post IDs. Avoids a `meta_query` on every request. Invalidated on `save_post_leastudios_snippet` and `deleted_post`. |

---

## 6. Hooks Reference

The hooks below let you control every stage of snippet execution, customise the post type,
and extend the snippet library. They are grouped by subject: execution, post type and
locations, library, and post type lifecycle. Within each group, filters appear before
actions, alphabetically within each type.

### Execution

#### `leastudios_snippets_condition_result`

- **Type:** Filter
- **Location:** `src/Execution/Condition_Checker.php`
- **Since:** 1.0.0
- **Description:** Override or extend the result of an individual condition evaluation. Called once for every condition in a snippet's condition set. The built-in condition types are `page_type`, `user_logged_in`, `user_role`, `post_type`, and `page_id`. Use this filter to add support for entirely new condition types or to override existing evaluations.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$result` | `bool` | The evaluated condition result. |
| `$condition` | `array<string, string>` | The condition array containing `type`, `value`, and `operator` keys. |
| `$snippet_id` | `int` | The snippet post ID. |

**Returns:** `bool` — The (possibly overridden) condition result.

**Example:**

```php
add_filter( 'leastudios_snippets_condition_result', function ( bool $result, array $condition, int $snippet_id ): bool {
    // Add a custom "device_type" condition.
    if ( 'device_type' === $condition['type'] ) {
        $is_mobile = wp_is_mobile();
        $match     = ( 'mobile' === $condition['value'] ) ? $is_mobile : ! $is_mobile;

        return ( 'is_not' === $condition['operator'] ) ? ! $match : $match;
    }

    return $result;
}, 10, 3 );
```

---

#### `leastudios_snippets_should_execute`

- **Type:** Filter
- **Location:** `src/Execution/Snippet_Executor.php`
- **Since:** 1.0.0
- **Description:** Control whether a specific snippet should be executed. This filter runs after conditions have already passed. This is the final gate before execution. All built-in conditions have already been evaluated by this point. Return `false` to prevent the snippet from running. Useful for implementing custom business logic, feature flags, A/B tests, or environment-specific restrictions.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$should_execute` | `bool` | Whether the snippet should execute. Default `true`. |
| `$snippet_id` | `int` | The snippet post ID. |
| `$snippet_post` | `WP_Post` | The full snippet post object. |

**Returns:** `bool` — Whether the snippet should execute.

**Example:**

```php
add_filter( 'leastudios_snippets_should_execute', function ( bool $should_execute, int $snippet_id, WP_Post $snippet ): bool {
    // Never run snippets on staging environments.
    if ( defined( 'WP_ENVIRONMENT_TYPE' ) && 'staging' === WP_ENVIRONMENT_TYPE ) {
        return false;
    }

    return $should_execute;
}, 10, 3 );
```

---

#### `leastudios_snippets_output`

- **Type:** Filter
- **Location:** `src/Execution/Snippet_Executor.php`
- **Since:** 1.0.0
- **Description:** Filter the rendered HTML output of a non-PHP snippet (JS, CSS, or HTML) before it is echoed. Modify snippet output on the fly. You can add attributes to script/style tags, inject nonces for CSP compliance, wrap output in comments for debugging, or conditionally strip output.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$output` | `string` | The rendered output string. For JS snippets this includes `<script>` tags; for CSS it includes `<style>` tags; for HTML it is the raw markup. |
| `$snippet_id` | `int` | The snippet post ID. |
| `$type` | `string` | The snippet type: `js`, `css`, or `html`. |

**Returns:** `string` — The filtered output string.

**Example:**

```php
add_filter( 'leastudios_snippets_output', function ( string $output, int $snippet_id, string $type ): string {
    // Add a nonce attribute to all inline scripts for Content Security Policy.
    if ( 'js' === $type ) {
        $nonce  = wp_create_nonce( 'inline-script' );
        $output = str_replace( '<script>', '<script nonce="' . esc_attr( $nonce ) . '">', $output );
    }

    return $output;
}, 10, 3 );
```

---

#### `leastudios_snippets_before_execute`

- **Type:** Action
- **Location:** `src/Execution/Snippet_Executor.php`
- **Since:** 1.0.0
- **Description:** Fires immediately before a snippet's code is executed. Fires after all conditions and the `leastudios_snippets_should_execute` filter have passed, but before the code is actually evaluated or output. Ideal for logging, performance profiling, or setting up context that the snippet code might need.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$snippet_id` | `int` | The snippet post ID. |
| `$type` | `string` | The snippet type: `php`, `js`, `css`, or `html`. |
| `$location` | `string` | The auto-insert location (e.g. `wp_head`, `everywhere`, `shortcode`). |

**Example:**

```php
add_action( 'leastudios_snippets_before_execute', function ( int $snippet_id, string $type, string $location ): void {
    // Start a timer for performance monitoring.
    if ( 'php' === $type && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        global $leastudios_snippet_timers;
        $leastudios_snippet_timers[ $snippet_id ] = microtime( true );
    }
}, 10, 3 );
```

---

#### `leastudios_snippets_after_execute`

- **Type:** Action
- **Location:** `src/Execution/Snippet_Executor.php`
- **Since:** 1.0.0
- **Description:** Fires immediately after a snippet's code has been executed. Fires after the snippet code has been evaluated (PHP) or output (JS/CSS/HTML). Combined with `leastudios_snippets_before_execute`, this lets you measure execution time, log completions, or run cleanup logic.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$snippet_id` | `int` | The snippet post ID. |
| `$type` | `string` | The snippet type: `php`, `js`, `css`, or `html`. |
| `$location` | `string` | The auto-insert location. |

**Example:**

```php
add_action( 'leastudios_snippets_after_execute', function ( int $snippet_id, string $type, string $location ): void {
    // Log execution time for PHP snippets.
    if ( 'php' === $type && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        global $leastudios_snippet_timers;

        if ( isset( $leastudios_snippet_timers[ $snippet_id ] ) ) {
            $elapsed = microtime( true ) - $leastudios_snippet_timers[ $snippet_id ];
            error_log( sprintf(
                '[leaStudios Snippets] Snippet #%d executed in %.4f seconds (location: %s)',
                $snippet_id,
                $elapsed,
                $location
            ) );
        }
    }
}, 10, 3 );
```

---

#### `leastudios_snippets_php_error`

- **Type:** Action
- **Location:** `src/Execution/Snippet_Executor.php`
- **Since:** 1.0.0
- **Description:** Fires when a PHP snippet triggers a fatal error, exception, or PHP warning/notice during execution. When this action fires, the plugin has already automatically deactivated the offending snippet, added it to the safe mode list, stored the error in a transient (`leastudios_snippets_error_{$id}`), and queued an admin notice. Use this hook for external error reporting, Slack notifications, or audit logging.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$snippet_id` | `int` | The snippet post ID. |
| `$error_message` | `string` | The error message text. |

**Example:**

```php
add_action( 'leastudios_snippets_php_error', function ( int $snippet_id, string $error_message ): void {
    // Send a Slack notification when a snippet crashes.
    $webhook_url = 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL';
    $snippet_title = get_the_title( $snippet_id );

    wp_remote_post( $webhook_url, [
        'body'    => wp_json_encode( [
            'text' => sprintf(
                ':warning: *leaStudios Snippets* -- "%s" (ID: %d) was auto-deactivated due to an error: `%s`',
                $snippet_title,
                $snippet_id,
                $error_message
            ),
        ] ),
        'headers' => [ 'Content-Type' => 'application/json' ],
        'timeout' => 5,
    ] );
}, 10, 2 );
```

---

### Post Type & Locations

#### `leastudios_snippets_admin_capability`

- **Type:** Filter
- **Location:** `src/Admin/Snippets_Page.php`
- **Since:** 1.0.0
- **Description:** Filter the WordPress capability required to access the Snippets admin menu. The default is `manage_options`. Use this to grant access to a custom capability without touching the plugin's CPT capability mapping.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$capability` | `string` | The required capability slug. Default `'manage_options'`. |

**Returns:** `string` — The capability slug used to register the admin menu page.

**Example:**

```php
add_filter( 'leastudios_snippets_admin_capability', function ( string $capability ): string {
    // Allow a custom role with 'manage_snippets' to access the Snippets menu.
    return 'manage_snippets';
} );
```

---

#### `leastudios_snippets_condition_types`

- **Type:** Filter
- **Location:** `src/Admin/Snippet_Editor.php`
- **Since:** 1.0.0
- **Description:** Filter the available condition types shown in the snippet editor's condition builder. The built-in types are `page_type`, `user_logged`, `user_role`, `post_type`, and `page_post_id`. Add entries here to expose new condition types in the editor UI. Pair this with `leastudios_snippets_condition_result` to implement the evaluation logic for any new type.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$condition_types` | `array<string, string>` | Associative array of condition slug to human-readable label. |

**Returns:** `array<string, string>` — The filtered condition types array.

**Example:**

```php
add_filter( 'leastudios_snippets_condition_types', function ( array $condition_types ): array {
    // Add a "Device Type" condition visible in the editor UI.
    $condition_types['device_type'] = __( 'Device Type', 'my-plugin' );

    return $condition_types;
} );
```

---

#### `leastudios_snippets_editing_disabled`

- **Type:** Filter
- **Location:** `src/CPT/Snippet_Post_Type.php`
- **Since:** 1.1.0
- **Description:** Filter whether snippet creation and editing are disabled site-wide. Returned by `Snippet_Post_Type::is_editing_disabled()`. When `true`, the snippet write capabilities map to `do_not_allow` (blocking creation/editing/deletion through the admin UI, REST, and WP-CLI) and the Library page refuses to install snippets. Already-saved snippets continue to execute.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$disabled` | `bool` | Whether editing is disabled. Defaults to `true` when `DISALLOW_FILE_MODS` or `DISALLOW_FILE_EDIT` is defined and truthy. |

**Returns:** `bool` — Whether snippet editing should be disabled.

**Example:**

```php
// Also lock snippet editing on the staging environment.
add_filter( 'leastudios_snippets_editing_disabled', function ( bool $disabled ): bool {
	if ( defined( 'WP_ENVIRONMENT_TYPE' ) && 'staging' === WP_ENVIRONMENT_TYPE ) {
		return true;
	}

	return $disabled;
} );
```

---

#### `leastudios_snippets_types`

- **Type:** Filter
- **Location:** `src/Admin/Snippet_Editor.php`
- **Since:** 1.0.0
- **Description:** Filter the available snippet language types shown in the snippet editor. The built-in types are `php`, `js`, `css`, and `html`. Add new entries to introduce additional language types. You are responsible for handling execution of the new type — the execution engine will not know how to evaluate it without corresponding changes.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$types` | `array<string, string>` | Associative array of type slug to human-readable label. |

**Returns:** `array<string, string>` — The filtered types array.

**Example:**

```php
add_filter( 'leastudios_snippets_types', function ( array $types ): array {
    // Add a "Twig" type label (requires custom execution handling).
    $types['twig'] = __( 'Twig Template', 'my-plugin' );

    return $types;
} );
```

---

#### `leastudios_snippets_locations`

- **Type:** Filter
- **Location:** `src/CPT/Snippet_Post_Type.php`
- **Since:** 1.0.0
- **Description:** Filter the available auto-insert locations that appear in the snippet editor dropdown. Called inside `Snippet_Post_Type::get_locations()`. The default locations include `everywhere`, `wp_head`, `wp_footer`, `wp_body_open`, `the_content_before`, `the_content_after`, `admin_head`, `admin_footer`, `login_head`, `custom_hook`, `shortcode`, `frontend_header`, and `frontend_footer`. Add your own entries to expose custom insertion points in the editor UI.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$locations` | `array<string, string>` | Associative array of location slug to human-readable label. |

**Returns:** `array<string, string>` — The filtered locations array.

**Example:**

```php
add_filter( 'leastudios_snippets_locations', function ( array $locations ): array {
    // Add a WooCommerce-specific location.
    $locations['woocommerce_before_cart'] = 'Before WooCommerce Cart';

    return $locations;
} );
```

> **Note:** Adding a location here only makes it selectable in the UI. You must also handle the actual hook registration in `register_location_hooks` or by listening for snippets with that location value yourself.

---

#### `leastudios_snippets_post_type_args`

- **Type:** Filter
- **Location:** `src/CPT/Snippet_Post_Type.php`
- **Since:** 1.0.0
- **Description:** Filter the arguments passed to `register_post_type()` for the `leastudios_snippet` post type. Lets you modify any aspect of the custom post type before it is registered — labels, capabilities, REST API visibility, supports, and so on.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$args` | `array` | The post type registration arguments array, as accepted by `register_post_type()`. |

**Returns:** `array` — The filtered arguments array.

**Example:**

```php
add_filter( 'leastudios_snippets_post_type_args', function ( array $args ): array {
    // Expose snippets in the REST API for a headless integration.
    $args['show_in_rest'] = true;

    // Add excerpt support.
    $args['supports'][] = 'excerpt';

    return $args;
} );
```

---

### Library

#### `leastudios_snippets_library_snippets`

- **Type:** Filter
- **Location:** `src/Library/Snippet_Library.php`
- **Since:** 1.0.0
- **Description:** Filter the full list of snippet definitions available in the snippet library. Add, remove, or modify pre-built snippets shown on the Library page in the admin. This is the primary hook for registering your own library snippets programmatically. See Section 8 (Recipes) for full usage examples.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$snippets` | `array<int, array<string, mixed>>` | Array of snippet definition arrays. Each definition has keys: `title`, `description`, `code`, `type`, `location`, `priority`, `category`, and `requires_plugin`. |

**Returns:** `array<int, array<string, mixed>>` — The filtered snippet definitions array.

**Example:**

```php
add_filter( 'leastudios_snippets_library_snippets', function ( array $snippets ): array {
    $snippets[] = [
        'title'           => 'Disable Gutenberg Editor',
        'description'     => 'Reverts to the Classic Editor for all post types.',
        'code'            => "add_filter( 'use_block_editor_for_post', '__return_false' );",
        'type'            => 'php',
        'location'        => 'everywhere',
        'priority'        => 10,
        'category'        => 'general',
        'requires_plugin' => null,
    ];

    return $snippets;
} );
```

---

#### `leastudios_snippets_library_categories`

- **Type:** Filter
- **Location:** `src/Admin/Library_Page.php`
- **Since:** 1.0.0
- **Description:** Filter the category labels displayed in the Library page's category filter UI. The default categories are derived from the `category` keys of the available snippet definitions. Use this to add, remove, or rename category labels.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$categories` | `array<string, string>` | Associative array of category slug to human-readable label. |

**Returns:** `array<string, string>` — The filtered categories array.

**Example:**

```php
add_filter( 'leastudios_snippets_library_categories', function ( array $categories ): array {
    // Rename the 'general' category label.
    if ( isset( $categories['general'] ) ) {
        $categories['general'] = __( 'General Utilities', 'my-plugin' );
    }

    // Add a custom category for your own library snippets.
    $categories['my-plugin'] = __( 'My Plugin Extras', 'my-plugin' );

    return $categories;
} );
```

---

#### `leastudios_snippets_before_library_install`

- **Type:** Action
- **Location:** `src/Library/Snippet_Library.php`
- **Since:** 1.0.0
- **Description:** Fires just before a library snippet is installed as a new post. Runs before `wp_insert_post()` is called. Use this to validate the snippet, log installations, or modify global state before the post is created.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$snippet` | `array<string, mixed>` | The full snippet definition array being installed. Contains keys: `title`, `description`, `code`, `type`, `location`, `priority`, `category`, `requires_plugin`. |

**Example:**

```php
add_action( 'leastudios_snippets_before_library_install', function ( array $snippet ): void {
    // Log every library installation for audit purposes.
    $current_user = wp_get_current_user();

    error_log( sprintf(
        '[leaStudios Snippets] User "%s" is installing library snippet: %s',
        $current_user->user_login,
        $snippet['title']
    ) );
} );
```

---

#### `leastudios_snippets_after_library_install`

- **Type:** Action
- **Location:** `src/Library/Snippet_Library.php`
- **Since:** 1.0.0
- **Description:** Fires after a library snippet has been successfully installed as a new post. At this point the post has been created and all meta fields (code, type, location, priority, active status) have been saved. The snippet is inactive by default (`META_ACTIVE = '0'`). Use this hook to set additional meta, auto-activate the snippet, assign taxonomy terms, or trigger downstream workflows.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$post_id` | `int` | The newly created snippet post ID. |
| `$snippet` | `array<string, mixed>` | The snippet definition that was installed. |

**Example:**

```php
add_action( 'leastudios_snippets_after_library_install', function ( int $post_id, array $snippet ): void {
    // Auto-activate CSS snippets from the library.
    if ( 'css' === $snippet['type'] ) {
        update_post_meta( $post_id, '_leastudios_snippets_active', '1' );
    }

    // Store the original library category as post meta for filtering.
    update_post_meta( $post_id, '_leastudios_snippets_library_category', $snippet['category'] );
}, 10, 2 );
```

---

#### `leastudios_snippets_library_snippet_installed`

- **Type:** Action
- **Location:** `src/Admin/Library_Page.php`
- **Since:** 1.0.0
- **Description:** Fires after a library snippet has been installed via the Library admin page UI (distinct from `leastudios_snippets_after_library_install`, which fires during `Snippet_Library::install_snippet()` for both UI and programmatic installs). At this point the new post exists and the admin is about to redirect to the snippet edit screen. Use this hook for UI-specific post-install workflows.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$post_id` | `int` | The newly created snippet post ID. |
| `$slug` | `string` | The library snippet slug that was installed. |

**Example:**

```php
add_action( 'leastudios_snippets_library_snippet_installed', function ( int $post_id, string $slug ): void {
    // Track which library snippets have been installed via the UI.
    $installed = get_option( 'my_plugin_installed_library_snippets', [] );
    $installed[] = [
        'slug'       => $slug,
        'post_id'    => $post_id,
        'installed'  => current_time( 'mysql' ),
    ];
    update_option( 'my_plugin_installed_library_snippets', $installed );
}, 10, 2 );
```

---

### Post Type & Lifecycle

#### `leastudios_snippets_before_save`

- **Type:** Action
- **Location:** `src/Admin/Snippet_Editor.php`
- **Since:** 1.0.0
- **Description:** Fires at the start of `Snippet_Editor::save_meta()`, before any snippet meta fields are written. Runs after nonce and capability checks have passed. Use this to add pre-save validation, logging, or to set up state that post-save logic depends on.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$post_id` | `int` | The snippet post ID being saved. |
| `$post` | `WP_Post` | The snippet post object. |

**Example:**

```php
add_action( 'leastudios_snippets_before_save', function ( int $post_id, WP_Post $post ): void {
    // Log the editor's action for audit purposes.
    $user = wp_get_current_user();
    error_log( sprintf(
        '[leaStudios Snippets] User "%s" is saving snippet #%d ("%s")',
        $user->user_login,
        $post_id,
        $post->post_title
    ) );
}, 10, 2 );
```

---

#### `leastudios_snippets_after_save`

- **Type:** Action
- **Location:** `src/Admin/Snippet_Editor.php`
- **Since:** 1.0.0
- **Description:** Fires at the end of `Snippet_Editor::save_meta()`, after all snippet meta fields have been written. Use this to react to snippet saves — for example, to sync snippet data to an external system or to invalidate a cache that depends on snippet content.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$post_id` | `int` | The snippet post ID that was saved. |
| `$post` | `WP_Post` | The snippet post object. |

**Example:**

```php
add_action( 'leastudios_snippets_after_save', function ( int $post_id, WP_Post $post ): void {
    // Invalidate a custom cache that holds compiled snippet output.
    delete_transient( 'my_plugin_compiled_snippets' );
}, 10, 2 );
```

---

#### `leastudios_snippets_snippet_reactivated`

- **Type:** Action
- **Location:** `src/Admin/Safe_Mode_Notice.php`
- **Since:** 1.0.0
- **Description:** Fires when an administrator manually reactivates a snippet that was previously deactivated by safe mode. Fires after `META_ACTIVE` has been set back to `'1'` and the snippet ID has been removed from the `leastudios_snippets_safe_mode` option, but before the admin redirect. Use this to clear related caches, send alerts, or log the reactivation.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$snippet_id` | `int` | The snippet post ID that was reactivated. |

**Example:**

```php
add_action( 'leastudios_snippets_snippet_reactivated', function ( int $snippet_id ): void {
    // Notify a monitoring channel when a safe-mode snippet is manually reactivated.
    $snippet_title = get_the_title( $snippet_id );
    $user          = wp_get_current_user();

    error_log( sprintf(
        '[leaStudios Snippets] User "%s" reactivated snippet #%d ("%s") from safe mode.',
        $user->user_login,
        $snippet_id,
        $snippet_title
    ) );
} );
```

---

#### `leastudios_snippets_initialized`

- **Type:** Action
- **Location:** `src/Plugin.php`
- **Since:** 1.0.0
- **Description:** Fires after all leaStudios Snippets components have been initialised. This action fires at the end of `Plugin::init()`, after the custom post type has been registered, the snippet executor has loaded all active snippets, and admin components (editor, library page, safe mode notices) have been wired up. Use it to run code that depends on the entire plugin being ready.

**Parameters:**

None.

**Example:**

```php
add_action( 'leastudios_snippets_initialized', function (): void {
    // Register a custom REST endpoint that queries snippet data.
    add_action( 'rest_api_init', function (): void {
        register_rest_route( 'my-app/v1', '/active-snippets', [
            'methods'             => 'GET',
            'callback'            => 'my_get_active_snippets',
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ] );
    } );
} );
```

---

## 7. Hook Execution Order

The following diagram shows the order in which leaStudios Snippets hooks fire during a typical page load. WordPress core hooks are shown in parentheses for context.

```
(plugins_loaded)
    |
(init)  -- priority 1
    |
    +-- Snippet_Executor::init()
    |       |
    |       +-- Queries all active snippets
    |       +-- Groups snippets by location
    |       +-- register_location_hooks()
    |       |       |
    |       |       +-- "everywhere" snippets execute immediately:
    |       |       |       |
    |       |       |       +-- Condition_Checker::check()
    |       |       |       |       |
    |       |       |       |       +-- [filter] leastudios_snippets_condition_result  (per condition)
    |       |       |       |
    |       |       |       +-- [filter] leastudios_snippets_should_execute
    |       |       |       +-- [action] leastudios_snippets_before_execute
    |       |       |       +-- execute_php() / execute_output()
    |       |       |       |       |
    |       |       |       |       +-- [filter] leastudios_snippets_output  (non-PHP only)
    |       |       |       |       +-- [action] leastudios_snippets_php_error  (on error, PHP only)
    |       |       |       |
    |       |       |       +-- [action] leastudios_snippets_after_execute
    |       |       |
    |       |       +-- Other locations register callbacks on their
    |       |           respective WordPress hooks (wp_head, wp_footer, etc.)
    |       |
    |       +-- Shortcode handler registered for [leastudios_snippet]
    |
(init)  -- default priority
    |
    +-- Snippet_Post_Type::register()
    |       |
    |       +-- [filter] leastudios_snippets_post_type_args
    |
    +-- Snippet_Post_Type::get_locations()  (called from admin UI)
    |       |
    |       +-- [filter] leastudios_snippets_locations
    |
    +-- Plugin::init() completes
            |
            +-- [action] leastudios_snippets_initialized
    |
(wp)
    |
(wp_head)
    |   +-- wp_head / frontend_header snippets execute
    |       (same condition/filter/action sequence as above)
    |
(wp_body_open)
    |   +-- wp_body_open snippets execute
    |
(the_content)
    |   +-- the_content_before / the_content_after snippets execute
    |
(wp_footer)
    |   +-- wp_footer / frontend_footer snippets execute
    |
(admin_head)  -- admin requests only
    |   +-- admin_head snippets execute
    |
(admin_footer)  -- admin requests only
    |   +-- admin_footer snippets execute
    |
(login_head)  -- login page only
    |   +-- login_head snippets execute
```

### Library Installation Hooks

When a snippet is installed from the library (via the admin UI or programmatically):

```
Snippet_Library::install_snippet()
    |
    +-- [action] leastudios_snippets_before_library_install
    |
    +-- wp_insert_post() + update_post_meta()
    |
    +-- [action] leastudios_snippets_after_library_install
```

### Summary Table

| Order | Hook | Type | Trigger |
|---|---|---|---|
| 1 | `leastudios_snippets_condition_result` | Filter | Each condition evaluated |
| 2 | `leastudios_snippets_should_execute` | Filter | After all conditions pass |
| 3 | `leastudios_snippets_before_execute` | Action | Immediately before code runs |
| 4 | `leastudios_snippets_output` | Filter | Non-PHP output rendering |
| 4a | `leastudios_snippets_php_error` | Action | PHP snippet error (replaces normal completion) |
| 5 | `leastudios_snippets_after_execute` | Action | Immediately after code runs |
| -- | `leastudios_snippets_admin_capability` | Filter | When the admin menu is registered |
| -- | `leastudios_snippets_condition_types` | Filter | When condition types are built for the editor |
| -- | `leastudios_snippets_editing_disabled` | Filter | When editing lock status is checked |
| -- | `leastudios_snippets_locations` | Filter | When locations list is requested |
| -- | `leastudios_snippets_post_type_args` | Filter | During post type registration |
| -- | `leastudios_snippets_types` | Filter | When snippet language types are built for the editor |
| -- | `leastudios_snippets_library_categories` | Filter | When library category labels are built |
| -- | `leastudios_snippets_library_snippets` | Filter | When library definitions are loaded |
| -- | `leastudios_snippets_before_library_install` | Action | Before a library snippet is saved |
| -- | `leastudios_snippets_after_library_install` | Action | After a library snippet is saved |
| -- | `leastudios_snippets_library_snippet_installed` | Action | After UI-triggered library install, before redirect |
| -- | `leastudios_snippets_before_save` | Action | Before snippet meta fields are written |
| -- | `leastudios_snippets_after_save` | Action | After all snippet meta fields have been written |
| -- | `leastudios_snippets_snippet_reactivated` | Action | After a safe-mode snippet is manually reactivated |
| -- | `leastudios_snippets_initialized` | Action | After full plugin init |

---

## 8. Extension Recipes

### How do I add a single custom snippet to the library?

**Goal:** Register one new snippet definition that users can install with a single click from the Library admin page.

**Hooks used:** `leastudios_snippets_library_snippets`.

**Walkthrough:** The `leastudios_snippets_library_snippets` filter receives the full array of
snippet definitions before the Library page renders. Append your definition to the array and
return it. The definition must include all eight required keys. The `title` value is used as a
human-readable display name; use a unique, descriptive title. The `code` field for PHP snippets
should omit the opening `<?php` tag. The snippet will appear immediately in the Library page
under the `category` you specify.

Place this filter in a must-use plugin or a plugin that loads early; the Library page reads
definitions each time it renders, so there is no persistent registration to worry about.

**Complete example:**

```php
add_filter( 'leastudios_snippets_library_snippets', function ( array $snippets ): array {
    $snippets[] = [
        'title'           => 'Limit Post Revisions to 5',
        'description'     => 'Caps the number of stored post revisions at 5 to reduce database bloat.',
        'code'            => "if ( ! defined( 'WP_POST_REVISIONS' ) ) {\n\tdefine( 'WP_POST_REVISIONS', 5 );\n}",
        'type'            => 'php',
        'location'        => 'everywhere',
        'priority'        => 5,
        'category'        => 'performance',
        'requires_plugin' => null,
    ];

    return $snippets;
} );
```

---

### How do I bulk-add library snippets from a plugin?

**Goal:** Register multiple snippet definitions in one filter callback, keeping them organised in a dedicated array.

**Hooks used:** `leastudios_snippets_library_snippets`.

**Walkthrough:** Build a local array of definitions and merge it into the incoming `$snippets`
array with `array_merge()`. This keeps your definitions together and avoids re-registering the
same filter multiple times.

Each definition is independent — types, locations, and categories can differ freely across
the set. Using `array_merge()` (rather than repeated `$snippets[] = …`) also makes it easy to
source your definitions from a separate file or a method on a library class.

**Complete example:**

```php
add_filter( 'leastudios_snippets_library_snippets', function ( array $snippets ): array {
    $my_snippets = [
        [
            'title'           => 'Redirect After Login',
            'description'     => 'Redirects all non-admin users to the homepage after logging in.',
            'code'            => "add_filter( 'login_redirect', function ( \$redirect_to, \$request, \$user ) {\n\tif ( ! is_wp_error( \$user ) && ! in_array( 'administrator', (array) \$user->roles, true ) ) {\n\t\treturn home_url();\n\t}\n\treturn \$redirect_to;\n}, 10, 3 );",
            'type'            => 'php',
            'location'        => 'everywhere',
            'priority'        => 10,
            'category'        => 'security',
            'requires_plugin' => null,
        ],
        [
            'title'           => 'Add Maintenance Mode Banner',
            'description'     => 'Displays a dismissible maintenance mode banner at the top of every page.',
            'code'            => "<div style=\"background:#f0ad4e;color:#fff;text-align:center;padding:10px;font-weight:bold;\">\n\tWe are currently performing scheduled maintenance. Some features may be unavailable.\n</div>",
            'type'            => 'html',
            'location'        => 'wp_body_open',
            'priority'        => 1,
            'category'        => 'general',
            'requires_plugin' => null,
        ],
    ];

    return array_merge( $snippets, $my_snippets );
} );
```

---

### How do I add a library snippet that requires a specific sibling plugin?

**Goal:** Register a snippet that should only appear in the Library when a particular plugin is active (e.g. a WooCommerce-specific snippet that is useless without WooCommerce).

**Hooks used:** `leastudios_snippets_library_snippets`.

**Walkthrough:** Set the `requires_plugin` key to the plugin's directory slug (the folder name
under `wp-content/plugins/`). `Snippet_Library::get_available_snippets()` passes each
definition through `Suite_Detector::is_active()`, which checks whether the plugin is active.
Definitions whose `requires_plugin` plugin is not active are filtered out before the Library
page renders — users never see snippets that would not work on their site.

Set `requires_plugin` to `null` for snippets with no plugin dependency.

**Complete example:**

```php
add_filter( 'leastudios_snippets_library_snippets', function ( array $snippets ): array {
    $snippets[] = [
        'title'           => 'Disable WooCommerce Reviews',
        'description'     => 'Removes the reviews tab from WooCommerce product pages.',
        'code'            => "add_filter( 'woocommerce_product_tabs', function ( \$tabs ) {\n\tunset( \$tabs['reviews'] );\n\treturn \$tabs;\n} );",
        'type'            => 'php',
        'location'        => 'everywhere',
        'priority'        => 10,
        'category'        => 'woocommerce',
        'requires_plugin' => 'woocommerce',
    ];

    return $snippets;
} );
```

---

### How do I remove a built-in library snippet?

**Goal:** Hide a specific built-in library snippet from the Library page so users cannot install it.

**Hooks used:** `leastudios_snippets_library_snippets`.

**Walkthrough:** Filter the `$snippets` array with `array_filter()` and match on the `title`
key. Use `array_values()` to re-index the array after filtering, so the Library page does not
encounter gaps in numeric keys.

The title comparison is exact and case-sensitive — use the same string you see in the admin
UI. Do not rely on array index position, as it may change if the built-in definitions are
reordered in a future release.

**Complete example:**

```php
add_filter( 'leastudios_snippets_library_snippets', function ( array $snippets ): array {
    return array_values( array_filter( $snippets, function ( array $snippet ): bool {
        return 'Disable XML-RPC' !== $snippet['title'];
    } ) );
} );
```

---

### How do I install a library snippet programmatically?

**Goal:** Install a snippet from the library in PHP — for example, during plugin activation or
a WP-CLI command — without requiring a user to click through the Library admin page.

**Hooks used:** `leastudios_snippets_before_library_install`, `leastudios_snippets_after_library_install`.

**Walkthrough:** Instantiate `Snippet_Library` and call `install_snippet()` with the exact
title of the snippet you want to install. The method creates a new `leastudios_snippet` post
from the matching definition and returns the new post ID. The snippet is created as **inactive**
by default — you must explicitly set `_leastudios_snippets_active` to `'1'` if you want it
to run immediately.

`install_snippet()` throws `\InvalidArgumentException` if the title is not found in the
available definitions and `\RuntimeException` if `wp_insert_post()` fails. Always wrap the
call in a try/catch.

`leastudios_snippets_before_library_install` and `leastudios_snippets_after_library_install`
fire as part of `install_snippet()`, so any hooks registered on those actions will run during
programmatic installs as well as UI installs.

**Complete example:**

```php
use LEAStudios\Snippets\Library\Snippet_Library;

$library = new Snippet_Library();

try {
    $post_id = $library->install_snippet( 'Disable XML-RPC' );
    // Optionally activate it immediately.
    update_post_meta( $post_id, '_leastudios_snippets_active', '1' );
} catch ( \InvalidArgumentException $e ) {
    // Snippet title not found in the library.
    error_log( $e->getMessage() );
} catch ( \RuntimeException $e ) {
    // Post creation failed.
    error_log( $e->getMessage() );
}
```

---

## 9. Testing

```bash
cd wp-content/plugins/leastudios-snippets
composer test                                              # run the full suite
vendor/bin/phpunit --filter ConditionCheckerTest          # one class
vendor/bin/phpunit tests/SnippetExecutorTest.php          # one file
```

The suite uses PHPUnit 9.6 against the WordPress test library (`/tmp/wordpress-tests-lib`).
Install it once with:

```bash
bash ../leastudios-dev-tools/bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
```

**Writing tests for an extension that loads this plugin:**

1. Ensure `leastudios-snippets` is active in the test environment (add it to the test
   bootstrap or activate it via WP-CLI in your test setup script).
2. `Snippet_Executor` is initialised during `Plugin::init()` (which runs on `plugins_loaded`).
   To test condition or execution hooks, register your callbacks before `plugins_loaded` fires.
3. To test a custom condition type, hook `leastudios_snippets_condition_result` in your
   `setUp()` and assert the resulting execution behaviour against a known snippet fixture.
4. `Safe_Mode` state (the `leastudios_snippets_safe_mode` option) persists across test cases
   unless explicitly cleared — call `delete_option( 'leastudios_snippets_safe_mode' )` and
   `wp_cache_delete( 'leastudios_snippets_active_ids', 'leastudios_snippets' )` in `tearDown()`
   when testing execution flows that may trigger deactivation.

---

## 10. Release Process

This plugin uses a tag-triggered release workflow (`.github/workflows/release.yml`)
that auto-generates release notes from the commit log between the previous and current tag.

**To cut a release:** bump the `Version:` header in `leastudios-snippets.php`, commit, then:

```bash
git tag v<X.Y.Z> && git push origin v<X.Y.Z>
```

**Commit-prefix → release-notes section:**

- `feat:` → `## Added`
- `fix:` → `## Fixed`
- `refactor:` → `## Changed`
- `perf:` → `## Performance`

**Hidden from release notes:** `ci:`, `chore:`, `docs:`, `test:`, `style:`, `build:`, `release:`.

---

## 11. Where to Read More

- [`CLAUDE.md`](../CLAUDE.md) — this plugin's repo conventions, safe-mode architecture, capability model, and execution-flow gotchas.
- [`README.md`](../README.md) — user-facing overview and feature list.
- [`leastudios-dev-tools/CLAUDE.md`](../../leastudios-dev-tools/CLAUDE.md) — suite-wide coding standards, security checklist (escape / sanitize / nonce / capability), and conventions inherited by every plugin.
