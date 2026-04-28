<?php
/**
 * Safe mode admin notices for auto-deactivated snippets.
 *
 * @package LEAStudios\Snippets\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Admin;

defined( 'ABSPATH' ) || exit;

use LEAStudios\Snippets\CPT\Snippet_Post_Type;

/**
 * Displays admin notices when snippets have been auto-deactivated due to PHP errors.
 */
class Safe_Mode_Notice {

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_notices', [ $this, 'render_notices' ] );
		add_action( 'admin_init', [ $this, 'handle_reactivate' ] );
	}

	/**
	 * Render admin notices for errored snippets.
	 *
	 * @return void
	 */
	public function render_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$safe_mode_ids = get_option( 'leastudios_snippets_safe_mode', [] );

		if ( ! is_array( $safe_mode_ids ) || empty( $safe_mode_ids ) ) {
			return;
		}

		foreach ( $safe_mode_ids as $snippet_id ) {
			$snippet_id = (int) $snippet_id;
			$post       = get_post( $snippet_id );

			if ( ! $post ) {
				continue;
			}

			$error_transient = get_transient( 'leastudios_snippets_error_' . $snippet_id );
			$error_message   = is_string( $error_transient ) ? $error_transient : __( 'Unknown error', 'leastudios-snippets' );

			$reactivate_url = wp_nonce_url(
				add_query_arg(
					[
						'action'     => 'leastudios_snippets_reactivate',
						'snippet_id' => $snippet_id,
					],
					admin_url( 'admin.php' )
				),
				'leastudios_snippets_reactivate_' . $snippet_id
			);

			$edit_url = get_edit_post_link( $snippet_id, 'raw' );

			printf(
				'<div class="notice notice-warning is-dismissible leastudios-snippets-safe-mode-notice">'
				. '<p><strong>%s</strong></p>'
				. '<p>%s</p>'
				. '<p>%s</p>'
				. '<p><a href="%s" class="button">%s</a> <a href="%s" class="button button-secondary">%s</a></p>'
				. '</div>',
				/* translators: %s: snippet title */
				sprintf( esc_html__( 'leaStudios Snippets: "%s" was auto-deactivated', 'leastudios-snippets' ), esc_html( $post->post_title ) ),
				/* translators: %s: error message */
				sprintf( esc_html__( 'Error: %s', 'leastudios-snippets' ), esc_html( $error_message ) ),
				esc_html__( 'This snippet has been deactivated to prevent further issues. Please review the code before reactivating.', 'leastudios-snippets' ),
				esc_url( $reactivate_url ),
				esc_html__( 'Reactivate Snippet', 'leastudios-snippets' ),
				esc_url( (string) $edit_url ),
				esc_html__( 'Edit Snippet', 'leastudios-snippets' )
			);
		}
	}

	/**
	 * Handle the reactivate action.
	 *
	 * @return void
	 */
	public function handle_reactivate(): void {
		if ( ! isset( $_GET['action'] ) || 'leastudios_snippets_reactivate' !== $_GET['action'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'leastudios-snippets' ) );
		}

		$snippet_id = isset( $_GET['snippet_id'] ) ? (int) $_GET['snippet_id'] : 0;

		if ( ! $snippet_id ) {
			wp_die( esc_html__( 'Invalid snippet ID.', 'leastudios-snippets' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'leastudios_snippets_reactivate_' . $snippet_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'leastudios-snippets' ) );
		}

		// Remove from safe mode array.
		$safe_mode_ids = get_option( 'leastudios_snippets_safe_mode', [] );

		if ( is_array( $safe_mode_ids ) ) {
			$safe_mode_ids = array_values( array_diff( $safe_mode_ids, [ $snippet_id ] ) );
			update_option( 'leastudios_snippets_safe_mode', $safe_mode_ids );
		}

		// Reactivate the snippet.
		update_post_meta( $snippet_id, Snippet_Post_Type::META_ACTIVE, '1' );

		// Delete the error transient.
		delete_transient( 'leastudios_snippets_error_' . $snippet_id );

		/**
		 * Fires after a snippet is reactivated from safe mode.
		 *
		 * @since 1.0.0
		 *
		 * @param int $snippet_id The reactivated snippet post ID.
		 */
		do_action( 'leastudios_snippets_snippet_reactivated', $snippet_id );

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Snippet_Post_Type::POST_TYPE ) );
		exit;
	}
}
