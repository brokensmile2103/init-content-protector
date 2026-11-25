<?php
/**
 * Request verification module (1.11).
 *
 * A small, stateless toolkit that other code can reuse to check that a REST
 * request comes from a real page view of this site before it is answered:
 *
 * 1. Same-site proof. Either the page's REST nonce or the browser's
 *    `Sec-Fetch-Site: same-origin` header, or a search engine crawler
 *    verified by reverse DNS (see verify_request()).
 * 2. A short-lived signed token with a minimum age. The client asks for the
 *    token first and can only redeem it after a short wait, so a request that
 *    fires both calls at once, or replays an old token, is refused.
 *
 * Nothing is stored: tokens are HMAC signed with the site's secret keys, so
 * there are no transients or options rows to clean up, and the module works
 * behind full-page caches. The matching browser module is
 * assets/js/verify.js (window.InitContentVerify).
 *
 * Like every other layer in this plugin it raises the cost of scraping; it is
 * not a captcha and cannot stop a script written specifically against it.
 *
 * @package Init_Content_Protector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minimum age (seconds) a verification token must reach before it is accepted.
 *
 * @since 1.11
 *
 * @return int
 */
function init_plugin_suite_content_protector_verify_min_age() {
	/**
	 * Filters how long a verification token must wait before it is accepted.
	 *
	 * @since 1.11
	 *
	 * @param int $seconds Seconds. Default 1. 0 accepts a token immediately.
	 */
	return max( 0, (int) apply_filters( 'init_plugin_suite_content_protector_verify_min_age', 1 ) );
}

/**
 * How long (seconds) a verification token stays valid after it is issued.
 *
 * @since 1.11
 *
 * @return int
 */
function init_plugin_suite_content_protector_verify_ttl() {
	/**
	 * Filters how long a verification token stays valid.
	 *
	 * @since 1.11
	 *
	 * @param int $seconds Seconds. Default 300.
	 */
	return max( 10, (int) apply_filters( 'init_plugin_suite_content_protector_verify_ttl', 300 ) );
}

/**
 * Sign the parts of a verification token.
 *
 * @since 1.11
 *
 * @param string     $scope      What the token is good for, e.g. 'content'.
 * @param int|string $subject    What it is bound to, e.g. a post ID.
 * @param int        $valid_from Unix time from which it is accepted.
 * @param int        $expires    Unix time after which it is refused.
 * @return string Hex HMAC.
 */
function init_plugin_suite_content_protector_verify_sign( $scope, $subject, $valid_from, $expires ) {
	$data = implode( '|', array( 'icp-verify', (string) $scope, (string) $subject, (int) $valid_from, (int) $expires ) );

	return hash_hmac( 'sha256', $data, wp_salt( 'nonce' ) );
}

/**
 * Issue a verification token.
 *
 * @since 1.11
 *
 * @param string     $scope   What the token is good for, e.g. 'content'.
 * @param int|string $subject What it is bound to, e.g. a post ID.
 * @return array{token: string, wait: int, expires: int} Token, seconds the client must wait before using it, and its expiry time.
 */
function init_plugin_suite_content_protector_verify_issue_token( $scope, $subject ) {
	$wait       = init_plugin_suite_content_protector_verify_min_age();
	$valid_from = time() + $wait;
	$expires    = $valid_from + init_plugin_suite_content_protector_verify_ttl();
	$signature  = init_plugin_suite_content_protector_verify_sign( $scope, $subject, $valid_from, $expires );

	return array(
		'token'   => $valid_from . '.' . $expires . '.' . $signature,
		'wait'    => $wait,
		'expires' => $expires,
	);
}

/**
 * Check a verification token.
 *
 * @since 1.11
 *
 * @param mixed      $token   Token as returned by issue_token().
 * @param string     $scope   Scope the token must have been issued for.
 * @param int|string $subject Subject the token must have been issued for.
 * @return true|WP_Error True when valid. The error code is `icp_token_invalid`,
 *                       `icp_token_early` or `icp_token_expired` (all HTTP 403).
 */
function init_plugin_suite_content_protector_verify_check_token( $token, $scope, $subject ) {
	$invalid = new WP_Error( 'icp_token_invalid', __( 'Invalid or expired request.', 'init-content-protector' ), array( 'status' => 403 ) );

	if ( ! is_string( $token ) || ! preg_match( '/^(\d{1,12})\.(\d{1,12})\.([0-9a-f]{64})$/', $token, $parts ) ) {
		return $invalid;
	}

	$valid_from = (int) $parts[1];
	$expires    = (int) $parts[2];

	if ( ! hash_equals( init_plugin_suite_content_protector_verify_sign( $scope, $subject, $valid_from, $expires ), $parts[3] ) ) {
		return $invalid;
	}

	$now = time();

	if ( $now < $valid_from ) {
		return new WP_Error( 'icp_token_early', __( 'Please try again in a moment.', 'init-content-protector' ), array( 'status' => 403 ) );
	}

	if ( $now > $expires ) {
		return new WP_Error( 'icp_token_expired', __( 'Invalid or expired request.', 'init-content-protector' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * Check that a REST request comes from a page of this site.
 *
 * Accepts a same-site request (valid nonce or `Sec-Fetch-Site: same-origin`),
 * and, when the crawler option is on, a search engine crawler verified by
 * reverse DNS. A crawler rendering a page from a full-page cache can carry an
 * expired nonce and may not send Sec-Fetch-Site, and would otherwise only
 * see the loading skeleton. DNS is only queried when the User-Agent claims
 * to be a crawler, and the result is cached (see crawler-verify.php).
 *
 * @since 1.11 Moved here from the key route (1.10).
 *
 * @param WP_REST_Request $request  Request.
 * @param array           $settings Settings array.
 * @return true|WP_Error
 */
function init_plugin_suite_content_protector_verify_request( WP_REST_Request $request, $settings ) {
	if ( init_plugin_suite_content_protector_rest_is_same_site( $request )
		|| ( init_plugin_suite_content_protector_is_enabled( $settings, 'headless_allow_crawlers' )
			&& '' !== init_plugin_suite_content_protector_verified_crawler() )
	) {
		return true;
	}

	return new WP_Error( 'icp_invalid_nonce', __( 'Invalid or expired request.', 'init-content-protector' ), array( 'status' => 403 ) );
}
