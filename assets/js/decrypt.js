/**
 * Init Content Protector – Decrypt
 *
 * Decrypts the AES-256-CBC payloads printed by the_content filter and swaps
 * them in place of their loading skeleton.
 *
 * Engine: the browser's native Web Crypto API (crypto.subtle) by default —
 * hardware-accelerated and with no library to download. Browsers only
 * expose it in secure contexts (HTTPS/localhost), so on non-HTTPS sites PHP
 * enqueues the bundled CryptoJS up front, and on HTTPS sites CryptoJS is
 * lazy-loaded on demand only in the rare browser without Web Crypto.
 *
 * Payload sources (both supported):
 * 1. 1.7+: `.icp-protected` wrappers, each with its own
 *    `<script type="application/json" class="icp-payload">`. Only the wrapper
 *    is replaced, so theme elements inside the content container survive.
 * 2. Legacy (≤ 1.6, e.g. HTML still served from a page cache):
 *    `window.InitContentEncryptedPayload`, rendered into the Content Selector.
 *
 * Timing: the render still happens no earlier than `delay` ms after
 * DOMContentLoaded (anti-scraper measure, default 1000), but the headless
 * check, key retrieval and decryption now run *during* that wait instead of
 * after it. The key is only fetched, and content only decrypted, once the
 * optional headless check has passed — same guarantee as before.
 */
