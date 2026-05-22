# Safe-Mode Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `leastudios-snippets`' safe mode actually contain fatal errors (including in deferred hook callbacks and uncatchable fatals), stop deactivating snippets for harmless warnings, surface silent failures, gate editing on `DISALLOW_FILE_MODS`, and add real test coverage.

**Architecture:** Extract a dedicated `Execution/Safe_Mode` class that owns all error-containment state. It adds a `shutdown` handler plus an "executing snippet" marker: if the request dies (a true fatal) while a snippet is mid-execution, that snippet is auto-deactivated. The per-`eval()` error handler is split so warnings are recorded rather than deactivating the snippet. A new `Snippet_Post_Type::is_editing_disabled()` predicate gates write capabilities and library install.

**Tech Stack:** PHP 8.1+, WordPress 6.4+, PHPUnit 9.6 against the WordPress test library, PHPCS (WordPress Coding Standards), PHPStan level 6.

**Spec:** `docs/superpowers/specs/2026-05-21-safe-mode-hardening-design.md`

**Conventions:** All commands run from the plugin directory (`wp-content/plugins/leastudios-snippets`). Commits follow the repo style — a capitalized sentence summary, no `feat:`/`fix:` prefix — and include the standard `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>` trailer. New filters/methods use `@since 1.1.0`.

---

## Task 1: Install the WordPress test library

The PHPUnit suite needs the WordPress test library, which is not yet installed. No code change, no commit — this is environment setup.

**Files:** none.

- [ ] **Step 1: Install the test library**

Run (from the plugin directory):

```bash
bash ../leastudios-dev-tools/bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

Expected: the script downloads WordPress + the test suite into `/tmp/wordpress-tests-lib` and `/tmp/wordpress/` and creates a `wordpress_test` database. If it fails with a MySQL access error, the local DB password differs from empty — re-run with the correct password as the third argument.

- [ ] **Step 2: Verify the existing suite runs**

Run:

```bash
composer test
```

Expected: PASS — `ConditionCheckerTest` runs green (13 tests). This confirms the library is wired up before any new tests are written.

---

## Task 2: Create the `Safe_Mode` class — marker and culprit decision

Create the new class with its execution marker, the safe-mode list readers, and the pure `decide_culprit()` decision. Deactivation/warning methods come in Task 3.

**Files:**
- Create: `src/Execution/Safe_Mode.php`
- Test: `tests/SafeModeTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/SafeModeTest.php`:

```php
<?php
/**
 * Tests for Safe_Mode.
 *
 * @package LEAStudios\Snippets\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Tests;

use LEAStudios\Snippets\Execution\Safe_Mode;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Snippets\Execution\Safe_Mode
 */
class SafeModeTest extends TestCase {

	public function test_begin_returns_previous_marker(): void {
		$safe_mode = new Safe_Mode();
		$this->assertNull( $safe_mode->begin( 5 ) );
		$this->assertSame( 5, $safe_mode->begin( 7 ) );
	}

	public function test_end_restores_previous_marker(): void {
		$safe_mode = new Safe_Mode();
		$first     = $safe_mode->begin( 5 );
		$second    = $safe_mode->begin( 7 );
		$safe_mode->end( $second );
		$this->assertSame( 5, $safe_mode->begin( 99 ) );
		$safe_mode->end( $first );
	}

	public function test_decide_culprit_returns_id_for_fatal_with_marker(): void {
		$error = [
			'type'    => E_ERROR,
			'message' => 'boom',
			'file'    => 'x.php',
			'line'    => 1,
		];
		$this->assertSame( 42, ( new Safe_Mode() )->decide_culprit( $error, 42 ) );
	}

	public function test_decide_culprit_returns_null_without_marker(): void {
		$error = [
			'type'    => E_ERROR,
			'message' => 'boom',
			'file'    => 'x.php',
			'line'    => 1,
		];
		$this->assertNull( ( new Safe_Mode() )->decide_culprit( $error, null ) );
	}

	public function test_decide_culprit_returns_null_for_nonfatal_error(): void {
		$error = [
			'type'    => E_NOTICE,
			'message' => 'meh',
			'file'    => 'x.php',
			'line'    => 1,
		];
		$this->assertNull( ( new Safe_Mode() )->decide_culprit( $error, 42 ) );
	}

	public function test_decide_culprit_returns_null_when_no_error(): void {
		// The clean-exit / redirect case: marker is set, but no fatal occurred.
		$this->assertNull( ( new Safe_Mode() )->decide_culprit( null, 42 ) );
	}

