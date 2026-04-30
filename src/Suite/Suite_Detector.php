<?php
/**
 * Suite plugin detection utility.
 *
 * @package LEAStudios\Snippets\Suite
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Suite;

defined( 'ABSPATH' ) || exit;

/**
 * Detects active leaStudios suite plugins.
 *
 * Detection prefers the plugin's `LEASTUDIOS_*_VERSION` constant — defined
 * by every sibling plugin's bootstrap — over file-path matching, because:
 *
 * - It survives plugin-file renames or relocation under MU-plugin loaders.
 * - It works at `plugins_loaded` time without first loading
 *   wp-admin/includes/plugin.php.
 * - It's also a strong "the plugin successfully booted" signal — a plugin
 *   whose Composer autoload is missing returns early before defining its
 *   constants, and we shouldn't claim it is active in that case.
 *
 * The is_plugin_active() fallback handles the edge case where a sibling
 * plugin's bootstrap file hasn't yet run on the current request.
 */
class Suite_Detector {

	/**
	 * Mapping of suite plugin slugs to detection info.
	 *
	 * @var array<string, array{file: string, constant: string}>
	 */
	public const PLUGINS = [
		'leastudios-mailer'          => [
			'file'     => 'leastudios-mailer/leastudios-mailer.php',
			'constant' => 'LEASTUDIOS_MAILER_VERSION',
		],
		'leastudios-forms'           => [
			'file'     => 'leastudios-forms/leastudios-forms.php',
			'constant' => 'LEASTUDIOS_FORMS_VERSION',
		],
		'leastudios-snippets'        => [
			'file'     => 'leastudios-snippets/leastudios-snippets.php',
			'constant' => 'LEASTUDIOS_SNIPPETS_VERSION',
		],
		'leastudios-payments'        => [
			'file'     => 'leastudios-payments/leastudios-payments.php',
			'constant' => 'LEASTUDIOS_PAYMENTS_VERSION',
		],
		'leastudios-email-templates' => [
			'file'     => 'leastudios-email-templates/leastudios-email-templates.php',
			'constant' => 'LEASTUDIOS_EMAIL_TEMPLATES_VERSION',
		],
		'leastudios-siteaudit'       => [
			'file'     => 'leastudios-siteaudit/leastudios-siteaudit.php',
			'constant' => 'LEASTUDIOS_SITEAUDIT_VERSION',
		],
	];

	/**
	 * Check if a suite plugin is active.
	 *
	 * @param string $slug The plugin slug.
	 * @return bool Whether the plugin is active.
	 */
	public static function is_active( string $slug ): bool {
		if ( ! isset( self::PLUGINS[ $slug ] ) ) {
			return false;
		}

		$entry = self::PLUGINS[ $slug ];

		if ( defined( $entry['constant'] ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $entry['file'] );
	}

	/**
	 * Get all active suite plugin slugs.
	 *
	 * @return string[] Array of active plugin slugs.
	 */
	public static function get_active_suite_plugins(): array {
		$active = [];

		foreach ( array_keys( self::PLUGINS ) as $slug ) {
			if ( self::is_active( $slug ) ) {
				$active[] = $slug;
			}
		}

		return $active;
	}
}
