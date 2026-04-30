<?php
/**
 * Conditional logic evaluator for snippets.
 *
 * Evaluates whether a snippet should run based on its configured conditions.
 *
 * @package LEAStudios\Snippets\Execution
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Execution;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates snippet conditions.
 */
class Condition_Checker {

	/**
	 * Check whether all conditions pass for a snippet.
	 *
	 * Uses AND logic: all conditions must pass for the snippet to execute.
	 * An empty conditions array means no restrictions (always execute).
	 *
	 * @param array<int, array<string, string>> $conditions The conditions array.
	 * @param int                               $snippet_id The snippet post ID.
	 * @return bool
	 */
	public function check( array $conditions, int $snippet_id = 0 ): bool {
		if ( empty( $conditions ) ) {
			return true;
		}

		foreach ( $conditions as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}

			$type     = $condition['type'] ?? '';
			$value    = $condition['value'] ?? '';
			$operator = $condition['operator'] ?? 'is';

			$result = $this->evaluate_condition( $type, $value, $operator );

			/**
			 * Filters the result of an individual condition check.
			 *
			 * Allows developers to override or extend condition evaluation.
			 *
			 * @since 1.0.0
			 *
			 * @param bool                 $result     The condition result.
			 * @param array<string, string> $condition  The condition array with type, value, and operator.
			 * @param int                  $snippet_id The snippet post ID.
			 */
			$result = (bool) apply_filters( 'leastudios_snippets_condition_result', $result, $condition, $snippet_id );

			if ( ! $result ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Evaluate a single condition.
	 *
	 * @param string $type     The condition type.
	 * @param string $value    The condition value.
	 * @param string $operator The comparison operator ('is' or 'is_not').
	 * @return bool
	 */
	private function evaluate_condition( string $type, string $value, string $operator ): bool {
		$result = match ( $type ) {
			'page_type'                => $this->check_page_type( $value ),
			'user_logged', 'user_logged_in' => $this->check_user_logged_in(),
			'user_role'                => $this->check_user_role( $value ),
			'post_type'                => $this->check_post_type( $value ),
			'page_post_id', 'page_id'  => $this->check_page_id( $value ),
			default                    => true,
		};

		if ( 'is_not' === $operator ) {
			return ! $result;
		}

		return $result;
	}

	/**
	 * Check a page type condition.
	 *
	 * @param string $value The WordPress conditional tag name.
	 * @return bool
	 */
	private function check_page_type( string $value ): bool {
		// `is_*()` page-type checks are only meaningful after `wp` has fired
		// and the main query has been resolved. If a snippet runs earlier
		// (e.g. the `everywhere` location at plugins_loaded), short-circuit
		// to *false* so the conditional gate denies execution rather than
		// silently passing every page-type check. This matches the user's
		// expectation that "run only on single posts" means "do nothing
		// before we know the page type."
		if ( ! did_action( 'wp' ) ) {
			return false;
		}

		return match ( $value ) {
			'is_front_page' => is_front_page(),
			'is_home'       => is_home(),
			'is_single'     => is_single(),
			'is_page'       => is_page(),
			'is_archive'    => is_archive(),
			'is_search'     => is_search(),
			'is_404'        => is_404(),
			default         => true,
		};
	}

	/**
	 * Check whether the current user is logged in.
	 *
	 * @return bool
	 */
	private function check_user_logged_in(): bool {
		return is_user_logged_in();
	}

	/**
	 * Check whether the current user has a specific role.
	 *
	 * @param string $role The role slug to check.
	 * @return bool
	 */
	private function check_user_role( string $role ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user = wp_get_current_user();

		return in_array( $role, (array) $user->roles, true );
	}

	/**
	 * Check the current post type.
	 *
	 * @param string $post_type The post type slug to match.
	 * @return bool
	 */
	private function check_post_type( string $post_type ): bool {
		// See check_page_type: deny when the main query has not yet resolved.
		if ( ! did_action( 'wp' ) ) {
			return false;
		}

		return get_post_type() === $post_type;
	}

	/**
	 * Check the current page or post ID.
	 *
	 * @param string $page_id The page/post ID to match.
	 * @return bool
	 */
	private function check_page_id( string $page_id ): bool {
		// See check_page_type: deny when the main query has not yet resolved.
		if ( ! did_action( 'wp' ) ) {
			return false;
		}

		return get_the_ID() === (int) $page_id;
	}
}
