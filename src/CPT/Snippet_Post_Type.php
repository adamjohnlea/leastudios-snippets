<?php
/**
 * Custom post type registration for code snippets.
 *
 * @package LEAStudios\Snippets\CPT
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\CPT;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and manages the leastudios_snippet custom post type.
 */
class Snippet_Post_Type {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'leastudios_snippet';

	/**
	 * Meta key for the snippet code.
	 *
	 * @var string
	 */
	public const META_CODE = '_leastudios_snippets_code';

	/**
	 * Meta key for the snippet type (php, js, css, html).
	 *
	 * @var string
	 */
	public const META_TYPE = '_leastudios_snippets_type';

	/**
	 * Meta key for the auto-insert location.
	 *
	 * @var string
	 */
	public const META_LOCATION = '_leastudios_snippets_location';

	/**
	 * Meta key for the active status ('1' or '0').
	 *
	 * @var string
	 */
	public const META_ACTIVE = '_leastudios_snippets_active';

	/**
	 * Meta key for the execution priority.
	 *
	 * @var string
	 */
	public const META_PRIORITY = '_leastudios_snippets_priority';

	/**
	 * Meta key for JSON conditions array.
	 *
	 * @var string
	 */
	public const META_CONDITIONS = '_leastudios_snippets_conditions';

	/**
	 * Meta key for a custom hook name.
	 *
	 * @var string
	 */
	public const META_CUSTOM_HOOK = '_leastudios_snippets_custom_hook';

	/**
	 * Every capability the snippet CPT introduces. We map all of them to
	 * `manage_options` via the {@see map_capabilities} filter, so only
	 * administrators can create, edit, publish, or delete snippets — which
	 * is required because publishing a snippet means executing arbitrary
	 * PHP / JS / CSS / HTML on the site.
	 *
	 * @var array<int, string>
	 */
	private const SNIPPET_CAPABILITIES = [
		// Meta caps (per-post).
		'edit_leastudios_snippet',
		'read_leastudios_snippet',
		'delete_leastudios_snippet',
		// Primitive caps.
		'edit_leastudios_snippets',
		'edit_others_leastudios_snippets',
		'edit_private_leastudios_snippets',
		'edit_published_leastudios_snippets',
		'publish_leastudios_snippets',
		'read_private_leastudios_snippets',
		'delete_leastudios_snippets',
		'delete_private_leastudios_snippets',
		'delete_published_leastudios_snippets',
		'delete_others_leastudios_snippets',
		'create_leastudios_snippets',
	];

	/**
	 * Available auto-insert locations.
	 *
	 * @var array<string, string>
	 */
	public const LOCATIONS = [
		'everywhere'         => 'Run Everywhere (PHP only)',
		'wp_head'            => 'Site Header (<head>)',
		'wp_footer'          => 'Site Footer',
		'wp_body_open'       => 'After <body> Tag',
		'the_content_before' => 'Before Post Content',
		'the_content_after'  => 'After Post Content',
		'admin_head'         => 'Admin Header',
		'admin_footer'       => 'Admin Footer',
		'login_head'         => 'Login Page Header',
		'custom_hook'        => 'Custom Hook (specify below)',
		'shortcode'          => 'Shortcode Only',
		'frontend_header'    => 'Frontend Header Only',
		'frontend_footer'    => 'Frontend Footer Only',
	];

	/**
	 * Gate every snippet capability on `manage_options`.
	 *
	 * WordPress derives a family of primitive and meta capabilities from
	 * `capability_type = 'leastudios_snippet'`. Without this filter those
	 * primitive caps are not granted to any role, so nobody can edit
	 * snippets. Mapping them all to `manage_options` cleanly delegates the
	 * decision to the existing site-admin gate without polluting role caps.
	 *
	 * @param array<int, string> $caps The required primitive capabilities.
	 * @param string             $cap  The capability being checked.
	 * @return array<int, string>
	 */
	public static function map_capabilities( array $caps, string $cap ): array {
		if ( in_array( $cap, self::SNIPPET_CAPABILITIES, true ) ) {
			return [ 'manage_options' ];
		}

		return $caps;
	}

	/**
	 * Get available auto-insert locations.
	 *
	 * Applies the `leastudios_snippets_locations` filter so developers
	 * can add or modify available locations.
	 *
	 * @return array<string, string>
	 */
	public static function get_locations(): array {
		/**
		 * Filters the available auto-insert locations for snippets.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $locations Associative array of location slug => label.
		 */
		return (array) apply_filters( 'leastudios_snippets_locations', self::LOCATIONS );
	}

