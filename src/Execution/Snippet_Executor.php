<?php
/**
 * Snippet execution engine.
 *
 * Queries active snippets, groups them by location, registers appropriate
 * WordPress hooks, and executes code at the right time with safe error handling.
 *
 * @package LEAStudios\Snippets\Execution
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Execution;

defined( 'ABSPATH' ) || exit;

use LEAStudios\Snippets\CPT\Snippet_Post_Type;
use WP_Post;
use WP_Query;

/**
 * Core snippet execution engine.
 */
class Snippet_Executor {

	/**
	 * Snippets grouped by location.
	 *
	 * @var array<string, array<int, WP_Post>>
	 */
	private array $snippets_by_location = [];

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

	/**
	 * Query all active snippets, group by location, and register hooks.
	 *
	 * Runs on `init` at priority 1 so snippets are available as early as possible.
	 *
	 * @return void
	 */
	public function init(): void {
		// Hook cache invalidation as soon as the executor wires up so a save
		// or delete during this same request flushes the cache for the next.
		add_action( 'save_post_' . Snippet_Post_Type::POST_TYPE, [ self::class, 'invalidate_active_cache' ] );
		add_action( 'deleted_post', [ self::class, 'invalidate_active_cache' ] );

		$active_ids = $this->get_active_snippet_ids();

		if ( empty( $active_ids ) ) {
			return;
		}

		foreach ( $active_ids as $snippet_id ) {
			if ( $this->safe_mode->is_disabled( $snippet_id ) ) {
				continue;
			}

			$snippet = get_post( $snippet_id );

			if ( ! $snippet instanceof WP_Post || 'publish' !== $snippet->post_status ) {
				continue;
			}

			$location = (string) get_post_meta( $snippet->ID, Snippet_Post_Type::META_LOCATION, true );

			if ( '' === $location ) {
				$location = 'everywhere';
			}

			$this->snippets_by_location[ $location ][] = $snippet;
		}

		$this->register_location_hooks( $this->snippets_by_location );
	}

	/**
	 * Cache key for the list of currently-active snippet post IDs.
	 */
	private const ACTIVE_IDS_CACHE_KEY = 'leastudios_snippets_active_ids';

	/**
	 * Get the list of active-snippet post IDs, hitting an object cache to
	 * avoid the meta_query on every front-end request.
	 *
	 * @return array<int, int>
	 */
	private function get_active_snippet_ids(): array {
		$cached = wp_cache_get( self::ACTIVE_IDS_CACHE_KEY, 'leastudios_snippets' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$query = new WP_Query(
			[
				'post_type'      => Snippet_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'   => Snippet_Post_Type::META_ACTIVE,
						'value' => '1',
					],
				],
			]
		);

		$ids = array_map( 'intval', $query->posts );

		wp_cache_set( self::ACTIVE_IDS_CACHE_KEY, $ids, 'leastudios_snippets' );

