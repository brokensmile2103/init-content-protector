/**
 * Init Content Protector – Browser verification module (1.11)
 *
 * The shared REST client behind Lazy Load mode and Enhanced key delivery.
 * It is exposed as `window.InitContentVerify`, so other scripts can reuse it:
 *
 * - request( path, options )  Same-site REST call. Sends the page's nonce and
 *                             transparently retries once as an anonymous
 *                             request when the nonce has expired (a page
 *                             served from a full-page cache). `path` is
 *                             relative to the plugin's REST root, or a full
 *                             URL. Options: method, params (query string for
 *                             GET, JSON body otherwise). Resolves with the
 *                             parsed JSON, rejects with an Error that has
 *                             `status` (HTTP) and `code` (REST error code).
 * - getToken( postId )        POST /verify. Resolves { token, wait }; wait the
 *                             given seconds before using the token.
 * - gate()                    Resolves true when the session may continue,
 *                             false when Headless Browser Detection withholds
 *                             it. Anything unexpected counts as false.
 * - wait( ms )                Promise timer.
 * - ready                     Promise resolved on DOMContentLoaded (also when
 *                             this script runs later).
 * - showNotice( el, kind, onRetry )
 *                             Shows a message in a protected wrapper. kind is
 *                             'failed' (with a Retry button when onRetry is
 *                             given), 'blocked' or 'gone'.
 * - clearNotice( el ), setState( el, state ), errorKind( err )
 * - render( el, html, css )   Replaces a wrapper with content, returns the
 *                             element that now holds it (or null).
 * - dispatch( name, detail, target )
 *
 * Server counterpart: includes/verify.php.
 */
