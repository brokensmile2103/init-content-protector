<?php
/**
 * Plugin Name: Init Content Protector
 * Plugin URI: https://inithtml.com/plugin/init-content-protector/
 * Description: A lightweight plugin to protect your post content from copy, scraping, and inspection. Features include copy protection, keyword cloaking, noise injection, and full content encryption.
 * Version: 1.7
 * Author: Init HTML
 * Author URI: https://inithtml.com/
 * Text Domain: init-content-protector
 * Domain Path: /languages
 * Requires at least: 5.7
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Init_Content_Protector
 */

defined( 'ABSPATH' ) || exit;

define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION', '1.7' );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_SLUG', 'init-content-protector' );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION', 'init_plugin_suite_content_protector_settings' );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_FILE', __FILE__ );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_URL', plugin_dir_url( __FILE__ ) );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL', INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_URL . 'assets/' );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_PATH', INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_PATH . 'assets/' );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_LANGUAGES_PATH', INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_PATH . 'languages/' );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_INCLUDES_PATH', INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_PATH . 'includes/' );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ENCRYPT_KEY', 'init@secure' );
define( 'INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_KEYWORD_SALT', 'init_salt_' );

require_once INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_INCLUDES_PATH . 'utils.php';
require_once INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_INCLUDES_PATH . 'settings-page.php';
require_once INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_INCLUDES_PATH . 'rest-api.php';
require_once INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_INCLUDES_PATH . 'hooks.php';

/*
 * ---------------------------------------------------------------------------
 * Shared asset helpers
 *
 * These are idempotent, so they can be called both from wp_enqueue_scripts
 * (the normal case: the queried post is protected) and lazily from the
 * content filter (a protected post rendered inside another view, e.g. a
 * page builder showing a post). Footer scripts and styles enqueued after
 * wp_head are still printed by WordPress in the footer.
 * ---------------------------------------------------------------------------
 */

/**
 * Enqueue the skeleton + noise stylesheet.
 *
 * @return void
 */
function init_plugin_suite_content_protector_enqueue_noise_style() {
	wp_enqueue_style(
		'init-content-protector-noise',
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'css/style.css',
		array(),
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION
	);
}

/**
 * Whether the bundled CryptoJS library should be enqueued up front.
 *
 * The decrypt.js script uses the native Web Crypto API, which browsers only
 * expose in secure contexts (HTTPS or localhost). So CryptoJS (~60 KB) is
 * only enqueued on sites that are not served over HTTPS. On HTTPS sites
 * decrypt.js still lazy-loads it on demand in the rare browser without Web
 * Crypto, so there is no dead end either way.
 *
 * @return bool
 */
function init_plugin_suite_content_protector_should_preload_cryptojs() {
	/**
	 * Filters whether CryptoJS is enqueued up front.
	 *
	 * @since 1.7
	 *
	 * @param bool $preload True when the site is not served over HTTPS.
	 */
	return (bool) apply_filters( 'init_plugin_suite_content_protector_load_cryptojs', ! is_ssl() );
}

/**
 * Enqueue decryption scripts and their data (once per request).
 *
 * @param array $settings Settings array.
 * @param int   $post_id  Post ID the key is requested for (Enhanced delivery).
 * @return void
 */
