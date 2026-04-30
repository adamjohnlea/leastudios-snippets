<?php
/**
 * Main plugin bootstrap class.
 *
 * @package LEAStudios\Snippets
 */

declare(strict_types=1);

namespace LEAStudios\Snippets;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Snippets\Admin\Library_Page;
use LEAStudios\Snippets\Admin\Safe_Mode_Notice;
use LEAStudios\Snippets\Admin\Snippet_Editor;
use LEAStudios\Snippets\Admin\Snippets_Page;
use LEAStudios\Snippets\CPT\Snippet_Post_Type;
use LEAStudios\Snippets\Execution\Condition_Checker;
use LEAStudios\Snippets\Execution\Snippet_Executor;
use LEAStudios\Snippets\Library\Snippet_Library;

/**
 * Wires all plugin components together.
 */
final class Plugin {

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	public function init(): void {
		// Register CPT, post-meta, and the capability gate. The gate must
		// hook map_meta_cap before any cap check fires, so we register it
		// at plugins_loaded time rather than waiting for `init`.
		add_action( 'init', [ Snippet_Post_Type::class, 'register' ] );
		add_action( 'init', [ Snippet_Post_Type::class, 'register_meta' ] );
		add_filter( 'map_meta_cap', [ Snippet_Post_Type::class, 'map_capabilities' ], 10, 2 );

		// Execute active snippets.
		$condition_checker = new Condition_Checker();
		$executor          = new Snippet_Executor( $condition_checker );
		$executor->init();

		// Admin.
		if ( is_admin() ) {
			$snippets_page = new Snippets_Page();
			$snippets_page->init();

			$editor = new Snippet_Editor();
			$editor->init();

			$safe_mode = new Safe_Mode_Notice();
			$safe_mode->init();

			$library      = new Snippet_Library();
			$library_page = new Library_Page( $library );
			$library_page->init();
		}

		/**
		 * Fires after all snippets components are initialized.
		 */
		do_action( 'leastudios_snippets_initialized' );
	}
}
