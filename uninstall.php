<?php
/**
 * Uninstall Init Content Protector.
 *
 * Runs when the plugin is deleted from the WordPress admin.
 *
 * @package Init_Content_Protector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove all plugin data for the current site.
 *
 * @return void
 */
function init_plugin_suite_content_protector_uninstall_site() {
	global $wpdb;

	delete_option( 'init_plugin_suite_content_protector_settings' );

	// Encrypted-content transients written by versions 1.4–1.6 ("icp_enc_*").
	// There is no API to delete transients by prefix, hence the direct query.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_icp_enc_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_icp_enc_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	$init_plugin_suite_content_protector_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $init_plugin_suite_content_protector_site_ids as $init_plugin_suite_content_protector_site_id ) {
		switch_to_blog( $init_plugin_suite_content_protector_site_id );
		init_plugin_suite_content_protector_uninstall_site();
		restore_current_blog();
	}
} else {
	init_plugin_suite_content_protector_uninstall_site();
}
