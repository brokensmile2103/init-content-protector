<?php
/**
 * Core helpers for Init Content Protector: settings access, request context
 * checks, encryption, keyword cloaking and noise injection.
 *
 * @package Init_Content_Protector
 */

defined( 'ABSPATH' ) || exit;

/*
 * ---------------------------------------------------------------------------
 * Settings & request context
 * ---------------------------------------------------------------------------
 */

/**
 * Default values for every stored setting.
 *
 * @return array<string, mixed>
 */
function init_plugin_suite_content_protector_default_settings() {
	return array(
		'post_types'       => array(),
		'content_mode'     => 'none',
		'encrypt_key'      => '',
		'encrypt_delivery' => 'inline',
		'headless_detect'  => '0',
		'content_selector' => '.entry-content',
		'js_protect'       => '0',
		'disable_devtool'  => '0',
		'antisnap'         => '0',
		'inject_noise'     => '0',
		'noise_rate'       => 7,
		'keywords'         => '',
		'excluded_roles'   => array(),
	);
}

/**
 * Get the plugin settings, always as a complete array.
 *
 * Merging with the defaults means callers never have to guard against a
 * missing key or a corrupted (non-array) option value.
 *
 * @return array<string, mixed>
 */
function init_plugin_suite_content_protector_get_settings() {
	$option = get_option( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION, array() );

	if ( ! is_array( $option ) ) {
		$option = array();
	}

	$settings = wp_parse_args( $option, init_plugin_suite_content_protector_default_settings() );

	$settings['post_types']     = is_array( $settings['post_types'] ) ? $settings['post_types'] : array();
	$settings['excluded_roles'] = is_array( $settings['excluded_roles'] ) ? $settings['excluded_roles'] : array();

	if ( '' === trim( (string) $settings['content_selector'] ) ) {
		$settings['content_selector'] = '.entry-content';
	}

	return $settings;
}

/**
 * Whether a boolean-style ('1' / '0') setting is switched on.
 *
 * @param array  $settings Settings array.
 * @param string $key      Setting key.
 * @return bool
 */
function init_plugin_suite_content_protector_is_enabled( $settings, $key ) {
	return isset( $settings[ $key ] ) && '1' === (string) $settings[ $key ];
}

/**
 * Whether Encrypt mode is active.
 *
 * @param array $settings Settings array.
 * @return bool
 */
function init_plugin_suite_content_protector_is_encrypt_mode( $settings ) {
	return isset( $settings['content_mode'] ) && 'encrypt' === $settings['content_mode'];
}

/**
 * Get the active encryption passphrase.
 *
 * @param array|null $settings Optional settings array.
 * @return string
 */
function init_plugin_suite_content_protector_get_passphrase( $settings = null ) {
	if ( null === $settings ) {
		$settings = init_plugin_suite_content_protector_get_settings();
	}

	return ! empty( $settings['encrypt_key'] ) ? (string) $settings['encrypt_key'] : INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ENCRYPT_KEY;
}

/**
 * Detect AMP endpoints (official AMP plugin and compatible plugins).
 *
 * Custom JS / inline scripts break AMP validation, so protection is skipped
 * there entirely rather than silently producing an invalid AMP page.
 *
 * @return bool
 */
function init_plugin_suite_content_protector_is_amp_endpoint() {
	if ( function_exists( 'amp_is_request' ) ) {
		return (bool) amp_is_request();
	}

	return function_exists( 'is_amp_endpoint' ) && is_amp_endpoint();
}

/**
 * Check if the current user should be excluded from every protection layer.
 *
 * The result is memoized per user for the current request, because it is
 * checked by every enqueue callback and by every content filter call.
 *
 * @param array|null $option Optional settings array.
 * @return bool
 */