		return $ids;
	}

	/**
	 * Drop the active-snippet ID cache after any snippet save or delete.
	 *
	 * @return void
	 */
	public static function invalidate_active_cache(): void {
		wp_cache_delete( self::ACTIVE_IDS_CACHE_KEY, 'leastudios_snippets' );
	}

	/**
	 * Register WordPress hooks for each snippet location.
	 *
	 * @param array<string, array<int, WP_Post>> $snippets_by_location Snippets grouped by location key.
	 * @return void
	 */
	public function register_location_hooks( array $snippets_by_location ): void {
		foreach ( $snippets_by_location as $location => $snippets ) {
			switch ( $location ) {
				case 'everywhere':
					// Execute PHP snippets immediately.
					foreach ( $snippets as $snippet ) {
						$this->maybe_execute_snippet( $snippet, $location );
					}
					break;

				case 'wp_head':
				case 'wp_footer':
				case 'wp_body_open':
				case 'admin_head':
				case 'admin_footer':
				case 'login_head':
					$this->register_hook_location( $location, $snippets );
					break;

				case 'frontend_header':
					add_action(
						'wp_head',
						function () use ( $snippets, $location ): void {
							if ( is_admin() ) {
								return;
							}
							foreach ( $snippets as $snippet ) {
								$this->maybe_execute_snippet( $snippet, $location );
							}
						}
					);
					break;

				case 'frontend_footer':
					add_action(
						'wp_footer',
						function () use ( $snippets, $location ): void {
							if ( is_admin() ) {
								return;
							}
							foreach ( $snippets as $snippet ) {
								$this->maybe_execute_snippet( $snippet, $location );
							}
						}
					);
					break;

				case 'the_content_before':
				case 'the_content_after':
					add_filter(
						'the_content',
						function ( string $content ) use ( $snippets, $location ): string {
							return $this->filter_the_content( $content, $snippets, $location );
						}
					);
					break;

				case 'shortcode':
					add_shortcode( 'leastudios_snippet', [ $this, 'handle_shortcode' ] );
					break;

				case 'custom_hook':
					foreach ( $snippets as $snippet ) {
						$hook_name = (string) get_post_meta( $snippet->ID, Snippet_Post_Type::META_CUSTOM_HOOK, true );

						if ( empty( $hook_name ) ) {
							continue;
						}

						$priority = (int) get_post_meta( $snippet->ID, Snippet_Post_Type::META_PRIORITY, true );
						$priority = ( $priority > 0 ) ? $priority : 10;

						add_action(
							$hook_name,
							function () use ( $snippet, $location ): void {
								$this->maybe_execute_snippet( $snippet, $location );
							},
							$priority
						);
					}
					break;
			}
		}
	}

	/**
	 * Register a standard WordPress action hook for a group of snippets.
	 *
	 * @param string              $hook     The WordPress hook name (matches the location key).
	 * @param array<int, WP_Post> $snippets The snippets to execute on this hook.
	 * @return void
	 */
	private function register_hook_location( string $hook, array $snippets ): void {
		add_action(
			$hook,
			function () use ( $snippets, $hook ): void {
				foreach ( $snippets as $snippet ) {
					$this->maybe_execute_snippet( $snippet, $hook );
				}
			}
		);
	}

	/**
	 * Determine if a snippet should execute and then execute it.
	 *
	 * Checks conditions, applies the `leastudios_snippets_should_execute` filter,
	 * fires before/after actions, and dispatches to the appropriate executor.
	 *
	 * @param WP_Post $snippet  The snippet post object.
	 * @param string  $location The location context.
	 * @return void
	 */
	private function maybe_execute_snippet( WP_Post $snippet, string $location ): void {
		$type = (string) get_post_meta( $snippet->ID, Snippet_Post_Type::META_TYPE, true );
		$code = (string) get_post_meta( $snippet->ID, Snippet_Post_Type::META_CODE, true );

		if ( empty( $code ) ) {
			return;
		}

		// Check conditions.
		$conditions_json = (string) get_post_meta( $snippet->ID, Snippet_Post_Type::META_CONDITIONS, true );
		$conditions      = ! empty( $conditions_json ) ? json_decode( $conditions_json, true ) : [];

		if ( is_array( $conditions ) && ! empty( $conditions ) ) {
			if ( ! $this->condition_checker->check( $conditions, $snippet->ID ) ) {
				return;
			}
		}

		/**
		 * Filters whether a snippet should execute.
		 *
		 * @since 1.0.0
		 *
		 * @param bool    $should_execute Whether the snippet should execute. Default true.
		 * @param int     $snippet_id     The snippet post ID.
		 * @param WP_Post $snippet_post   The snippet post object.
		 */
		$should_execute = (bool) apply_filters( 'leastudios_snippets_should_execute', true, $snippet->ID, $snippet );

		if ( ! $should_execute ) {
			return;
		}

		/**
		 * Fires before a snippet is executed.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $snippet_id The snippet post ID.
		 * @param string $type       The snippet type (php, js, css, html).
		 * @param string $location   The auto-insert location.
		 */
		do_action( 'leastudios_snippets_before_execute', $snippet->ID, $type, $location );

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

		/**
		 * Fires after a snippet is executed.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $snippet_id The snippet post ID.
		 * @param string $type       The snippet type (php, js, css, html).
		 * @param string $location   The auto-insert location.
		 */
		do_action( 'leastudios_snippets_after_execute', $snippet->ID, $type, $location );
	}

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

	/**
	 * Output a JS, CSS, or HTML snippet wrapped in the appropriate tags.
	 *
	 * @param int    $snippet_id The snippet post ID.
	 * @param string $code       The code to output.
	 * @param string $type       The snippet type (js, css, html).
	 * @return void
	 */
	public function execute_output( int $snippet_id, string $code, string $type ): void {
		$output = match ( $type ) {
			'js'    => '<script>' . $code . '</script>',
			'css'   => '<style>' . $code . '</style>',
			'html'  => $code,
			default => '',
		};

		if ( empty( $output ) ) {
			return;
		}

		/**
		 * Filters the snippet output before it is echoed.
		 *
		 * @since 1.0.0
		 *
		 * @param string $output     The rendered output string.
		 * @param int    $snippet_id The snippet post ID.
		 * @param string $type       The snippet type (js, css, html).
		 */
		$output = (string) apply_filters( 'leastudios_snippets_output', $output, $snippet_id, $type );

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Filter `the_content` to prepend or append snippet output.
	 *
	 * @param string              $content  The original post content.
	 * @param array<int, WP_Post> $snippets The snippets to inject.
	 * @param string              $location Either 'the_content_before' or 'the_content_after'.
	 * @return string
	 */
	private function filter_the_content( string $content, array $snippets, string $location ): string {
		ob_start();

		foreach ( $snippets as $snippet ) {
			$this->maybe_execute_snippet( $snippet, $location );
		}

		$snippet_output = (string) ob_get_clean();

		if ( 'the_content_before' === $location ) {
			return $snippet_output . $content;
		}

		return $content . $snippet_output;
	}

	/**
	 * Handle the [leastudios_snippet] shortcode.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function handle_shortcode( array|string $atts = [] ): string {
		$atts = shortcode_atts(
			[ 'id' => 0 ],
			$atts,
			'leastudios_snippet'
		);

		$snippet_id = (int) $atts['id'];

		if ( $snippet_id <= 0 ) {
			return '';
		}

		$snippet = get_post( $snippet_id );

		if ( ! $snippet instanceof WP_Post || Snippet_Post_Type::POST_TYPE !== $snippet->post_type ) {
			return '';
		}

		// Verify the snippet is active.
		$active = (string) get_post_meta( $snippet_id, Snippet_Post_Type::META_ACTIVE, true );

		if ( '1' !== $active ) {
			return '';
		}

		// Check safe mode.
		if ( $this->safe_mode->is_disabled( $snippet_id ) ) {
			return '';
		}

		ob_start();
		$this->maybe_execute_snippet( $snippet, 'shortcode' );

		return (string) ob_get_clean();
	}
}