	/**
	 * Register the custom post type.
	 *
	 * @return void
	 */
	public static function register(): void {
		$labels = [
			'name'                  => _x( 'Snippets', 'Post type general name', 'leastudios-snippets' ),
			'singular_name'         => _x( 'Snippet', 'Post type singular name', 'leastudios-snippets' ),
			'menu_name'             => _x( 'Snippets', 'Admin Menu text', 'leastudios-snippets' ),
			'add_new'               => __( 'Add New Snippet', 'leastudios-snippets' ),
			'add_new_item'          => __( 'Add New Snippet', 'leastudios-snippets' ),
			'edit_item'             => __( 'Edit Snippet', 'leastudios-snippets' ),
			'new_item'              => __( 'New Snippet', 'leastudios-snippets' ),
			'view_item'             => __( 'View Snippet', 'leastudios-snippets' ),
			'search_items'          => __( 'Search Snippets', 'leastudios-snippets' ),
			'not_found'             => __( 'No snippets found.', 'leastudios-snippets' ),
			'not_found_in_trash'    => __( 'No snippets found in Trash.', 'leastudios-snippets' ),
			'all_items'             => __( 'All Snippets', 'leastudios-snippets' ),
			'archives'              => __( 'Snippet Archives', 'leastudios-snippets' ),
			'attributes'            => __( 'Snippet Attributes', 'leastudios-snippets' ),
			'insert_into_item'      => __( 'Insert into snippet', 'leastudios-snippets' ),
			'uploaded_to_this_item' => __( 'Uploaded to this snippet', 'leastudios-snippets' ),
		];

		$args = [
			'labels'              => $labels,
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'leastudios-snippets',
			'show_in_rest'        => false,
			'supports'            => [ 'title', 'revisions' ],
			// Custom capability_type so that publishing/editing snippets
			// does not inherit from the generic `post` cap family. The
			// {@see map_capabilities} filter routes all derived caps to
			// `manage_options`.
			'capability_type'     => [ 'leastudios_snippet', 'leastudios_snippets' ],
			'map_meta_cap'        => true,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => true,
			'delete_with_user'    => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
		];

		/**
		 * Filters the custom post type registration arguments.
		 *
		 * @since 1.0.0
		 *
		 * @param array $args The post type registration arguments.
		 */
		$args = (array) apply_filters( 'leastudios_snippets_post_type_args', $args );

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register post meta fields for the snippet post type.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		$meta_fields = [
			self::META_CODE        => [
				'type'              => 'string',
				'description'       => __( 'The snippet code.', 'leastudios-snippets' ),
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => null, // Raw code; sanitized on output.
				'auth_callback'     => function () {
					return current_user_can( 'manage_options' );
				},
			],
			self::META_TYPE        => [
				'type'              => 'string',
				'description'       => __( 'The snippet language type.', 'leastudios-snippets' ),
				'single'            => true,
				'default'           => 'php',
				'sanitize_callback' => [ __CLASS__, 'sanitize_type' ],
				'auth_callback'     => function () {
					return current_user_can( 'manage_options' );
				},
			],
			self::META_LOCATION    => [
				'type'              => 'string',
				'description'       => __( 'The auto-insert location.', 'leastudios-snippets' ),
				'single'            => true,
				'default'           => 'everywhere',
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => function () {
					return current_user_can( 'manage_options' );
				},
			],
			self::META_ACTIVE      => [
				'type'              => 'string',
				'description'       => __( 'Whether the snippet is active.', 'leastudios-snippets' ),
				'single'            => true,
				'default'           => '0',
				'sanitize_callback' => [ __CLASS__, 'sanitize_active' ],
				'auth_callback'     => function () {
					return current_user_can( 'manage_options' );
				},
			],
			self::META_PRIORITY    => [
				'type'              => 'string',
				'description'       => __( 'Execution priority.', 'leastudios-snippets' ),
				'single'            => true,
				'default'           => '10',
				'sanitize_callback' => [ __CLASS__, 'sanitize_priority' ],
				'auth_callback'     => function () {
					return current_user_can( 'manage_options' );
				},
			],
			self::META_CONDITIONS  => [
				'type'              => 'string',
				'description'       => __( 'JSON-encoded conditions array.', 'leastudios-snippets' ),
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => [ __CLASS__, 'sanitize_conditions' ],
				'auth_callback'     => function () {
					return current_user_can( 'manage_options' );
				},
			],
			self::META_CUSTOM_HOOK => [
				'type'              => 'string',
				'description'       => __( 'Custom hook name for the custom_hook location.', 'leastudios-snippets' ),
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => function () {
					return current_user_can( 'manage_options' );
				},
			],
		];

		foreach ( $meta_fields as $meta_key => $meta_args ) {
			register_post_meta( self::POST_TYPE, $meta_key, $meta_args );
		}
	}

	/**
	 * Sanitize the snippet type meta value.
	 *
	 * @param mixed $value The raw meta value.
	 * @return string
	 */
	public static function sanitize_type( mixed $value ): string {
		$allowed = [ 'php', 'js', 'css', 'html' ];

		return in_array( (string) $value, $allowed, true ) ? (string) $value : 'php';
	}

	/**
	 * Sanitize the active status meta value.
	 *
	 * @param mixed $value The raw meta value.
	 * @return string
	 */
	public static function sanitize_active( mixed $value ): string {
		return ( '1' === (string) $value ) ? '1' : '0';
	}

	/**
	 * Sanitize the priority meta value.
	 *
	 * @param mixed $value The raw meta value.
	 * @return string
	 */
	public static function sanitize_priority( mixed $value ): string {
		$int_value = (int) $value;

		return ( $int_value > 0 ) ? (string) $int_value : '10';
	}

	/**
	 * Sanitize the conditions JSON meta value.
	 *
	 * @param mixed $value The raw meta value.
	 * @return string
	 */
	public static function sanitize_conditions( mixed $value ): string {
		if ( empty( $value ) ) {
			return '';
		}

		// Validate that it is valid JSON.
		$decoded = json_decode( (string) $value, true );

		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$encoded = wp_json_encode( $decoded );
		return false !== $encoded ? $encoded : '';
	}
}