function init_plugin_suite_content_protector_is_excluded_for_current_user( $option = null ) {
	static $cache = array();

	if ( ! is_user_logged_in() ) {
		return false;
	}

	if ( null === $option ) {
		$option = init_plugin_suite_content_protector_get_settings();
	}

	if ( empty( $option['excluded_roles'] ) ) {
		return false;
	}

	$excluded_roles = (array) $option['excluded_roles'];
	$cache_key      = get_current_user_id() . '|' . implode( ',', $excluded_roles );

	if ( isset( $cache[ $cache_key ] ) ) {
		return $cache[ $cache_key ];
	}

	$user = wp_get_current_user();
	if ( empty( $user->roles ) || ! is_array( $user->roles ) ) {
		$cache[ $cache_key ] = false;
		return false;
	}

	$cache[ $cache_key ] = (bool) array_intersect( $user->roles, $excluded_roles );

	return $cache[ $cache_key ];
}

/**
 * Whether front-end protection must be skipped entirely for this request.
 *
 * @param array $settings Settings array.
 * @return bool
 */
function init_plugin_suite_content_protector_should_skip_frontend( $settings ) {
	if ( is_admin() || init_plugin_suite_content_protector_is_amp_endpoint() ) {
		return true;
	}

	return init_plugin_suite_content_protector_is_excluded_for_current_user( $settings );
}

/**
 * Whether a given post belongs to a protected post type.
 *
 * @param WP_Post|null $post     Post object.
 * @param array        $settings Settings array.
 * @return bool
 */
function init_plugin_suite_content_protector_is_protected_post( $post, $settings ) {
	if ( ! $post instanceof WP_Post ) {
		return false;
	}

	return in_array( $post->post_type, (array) $settings['post_types'], true );
}

/**
 * Get the queried post when the current view is a protected singular view.
 *
 * Content filtering only ever happens on singular views, so assets that are
 * only useful for protected content (skeleton CSS, decryption scripts,
 * keyword CSS) are only needed there — not on the homepage or archives.
 *
 * @param array $settings Settings array.
 * @return WP_Post|null
 */
function init_plugin_suite_content_protector_get_protected_queried_post( $settings ) {
	if ( ! is_singular() ) {
		return null;
	}

	$post = get_queried_object();

	return init_plugin_suite_content_protector_is_protected_post( $post, $settings ) ? $post : null;
}

/*
 * ---------------------------------------------------------------------------
 * Encryption
 * ---------------------------------------------------------------------------
 */

/**
 * Check if this host has the required crypto functions available.
 *
 * Some low-end/shared hosts disable the OpenSSL extension. Without this guard
 * encrypt() would fatal with "Call to undefined function" on those hosts.
 * The cipher list lookup is memoized since it builds a large array.
 *
 * @return bool
 */
function init_plugin_suite_content_protector_crypto_available() {
	static $available = null;

	if ( null !== $available ) {
		return $available;
	}

	$available = function_exists( 'openssl_encrypt' )
		&& function_exists( 'openssl_get_cipher_methods' )
		&& function_exists( 'hash_pbkdf2' )
		&& function_exists( 'random_bytes' )
		&& in_array( 'aes-256-cbc', array_map( 'strtolower', openssl_get_cipher_methods() ), true );

	return $available;
}

/**
 * Derive (and memoize) the AES key for a passphrase.
 *
 * PBKDF2 is ~80% of the total encryption cost. The salt is derived from the
 * passphrase and this site's secret keys, so it is unique per site and per
 * passphrase but stable across requests. That lets the derived key be reused
 * for every encryption in the request, and across requests when a persistent
 * object cache is available — without any database writes. A fresh random IV
 * is still used for every single encryption.
 *
 * @param string $passphrase Passphrase.
 * @return array{0: string, 1: string} Binary salt and binary 32-byte key.
 */
function init_plugin_suite_content_protector_derive_key( $passphrase ) {
	static $memo = array();

	$salt      = hash_hmac( 'sha256', 'icp-salt|' . $passphrase, wp_salt( 'secure_auth' ), true );
	$cache_key = 'key_' . md5( $passphrase . '|' . $salt );

	if ( isset( $memo[ $cache_key ] ) ) {
		return array( $salt, $memo[ $cache_key ] );
	}

	$key = wp_cache_get( $cache_key, 'init_content_protector' );

	if ( ! is_string( $key ) || 32 !== strlen( $key ) ) {
		// 64 hex characters = 32 bytes = AES-256 key. Parameters (SHA-512,
		// 999 iterations, 32-byte key) must stay in sync with decrypt.js.
		$key = hex2bin( hash_pbkdf2( 'sha512', $passphrase, $salt, 999, 64 ) );
		wp_cache_set( $cache_key, $key, 'init_content_protector', DAY_IN_SECONDS );
	}

	$memo[ $cache_key ] = $key;

	return array( $salt, $key );
}

