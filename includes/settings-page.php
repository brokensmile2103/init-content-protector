<?php
/**
 * Settings page.
 *
 * @package Init_Content_Protector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the settings page under Settings.
 *
 * @return void
 */
function init_plugin_suite_content_protector_register_settings_page() {
	add_options_page(
		__( 'Init Content Protector Settings', 'init-content-protector' ),
		__( 'Init Content Protector', 'init-content-protector' ),
		'manage_options',
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_SLUG,
		'init_plugin_suite_content_protector_render_settings_page'
	);
}
add_action( 'admin_menu', 'init_plugin_suite_content_protector_register_settings_page' );

/**
 * Register the plugin option.
 *
 * @return void
 */
function init_plugin_suite_content_protector_register_settings() {
	register_setting(
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION,
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'init_plugin_suite_content_protector_sanitize_settings',
			'default'           => init_plugin_suite_content_protector_default_settings(),
		)
	);
}
add_action( 'admin_init', 'init_plugin_suite_content_protector_register_settings' );

/**
 * Enqueue the settings screen script (Content Selector auto-detect).
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function init_plugin_suite_content_protector_admin_assets( $hook ) {
	if ( 'settings_page_' . INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_SLUG !== $hook ) {
		return;
	}

	wp_enqueue_script(
		'init-content-protector-admin',
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_ASSETS_URL . 'js/admin-settings.js',
		array(),
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION,
		true
	);

	// Latest published post as a live sample page to test selectors against.
	$sample_posts = get_posts(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'numberposts'            => 1,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);
	$sample_url   = ! empty( $sample_posts ) ? get_permalink( $sample_posts[0] ) : home_url( '/' );

	wp_localize_script(
		'init-content-protector-admin',
		'InitContentProtectorAdmin',
		array(
			'sample_url' => esc_url_raw( $sample_url ),
			// Common content-wrapper selectors across popular themes/builders.
			'candidates' => array(
				'.entry-content',
				'.post-content',
				'.elementor-widget-theme-post-content',
				'.td-post-content',
				'.single-post-content',
				'article .content',
				'main article',
				'.content-area .entry-content',
				'#content article',
			),
			'i18n'       => array(
				'detecting' => __( 'Detecting…', 'init-content-protector' ),
				'notFound'  => __( 'No matching selector found on the sample page. Please enter it manually.', 'init-content-protector' ),
				'fetchFail' => __( 'Could not load the sample page to auto-detect. Please enter the selector manually.', 'init-content-protector' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'init_plugin_suite_content_protector_admin_assets' );

/**
 * Sanitize the settings array.
 *
 * @param mixed $input Raw input.
 * @return array
 */
function init_plugin_suite_content_protector_sanitize_settings( $input ) {
	$input    = is_array( $input ) ? $input : array();
	$defaults = init_plugin_suite_content_protector_default_settings();
	$output   = array();

	$checkbox = static function ( $key ) use ( $input ) {
		return ! empty( $input[ $key ] ) ? '1' : '0';
	};

	$output['post_types']       = array_values( array_filter( array_map( 'sanitize_key', (array) ( $input['post_types'] ?? array() ) ) ) );
	$output['content_mode']     = in_array( $input['content_mode'] ?? 'none', array( 'none', 'encrypt' ), true ) ? $input['content_mode'] : 'none';
	$output['encrypt_key']      = isset( $input['encrypt_key'] ) ? sanitize_text_field( wp_unslash( $input['encrypt_key'] ) ) : '';
	$output['encrypt_delivery'] = in_array( $input['encrypt_delivery'] ?? 'inline', array( 'inline', 'rest' ), true ) ? $input['encrypt_delivery'] : 'inline';
	$output['headless_detect']  = $checkbox( 'headless_detect' );
	$output['content_selector'] = isset( $input['content_selector'] ) ? sanitize_text_field( wp_unslash( $input['content_selector'] ) ) : '';
	$output['js_protect']       = $checkbox( 'js_protect' );
	$output['disable_devtool']  = $checkbox( 'disable_devtool' );
	$output['antisnap']         = $checkbox( 'antisnap' );
	$output['inject_noise']     = $checkbox( 'inject_noise' );
	$output['noise_rate']       = isset( $input['noise_rate'] ) ? max( 1, min( 50, (int) $input['noise_rate'] ) ) : $defaults['noise_rate'];
	$output['keywords']         = isset( $input['keywords'] ) ? sanitize_text_field( wp_unslash( $input['keywords'] ) ) : '';
	$output['excluded_roles']   = array_values( array_filter( array_map( 'sanitize_key', (array) ( $input['excluded_roles'] ?? array() ) ) ) );

	if ( '' === $output['content_selector'] ) {
		$output['content_selector'] = $defaults['content_selector'];
	}

	return $output;
}

