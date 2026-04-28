<?php
/**
 * Plugin Name:       leaStudios Snippets
 * Plugin URI:        https://leastudios.com/plugins/leastudios-snippets
 * Description:       Manage custom code snippets (PHP, JS, CSS, HTML) with auto-insert locations, safe error handling, and a pre-built library of leaStudios suite hooks.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
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

define( 'LEASTUDIOS_SNIPPETS_VERSION', '1.0.0' );
define( 'LEASTUDIOS_SNIPPETS_FILE', __FILE__ );
define( 'LEASTUDIOS_SNIPPETS_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEASTUDIOS_SNIPPETS_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Initialize the plugin.
 *
 * @return void
 */
function leastudios_snippets_init(): void {
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
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
		esc_html__( 'leaStudios Snippets requires PHP 8.1 or higher.', 'leastudios-snippets' )
	);
}

/**
 * Run on plugin activation.
 *
 * @return void
 */
function leastudios_snippets_activate(): void {
	LEAStudios\Snippets\CPT\Snippet_Post_Type::register();
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
