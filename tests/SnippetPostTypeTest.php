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
		// DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT cannot be undefined between tests,
		// so the constant path is covered via the editing-disabled filter seam.
		add_filter( 'leastudios_snippets_editing_disabled', '__return_true' );
		$this->assertTrue( Snippet_Post_Type::is_editing_disabled() );
	}

	public function test_admin_can_edit_a_snippet_post(): void {
		$admin_id   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$snippet_id = self::factory()->post->create(
			[
				'post_type'   => Snippet_Post_Type::POST_TYPE,
				'post_author' => $admin_id,
			]
		);

		$this->assertTrue( user_can( $admin_id, 'edit_post', $snippet_id ) );
	}

	public function test_non_admin_cannot_edit_a_snippet_post(): void {
		$editor_id  = self::factory()->user->create( [ 'role' => 'editor' ] );
		$snippet_id = self::factory()->post->create(
			[ 'post_type' => Snippet_Post_Type::POST_TYPE ]
		);

		$this->assertFalse( user_can( $editor_id, 'edit_post', $snippet_id ) );
	}

	public function test_snippet_edit_denied_when_editing_disabled(): void {
		add_filter( 'leastudios_snippets_editing_disabled', '__return_true' );
		$admin_id   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$snippet_id = self::factory()->post->create(
			[
				'post_type'   => Snippet_Post_Type::POST_TYPE,
				'post_author' => $admin_id,
			]
		);

		$this->assertFalse( user_can( $admin_id, 'edit_post', $snippet_id ) );
	}

	public function test_admin_editing_regular_post_is_unaffected(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$post_id  = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_author' => $admin_id,
			]
		);

		$this->assertTrue( user_can( $admin_id, 'edit_post', $post_id ) );
	}
}
