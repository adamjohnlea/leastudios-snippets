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
	 */
	public const OPTION_SAFE_MODE = 'leastudios_snippets_safe_mode';

	/**
	 * Option holding the list of snippet IDs that emitted warnings.
	 */
	public const OPTION_WARNINGS = 'leastudios_snippets_warnings';

	/**
	 * Transient key prefix for a deactivated snippet's error message.
	 */
	public const ERROR_TRANSIENT_PREFIX = 'leastudios_snippets_error_';

	/**
	 * Transient key prefix for a snippet's recorded warning messages.
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
	 * @param int                $snippet_id The snippet post ID.
	 * @param array<int, string> $messages   The warning messages.
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

		// $last_error is non-null here: decide_culprit() only returns a non-null
		// culprit when $last_error has a fatal-class 'type' key, which means PHP
		// populated the full error struct with all four fields.
		$message = sprintf(
			'%s in %s on line %d',
			(string) $last_error['message'],
			(string) $last_error['file'],
			(int) $last_error['line']
		);

		$this->deactivate( $culprit, $message );
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
