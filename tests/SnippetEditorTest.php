<?php
/**
 * Tests for Snippet_Editor save handling.
 *
 * @package LEAStudios\Snippets\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Tests;

use LEAStudios\Snippets\Admin\Snippet_Editor;
use LEAStudios\Snippets\CPT\Snippet_Post_Type;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Snippets\Admin\Snippet_Editor
 */
class SnippetEditorTest extends TestCase {

	public function tear_down(): void {
		unset( $_POST['_leastudios_snippets_nonce'], $_POST['leastudios_snippets_code'] );
		parent::tear_down();
	}

	public function test_save_rejects_oversized_code(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post_id = self::factory()->post->create( [ 'post_type' => Snippet_Post_Type::POST_TYPE ] );
		update_post_meta( $post_id, Snippet_Post_Type::META_CODE, 'original' );

		// These literals mirror Snippet_Editor's private NONCE_FIELD / NONCE_ACTION.
		$_POST['_leastudios_snippets_nonce'] = wp_create_nonce( 'leastudios_snippets_save_snippet' );
		$_POST['leastudios_snippets_code']   = str_repeat( 'a', 262145 );

		( new Snippet_Editor() )->save( $post_id, get_post( $post_id ) );

		$this->assertSame( 'original', get_post_meta( $post_id, Snippet_Post_Type::META_CODE, true ) );
		$this->assertNotFalse( get_transient( 'leastudios_snippets_oversize_' . $post_id ) );
	}

	public function test_save_accepts_normal_code(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post_id = self::factory()->post->create( [ 'post_type' => Snippet_Post_Type::POST_TYPE ] );

		$_POST['_leastudios_snippets_nonce'] = wp_create_nonce( 'leastudios_snippets_save_snippet' );
		$_POST['leastudios_snippets_code']   = 'echo "hello";';

		( new Snippet_Editor() )->save( $post_id, get_post( $post_id ) );

		$this->assertSame( 'echo "hello";', get_post_meta( $post_id, Snippet_Post_Type::META_CODE, true ) );
		$this->assertFalse( get_transient( 'leastudios_snippets_oversize_' . $post_id ) );
	}
}
