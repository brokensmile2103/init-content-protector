<?php

defined( 'ABSPATH' ) || exit;

/**
 * Core protection functions for Init Content Protector
 */

// Inject invisible noise
//
// IMPORTANT: this must only ever insert noise spans into plain text runs
// between HTML tags — never inside a tag's `<...>` span itself. The previous
// implementation split the *raw HTML* on whitespace, which meant a noise
// span could land in the middle of a tag's attributes (e.g. between `<img`
// and `src="..."`), corrupting markup and breaking layout. This version
// tokenizes content into "tag" pieces (left untouched) and "text" pieces
// (where noise may be sprinkled), so tag structure can never be split.
function init_plugin_suite_content_protector_inject_noise( $content ) {
    $classes = ['frag-shade-01','ghost-x7','scramble-v3','nullcore-beta','blurwave-92','hidezone-k1','phantom-lag','junklayer-zx','stealth-tick','flick-fade-r7','vapor-delta','cloak-mute-9','packet-fog','mute-husk-88','shadow-glitch','dust-null','junk-mark32','camoframe-z1','crackline-vx','bit-spike','noisepatch-t2','anti-read-burst','cloakdrop-v7','faint-node','echo-trick','shield-pulse-0x','blind-phase','loopbug-alpha','ghostline-17','mist-frag','invis-junk','flick-hint','dummy-ghost','silent-dust','hovermask-01','blurpoint-mix','phaseblock-zk','hollow-trail','node-husk','noise-crawl','masker-core','patchwave-93','hacknull-tt','mimic-mute-5x','hush-blip','filter-junked','glitchcore','softfade-v9','decoy-null','streamblock-q4','noshadow-55','divert-trap','slice-invert','scatter-vibe','whitefade','trapdust-fake','shadowbyte','offset-glow','noise-token','pixel-disrupt','crackloop','blocktrap-ghost','coreblur-beta','pulsar-drop','blindfade-mx','shimmer-null','lag-bug-21','trapzone-random','lineghost-v1','blurstream-fake','inert-code-99','distort-mimic','cloakping','jammer-lost','nodedust-18','fakeline-delta','buffblock-k2','trickpulse','fogmark-v0','scramble-loop','coverray-ghost','noise-phi-7','fragshade-l1','zapdust','anti-scan-33','bypass-hollow','tracer-dust','shade-void','invisible-xn','null-slice','offpoint-zz','glitch-tag','blurtrace-71','stealth-zap','dropfilter-f','dummy-dash-3','smokephase','mute-jammer','jam-dustbox','fakeslice-z'];
    $tags = ['span', 'del', 'ins', 'small', 'i', 'b', 'em', 'strong', 'mark'];

    // Word pool for noise text, built from the fully stripped plain-text
    // version of the content (never from raw HTML).
    $raw_words = preg_split( '/\s+/u', wp_strip_all_tags( $content ), -1, PREG_SPLIT_NO_EMPTY );
    if ( ! is_array( $raw_words ) ) {
        $raw_words = [];
    }
    $noise_pool = array_values( array_filter( $raw_words, fn( $w ) => mb_strlen( $w ) >= 2 ) );
    if ( empty( $noise_pool ) ) {
        return $content;
    }

    $option    = get_option( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION, [] );
    $rate      = isset( $option['noise_rate'] ) ? (int) $option['noise_rate'] : 7;
    $rate      = max( 1, min( 50, $rate ) ); // clamp to a sane range

    // Elements whose entire contents must be left completely alone:
    // - script/style: not reader-facing text, and a stray span could break JS/CSS.
    // - pre/textarea/code: whitespace-sensitive, noise would corrupt output.
    // - select/option/title/svg: inserting inline HTML tags there is invalid
    //   markup (option/title/svg text content don't allow arbitrary child
    //   elements) and can visibly break dropdowns or SVG rendering.
    $skip_tags = 'script|style|pre|textarea|code|select|option|title|svg';

    // Tokenize into: whole skip-tag blocks (kept opaque), any other single
    // tag `<...>`, or the plain text runs between them. PREG_SPLIT_DELIM_CAPTURE
    // keeps every token (matched and unmatched) in order so a simple
    // concatenation losslessly reconstructs the original structure.
    $pattern = '/(<(?:' . $skip_tags . ')\b[^>]*>.*?<\/(?:' . $skip_tags . ')>|<[^>]+>)/is';
    $parts   = preg_split( $pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

    if ( ! is_array( $parts ) ) {
        return $content;
    }

    $result = '';

    foreach ( $parts as $part ) {
        // Tags and opaque skip-tag blocks pass through completely untouched.
        if ( isset( $part[0] ) && '<' === $part[0] ) {
            $result .= $part;
            continue;
        }

        // Plain text run between tags — safe to sprinkle noise into.
        $words = preg_split( '/(\s+)/u', $part, -1, PREG_SPLIT_DELIM_CAPTURE );
        foreach ( $words as $word ) {
            $result .= $word;

            if ( trim( $word ) !== '' && wp_rand( 1, 100 ) <= $rate ) {
                $cls  = $classes[ array_rand( $classes ) ];
                $tag  = $tags[ array_rand( $tags ) ];
                $text = $noise_pool[ array_rand( $noise_pool ) ];
                // aria-hidden as defense-in-depth: display:none already hides
                // this from screen readers in practice, but this guards
                // against the CSS failing to load for any reason.
                $result .= "<{$tag} class=\"" . esc_attr( $cls ) . "\" aria-hidden=\"true\">" . esc_html( $text ) . "</{$tag}>";
            }
        }
    }

    return $result;
}

// Check if this host has the required crypto functions available.
// Some low-end/shared hosts disable the OpenSSL extension, and hash_pbkdf2()
// requires PHP >= 7.1.1's hash extension args. Without this guard, encrypt()
// would throw a fatal "Call to undefined function" on those hosts.
function init_plugin_suite_content_protector_crypto_available() {
    return function_exists( 'openssl_encrypt' )
        && function_exists( 'openssl_random_pseudo_bytes' )
        && function_exists( 'hash_pbkdf2' )
        && in_array( 'aes-256-cbc', array_map( 'strtolower', openssl_get_cipher_methods() ), true );
}

// Encrypt content using AES.
// Returns a JSON string on success, or false if this host cannot encrypt
// (caller MUST check for false — do not assume a non-empty return).
function init_plugin_suite_content_protector_encrypt( $plain_text, $passphrase = null ) {
    if ( ! init_plugin_suite_content_protector_crypto_available() ) {
        return false;
    }

    // Ưu tiên dùng passphrase truyền vào, nếu không thì lấy từ option hoặc constant
    if ( is_null( $passphrase ) ) {
        $option = get_option( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION, [] );
        $passphrase = ! empty( $option['encrypt_key'] )
            ? $option['encrypt_key']
            : INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ENCRYPT_KEY;
    }

    // 32 bytes (256 bit) is a standard, sufficient salt size for PBKDF2.
    // The previous 256-byte salt added CPU cost server-side (every request,
    // uncached) and client-side (every visitor) with no real security benefit.
    $salt = openssl_random_pseudo_bytes(32);
    $iv   = openssl_random_pseudo_bytes(16);
    $key  = hash_pbkdf2("sha512", $passphrase, $salt, 999, 64);

    $encrypted = openssl_encrypt( $plain_text, 'aes-256-cbc', hex2bin( $key ), OPENSSL_RAW_DATA, $iv );

    if ( false === $encrypted ) {
        return false;
    }

    return json_encode([
        'ciphertext' => base64_encode( $encrypted ),
        'iv'         => bin2hex( $iv ),
        'salt'       => bin2hex( $salt ),
    ]);
}

// Generate keyword class
function init_plugin_suite_content_protector_keyword_to_class( $keyword, $content_id = 0 ) {
    $normalized = mb_strtolower( trim( $keyword ) );
    $hash_keyword = substr( md5( $normalized ), 0, 8 );

    $hash_context = '';
    if ( $content_id > 0 ) {
        $salted = INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_KEYWORD_SALT . $content_id;
        $hash_context = substr( md5( $salted ), 0, 5 );
    }

    return 'icp-' . $hash_context . '-' . $hash_keyword;
}

// Output <style> to reconstruct hidden keywords via CSS
add_action( 'wp_enqueue_scripts', 'init_plugin_suite_content_protector_enqueue_styles' );
function init_plugin_suite_content_protector_enqueue_styles() {
    global $post;

    $option = get_option( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION, [] );

    // Skip for excluded roles, consistent with every other protection layer
    // (encryption, JS protection, Advanced DevTools Blocking, Anti-Screenshot
    // Protection, noise injection all check this already).
    if ( init_plugin_suite_content_protector_is_excluded_for_current_user( $option ) ) {
        return;
    }

    $keywords_raw = $option['keywords'] ?? '';
    if ( empty( $keywords_raw ) || empty( $post->ID ) ) {
        return;
    }

    // ob_start() must come AFTER the guard clauses above, not before: the
    // previous version opened the buffer unconditionally at the top of the
    // function, then `return`ed early (with no matching ob_get_clean()) on
    // any request where keywords aren't configured or $post isn't set —
    // which is most front-end requests on most sites (archives, the
    // homepage, any post with no keywords entered). That left an orphaned
    // output buffer open for the rest of the request on every such request.
    ob_start();

    $keywords = array_filter( array_map( 'trim', explode( ',', $keywords_raw ) ) );
    foreach ( $keywords as $keyword ) {
        $class = init_plugin_suite_content_protector_keyword_to_class( $keyword, $post->ID );
        $escaped = esc_js( $keyword );
        printf( ".%s::before{content:\"%s\"}\n", esc_attr( $class ), esc_html( $escaped ) );
    }

    $custom_css = ob_get_clean();
    wp_register_style( 
        'init-content-keyword-hide', 
        false, 
        [], 
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION 
    );
    wp_enqueue_style( 'init-content-keyword-hide' );
    wp_add_inline_style( 'init-content-keyword-hide', $custom_css );
}

// Replace keyword with hidden span
function init_plugin_suite_content_protector_replace_keywords( $content, $content_id = 0 ) {
    $option = get_option( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION, [] );
    $keywords_raw = $option['keywords'] ?? '';
    if ( empty( $keywords_raw ) ) return $content;

    $keywords = array_filter( array_map( 'trim', explode( ',', $keywords_raw ) ) );
    if ( empty( $keywords ) ) return $content;

    foreach ( $keywords as $keyword ) {
        if ( $keyword === '' ) continue;

        $class = init_plugin_suite_content_protector_keyword_to_class( $keyword, $content_id );
        $escaped_keyword = preg_quote( $keyword, '/' );

        $content = preg_replace_callback(
            '/\b(' . $escaped_keyword . ')\b/u',
            function () use ( $class ) {
                return '<span class="' . esc_attr( $class ) . '"></span>';
            },
            $content
        );
    }

    return $content;
}

// Detect AMP endpoint (works with the official AMP plugin and common AMP
// plugins that define this same function name). Custom JS / inline <script>
// injection breaks AMP validation, so protection is skipped there entirely
// rather than silently producing an invalid AMP page.
function init_plugin_suite_content_protector_is_amp_endpoint() {
    return function_exists( 'is_amp_endpoint' ) && is_amp_endpoint();
}

// Check if current user should be excluded from content protection based on roles
function init_plugin_suite_content_protector_is_excluded_for_current_user( $option = null ) {
    if ( null === $option ) {
        $option = get_option( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION, [] );
    }

    if ( empty( $option['excluded_roles'] ) || ! is_user_logged_in() ) {
        return false;
    }

    $user = wp_get_current_user();
    if ( empty( $user->roles ) || ! is_array( $user->roles ) ) {
        return false;
    }

    return (bool) array_intersect( $user->roles, (array) $option['excluded_roles'] );
}
