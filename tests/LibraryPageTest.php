<?php
/**
 * Tests for Library_Page install gating.
 *
 * @package LEAStudios\Snippets\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Tests;

use LEAStudios\Snippets\Admin\Library_Page;
use LEAStudios\Snippets\Library\Snippet_Library;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Snippets\Admin\Library_Page
 */
class LibraryPageTest extends TestCase {

	public function test_handle_install_blocked_when_editing_disabled(): void {
		add_filter( 'leastudios_snippets_editing_disabled', '__return_true' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_POST['_leastudios_snippets_library_nonce'] = wp_create_nonce( 'leastudios_snippets_install_library' );
		$_POST['snippet_slug']                       = 'disable-xmlrpc';

		$this->expectException( \WPDieException::class );

		( new Library_Page( new Snippet_Library() ) )->handle_install();
	}
}
