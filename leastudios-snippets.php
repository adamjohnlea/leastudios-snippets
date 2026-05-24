<?php
/**
 * Plugin Name:       leaStudios Snippets
 * Plugin URI:        https://leastudios.com/plugins/leastudios-snippets
 * Description:       Manage custom code snippets (PHP, JS, CSS, HTML) with auto-insert locations, safe error handling, and a pre-built library of leaStudios suite hooks.
 * Version:           1.1.2
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            leaStudios
 * Author URI:        https://leastudios.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       leastudios-snippets
 * Domain Path:       /languages
 *
 * @package LEAStudios\Snippets
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'LEASTUDIOS_SNIPPETS_VERSION', '1.1.1' );
define( 'LEASTUDIOS_SNIPPETS_FILE', __FILE__ );
define( 'LEASTUDIOS_SNIPPETS_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEASTUDIOS_SNIPPETS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Run on plugin activation.
 *
 * Registered above the vendor-autoload check so a botched install
 * (composer not yet run) still gets a working activation hook. The
 * call to `Snippet_Post_Type::register()` falls back to a no-op when
 * the autoloader hasn't run; the `flush_rewrite_rules()` call is the
 * minimum we want on every activation regardless.
 *
 * @return void
 */
function leastudios_snippets_activate(): void {
	if ( class_exists( LEAStudios\Snippets\CPT\Snippet_Post_Type::class ) ) {
		LEAStudios\Snippets\CPT\Snippet_Post_Type::register();
	}
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'leastudios_snippets_activate' );

/**
 * Run on plugin deactivation.
 *
 * @return void
 */
function leastudios_snippets_deactivate(): void {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'leastudios_snippets_deactivate' );

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong>: %s</p></div>',
				esc_html__( 'leaStudios Snippets', 'leastudios-snippets' ),
				esc_html__( 'Plugin dependencies are missing. Run "composer install" in the plugin directory.', 'leastudios-snippets' )
			);
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Initialize the plugin.
 *
 * @return void
 */
function leastudios_snippets_init(): void {
	if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
		add_action( 'admin_notices', 'leastudios_snippets_php_version_notice' );
		return;
	}

	$plugin = new LEAStudios\Snippets\Plugin();
	$plugin->init();
}
add_action( 'plugins_loaded', 'leastudios_snippets_init' );

/**
 * Display PHP version notice.
 *
 * @return void
 */
function leastudios_snippets_php_version_notice(): void {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'leaStudios Snippets requires PHP 8.2 or higher.', 'leastudios-snippets' )
	);
}
