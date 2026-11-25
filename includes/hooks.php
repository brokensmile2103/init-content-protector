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
	if ( is_admin() || init_plugin_suite_content_protector_is_amp_endpoint() ) {
		return $content;
	}

	// 1.11: a feed gets the same message as in the_content_feed, so the
	// article is withheld even where a plugin builds feed items from
	// the_content itself.
	if ( is_feed() ) {
		return init_plugin_suite_content_protector_filter_feed_content( $content );
	}

	// 1.11: listings (home, archives, search) that print the full content
	// are protected like single views; 1.10 only protected single views.
	if ( ! is_singular() && ! init_plugin_suite_content_protector_is_front_listing() ) {
		return $content;
	}

	// The Lazy Load REST route runs the_content itself: never protect that.
	if ( init_plugin_suite_content_protector_lazy_rendering() ) {
		return $content;
	}

	global $post;
	if ( ! $post instanceof WP_Post ) {
		return $content;
	}

	if ( init_plugin_suite_content_protector_is_secondary_content_call() ) {
		return $content;
	}

	$settings = init_plugin_suite_content_protector_get_settings();

	if ( init_plugin_suite_content_protector_is_excluded_for_current_user( $settings )
		|| ! init_plugin_suite_content_protector_is_protected_post( $post, $settings )
	) {
		return $content;
	}

	/*
	 * Lazy Load (1.11): the article is left out of the HTML altogether. The
	 * placeholder is fetched and filled in by lazy.js once the browser has
	 * been verified. The filters above have already run, so shortcodes and
	 * blocks still enqueue their own assets on this page. A password form is
	 * the one thing that must stay in the page.
	 */
	if ( init_plugin_suite_content_protector_is_lazy_mode( $settings ) ) {
		if ( post_password_required( $post ) ) {
			return $content;
		}

		init_plugin_suite_content_protector_enqueue_lazy_assets( $settings );

		return init_plugin_suite_content_protector_render_placeholder(
			$post->ID,
			'',
			array(
				'data-icp-mode' => 'lazy',
				'data-icp-page' => (string) ( get_the_ID() === get_queried_object_id() ? max( 1, (int) get_query_var( 'page' ) ) : 1 ),
			)
		);
	}

	$has_keywords = ! empty( init_plugin_suite_content_protector_get_keywords( $settings ) );
	$has_noise    = init_plugin_suite_content_protector_is_enabled( $settings, 'inject_noise' );

	if ( $has_keywords ) {
		init_plugin_suite_content_protector_ensure_keyword_css( $post->ID, $settings );
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
 * Whether a front-end page that is not a single view is being rendered.
 *
 * True on the home page, archives, search results and other listings, once
 * the template is running. False for feeds, embeds, REST, AJAX and cron
 * requests, which have their own handling.
 *
 * @since 1.11
 *
 * @return bool
 */
function init_plugin_suite_content_protector_is_front_listing() {
	return did_action( 'template_redirect' )
		&& ! is_feed()
		&& ! is_embed()
		&& ! wp_doing_ajax()
		&& ! wp_doing_cron()
		&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
}

/**
 * Whether a feed must not carry the content of the current post.
 *
 * @since 1.11
 *
 * @param bool $auto_excerpt_only Only true when the excerpt is built from the article (no manual excerpt).
 * @return bool
 */
function init_plugin_suite_content_protector_hide_in_feed( $auto_excerpt_only = false ) {
	$post     = get_post();
	$settings = init_plugin_suite_content_protector_get_settings();

	if ( ! $post instanceof WP_Post
		|| ! init_plugin_suite_content_protector_is_gated_mode( $settings )
		|| ! init_plugin_suite_content_protector_is_protected_post( $post, $settings )
		|| post_password_required( $post )
		|| init_plugin_suite_content_protector_is_excluded_for_current_user( $settings )
		|| ( $auto_excerpt_only && has_excerpt( $post ) )
	) {
		return false;
	}

	/** This filter is documented in includes/rest-api.php */
	return (bool) apply_filters( 'init_plugin_suite_content_protector_rest_hide_content', true, $post, null );
}

/**
 * Message shown in syndication feeds instead of a protected article.
 *
 * @since 1.11
 *
 * @return string
 */
function init_plugin_suite_content_protector_feed_message() {
	return __( 'This content is protected and is only available on the website.', 'init-content-protector' );
}

/**
 * Replace the full text of a protected post in RSS/Atom feeds.
 *
 * @since 1.11
 *
 * @param string $content Feed content.
 * @return string
 */
function init_plugin_suite_content_protector_filter_feed_content( $content ) {
	if ( init_plugin_suite_content_protector_hide_in_feed() ) {
		return '<p>' . esc_html( init_plugin_suite_content_protector_feed_message() ) . '</p>';
	}

	return $content;
}
// Last in line, so a later filter cannot hand the article back.
add_filter( 'the_content_feed', 'init_plugin_suite_content_protector_filter_feed_content', PHP_INT_MAX, 1 );

/**
 * Replace the excerpt (feed <description> / Atom <summary>) in RSS/Atom feeds.
 *
 * A feed excerpt is the start of the article, or all of it when the post is
 * short, and the "Summary" feed setting still prints it. A manual excerpt is
 * replaced too; the rest_hide_content filter lets a post through.
 *
 * @since 1.11
 *
 * @param string $excerpt Feed excerpt.
 * @return string
 */
function init_plugin_suite_content_protector_filter_feed_excerpt( $excerpt ) {
	if ( init_plugin_suite_content_protector_hide_in_feed() ) {
		return init_plugin_suite_content_protector_feed_message();
	}

	return $excerpt;
}
add_filter( 'the_excerpt_rss', 'init_plugin_suite_content_protector_filter_feed_excerpt', PHP_INT_MAX, 1 );

/**
 * Same message when a feed reads the excerpt through get_the_excerpt().
 *
 * @since 1.11
 *
 * @param string $excerpt Excerpt.
 * @return string
 */
function init_plugin_suite_content_protector_filter_feed_get_excerpt( $excerpt ) {
	return is_feed() ? init_plugin_suite_content_protector_filter_feed_excerpt( $excerpt ) : $excerpt;
}
add_filter( 'get_the_excerpt', 'init_plugin_suite_content_protector_filter_feed_get_excerpt', PHP_INT_MAX, 1 );

/**
 * Whether the_content is being run to build text that is not the article
 * body shown to readers.
 *
 * - get_the_excerpt: WordPress builds an automatic excerpt by running
 *   the_content and stripping the tags. Encrypt mode turned that excerpt
 *   into the "Please enable JavaScript" notice, and Inject Noise added the
 *   hidden noise words to it, and cloaked keywords vanished from it. SEO
 *   plugins use that excerpt for the meta description and social cards.
 * - wp_head: SEO plugins that run the_content inside <head> (descriptions,
 *   structured data) had the same problem.
 *
 * The visible article body is protected exactly as before.
 *
 * @since 1.10
 *
 * @return bool
 */
function init_plugin_suite_content_protector_is_secondary_content_call() {
	$secondary = doing_filter( 'get_the_excerpt' ) || doing_action( 'wp_head' );

	/**
	 * Filters whether the current the_content call is left unprotected
	 * because it builds an excerpt or <head> metadata, not the article body.
	 *
	 * @since 1.10
	 *
	 * @param bool $secondary True inside get_the_excerpt or wp_head.
	 */
	return (bool) apply_filters( 'init_plugin_suite_content_protector_skip_secondary_content', $secondary );
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
	// The payload only contains base64/hex and JSON punctuation, and
	// wp_json_encode() escapes "/", so it cannot close the script element.
	$payload = '<script type="application/json" class="icp-payload">' . $encrypted_json . '</script>';

	return init_plugin_suite_content_protector_render_placeholder( $post_id, $payload );
}

/**
 * Render the loading placeholder shared by Encrypt and Lazy Load mode:
 * skeleton, noscript notice and the optional payload.
 *
 * @since 1.11
 *
 * @param int                  $post_id    Post ID.
 * @param string               $payload    Trusted markup placed after the notice (the encrypted payload), or ''.
 * @param array<string,string> $attributes Extra attributes of the wrapper, name => value.
 * @return string
 */
function init_plugin_suite_content_protector_render_placeholder( $post_id, $payload = '', array $attributes = array() ) {
	$skeleton = '<div class="imc-skeleton-line"></div>'
		. '<div class="imc-skeleton-line short"></div>'
		. '<div class="imc-skeleton-line"></div>'
		. '<div class="imc-skeleton-line"></div>'
		. '<div class="imc-skeleton-line short"></div>';

	$noscript = '<noscript><p class="icp-noscript">'
		. esc_html__( 'Please enable JavaScript in your browser to read this content.', 'init-content-protector' )
		. '</p></noscript>';

	$settings = init_plugin_suite_content_protector_get_settings();

	/**
	 * Filters the color scheme of the loading skeleton and noscript notice.
	 *
	 * 'light' (default look), 'dark', or 'auto' (follows the visitor's
	 * system setting through prefers-color-scheme). Useful for a theme with
	 * its own dark mode toggle, e.g. return 'dark' when the visitor's dark
	 * mode cookie is set. For full control, style the CSS custom properties
	 * documented in assets/css/style.css instead.
	 *
	 * @since 1.10
	 *
	 * @param string $scheme  'light', 'dark' or 'auto'.
	 * @param int    $post_id Post ID.
	 */
	$scheme = (string) apply_filters( 'init_plugin_suite_content_protector_skeleton_scheme', $settings['skeleton_scheme'], (int) $post_id );

	$class = 'icp-protected';
	if ( 'dark' === $scheme || 'auto' === $scheme ) {
		$class .= ' icp-scheme-' . $scheme;
	}

	$extra = '';
	foreach ( $attributes as $name => $value ) {
		$extra .= ' ' . sanitize_key( $name ) . '="' . esc_attr( $value ) . '"';
	}

	return '<div class="' . esc_attr( $class ) . '" data-icp-id="' . esc_attr( (string) (int) $post_id ) . '"' . $extra . '>'
		. $skeleton . $noscript . $payload
		. '</div>';
}

/**
 * Whether the Lazy Load route is rendering post content right now.
 *
 * Call with a boolean to switch the flag, without arguments to read it.
 *
 * @since 1.11
 *
 * @param bool|null $set New value, or null to only read.
 * @return bool
 */
function init_plugin_suite_content_protector_lazy_rendering( $set = null ) {
	static $active = false;

	if ( null !== $set ) {
		$active = (bool) $set;
	}

	return $active;
}

/**
 * Render the protected content of a post for the Lazy Load route.
 *
 * Runs the same pipeline as the post template (more tag, pagination,
 * the_content filters) inside a single-post query, then keyword cloaking and
 * noise injection. The CSS that goes with the cloaked keywords and the noise
 * is returned with the HTML, so a page served from a cache can never carry
 * rules that do not match the content (the noise class names rotate).
 *
 * @since 1.11
 *
 * @param WP_Post $post     Post.
 * @param int     $page     Page of a multi-page post (<!--nextpage-->), 1 for the first.
 * @param array   $settings Settings array.
 * @return array{html: string, css: string}
 */
function init_plugin_suite_content_protector_render_lazy_content( $post, $page, $settings ) {
	$saved_post  = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
	$saved_page  = isset( $GLOBALS['page'] ) ? $GLOBALS['page'] : null;
	$saved_more  = isset( $GLOBALS['more'] ) ? $GLOBALS['more'] : null;
	$saved_query = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;

	/*
	 * Run the post through a real single-post query, as the theme does: the
	 * more tag, pagination, conditional tags (is_singular() and friends) and
	 * the loop globals then behave exactly like on the post's own page, which
	 * shortcodes, blocks and other the_content filters rely on.
	 */
	$query = new WP_Query(
		array(
			'p'                   => $post->ID,
			'post_type'           => $post->post_type,
			'post_status'         => $post->post_status,
			'page'                => max( 1, (int) $page ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		)
	);

	$html = '';

	// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Template globals, restored below.
	$GLOBALS['wp_query'] = $query;
	init_plugin_suite_content_protector_lazy_rendering( true );

	if ( $query->have_posts() ) {
		$query->the_post();

		$html = apply_filters( 'the_content', get_the_content() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$html = str_replace( ']]>', ']]&gt;', (string) $html );
	}

	init_plugin_suite_content_protector_lazy_rendering( false );

	$GLOBALS['wp_query'] = $saved_query;
	$GLOBALS['post']     = $saved_post;
	if ( $saved_post instanceof WP_Post ) {
		setup_postdata( $saved_post );
	}
	$GLOBALS['page'] = $saved_page;
	$GLOBALS['more'] = $saved_more;
	// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited

	$has_keywords = ! empty( init_plugin_suite_content_protector_get_keywords( $settings ) );
	$has_noise    = init_plugin_suite_content_protector_is_enabled( $settings, 'inject_noise' );
	$css          = '';

	if ( $has_keywords ) {
		$css .= init_plugin_suite_content_protector_build_keyword_css( $post->ID, $settings );
	}

	if ( $has_noise ) {
		$profile = init_plugin_suite_content_protector_noise_profile( $post->ID );
		$css    .= $profile['css'];
	}

	return array(
		'html' => init_plugin_suite_content_protector_transform_text( $html, $post->ID, $has_keywords, $has_noise, $settings ),
		'css'  => $css,
	);
}
