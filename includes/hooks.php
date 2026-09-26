<?php
/**
 * Front-end content filter.
 *
 * @package Init_Content_Protector
 */

defined( 'ABSPATH' ) || exit;

/*
 * Runs late (priority 99) so shortcodes, blocks, captions, wpautop and
 * wptexturize have all been applied first:
 *   8 wpautop · 9 do_blocks · 10 wptexturize · 11 do_shortcode
 *   12 wp_filter_content_tags · 99 this filter
 */
add_filter( 'the_content', 'init_plugin_suite_content_protector_filter_post_content', 99, 1 );

/**
 * Apply keyword cloaking, noise injection and encryption to post content.
 *
 * @param string $content Post content.
 * @return string
 */
function init_plugin_suite_content_protector_filter_post_content( $content ) {
	if ( is_admin() || ! is_singular() || init_plugin_suite_content_protector_is_amp_endpoint() ) {
		return $content;
	}

	global $post;
	if ( ! $post instanceof WP_Post ) {
		return $content;
	}

	$settings = init_plugin_suite_content_protector_get_settings();

	if ( init_plugin_suite_content_protector_is_excluded_for_current_user( $settings )
		|| ! init_plugin_suite_content_protector_is_protected_post( $post, $settings )
	) {
		return $content;
	}

	$has_keywords = ! empty( init_plugin_suite_content_protector_get_keywords( $settings ) );
	$has_noise    = init_plugin_suite_content_protector_is_enabled( $settings, 'inject_noise' );

	if ( $has_keywords ) {
		init_plugin_suite_content_protector_ensure_keyword_css( $post->ID, $settings );
	}

	if ( $has_noise ) {
		init_plugin_suite_content_protector_enqueue_noise_style();
	}

	$content = init_plugin_suite_content_protector_transform_text( $content, $post->ID, $has_keywords, $has_noise, $settings );

	if ( ! init_plugin_suite_content_protector_is_encrypt_mode( $settings ) ) {
		return $content;
	}

	/*
	 * Encrypted fresh on every call. 1.4–1.6 cached the ciphertext in a
	 * transient keyed only by post ID, which (a) served one visitor's
	 * version of the content to everyone for 12 hours when a membership or
	 * paywall plugin filters the_content differently per user, (b) ignored
	 * keyword/noise setting changes, and (c) cost two database queries per
	 * page view — more than the ~0.3 ms the encryption itself now takes,
	 * since the PBKDF2 key derivation is memoized (see derive_key()).
	 */
	$encrypted_json = init_plugin_suite_content_protector_encrypt( $content, init_plugin_suite_content_protector_get_passphrase( $settings ) );

	// Host lacks OpenSSL, or encryption failed: fail open so visitors still
	// get the content instead of a permanently broken page.
	if ( false === $encrypted_json ) {
		if ( current_user_can( 'manage_options' ) ) {
			return '<div class="icp-admin-notice uk-alert-danger">'
				. esc_html__( 'Init Content Protector: encryption is unavailable on this server (missing OpenSSL or PBKDF2 support). Showing unprotected content. This notice is only visible to administrators.', 'init-content-protector' )
				. '</div>' . $content;
		}
		return $content;
	}

	// Covers protected posts rendered outside their own singular view.
	init_plugin_suite_content_protector_enqueue_decrypt_assets( $settings, $post->ID );

	return init_plugin_suite_content_protector_render_encrypted_placeholder( $encrypted_json, $post->ID );
}

/**
 * Render the placeholder that decrypt.js replaces with the real content.
 *
 * The payload lives in a non-executable JSON script block inside its own
 * wrapper, instead of an inline script assigning a global. That means:
 * - Every call gets its own payload. 1.6 printed the payload only on the
 *   first the_content call of the request, so if an SEO/theme feature
 *   called the_content earlier (e.g. to build a description), the real
 *   content area got a skeleton with no payload and never decrypted.
 * - decrypt.js swaps exactly this wrapper, instead of wiping everything
 *   inside the Content Selector container (theme share buttons, pagination
 *   and related-post blocks placed there no longer disappear).
 * - It is not subject to Content-Security-Policy script-src rules.
 *
 * @param string $encrypted_json Encrypted payload (JSON).
 * @param int    $post_id        Post ID.
 * @return string
 */
function init_plugin_suite_content_protector_render_encrypted_placeholder( $encrypted_json, $post_id ) {
	$skeleton = '<div class="imc-skeleton-line"></div>'
		. '<div class="imc-skeleton-line short"></div>'
		. '<div class="imc-skeleton-line"></div>'
		. '<div class="imc-skeleton-line"></div>'
		. '<div class="imc-skeleton-line short"></div>';

	$noscript = '<noscript><p class="icp-noscript">'
		. esc_html__( 'Please enable JavaScript in your browser to read this content.', 'init-content-protector' )
		. '</p></noscript>';

	// The payload only contains base64/hex and JSON punctuation, and
	// wp_json_encode() escapes "/", so it cannot close the script element.
	$payload = '<script type="application/json" class="icp-payload">' . $encrypted_json . '</script>';

	return '<div class="icp-protected" data-icp-id="' . esc_attr( (string) (int) $post_id ) . '">'
		. $skeleton . $noscript . $payload
		. '</div>';
}
