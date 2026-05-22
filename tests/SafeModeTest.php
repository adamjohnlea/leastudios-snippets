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

	public function test_get_disabled_ids_returns_empty_array_when_option_is_corrupted(): void {
		update_option( 'leastudios_snippets_safe_mode', 'corrupted' );
		$this->assertSame( [], ( new Safe_Mode() )->get_disabled_ids() );
	}

	public function test_decide_culprit_returns_null_when_error_has_no_type(): void {
		$this->assertNull( ( new Safe_Mode() )->decide_culprit( [], 42 ) );
	}

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
}
