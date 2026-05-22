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
