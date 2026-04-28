<?php
/**
 * Top-level admin menu page for leaStudios Snippets.
 *
 * @package LEAStudios\Snippets\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level Snippets admin menu page.
 */
class Snippets_Page {

	/**
	 * Admin menu slug.
	 *
	 * @var string
	 */
	public const MENU_SLUG = 'leastudios-snippets';

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
	}

	/**
	 * Register the top-level admin menu page.
	 *
	 * The CPT auto-nests under this menu via `show_in_menu`.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		/**
		 * Filters the capability required to access the Snippets admin page.
		 *
		 * @since 1.0.0
		 *
		 * @param string $capability The required capability.
		 */
		$capability = (string) apply_filters( 'leastudios_snippets_admin_capability', 'manage_options' );

		add_menu_page(
			__( 'leaStudios Snippets', 'leastudios-snippets' ),
			__( 'Snippets', 'leastudios-snippets' ),
			$capability,
			self::MENU_SLUG,
			'', // Empty callback — CPT handles rendering.
			'dashicons-editor-code'
		);
	}
}
