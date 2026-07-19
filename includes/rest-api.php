<?php

defined( 'ABSPATH' ) || exit;

/**
 * REST API — decryption key delivery for the opt-in "Enhanced" encrypt_delivery mode
 *
 * Rationale: in the default "inline" mode, the decryption key is base64'd
 * directly into page HTML — trivial to read via view-source, no real
 * barrier. In "rest" mode, the key is fetched client-side (Vanilla JS,
 * `fetch()`) from this REST API route after page load instead. This does
 * NOT make the key secret (it's still handed to any browser that asks),
 * but it does mean the key never appears in page source / cached HTML, so
 * simple wget/curl scrapers get nothing without executing JS, and it works
 * cleanly with full-page cache plugins since the request is dynamic and
 * happens client-side after the cached HTML loads.
 *
 * This is a meaningful floor-raise for casual scraping, not a claim of
 * strong security. That limitation is documented in the settings UI.
 *
 * Deliberately NOT rate-limited: a transient-per-IP approach creates one
 * wp_options row per unique visitor/bot IP, and transients are NOT actively
 * garbage-collected by WordPress — they just sit in the DB until something
 * happens to read (and expire) that specific key, or a cleanup plugin/cron
 * sweeps them. On any site with meaningful traffic or bot scanning, that's
 * an unbounded, effectively-never-cleaned table bloat problem — worse for
 * the site than the scraping this endpoint is trying to mitigate. If real
 * rate limiting is needed, it belongs at the web server / WAF / CDN layer,
 * not in per-IP DB rows here.
 */

add_action( 'rest_api_init', 'init_plugin_suite_content_protector_register_rest_routes' );
function init_plugin_suite_content_protector_register_rest_routes() {
    register_rest_route( 'init-content-protector/v1', '/key/(?P<post_id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'init_plugin_suite_content_protector_rest_get_key',
        'permission_callback' => '__return_true',
        'args'                => [
            'post_id' => [
                'validate_callback' => function ( $param ) {
                    return is_numeric( $param );
                },
            ],
        ],
    ] );
}

function init_plugin_suite_content_protector_rest_get_key( WP_REST_Request $request ) {
    $nonce = $request->get_header( 'X-WP-Nonce' );
    if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
        return new WP_Error( 'icp_invalid_nonce', __( 'Invalid or expired request.', 'init-content-protector' ), [ 'status' => 403 ] );
    }

    $option = get_option( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION, [] );

    $encrypt_mode = ! empty( $option['content_mode'] ) && 'encrypt' === $option['content_mode'];
    $rest_mode    = ! empty( $option['encrypt_delivery'] ) && 'rest' === $option['encrypt_delivery'];
    if ( ! $encrypt_mode || ! $rest_mode ) {
        return new WP_Error( 'icp_rest_delivery_disabled', __( 'Not available.', 'init-content-protector' ), [ 'status' => 404 ] );
    }

    $post_id = (int) $request->get_param( 'post_id' );
    $post    = get_post( $post_id );
    if ( ! $post || 'publish' !== $post->post_status ) {
        return new WP_Error( 'icp_invalid_post', __( 'Not found.', 'init-content-protector' ), [ 'status' => 404 ] );
    }

    $allowed_post_types = $option['post_types'] ?? [];
    if ( ! in_array( $post->post_type, $allowed_post_types, true ) ) {
        return new WP_Error( 'icp_not_protected', __( 'Not found.', 'init-content-protector' ), [ 'status' => 404 ] );
    }

    // No per-IP rate limiting — see file header docblock for why.

    $key = ! empty( $option['encrypt_key'] ) ? $option['encrypt_key'] : INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ENCRYPT_KEY;

    return rest_ensure_response( [ 'k' => base64_encode( $key ) ] );
}