function init_plugin_suite_content_protector_enqueue_decrypt_assets( $settings, $post_id = 0 ) {
	static $done = false;

	if ( $done ) {
		return;
	}
	$done = true;

	init_plugin_suite_content_protector_enqueue_noise_style();

	$cryptojs_url = INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'js/crypto-js.min.js';
	$decrypt_deps = array();

	wp_register_script(
		'init-content-protector-crypto',
		$cryptojs_url,
		array(),
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION,
		true
	);

	if ( init_plugin_suite_content_protector_should_preload_cryptojs() ) {
		$decrypt_deps[] = 'init-content-protector-crypto';
	}

	// Optional headless/automation-browser guard. decrypt.js awaits
	// window.InitContentHeadlessCheck before fetching the key or decrypting.
	// Only meaningful in Encrypt mode: in "No Protection" mode the real
	// content is already in the HTML before any JS runs.
	if ( init_plugin_suite_content_protector_is_enabled( $settings, 'headless_detect' ) ) {
		wp_enqueue_script(
			'init-content-protector-headless-detect',
			INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'js/headless-detect.js',
			array(),
			INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION,
			true
		);

		wp_localize_script(
			'init-content-protector-headless-detect',
			'InitHeadlessDetectData',
			array(
				'debug' => current_user_can( 'manage_options' ),
			)
		);

		$decrypt_deps[] = 'init-content-protector-headless-detect';
	}

	wp_enqueue_script(
		'init-content-protector-decrypt',
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'js/decrypt.js',
		$decrypt_deps,
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION,
		true
	);

	/**
	 * Filters the minimum delay (ms, after DOMContentLoaded) before decrypted
	 * content is rendered. Key retrieval and decryption run in parallel with
	 * this delay, so it no longer adds to the total time.
	 *
	 * @since 1.7
	 *
	 * @param int $delay Delay in milliseconds. Default 1000.
	 */
	$delay = (int) apply_filters( 'init_plugin_suite_content_protector_decrypt_delay', 1000 );

	$data = array(
		'content_selector' => $settings['content_selector'],
		// Only surface console diagnostics to admins.
		'debug'            => current_user_can( 'manage_options' ),
		'delay'            => max( 0, $delay ),
		// Used by decrypt.js to lazy-load CryptoJS if Web Crypto is missing.
		'cryptojs_url'     => esc_url_raw( add_query_arg( 'ver', INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION, $cryptojs_url ) ),
	);

	// 'inline' (default): key is base64'd into the page. Simple, soft deterrent.
	// 'rest' (Enhanced): key is fetched from a REST endpoint after the
	// headless check passes, so it never appears in cached/static HTML.
	if ( isset( $settings['encrypt_delivery'] ) && 'rest' === $settings['encrypt_delivery'] ) {
		$data['rest_url'] = esc_url_raw( rest_url( 'init-content-protector/v1/key' ) );
		$data['nonce']    = wp_create_nonce( 'wp_rest' );
		$data['post_id']  = $post_id ? (int) $post_id : (int) get_the_ID();
	} else {
		$data['decryption_key'] = base64_encode( init_plugin_suite_content_protector_get_passphrase( $settings ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Documented soft-deterrent transport of the key.
	}

	wp_localize_script( 'init-content-protector-decrypt', 'InitContentDecryptData', $data );
}

/*
 * ---------------------------------------------------------------------------
 * wp_enqueue_scripts callbacks
 *
 * Function names and priorities are unchanged from previous versions, so
 * any site that unhooks one of them with remove_action() keeps working.
 * ---------------------------------------------------------------------------
 */

/**
 * Enqueue the noise/skeleton CSS on protected singular views.
 *
 * @return void
 */
function init_plugin_suite_content_protector_maybe_enqueue_noise_css() {
	$settings = init_plugin_suite_content_protector_get_settings();

	if ( init_plugin_suite_content_protector_should_skip_frontend( $settings ) ) {
		return;
	}

	if ( ! init_plugin_suite_content_protector_is_enabled( $settings, 'inject_noise' )
		&& ! init_plugin_suite_content_protector_is_encrypt_mode( $settings )
	) {
		return;
	}

	if ( ! init_plugin_suite_content_protector_get_protected_queried_post( $settings ) ) {
		return;
	}

	init_plugin_suite_content_protector_enqueue_noise_style();
}
add_action( 'wp_enqueue_scripts', 'init_plugin_suite_content_protector_maybe_enqueue_noise_css', 99 );

/**
 * Enqueue decryption scripts on protected singular views (Encrypt mode).
 *
 * @return void
 */
function init_plugin_suite_content_protector_enqueue_encryption() {
	$settings = init_plugin_suite_content_protector_get_settings();

	if ( ! init_plugin_suite_content_protector_is_encrypt_mode( $settings )
		|| init_plugin_suite_content_protector_should_skip_frontend( $settings )
	) {
		return;
	}

	$post = init_plugin_suite_content_protector_get_protected_queried_post( $settings );
	if ( ! $post ) {
		return;
	}

	init_plugin_suite_content_protector_enqueue_decrypt_assets( $settings, $post->ID );
}
add_action( 'wp_enqueue_scripts', 'init_plugin_suite_content_protector_enqueue_encryption', 100 );

/**
 * Enqueue the basic JavaScript content protection.
 *
 * @return void
 */
function init_plugin_suite_content_protector_enqueue_js_protect() {
	$settings = init_plugin_suite_content_protector_get_settings();

	if ( ! init_plugin_suite_content_protector_is_enabled( $settings, 'js_protect' )
		|| init_plugin_suite_content_protector_should_skip_frontend( $settings )
	) {
		return;
	}

	wp_enqueue_script(
		'init-content-protector-js',
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'js/content-protector.js',
		array(),
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION,
		true
	);

	wp_localize_script(
		'init-content-protector-js',
		'InitContentProtectorData',
		array(
			'jsContentProtectionEnabled' => true,
			'content_selector'           => $settings['content_selector'],
		)
	);
}
add_action( 'wp_enqueue_scripts', 'init_plugin_suite_content_protector_enqueue_js_protect', 101 );

/**
 * Enqueue Advanced DevTools Blocking (third-party disable-devtool library).
 *
 * @return void
 */
function init_plugin_suite_content_protector_enqueue_disable_devtool() {
	$settings = init_plugin_suite_content_protector_get_settings();

	if ( ! init_plugin_suite_content_protector_is_enabled( $settings, 'disable_devtool' )
		|| init_plugin_suite_content_protector_should_skip_frontend( $settings )
	) {
		return;
	}

	// MIT licensed, https://github.com/theajack/disable-devtool — vendored
	// as-is so it can be updated as a drop-in.
	wp_enqueue_script(
		'init-content-protector-disable-devtool-lib',
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'js/disable-devtool.min.js',
		array(),
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION,
		true
	);

	// Our own thin init wrapper with plugin-specific config.
	wp_enqueue_script(
		'init-content-protector-disable-devtool-init',
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'js/disable-devtool-init.js',
		array( 'init-content-protector-disable-devtool-lib' ),
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION,
		true
	);

	wp_localize_script(
		'init-content-protector-disable-devtool-init',
		'InitDisableDevtoolData',
		array(
			'url' => esc_url_raw( home_url( '/' ) ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'init_plugin_suite_content_protector_enqueue_disable_devtool', 102 );

/**
 * Enqueue Anti-Screenshot Protection (Init AntiSnap).
 *
 * @return void
 */
function init_plugin_suite_content_protector_enqueue_antisnap() {
	$settings = init_plugin_suite_content_protector_get_settings();

	if ( ! init_plugin_suite_content_protector_is_enabled( $settings, 'antisnap' )
		|| init_plugin_suite_content_protector_should_skip_frontend( $settings )
	) {
		return;
	}

	// First-party library, vendored as full human-readable source.
	wp_enqueue_script(
		'init-content-protector-antisnap',
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'js/init-antisnap.js',
		array(),
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION,
		true
	);

	// AntiSnap reads window.InitAntiSnapConfig synchronously when it runs, so
	// this must be printed BEFORE it. Values a theme already set on that
	// global (e.g. its own onDetect hook or message) are kept and win over
	// the plugin's defaults, instead of being overwritten as in 1.6.
	$defaults = array(
		'ALERT_MESSAGE' => __( '⚠️ Automated screenshot or scraping tool detected. Action blocked!', 'init-content-protector' ),
	);

	$config_js = 'window.InitAntiSnapConfig=Object.assign(' . wp_json_encode( $defaults ) . ',window.InitAntiSnapConfig||{});';

	wp_add_inline_script( 'init-content-protector-antisnap', $config_js, 'before' );
}
add_action( 'wp_enqueue_scripts', 'init_plugin_suite_content_protector_enqueue_antisnap', 103 );

/*
 * ---------------------------------------------------------------------------
 * Settings link
 * ---------------------------------------------------------------------------
 */

/**
 * Add a "Settings" link to the plugin row in the Plugins screen.
 *
 * @param string[] $links Existing links.
 * @return string[]
 */
function init_plugin_suite_content_protector_add_settings_link( $links ) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'options-general.php?page=' . INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_SLUG ) ),
		esc_html__( 'Settings', 'init-content-protector' )
	);

	array_unshift( $links, $settings_link );

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'init_plugin_suite_content_protector_add_settings_link' );
