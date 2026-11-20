<?php
/**
 * Per-post protection switch for the "Only selected posts" scope (1.10).
 *
 * - An editor box "Content Protection" with a "Protect this post" checkbox on
 *   every post type chosen under "Apply Protection to Post Types".
 * - A "Protected" column and "Turn protection on / off" bulk actions on the
 *   list screens of those post types.
 *
 * All of this is only shown while the scope is "Only selected posts". The
 * switch is stored as post meta (`_init_content_protector_enabled` = '1'),
 * which is kept when the scope is changed back, so switching scopes is
 * lossless.
 *
 * @package Init_Content_Protector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post types that get the editor box, column and bulk actions.
 *
 * @return string[] Empty unless the "Only selected posts" scope is active.
 */
function init_plugin_suite_content_protector_switchable_post_types() {
	$settings = init_plugin_suite_content_protector_get_settings();

	if ( ! init_plugin_suite_content_protector_is_selected_scope( $settings ) ) {
		return array();
	}

	return array_values( array_filter( (array) $settings['post_types'], 'post_type_exists' ) );
}

/**
 * Register the post meta.
 *
 * Registered for every post type, since the list of protected types can
 * change at any time. Not exposed to the REST API: the editor box below works
 * in both the classic and the block editor.
 *
 * @return void
 */
function init_plugin_suite_content_protector_register_post_meta() {
	register_meta(
		'post',
		INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_META_KEY,
		array(
			'type'              => 'string',
			'description'       => __( 'Whether Init Content Protector protects this post ("1") when the protection scope is "Only posts where protection is turned on".', 'init-content-protector' ),
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => false,
			'sanitize_callback' => 'init_plugin_suite_content_protector_sanitize_post_switch',
			'auth_callback'     => 'init_plugin_suite_content_protector_post_meta_auth',
		)
	);
}
add_action( 'init', 'init_plugin_suite_content_protector_register_post_meta' );

/**
 * Sanitize the stored switch value.
 *
 * @param mixed $value Raw value.
 * @return string '1' or ''.
 */
function init_plugin_suite_content_protector_sanitize_post_switch( $value ) {
	return ( '1' === (string) $value || true === $value ) ? '1' : '';
}

/**
 * Who may change the switch: anyone who can edit the post.
 *
 * @param bool   $allowed  Whether the user can edit the meta key.
 * @param string $meta_key Meta key.
 * @param int    $post_id  Post ID.
 * @return bool
 */
function init_plugin_suite_content_protector_post_meta_auth( $allowed, $meta_key, $post_id ) {
	return current_user_can( 'edit_post', (int) $post_id );
}

/**
 * Set or clear the switch for one post.
 *
 * Off is stored by deleting the meta row instead of saving an empty value,
 * so unprotected posts cost nothing in the postmeta table.
 *
 * @param int  $post_id Post ID.
 * @param bool $enabled Whether protection is on.
 * @return void
 */
function init_plugin_suite_content_protector_set_post_switch( $post_id, $enabled ) {
	if ( $enabled ) {
		update_post_meta( (int) $post_id, INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_META_KEY, '1' );
	} else {
		delete_post_meta( (int) $post_id, INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_META_KEY );
	}
}

/*
 * ---------------------------------------------------------------------------
 * Editor box
 * ---------------------------------------------------------------------------
 */

/**
 * Add the "Content Protection" box to the protected post types.
 *
 * Works in both the classic and the block editor (as a sidebar panel).
 *
 * @return void
 */
function init_plugin_suite_content_protector_add_meta_box() {
	foreach ( init_plugin_suite_content_protector_switchable_post_types() as $post_type ) {
		add_meta_box(
			'init-content-protector',
			__( 'Content Protection', 'init-content-protector' ),
			'init_plugin_suite_content_protector_render_meta_box',
			$post_type,
			'side',
			'default'
		);
	}
}
add_action( 'add_meta_boxes', 'init_plugin_suite_content_protector_add_meta_box' );

/**
 * Render the editor box.
 *
 * @param WP_Post $post Post being edited.
 * @return void
 */
function init_plugin_suite_content_protector_render_meta_box( $post ) {
	wp_nonce_field( 'init_content_protector_post_switch', 'init_content_protector_post_nonce' );
	?>
	<p>
		<label for="init-content-protector-enabled">
			<input type="checkbox" name="init_content_protector_enabled" id="init-content-protector-enabled" value="1" <?php checked( init_plugin_suite_content_protector_is_post_opted_in( $post->ID ) ); ?> />
			<?php esc_html_e( 'Protect this post', 'init-content-protector' ); ?>
		</label>
	</p>
	<p class="description">
		<?php esc_html_e( 'Applies every protection layer that is switched on in the plugin settings to this post only.', 'init-content-protector' ); ?>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_SLUG ) ); ?>"><?php esc_html_e( 'Settings', 'init-content-protector' ); ?></a>
		<?php endif; ?>
	</p>
	<?php
}

/**
 * Save the editor box.
 *
 * The block editor saves the post through the REST API first (no nonce in
 * that request, so it is skipped here) and then posts the meta box form.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @return void
 */
