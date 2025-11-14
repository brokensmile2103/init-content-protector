<?php

defined( 'ABSPATH' ) || exit;

/**
 * Core protection functions for Init Content Protector
 */

// Inject invisible noise
function init_plugin_suite_content_protector_inject_noise( $content ) {
    $classes = ['frag-shade-01','ghost-x7','scramble-v3','nullcore-beta','blurwave-92','hidezone-k1','phantom-lag','junklayer-zx','stealth-tick','flick-fade-r7','vapor-delta','cloak-mute-9','packet-fog','mute-husk-88','shadow-glitch','dust-null','junk-mark32','camoframe-z1','crackline-vx','bit-spike','noisepatch-t2','anti-read-burst','cloakdrop-v7','faint-node','echo-trick','shield-pulse-0x','blind-phase','loopbug-alpha','ghostline-17','mist-frag','invis-junk','flick-hint','dummy-ghost','silent-dust','hovermask-01','blurpoint-mix','phaseblock-zk','hollow-trail','node-husk','noise-crawl','masker-core','patchwave-93','hacknull-tt','mimic-mute-5x','hush-blip','filter-junked','glitchcore','softfade-v9','decoy-null','streamblock-q4','noshadow-55','divert-trap','slice-invert','scatter-vibe','whitefade','trapdust-fake','shadowbyte','offset-glow','noise-token','pixel-disrupt','crackloop','blocktrap-ghost','coreblur-beta','pulsar-drop','blindfade-mx','shimmer-null','lag-bug-21','trapzone-random','lineghost-v1','blurstream-fake','inert-code-99','distort-mimic','cloakping','jammer-lost','nodedust-18','fakeline-delta','buffblock-k2','trickpulse','fogmark-v0','scramble-loop','coverray-ghost','noise-phi-7','fragshade-l1','zapdust','anti-scan-33','bypass-hollow','tracer-dust','shade-void','invisible-xn','null-slice','offpoint-zz','glitch-tag','blurtrace-71','stealth-zap','dropfilter-f','dummy-dash-3','smokephase','mute-jammer','jam-dustbox','fakeslice-z'];
    $tags = ['span', 'del', 'ins', 'small', 'i', 'b', 'em', 'strong', 'mark'];

    $raw_words = preg_split( '/\s+/u', wp_strip_all_tags( $content ), -1, PREG_SPLIT_NO_EMPTY );
    if ( ! is_array( $raw_words ) ) {
        $raw_words = [];
    }

    $noise_pool = array_filter( $raw_words, fn($w) => mb_strlen($w) >= 2 );
    $noise_pool = array_values( $noise_pool );

    $words = preg_split( '/(\s+)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
    $result = [];

    foreach ( $words as $word ) {
        $result[] = $word;

        if ( trim( $word ) !== '' && wp_rand(1, 100) <= 7 && count($noise_pool) > 0 ) {
            $cls  = $classes[ array_rand( $classes ) ];
            $tag  = $tags[ array_rand( $tags ) ];
            $text = $noise_pool[ array_rand( $noise_pool ) ];
            $result[] = "<{$tag} class=\"" . esc_attr( $cls ) . "\">" . esc_html( $text ) . "</{$tag}>";
        }
    }

    return implode( '', $result );
}

// Encrypt content using AES
function init_plugin_suite_content_protector_encrypt( $plain_text, $passphrase = null ) {
    // Ưu tiên dùng passphrase truyền vào, nếu không thì lấy từ option hoặc constant
    if ( is_null( $passphrase ) ) {
        $option = get_option( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION, [] );
        $passphrase = ! empty( $option['encrypt_key'] )
            ? $option['encrypt_key']
            : INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ENCRYPT_KEY;
    }

    $salt = openssl_random_pseudo_bytes(256);
    $iv   = openssl_random_pseudo_bytes(16);
    $key  = hash_pbkdf2("sha512", $passphrase, $salt, 999, 64);

    $encrypted = openssl_encrypt( $plain_text, 'aes-256-cbc', hex2bin( $key ), OPENSSL_RAW_DATA, $iv );

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
    // Tạo nội dung CSS
    ob_start();
    global $post;

    $option = get_option( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION, [] );
    $keywords_raw = $option['keywords'] ?? '';
    if ( empty( $keywords_raw ) || empty( $post->ID ) ) return;

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
