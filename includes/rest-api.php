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
 * 1.11 also keeps the WordPress REST API (wp/v2) from handing out the
 * content of protected posts, see rest_prepare_post().
 *
 * 1.11 adds the routes of the "Lazy Load" mode: /verify (hands out a
 * short-lived token, see verify.php) and /content/<id> (returns the post
 * content for a valid token).
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
 * Register the crawler verification route (1.9).
 *
 * @return void
 */
function init_plugin_suite_content_protector_register_crawler_route() {
	register_rest_route(
		'init-content-protector/v1',
		'/crawler',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'init_plugin_suite_content_protector_rest_verify_crawler',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'init_plugin_suite_content_protector_register_crawler_route' );

/**
 * Register the routes of the "Lazy Load" mode (1.11).
 *
 * @return void
 */
function init_plugin_suite_content_protector_register_lazy_routes() {
	register_rest_route(
		'init-content-protector/v1',
		'/verify',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'init_plugin_suite_content_protector_rest_issue_token',
			'permission_callback' => '__return_true',
			'args'                => array(
				'post_id' => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
					'validate_callback' => static function ( $param ) {
						return is_numeric( $param ) && (int) $param > 0;
					},
				),
			),
		)
	);

	register_rest_route(
		'init-content-protector/v1',
		'/content/(?P<post_id>\d+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'init_plugin_suite_content_protector_rest_get_content',
			'permission_callback' => '__return_true',
			'args'                => array(
				'post_id' => array(
					'sanitize_callback' => 'absint',
					'validate_callback' => static function ( $param ) {
						return is_numeric( $param ) && (int) $param > 0;
					},
				),
				'token'   => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'page'    => array(
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'init_plugin_suite_content_protector_register_lazy_routes' );

/**
 * Hook every REST endpoint that renders post content (1.11).
 *
 * The core controllers (posts, pages, custom post types, revisions and
 * autosaves) return `content.rendered` and `excerpt.rendered` through their
 * own pipeline, where is_singular() is false and the_content is therefore
 * not protected. That would hand the article to any scraper that asks
 * /wp-json/wp/v2/posts/<id>, in every mode.
 *
 * @return void
 */
function init_plugin_suite_content_protector_register_rest_content_guard() {
	if ( ! init_plugin_suite_content_protector_is_gated_mode( init_plugin_suite_content_protector_get_settings() ) ) {
		return;
	}

	$types = array_merge( array_values( get_post_types( array( 'show_in_rest' => true ) ) ), array( 'revision', 'autosave' ) );

	foreach ( array_unique( $types ) as $type ) {
		add_filter( 'rest_prepare_' . $type, 'init_plugin_suite_content_protector_rest_prepare_post', 10, 3 );
	}
}
add_action( 'rest_api_init', 'init_plugin_suite_content_protector_register_rest_content_guard', 20 );

/**
 * Replace the rendered content of a protected post by a message.
 *
 * Only active while Encrypt or Lazy Load is on. Users who may edit the post,
 * and roles excluded in the settings, still get the real content. A manual
 * excerpt is left alone (the author wrote it to be public); an automatic one
 * is built from the article, so it is replaced.
 *
 * @since 1.11
 *
 * @param WP_REST_Response $response Response.
 * @param WP_Post          $post     Post, revision or autosave.
 * @param WP_REST_Request  $request  Request.
 * @return WP_REST_Response
 */
function init_plugin_suite_content_protector_rest_prepare_post( $response, $post, $request ) {
	$settings = init_plugin_suite_content_protector_get_settings();

	if ( ! $response instanceof WP_REST_Response || ! $post instanceof WP_Post
		|| ! init_plugin_suite_content_protector_is_gated_mode( $settings )
	) {
		return $response;
	}

	// A revision or autosave carries the article of its parent.
	$target = ( 'revision' === $post->post_type || 'autosave' === $post->post_type ) ? get_post( $post->post_parent ) : $post;

	if ( ! $target instanceof WP_Post
		|| ! init_plugin_suite_content_protector_is_protected_post( $target, $settings )
		|| post_password_required( $target )
		|| init_plugin_suite_content_protector_is_excluded_for_current_user( $settings )
		|| current_user_can( 'edit_post', $target->ID )
	) {
		return $response;
	}

	/**
	 * Filters whether the REST API or a feed hides the content of a protected post.
	 *
	 * @since 1.11
	 *
	 * @param bool                 $hide    Whether to hide it. Default true.
	 * @param WP_Post              $target  The protected post.
	 * @param WP_REST_Request|null $request Request, null in a feed.
	 */
	if ( ! apply_filters( 'init_plugin_suite_content_protector_rest_hide_content', true, $target, $request ) ) {
		return $response;
	}

	/**
	 * Filters the message that replaces the content in REST responses.
	 *
	 * @since 1.11
	 *
	 * @param string  $message Plain text message.
	 * @param WP_Post $target  The protected post.
	 */
	$message = (string) apply_filters(
		'init_plugin_suite_content_protector_rest_content_message',
		__( 'This content is protected and is not available through the REST API.', 'init-content-protector' ),
		$target
	);
	$html    = '<p>' . esc_html( $message ) . '</p>';

	$data = $response->get_data();

	if ( isset( $data['content'] ) && is_array( $data['content'] ) && array_key_exists( 'rendered', $data['content'] ) ) {
		$data['content']['rendered'] = $html;
	}

	if ( isset( $data['excerpt'] ) && is_array( $data['excerpt'] ) && array_key_exists( 'rendered', $data['excerpt'] ) && ! has_excerpt( $target ) ) {
		$data['excerpt']['rendered'] = $html;
	}

	$response->set_data( $data );

	return $response;
}

/**
 * Tell headless-detect.js whether the requester is a verified search engine
 * crawler (reverse DNS confirmed by forward DNS).
 *
 * Only answers about the caller's own IP address and User-Agent, so it reveals
 * nothing secret and needs no nonce. The answer depends on who asks, so it is
 * sent with no-store headers and works behind full-page caches and CDNs.
 *
 * @return WP_REST_Response
 */
function init_plugin_suite_content_protector_rest_verify_crawler() {
	$settings = init_plugin_suite_content_protector_get_settings();
	$verified = false;

	if ( init_plugin_suite_content_protector_is_gated_mode( $settings )
		&& init_plugin_suite_content_protector_is_enabled( $settings, 'headless_detect' )
		&& init_plugin_suite_content_protector_is_enabled( $settings, 'headless_allow_crawlers' )
	) {
		$verified = '' !== init_plugin_suite_content_protector_verified_crawler();
	}

	$response = rest_ensure_response( array( 'verified' => $verified ) );
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	$response->header( 'Vary', 'User-Agent' );

	return $response;
}

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
 * Load a post for a REST route and make sure it is protected and viewable.
 *
 * Public posts, plus private/draft posts the current user may read (1.6 only
 * accepted "publish", so private posts never decrypted).
 *
 * @since 1.11 Shared by the key and content routes.
 *
 * @param int   $post_id  Post ID.
 * @param array $settings Settings array.
 * @return WP_Post|WP_Error
 */
function init_plugin_suite_content_protector_rest_get_protected_post( $post_id, $settings ) {
	$post = get_post( (int) $post_id );

	$viewable = $post && ( is_post_publicly_viewable( $post ) || current_user_can( 'read_post', $post->ID ) );
	if ( ! $viewable ) {
		return new WP_Error( 'icp_invalid_post', __( 'Not found.', 'init-content-protector' ), array( 'status' => 404 ) );
	}

	if ( ! init_plugin_suite_content_protector_is_protected_post( $post, $settings ) ) {
		return new WP_Error( 'icp_not_protected', __( 'Not found.', 'init-content-protector' ), array( 'status' => 404 ) );
	}

	return $post;
}

/**
 * Mark a response as private: it depends on who asks and must never be
 * stored by a browser, CDN or page cache.
 *
 * @since 1.11
 *
 * @param WP_REST_Response $response Response.
 * @return WP_REST_Response
 */
function init_plugin_suite_content_protector_rest_no_store( $response ) {
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
	$response->header( 'Vary', 'Cookie, User-Agent' );

	return $response;
}

/**
 * Shared checks of the "Lazy Load" routes: same-site proof, mode, post.
 *
 * @since 1.11
 *
 * @param WP_REST_Request $request  Request.
 * @param array           $settings Settings array.
 * @return WP_Post|WP_Error
 */
function init_plugin_suite_content_protector_rest_lazy_post( WP_REST_Request $request, $settings ) {
	$verified = init_plugin_suite_content_protector_verify_request( $request, $settings );
	if ( is_wp_error( $verified ) ) {
		return $verified;
	}

	if ( ! init_plugin_suite_content_protector_is_lazy_mode( $settings ) ) {
		return new WP_Error( 'icp_lazy_disabled', __( 'Not available.', 'init-content-protector' ), array( 'status' => 404 ) );
	}

	$post = init_plugin_suite_content_protector_rest_get_protected_post( (int) $request->get_param( 'post_id' ), $settings );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	// The password form is shown on the page itself, never lazy loaded.
	if ( post_password_required( $post ) ) {
		return new WP_Error( 'icp_password_required', __( 'Not available.', 'init-content-protector' ), array( 'status' => 403 ) );
	}

	return $post;
}

/**
 * Hand out a verification token for a protected post (Lazy Load mode).
 *
 * @since 1.11
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function init_plugin_suite_content_protector_rest_issue_token( WP_REST_Request $request ) {
	$post = init_plugin_suite_content_protector_rest_lazy_post( $request, init_plugin_suite_content_protector_get_settings() );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$issued = init_plugin_suite_content_protector_verify_issue_token( 'content', $post->ID );

	return init_plugin_suite_content_protector_rest_no_store(
		rest_ensure_response(
			array(
				'token' => $issued['token'],
				'wait'  => $issued['wait'],
			)
		)
	);
}

/**
 * Return the rendered content of a protected post (Lazy Load mode).
 *
 * @since 1.11
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function init_plugin_suite_content_protector_rest_get_content( WP_REST_Request $request ) {
	$settings = init_plugin_suite_content_protector_get_settings();

	$post = init_plugin_suite_content_protector_rest_lazy_post( $request, $settings );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$checked = init_plugin_suite_content_protector_verify_check_token( $request->get_param( 'token' ), 'content', $post->ID );
	if ( is_wp_error( $checked ) ) {
		return $checked;
	}

	$rendered = init_plugin_suite_content_protector_render_lazy_content( $post, (int) $request->get_param( 'page' ), $settings );

	return init_plugin_suite_content_protector_rest_no_store(
		rest_ensure_response(
			array(
				'html'    => $rendered['html'],
				'css'     => $rendered['css'],
				'post_id' => $post->ID,
			)
		)
	);
}

/**
 * Return the decryption key for a protected, viewable post.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function init_plugin_suite_content_protector_rest_get_key( WP_REST_Request $request ) {
	$settings = init_plugin_suite_content_protector_get_settings();

	// 1.10: a verified search engine crawler is also accepted (1.11: the
	// check lives in verify.php so every route shares it).
	$verified = init_plugin_suite_content_protector_verify_request( $request, $settings );
	if ( is_wp_error( $verified ) ) {
		return $verified;
	}

	$rest_mode = isset( $settings['encrypt_delivery'] ) && 'rest' === $settings['encrypt_delivery'];
	if ( ! init_plugin_suite_content_protector_is_encrypt_mode( $settings ) || ! $rest_mode ) {
		return new WP_Error( 'icp_rest_delivery_disabled', __( 'Not available.', 'init-content-protector' ), array( 'status' => 404 ) );
	}

	$post = init_plugin_suite_content_protector_rest_get_protected_post( (int) $request->get_param( 'post_id' ), $settings );
	if ( is_wp_error( $post ) ) {
		return $post;
	}

	return rest_ensure_response(
		array(
			'k' => base64_encode( init_plugin_suite_content_protector_get_passphrase( $settings ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Same transport format as inline delivery.
		)
	);
}
