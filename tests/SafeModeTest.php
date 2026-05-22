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
