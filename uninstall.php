<?php
/**
 * Uninstall handler.
 *
 * Deletes all snippet posts in batches so a site with thousands of
 * snippets does not time out the uninstall request, and clears every
 * snippet error transient so per-snippet error data does not survive
 * the plugin removal.
 *
 * @package LEAStudios\Snippets
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Delete snippet posts in batches.
$leastudios_snippets_batch_size = 100;

while ( true ) {
	$leastudios_snippets_post_ids = get_posts(
		[
			'post_type'   => 'leastudios_snippet',
			'numberposts' => $leastudios_snippets_batch_size,
			'post_status' => 'any',
			'fields'      => 'ids',
		]
	);

	if ( empty( $leastudios_snippets_post_ids ) ) {
		break;
	}

	foreach ( $leastudios_snippets_post_ids as $leastudios_snippets_post_id ) {
		wp_delete_post( $leastudios_snippets_post_id, true );
	}
}

// Drop every plugin transient — per-snippet error and warning messages
// (`leastudios_snippets_error_<id>`, `leastudios_snippets_warnings_<id>`) and
// the oversized-code flags (`leastudios_snippets_oversize_<id>`). Removing the
// posts above does not clean these up.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_leastudios_snippets_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_leastudios_snippets_' ) . '%'
	)
);

// Delete options.
delete_option( 'leastudios_snippets_safe_mode' );
delete_option( 'leastudios_snippets_warnings' );

flush_rewrite_rules();