/**
 * Encrypt content using AES-256-CBC.
 *
 * The payload format ({ciphertext, iv, salt}) is unchanged since 1.0, so it
 * stays decryptable by every decrypt.js version, including one still served
 * from a stale CDN/page cache.
 *
 * @param string      $plain_text Plain text.
 * @param string|null $passphrase Optional passphrase. Defaults to the site key.
 * @return string|false JSON string on success, false if this host cannot encrypt.
 */
function init_plugin_suite_content_protector_encrypt( $plain_text, $passphrase = null ) {
	if ( ! init_plugin_suite_content_protector_crypto_available() ) {
		return false;
	}

	if ( null === $passphrase ) {
		$passphrase = init_plugin_suite_content_protector_get_passphrase();
	}

	try {
		list( $salt, $key ) = init_plugin_suite_content_protector_derive_key( (string) $passphrase );
		$iv                 = random_bytes( 16 );
	} catch ( Exception $e ) {
		return false;
	}

	$encrypted = openssl_encrypt( (string) $plain_text, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

	if ( false === $encrypted ) {
		return false;
	}

	return wp_json_encode(
		array(
			'ciphertext' => base64_encode( $encrypted ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext transport, not obfuscation.
			'iv'         => bin2hex( $iv ),
			'salt'       => bin2hex( $salt ),
		)
	);
}

/*
 * ---------------------------------------------------------------------------
 * HTML tokenizer shared by keyword cloaking and noise injection
 * ---------------------------------------------------------------------------
 */

/**
 * Split HTML into an ordered list of text runs and opaque markup tokens.
 *
 * Markup tokens are: comments, CDATA, whole blocks of elements whose content
 * must never be touched, and any other single tag. Everything else is a plain
 * text run between tags. Concatenating every token rebuilds the input
 * byte-for-byte.
 *
 * Elements kept opaque (entire content untouched):
 * - script/style/template: not reader-facing text; a stray span breaks them.
 * - pre/code/textarea: whitespace-sensitive.
 * - select/option/title/svg/math/noscript/iframe/object: inserting inline
 *   elements there is invalid markup and can break dropdowns or rendering.
 *
 * Block matching uses a backreference so `<svg>…<title>…</title>…</svg>`
 * is consumed as one block up to its own closing `</svg>`.
 *
 * @param string $html HTML.
 * @return array<int, array{0: bool, 1: string}>|false List of [is_text, chunk], or false on PCRE failure.
 */
function init_plugin_suite_content_protector_tokenize_html( $html ) {
	$pattern = '~<!--.*?-->|<!\[CDATA\[.*?\]\]>|<(script|style|template|pre|code|textarea|select|option|title|svg|math|noscript|iframe|object)\b[^>]*>.*?</\1\s*>|<[^>]+>~is';

	if ( false === preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
		return false;
	}

	$tokens = array();
	$cursor = 0;

	foreach ( $matches[0] as $match ) {
		list( $markup, $offset ) = $match;

		if ( $offset > $cursor ) {
			$tokens[] = array( true, substr( $html, $cursor, $offset - $cursor ) );
		}

		$tokens[] = array( false, $markup );
		$cursor   = $offset + strlen( $markup );
	}

	if ( $cursor < strlen( $html ) ) {
		$tokens[] = array( true, substr( $html, $cursor ) );
	}

	return $tokens;
}

/*
 * ---------------------------------------------------------------------------
 * Keyword cloaking
 * ---------------------------------------------------------------------------
 */

/**
 * Parse the comma-separated keyword setting into a unique list.
 *
 * @param array|null $settings Optional settings array.
 * @return string[]
 */
function init_plugin_suite_content_protector_get_keywords( $settings = null ) {
	if ( null === $settings ) {
		$settings = init_plugin_suite_content_protector_get_settings();
	}

	$raw = (string) $settings['keywords'];
	if ( '' === trim( $raw ) ) {
		return array();
	}

	$keywords = array_map( 'trim', explode( ',', $raw ) );
	$keywords = array_filter(
		$keywords,
		static function ( $keyword ) {
			return '' !== $keyword;
		}
	);

	return array_values( array_unique( $keywords ) );
}

/**
 * Generate the obfuscated class name for a keyword.
 *
 * The hash is case-sensitive so that "Naruto" and "naruto" get their own
 * class and CSS rule (previously they collided and one spelling rendered as
 * the other).
 *
 * @param string $keyword    Keyword.
 * @param int    $content_id Post ID used as a per-post salt.
 * @return string
 */
function init_plugin_suite_content_protector_keyword_to_class( $keyword, $content_id = 0 ) {
	$hash_keyword = substr( md5( trim( (string) $keyword ) ), 0, 8 );

	$hash_context = '';
	if ( $content_id > 0 ) {
		$hash_context = substr( md5( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_KEYWORD_SALT . $content_id ), 0, 5 );
	}

	return 'icp-' . $hash_context . '-' . $hash_keyword;
}

/**
 * Get the Unicode code point of a single UTF-8 character.
 *
 * @param string $char One UTF-8 character.
 * @return int
 */
function init_plugin_suite_content_protector_utf8_ord( $char ) {
	if ( function_exists( 'mb_ord' ) ) {
		return (int) mb_ord( $char, 'UTF-8' );
	}

	$bytes = array_values( unpack( 'C*', $char ) );
	$count = count( $bytes );

	if ( 1 === $count ) {
		return $bytes[0];
	}
	if ( 2 === $count ) {
		return ( ( $bytes[0] & 0x1F ) << 6 ) | ( $bytes[1] & 0x3F );
	}
	if ( 3 === $count ) {
		return ( ( $bytes[0] & 0x0F ) << 12 ) | ( ( $bytes[1] & 0x3F ) << 6 ) | ( $bytes[2] & 0x3F );
	}

	return ( ( $bytes[0] & 0x07 ) << 18 ) | ( ( $bytes[1] & 0x3F ) << 12 ) | ( ( $bytes[2] & 0x3F ) << 6 ) | ( $bytes[3] & 0x3F );
}

/**
 * Escape a string for use inside a double-quoted CSS `content` string.
 *
 * Every character other than letters, digits and spaces becomes a CSS hex
 * escape. This renders `&`, `"`, `<`, `\` etc. correctly (previously they
 * showed up as literal `&amp;`/`&quot;`) and makes a `</style>` breakout
 * impossible.
 *
 * @param string $text Text.
 * @return string
 */
function init_plugin_suite_content_protector_css_string( $text ) {
	$escaped = preg_replace_callback(
		'/[^\p{L}\p{N} ]/u',
		static function ( $m ) {
			return sprintf( '\\%X ', init_plugin_suite_content_protector_utf8_ord( $m[0] ) );
		},
		(string) $text
	);

	return null === $escaped ? '' : $escaped;
}

/**
 * Build the CSS that visually reconstructs cloaked keywords for one post.
 *
 * @param int        $content_id Post ID.
 * @param array|null $settings   Optional settings array.
 * @return string
 */
function init_plugin_suite_content_protector_build_keyword_css( $content_id, $settings = null ) {
	$css = '';

	foreach ( init_plugin_suite_content_protector_get_keywords( $settings ) as $keyword ) {
		$class = init_plugin_suite_content_protector_keyword_to_class( $keyword, $content_id );
		$css  .= '.' . sanitize_html_class( $class ) . '::before{content:"' . init_plugin_suite_content_protector_css_string( $keyword ) . '"}' . "\n";
	}

	return $css;
}

/**
 * Make sure the keyword CSS for a post is output exactly once.
 *
 * The queried post gets its CSS in <head> (no flash). Any other protected
 * post whose content is rendered on the same page (e.g. a theme rendering
 * full content of related posts) previously got cloaked spans with no CSS at
 * all, so the keywords silently vanished. Those now get their CSS printed
 * late, in the footer, through a second style handle.
 *
 * @param int        $content_id Post ID.
 * @param array|null $settings   Optional settings array.
 * @return void
 */
function init_plugin_suite_content_protector_ensure_keyword_css( $content_id, $settings = null ) {
	static $printed = array();

	$content_id = (int) $content_id;
	if ( isset( $printed[ $content_id ] ) ) {
		return;
	}

	$css = init_plugin_suite_content_protector_build_keyword_css( $content_id, $settings );
	if ( '' === $css ) {
		return;
	}

	$printed[ $content_id ] = true;

	$handle = 'init-content-keyword-hide';
	if ( wp_style_is( $handle, 'done' ) ) {
		$handle = 'init-content-keyword-hide-late';
	}

	if ( ! wp_style_is( $handle, 'registered' ) ) {
		wp_register_style( $handle, false, array(), INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION );
	}

	wp_enqueue_style( $handle );
	wp_add_inline_style( $handle, $css );
}

/**
 * Output keyword-reconstruction CSS for the queried post, in <head>.
 *
 * @return void
 */
function init_plugin_suite_content_protector_enqueue_styles() {
	$settings = init_plugin_suite_content_protector_get_settings();

	if ( init_plugin_suite_content_protector_should_skip_frontend( $settings ) ) {
		return;
	}

	if ( empty( init_plugin_suite_content_protector_get_keywords( $settings ) ) ) {
		return;
	}

	$post = init_plugin_suite_content_protector_get_protected_queried_post( $settings );
	if ( ! $post ) {
		return;
	}

	init_plugin_suite_content_protector_ensure_keyword_css( $post->ID, $settings );
}
add_action( 'wp_enqueue_scripts', 'init_plugin_suite_content_protector_enqueue_styles' );

/**
 * Compile the single-pass keyword matcher for a post.
 *
 * All keywords are merged into one alternation, longest first, so a text run
 * is scanned once no matter how many keywords exist, and "dragon ball" wins
 * over "dragon". Boundaries are Unicode-aware lookarounds instead of `\b`,
 * which is ASCII-only and never matched keywords that start or end with a
 * Vietnamese (or any non-ASCII) letter.
 *
 * @param int        $content_id Post ID.
 * @param array|null $settings   Optional settings array.
 * @return array{0: string, 1: array<string, string>}|null Pattern and needle => class map.
 */
function init_plugin_suite_content_protector_keyword_matcher( $content_id, $settings = null ) {
	$keywords = init_plugin_suite_content_protector_get_keywords( $settings );
	if ( empty( $keywords ) ) {
		return null;
	}

	$map = array();
	foreach ( $keywords as $keyword ) {
		$class           = init_plugin_suite_content_protector_keyword_to_class( $keyword, $content_id );
		$map[ $keyword ] = $class;

		// Also match the HTML-encoded form (e.g. "AT&T" appears as "AT&amp;T").
		$encoded = esc_html( $keyword );
		if ( $encoded !== $keyword && ! isset( $map[ $encoded ] ) ) {
			$map[ $encoded ] = $class;
		}
	}

	$needles = array_keys( $map );
	usort(
		$needles,
		static function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		}
	);

	$alternation = implode(
		'|',
		array_map(
			static function ( $needle ) {
				return preg_quote( $needle, '/' );
			},
			$needles
		)
	);

	return array( '/(?<![\p{L}\p{N}_])(?:' . $alternation . ')(?![\p{L}\p{N}_])/u', $map );
}

/*
 * ---------------------------------------------------------------------------
 * Noise injection
 * ---------------------------------------------------------------------------
 */

/**
 * Class names used for hidden noise elements (hidden by assets/css/style.css).
 *
 * @return string[]
 */
function init_plugin_suite_content_protector_noise_classes() {
	return array( 'frag-shade-01', 'ghost-x7', 'scramble-v3', 'nullcore-beta', 'blurwave-92', 'hidezone-k1', 'phantom-lag', 'junklayer-zx', 'stealth-tick', 'flick-fade-r7', 'vapor-delta', 'cloak-mute-9', 'packet-fog', 'mute-husk-88', 'shadow-glitch', 'dust-null', 'junk-mark32', 'camoframe-z1', 'crackline-vx', 'bit-spike', 'noisepatch-t2', 'anti-read-burst', 'cloakdrop-v7', 'faint-node', 'echo-trick', 'shield-pulse-0x', 'blind-phase', 'loopbug-alpha', 'ghostline-17', 'mist-frag', 'invis-junk', 'flick-hint', 'dummy-ghost', 'silent-dust', 'hovermask-01', 'blurpoint-mix', 'phaseblock-zk', 'hollow-trail', 'node-husk', 'noise-crawl', 'masker-core', 'patchwave-93', 'hacknull-tt', 'mimic-mute-5x', 'hush-blip', 'filter-junked', 'glitchcore', 'softfade-v9', 'decoy-null', 'streamblock-q4', 'noshadow-55', 'divert-trap', 'slice-invert', 'scatter-vibe', 'whitefade', 'trapdust-fake', 'shadowbyte', 'offset-glow', 'noise-token', 'pixel-disrupt', 'crackloop', 'blocktrap-ghost', 'coreblur-beta', 'pulsar-drop', 'blindfade-mx', 'shimmer-null', 'lag-bug-21', 'trapzone-random', 'lineghost-v1', 'blurstream-fake', 'inert-code-99', 'distort-mimic', 'cloakping', 'jammer-lost', 'nodedust-18', 'fakeline-delta', 'buffblock-k2', 'trickpulse', 'fogmark-v0', 'scramble-loop', 'coverray-ghost', 'noise-phi-7', 'fragshade-l1', 'zapdust', 'anti-scan-33', 'bypass-hollow', 'tracer-dust', 'shade-void', 'invisible-xn', 'null-slice', 'offpoint-zz', 'glitch-tag', 'blurtrace-71', 'stealth-zap', 'dropfilter-f', 'dummy-dash-3', 'smokephase', 'mute-jammer', 'jam-dustbox', 'fakeslice-z' );
}

/**
 * Draw the number of words to skip before the next noise insertion.
 *
 * Inserting after each word independently with probability p is exactly
 * equivalent to skipping Geometric(p) words between insertions. Sampling the
 * gap directly needs one random number per *insertion* instead of one per
 * *word* — ~14x fewer RNG calls at the default 7% rate.
 *
 * @param float $log_keep Precomputed log( 1 - p ).
 * @return int
 */
function init_plugin_suite_content_protector_noise_gap( $log_keep ) {
	$u = wp_rand( 1, 1000000 ) / 1000000; // Uniform in (0, 1].

	return (int) floor( log( $u ) / $log_keep );
}

/**
 * Inject noise elements into the text runs of a token list (in place).
 *
 * @param array $tokens Token list from tokenize_html(), keyword spans already applied.
 * @param int   $rate   Insertion chance per word, in percent (1–50).
 * @return void
 */
function init_plugin_suite_content_protector_apply_noise_to_tokens( array &$tokens, $rate ) {
	// Word pool for noise text, built from reader-facing text only.
	$pool = array();
	foreach ( $tokens as $token ) {
		if ( $token[0] && preg_match_all( '/\S{2,}/u', $token[1], $words ) ) {
			foreach ( $words[0] as $word ) {
				$pool[] = $word;
			}
		}
	}

	if ( empty( $pool ) ) {
		return;
	}

	$classes  = init_plugin_suite_content_protector_noise_classes();
	$tags     = array( 'span', 'del', 'ins', 'small', 'i', 'b', 'em', 'strong', 'mark' );
	$log_keep = log( 1 - ( max( 1, min( 50, (int) $rate ) ) / 100 ) );
	$skip     = init_plugin_suite_content_protector_noise_gap( $log_keep );

	foreach ( $tokens as $index => $token ) {
		if ( ! $token[0] ) {
			continue;
		}

		$words = preg_split( '/(\s+)/u', $token[1], -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $words ) ) {
			continue;
		}

		$out = '';
		foreach ( $words as $word ) {
			$out .= $word;

			if ( '' === $word || '' === trim( $word ) ) {
				continue;
			}

			if ( $skip > 0 ) {
				--$skip;
				continue;
			}

			$tag  = $tags[ array_rand( $tags ) ];
			$out .= '<' . $tag . ' class="' . esc_attr( $classes[ array_rand( $classes ) ] ) . '" aria-hidden="true">'
				. esc_html( $pool[ array_rand( $pool ) ] )
				. '</' . $tag . '>';
			$skip = init_plugin_suite_content_protector_noise_gap( $log_keep );
		}

		$tokens[ $index ][1] = $out;
	}
}