( function () {
	'use strict';

	var data = window.InitContentDecryptData;
	if ( ! data ) {
		return;
	}

	var DEFAULT_SELECTOR = '.entry-content';
	var PBKDF2_ITERATIONS = 999; // Must match hash_pbkdf2() in includes/utils.php.
	var LOG_PREFIX = '[Init Content Protector] ';

	var subtle = window.crypto && window.crypto.subtle;
	var hasNativeCrypto = !! ( subtle && window.TextEncoder && window.TextDecoder && window.Uint8Array );

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
	// by an optimization plugin), where 1.6 waited forever for an event that
	// had already fired.
	var domReady = new Promise( function ( resolve ) {
		if ( 'loading' !== document.readyState ) {
			resolve();
		} else {
			document.addEventListener( 'DOMContentLoaded', function () {
				resolve();
			} );
		}
	} );

	function hexToBytes( hex ) {
		var length = hex.length >>> 1;
		var bytes = new Uint8Array( length );
		for ( var i = 0; i < length; i++ ) {
			bytes[ i ] = parseInt( hex.substr( i * 2, 2 ), 16 );
		}
		return bytes;
	}

	function base64ToBytes( base64 ) {
		var binary = window.atob( base64 );
		var bytes = new Uint8Array( binary.length );
		for ( var i = 0; i < binary.length; i++ ) {
			bytes[ i ] = binary.charCodeAt( i );
		}
		return bytes;
	}

	function base64DecodeUnicode( base64 ) {
		if ( window.TextDecoder ) {
			return new TextDecoder( 'utf-8' ).decode( base64ToBytes( base64 ) );
		}
		return decodeURIComponent(
			Array.prototype.map.call( window.atob( base64 ), function ( c ) {
				return '%' + ( '00' + c.charCodeAt( 0 ).toString( 16 ) ).slice( -2 );
			} ).join( '' )
		);
	}

	function parsePayload( raw ) {
		var payload = 'string' === typeof raw ? JSON.parse( raw ) : raw;
		if ( ! payload || ! payload.ciphertext || ! payload.iv || ! payload.salt ) {
			throw new Error( 'Malformed payload' );
		}
		return payload;
	}

	function findContainer() {
		var selector = data.content_selector || DEFAULT_SELECTOR;
		try {
			return document.querySelector( selector );
		} catch ( e ) {
			// Invalid selector in settings — would have thrown and killed
			// the whole script in 1.6.
			log( 'warn', 'Content selector "' + selector + '" is not a valid CSS selector.' );
			return null;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Engines                                                              */
	/* ------------------------------------------------------------------ */

	var cryptoJsPromise = null;

	function loadCryptoJs() {
		if ( window.CryptoJS ) {
			return Promise.resolve( window.CryptoJS );
		}
		if ( cryptoJsPromise ) {
			return cryptoJsPromise;
		}
		cryptoJsPromise = new Promise( function ( resolve, reject ) {
			if ( ! data.cryptojs_url ) {
				reject( new Error( 'Web Crypto is unavailable and no CryptoJS fallback URL was provided.' ) );
				return;
			}
			var script = document.createElement( 'script' );
			script.src = data.cryptojs_url;
			script.async = true;
			script.onload = function () {
				if ( window.CryptoJS ) {
					resolve( window.CryptoJS );
				} else {
					reject( new Error( 'CryptoJS failed to initialise.' ) );
				}
			};
			script.onerror = function () {
				reject( new Error( 'CryptoJS failed to load.' ) );
			};
			( document.head || document.documentElement ).appendChild( script );
		} );
		return cryptoJsPromise;
	}

	// One PBKDF2 derivation per distinct salt. Since 1.7 every payload on a
	// page shares the same salt, so a page with several payloads derives once.
	var nativeKeys = {};

	function nativeDecrypt( passphrase, payload ) {
		var cacheKey = payload.salt;
		if ( ! nativeKeys[ cacheKey ] ) {
			nativeKeys[ cacheKey ] = subtle.importKey(
				'raw',
				new TextEncoder().encode( passphrase ),
				{ name: 'PBKDF2' },
				false,
				[ 'deriveKey' ]
			).then( function ( baseKey ) {
				return subtle.deriveKey(
					{
						name: 'PBKDF2',
						salt: hexToBytes( payload.salt ),
						iterations: PBKDF2_ITERATIONS,
						hash: 'SHA-512',
					},
					baseKey,
					{ name: 'AES-CBC', length: 256 },
					false,
					[ 'decrypt' ]
				);
			} );
		}

		return nativeKeys[ cacheKey ].then( function ( aesKey ) {
			return subtle.decrypt(
				{ name: 'AES-CBC', iv: hexToBytes( payload.iv ) },
				aesKey,
				base64ToBytes( payload.ciphertext )
			);
		} ).then( function ( plainBuffer ) {
			return new TextDecoder( 'utf-8' ).decode( plainBuffer );
		} );
	}

	function cryptoJsDecrypt( passphrase, payload ) {
		return loadCryptoJs().then( function ( CryptoJS ) {
			var key = CryptoJS.PBKDF2( passphrase, CryptoJS.enc.Hex.parse( payload.salt ), {
				hasher: CryptoJS.algo.SHA512,
				keySize: 256 / 32,
				iterations: PBKDF2_ITERATIONS,
			} );
			var decrypted = CryptoJS.AES.decrypt( payload.ciphertext, key, {
				iv: CryptoJS.enc.Hex.parse( payload.iv ),
			} );
			return decrypted.toString( CryptoJS.enc.Utf8 );
		} );
	}

	function decrypt( passphrase, payload ) {
		if ( ! hasNativeCrypto ) {
			return cryptoJsDecrypt( passphrase, payload );
		}
		return nativeDecrypt( passphrase, payload ).catch( function ( err ) {
			// Should not happen, but never leave a reader stuck on the
			// skeleton if the native engine misbehaves in some browser.
			log( 'warn', 'Web Crypto decryption failed, retrying with CryptoJS.', err );
			return cryptoJsDecrypt( passphrase, payload );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Key retrieval                                                        */
	/* ------------------------------------------------------------------ */

	function fetchKey( postId, credentials ) {
		return window.fetch( data.rest_url + '/' + postId, {
			credentials: credentials,
			headers: { 'X-WP-Nonce': data.nonce || '' },
		} ).then( function ( res ) {
			return res.ok ? res.json() : Promise.reject( res.status );
		} );
	}

	function getPassphrase( postId ) {
		if ( data.decryption_key ) {
			return Promise.resolve( base64DecodeUnicode( data.decryption_key ) );
		}

		if ( ! data.rest_url || ! postId || ! window.fetch ) {
			return Promise.reject( new Error( 'No decryption key source is configured.' ) );
		}

		// Retry without cookies on 403: a logged-in visitor served a cached
		// page gets a guest nonce, which WordPress rejects for a cookie
		// session. The key is not user-specific, so a guest request is fine.
		return fetchKey( postId, 'same-origin' ).catch( function ( status ) {
			if ( 403 === status ) {
				return fetchKey( postId, 'omit' );
			}
			return Promise.reject( status );
		} ).then( function ( json ) {
			if ( ! json || ! json.k ) {
				return Promise.reject( new Error( 'Empty key response.' ) );
			}
			return base64DecodeUnicode( json.k );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Jobs                                                                 */
	/* ------------------------------------------------------------------ */

	function collectJobs() {
		var jobs = [];
		var wrappers = document.querySelectorAll( '.icp-protected' );

		for ( var i = 0; i < wrappers.length; i++ ) {
			var wrapper = wrappers[ i ];
			if ( wrapper.getAttribute( 'data-icp-state' ) ) {
				continue;
			}
			var node = wrapper.querySelector( 'script.icp-payload' );
			if ( ! node ) {
				continue;
			}
			try {
				jobs.push( {
					wrapper: wrapper,
					postId: parseInt( wrapper.getAttribute( 'data-icp-id' ), 10 ) || 0,
					raw: node.textContent,
					payload: parsePayload( node.textContent ),
				} );
				wrapper.setAttribute( 'data-icp-state', 'pending' );
			} catch ( e ) {
				log( 'error', 'Unreadable encrypted payload.', e );
			}
		}

		if ( jobs.length ) {
			// Backward compatibility for custom code written against ≤ 1.6.
			if ( 'undefined' === typeof window.InitContentEncryptedPayload ) {
				window.InitContentEncryptedPayload = jobs[ 0 ].raw;
			}
			return jobs;
		}

		// Legacy HTML (≤ 1.6) still served from a page/CDN cache.
		if ( 'undefined' !== typeof window.InitContentEncryptedPayload ) {
			var container = findContainer();
			if ( ! container ) {
				log(
					'warn',
					'Content selector "' + ( data.content_selector || DEFAULT_SELECTOR ) + '" was not found on this page. ' +
					'Decryption was skipped, so this content will stay stuck on the loading skeleton for visitors. ' +
					'Check Settings → Init Content Protector → Content Selector.'
				);
				return jobs;
			}
			try {
				jobs.push( {
					container: container,
					postId: parseInt( data.post_id, 10 ) || 0,
					payload: parsePayload( window.InitContentEncryptedPayload ),
				} );
			} catch ( e ) {
				log( 'error', 'Unreadable encrypted payload.', e );
			}
		}

		return jobs;
	}

	function render( job, html ) {
		if ( job.container ) {
			job.container.innerHTML = html;
			return job.container;
		}

		var wrapper = job.wrapper;
		var parent = wrapper.parentNode;
		if ( ! parent ) {
			return null;
		}

		// Parsed through a <template> so the resulting nodes are identical to
		// setting innerHTML (inline scripts inside the content stay inert,
		// exactly like 1.6), then unwrapped so the DOM structure matches
		// unprotected content (e.g. `.entry-content > p` theme CSS still works).
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

		parent.replaceChild( fragment, wrapper );
		return parent;
	}

	function dispatch( name, detail ) {
		try {
			window.dispatchEvent( new CustomEvent( name, { detail: detail } ) );
		} catch ( e ) {
			// CustomEvent unsupported: nothing to notify.
		}
	}

	/* ------------------------------------------------------------------ */
	/* Main                                                                 */
	/* ------------------------------------------------------------------ */

	var delay = parseInt( data.delay, 10 );
	if ( isNaN( delay ) || delay < 0 ) {
		delay = 1000;
	}

	var renderGate = domReady.then( function () {
		return wait( delay );
	} );

	var headlessCheck = ( window.InitContentHeadlessCheck && 'function' === typeof window.InitContentHeadlessCheck.then )
		? window.InitContentHeadlessCheck
		: Promise.resolve( false );

	domReady.then( function () {
		var jobs = collectJobs();
		if ( ! jobs.length ) {
			return;
		}

		dispatch( 'init-content-payload-ready' );

		headlessCheck.then( function ( suspectedHeadless ) {
			if ( suspectedHeadless ) {
				log( 'warn', 'Headless/automation browser suspected — decryption withheld for this session.' );
				return;
			}

			// Start the CryptoJS download early when it will be needed.
			if ( ! hasNativeCrypto ) {
				loadCryptoJs().catch( function () {} );
			}

			getPassphrase( jobs[ 0 ].postId || parseInt( data.post_id, 10 ) ).then( function ( passphrase ) {
				var decrypted = jobs.map( function ( job ) {
					return decrypt( passphrase, job.payload ).then( function ( html ) {
						if ( ! html ) {
							// CryptoJS returns '' for a wrong key instead of throwing.
							return Promise.reject( new Error( 'Decryption produced no output (wrong key?).' ) );
						}
						return html;
					} );
				} );

				jobs.forEach( function ( job, index ) {
					Promise.all( [ decrypted[ index ], renderGate ] ).then( function ( results ) {
						var target = render( job, results[ 0 ] );
						if ( job.wrapper ) {
							job.wrapper.setAttribute( 'data-icp-state', 'done' );
						}
						dispatch( 'init-content-decrypted', { element: target } );
					} ).catch( function ( err ) {
						if ( job.wrapper ) {
							job.wrapper.setAttribute( 'data-icp-state', 'error' );
						}
						log( 'error', 'Decryption failed:', err );
					} );
				} );
			} ).catch( function ( err ) {
				log( 'error', 'Failed to fetch decryption key:', err );
			} );
		} );
	} );
}() );
