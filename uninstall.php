<?php
/**
 * Uninstall Init Content Protector
 *
 * This file is executed when the plugin is deleted via WordPress admin.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Option name used in settings-page.php
$option_name = 'init_plugin_suite_content_protector_settings';

// Delete plugin option
delete_option( $option_name );
