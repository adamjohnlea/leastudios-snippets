<?php
/**
 * Nonce helper.
 *
 * @package LEAStudios\Snippets\Security
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Security;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Centralized nonce management.
 */
class Nonce {

	/**
	 * Create a nonce.
	 *
	 * @param string $action The action name.
	 * @return string
	 */
	public static function create( string $action ): string {
		return wp_create_nonce( 'leastudios_snippets_' . $action );
	}

	/**
	 * Verify a nonce.
	 *
	 * @param string $nonce  The nonce to verify.
	 * @param string $action The action name.
	 * @return bool
	 */
	public static function verify( string $nonce, string $action ): bool {
		return (bool) wp_verify_nonce( $nonce, 'leastudios_snippets_' . $action );
	}

	/**
	 * Verify an AJAX nonce and die on failure.
	 *
	 * @param string $action    The action name.
	 * @param string $param_key The request parameter key.
	 * @return void
	 */
	public static function check_ajax( string $action, string $param_key = '_wpnonce' ): void {
		check_ajax_referer( 'leastudios_snippets_' . $action, $param_key );
	}
}
