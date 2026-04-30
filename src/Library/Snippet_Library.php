<?php
/**
 * Pre-built snippet library.
 *
 * @package LEAStudios\Snippets\Library
 */

declare(strict_types=1);

namespace LEAStudios\Snippets\Library;

defined( 'ABSPATH' ) || exit;

use LEAStudios\Snippets\CPT\Snippet_Post_Type;
use LEAStudios\Snippets\Suite\Suite_Detector;

/**
 * Provides a library of pre-built snippet definitions.
 */
class Snippet_Library {

	/**
	 * Get all library snippet definitions.
	 *
	 * @return array<int, array<string, mixed>> Array of snippet definitions.
	 */
	public function get_library_snippets(): array {
		$snippets = [
			// General — always available.
			[
				'slug'            => 'disable-admin-bar-non-admins',
				'title'           => __( 'Disable WordPress Admin Bar for Non-Admins', 'leastudios-snippets' ),
				'description'     => __( 'Hides the WordPress admin bar for all users who do not have the manage_options capability.', 'leastudios-snippets' ),
				'code'            => "if ( ! current_user_can( 'manage_options' ) ) {\n\tshow_admin_bar( false );\n}",
				'type'            => 'php',
				'location'        => 'everywhere',
				'priority'        => 10,
				'category'        => 'general',
				'requires_plugin' => null,
			],
			[
				'slug'            => 'custom-frontend-css',
				'title'           => __( 'Add Custom CSS to Frontend', 'leastudios-snippets' ),
				'description'     => __( 'Adds custom CSS styles to your site front-end. Edit the CSS below to suit your needs.', 'leastudios-snippets' ),
				'code'            => "/* Add your custom CSS here */\nbody {\n\t/* Example: font-family: 'Inter', sans-serif; */\n}",
				'type'            => 'css',
				'location'        => 'wp_head',
				'priority'        => 10,
				'category'        => 'general',
				'requires_plugin' => null,
			],
			[
				'slug'            => 'google-analytics-gtm',
				'title'           => __( 'Google Analytics / GTM Script', 'leastudios-snippets' ),
				'description'     => __( 'Inserts Google Analytics or Google Tag Manager tracking script into your site header. Replace GA_TRACKING_ID with your actual ID.', 'leastudios-snippets' ),
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Example snippet code, not actual enqueue.
				'code'            => "<!-- Replace GA_TRACKING_ID with your real Google Analytics or GTM ID before activating this snippet. -->\n<!-- Google tag (gtag.js) -->\n<script async src=\"https://www.googletagmanager.com/gtag/js?id=GA_TRACKING_ID\"></script>\n<script>\n\twindow.dataLayer = window.dataLayer || [];\n\tfunction gtag(){dataLayer.push(arguments);}\n\tgtag('js', new Date());\n\tgtag('config', 'GA_TRACKING_ID');\n</script>",
				'type'            => 'html',
				'location'        => 'wp_head',
				'priority'        => 5,
				'category'        => 'general',
				'requires_plugin' => null,
			],
			[
				'slug'            => 'disable-xmlrpc',
				'title'           => __( 'Disable XML-RPC', 'leastudios-snippets' ),
				'description'     => __( 'Disables the XML-RPC interface to reduce attack surface. Note: this may break some plugins that rely on XML-RPC.', 'leastudios-snippets' ),
				'code'            => "add_filter( 'xmlrpc_enabled', '__return_false' );",
				'type'            => 'php',
				'location'        => 'everywhere',
				'priority'        => 10,
				'category'        => 'general',
				'requires_plugin' => null,
			],
			[
				'slug'            => 'remove-wp-version-meta',
				'title'           => __( 'Remove WordPress Version from Head', 'leastudios-snippets' ),
				'description'     => __( 'Removes the WordPress version meta tag from the site head for improved security.', 'leastudios-snippets' ),
				'code'            => "remove_action( 'wp_head', 'wp_generator' );",
				'type'            => 'php',
				'location'        => 'everywhere',
				'priority'        => 10,
				'category'        => 'general',
				'requires_plugin' => null,
			],

			// leaStudios Mailer.
			[
				'slug'            => 'mailer-log-failed-emails',
				'title'           => __( 'Log Failed Emails to Error Log', 'leastudios-snippets' ),
				'description'     => __( 'Logs details of sent emails to the PHP error log via the leastudios_mailer_email_sent hook for debugging purposes.', 'leastudios-snippets' ),
				'code'            => "add_action( 'leastudios_mailer_email_sent', function ( \$result, \$to, \$subject ) {\n\tif ( is_wp_error( \$result ) ) {\n\t\terror_log( sprintf(\n\t\t\t'[leaStudios Mailer] Failed to send email to %s (Subject: %s). Error: %s',\n\t\t\tis_array( \$to ) ? implode( ', ', \$to ) : \$to,\n\t\t\t\$subject,\n\t\t\t\$result->get_error_message()\n\t\t) );\n\t}\n}, 10, 3 );",
				'type'            => 'php',
				'location'        => 'everywhere',
				'priority'        => 10,
				'category'        => 'mailer',
				'requires_plugin' => 'leastudios-mailer',
			],
			[
				'slug'            => 'mailer-skip-ses-dev',
				'title'           => __( 'Skip SES for Local/Dev Emails', 'leastudios-snippets' ),
				'description'     => __( 'Prevents leaStudios Mailer from intercepting emails on local or development environments by checking the site URL.', 'leastudios-snippets' ),
				'code'            => "add_filter( 'leastudios_mailer_should_intercept', function ( bool \$should_intercept ): bool {\n\t\$dev_domains = [ '.local', '.test', 'localhost', '.dev' ];\n\t\$site_url    = site_url();\n\n\tforeach ( \$dev_domains as \$domain ) {\n\t\tif ( str_contains( \$site_url, \$domain ) ) {\n\t\t\treturn false;\n\t\t}\n\t}\n\n\treturn \$should_intercept;\n} );",
				'type'            => 'php',
				'location'        => 'everywhere',
				'priority'        => 10,
				'category'        => 'mailer',
				'requires_plugin' => 'leastudios-mailer',
			],
			[
				'slug'            => 'mailer-ses-config-set',
				'title'           => __( 'Add Custom SES Configuration Set', 'leastudios-snippets' ),
				'description'     => __( 'Adds a custom SES configuration set name to outgoing emails for tracking and event monitoring.', 'leastudios-snippets' ),
				'code'            => "add_filter( 'leastudios_mailer_ses_request_body', function ( array \$body ): array {\n\t\$body['ConfigurationSetName'] = 'my-configuration-set';\n\n\treturn \$body;\n} );",
				'type'            => 'php',
				'location'        => 'everywhere',
				'priority'        => 10,
				'category'        => 'mailer',
				'requires_plugin' => 'leastudios-mailer',
			],

			// leaStudios Forms.
			[
				'slug'            => 'forms-block-disposable-email',
				'title'           => __( 'Block Disposable Email Domains', 'leastudios-snippets' ),
				'description'     => __( 'Rejects form submissions from known disposable email providers using the leastudios_forms_validation_errors hook.', 'leastudios-snippets' ),
				'code'            => "add_filter( 'leastudios_forms_validation_errors', function ( array \$errors, array \$data ): array {\n\t\$blocked_domains = [ 'mailinator.com', 'guerrillamail.com', 'tempmail.com', 'throwaway.email' ];\n\n\tforeach ( \$data as \$field ) {\n\t\tif ( ! empty( \$field ) && is_string( \$field ) && filter_var( \$field, FILTER_VALIDATE_EMAIL ) ) {\n\t\t\t\$domain = strtolower( substr( strrchr( \$field, '@' ), 1 ) );\n\t\t\tif ( in_array( \$domain, \$blocked_domains, true ) ) {\n\t\t\t\t\$errors[] = __( 'Please use a valid, non-disposable email address.', 'leastudios-snippets' );\n\t\t\t\tbreak;\n\t\t\t}\n\t\t}\n\t}\n\n\treturn \$errors;\n}, 10, 2 );",
				'type'            => 'php',
				'location'        => 'everywhere',
				'priority'        => 10,
				'category'        => 'forms',
				'requires_plugin' => 'leastudios-forms',
			],
			[
				'slug'            => 'forms-add-css-class',
				'title'           => __( 'Add CSS Class to All Forms', 'leastudios-snippets' ),
				'description'     => __( 'Adds a custom CSS class to every leaStudios form element for styling purposes.', 'leastudios-snippets' ),
				'code'            => "add_filter( 'leastudios_forms_form_attributes', function ( array \$attributes ): array {\n\t\$existing_classes     = \$attributes['class'] ?? '';\n\t\$attributes['class'] = trim( \$existing_classes . ' my-custom-form-class' );\n\n\treturn \$attributes;\n} );",
				'type'            => 'php',
				'location'        => 'everywhere',
				'priority'        => 10,
				'category'        => 'forms',
				'requires_plugin' => 'leastudios-forms',
			],
			[
				'slug'            => 'forms-external-webhook',
				'title'           => __( 'Send Submission Data to External Webhook', 'leastudios-snippets' ),
				'description'     => __( 'Sends form submission data to an external webhook URL after a successful submission.', 'leastudios-snippets' ),
				'code'            => "add_action( 'leastudios_forms_submission_created', function ( int \$submission_id, array \$data, int \$form_id ): void {\n\t\$webhook_url = 'https://example.com/webhook';\n\n\twp_remote_post( \$webhook_url, [\n\t\t'body'    => wp_json_encode( [\n\t\t\t'form_id'       => \$form_id,\n\t\t\t'submission_id' => \$submission_id,\n\t\t\t'data'          => \$data,\n\t\t\t'timestamp'     => current_time( 'c' ),\n\t\t] ),\n\t\t'headers' => [\n\t\t\t'Content-Type' => 'application/json',\n\t\t],\n\t\t'timeout' => 15,\n\t] );\n}, 10, 3 );",
				'type'            => 'php',
				'location'        => 'everywhere',
				'priority'        => 10,
				'category'        => 'forms',
				'requires_plugin' => 'leastudios-forms',
			],
			[
				'slug'            => 'forms-custom-thank-you',
				'title'           => __( 'Custom Thank You Message per Form', 'leastudios-snippets' ),
				'description'     => __( 'Customizes the submission response message based on which form was submitted.', 'leastudios-snippets' ),
				'code'            => "add_filter( 'leastudios_forms_submission_response', function ( array \$response, int \$form_id ): array {\n\t\$messages = [\n\t\t// Form ID => Custom message.\n\t\t1 => 'Thanks for contacting us! We\\'ll reply within 24 hours.',\n\t\t2 => 'Your application has been received. We\\'ll be in touch soon.',\n\t];\n\n\tif ( isset( \$messages[ \$form_id ] ) ) {\n\t\t\$response['message'] = \$messages[ \$form_id ];\n\t}\n\n\treturn \$response;\n}, 10, 2 );",
				'type'            => 'php',
				'location'        => 'everywhere',
				'priority'        => 10,
				'category'        => 'forms',
				'requires_plugin' => 'leastudios-forms',
			],
		];

		/**
		 * Filters the snippet library definitions.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array<string, mixed>> $snippets The library snippet definitions.
		 */
		return (array) apply_filters( 'leastudios_snippets_library_snippets', $snippets );
	}

