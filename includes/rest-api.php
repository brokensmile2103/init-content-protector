<?php
/**
 * REST API — decryption key delivery for the opt-in "Enhanced" mode.
 *
 * In the default "inline" mode the key is base64'd into page HTML. In "rest"
 * mode decrypt.js fetches it from this route after page load (and only after
 * the optional headless check passes). This does NOT make the key secret —
 * any browser that runs the page's JavaScript gets it — but it keeps the key
 * out of page source and cached HTML, so plain wget/curl scrapers get nothing.
 *
 * Deliberately NOT rate-limited: per-IP transients create one wp_options row
 * per visitor/bot IP, which bloats the database far worse than the scraping
 * this aims to deter. Rate limiting belongs at the server/CDN/WAF layer.
 *
 * @package Init_Content_Protector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the REST route.
 *
 * @return void
 */
function init_plugin_suite_content_protector_register_rest_routes() {
	register_rest_route(
		'init-content-protector/v1',
		'/key/(?P<post_id>\d+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'init_plugin_suite_content_protector_rest_get_key',
			'permission_callback' => '__return_true',
			'args'                => array(
				'post_id' => array(
					'sanitize_callback' => 'absint',
					'validate_callback' => static function ( $param ) {
						return is_numeric( $param ) && (int) $param > 0;
					},
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'init_plugin_suite_content_protector_register_rest_routes' );

/**
 * Whether the request is a legitimate same-site request from a page.
 *
 * A valid nonce is accepted as before. Because nonces expire after 12–24
 * hours, a page served from a full-page cache eventually carries a stale
 * one; in 1.6 that left every visitor of such a page stuck on the loading
 * skeleton. Browsers attach `Sec-Fetch-Site: same-origin` to the page's own
 * fetch() call, so that header is accepted as an equivalent same-site proof.
 * Neither is a secret (a guest nonce is shared by all guests and printed in
 * the page), so this adds no weakness — it only removes the expiry failure.
 *
 * @param WP_REST_Request $request Request.
 * @return bool
 */
function init_plugin_suite_content_protector_rest_is_same_site( WP_REST_Request $request ) {
	$nonce = $request->get_header( 'X-WP-Nonce' );
	if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return true;
	}

	return 'same-origin' === $request->get_header( 'Sec-Fetch-Site' );
}

/**
 * Return the decryption key for a protected, viewable post.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function init_plugin_suite_content_protector_rest_get_key( WP_REST_Request $request ) {
	if ( ! init_plugin_suite_content_protector_rest_is_same_site( $request ) ) {
		return new WP_Error( 'icp_invalid_nonce', __( 'Invalid or expired request.', 'init-content-protector' ), array( 'status' => 403 ) );
	}

	$settings = init_plugin_suite_content_protector_get_settings();

	$rest_mode = isset( $settings['encrypt_delivery'] ) && 'rest' === $settings['encrypt_delivery'];
	if ( ! init_plugin_suite_content_protector_is_encrypt_mode( $settings ) || ! $rest_mode ) {
		return new WP_Error( 'icp_rest_delivery_disabled', __( 'Not available.', 'init-content-protector' ), array( 'status' => 404 ) );
	}

	$post = get_post( (int) $request->get_param( 'post_id' ) );

	// Public posts, plus private/draft posts the current user may read
	// (1.6 only accepted "publish", so private posts never decrypted).
	$viewable = $post && ( is_post_publicly_viewable( $post ) || current_user_can( 'read_post', $post->ID ) );
	if ( ! $viewable ) {
		return new WP_Error( 'icp_invalid_post', __( 'Not found.', 'init-content-protector' ), array( 'status' => 404 ) );
	}

	if ( ! init_plugin_suite_content_protector_is_protected_post( $post, $settings ) ) {
		return new WP_Error( 'icp_not_protected', __( 'Not found.', 'init-content-protector' ), array( 'status' => 404 ) );
	}

	return rest_ensure_response(
		array(
			'k' => base64_encode( init_plugin_suite_content_protector_get_passphrase( $settings ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Same transport format as inline delivery.
		)
	);
}
