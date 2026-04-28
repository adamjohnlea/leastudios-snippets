# leaStudios Snippets -- Developer Handbook

This handbook documents every WordPress hook (action and filter) provided by the leaStudios Snippets plugin. Use these hooks to extend, customise, or integrate with the snippet execution engine, the condition system, the snippet library, and the custom post type.

---

## Table of Contents

1. [Filters](#filters)
   - [`leastudios_snippets_locations`](#leastudios_snippets_locations)
   - [`leastudios_snippets_post_type_args`](#leastudios_snippets_post_type_args)
   - [`leastudios_snippets_should_execute`](#leastudios_snippets_should_execute)
   - [`leastudios_snippets_output`](#leastudios_snippets_output)
   - [`leastudios_snippets_condition_result`](#leastudios_snippets_condition_result)
   - [`leastudios_snippets_library_snippets`](#leastudios_snippets_library_snippets)
2. [Actions](#actions)
   - [`leastudios_snippets_initialized`](#leastudios_snippets_initialized)
   - [`leastudios_snippets_before_execute`](#leastudios_snippets_before_execute)
   - [`leastudios_snippets_after_execute`](#leastudios_snippets_after_execute)
   - [`leastudios_snippets_php_error`](#leastudios_snippets_php_error)
   - [`leastudios_snippets_before_library_install`](#leastudios_snippets_before_library_install)
   - [`leastudios_snippets_after_library_install`](#leastudios_snippets_after_library_install)
3. [Snippet Library -- Adding Custom Snippets Programmatically](#snippet-library----adding-custom-snippets-programmatically)
4. [Hook Execution Order](#hook-execution-order)

---

## Filters

### `leastudios_snippets_locations`

Filter the available auto-insert locations that appear in the snippet editor dropdown.

| Detail | Value |
|---|---|
| **Type** | Filter |
| **File** | `src/CPT/Snippet_Post_Type.php` |
| **Since** | 1.0.0 |

#### Parameters

| Parameter | Type | Description |
|---|---|---|
| `$locations` | `array<string, string>` | Associative array of location slug to human-readable label. |

#### Description

Called inside `Snippet_Post_Type::get_locations()`. The default locations include `everywhere`, `wp_head`, `wp_footer`, `wp_body_open`, `the_content_before`, `the_content_after`, `admin_head`, `admin_footer`, `login_head`, `custom_hook`, `shortcode`, `frontend_header`, and `frontend_footer`. Add your own entries to expose custom insertion points in the editor UI.

#### Example

```php
add_filter( 'leastudios_snippets_locations', function ( array $locations ): array {
    // Add a WooCommerce-specific location.
    $locations['woocommerce_before_cart'] = 'Before WooCommerce Cart';

    return $locations;
} );
```

> **Note:** Adding a location here only makes it selectable in the UI. You must also handle the actual hook registration in `register_location_hooks` or by listening for snippets with that location value yourself.

---

### `leastudios_snippets_post_type_args`

Filter the arguments passed to `register_post_type()` for the `leastudios_snippet` post type.

| Detail | Value |
|---|---|
| **Type** | Filter |
| **File** | `src/CPT/Snippet_Post_Type.php` |
| **Since** | 1.0.0 |

#### Parameters

| Parameter | Type | Description |
|---|---|---|
| `$args` | `array` | The post type registration arguments array, as accepted by `register_post_type()`. |

#### Description

Lets you modify any aspect of the custom post type before it is registered -- labels, capabilities, REST API visibility, supports, and so on.

#### Example

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

### `leastudios_snippets_should_execute`

Control whether a specific snippet should be executed. This filter runs after conditions have already passed.

| Detail | Value |
|---|---|
| **Type** | Filter |
| **File** | `src/Execution/Snippet_Executor.php` |
| **Since** | 1.0.0 |

#### Parameters

| Parameter | Type | Description |
|---|---|---|
| `$should_execute` | `bool` | Whether the snippet should execute. Default `true`. |
| `$snippet_id` | `int` | The snippet post ID. |
| `$snippet_post` | `WP_Post` | The full snippet post object. |

#### Description

This is the final gate before execution. All built-in conditions have already been evaluated by this point. Return `false` to prevent the snippet from running. Useful for implementing custom business logic, feature flags, A/B tests, or environment-specific restrictions.

#### Example

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

### `leastudios_snippets_output`

Filter the rendered HTML output of a non-PHP snippet (JS, CSS, or HTML) before it is echoed.

| Detail | Value |
|---|---|
| **Type** | Filter |
| **File** | `src/Execution/Snippet_Executor.php` |
| **Since** | 1.0.0 |

#### Parameters

| Parameter | Type | Description |
|---|---|---|
| `$output` | `string` | The rendered output string. For JS snippets this includes `<script>` tags; for CSS it includes `<style>` tags; for HTML it is the raw markup. |
| `$snippet_id` | `int` | The snippet post ID. |
| `$type` | `string` | The snippet type: `js`, `css`, or `html`. |

#### Description

Modify snippet output on the fly. You can add attributes to script/style tags, inject nonces for CSP compliance, wrap output in comments for debugging, or conditionally strip output.

#### Example

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

### `leastudios_snippets_condition_result`

Override or extend the result of an individual condition evaluation.

| Detail | Value |
|---|---|
| **Type** | Filter |
| **File** | `src/Execution/Condition_Checker.php` |
| **Since** | 1.0.0 |

#### Parameters

| Parameter | Type | Description |
|---|---|---|
| `$result` | `bool` | The evaluated condition result. |
| `$condition` | `array<string, string>` | The condition array containing `type`, `value`, and `operator` keys. |
| `$snippet_id` | `int` | The snippet post ID. |

#### Description

Called once for every condition in a snippet's condition set. The built-in condition types are `page_type`, `user_logged_in`, `user_role`, `post_type`, and `page_id`. Use this filter to add support for entirely new condition types or to override existing evaluations.

#### Example

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

### `leastudios_snippets_library_snippets`

Filter the full list of snippet definitions available in the snippet library.

| Detail | Value |
|---|---|
| **Type** | Filter |
| **File** | `src/Library/Snippet_Library.php` |
| **Since** | 1.0.0 |

#### Parameters

| Parameter | Type | Description |
|---|---|---|
| `$snippets` | `array<int, array<string, mixed>>` | Array of snippet definition arrays. Each definition has keys: `title`, `description`, `code`, `type`, `location`, `priority`, `category`, and `requires_plugin`. |

#### Description

Add, remove, or modify pre-built snippets shown on the Library page in the admin. This is the primary hook for registering your own library snippets programmatically. See the [Snippet Library section](#snippet-library----adding-custom-snippets-programmatically) below for full details.

#### Example

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

## Actions

### `leastudios_snippets_initialized`

Fires after all leaStudios Snippets components have been initialised.

| Detail | Value |
|---|---|
| **Type** | Action |
| **File** | `src/Plugin.php` |
| **Since** | 1.0.0 |

#### Parameters

None.

#### Description

This action fires at the end of `Plugin::init()`, after the custom post type has been registered, the snippet executor has loaded all active snippets, and admin components (editor, library page, safe mode notices) have been wired up. Use it to run code that depends on the entire plugin being ready.

#### Example

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

### `leastudios_snippets_before_execute`

Fires immediately before a snippet's code is executed.

| Detail | Value |
|---|---|
| **Type** | Action |
| **File** | `src/Execution/Snippet_Executor.php` |
| **Since** | 1.0.0 |

#### Parameters

| Parameter | Type | Description |
|---|---|---|
| `$snippet_id` | `int` | The snippet post ID. |
| `$type` | `string` | The snippet type: `php`, `js`, `css`, or `html`. |
| `$location` | `string` | The auto-insert location (e.g. `wp_head`, `everywhere`, `shortcode`). |

#### Description

Fires after all conditions and the `leastudios_snippets_should_execute` filter have passed, but before the code is actually evaluated or output. Ideal for logging, performance profiling, or setting up context that the snippet code might need.

#### Example

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

### `leastudios_snippets_after_execute`

Fires immediately after a snippet's code has been executed.

| Detail | Value |
|---|---|
| **Type** | Action |
| **File** | `src/Execution/Snippet_Executor.php` |
| **Since** | 1.0.0 |

#### Parameters

| Parameter | Type | Description |
|---|---|---|
| `$snippet_id` | `int` | The snippet post ID. |
| `$type` | `string` | The snippet type: `php`, `js`, `css`, or `html`. |
| `$location` | `string` | The auto-insert location. |

#### Description

Fires after the snippet code has been evaluated (PHP) or output (JS/CSS/HTML). Combined with `leastudios_snippets_before_execute`, this lets you measure execution time, log completions, or run cleanup logic.

#### Example

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

### `leastudios_snippets_php_error`

Fires when a PHP snippet triggers a fatal error, exception, or PHP warning/notice during execution.

| Detail | Value |
|---|---|
| **Type** | Action |
| **File** | `src/Execution/Snippet_Executor.php` |
| **Since** | 1.0.0 |

#### Parameters

| Parameter | Type | Description |
|---|---|---|
| `$snippet_id` | `int` | The snippet post ID. |
| `$error_message` | `string` | The error message text. |

#### Description

When this action fires, the plugin has already automatically deactivated the offending snippet, added it to the safe mode list, stored the error in a transient (`leastudios_snippets_error_{$id}`), and queued an admin notice. Use this hook for external error reporting, Slack notifications, or audit logging.

#### Example

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

### `leastudios_snippets_before_library_install`

Fires just before a library snippet is installed as a new post.

| Detail | Value |
|---|---|
| **Type** | Action |
| **File** | `src/Library/Snippet_Library.php` |
| **Since** | 1.0.0 |

#### Parameters

| Parameter | Type | Description |
|---|---|---|
| `$snippet` | `array<string, mixed>` | The full snippet definition array being installed. Contains keys: `title`, `description`, `code`, `type`, `location`, `priority`, `category`, `requires_plugin`. |

#### Description

Runs before `wp_insert_post()` is called. Use this to validate the snippet, log installations, or modify global state before the post is created.

#### Example

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

### `leastudios_snippets_after_library_install`

Fires after a library snippet has been successfully installed as a new post.

| Detail | Value |
|---|---|
| **Type** | Action |
| **File** | `src/Library/Snippet_Library.php` |
| **Since** | 1.0.0 |

#### Parameters

| Parameter | Type | Description |
|---|---|---|
| `$post_id` | `int` | The newly created snippet post ID. |
| `$snippet` | `array<string, mixed>` | The snippet definition that was installed. |

#### Description

At this point the post has been created and all meta fields (code, type, location, priority, active status) have been saved. The snippet is inactive by default (`META_ACTIVE = '0'`). Use this hook to set additional meta, auto-activate the snippet, assign taxonomy terms, or trigger downstream workflows.

#### Example

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

## Snippet Library -- Adding Custom Snippets Programmatically

The snippet library is a curated collection of pre-built snippet definitions that users can install with one click from the **Library** admin page. Each definition is an array describing the snippet's title, code, type, and insertion behaviour.

### Snippet Definition Schema

| Key | Type | Required | Description |
|---|---|---|---|
| `title` | `string` | Yes | Human-readable name displayed in the library. Must be unique -- it is used as the lookup key during installation. |
| `description` | `string` | Yes | A short explanation of what the snippet does, shown below the title in the library UI. |
| `code` | `string` | Yes | The snippet source code. For PHP snippets, omit the opening `<?php` tag. |
| `type` | `string` | Yes | The language type: `php`, `js`, `css`, or `html`. |
| `location` | `string` | Yes | The auto-insert location slug. Must match a key from `Snippet_Post_Type::LOCATIONS` (or a custom location registered via the `leastudios_snippets_locations` filter). |
| `priority` | `int` | Yes | Execution priority (lower numbers run earlier). Default: `10`. |
| `category` | `string` | Yes | A grouping key used for organising snippets in the library UI (e.g. `general`, `security`, `performance`). |
| `requires_plugin` | `string\|null` | Yes | Plugin slug that must be active for this snippet to appear in the available list (checked via `Suite_Detector::is_active()`). Set to `null` if the snippet has no dependency. |

### Adding a Single Snippet

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

### Adding Multiple Snippets From a Plugin

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

### Adding Snippets That Require a Specific Plugin

Set the `requires_plugin` key to the plugin's slug. The snippet will only appear in the library when that plugin is active:

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

### Removing a Built-in Library Snippet

```php
add_filter( 'leastudios_snippets_library_snippets', function ( array $snippets ): array {
    return array_values( array_filter( $snippets, function ( array $snippet ): bool {
        return 'Disable XML-RPC' !== $snippet['title'];
    } ) );
} );
```

### Installing a Library Snippet Programmatically

The `Snippet_Library::install_snippet()` method creates a new snippet post from a library definition. The snippet is created as **inactive** by default:

```php
use LEAStudios\Snippets\Library\Snippet_Library;

$library = new Snippet_Library();

try {
    $post_id = $library->install_snippet( 'Disable XML-RPC' );
    // Optionally activate it.
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

## Hook Execution Order

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
| -- | `leastudios_snippets_post_type_args` | Filter | During post type registration |
| -- | `leastudios_snippets_locations` | Filter | When locations list is requested |
| -- | `leastudios_snippets_library_snippets` | Filter | When library definitions are loaded |
| -- | `leastudios_snippets_initialized` | Action | After full plugin init |
| -- | `leastudios_snippets_before_library_install` | Action | Before a library snippet is saved |
| -- | `leastudios_snippets_after_library_install` | Action | After a library snippet is saved |
