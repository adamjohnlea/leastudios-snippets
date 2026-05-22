<?php
/**
 * Library submenu page for browsing and installing pre-built snippets.
 *
 * @package LEAStudios\Snippets\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Admin;

defined( 'ABSPATH' ) || exit;

use LEAStudios\Snippets\CPT\Snippet_Post_Type;
use LEAStudios\Snippets\Library\Snippet_Library;
use LEAStudios\Snippets\Suite\Suite_Detector;

/**
 * Renders the snippet library page and handles snippet installation.
 */
class Library_Page {

	/**
	 * The snippet library instance.
	 *
	 * @var Snippet_Library
	 */
	private Snippet_Library $library;

	/**
	 * Constructor.
	 *
	 * @param Snippet_Library $library The snippet library instance.
	 */
	public function __construct( Snippet_Library $library ) {
		$this->library = $library;
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_submenu' ] );
		add_action( 'admin_post_leastudios_snippets_install_library', [ $this, 'handle_install' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register the Library submenu page.
	 *
	 * @return void
	 */
	public function register_submenu(): void {
		add_submenu_page(
			Snippets_Page::MENU_SLUG,
			__( 'Snippet Library', 'leastudios-snippets' ),
			__( 'Library', 'leastudios-snippets' ),
			'manage_options',
			'leastudios-snippets-library',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Enqueue assets for the library page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'snippets_page_leastudios-snippets-library' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'leastudios-snippets-admin',
			LEASTUDIOS_SNIPPETS_URL . 'assets/css/admin.css',
			[],
			LEASTUDIOS_SNIPPETS_VERSION
		);
	}

	/**
	 * Render the library page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$snippets   = $this->library->get_library_snippets();
		$categories = [
			'general' => __( 'General', 'leastudios-snippets' ),
			'mailer'  => __( 'leaStudios Mailer', 'leastudios-snippets' ),
			'forms'   => __( 'leaStudios Forms', 'leastudios-snippets' ),
		];

		/**
		 * Filters the library category labels.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $categories Category slug => label.
		 */
		$categories = (array) apply_filters( 'leastudios_snippets_library_categories', $categories );

		$plugin_labels    = [
			'leastudios-mailer' => __( 'leaStudios Mailer', 'leastudios-snippets' ),
			'leastudios-forms'  => __( 'leaStudios Forms', 'leastudios-snippets' ),
		];
		$editing_disabled = Snippet_Post_Type::is_editing_disabled();
		?>
		<div class="wrap leastudios-snippets-library-wrap">
			<h1><?php esc_html_e( 'Snippet Library', 'leastudios-snippets' ); ?></h1>
			<?php if ( $editing_disabled ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'Installing snippets is disabled by this site\'s configuration.', 'leastudios-snippets' ); ?></p>
				</div>
			<?php endif; ?>
			<p class="description">
				<?php esc_html_e( 'Browse and install pre-built code snippets. Installed snippets are inactive by default — review and activate them from the editor.', 'leastudios-snippets' ); ?>
			</p>

			<?php foreach ( $categories as $cat_slug => $cat_label ) : ?>
				<?php
				$cat_snippets = array_filter(
					$snippets,
					function ( array $s ) use ( $cat_slug ): bool {
						return ( $s['category'] ?? '' ) === $cat_slug;
					}
				);

				if ( empty( $cat_snippets ) ) {
					continue;
				}
				?>
				<h2 class="leastudios-snippets-library-category-heading"><?php echo esc_html( $cat_label ); ?></h2>

				<div class="leastudios-snippets-library-grid">
					<?php foreach ( $cat_snippets as $snippet ) : ?>
						<?php
						$requires     = $snippet['requires_plugin'];
						$is_available = null === $requires || Suite_Detector::is_active( $requires );
						$card_class   = 'leastudios-snippets-library-card';

						if ( ! $is_available ) {
							$card_class .= ' leastudios-snippets-library-card--unavailable';
						}
						?>
						<div class="<?php echo esc_attr( $card_class ); ?>">
							<div class="leastudios-snippets-library-card__header">
								<h3 class="leastudios-snippets-library-card__title">
									<?php echo esc_html( $snippet['title'] ); ?>
								</h3>
								<div class="leastudios-snippets-library-card__badges">
									<span class="leastudios-snippets-badge leastudios-snippets-badge--<?php echo esc_attr( $snippet['type'] ); ?>">
										<?php echo esc_html( strtoupper( $snippet['type'] ) ); ?>
									</span>
									<?php if ( null !== $requires ) : ?>
										<span class="leastudios-snippets-badge leastudios-snippets-badge--plugin">
											<?php echo esc_html( $plugin_labels[ $requires ] ?? $requires ); ?>
										</span>
									<?php endif; ?>
								</div>
							</div>

							<p class="leastudios-snippets-library-card__description">
								<?php echo esc_html( $snippet['description'] ); ?>
							</p>

							<div class="leastudios-snippets-library-card__footer">
								<?php if ( $editing_disabled ) : ?>
									<?php // Editing disabled — no install action. ?>
								<?php elseif ( $is_available ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="leastudios_snippets_install_library" />
										<input type="hidden" name="snippet_slug" value="<?php echo esc_attr( $snippet['slug'] ?? '' ); ?>" />
										<?php wp_nonce_field( 'leastudios_snippets_install_library', '_leastudios_snippets_library_nonce' ); ?>
										<button type="submit" class="button button-primary">
											<?php esc_html_e( 'Install', 'leastudios-snippets' ); ?>
										</button>
									</form>
								<?php else : ?>
									<span class="leastudios-snippets-library-card__requires">
										<?php
										printf(
											/* translators: %s: plugin name */
											esc_html__( 'Requires %s', 'leastudios-snippets' ),
											esc_html( $plugin_labels[ $requires ] ?? $requires )
										);
										?>
									</span>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Handle the install action from the library page.
	 *
	 * @return void
	 */
	public function handle_install(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'leastudios-snippets' ) );
		}

		if ( ! isset( $_POST['_leastudios_snippets_library_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_leastudios_snippets_library_nonce'] ) ), 'leastudios_snippets_install_library' )
		) {
			wp_die( esc_html__( 'Security check failed.', 'leastudios-snippets' ) );
		}

		if ( Snippet_Post_Type::is_editing_disabled() ) {
			wp_die(
				esc_html__(
					'Snippet installation is disabled by this site\'s configuration.',
					'leastudios-snippets'
				)
			);
		}

		$slug = isset( $_POST['snippet_slug'] ) ? sanitize_key( wp_unslash( (string) $_POST['snippet_slug'] ) ) : '';

		if ( '' === $slug ) {
			wp_die( esc_html__( 'Invalid snippet identifier.', 'leastudios-snippets' ) );
		}

		try {
			$post_id = $this->library->install_snippet( $slug );
		} catch ( \Exception $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}

		/**
		 * Fires after a library snippet has been installed via the admin UI.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $post_id The new snippet post ID.
		 * @param string $slug    The library snippet slug.
		 */
		do_action( 'leastudios_snippets_library_snippet_installed', $post_id, $slug );

		wp_safe_redirect(
			add_query_arg(
				[
					'action' => 'edit',
					'post'   => $post_id,
				],
				admin_url( 'post.php' )
			)
		);
		exit;
	}
}