	/**
	 * Get only available snippets (required plugins are active or null).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_available_snippets(): array {
		$all = $this->get_library_snippets();

		return array_values(
			array_filter(
				$all,
				function ( array $snippet ): bool {
					if ( null === $snippet['requires_plugin'] ) {
						return true;
					}

					return Suite_Detector::is_active( $snippet['requires_plugin'] );
				}
			)
		);
	}

	/**
	 * Install a library snippet as a new CPT post (inactive by default).
	 *
	 * Looks up the snippet by its locale-independent slug. The previous
	 * implementation matched on the translated title, which silently
	 * broke the install path on non-English sites.
	 *
	 * @param string $slug The library entry slug.
	 * @return int The created post ID.
	 *
	 * @throws \InvalidArgumentException If the snippet slug is not found.
	 * @throws \RuntimeException        If the post could not be created.
	 */
	public function install_snippet( string $slug ): int {
		$snippet = null;

		foreach ( $this->get_library_snippets() as $item ) {
			if ( ( $item['slug'] ?? '' ) === $slug ) {
				$snippet = $item;
				break;
			}
		}

		if ( null === $snippet ) {
			throw new \InvalidArgumentException(
				esc_html(
					sprintf(
						/* translators: %s: snippet slug */
						__( 'Library snippet "%s" not found.', 'leastudios-snippets' ),
						$slug
					)
				)
			);
		}

		/**
		 * Fires before a library snippet is installed.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $snippet The snippet definition being installed.
		 */
		do_action( 'leastudios_snippets_before_library_install', $snippet );

		$post_id = wp_insert_post(
			[
				'post_type'   => Snippet_Post_Type::POST_TYPE,
				'post_title'  => $snippet['title'],
				'post_status' => 'publish',
			]
		);

		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( esc_html( $post_id->get_error_message() ) );
		}

		update_post_meta( $post_id, Snippet_Post_Type::META_CODE, $snippet['code'] );
		update_post_meta( $post_id, Snippet_Post_Type::META_TYPE, $snippet['type'] );
		update_post_meta( $post_id, Snippet_Post_Type::META_LOCATION, $snippet['location'] );
		update_post_meta( $post_id, Snippet_Post_Type::META_PRIORITY, (string) $snippet['priority'] );
		update_post_meta( $post_id, Snippet_Post_Type::META_ACTIVE, '0' );

		/**
		 * Fires after a library snippet has been installed.
		 *
		 * @since 1.0.0
		 *
		 * @param int                  $post_id The new snippet post ID.
		 * @param array<string, mixed> $snippet The snippet definition that was installed.
		 */
		do_action( 'leastudios_snippets_after_library_install', $post_id, $snippet );

		return $post_id;
	}
}
