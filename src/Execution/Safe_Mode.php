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
