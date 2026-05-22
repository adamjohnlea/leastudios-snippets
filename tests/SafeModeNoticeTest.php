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
