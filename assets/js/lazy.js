/**
 * Init Content Protector – Lazy Load
 *
 * Lazy Load mode sends the page without the article. For every
 * `.icp-protected[data-icp-mode="lazy"]` placeholder this script:
 *
 * 1. waits for the optional headless browser check (withheld sessions get
 *    nothing),
 * 2. asks the server for a short-lived token and waits the time it demands,
 * 3. fetches the content with that token and puts it in place of the
 *    placeholder.
 *
 * If a step fails the placeholder shows a message with a Retry button. All
 * REST work is done by the shared module, assets/js/verify.js.
 *
 * Events (same as Encrypt mode, so scripts that wait for the real text work
 * in both):
 * - `init-content-payload-ready` (window): placeholders were found.
 * - `init-content-decrypted`: content was inserted. Dispatched on the element
 *   that now holds it and bubbles. `event.detail.element`,
 *   `event.detail.postId` and `event.detail.mode` ('lazy').
 *   Never fired when verification fails or nothing was inserted.
 */
( function () {
	'use strict';

	var V = window.InitContentVerify;
	if ( ! V ) {
		return;
	}

	// Margin on top of the wait the server asks for.
	var WAIT_MARGIN_MS = 150;

	function fail( code ) {
		var error = new Error( code );
		error.code = code;
		return error;
	}

	function load( wrapper ) {
		var postId = parseInt( wrapper.getAttribute( 'data-icp-id' ), 10 ) || 0;
		var page = parseInt( wrapper.getAttribute( 'data-icp-page' ), 10 ) || 1;

		if ( ! postId ) {
			return;
		}

		V.setState( wrapper, 'pending' );

		V.gate().then( function ( allowed ) {
			if ( ! allowed ) {
				V.log( 'warn', 'Headless/automation browser suspected — content withheld for this session.' );
				throw fail( 'icp_blocked' );
			}
			return V.getToken( postId );
		} ).then( function ( verification ) {
			return V.wait( verification.wait * 1000 + WAIT_MARGIN_MS ).then( function () {
				return V.request( 'content/' + postId, { params: { token: verification.token, page: page } } );
			} );
		} ).then( function ( json ) {
			if ( ! json || 'string' !== typeof json.html ) {
				throw fail( 'icp_bad_response' );
			}

			var target = V.render( wrapper, json.html, json.css );
			if ( ! target ) {
				// The placeholder was removed from the page (e.g. by a theme
				// script) before the content arrived: nothing was inserted
				// and nothing is announced.
				throw fail( 'icp_removed' );
			}

			V.dispatch( 'init-content-decrypted', { element: target, postId: postId, mode: 'lazy' }, target );
		} ).catch( function ( error ) {
			V.log( 'error', 'Lazy load failed:', error );

			if ( 'icp_removed' === error.code || ! wrapper.parentNode ) {
				return;
			}

			var kind = V.errorKind( error );
			V.showNotice( wrapper, kind, function () {
				load( wrapper );
			} );
		} );
	}

	V.ready.then( function () {
		var wrappers = document.querySelectorAll( '.icp-protected[data-icp-mode="lazy"]' );
		var pending = [];

		for ( var i = 0; i < wrappers.length; i++ ) {
			if ( ! wrappers[ i ].getAttribute( 'data-icp-state' ) ) {
				pending.push( wrappers[ i ] );
			}
		}

		if ( ! pending.length ) {
			return;
		}

		V.dispatch( 'init-content-payload-ready', { mode: 'lazy' } );

		pending.forEach( load );
	} );
}() );