	public function test_is_disabled_reads_safe_mode_option(): void {
		update_option( 'leastudios_snippets_safe_mode', [ 10, 20 ] );
		$safe_mode = new Safe_Mode();
		$this->assertTrue( $safe_mode->is_disabled( 10 ) );
		$this->assertFalse( $safe_mode->is_disabled( 30 ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter SafeModeTest`
Expected: FAIL — `Error: Class "LEAStudios\Snippets\Execution\Safe_Mode" not found`.

- [ ] **Step 3: Write the minimal implementation**

Create `src/Execution/Safe_Mode.php`:

```php
<?php
/**
 * Snippet error containment and safe-mode state.
 *
 * @package LEAStudios\Snippets\Execution
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Execution;

defined( 'ABSPATH' ) || exit;

use LEAStudios\Snippets\CPT\Snippet_Post_Type;

/**
 * Owns safe-mode state and fatal-error containment for snippet execution.
 */
class Safe_Mode {

	/**
	 * Option holding the list of auto-deactivated snippet IDs.
	 *
	 * @var string
	 */
	public const OPTION_SAFE_MODE = 'leastudios_snippets_safe_mode';

	/**
	 * Option holding the list of snippet IDs that emitted warnings.
	 *
	 * @var string
	 */
	public const OPTION_WARNINGS = 'leastudios_snippets_warnings';

	/**
	 * Transient key prefix for a deactivated snippet's error message.
	 *
	 * @var string
	 */
	public const ERROR_TRANSIENT_PREFIX = 'leastudios_snippets_error_';

	/**
	 * Transient key prefix for a snippet's recorded warning messages.
	 *
	 * @var string
	 */
	public const WARNINGS_TRANSIENT_PREFIX = 'leastudios_snippets_warnings_';

	/**
	 * PHP error levels treated as fatal for shutdown-time attribution.
	 *
	 * @var array<int, int>
	 */
	private const FATAL_ERROR_TYPES = [
		E_ERROR,
		E_PARSE,
		E_CORE_ERROR,
		E_COMPILE_ERROR,
		E_USER_ERROR,
		E_RECOVERABLE_ERROR,
	];

	/**
	 * ID of the snippet currently executing, or null when none is.
	 *
	 * @var int|null
	 */
	private ?int $executing_snippet_id = null;

	/**
	 * Mark a snippet as the one currently executing.
	 *
	 * @param int $snippet_id The snippet post ID.
	 * @return int|null The previously-marked snippet ID, for restoration via end().
	 */
	public function begin( int $snippet_id ): ?int {
		$previous                   = $this->executing_snippet_id;
		$this->executing_snippet_id = $snippet_id;

		return $previous;
	}

	/**
	 * Restore the execution marker to a previously-saved value.
	 *
	 * @param int|null $previous The value returned by the matching begin() call.
	 * @return void
	 */
	public function end( ?int $previous ): void {
		$this->executing_snippet_id = $previous;
	}

	/**
	 * Get the list of auto-deactivated snippet IDs.
	 *
	 * @return array<int, int>
	 */
	public function get_disabled_ids(): array {
		$ids = get_option( self::OPTION_SAFE_MODE, [] );

		if ( ! is_array( $ids ) ) {
			return [];
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * Check whether a snippet is in safe mode.
	 *
	 * @param int $snippet_id The snippet post ID.
	 * @return bool
	 */
	public function is_disabled( int $snippet_id ): bool {
		return in_array( $snippet_id, $this->get_disabled_ids(), true );
	}

	/**
	 * Decide which snippet, if any, caused the request to die.
	 *
	 * Returns the executing snippet ID only when the request terminated with a
	 * fatal-class error while that snippet was mid-execution. A snippet that
	 * legitimately ends the request (e.g. wp_safe_redirect(); exit;) produces no
	 * fatal error and is therefore not blamed.
	 *
	 * @param array{type?: int, message?: string, file?: string, line?: int}|null $last_error The result of error_get_last().
	 * @param int|null                                                            $executing_id The currently-executing snippet ID.
	 * @return int|null The snippet ID to deactivate, or null.
	 */
	public function decide_culprit( ?array $last_error, ?int $executing_id ): ?int {
		if ( null === $executing_id ) {
			return null;
		}

		if ( null === $last_error || ! isset( $last_error['type'] ) ) {
			return null;
		}

		if ( ! in_array( (int) $last_error['type'], self::FATAL_ERROR_TYPES, true ) ) {
			return null;
		}

		return $executing_id;
	}
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter SafeModeTest`
Expected: PASS — 6 tests.

- [ ] **Step 5: Lint**

Run: `composer lint`
Expected: PHPCS and PHPStan both clean.

- [ ] **Step 6: Commit**

```bash
git add src/Execution/Safe_Mode.php tests/SafeModeTest.php
git commit -m "Add Safe_Mode class with execution marker and culprit decision"
```

---

## Task 3: Extend `Safe_Mode` — deactivation, warnings, shutdown handler

Add the state-mutating methods and the shutdown wiring to the class created in Task 2.

**Files:**
- Modify: `src/Execution/Safe_Mode.php`
- Test: `tests/SafeModeTest.php`

- [ ] **Step 1: Add the failing tests**

Append these methods to the `SafeModeTest` class in `tests/SafeModeTest.php` (before the closing brace):

```php
	public function test_deactivate_disables_snippet_and_records_error(): void {
		$id = self::factory()->post->create(
			[ 'post_type' => \LEAStudios\Snippets\CPT\Snippet_Post_Type::POST_TYPE ]
		);
		update_post_meta( $id, \LEAStudios\Snippets\CPT\Snippet_Post_Type::META_ACTIVE, '1' );

		$safe_mode = new Safe_Mode();
		$safe_mode->deactivate( $id, 'fatal: boom' );

		$this->assertSame(
			'0',
			get_post_meta( $id, \LEAStudios\Snippets\CPT\Snippet_Post_Type::META_ACTIVE, true )
		);
		$this->assertTrue( $safe_mode->is_disabled( $id ) );
		$this->assertSame( 'fatal: boom', get_transient( 'leastudios_snippets_error_' . $id ) );
	}

	public function test_deactivate_fires_php_error_action(): void {
		$id       = self::factory()->post->create(
			[ 'post_type' => \LEAStudios\Snippets\CPT\Snippet_Post_Type::POST_TYPE ]
		);
		$captured = [];
		add_action(
			'leastudios_snippets_php_error',
			function ( $sid, $msg ) use ( &$captured ): void {
				$captured = [ $sid, $msg ];
			},
			10,
			2
		);

		( new Safe_Mode() )->deactivate( $id, 'oops' );

		$this->assertSame( [ $id, 'oops' ], $captured );
	}

	public function test_record_warnings_keeps_snippet_active(): void {
		$id = self::factory()->post->create(
			[ 'post_type' => \LEAStudios\Snippets\CPT\Snippet_Post_Type::POST_TYPE ]
		);
		update_post_meta( $id, \LEAStudios\Snippets\CPT\Snippet_Post_Type::META_ACTIVE, '1' );

		$safe_mode = new Safe_Mode();
		$safe_mode->record_warnings( $id, [ 'Deprecated: foo' ] );

		$this->assertSame(
			'1',
			get_post_meta( $id, \LEAStudios\Snippets\CPT\Snippet_Post_Type::META_ACTIVE, true )
		);
		$this->assertFalse( $safe_mode->is_disabled( $id ) );
		$this->assertSame( [ 'Deprecated: foo' ], get_transient( 'leastudios_snippets_warnings_' . $id ) );
		$this->assertContains( $id, get_option( 'leastudios_snippets_warnings', [] ) );
	}

	public function test_record_warnings_empty_is_noop(): void {
		$id = self::factory()->post->create(
			[ 'post_type' => \LEAStudios\Snippets\CPT\Snippet_Post_Type::POST_TYPE ]
		);
		( new Safe_Mode() )->record_warnings( $id, [] );
		$this->assertFalse( get_transient( 'leastudios_snippets_warnings_' . $id ) );
	}

	public function test_clear_warnings_removes_transient_and_index(): void {
		$id        = self::factory()->post->create(
			[ 'post_type' => \LEAStudios\Snippets\CPT\Snippet_Post_Type::POST_TYPE ]
		);
		$safe_mode = new Safe_Mode();
		$safe_mode->record_warnings( $id, [ 'w' ] );
		$safe_mode->clear_warnings( $id );

		$this->assertFalse( get_transient( 'leastudios_snippets_warnings_' . $id ) );
		$this->assertNotContains( $id, get_option( 'leastudios_snippets_warnings', [] ) );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter SafeModeTest`
Expected: FAIL — `Error: Call to undefined method ...::deactivate()`.

- [ ] **Step 3: Add the implementation**

Add these methods to `src/Execution/Safe_Mode.php`, immediately after `decide_culprit()` (before the closing class brace):

```php
	/**
	 * Register the shutdown handler and warning-cleanup hook.
	 *
	 * Uses register_shutdown_function() directly so the handler runs even after
	 * an uncatchable fatal, regardless of WordPress hook state.
	 *
	 * @return void
	 */
	public function init(): void {
		register_shutdown_function( [ $this, 'on_shutdown' ] );
		add_action( 'save_post_' . Snippet_Post_Type::POST_TYPE, [ $this, 'clear_warnings' ] );
	}

	/**
	 * Deactivate a snippet and record the error that caused it.
	 *
	 * @param int    $snippet_id The snippet post ID.
	 * @param string $message    The error message.
	 * @return void
	 */
	public function deactivate( int $snippet_id, string $message ): void {
		update_post_meta( $snippet_id, Snippet_Post_Type::META_ACTIVE, '0' );

		$disabled = $this->get_disabled_ids();

		if ( ! in_array( $snippet_id, $disabled, true ) ) {
			$disabled[] = $snippet_id;
			update_option( self::OPTION_SAFE_MODE, $disabled );
		}

		set_transient( self::ERROR_TRANSIENT_PREFIX . $snippet_id, $message, DAY_IN_SECONDS );

		// update_post_meta() does not fire save_post, so the active-IDs cache
		// would otherwise keep listing this snippet as active.
		Snippet_Executor::invalidate_active_cache();

		/**
		 * Fires when a PHP snippet encounters an error.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $snippet_id    The snippet post ID.
		 * @param string $error_message The error message.
		 */
		do_action( 'leastudios_snippets_php_error', $snippet_id, $message );
	}

	/**
	 * Record non-fatal warnings for a snippet without deactivating it.
	 *
	 * @param int                   $snippet_id The snippet post ID.
	 * @param array<int, string>    $messages   The warning messages.
	 * @return void
	 */
	public function record_warnings( int $snippet_id, array $messages ): void {
		if ( empty( $messages ) ) {
			return;
		}

		set_transient(
			self::WARNINGS_TRANSIENT_PREFIX . $snippet_id,
			array_values( $messages ),
			DAY_IN_SECONDS
		);

		$index = get_option( self::OPTION_WARNINGS, [] );

		if ( ! is_array( $index ) ) {
			$index = [];
		}

		if ( ! in_array( $snippet_id, $index, true ) ) {
			$index[] = $snippet_id;
			update_option( self::OPTION_WARNINGS, $index );
		}
	}

	/**
	 * Clear any recorded warnings for a snippet.
	 *
	 * @param int $snippet_id The snippet post ID.
	 * @return void
	 */
	public function clear_warnings( int $snippet_id ): void {
		delete_transient( self::WARNINGS_TRANSIENT_PREFIX . $snippet_id );

		$index = get_option( self::OPTION_WARNINGS, [] );

		if ( is_array( $index ) && in_array( $snippet_id, $index, true ) ) {
			update_option(
				self::OPTION_WARNINGS,
				array_values( array_diff( $index, [ $snippet_id ] ) )
			);
		}
	}

	/**
	 * Shutdown handler: deactivate a snippet that crashed the request.
	 *
	 * @return void
	 */
	public function on_shutdown(): void {
		$last_error = error_get_last();
		$culprit    = $this->decide_culprit( $last_error, $this->executing_snippet_id );

		if ( null === $culprit ) {
			return;
		}

		$message = ( null !== $last_error && isset( $last_error['message'] ) )
			? sprintf(
				'%s in %s on line %d',
				(string) $last_error['message'],
				(string) ( $last_error['file'] ?? '' ),
				(int) ( $last_error['line'] ?? 0 )
			)
			: __( 'The snippet terminated the request with a fatal error.', 'leastudios-snippets' );

		$this->deactivate( $culprit, $message );
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter SafeModeTest`
Expected: PASS — 11 tests.

- [ ] **Step 5: Lint**

Run: `composer lint`
Expected: PHPCS and PHPStan both clean.

- [ ] **Step 6: Commit**

```bash
git add src/Execution/Safe_Mode.php tests/SafeModeTest.php
git commit -m "Add Safe_Mode deactivation, warning recording and shutdown handler"
```

---

## Task 4: Route `Snippet_Executor` error handling through `Safe_Mode`

Inject `Safe_Mode` into the executor, replace the inline safe-mode logic, split the `eval()` error handler so warnings no longer deactivate, and wrap dispatch in the execution marker. `Plugin.php` must change in the same commit because the executor constructor signature changes.

**Files:**
- Modify: `src/Execution/Snippet_Executor.php`
- Modify: `src/Plugin.php`
- Test: `tests/SnippetExecutorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/SnippetExecutorTest.php`:

```php
<?php
/**
 * Tests for Snippet_Executor.
 *
 * @package LEAStudios\Snippets\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Tests;

use LEAStudios\Snippets\CPT\Snippet_Post_Type;
use LEAStudios\Snippets\Execution\Condition_Checker;
use LEAStudios\Snippets\Execution\Safe_Mode;
use LEAStudios\Snippets\Execution\Snippet_Executor;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Snippets\Execution\Snippet_Executor
 */
class SnippetExecutorTest extends TestCase {

	private function make_executor(): Snippet_Executor {
		return new Snippet_Executor( new Condition_Checker(), new Safe_Mode() );
	}

	public function test_execute_php_runs_code(): void {
		$id = self::factory()->post->create( [ 'post_type' => Snippet_Post_Type::POST_TYPE ] );
		$this->make_executor()->execute_php(
			$id,
			'update_option( "leastudios_snippets_test_marker", "ran" );'
		);
		$this->assertSame( 'ran', get_option( 'leastudios_snippets_test_marker' ) );
	}

	public function test_execute_php_with_exception_deactivates_snippet(): void {
		$id = self::factory()->post->create( [ 'post_type' => Snippet_Post_Type::POST_TYPE ] );
		update_post_meta( $id, Snippet_Post_Type::META_ACTIVE, '1' );

		$this->make_executor()->execute_php( $id, 'throw new \RuntimeException( "boom" );' );

		$this->assertSame( '0', get_post_meta( $id, Snippet_Post_Type::META_ACTIVE, true ) );
	}

	public function test_execute_php_with_notice_keeps_snippet_active(): void {
		$id = self::factory()->post->create( [ 'post_type' => Snippet_Post_Type::POST_TYPE ] );
		update_post_meta( $id, Snippet_Post_Type::META_ACTIVE, '1' );

		$this->make_executor()->execute_php( $id, 'trigger_error( "just a notice", E_USER_NOTICE );' );

		$this->assertSame( '1', get_post_meta( $id, Snippet_Post_Type::META_ACTIVE, true ) );
		$this->assertIsArray( get_transient( 'leastudios_snippets_warnings_' . $id ) );
	}

	public function test_execute_output_wraps_js(): void {
		ob_start();
		$this->make_executor()->execute_output( 1, 'alert(1)', 'js' );
		$this->assertSame( '<script>alert(1)</script>', ob_get_clean() );
	}

	public function test_execute_output_applies_filter(): void {
		add_filter(
			'leastudios_snippets_output',
			function (): string {
				return 'FILTERED';
			}
		);
		ob_start();
		$this->make_executor()->execute_output( 1, 'body{}', 'css' );
		$this->assertSame( 'FILTERED', ob_get_clean() );
	}
}
```

> Note: `E_USER_ERROR` deactivation is intentionally not exercised by a test — triggering it through `eval()` risks terminating the PHPUnit process. The `throw` test covers the deactivation path; the errno classification is a two-element `in_array` check.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter SnippetExecutorTest`
Expected: FAIL — `ArgumentCountError` or a constructor mismatch (the executor does not yet accept `Safe_Mode`).

- [ ] **Step 3: Modify `Snippet_Executor`**

In `src/Execution/Snippet_Executor.php`:

(a) Add the import after the existing `use` lines:

```php
use WP_Post;
use WP_Query;
```

becomes

```php
use WP_Post;
use WP_Query;
```

(no change needed there — `Safe_Mode` is in the same namespace, no `use` required).

(b) Replace the `$condition_checker` property block and constructor with:

```php
	/**
	 * Condition checker instance.
	 *
	 * @var Condition_Checker
	 */
	private Condition_Checker $condition_checker;

	/**
	 * Safe-mode / error-containment instance.
	 *
	 * @var Safe_Mode
	 */
	private Safe_Mode $safe_mode;

	/**
	 * Constructor.
	 *
	 * @param Condition_Checker $condition_checker The condition checker instance.
	 * @param Safe_Mode         $safe_mode         The safe-mode instance.
	 */
	public function __construct( Condition_Checker $condition_checker, Safe_Mode $safe_mode ) {
		$this->condition_checker = $condition_checker;
		$this->safe_mode         = $safe_mode;
	}
```

(c) In `init()`, delete the line `$safe_mode_ids = $this->get_safe_mode_ids();` and replace the in-loop check

```php
			if ( in_array( $snippet_id, $safe_mode_ids, true ) ) {
				continue;
			}
```

with

```php
			if ( $this->safe_mode->is_disabled( $snippet_id ) ) {
				continue;
			}
```

(d) Replace the body of `maybe_execute_snippet()` from the dispatch block onward. Find:

```php
		if ( 'php' === $type ) {
			$this->execute_php( $snippet->ID, $code );
		} else {
			$this->execute_output( $snippet->ID, $code, $type );
		}
```

and replace with:

```php
		$previous_marker = $this->safe_mode->begin( $snippet->ID );

		try {
			if ( 'php' === $type ) {
				$this->execute_php( $snippet->ID, $code );
			} else {
				$this->execute_output( $snippet->ID, $code, $type );
			}
		} finally {
			$this->safe_mode->end( $previous_marker );
		}
```

(e) Replace the entire `execute_php()` method body with:

```php
	/**
	 * Execute a PHP snippet via eval() with error handling.
	 *
	 * Errors thrown during execution deactivate the snippet; non-fatal
	 * warnings are recorded but leave the snippet active.
	 *
	 * @param int    $snippet_id The snippet post ID.
	 * @param string $code       The PHP code to execute.
	 * @return void
	 */
	public function execute_php( int $snippet_id, string $code ): void {
		$fatal_message = '';
		$warnings      = [];

		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			function ( int $errno, string $errstr, string $errfile, int $errline ) use ( &$fatal_message, &$warnings ): bool {
				$formatted = sprintf( '%s in %s on line %d', $errstr, $errfile, $errline );

				if ( in_array( $errno, [ E_USER_ERROR, E_RECOVERABLE_ERROR ], true ) ) {
					$fatal_message = $formatted;
				} else {
					$warnings[] = $formatted;
				}

				// Return true to prevent the default PHP error handler from running.
				return true;
			}
		);

		$thrown = null;

		try {
			eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		} catch ( \Throwable $e ) {
			$thrown = $e;
		}

		restore_error_handler();

		if ( null !== $thrown ) {
			$this->safe_mode->deactivate( $snippet_id, $thrown->getMessage() );
			return;
		}

		if ( '' !== $fatal_message ) {
			$this->safe_mode->deactivate( $snippet_id, $fatal_message );
			return;
		}

		if ( ! empty( $warnings ) ) {
			$this->safe_mode->record_warnings( $snippet_id, $warnings );
		}
	}
```

(f) In `handle_shortcode()`, replace

```php
		if ( in_array( $snippet_id, $this->get_safe_mode_ids(), true ) ) {
			return '';
		}
```

with

```php
		if ( $this->safe_mode->is_disabled( $snippet_id ) ) {
			return '';
		}
```

(g) Delete the now-unused `handle_php_error()` and `get_safe_mode_ids()` methods entirely.

- [ ] **Step 4: Modify `Plugin.php`**

In `src/Plugin.php`, add the import alongside the other `Execution` imports:

```php
use LEAStudios\Snippets\Execution\Condition_Checker;
use LEAStudios\Snippets\Execution\Safe_Mode;
use LEAStudios\Snippets\Execution\Snippet_Executor;
```

Then replace:

```php
		// Execute active snippets.
		$condition_checker = new Condition_Checker();
		$executor          = new Snippet_Executor( $condition_checker );
		$executor->init();
```

with:

```php
		// Execute active snippets.
		$condition_checker = new Condition_Checker();
		$safe_mode         = new Safe_Mode();
		$safe_mode->init();
		$executor          = new Snippet_Executor( $condition_checker, $safe_mode );
		$executor->init();
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter SnippetExecutorTest`
Expected: PASS — 5 tests.

Then run the full suite to confirm no regression:

Run: `composer test`
Expected: PASS — `ConditionCheckerTest`, `SafeModeTest`, `SnippetExecutorTest` all green.

- [ ] **Step 6: Lint**

Run: `composer lint`
Expected: PHPCS and PHPStan both clean.

- [ ] **Step 7: Commit**

```bash
git add src/Execution/Snippet_Executor.php src/Plugin.php tests/SnippetExecutorTest.php
git commit -m "Route Snippet_Executor error handling through Safe_Mode"
```

---

## Task 5: Gate snippet capabilities on `DISALLOW_FILE_MODS`

Add `Snippet_Post_Type::is_editing_disabled()` and split capability mapping so write capabilities are denied when the site disables file modifications.

**Files:**
- Modify: `src/CPT/Snippet_Post_Type.php`
- Test: `tests/SnippetPostTypeTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/SnippetPostTypeTest.php`:

```php
<?php
/**
 * Tests for Snippet_Post_Type capability mapping.
 *
 * @package LEAStudios\Snippets\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Tests;

use LEAStudios\Snippets\CPT\Snippet_Post_Type;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Snippets\CPT\Snippet_Post_Type
 */
class SnippetPostTypeTest extends TestCase {

	public function test_write_cap_maps_to_manage_options(): void {
		$this->assertSame(
			[ 'manage_options' ],
			Snippet_Post_Type::map_capabilities( [ 'x' ], 'edit_leastudios_snippets' )
		);
	}

	public function test_read_cap_maps_to_manage_options(): void {
		$this->assertSame(
			[ 'manage_options' ],
			Snippet_Post_Type::map_capabilities( [ 'x' ], 'read_private_leastudios_snippets' )
		);
	}

	public function test_unknown_cap_is_unchanged(): void {
		$this->assertSame(
			[ 'x' ],
			Snippet_Post_Type::map_capabilities( [ 'x' ], 'edit_posts' )
		);
	}

	public function test_write_cap_denied_when_editing_disabled(): void {
		add_filter( 'leastudios_snippets_editing_disabled', '__return_true' );
		$this->assertSame(
			[ 'do_not_allow' ],
			Snippet_Post_Type::map_capabilities( [ 'x' ], 'edit_leastudios_snippets' )
		);
	}

	public function test_read_cap_allowed_when_editing_disabled(): void {
		add_filter( 'leastudios_snippets_editing_disabled', '__return_true' );
		$this->assertSame(
			[ 'manage_options' ],
			Snippet_Post_Type::map_capabilities( [ 'x' ], 'read_private_leastudios_snippets' )
		);
	}

	public function test_is_editing_disabled_defaults_false(): void {
		$this->assertFalse( Snippet_Post_Type::is_editing_disabled() );
	}

	public function test_is_editing_disabled_filterable(): void {
		add_filter( 'leastudios_snippets_editing_disabled', '__return_true' );
		$this->assertTrue( Snippet_Post_Type::is_editing_disabled() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter SnippetPostTypeTest`
Expected: FAIL — `test_write_cap_denied_when_editing_disabled` fails (still returns `manage_options`) and `test_is_editing_disabled_*` fail with undefined method.

- [ ] **Step 3: Modify `Snippet_Post_Type`**

In `src/CPT/Snippet_Post_Type.php`, replace the `SNIPPET_CAPABILITIES` constant with two split constants:

```php
	/**
	 * Read-only snippet capabilities. Always mapped to `manage_options`.
	 *
	 * @var array<int, string>
	 */
	private const READ_CAPABILITIES = [
		'read_leastudios_snippet',
		'read_private_leastudios_snippets',
	];

	/**
	 * Write snippet capabilities. Mapped to `manage_options`, or to
	 * `do_not_allow` when {@see is_editing_disabled()} is true — because
	 * creating or editing a snippet means executing arbitrary code, which
	 * a site running DISALLOW_FILE_MODS has explicitly opted out of.
	 *
	 * @var array<int, string>
	 */
	private const WRITE_CAPABILITIES = [
		'edit_leastudios_snippet',
		'delete_leastudios_snippet',
		'edit_leastudios_snippets',
		'edit_others_leastudios_snippets',
		'edit_private_leastudios_snippets',
		'edit_published_leastudios_snippets',
		'publish_leastudios_snippets',
		'delete_leastudios_snippets',
		'delete_private_leastudios_snippets',
		'delete_published_leastudios_snippets',
		'delete_others_leastudios_snippets',
		'create_leastudios_snippets',
	];
```

Replace the `map_capabilities()` method with:

```php
	/**
	 * Gate snippet capabilities.
	 *
	 * Read capabilities always map to `manage_options`. Write capabilities map
	 * to `manage_options` normally, or to `do_not_allow` when snippet editing
	 * is disabled site-wide.
	 *
	 * @param array<int, string> $caps The required primitive capabilities.
	 * @param string             $cap  The capability being checked.
	 * @return array<int, string>
	 */
	public static function map_capabilities( array $caps, string $cap ): array {
		if ( in_array( $cap, self::READ_CAPABILITIES, true ) ) {
			return [ 'manage_options' ];
		}

		if ( in_array( $cap, self::WRITE_CAPABILITIES, true ) ) {
			return self::is_editing_disabled() ? [ 'do_not_allow' ] : [ 'manage_options' ];
		}

		return $caps;
	}

	/**
	 * Whether snippet creation and editing are disabled site-wide.
	 *
	 * True when the site defines `DISALLOW_FILE_MODS` or `DISALLOW_FILE_EDIT`
	 * as truthy — snippets are an equivalent code-execution surface to the
	 * theme/plugin file editors those constants disable.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public static function is_editing_disabled(): bool {
		$disabled = ( defined( 'DISALLOW_FILE_MODS' ) && constant( 'DISALLOW_FILE_MODS' ) )
			|| ( defined( 'DISALLOW_FILE_EDIT' ) && constant( 'DISALLOW_FILE_EDIT' ) );

		/**
		 * Filters whether snippet creation and editing are disabled.
		 *
		 * @since 1.1.0
		 *
		 * @param bool $disabled Whether snippet editing is disabled.
		 */
		return (bool) apply_filters( 'leastudios_snippets_editing_disabled', $disabled );
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter SnippetPostTypeTest`
Expected: PASS — 7 tests.

- [ ] **Step 5: Lint**

Run: `composer lint`
Expected: PHPCS and PHPStan both clean.

- [ ] **Step 6: Commit**

```bash
git add src/CPT/Snippet_Post_Type.php tests/SnippetPostTypeTest.php
git commit -m "Gate snippet capabilities on DISALLOW_FILE_MODS"
```

---

## Task 6: Block library install when editing is disabled

Make `Library_Page` refuse to install snippets and hide install controls when editing is disabled.

**Files:**
- Modify: `src/Admin/Library_Page.php`
- Test: `tests/LibraryPageTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/LibraryPageTest.php`:

```php
<?php
/**
 * Tests for Library_Page install gating.
 *
 * @package LEAStudios\Snippets\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Tests;

use LEAStudios\Snippets\Admin\Library_Page;
use LEAStudios\Snippets\Library\Snippet_Library;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Snippets\Admin\Library_Page
 */
class LibraryPageTest extends TestCase {

	public function test_handle_install_blocked_when_editing_disabled(): void {
		add_filter( 'leastudios_snippets_editing_disabled', '__return_true' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_POST['_leastudios_snippets_library_nonce'] = wp_create_nonce( 'leastudios_snippets_install_library' );
		$_POST['snippet_slug']                       = 'disable-xmlrpc';

		$this->expectException( \WPDieException::class );

		( new Library_Page( new Snippet_Library() ) )->handle_install();
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter LibraryPageTest`
Expected: FAIL — no `WPDieException` is thrown; the install proceeds and the test reports the expected exception was not raised.

- [ ] **Step 3: Modify `Library_Page`**

In `src/Admin/Library_Page.php`, add the import alongside the existing ones:

```php
use LEAStudios\Snippets\CPT\Snippet_Post_Type;
use LEAStudios\Snippets\Library\Snippet_Library;
use LEAStudios\Snippets\Suite\Suite_Detector;
```

In `handle_install()`, immediately after the nonce check block (after the `wp_die( … 'Security check failed.' … )` `if`), add:

```php
		if ( Snippet_Post_Type::is_editing_disabled() ) {
			wp_die(
				esc_html__(
					'Snippet editing is disabled on this site because DISALLOW_FILE_MODS is enabled.',
					'leastudios-snippets'
				)
			);
		}
```

In `render_page()`, immediately after the opening `<div class="wrap …">` and the `<h1>`, add a banner. Replace:

```php
			<h1><?php esc_html_e( 'Snippet Library', 'leastudios-snippets' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Browse and install pre-built code snippets. Installed snippets are inactive by default — review and activate them from the editor.', 'leastudios-snippets' ); ?>
			</p>
```

with:

```php
			<h1><?php esc_html_e( 'Snippet Library', 'leastudios-snippets' ); ?></h1>
			<?php $editing_disabled = Snippet_Post_Type::is_editing_disabled(); ?>
			<?php if ( $editing_disabled ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'Installing snippets is disabled because this site has DISALLOW_FILE_MODS enabled.', 'leastudios-snippets' ); ?></p>
				</div>
			<?php endif; ?>
			<p class="description">
				<?php esc_html_e( 'Browse and install pre-built code snippets. Installed snippets are inactive by default — review and activate them from the editor.', 'leastudios-snippets' ); ?>
			</p>
```

Then, in the card footer, gate the install form. Replace:

```php
								<?php if ( $is_available ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
```

with:

```php
								<?php if ( $is_available && ! $editing_disabled ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter LibraryPageTest`
Expected: PASS — 1 test.

- [ ] **Step 5: Lint**

Run: `composer lint`
Expected: PHPCS and PHPStan both clean.

- [ ] **Step 6: Manual verification**

Define `define( 'DISALLOW_FILE_MODS', true );` in `wp-config.php`, load **Snippets → Library** in the admin: the info banner shows and every card's Install button is gone. Submitting an install (e.g. via a stale form) shows the "disabled" `wp_die` screen. Remove the constant afterward.

- [ ] **Step 7: Commit**

```bash
git add src/Admin/Library_Page.php tests/LibraryPageTest.php
git commit -m "Block library install when snippet editing is disabled"
```

---

## Task 7: Surface oversized-code rejection and the editing-disabled state

Stop silently dropping oversized snippet code; show an admin notice. Also add the explanatory notice for the editing-disabled state.

**Files:**
- Modify: `src/Admin/Snippet_Editor.php`
- Test: `tests/SnippetEditorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/SnippetEditorTest.php`:

```php
<?php
/**
 * Tests for Snippet_Editor save handling.
 *
 * @package LEAStudios\Snippets\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Tests;

use LEAStudios\Snippets\Admin\Snippet_Editor;
use LEAStudios\Snippets\CPT\Snippet_Post_Type;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Snippets\Admin\Snippet_Editor
 */
class SnippetEditorTest extends TestCase {

	public function test_save_rejects_oversized_code(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post_id = self::factory()->post->create( [ 'post_type' => Snippet_Post_Type::POST_TYPE ] );
		update_post_meta( $post_id, Snippet_Post_Type::META_CODE, 'original' );

		// These literals mirror Snippet_Editor's private NONCE_FIELD / NONCE_ACTION.
		$_POST['_leastudios_snippets_nonce'] = wp_create_nonce( 'leastudios_snippets_save_snippet' );
		$_POST['leastudios_snippets_code']   = str_repeat( 'a', 262145 );

		( new Snippet_Editor() )->save( $post_id, get_post( $post_id ) );

		$this->assertSame( 'original', get_post_meta( $post_id, Snippet_Post_Type::META_CODE, true ) );
		$this->assertNotFalse( get_transient( 'leastudios_snippets_oversize_' . $post_id ) );

		unset( $_POST['_leastudios_snippets_nonce'], $_POST['leastudios_snippets_code'] );
	}

	public function test_save_accepts_normal_code(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post_id = self::factory()->post->create( [ 'post_type' => Snippet_Post_Type::POST_TYPE ] );

		$_POST['_leastudios_snippets_nonce'] = wp_create_nonce( 'leastudios_snippets_save_snippet' );
		$_POST['leastudios_snippets_code']   = 'echo "hello";';

		( new Snippet_Editor() )->save( $post_id, get_post( $post_id ) );

		$this->assertSame( 'echo "hello";', get_post_meta( $post_id, Snippet_Post_Type::META_CODE, true ) );
		$this->assertFalse( get_transient( 'leastudios_snippets_oversize_' . $post_id ) );

		unset( $_POST['_leastudios_snippets_nonce'], $_POST['leastudios_snippets_code'] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter SnippetEditorTest`
Expected: FAIL — `test_save_rejects_oversized_code` fails because no `leastudios_snippets_oversize_*` transient is set (oversized code is currently dropped silently).

- [ ] **Step 3: Modify `Snippet_Editor`**

In `src/Admin/Snippet_Editor.php`:

(a) Add the imports after the existing `use LEAStudios\Snippets\CPT\Snippet_Post_Type;`:

```php
use LEAStudios\Snippets\CPT\Snippet_Post_Type;
```

(no extra import needed — `Snippet_Post_Type` is already imported; the oversize transient uses a literal key).

(b) In `init()`, add an admin-notices hook:

```php
	public function init(): void {
		add_action( 'add_meta_boxes_' . Snippet_Post_Type::POST_TYPE, [ $this, 'register_metaboxes' ] );
		add_action( 'save_post_' . Snippet_Post_Type::POST_TYPE, [ $this, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ $this, 'render_oversize_notice' ] );
		add_action( 'admin_notices', [ $this, 'render_editing_disabled_notice' ] );
	}
```

(c) In `save()`, replace the code-save block:

```php
		if ( isset( $_POST['leastudios_snippets_code'] ) ) {
			$code = wp_unslash( $_POST['leastudios_snippets_code'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_string( $code ) && strlen( $code ) <= self::MAX_CODE_BYTES ) {
				update_post_meta( $post_id, Snippet_Post_Type::META_CODE, $code );
			}
		}
```

with:

```php
		if ( isset( $_POST['leastudios_snippets_code'] ) ) {
			$code = wp_unslash( $_POST['leastudios_snippets_code'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_string( $code ) ) {
				if ( strlen( $code ) <= self::MAX_CODE_BYTES ) {
					update_post_meta( $post_id, Snippet_Post_Type::META_CODE, $code );
				} else {
					// Oversized code is not saved; flag it so the editor can
					// tell the user instead of failing silently.
					set_transient( 'leastudios_snippets_oversize_' . $post_id, true, MINUTE_IN_SECONDS );
				}
			}
		}
```

(d) Add two render methods immediately before `get_condition_types()`:

```php
	/**
	 * Show a notice when a snippet's code was rejected for exceeding the size limit.
	 *
	 * @return void
	 */
	public function render_oversize_notice(): void {
		$screen = get_current_screen();

		if ( ! $screen || Snippet_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! get_transient( 'leastudios_snippets_oversize_' . $post->ID ) ) {
			return;
		}

		delete_transient( 'leastudios_snippets_oversize_' . $post->ID );

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__(
				'leaStudios Snippets: the snippet code exceeded the 256 KB limit and was not saved.',
				'leastudios-snippets'
			)
		);
	}

	/**
	 * Show a notice on snippet screens when editing is disabled site-wide.
	 *
	 * @return void
	 */
	public function render_editing_disabled_notice(): void {
		if ( ! Snippet_Post_Type::is_editing_disabled() ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || Snippet_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html__(
				'Snippet creation and editing are disabled because this site has DISALLOW_FILE_MODS (or DISALLOW_FILE_EDIT) enabled. Existing snippets continue to run.',
				'leastudios-snippets'
			)
		);
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter SnippetEditorTest`
Expected: PASS — 2 tests.

- [ ] **Step 5: Lint**

Run: `composer lint`
Expected: PHPCS and PHPStan both clean.

- [ ] **Step 6: Manual verification**

In the admin, edit a snippet, paste >256 KB of text into the code editor, and Update — the page reloads with a red "exceeded the 256 KB limit" notice and the prior code intact. With `DISALLOW_FILE_MODS` defined, the snippet list/edit screens show the blue editing-disabled notice.

- [ ] **Step 7: Commit**

```bash
git add src/Admin/Snippet_Editor.php tests/SnippetEditorTest.php
git commit -m "Surface oversized-code rejection and editing-disabled state"
```

---

## Task 8: Show admin notices for snippets that emitted warnings

Add a warning-notice render path to `Safe_Mode_Notice` and switch its literal option/transient keys to the `Safe_Mode` constants.

**Files:**
- Modify: `src/Admin/Safe_Mode_Notice.php`
- Test: `tests/SafeModeNoticeTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/SafeModeNoticeTest.php`:

```php
<?php
/**
 * Tests for Safe_Mode_Notice warning rendering.
 *
 * @package LEAStudios\Snippets\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Tests;

use LEAStudios\Snippets\Admin\Safe_Mode_Notice;
use LEAStudios\Snippets\CPT\Snippet_Post_Type;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Snippets\Admin\Safe_Mode_Notice
 */
class SafeModeNoticeTest extends TestCase {

	public function test_render_warning_notices_lists_warnings(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post_id = self::factory()->post->create(
			[
				'post_type'  => Snippet_Post_Type::POST_TYPE,
				'post_title' => 'Noisy Snippet',
			]
		);
		update_option( 'leastudios_snippets_warnings', [ $post_id ] );
		set_transient( 'leastudios_snippets_warnings_' . $post_id, [ 'Deprecated: x' ], 60 );

		ob_start();
		( new Safe_Mode_Notice() )->render_warning_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Noisy Snippet', $output );
		$this->assertStringContainsString( 'Deprecated: x', $output );
	}

	public function test_render_warning_notices_empty_when_none(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		ob_start();
		( new Safe_Mode_Notice() )->render_warning_notices();

		$this->assertSame( '', ob_get_clean() );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter SafeModeNoticeTest`
Expected: FAIL — `Error: Call to undefined method ...::render_warning_notices()`.

- [ ] **Step 3: Modify `Safe_Mode_Notice`**

In `src/Admin/Safe_Mode_Notice.php`:

(a) Add the import after the existing `use`:

```php
use LEAStudios\Snippets\CPT\Snippet_Post_Type;
use LEAStudios\Snippets\Execution\Safe_Mode;
```

(b) Register the new notice in `init()`:

```php
	public function init(): void {
		add_action( 'admin_notices', [ $this, 'render_notices' ] );
		add_action( 'admin_notices', [ $this, 'render_warning_notices' ] );
		add_action( 'admin_init', [ $this, 'handle_reactivate' ] );
	}
```

(c) In `render_notices()`, replace the literal `'leastudios_snippets_safe_mode'` with `Safe_Mode::OPTION_SAFE_MODE`, and the literal `'leastudios_snippets_error_' . $snippet_id` with `Safe_Mode::ERROR_TRANSIENT_PREFIX . $snippet_id`.

(d) In `handle_reactivate()`, likewise replace `'leastudios_snippets_safe_mode'` with `Safe_Mode::OPTION_SAFE_MODE` (both the `get_option` and `update_option` calls) and `'leastudios_snippets_error_' . $snippet_id` with `Safe_Mode::ERROR_TRANSIENT_PREFIX . $snippet_id`.

(e) Add the new render method after `render_notices()`:

```php
	/**
	 * Render admin notices for snippets that emitted non-fatal warnings.
	 *
	 * @return void
	 */
	public function render_warning_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$warning_ids = get_option( Safe_Mode::OPTION_WARNINGS, [] );

		if ( ! is_array( $warning_ids ) || empty( $warning_ids ) ) {
			return;
		}

		foreach ( $warning_ids as $snippet_id ) {
			$snippet_id = (int) $snippet_id;
			$post       = get_post( $snippet_id );

			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$warnings = get_transient( Safe_Mode::WARNINGS_TRANSIENT_PREFIX . $snippet_id );

			if ( ! is_array( $warnings ) || empty( $warnings ) ) {
				continue;
			}

			$edit_url = get_edit_post_link( $snippet_id, 'raw' );

			printf(
				'<div class="notice notice-info is-dismissible">'
				. '<p><strong>%s</strong></p>'
				. '<p>%s</p>'
				. '<p><a href="%s" class="button button-secondary">%s</a></p>'
				. '</div>',
				/* translators: %s: snippet title */
				sprintf( esc_html__( 'leaStudios Snippets: "%s" emitted warnings', 'leastudios-snippets' ), esc_html( $post->post_title ) ),
				esc_html( implode( ' | ', array_map( 'strval', $warnings ) ) ),
				esc_url( (string) $edit_url ),
				esc_html__( 'Edit Snippet', 'leastudios-snippets' )
			);
		}
	}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter SafeModeNoticeTest`
Expected: PASS — 2 tests.

- [ ] **Step 5: Lint**

Run: `composer lint`
Expected: PHPCS and PHPStan both clean.

- [ ] **Step 6: Commit**

```bash
git add src/Admin/Safe_Mode_Notice.php tests/SafeModeNoticeTest.php
git commit -m "Show admin notices for snippets that emitted warnings"
```

---

## Task 9: Broaden uninstall transient cleanup

Generalize `uninstall.php` so it removes the new warnings and oversize transients and the warnings index option.

**Files:**
- Modify: `uninstall.php`

- [ ] **Step 1: Modify `uninstall.php`**

Replace the transient-deletion query and the option deletion. Find:

```php
// Drop every per-snippet error transient. The Snippet_Executor's safe-mode
// path stashes errors under `leastudios_snippets_error_<id>`; removing the
// post above doesn't clean these up.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_leastudios_snippets_error_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_leastudios_snippets_error_' ) . '%'
	)
);

// Delete options.
delete_option( 'leastudios_snippets_safe_mode' );
```

Replace with:

```php
// Drop every plugin transient — per-snippet error and warning messages
// (`leastudios_snippets_error_<id>`, `leastudios_snippets_warnings_<id>`) and
// the oversized-code flags (`leastudios_snippets_oversize_<id>`). Removing the
// posts above does not clean these up.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_leastudios_snippets_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_leastudios_snippets_' ) . '%'
	)
);

// Delete options.
delete_option( 'leastudios_snippets_safe_mode' );
delete_option( 'leastudios_snippets_warnings' );
```

- [ ] **Step 2: Lint**

Run: `composer lint`
Expected: PHPCS and PHPStan both clean.

- [ ] **Step 3: Manual verification**

On a dev site: create a snippet that records a warning and one that is deactivated, confirm `leastudios_snippets_warnings`, `_transient_leastudios_snippets_warnings_*`, and `_transient_leastudios_snippets_error_*` rows exist (`wp option list --search='leastudios_snippets*'`), delete the plugin, and confirm all are gone.

- [ ] **Step 4: Commit**

```bash
git add uninstall.php
git commit -m "Broaden uninstall transient cleanup"
```

---

## Task 10: Update documentation and bump the version

Update the three docs to reflect the hardening, and bump the version to `1.1.0` so it matches the new `@since 1.1.0` tags.

**Files:**
- Modify: `CLAUDE.md`
- Modify: `README.md`
- Modify: `docs/developer-handbook.md`
- Modify: `leastudios-snippets.php`
- Modify: `phpstan-bootstrap.php`
- Modify: `readme.txt`

- [ ] **Step 1: Update `CLAUDE.md`**

Replace the entire "Safe mode — fatal-error containment" section (the heading, the two paragraphs, and the "Containment is not total" paragraph) with:

```markdown
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
```

In the "Plugin-specific gotchas" list, add this bullet after the `uninstall.php` bullet:

```markdown
- Snippet editing is gated on `DISALLOW_FILE_MODS` / `DISALLOW_FILE_EDIT` via
  `Snippet_Post_Type::is_editing_disabled()` (filterable through
  `leastudios_snippets_editing_disabled`). When disabled, write capabilities map to
  `do_not_allow` and library install is blocked; already-saved snippets still execute.
```

- [ ] **Step 2: Update `README.md`**

In the "Features" list, after the "Safe mode" bullet, add:

```markdown
- **Warning tracking** — snippets that emit non-fatal PHP warnings stay active but surface a dismissible admin notice.
```

In the "Safety model" paragraph, append this sentence:

```markdown
Fatal errors are contained even when they occur in deferred hooks or as uncatchable fatals, via a shutdown handler. If the site defines `DISALLOW_FILE_MODS` or `DISALLOW_FILE_EDIT`, creating and editing snippets is disabled (existing snippets keep running).
```

- [ ] **Step 3: Update `docs/developer-handbook.md`**

In the "Table of Contents" under "Filters", add a list entry after `leastudios_snippets_library_snippets`:

```markdown
   - [`leastudios_snippets_editing_disabled`](#leastudios_snippets_editing_disabled)
```

At the end of the "Filters" section (immediately before the `## Actions` heading), add:

```markdown
### `leastudios_snippets_editing_disabled`

Filter whether snippet creation and editing are disabled site-wide.

| Detail | Value |
|---|---|
| **Type** | Filter |
| **File** | `src/CPT/Snippet_Post_Type.php` |
| **Since** | 1.1.0 |

#### Parameters

| Parameter | Type | Description |
|---|---|---|
| `$disabled` | `bool` | Whether editing is disabled. Defaults to `true` when `DISALLOW_FILE_MODS` or `DISALLOW_FILE_EDIT` is defined and truthy. |

#### Description

Returned by `Snippet_Post_Type::is_editing_disabled()`. When `true`, the snippet write
capabilities map to `do_not_allow` (blocking creation/editing/deletion through the admin UI,
REST, and WP-CLI) and the Library page refuses to install snippets. Already-saved snippets
continue to execute.

#### Example

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
```

- [ ] **Step 4: Bump the version**

In `leastudios-snippets.php`, change the header line `* Version:           1.0.0` to `* Version:           1.1.0`, and change `define( 'LEASTUDIOS_SNIPPETS_VERSION', '1.0.0' );` to `define( 'LEASTUDIOS_SNIPPETS_VERSION', '1.1.0' );`.

In `phpstan-bootstrap.php`, change `define( 'LEASTUDIOS_SNIPPETS_VERSION', '1.0.0' );` to `define( 'LEASTUDIOS_SNIPPETS_VERSION', '1.1.0' );`.

In `readme.txt`, change the `Stable tag:` line to `Stable tag: 1.1.0`.

- [ ] **Step 5: Verify docs and lint**

Run: `composer lint`
Expected: PHPCS and PHPStan both clean (no PHP changed, but confirms nothing broke).

- [ ] **Step 6: Commit**

```bash
git add CLAUDE.md README.md docs/developer-handbook.md leastudios-snippets.php phpstan-bootstrap.php readme.txt
git commit -m "Document safe-mode hardening and bump to 1.1.0"
```

---

## Task 11: Final verification

Confirm the whole suite and static analysis are green.

**Files:** none.

- [ ] **Step 1: Run the full test suite**

Run: `composer test`
Expected: PASS — all test classes green: `ConditionCheckerTest`, `SafeModeTest`, `SnippetExecutorTest`, `SnippetPostTypeTest`, `LibraryPageTest`, `SnippetEditorTest`, `SafeModeNoticeTest`.

- [ ] **Step 2: Run static analysis and coding standards**

Run: `composer lint`
Expected: PASS — PHPCS reports no violations; PHPStan level 6 reports no errors.

- [ ] **Step 3: Smoke-test the plugin**

Run: `wp plugin list` and confirm `leastudios-snippets` is active with version `1.1.0`. Create a PHP snippet that does `add_action( 'wp_head', function () { undefined_snippet_fn(); } );`, activate it, load the front end once: the page errors, then the snippet appears auto-deactivated under safe mode and subsequent loads are clean.

---

## Self-Review

**Spec coverage:**
- §1 `Safe_Mode` class — Tasks 2, 3. ✓
- §2 fatal-error containment (marker + shutdown) — Tasks 3 (`on_shutdown`, `decide_culprit`), 4 (`begin`/`end` around dispatch). ✓
- §3 errno filter + warning recording — Task 4 (`execute_php` split). ✓
- §4 warning notice + oversized-code notice — Tasks 7 (oversize), 8 (warning notice). ✓
- §5 `DISALLOW_FILE_MODS` gating — Tasks 5 (caps + predicate), 6 (library), 7 (editing-disabled notice). ✓
- §6 testing — every implementation task is test-first; Task 1 installs the library; Task 11 verifies. ✓
- §7 docs + `uninstall.php` — Tasks 9, 10. ✓

**Placeholder scan:** No "TBD"/"TODO"; every code step shows complete code; every command shows expected output. The two manual-verification steps (Tasks 6, 7, 9) are for admin-UI/uninstall glue that cannot be unit-tested and give concrete reproduction steps.

**Type consistency:** `Safe_Mode` method names are stable across tasks — `begin`, `end`, `get_disabled_ids`, `is_disabled`, `decide_culprit`, `deactivate`, `record_warnings`, `clear_warnings`, `init`, `on_shutdown`. Constants `OPTION_SAFE_MODE`, `OPTION_WARNINGS`, `ERROR_TRANSIENT_PREFIX`, `WARNINGS_TRANSIENT_PREFIX` are defined in Task 2/3 and consumed in Tasks 4, 8. `Snippet_Executor.__construct(Condition_Checker, Safe_Mode)` is defined in Task 4 and used consistently by `SnippetExecutorTest`. `Snippet_Post_Type::is_editing_disabled()` is defined in Task 5 and consumed in Tasks 6, 7.
