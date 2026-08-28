<?php
/**
 * Plugin Name: Init Content Protector
 * Plugin URI: https://inithtml.com/plugin/init-content-protector/
 * Description: A lightweight plugin to protect your post content from copy, scraping, and inspection. Features include copy protection, keyword cloaking, noise injection, and full content encryption.
 * Version: 1.6
 * Author: Init HTML
 * Author URI: https://inithtml.com/
 * Text Domain: init-content-protector
 * Domain Path: /languages
 * Requires at least: 5.7
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION',        '1.6' );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_SLUG',           'init-content-protector' );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION',         'init_plugin_suite_content_protector_settings' );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_URL',            plugin_dir_url( __FILE__ ) );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_PATH',           plugin_dir_path( __FILE__ ) );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL',     INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_URL  . 'assets/' );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_PATH',    INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_PATH . 'assets/' );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_LANGUAGES_PATH', INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_PATH . 'languages/' );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_INCLUDES_PATH',  INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_PATH . 'includes/' );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ENCRYPT_KEY', 	  'init@secure' );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_KEYWORD_SALT',   'init_salt_' );

require_once INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_INCLUDES_PATH . 'settings-page.php';
require_once INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_INCLUDES_PATH . 'utils.php';
require_once INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_INCLUDES_PATH . 'rest-api.php';
require_once INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_INCLUDES_PATH . 'hooks.php';

add_action( 'wp_enqueue_scripts', 'init_plugin_suite_content_protector_maybe_enqueue_noise_css', 99 );
function init_plugin_suite_content_protector_maybe_enqueue_noise_css() {
    if ( is_admin() || init_plugin_suite_content_protector_is_amp_endpoint() ) {
        return;
    }

    $option = get_option( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION, [] );

    // Skip all visual noise/encryption styling for excluded roles.
    if ( init_plugin_suite_content_protector_is_excluded_for_current_user( $option ) ) {
        return;
    }

    $inject_noise = ! empty( $option['inject_noise'] ) && $option['inject_noise'] === '1';
    $encrypt_mode = ! empty( $option['content_mode'] ) && $option['content_mode'] === 'encrypt';

    // Noise CSS is used both for "noise" spans and for some encrypted output.
    if ( ! $inject_noise && ! $encrypt_mode ) {
        return;
    }

    wp_enqueue_style(
        'init-content-protector-noise',
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'css/style.css',
        [],
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION
    );
}

add_action( 'wp_enqueue_scripts', 'init_plugin_suite_content_protector_enqueue_encryption', 100 );
function init_plugin_suite_content_protector_enqueue_encryption() {
    if ( is_admin() || init_plugin_suite_content_protector_is_amp_endpoint() ) {
        return;
    }

    $option = get_option( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION, [] );

    // Skip for excluded roles
    if ( init_plugin_suite_content_protector_is_excluded_for_current_user( $option ) ) {
        return;
    }

    $encrypt_mode = ! empty( $option['content_mode'] ) && $option['content_mode'] === 'encrypt';
    if ( ! $encrypt_mode ) {
        return;
    }

    // Load crypto + decrypt
    wp_enqueue_script(
        'init-content-protector-crypto',
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'js/crypto-js.min.js',
        [],
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION,
        true
    );

    $decrypt_deps = [ 'init-content-protector-crypto' ];

    // Optional headless/automation-browser guard. First-party script (not a
    // vendored third-party library) that scores several client-side
    // automation signals (Puppeteer/Playwright/Selenium) and exposes
    // window.InitContentHeadlessCheck, a Promise that decrypt.js awaits
    // before decrypting. Only meaningful in Encrypt mode — hence living in
    // this same function — since in "No Protection" mode the real content
    // is already in the initial HTML response before any JS runs, leaving
    // nothing left to withhold.
    $headless_detect_enabled = ! empty( $option['headless_detect'] ) && $option['headless_detect'] === '1';
    if ( $headless_detect_enabled ) {
        wp_enqueue_script(
            'init-content-protector-headless-detect',
            INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'js/headless-detect.js',
            [],
            INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION,
            true
        );

        wp_localize_script(
            'init-content-protector-headless-detect',
            'InitHeadlessDetectData',
            [
                // Only surface console warnings to admins, same rationale as
                // InitContentDecryptData.debug below.
                'debug' => current_user_can( 'manage_options' ),
            ]
        );

        // Declaring this as a decrypt.js dependency guarantees its <script>
        // tag prints first. Not strictly required for correctness — the
        // window.InitContentHeadlessCheck Promise is created synchronously
        // by headless-detect.js regardless of tag order, and is guaranteed
        // to exist before DOMContentLoaded fires either way — but making
        // the load order explicit here is clearer than relying on that.
        $decrypt_deps[] = 'init-content-protector-headless-detect';
    }

    wp_enqueue_script(
        'init-content-protector-decrypt',
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'js/decrypt.js',
        $decrypt_deps,
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION,
        true
    );

    // 'inline' (default, backward-compatible): key is base64'd directly into
    //   page HTML. Simple, works everywhere, but trivially readable via
    //   view-source — documented as a soft deterrent only.
    // 'rest' (opt-in "Enhanced"): key is fetched client-side (Vanilla JS
    //   fetch()) from a REST API endpoint after page load. Keeps the key
    //   out of cached/static HTML. Still not real secrecy (any browser that
    //   executes the JS can get it) but raises the bar for naive scrapers
    //   and plays nicely with full-page cache plugins since the fetch
    //   happens dynamically, outside the cached HTML. Not rate-limited by
    //   the plugin itself (see includes/rest-api.php for why per-IP
    //   transients were deliberately avoided) — add server/CDN/WAF-level
    //   limiting if that matters for your site.
    $delivery = ( ! empty( $option['encrypt_delivery'] ) && 'rest' === $option['encrypt_delivery'] ) ? 'rest' : 'inline';

    $data = [
        'content_selector' => $option['content_selector'] ?? '.entry-content',
        // Only surface console warnings to admins so we don't spam real
        // visitors' consoles when a theme's selector doesn't match.
        'debug'             => current_user_can( 'manage_options' ),
    ];

    if ( 'rest' === $delivery ) {
        $data['rest_url'] = esc_url_raw( rest_url( 'init-content-protector/v1/key' ) );
        $data['nonce']    = wp_create_nonce( 'wp_rest' );
        $data['post_id']  = get_the_ID();
    } else {
        $key = ! empty( $option['encrypt_key'] ) ? $option['encrypt_key'] : INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ENCRYPT_KEY;
        $data['decryption_key'] = base64_encode( $key );
    }

    wp_localize_script( 'init-content-protector-decrypt', 'InitContentDecryptData', $data );
}

add_action( 'wp_enqueue_scripts', 'init_plugin_suite_content_protector_enqueue_js_protect', 101 );
function init_plugin_suite_content_protector_enqueue_js_protect() {
    if ( is_admin() || init_plugin_suite_content_protector_is_amp_endpoint() ) {
        return;
    }

    $option = get_option( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION, [] );

    // Skip for excluded roles
    if ( init_plugin_suite_content_protector_is_excluded_for_current_user( $option ) ) {
        return;
    }

    $js_protect_enabled = ! empty( $option['js_protect'] ) && $option['js_protect'] === '1';
    if ( ! $js_protect_enabled ) {
        return;
    }

    wp_enqueue_script(
        'init-content-protector-js',
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'js/content-protector.js',
        [],
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION,
        true
    );

    wp_localize_script(
        'init-content-protector-js',
        'InitContentProtectorData',
        [
            'jsContentProtectionEnabled' => true,
            'content_selector'           => $option['content_selector'] ?? '.entry-content',
        ]
    );
}

add_action( 'wp_enqueue_scripts', 'init_plugin_suite_content_protector_enqueue_disable_devtool', 102 );
function init_plugin_suite_content_protector_enqueue_disable_devtool() {
    if ( is_admin() || init_plugin_suite_content_protector_is_amp_endpoint() ) {
        return;
    }

    $option = get_option( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION, [] );

    // Skip for excluded roles
    if ( init_plugin_suite_content_protector_is_excluded_for_current_user( $option ) ) {
        return;
    }

    $disable_devtool_enabled = ! empty( $option['disable_devtool'] ) && $option['disable_devtool'] === '1';
    if ( ! $disable_devtool_enabled ) {
        return;
    }

    // Third-party library (MIT licensed): https://github.com/theajack/disable-devtool
    // Vendored as-is in assets/js/disable-devtool.min.js. Actively detects
    // several different ways of opening browser DevTools (not just the
    // keyboard shortcuts that content-protector.js already blocks) and
    // reacts by closing the tab or redirecting away. This is independent
    // of, and can be used with or without, "Enable JavaScript Content
    // Protection" above.
    wp_enqueue_script(
        'init-content-protector-disable-devtool-lib',
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'js/disable-devtool.min.js',
        [],
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION,
        true
    );

    // Our own thin init wrapper (assets/js/disable-devtool-init.js) that
    // calls the library with plugin-specific config, kept separate so a
    // future library update can drop straight in without touching our code.
    wp_enqueue_script(
        'init-content-protector-disable-devtool-init',
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'js/disable-devtool-init.js',
        [ 'init-content-protector-disable-devtool-lib' ],
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION,
        true
    );

    // See the comment in disable-devtool-init.js: the library's own default
    // fallback URL is the literal string "localhost", which is a dead link
    // on a live site. We pass the site's homepage instead.
    wp_localize_script(
        'init-content-protector-disable-devtool-init',
        'InitDisableDevtoolData',
        [
            'url' => esc_url_raw( home_url( '/' ) ),
        ]
    );
}

add_action( 'wp_enqueue_scripts', 'init_plugin_suite_content_protector_enqueue_antisnap', 103 );
function init_plugin_suite_content_protector_enqueue_antisnap() {
    if ( is_admin() || init_plugin_suite_content_protector_is_amp_endpoint() ) {
        return;
    }

    $option = get_option( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION, [] );

    // Skip for excluded roles
    if ( init_plugin_suite_content_protector_is_excluded_for_current_user( $option ) ) {
        return;
    }

    $antisnap_enabled = ! empty( $option['antisnap'] ) && $option['antisnap'] === '1';
    if ( ! $antisnap_enabled ) {
        return;
    }

    // Third-party library (MIT licensed): "Init AntiSnap" by Init HTML.
    // Vendored as its full, human-readable source in assets/js/init-antisnap.js
    // (not minified — small enough that minifying it buys nothing worth
    // sacrificing reviewability for). Watches for scroll-jump and
    // viewport-resize patterns typical of automated screenshot/scraping
    // tools and DevTools panels, and briefly blurs the page with a warning
    // when detected. Independent of the other protection layers; real
    // readers scrolling/resizing normally aren't affected (see the
    // library's own header docblock for details).
    wp_enqueue_script(
        'init-content-protector-antisnap',
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'js/init-antisnap.js',
        [],
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION,
        true
    );

    // AntiSnap reads window.InitAntiSnapConfig synchronously as soon as its
    // own script executes — it's a plain global, not wp_localize_script
    // data — so this config object must be printed as an inline script
    // BEFORE init-antisnap.js runs, hence position 'before'.
    $config_js = 'window.InitAntiSnapConfig = ' . wp_json_encode( [
        'ALERT_MESSAGE' => __( '⚠️ Automated screenshot or scraping tool detected. Action blocked!', 'init-content-protector' ),
    ] ) . ';';

    wp_add_inline_script( 'init-content-protector-antisnap', $config_js, 'before' );
}

// ==========================
// Settings link
// ==========================

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'init_plugin_suite_content_protector_add_settings_link');
// Add a "Settings" link to the plugin row in the Plugins admin screen
function init_plugin_suite_content_protector_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=' . INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_SLUG) . '">' . __('Settings', 'init-content-protector') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