/**
 * Apply keyword cloaking and/or noise injection to HTML in a single pass.
 *
 * Both operations only ever touch plain text runs between tags — never a
 * tag's attributes, and never the content of script/style/pre/code/svg etc.
 * Keywords are cloaked first so their plain text is not reused as noise.
 *
 * @param string     $content    HTML.
 * @param int        $content_id Post ID (per-post keyword class salt).
 * @param bool       $keywords   Whether to cloak keywords.
 * @param bool       $noise      Whether to inject noise.
 * @param array|null $settings   Optional settings array.
 * @return string
 */
function init_plugin_suite_content_protector_transform_text( $content, $content_id, $keywords, $noise, $settings = null ) {
	if ( ( ! $keywords && ! $noise ) || '' === $content ) {
		return $content;
	}

	if ( null === $settings ) {
		$settings = init_plugin_suite_content_protector_get_settings();
	}

	$matcher = $keywords ? init_plugin_suite_content_protector_keyword_matcher( $content_id, $settings ) : null;
	if ( ! $matcher && ! $noise ) {
		return $content;
	}

	$tokens = init_plugin_suite_content_protector_tokenize_html( $content );
	if ( false === $tokens ) {
		return $content;
	}

	if ( $matcher ) {
		list( $pattern, $map ) = $matcher;

		$expanded = array();
		foreach ( $tokens as $token ) {
			if ( ! $token[0] ) {
				$expanded[] = $token;
				continue;
			}

			$pieces = preg_split( $pattern, $token[1], -1, PREG_SPLIT_OFFSET_CAPTURE );
			if ( ! is_array( $pieces ) || 1 === count( $pieces ) ) {
				$expanded[] = $token;
				continue;
			}

			// Rebuild: piece, matched keyword, piece, matched keyword, ...
			$last = count( $pieces ) - 1;
			foreach ( $pieces as $i => $piece ) {
				if ( '' !== $piece[0] ) {
					$expanded[] = array( true, $piece[0] );
				}
				if ( $i < $last ) {
					$start      = $piece[1] + strlen( $piece[0] );
					$needle     = substr( $token[1], $start, $pieces[ $i + 1 ][1] - $start );
					$class      = isset( $map[ $needle ] ) ? $map[ $needle ] : '';
					$expanded[] = '' === $class
						? array( true, $needle )
						: array( false, '<span class="' . esc_attr( $class ) . '"></span>' );
				}
			}
		}
		$tokens = $expanded;
	}

	if ( $noise ) {
		init_plugin_suite_content_protector_apply_noise_to_tokens( $tokens, (int) $settings['noise_rate'] );
	}

	$output = '';
	foreach ( $tokens as $token ) {
		$output .= $token[1];
	}

	return $output;
}

/**
 * Inject invisible noise elements into content.
 *
 * Kept for backward compatibility with code calling it directly.
 *
 * @param string $content HTML.
 * @return string
 */
function init_plugin_suite_content_protector_inject_noise( $content ) {
	return init_plugin_suite_content_protector_transform_text( $content, 0, false, true );
}

/**
 * Replace keywords with empty spans that CSS visually fills back in.
 *
 * Kept for backward compatibility with code calling it directly.
 *
 * @param string $content    HTML.
 * @param int    $content_id Post ID.
 * @return string
 */
function init_plugin_suite_content_protector_replace_keywords( $content, $content_id = 0 ) {
	return init_plugin_suite_content_protector_transform_text( $content, $content_id, true, false );
}
