<?php

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'init_plugin_suite_content_protector_register_settings_page' );
add_action( 'admin_init', 'init_plugin_suite_content_protector_register_settings' );

function init_plugin_suite_content_protector_register_settings_page() {
    add_options_page(
        __( 'Init Content Protector Settings', 'init-content-protector' ),
        __( 'Init Content Protector', 'init-content-protector' ),
        'manage_options',
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_SLUG,
        'init_plugin_suite_content_protector_render_settings_page'
    );
}

function init_plugin_suite_content_protector_register_settings() {
    register_setting(
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION,
        INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION,
        'init_plugin_suite_content_protector_sanitize_settings'
    );
}

function init_plugin_suite_content_protector_sanitize_settings( $input ) {
    $output = [];

    $output['post_types']       = array_map( 'sanitize_key', (array) ( $input['post_types'] ?? [] ) );
    $output['content_mode']     = in_array( $input['content_mode'] ?? 'none', ['none', 'encrypt'], true ) ? $input['content_mode'] : 'none';
    $output['encrypt_key']      = isset( $input['encrypt_key'] ) ? sanitize_text_field( $input['encrypt_key'] ) : '';
    $output['content_selector'] = isset( $input['content_selector'] ) ? sanitize_text_field( $input['content_selector'] ) : '.entry-content';
    $output['js_protect']       = ! empty( $input['js_protect'] ) ? '1' : '0';
    $output['inject_noise']     = ! empty( $input['inject_noise'] ) ? '1' : '0';
    $output['keywords']         = isset( $input['keywords'] ) ? sanitize_text_field( $input['keywords'] ) : '';
    $output['excluded_roles']   = array_map( 'sanitize_key', (array) ( $input['excluded_roles'] ?? [] ) );

    return $output;
}

function init_plugin_suite_content_protector_render_settings_page() {
    $option = get_option( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION, [] );
    $content_mode = $option['content_mode'] ?? 'none';
    $selected_post_types = $option['post_types'] ?? [];

    $post_types = get_post_types( ['public' => true], 'objects' );
    unset( $post_types['attachment'] );

    $selected_roles = $option['excluded_roles'] ?? [];
    $roles          = function_exists( 'get_editable_roles' ) ? get_editable_roles() : wp_roles()->roles;
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Init Content Protector Settings', 'init-content-protector' ); ?></h1>

        <form method="post" action="options.php">
            <?php settings_fields( esc_attr( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION ) ); ?>
            <table class="form-table" role="presentation">
                <tr><th colspan="2"><h2><?php esc_html_e( 'Content Protection', 'init-content-protector' ); ?></h2></th></tr>

                <tr>
                    <th scope="row"><?php esc_html_e( 'Apply Protection to Post Types', 'init-content-protector' ); ?></th>
                    <td>
                        <fieldset>
                            <?php foreach ( $post_types as $post_type => $obj ) : ?>
                                <label>
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION ); ?>[post_types][]"
                                           value="<?php echo esc_attr( $post_type ); ?>"
                                           <?php checked( in_array( $post_type, $selected_post_types, true ) ); ?>>
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
                                <input type="radio" name="<?php echo esc_attr( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION ); ?>[content_mode]" value="none" <?php checked( $content_mode, 'none' ); ?> />
                                <?php esc_html_e( 'No Protection', 'init-content-protector' ); ?>
                            </label><br>
                            <label>
                                <input type="radio" name="<?php echo esc_attr( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION ); ?>[content_mode]" value="encrypt" <?php checked( $content_mode, 'encrypt' ); ?> />
                                <?php esc_html_e( 'Encrypt Content (decode via JS)', 'init-content-protector' ); ?>
                            </label>
                        </fieldset>
                        <p class="description"><?php esc_html_e( 'Encrypting the content helps prevent crawlers from reading raw HTML. Do not enable if you need SEO access.', 'init-content-protector' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="encrypt_key"><?php esc_html_e( 'Custom Encryption Key', 'init-content-protector' ); ?></label></th>
                    <td>
                        <input type="text" name="<?php echo esc_attr( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION ); ?>[encrypt_key]" id="encrypt_key" value="<?php echo esc_attr( $option['encrypt_key'] ?? '' ); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e( 'Use a unique key for this website. Leave blank to use default key from plugin constant.', 'init-content-protector' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="content_selector"><?php esc_html_e( 'Content Selector (for JS injection)', 'init-content-protector' ); ?></label></th>
                    <td>
                        <input type="text" name="<?php echo esc_attr( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION ); ?>[content_selector]" id="content_selector" value="<?php echo esc_attr( $option['content_selector'] ?? '.entry-content' ); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e( 'CSS selector to locate content wrapper. Used for decryption and JS protection. Example: <code>.entry-content</code>', 'init-content-protector' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="js_protect"><?php esc_html_e( 'Enable JavaScript Content Protection', 'init-content-protector' ); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION ); ?>[js_protect]" value="1" <?php checked( $option['js_protect'] ?? '0', '1' ); ?>>
                            <?php esc_html_e( 'Block printing, prevent right-click and text selection, and interfere with browser developer tools.', 'init-content-protector' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Blocks copying, selecting, printing, and inspecting content via JS. Includes right-click disable, keyboard shortcut blocking (Ctrl/⌘ + C, P, U, etc.), and DevTools interference.', 'init-content-protector' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="inject_noise"><?php esc_html_e( 'Inject Noise', 'init-content-protector' ); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION ); ?>[inject_noise]" value="1" <?php checked( $option['inject_noise'] ?? '0', '1' ); ?>>
                            <?php esc_html_e( 'Insert invisible junk spans randomly to confuse crawlers.', 'init-content-protector' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Invisible to real readers. Junk spans use display: none.', 'init-content-protector' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="keywords"><?php esc_html_e( 'Sensitive Keywords to Obscure', 'init-content-protector' ); ?></label></th>
                    <td>
                        <textarea name="<?php echo esc_attr( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION ); ?>[keywords]" id="keywords" rows="3" class="large-text"><?php
                            echo esc_textarea( $option['keywords'] ?? '' );
                        ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'Enter keywords to hide. Separate by commas. Example: <code>dragon ball,one piece,naruto</code>', 'init-content-protector' ); ?><br>
                            <?php esc_html_e( 'These will be replaced visually using CSS pseudo-elements and hidden from raw HTML.', 'init-content-protector' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e( 'Exclude User Roles', 'init-content-protector' ); ?></th>
                    <td>
                        <fieldset>
                            <?php foreach ( $roles as $role_key => $role_data ) : ?>
                                <label>
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_OPTION ); ?>[excluded_roles][]"
                                           value="<?php echo esc_attr( $role_key ); ?>"
                                        <?php checked( in_array( $role_key, $selected_roles, true ) ); ?>>
                                    <?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
                                </label><br>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description">
                            <?php esc_html_e( 'Content protection will not be applied for these roles. Selected roles will always see the original, unprotected content.', 'init-content-protector' ); ?>
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
            </ul>
        </div>
    </div>
    <?php
}
