<?php
/**
 * Plugin Name: Init Content Protector
 * Plugin URI: https://inithtml.com/plugin/init-content-protector/
 * Description: A lightweight plugin to protect your post content from copy, scraping, and inspection. Features include copy protection, keyword cloaking, noise injection, and full content encryption.
 * Version: 1.2
 * Author: Init HTML
 * Author URI: https://inithtml.com/
 * Text Domain: init-content-protector
 * Domain Path: /languages
 * Requires at least: 5.7
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION',        '1.2' );
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
require_once INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_INCLUDES_PATH . 'hooks.php';

add_action( 'wp_enqueue_scripts', 'init_plugin_suite_content_protector_maybe_enqueue_noise_css', 99 );
function init_plugin_suite_content_protector_maybe_enqueue_noise_css() {
    if ( is_admin() ) {
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

add_action( 'wp_enqueue_scripts', 'init_plugin_suite_content_protector_maybe_enqueue_encrypt_scripts', 100 );
function init_plugin_suite_content_protector_maybe_enqueue_encrypt_scripts() {
    if ( is_admin() ) {
        return;
    }

    $option = get_option( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION, [] );

    // Skip all script-level protection for excluded roles.
    if ( init_plugin_suite_content_protector_is_excluded_for_current_user( $option ) ) {
        return;
    }

    $encrypt_mode       = ! empty( $option['content_mode'] ) && $option['content_mode'] === 'encrypt';
    $js_protect_enabled = ! empty( $option['js_protect'] ) && $option['js_protect'] === '1';

    // If neither encryption nor JS protection is enabled, do nothing.
    if ( ! $encrypt_mode && ! $js_protect_enabled ) {
        return;
    }

    // Only load crypto library when encryption is active.
    if ( $encrypt_mode ) {
        wp_enqueue_script(
            'init-content-protector-crypto',
            INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'js/crypto-js.min.js',
            [],
            INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION,
            true
        );
    }

    wp_enqueue_script(
        'init-content-protector-script',
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'js/script.js',
        $encrypt_mode ? [ 'init-content-protector-crypto' ] : [],
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION,
        true
    );

    // Prepare data for JS side.
    $decryption_key = '';
    if ( $encrypt_mode ) {
        $key            = ! empty( $option['encrypt_key'] ) ? $option['encrypt_key'] : INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ENCRYPT_KEY;
        $decryption_key = base64_encode( $key );
    }

    wp_localize_script(
        'init-content-protector-script',
        'InitContentProtectorData',
        [
            'decryption_key'             => $decryption_key,
            'jsContentProtectionEnabled' => $js_protect_enabled,
            'content_selector'           => $option['content_selector'] ?? '.entry-content',
        ]
    );
}