( function () {
	'use strict';

	var data = window.InitContentVerifyData;
	if ( ! data ) {
		return;
	}

	var i18n = data.i18n || {};
	var LOG_PREFIX = '[Init Content Protector] ';

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	function log( level, message, extra ) {
		if ( ! data.debug || ! window.console || ! window.console[ level ] ) {
			return;
		}
		if ( undefined === extra ) {
			window.console[ level ]( LOG_PREFIX + message );
		} else {
			window.console[ level ]( LOG_PREFIX + message, extra );
		}
	}

	function wait( ms ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, ms );
		} );
	}

	// Works even when this script runs after DOMContentLoaded (e.g. delayed
	// by an optimization plugin).
	var ready = new Promise( function ( resolve ) {
		if ( 'loading' !== document.readyState ) {
			resolve();
		} else {
			document.addEventListener( 'DOMContentLoaded', function () {
				resolve();
			} );
		}
	} );

	function createError( status, code, message ) {
		var error = new Error( message || ( 'Request failed (' + status + ')' ) );
		error.status = status;
		error.code = code || '';
		return error;
	}

	// With plain permalinks the REST root is "?rest_route=/...", so the
	// query string of a route has to be appended with "&" instead of "?".
	function buildUrl( path, params ) {
		var url = /^(https?:)?\/\//.test( path ) ? path : data.root.replace( /\/+$/, '' ) + '/' + path.replace( /^\/+/, '' );
		var query = [];

		if ( params ) {
			Object.keys( params ).forEach( function ( key ) {
				query.push( encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] ) );
			} );
		}

		if ( ! query.length ) {
			return url;
		}

		return url + ( -1 === url.indexOf( '?' ) ? '?' : '&' ) + query.join( '&' );
	}

	/* ------------------------------------------------------------------ */
	/* REST                                                                 */
	/* ------------------------------------------------------------------ */

	function send( path, options, anonymous ) {
		var method = options.method || 'GET';
		var isGet = 'GET' === method;
		var headers = { Accept: 'application/json' };
		var init = {
			method: method,
			credentials: anonymous ? 'omit' : 'same-origin',
			cache: 'no-store',
			headers: headers
		};

		// An anonymous retry sends no nonce at all: WordPress answers an
		// expired nonce with 403 before the plugin sees the request, even
		// for a guest.
		if ( ! anonymous && data.nonce ) {
			headers[ 'X-WP-Nonce' ] = data.nonce;
		}

		if ( ! isGet && options.params ) {
			headers[ 'Content-Type' ] = 'application/json';
			init.body = JSON.stringify( options.params );
		}

		return window.fetch( buildUrl( path, isGet ? options.params : null ), init ).then( function ( res ) {
			if ( res.ok ) {
				return res.json();
			}
			return res.json().catch( function () {
				return {};
			} ).then( function ( body ) {
				throw createError( res.status, body && body.code, body && body.message );
			} );
		} );
	}

	function request( path, options ) {
		options = options || {};

		if ( ! window.fetch ) {
			return Promise.reject( createError( 0, 'icp_no_fetch', 'fetch() is not supported.' ) );
		}

		return send( path, options, false ).catch( function ( error ) {
			// A stale nonce (cached page) or a missing same-site proof.
			if ( error && 403 === error.status && ( 'rest_cookie_invalid_nonce' === error.code || 'icp_invalid_nonce' === error.code ) ) {
				log( 'warn', 'Request refused (' + error.code + '), retrying without nonce.' );
				return send( path, options, true );
			}
			throw error;
		} );
	}

	function getToken( postId ) {
		return request( 'verify', { method: 'POST', params: { post_id: postId } } ).then( function ( json ) {
			if ( ! json || ! json.token ) {
				throw createError( 0, 'icp_bad_response', 'Empty token response.' );
			}
			return { token: json.token, wait: Math.max( 0, parseInt( json.wait, 10 ) || 0 ) };
		} );
	}

	// window.InitContentHeadlessCheck exists only when Headless Browser
	// Detection is on. It resolves true for a suspected automation session.
	function gate() {
		var check = window.InitContentHeadlessCheck;
		if ( ! check || 'function' !== typeof check.then ) {
			return Promise.resolve( true );
		}
		return check.then( function ( suspected ) {
			return true !== suspected;
		}, function () {
			return false;
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Notice with Retry button                                             */
	/* ------------------------------------------------------------------ */

	function setState( wrapper, state ) {
		wrapper.setAttribute( 'data-icp-state', state );
		if ( 'pending' === state ) {
			wrapper.setAttribute( 'aria-busy', 'true' );
		} else {
			wrapper.removeAttribute( 'aria-busy' );
		}
	}

	function clearNotice( wrapper ) {
		var notice = wrapper.querySelector( '.icp-notice' );
		if ( notice && notice.parentNode ) {
			notice.parentNode.removeChild( notice );
		}
	}

	function errorKind( error ) {
		if ( error && 'icp_blocked' === error.code ) {
			return 'blocked';
		}
		if ( error && ( 404 === error.status || 'icp_password_required' === error.code ) ) {
			return 'gone';
		}
		return 'failed';
	}

	function showNotice( wrapper, kind, onRetry ) {
		clearNotice( wrapper );
		setState( wrapper, 'error' );

		var notice = document.createElement( 'div' );
		notice.className = 'icp-notice';
		notice.setAttribute( 'role', 'alert' );

		var text = document.createElement( 'p' );
		text.textContent = i18n[ kind ] || i18n.failed || '';
		notice.appendChild( text );

		if ( 'failed' === kind && 'function' === typeof onRetry ) {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'icp-retry';
			button.textContent = i18n.retry || 'Retry';
			button.addEventListener( 'click', function () {
				clearNotice( wrapper );
				setState( wrapper, 'pending' );
				onRetry();
			} );
			notice.appendChild( button );
		}

		wrapper.appendChild( notice );
	}

	/* ------------------------------------------------------------------ */
	/* Insert content                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Replace a protected wrapper with HTML.
	 *
	 * Parsed through a <template> so the resulting nodes are identical to
	 * setting innerHTML (inline scripts inside the content stay inert), then
	 * unwrapped so the DOM structure matches unprotected content (e.g.
	 * `.entry-content > p` theme CSS still works).
	 *
	 * @param {Element} wrapper The `.icp-protected` element.
	 * @param {string}  html    Content.
	 * @param {string}  css     Optional CSS that goes with the content.
	 * @return {Element|null} The element that now holds the content.
	 */
	function render( wrapper, html, css ) {
		var parent = wrapper.parentNode;
		if ( ! parent ) {
			return null;
		}

		var template = document.createElement( 'template' );
		var fragment;
		if ( 'content' in template ) {
			template.innerHTML = html;
			fragment = template.content;
		} else {
			var holder = document.createElement( 'div' );
			holder.innerHTML = html;
			fragment = document.createDocumentFragment();
			while ( holder.firstChild ) {
				fragment.appendChild( holder.firstChild );
			}
		}

		if ( css ) {
			var style = document.createElement( 'style' );
			style.setAttribute( 'data-icp-css', '1' );
			style.appendChild( document.createTextNode( css ) );
			( document.head || document.documentElement ).appendChild( style );
		}

		parent.replaceChild( fragment, wrapper );
		return parent;
	}

	/**
	 * Fire a CustomEvent on `target` (bubbling) when it is part of the page,
	 * on `window` otherwise.
	 *
	 * @param {string}  name   Event name.
	 * @param {Object}  detail Event detail.
	 * @param {Element} target Optional element the event originates from.
	 */
	function dispatch( name, detail, target ) {
		try {
			var attached = !! ( target && 1 === target.nodeType && document.documentElement.contains( target ) );
			var event = new CustomEvent( name, { detail: detail, bubbles: attached } );
			( attached ? target : window ).dispatchEvent( event );
		} catch ( e ) {
			// CustomEvent unsupported: nothing to notify.
		}
	}

	window.InitContentVerify = {
		version: '1.11',
		request: request,
		getToken: getToken,
		gate: gate,
		wait: wait,
		ready: ready,
		showNotice: showNotice,
		clearNotice: clearNotice,
		setState: setState,
		errorKind: errorKind,
		render: render,
		dispatch: dispatch,
		log: log
	};
}() );