function init_plugin_suite_content_protector_save_meta_box( $post_id, $post ) {
	if ( ! isset( $_POST['init_content_protector_post_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['init_content_protector_post_nonce'] ) ), 'init_content_protector_post_switch' )
	) {
		return;
	}

	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( ! in_array( $post->post_type, init_plugin_suite_content_protector_switchable_post_types(), true ) ) {
		return;
	}

	init_plugin_suite_content_protector_set_post_switch( $post_id, ! empty( $_POST['init_content_protector_enabled'] ) );
}
add_action( 'save_post', 'init_plugin_suite_content_protector_save_meta_box', 10, 2 );

/*
 * ---------------------------------------------------------------------------
 * List screen: column and bulk actions
 * ---------------------------------------------------------------------------
 */

/**
 * Hook the column and bulk actions into the protected post types' list screens.
 *
 * @return void
 */
function init_plugin_suite_content_protector_init_list_screens() {
	foreach ( init_plugin_suite_content_protector_switchable_post_types() as $post_type ) {
		add_filter( 'manage_' . $post_type . '_posts_columns', 'init_plugin_suite_content_protector_add_column' );
		add_action( 'manage_' . $post_type . '_posts_custom_column', 'init_plugin_suite_content_protector_render_column', 10, 2 );
		add_filter( 'bulk_actions-edit-' . $post_type, 'init_plugin_suite_content_protector_add_bulk_actions' );
		add_filter( 'handle_bulk_actions-edit-' . $post_type, 'init_plugin_suite_content_protector_handle_bulk_actions', 10, 3 );
	}
}
add_action( 'admin_init', 'init_plugin_suite_content_protector_init_list_screens' );

/**
 * Add the "Protected" column.
 *
 * @param string[] $columns Columns.
 * @return string[]
 */
function init_plugin_suite_content_protector_add_column( $columns ) {
	$columns['init_content_protector'] = '<span class="dashicons dashicons-lock" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Protected', 'init-content-protector' ) . '</span>';

	return $columns;
}

/**
 * Render the "Protected" column.
 *
 * Post meta is already primed for every listed post, so this adds no query.
 *
 * @param string $column  Column key.
 * @param int    $post_id Post ID.
 * @return void
 */
function init_plugin_suite_content_protector_render_column( $column, $post_id ) {
	if ( 'init_content_protector' !== $column ) {
		return;
	}

	if ( init_plugin_suite_content_protector_is_post_opted_in( $post_id ) ) {
		echo '<span class="dashicons dashicons-lock" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Protected', 'init-content-protector' ) . '</span>';
	} else {
		echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__( 'Not protected', 'init-content-protector' ) . '</span>';
	}
}

/**
 * Keep the column narrow.
 *
 * @param string $hook Current admin page hook.
 * @return void
 */
function init_plugin_suite_content_protector_column_style( $hook ) {
	if ( 'edit.php' !== $hook ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->post_type, init_plugin_suite_content_protector_switchable_post_types(), true ) ) {
		return;
	}

	wp_register_style( 'init-content-protector-list', false, array(), INIT_PLUGIN_SUITE_CONTENT_PROTECTOR_VERSION );
	wp_enqueue_style( 'init-content-protector-list' );
	wp_add_inline_style( 'init-content-protector-list', '.fixed .column-init_content_protector{width:2.5em;text-align:center}' );
}
add_action( 'admin_enqueue_scripts', 'init_plugin_suite_content_protector_column_style' );

/**
 * Add the bulk actions.
 *
 * @param string[] $actions Bulk actions.
 * @return string[]
 */
function init_plugin_suite_content_protector_add_bulk_actions( $actions ) {
	$actions['icp_protect_on']  = __( 'Turn content protection on', 'init-content-protector' );
	$actions['icp_protect_off'] = __( 'Turn content protection off', 'init-content-protector' );

	return $actions;
}

/**
 * Run a bulk action.
 *
 * The list screen has already checked its own bulk-posts nonce before this
 * filter runs. Each post is still checked for edit_post.
 *
 * @param string $redirect_to Redirect URL.
 * @param string $action      Bulk action.
 * @param int[]  $post_ids    Selected post IDs.
 * @return string
 */
function init_plugin_suite_content_protector_handle_bulk_actions( $redirect_to, $action, $post_ids ) {
	if ( 'icp_protect_on' !== $action && 'icp_protect_off' !== $action ) {
		return $redirect_to;
	}

	$changed = 0;
	foreach ( (array) $post_ids as $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id > 0 && current_user_can( 'edit_post', $post_id ) ) {
			init_plugin_suite_content_protector_set_post_switch( $post_id, 'icp_protect_on' === $action );
			++$changed;
		}
	}

	return add_query_arg(
		array(
			'icp_bulk'    => 'icp_protect_on' === $action ? 'on' : 'off',
			'icp_changed' => $changed,
		),
		remove_query_arg( array( 'icp_bulk', 'icp_changed' ), $redirect_to )
	);
}

/**
 * Confirm a finished bulk action.
 *
 * @return void
 */
function init_plugin_suite_content_protector_bulk_notice() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values from our own redirect.
	if ( ! isset( $_GET['icp_bulk'], $_GET['icp_changed'] ) ) {
		return;
	}

	$state   = sanitize_key( wp_unslash( $_GET['icp_bulk'] ) );
	$changed = absint( $_GET['icp_changed'] );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( 'on' === $state ) {
		/* translators: %s: number of posts */
		$message = _n( 'Content protection turned on for %s item.', 'Content protection turned on for %s items.', $changed, 'init-content-protector' );
	} else {
		/* translators: %s: number of posts */
		$message = _n( 'Content protection turned off for %s item.', 'Content protection turned off for %s items.', $changed, 'init-content-protector' );
	}

	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html( sprintf( $message, number_format_i18n( $changed ) ) )
	);
}
add_action( 'admin_notices', 'init_plugin_suite_content_protector_bulk_notice' );

/**
 * Remove the bulk action result arguments from the address bar after display.
 *
 * @param string[] $args Removable query arguments.
 * @return string[]
 */
function init_plugin_suite_content_protector_removable_query_args( $args ) {
	$args[] = 'icp_bulk';
	$args[] = 'icp_changed';

	return $args;
}
add_filter( 'removable_query_args', 'init_plugin_suite_content_protector_removable_query_args' );