/**
 * Render the settings page.
 *
 * @return void
 */
function init_plugin_suite_content_protector_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$option              = init_plugin_suite_content_protector_get_settings();
	$name                = INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION;
	$content_mode        = $option['content_mode'];
	$selected_post_types = $option['post_types'];
	$selected_roles      = $option['excluded_roles'];

	$post_types = get_post_types( array( 'public' => true ), 'objects' );
	unset( $post_types['attachment'] );

	$roles = function_exists( 'get_editable_roles' ) ? get_editable_roles() : wp_roles()->roles;
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Init Content Protector Settings', 'init-content-protector' ); ?></h1>

		<form method="post" action="options.php">
			<?php settings_fields( $name ); ?>
			<table class="form-table" role="presentation">
				<tr><th colspan="2"><h2><?php esc_html_e( 'Content Protection', 'init-content-protector' ); ?></h2></th></tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Apply Protection to Post Types', 'init-content-protector' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( $post_types as $post_type => $obj ) : ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[post_types][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, $selected_post_types, true ) ); ?>>
									<?php echo esc_html( $obj->labels->name ); ?>
								</label><br>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Choose which post types to apply content protection to.', 'init-content-protector' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Content Protection Mode', 'init-content-protector' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="<?php echo esc_attr( $name ); ?>[content_mode]" value="none" <?php checked( $content_mode, 'none' ); ?> />
								<?php esc_html_e( 'No Protection', 'init-content-protector' ); ?>
							</label><br>
							<label>
								<input type="radio" name="<?php echo esc_attr( $name ); ?>[content_mode]" value="encrypt" <?php checked( $content_mode, 'encrypt' ); ?> />
								<?php esc_html_e( 'Encrypt Content (decode via JS)', 'init-content-protector' ); ?>
							</label>
						</fieldset>
						<p class="description">
							<?php esc_html_e( 'Encrypting the content helps prevent crawlers from reading raw HTML. Do not enable if you need SEO access.', 'init-content-protector' ); ?>
							<br>
							<?php esc_html_e( 'Decryption uses the browser\'s built-in Web Crypto API. The bundled CryptoJS fallback (about 60 KB) is only loaded on sites not served over HTTPS, or on demand in the rare browser without Web Crypto support.', 'init-content-protector' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="encrypt_key"><?php esc_html_e( 'Custom Encryption Key', 'init-content-protector' ); ?></label></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $name ); ?>[encrypt_key]" id="encrypt_key" value="<?php echo esc_attr( $option['encrypt_key'] ); ?>" class="regular-text" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Use a unique key for this website. Leave blank to use default key from plugin constant.', 'init-content-protector' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Decryption Key Delivery', 'init-content-protector' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="<?php echo esc_attr( $name ); ?>[encrypt_delivery]" value="inline" <?php checked( $option['encrypt_delivery'], 'inline' ); ?> />
								<?php esc_html_e( 'Inline (default)', 'init-content-protector' ); ?>
							</label><br>
							<label>
								<input type="radio" name="<?php echo esc_attr( $name ); ?>[encrypt_delivery]" value="rest" <?php checked( $option['encrypt_delivery'], 'rest' ); ?> />
								<?php esc_html_e( 'Enhanced (fetch key via REST API after page load)', 'init-content-protector' ); ?>
							</label>
						</fieldset>
						<p class="description">
							<?php esc_html_e( 'Inline puts the key directly in page HTML (simple, works everywhere, but readable via view-source). Enhanced fetches the key from a REST API endpoint instead, keeping it out of cached/static HTML — better against basic scrapers and compatible with full-page caching. Neither mode makes content truly secret to a determined visitor running the page\'s own JavaScript. This endpoint is not rate-limited by the plugin; use your server/CDN/WAF if you need that.', 'init-content-protector' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="headless_detect"><?php esc_html_e( 'Enable Headless Browser Detection', 'init-content-protector' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[headless_detect]" id="headless_detect" value="1" <?php checked( $option['headless_detect'], '1' ); ?>>
							<?php esc_html_e( 'Withhold decryption from sessions that look like an automated browser (Puppeteer, Playwright, Selenium).', 'init-content-protector' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Only takes effect when Content Protection Mode is set to Encrypt — it has no effect in "No Protection" mode, since there is no decryption step left to withhold. Scores several client-side automation signals together, so no single false-positive flag can block a real visitor on its own.', 'init-content-protector' ); ?>
							<br>
							<?php esc_html_e( 'This raises the cost of automated scraping but cannot detect every setup — well-configured "stealth" automation tooling can evade individual signals. Treat it as an extra deterrent layer, not a guarantee.', 'init-content-protector' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="content_selector"><?php esc_html_e( 'Content Selector (for JS injection)', 'init-content-protector' ); ?></label></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $name ); ?>[content_selector]" id="content_selector" value="<?php echo esc_attr( $option['content_selector'] ); ?>" class="regular-text" />
						<button type="button" id="icp-autodetect-selector" class="button"><?php esc_html_e( 'Auto-detect', 'init-content-protector' ); ?></button>
						<p class="description">
							<?php
							printf(
								/* translators: %s: example CSS selector wrapped in <code> */
								esc_html__( 'CSS selector to locate content wrapper. Used for decryption and JS protection. Example: %s', 'init-content-protector' ),
								'<code>.entry-content</code>'
							);
							?>
							<br>
							<?php esc_html_e( '"Auto-detect" loads your most recent published post in the background and tests common theme selectors against it.', 'init-content-protector' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="js_protect"><?php esc_html_e( 'Enable JavaScript Content Protection', 'init-content-protector' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[js_protect]" id="js_protect" value="1" <?php checked( $option['js_protect'], '1' ); ?>>
							<?php esc_html_e( 'Block printing, prevent right-click and text selection, and interfere with browser developer tools.', 'init-content-protector' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Blocks copying, selecting, printing, and inspecting content via JS. Includes right-click disable, keyboard shortcut blocking (Ctrl/⌘ + C, P, U, etc.), and DevTools interference.', 'init-content-protector' ); ?>
						</p>
					</td>
				</tr>

				<tr><th colspan="2"><h2><?php esc_html_e( 'Advanced Browser Protection (Experimental)', 'init-content-protector' ); ?></h2></th></tr>

				<tr>
					<th scope="row">
						<label for="disable_devtool"><?php esc_html_e( 'Enable Advanced DevTools Blocking', 'init-content-protector' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[disable_devtool]" id="disable_devtool" value="1" <?php checked( $option['disable_devtool'], '1' ); ?>>
							<?php esc_html_e( 'Actively detect when browser DevTools are opened (not just keyboard shortcuts) and close or redirect away from the tab.', 'init-content-protector' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Uses the third-party "disable-devtool" library alongside the basic protection above. Independent of "Enable JavaScript Content Protection" — use either one alone, or both together.', 'init-content-protector' ); ?>
							<br>
							<?php esc_html_e( 'When DevTools stay open, this tries to close the tab; if the browser blocks that (the common case, since most browsers only allow closing tabs a script itself opened), it redirects the visitor back to your homepage instead.', 'init-content-protector' ); ?>
							<br>
							<?php esc_html_e( 'On the homepage itself the page content is hidden instead of redirected, so a false detection can never cause an endless reload loop.', 'init-content-protector' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="antisnap"><?php esc_html_e( 'Enable Anti-Screenshot Protection (Init AntiSnap)', 'init-content-protector' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[antisnap]" id="antisnap" value="1" <?php checked( $option['antisnap'], '1' ); ?>>
							<?php esc_html_e( 'Detect scroll-jump and DevTools-triggered layout patterns typical of automated screenshot or scraping tools, and briefly blur the page with a warning.', 'init-content-protector' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Real readers scrolling and resizing normally are not affected. The blur clears itself automatically after a few seconds, or as soon as the tab regains focus.', 'init-content-protector' ); ?>
							<br>
							<?php esc_html_e( 'Init AntiSnap 6.0 only reacts to the evenly spaced, step-by-step jumps that scroll-and-stitch capture tools make — not to find-in-page, anchor links, scrollbar dragging, zooming, or pop-ups that lock page scrolling.', 'init-content-protector' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="inject_noise"><?php esc_html_e( 'Inject Noise', 'init-content-protector' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[inject_noise]" id="inject_noise" value="1" <?php checked( $option['inject_noise'], '1' ); ?>>
							<?php esc_html_e( 'Insert invisible junk spans randomly to confuse crawlers.', 'init-content-protector' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Invisible to real readers. Junk spans use display: none.', 'init-content-protector' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="noise_rate"><?php esc_html_e( 'Noise Injection Rate', 'init-content-protector' ); ?></label>
					</th>
					<td>
						<input type="number" name="<?php echo esc_attr( $name ); ?>[noise_rate]" id="noise_rate" min="1" max="50" value="<?php echo esc_attr( (string) (int) $option['noise_rate'] ); ?>" class="small-text" /> %
						<p class="description"><?php esc_html_e( 'Chance (per word) of inserting a noise span. Higher values confuse crawlers more but add more hidden markup to the page. 1–50%, default 7%.', 'init-content-protector' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="keywords"><?php esc_html_e( 'Sensitive Keywords to Obscure', 'init-content-protector' ); ?></label></th>
					<td>
						<textarea name="<?php echo esc_attr( $name ); ?>[keywords]" id="keywords" rows="3" class="large-text"><?php echo esc_textarea( $option['keywords'] ); ?></textarea>
						<p class="description">
							<?php
							printf(
								/* translators: %s: example comma-separated keyword list wrapped in <code> */
								esc_html__( 'Enter keywords to hide. Separate by commas. Example: %s', 'init-content-protector' ),
								'<code>dragon ball,one piece,naruto</code>'
							);
							?>
							<br>
							<?php esc_html_e( 'These will be replaced visually using CSS pseudo-elements and hidden from raw HTML.', 'init-content-protector' ); ?>
						</p>
					</td>
				</tr>

				<tr><th colspan="2"><h2><?php esc_html_e( 'Global Exceptions', 'init-content-protector' ); ?></h2></th></tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Exclude User Roles', 'init-content-protector' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( $roles as $role_key => $role_data ) : ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[excluded_roles][]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, $selected_roles, true ) ); ?>>
									<?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
								</label><br>
							<?php endforeach; ?>
						</fieldset>
						<p class="description">
							<?php esc_html_e( 'This applies to every protection feature on this page at once — Content Protection Mode, JavaScript Content Protection, Advanced DevTools Blocking, Anti-Screenshot Protection, Inject Noise, and Sensitive Keywords. Selected roles are fully excluded from all of them together and will always see the original, unprotected content — you can\'t exclude a role from just one feature.', 'init-content-protector' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<div style="padding: 1em; background: #fff8e5; border-left: 4px solid #ffba00; margin-top: 1em;">
			<p><strong><?php esc_html_e( 'Important Notes When Using This Plugin:', 'init-content-protector' ); ?></strong></p>
			<ul style="list-style: disc; margin-left: 1.5em;">
				<li><?php esc_html_e( 'Use encryption only if you understand its impact on SEO, caching, and content accessibility.', 'init-content-protector' ); ?></li>
				<li><?php esc_html_e( 'JavaScript protection relies on client-side execution. It can be bypassed by experienced users.', 'init-content-protector' ); ?></li>
				<li><?php esc_html_e( 'For best results, combine multiple protection layers (encryption, JS, keyword cloaking).', 'init-content-protector' ); ?></li>
				<li><?php esc_html_e( 'This plugin does not prevent content theft 100%. It raises the difficulty level for scraping.', 'init-content-protector' ); ?></li>
				<li><?php esc_html_e( 'DevTools blocking, anti-screenshot detection, and headless browser detection are all heuristic and best-effort. They can occasionally trigger on unusual browsers/extensions, and can be bypassed by a determined user or well-configured automation tooling — treat them as an extra deterrent layer, not a guarantee.', 'init-content-protector' ); ?></li>
			</ul>
		</div>
	</div>
	<?php
}
