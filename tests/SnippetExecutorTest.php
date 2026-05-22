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
