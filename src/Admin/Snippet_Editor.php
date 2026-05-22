<?php
/**
 * Snippet editor metaboxes and save handler.
 *
 * @package LEAStudios\Snippets\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Admin;

defined( 'ABSPATH' ) || exit;

use LEAStudios\Snippets\CPT\Snippet_Post_Type;

/**
 * Registers metaboxes on the snippet edit screen and handles saving.
 */
class Snippet_Editor {

	/**
	 * Nonce action for saving snippets.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'leastudios_snippets_save_snippet';

	/**
	 * Nonce field name.
	 *
	 * @var string
	 */
	private const NONCE_FIELD = '_leastudios_snippets_nonce';

	/**
	 * Maximum allowed snippet code size in bytes.
	 */
	private const MAX_CODE_BYTES = 262144;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'add_meta_boxes_' . Snippet_Post_Type::POST_TYPE, [ $this, 'register_metaboxes' ] );
		add_action( 'save_post_' . Snippet_Post_Type::POST_TYPE, [ $this, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ $this, 'render_oversize_notice' ] );
		add_action( 'admin_notices', [ $this, 'render_editing_disabled_notice' ] );
	}

	/**
	 * Register metaboxes for the snippet edit screen.
	 *
	 * @return void
	 */
	public function register_metaboxes(): void {
		add_meta_box(
			'leastudios-snippets-code-editor',
			__( 'Code Editor', 'leastudios-snippets' ),
			[ $this, 'render_code_editor' ],
			Snippet_Post_Type::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'leastudios-snippets-insert-location',
			__( 'Insert Location', 'leastudios-snippets' ),
			[ $this, 'render_insert_location' ],
			Snippet_Post_Type::POST_TYPE,
			'side',
			'default'
		);

		add_meta_box(
			'leastudios-snippets-conditions',
			__( 'Conditions', 'leastudios-snippets' ),
			[ $this, 'render_conditions' ],
			Snippet_Post_Type::POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Render the Code Editor metabox.
	 *
	 * @param \WP_Post $post The current post object.
	 * @return void
	 */
	public function render_code_editor( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$code     = get_post_meta( $post->ID, Snippet_Post_Type::META_CODE, true );
		$type_raw = get_post_meta( $post->ID, Snippet_Post_Type::META_TYPE, true );
		$type     = ( '' !== $type_raw && false !== $type_raw ) ? $type_raw : 'php';
		$active   = get_post_meta( $post->ID, Snippet_Post_Type::META_ACTIVE, true );
		$types    = [
			'php'  => __( 'PHP', 'leastudios-snippets' ),
			'js'   => __( 'JavaScript', 'leastudios-snippets' ),
			'css'  => __( 'CSS', 'leastudios-snippets' ),
			'html' => __( 'HTML', 'leastudios-snippets' ),
		];

		/**
		 * Filters the available snippet types.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $types Associative array of type slug => label.
		 */
		$types = (array) apply_filters( 'leastudios_snippets_types', $types );
		?>
		<div class="leastudios-snippets-editor-wrap">
			<div class="leastudios-snippets-type-row">
				<label for="leastudios-snippets-type">
					<?php esc_html_e( 'Snippet Type:', 'leastudios-snippets' ); ?>
				</label>
				<select id="leastudios-snippets-type" name="leastudios_snippets_type">
					<?php foreach ( $types as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div id="leastudios-snippets-php-warning" class="leastudios-snippets-warning"<?php echo 'php' !== $type ? ' style="display:none;"' : ''; ?>>
				<span class="dashicons dashicons-warning"></span>
				<p>
					<?php esc_html_e( 'PHP snippets execute server-side code. Only add code you understand and trust. Errors can break your site.', 'leastudios-snippets' ); ?>
				</p>
			</div>

			<div class="leastudios-snippets-codemirror-wrap">
				<textarea
					id="leastudios-snippets-code"
					name="leastudios_snippets_code"
					rows="15"
				><?php echo esc_textarea( (string) $code ); ?></textarea>
			</div>

			<div class="leastudios-snippets-active-row">
				<label for="leastudios-snippets-active">
					<input
						type="checkbox"
						id="leastudios-snippets-active"
						name="leastudios_snippets_active"
						value="1"
						<?php checked( $active, '1' ); ?>
					/>
					<?php esc_html_e( 'Enable this snippet', 'leastudios-snippets' ); ?>
				</label>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Insert Location metabox.
	 *
	 * @param \WP_Post $post The current post object.
	 * @return void
	 */
	public function render_insert_location( \WP_Post $post ): void {
		$location_raw = get_post_meta( $post->ID, Snippet_Post_Type::META_LOCATION, true );
		$location     = ( '' !== $location_raw && false !== $location_raw ) ? $location_raw : 'everywhere';
		$custom_hook  = get_post_meta( $post->ID, Snippet_Post_Type::META_CUSTOM_HOOK, true );
		$priority_raw = get_post_meta( $post->ID, Snippet_Post_Type::META_PRIORITY, true );
		$priority     = ( '' !== $priority_raw && false !== $priority_raw ) ? $priority_raw : '10';
		$locations    = Snippet_Post_Type::get_locations();
		?>
		<div class="leastudios-snippets-location-wrap">
			<p>
				<label for="leastudios-snippets-location">
					<?php esc_html_e( 'Location:', 'leastudios-snippets' ); ?>
				</label>
				<select id="leastudios-snippets-location" name="leastudios_snippets_location" class="widefat">
					<?php foreach ( $locations as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $location, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p id="leastudios-snippets-custom-hook-wrap"<?php echo 'custom_hook' !== $location ? ' style="display:none;"' : ''; ?>>
				<label for="leastudios-snippets-custom-hook">
					<?php esc_html_e( 'Custom Hook Name:', 'leastudios-snippets' ); ?>
				</label>
				<input
					type="text"
					id="leastudios-snippets-custom-hook"
					name="leastudios_snippets_custom_hook"
					value="<?php echo esc_attr( (string) $custom_hook ); ?>"
					class="widefat"
					placeholder="<?php esc_attr_e( 'e.g. my_custom_action', 'leastudios-snippets' ); ?>"
				/>
			</p>

			<p>
				<label for="leastudios-snippets-priority">
					<?php esc_html_e( 'Priority:', 'leastudios-snippets' ); ?>
				</label>
				<input
					type="number"
					id="leastudios-snippets-priority"
					name="leastudios_snippets_priority"
					value="<?php echo esc_attr( $priority ); ?>"
					min="1"
					max="999"
					step="1"
					class="widefat"
				/>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the Conditions metabox.
	 *
	 * @param \WP_Post $post The current post object.
	 * @return void
	 */
	public function render_conditions( \WP_Post $post ): void {
		$conditions_json = get_post_meta( $post->ID, Snippet_Post_Type::META_CONDITIONS, true );
		$conditions      = ! empty( $conditions_json ) ? json_decode( $conditions_json, true ) : [];

		if ( ! is_array( $conditions ) ) {
			$conditions = [];
		}

		$condition_types = $this->get_condition_types();
		?>
		<div class="leastudios-snippets-conditions-wrap">
			<div id="leastudios-snippets-conditions-rows">
				<?php if ( ! empty( $conditions ) ) : ?>
					<?php foreach ( $conditions as $index => $condition ) : ?>
						<div class="leastudios-snippets-condition-row" data-index="<?php echo esc_attr( (string) $index ); ?>">
							<select class="leastudios-snippets-condition-type" name="leastudios_snippets_conditions[<?php echo esc_attr( (string) $index ); ?>][type]">
								<?php foreach ( $condition_types as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $condition['type'] ?? '', $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>

							<select class="leastudios-snippets-condition-operator" name="leastudios_snippets_conditions[<?php echo esc_attr( (string) $index ); ?>][operator]">
								<option value="is" <?php selected( $condition['operator'] ?? '', 'is' ); ?>>
									<?php esc_html_e( 'is', 'leastudios-snippets' ); ?>
								</option>
								<option value="is_not" <?php selected( $condition['operator'] ?? '', 'is_not' ); ?>>
									<?php esc_html_e( 'is not', 'leastudios-snippets' ); ?>
								</option>
							</select>

							<input
								type="text"
								class="leastudios-snippets-condition-value"
								name="leastudios_snippets_conditions[<?php echo esc_attr( (string) $index ); ?>][value]"
								value="<?php echo esc_attr( $condition['value'] ?? '' ); ?>"
								placeholder="<?php esc_attr_e( 'Value', 'leastudios-snippets' ); ?>"
							/>

							<button type="button" class="button leastudios-snippets-remove-condition" aria-label="<?php esc_attr_e( 'Remove condition', 'leastudios-snippets' ); ?>">
								<span class="dashicons dashicons-no-alt"></span>
							</button>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<p>
				<button type="button" class="button" id="leastudios-snippets-add-condition">
					<?php esc_html_e( 'Add Condition', 'leastudios-snippets' ); ?>
				</button>
			</p>

			<textarea
				id="leastudios-snippets-conditions-data"
				name="leastudios_snippets_conditions_json"
				style="display:none;"
			><?php echo esc_textarea( ( '' !== $conditions_json && false !== $conditions_json ) ? $conditions_json : '[]' ); ?></textarea>

		</div>
		<?php
	}

	/**
	 * Save snippet meta data.
	 *
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 * @return void
	 */
	public function save( int $post_id, \WP_Post $post ): void {
		// Verify nonce.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
		) {
			return;
		}

		// Skip autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		/**
		 * Fires before snippet meta is saved.
		 *
		 * @since 1.0.0
		 *
		 * @param int      $post_id The snippet post ID.
		 * @param \WP_Post $post    The snippet post object.
		 */
		do_action( 'leastudios_snippets_before_save', $post_id, $post );

		// Save code — use wp_unslash but do NOT sanitize_text_field (would break code).
		// Cap at 256 KB so a hostile or run-away client cannot blow up post_meta
		// or the eval() path. Real-world snippets are kilobytes at most.
		if ( isset( $_POST['leastudios_snippets_code'] ) ) {
			$code = wp_unslash( $_POST['leastudios_snippets_code'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_string( $code ) ) {
				if ( strlen( $code ) <= self::MAX_CODE_BYTES ) {
					update_post_meta( $post_id, Snippet_Post_Type::META_CODE, $code );
				} else {
					// Oversized code is not saved; flag it so the editor can
					// tell the user instead of failing silently.
					set_transient( 'leastudios_snippets_oversize_' . $post_id, true, MINUTE_IN_SECONDS );
				}
			}
		}

		// Save type.
		if ( isset( $_POST['leastudios_snippets_type'] ) ) {
			$type = sanitize_text_field( wp_unslash( $_POST['leastudios_snippets_type'] ) );
			update_post_meta( $post_id, Snippet_Post_Type::META_TYPE, $type );
		}

		// Save active status.
		$active = isset( $_POST['leastudios_snippets_active'] ) ? '1' : '0';
		update_post_meta( $post_id, Snippet_Post_Type::META_ACTIVE, $active );

		// Save location.
		if ( isset( $_POST['leastudios_snippets_location'] ) ) {
			$location = sanitize_text_field( wp_unslash( $_POST['leastudios_snippets_location'] ) );
			update_post_meta( $post_id, Snippet_Post_Type::META_LOCATION, $location );
		}

		// Save custom hook name.
		if ( isset( $_POST['leastudios_snippets_custom_hook'] ) ) {
			$custom_hook = sanitize_text_field( wp_unslash( $_POST['leastudios_snippets_custom_hook'] ) );
			update_post_meta( $post_id, Snippet_Post_Type::META_CUSTOM_HOOK, $custom_hook );
		}

		// Save priority.
		if ( isset( $_POST['leastudios_snippets_priority'] ) ) {
			$priority = absint( wp_unslash( $_POST['leastudios_snippets_priority'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$priority = max( 1, min( $priority, 999 ) );
			update_post_meta( $post_id, Snippet_Post_Type::META_PRIORITY, (string) $priority );
		}

		// Save conditions from hidden JSON textarea.
		if ( isset( $_POST['leastudios_snippets_conditions_json'] ) ) {
			$conditions_raw = wp_unslash( $_POST['leastudios_snippets_conditions_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$conditions     = json_decode( $conditions_raw, true );

			// Re-encode to ensure clean JSON is stored.
			$conditions_json = is_array( $conditions ) ? (string) wp_json_encode( $conditions ) : '[]';
			update_post_meta( $post_id, Snippet_Post_Type::META_CONDITIONS, $conditions_json );
		}

		/**
		 * Fires after snippet meta is saved.
		 *
		 * @since 1.0.0
		 *
		 * @param int      $post_id The snippet post ID.
		 * @param \WP_Post $post    The snippet post object.
		 */
		do_action( 'leastudios_snippets_after_save', $post_id, $post );
	}

	/**
	 * Enqueue admin assets on the snippet edit screen.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		$screen = get_current_screen();

		if ( ! $screen || Snippet_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		if ( ! in_array( $hook_suffix, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		// Determine current snippet type for CodeMirror mode.
		$post             = get_post();
		$snippet_type     = $post ? get_post_meta( $post->ID, Snippet_Post_Type::META_TYPE, true ) : 'php';
		$snippet_type     = '' !== $snippet_type ? $snippet_type : 'php';
		$codemirror_types = [
			'php'  => 'application/x-httpd-php',
			'js'   => 'text/javascript',
			'css'  => 'text/css',
			'html' => 'text/html',
		];
		$cm_type          = $codemirror_types[ $snippet_type ] ?? 'application/x-httpd-php';

		// Use wp_enqueue_code_editor which loads CodeMirror + all required modes.
		$editor_settings = wp_enqueue_code_editor( [ 'type' => $cm_type ] );

		// Custom admin assets.
		wp_enqueue_style(
			'leastudios-snippets-admin',
			LEASTUDIOS_SNIPPETS_URL . 'assets/css/admin.css',
			[],
			LEASTUDIOS_SNIPPETS_VERSION
		);

		wp_enqueue_script(
			'leastudios-snippets-admin',
			LEASTUDIOS_SNIPPETS_URL . 'assets/js/admin.js',
			[ 'jquery', 'wp-theme-plugin-editor' ],
			LEASTUDIOS_SNIPPETS_VERSION,
			true
		);

		wp_localize_script(
			'leastudios-snippets-admin',
			'leastudiosSnippetsAdmin',
			[
				'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
				'editorSettings' => false !== $editor_settings ? $editor_settings : [],
				'conditionTypes' => $this->get_condition_types(),
			]
		);
	}

	/**
	 * Show a notice when a snippet's code was rejected for exceeding the size limit.
	 *
	 * @return void
	 */
	public function render_oversize_notice(): void {
		$screen = get_current_screen();

		if ( ! $screen || Snippet_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! get_transient( 'leastudios_snippets_oversize_' . $post->ID ) ) {
			return;
		}

		delete_transient( 'leastudios_snippets_oversize_' . $post->ID );

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__(
				'leaStudios Snippets: the snippet code exceeded the 256 KB limit and was not saved.',
				'leastudios-snippets'
			)
		);
	}

	/**
	 * Show a notice on snippet screens when editing is disabled site-wide.
	 *
	 * @return void
	 */
	public function render_editing_disabled_notice(): void {
		if ( ! Snippet_Post_Type::is_editing_disabled() ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || Snippet_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html__(
				'Snippet creation and editing are disabled by this site\'s configuration. Existing snippets continue to run.',
				'leastudios-snippets'
			)
		);
	}

	/**
	 * Single source of truth for the snippet condition-type map.
	 *
	 * Used both by the meta-box renderer and by the JS-side editor that
	 * builds the condition-row UI. Both call sites previously duplicated
	 * the literal array and the filter, drifting whenever new types were
	 * added.
	 *
	 * @return array<string, string>
	 */
	private function get_condition_types(): array {
		$condition_types = [
			'page_type'    => __( 'Page Type', 'leastudios-snippets' ),
			'user_logged'  => __( 'User Logged In', 'leastudios-snippets' ),
			'user_role'    => __( 'User Role', 'leastudios-snippets' ),
			'post_type'    => __( 'Post Type', 'leastudios-snippets' ),
			'page_post_id' => __( 'Page/Post ID', 'leastudios-snippets' ),
		];

		/**
		 * Filters the available condition types for snippets.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $condition_types Condition slug => label.
		 */
		return (array) apply_filters( 'leastudios_snippets_condition_types', $condition_types );
	}
}
