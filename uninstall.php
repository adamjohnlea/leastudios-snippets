<?php
/**
 * Uninstall handler.
 *
 * @package LEAStudios\Snippets
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete all snippet posts.
$leastudios_snippets_posts = get_posts(
	[
		'post_type'   => 'leastudios_snippet',
		'numberposts' => -1,
		'post_status' => 'any',
		'fields'      => 'ids',
	]
);

foreach ( $leastudios_snippets_posts as $leastudios_snippets_post_id ) {
	wp_delete_post( $leastudios_snippets_post_id, true );
}

// Delete options.
delete_option( 'leastudios_snippets_safe_mode' );

flush_rewrite_rules();
