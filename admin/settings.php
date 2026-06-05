<?php
/**
 * Admin settings page for Church Events.
 * Organised into tabs: Import, Display, Interactions, Fields, Styling.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the settings menu page.
 */
function ce_add_settings_page() {
	add_submenu_page(
		'edit.php?post_type=event',
		__( 'Church Events Settings', 'church-events' ),
		__( 'Settings', 'church-events' ),
		'manage_options',
		'church-events-settings',
		'ce_render_settings_page'
	);
}
add_action( 'admin_menu', 'ce_add_settings_page' );

/**
 * Handle manual cache clear from settings page.
 */
function ce_handle_clear_cache() {
	if (
		isset( $_POST['ce_clear_cache'] ) &&
		check_admin_referer( 'ce_clear_cache_action', 'ce_clear_cache_nonce' )
	) {
		ce_clear_rest_cache();
		add_settings_error( 'ce_settings', 'cache_cleared', __( 'Event cache cleared.', 'church-events' ), 'success' );
	}
}
add_action( 'admin_init', 'ce_handle_clear_cache' );

/**
 * Register all settings.
 */
function ce_register_settings() {
	register_setting( 'ce_settings', 'ce_settings', array(
		'sanitize_callback' => 'ce_sanitize_settings',
	) );
}
add_action( 'admin_init', 'ce_register_settings' );

/**
 * Fire ce_settings_saved after options are updated so importers can reschedule cron.
 */
add_action( 'update_option_ce_settings', function() {
	do_action( 'ce_settings_saved' );
} );

/**
 * Sanitize settings on save.
 *
 * @param array $input
 * @return array
 */
function ce_sanitize_settings( $input ) {
	$sanitized = get_option( 'ce_settings', array() );

	// Import
	$sanitized['source_type']       = isset( $input['source_type'] ) ? sanitize_text_field( $input['source_type'] ) : 'churchsuite';
	$sanitized['churchsuite_url']   = ! empty( $input['churchsuite_url'] ) ? esc_url_raw( $input['churchsuite_url'] ) : ( $sanitized['churchsuite_url'] ?? '' );
	$sanitized['google_cal_id']     = isset( $input['google_cal_id'] ) ? sanitize_text_field( $input['google_cal_id'] ) : ( $sanitized['google_cal_id'] ?? '' );
	$sanitized['google_api_key']    = isset( $input['google_api_key'] ) ? sanitize_text_field( $input['google_api_key'] ) : ( $sanitized['google_api_key'] ?? '' );
	$sanitized['sync_interval']     = isset( $input['sync_interval'] ) ? sanitize_text_field( $input['sync_interval'] ) : ( $sanitized['sync_interval'] ?? 'hourly' );
	$sanitized['sync_key']          = ! empty( $input['sync_key'] ) ? sanitize_text_field( $input['sync_key'] ) : ( $sanitized['sync_key'] ?? '' );

	// CPT rewrite slug — changing this requires a permalink flush (handled below)
	$new_slug = ! empty( $input['cpt_slug'] ) ? sanitize_title( $input['cpt_slug'] ) : '';
	$sanitized['cpt_slug'] = ! empty( $new_slug ) ? $new_slug : ( $sanitized['cpt_slug'] ?? 'events' );

	// Flush rewrite rules if the slug changed, so the new URLs work immediately
	if ( isset( $input['cpt_slug'] ) ) {
		$old_settings = get_option( 'ce_settings', array() );
		$old_slug     = ! empty( $old_settings['cpt_slug'] ) ? $old_settings['cpt_slug'] : 'events';
		if ( $old_slug !== $sanitized['cpt_slug'] ) {
			add_action( 'shutdown', 'flush_rewrite_rules' );
		}
	}

	// Display
	$valid_views = array( 'calendar', 'cards', 'list' );
	if ( isset( $input['enabled_views'] ) && is_array( $input['enabled_views'] ) ) {
		$enabled = array_values( array_intersect( $input['enabled_views'], $valid_views ) );
		// Must enable at least one view
		$sanitized['enabled_views'] = ! empty( $enabled ) ? $enabled : array( 'calendar', 'cards' );
	} elseif ( ! isset( $sanitized['enabled_views'] ) ) {
		// Genuine first install — migrate from legacy list_style + default_view if present
		$old_list_style  = $sanitized['list_style'] ?? 'cards';
		$old_default     = $sanitized['default_view'] ?? 'toggle';
		if ( $old_default === 'calendar' ) {
			$sanitized['enabled_views'] = array( 'calendar' );
		} elseif ( $old_default === 'list' ) {
			$sanitized['enabled_views'] = array( $old_list_style === 'agenda' ? 'list' : 'cards' );
		} else {
			$sanitized['enabled_views'] = array( 'calendar', $old_list_style === 'agenda' ? 'list' : 'cards' );
		}
	}
	// else: field absent but stored value already exists — keep it unchanged
	$enabled_views = $sanitized['enabled_views'];

	// Ensure default_view is always one of the enabled views
	if ( isset( $input['default_view'] ) ) {
		$requested_default          = sanitize_text_field( $input['default_view'] );
		$sanitized['default_view']  = in_array( $requested_default, $enabled_views, true ) ? $requested_default : $enabled_views[0];
	} elseif ( ! isset( $sanitized['default_view'] ) || ! in_array( $sanitized['default_view'], $enabled_views, true ) ) {
		$sanitized['default_view']  = $enabled_views[0];
	}

	// Mobile fallback view — must be a non-calendar enabled view, or empty if only calendar is enabled
	$non_cal_views = array_values( array_filter( $enabled_views, fn( $v ) => $v !== 'calendar' ) );
	if ( isset( $input['mobile_view'] ) ) {
		$requested_mobile = sanitize_text_field( $input['mobile_view'] );
		$sanitized['mobile_view']   = $non_cal_views && in_array( $requested_mobile, $non_cal_views, true ) ? $requested_mobile : ( $non_cal_views[0] ?? '' );
	} elseif ( ! isset( $sanitized['mobile_view'] ) ) {
		$sanitized['mobile_view']   = $non_cal_views[0] ?? '';
	}

	// Keep list_style for backward compat (derived from enabled_views)
	$sanitized['list_style'] = in_array( 'list', $enabled_views, true ) ? 'agenda' : 'cards';
	$sanitized['image_ratio']      = isset( $input['image_ratio'] )      ? sanitize_text_field( $input['image_ratio'] )                                                         : ( $sanitized['image_ratio']      ?? '16:9'   );
	$sanitized['date_filter_type'] = isset( $input['date_filter_type'] ) ? sanitize_text_field( $input['date_filter_type'] )                                                    : ( $sanitized['date_filter_type'] ?? 'month'  );
	$sanitized['grid_columns']     = isset( $input['grid_columns'] )     ? absint( $input['grid_columns'] )                                                                     : ( $sanitized['grid_columns']     ?? 3       );
	$sanitized['per_page']         = isset( $input['per_page'] )         ? absint( $input['per_page'] )                                                                         : ( $sanitized['per_page']         ?? 12      );
	$sanitized['fallback_image_id'] = ! empty( $input['fallback_image_id'] ) ? absint( $input['fallback_image_id'] )                                                            : ( $sanitized['fallback_image_id'] ?? 0      );
	$sanitized['site_label']       = ( isset( $input['site_label'] ) && in_array( $input['site_label'], array( 'site', 'church' ), true ) ) ? $input['site_label']              : ( $sanitized['site_label']       ?? 'site'  );

	// Interactions
	$sanitized['card_interaction'] = ( isset( $input['card_interaction'] ) )                                           ? sanitize_text_field( $input['card_interaction'] )      : ( $sanitized['card_interaction'] ?? 'modal' );
	$sanitized['hover_preview']    = isset( $input['hover_preview'] )                                                  ? (bool) $input['hover_preview']                         : ( $sanitized['hover_preview']    ?? false   );

	// Fields — archive (serialized order + visibility)
	$sanitized['archive_fields']   = isset( $input['archive_fields'] )   ? ce_sanitize_fields_config( $input['archive_fields'] )                                                : ( $sanitized['archive_fields']   ?? ce_default_archive_fields() );

	// Fields — detail/modal
	$sanitized['detail_fields']    = isset( $input['detail_fields'] )    ? ce_sanitize_fields_config( $input['detail_fields'] )                                                 : ( $sanitized['detail_fields']    ?? ce_default_detail_fields()   );

	// Styling
	$sanitized['color_primary']     = isset( $input['color_primary'] ) ? sanitize_hex_color( $input['color_primary'] ) : ( $sanitized['color_primary'] ?? '#083C5E' );
	$sanitized['color_secondary']   = isset( $input['color_secondary'] ) ? sanitize_hex_color( $input['color_secondary'] ) : ( $sanitized['color_secondary'] ?? '#35878C' );
	$sanitized['color_text']        = isset( $input['color_text'] ) ? sanitize_hex_color( $input['color_text'] ) : ( $sanitized['color_text'] ?? '#333333' );
	$sanitized['color_accent']      = isset( $input['color_accent'] ) ? sanitize_hex_color( $input['color_accent'] ) : ( $sanitized['color_accent'] ?? '#C1789C' );
	$sanitized['custom_css']        = isset( $input['custom_css'] ) ? wp_strip_all_tags( $input['custom_css'] ) : ( $sanitized['custom_css'] ?? '' );

	return $sanitized;
}

/**
 * Sanitize a fields config array.
 *
 * @param mixed $input
 * @return array
 */
function ce_sanitize_fields_config( $input ) {
	if ( ! is_array( $input ) ) return array();
	$sanitized = array();
	foreach ( $input as $key => $field ) {
		$sanitized[ sanitize_key( $key ) ] = array(
			'enabled' => isset( $field['enabled'] ) ? (bool) $field['enabled'] : false,
			'order'   => isset( $field['order'] ) ? absint( $field['order'] ) : 0,
			'label'   => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : '',
		);
	}
	return $sanitized;
}

/**
 * Default field configuration for the archive/list view.
 *
 * @return array
 */
function ce_default_archive_fields() {
	return array(
		'featured_image'  => array( 'enabled' => true,  'order' => 1,  'label' => __( 'Featured Image', 'church-events' ) ),
		'title'           => array( 'enabled' => true,  'order' => 2,  'label' => __( 'Title', 'church-events' ) ),
		'date'            => array( 'enabled' => true,  'order' => 3,  'label' => __( 'Date', 'church-events' ) ),
		'time'            => array( 'enabled' => true,  'order' => 4,  'label' => __( 'Time', 'church-events' ) ),
		'location'        => array( 'enabled' => true,  'order' => 5,  'label' => __( 'Location', 'church-events' ) ),
		'excerpt'         => array( 'enabled' => true,  'order' => 6,  'label' => __( 'Excerpt', 'church-events' ) ),
		'categories'      => array( 'enabled' => false, 'order' => 7,  'label' => __( 'Categories', 'church-events' ) ),
		'booking_link'    => array( 'enabled' => true,  'order' => 8,  'label' => __( 'Booking Link', 'church-events' ) ),
	);
}

/**
 * Default field configuration for the detail/modal view.
 *
 * @return array
 */
function ce_default_detail_fields() {
	return array(
		'featured_image'  => array( 'enabled' => true,  'order' => 1,  'label' => __( 'Featured Image', 'church-events' ) ),
		'title'           => array( 'enabled' => true,  'order' => 2,  'label' => __( 'Title', 'church-events' ) ),
		'date'            => array( 'enabled' => true,  'order' => 3,  'label' => __( 'Date', 'church-events' ) ),
		'time'            => array( 'enabled' => true,  'order' => 4,  'label' => __( 'Time', 'church-events' ) ),
		'location'        => array( 'enabled' => true,  'order' => 5,  'label' => __( 'Location', 'church-events' ) ),
		'description'     => array( 'enabled' => true,  'order' => 6,  'label' => __( 'Full Description', 'church-events' ) ),
		'categories'      => array( 'enabled' => true,  'order' => 7,  'label' => __( 'Categories', 'church-events' ) ),
		'booking_link'    => array( 'enabled' => true,  'order' => 8,  'label' => __( 'Booking Link', 'church-events' ) ),
		'address'         => array( 'enabled' => false, 'order' => 9,  'label' => __( 'Address', 'church-events' ) ),
	);
}

/**
 * Get a single option value with fallback.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function ce_get_option( $key, $default = null ) {
	$settings = get_option( 'ce_settings', array() );
	return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
}

/**
 * Enqueue admin scripts and styles for the settings page.
 *
 * @param string $hook
 */
function ce_admin_enqueue( $hook ) {
	if ( strpos( $hook, 'church-events-settings' ) === false ) return;

	wp_enqueue_style(
		'ce-admin',
		CE_PLUGIN_URL . 'admin/css/admin.css',
		array(),
		CE_VERSION
	);

	wp_enqueue_media();

	wp_enqueue_script(
		'ce-admin',
		CE_PLUGIN_URL . 'admin/js/admin.js',
		array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker' ),
		CE_VERSION,
		true
	);

	wp_enqueue_style( 'wp-color-picker' );
}
add_action( 'admin_enqueue_scripts', 'ce_admin_enqueue' );

/**
 * Render the full settings page with tabs.
 */
function ce_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'import';
	$settings   = get_option( 'ce_settings', array() );

	$tabs = array(
		'import'       => __( 'Import', 'church-events' ),
		'display'      => __( 'Display', 'church-events' ),
		'interactions' => __( 'Interactions', 'church-events' ),
		'fields'       => __( 'Fields', 'church-events' ),
		'styling'      => __( 'Styling', 'church-events' ),
		'shortcodes'   => __( 'Shortcodes', 'church-events' ),
	);
	?>
	<div class="wrap ce-settings-wrap">
		<h1><?php esc_html_e( 'Church Events Settings', 'church-events' ); ?></h1>

		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $tab => $label ) : ?>
				<a href="?post_type=event&page=church-events-settings&tab=<?php echo esc_attr( $tab ); ?>"
				   class="nav-tab <?php echo $active_tab === $tab ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php if ( $active_tab === 'shortcodes' ) : ?>
			<div class="ce-tab-content">
				<?php ce_render_tab_shortcodes(); ?>
			</div>
		<?php else : ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'ce_settings' ); ?>

			<div class="ce-tab-content">
				<?php
				switch ( $active_tab ) {
					case 'import':
						ce_render_tab_import( $settings );
						break;
					case 'display':
						ce_render_tab_display( $settings );
						break;
					case 'interactions':
						ce_render_tab_interactions( $settings );
						break;
					case 'fields':
						ce_render_tab_fields( $settings );
						break;
					case 'styling':
						ce_render_tab_styling( $settings );
						break;
				}
				?>
			</div>

			<?php submit_button(); ?>

		</form>

		<form method="post" style="margin-top: 10px;">
			<?php wp_nonce_field( 'ce_clear_cache_action', 'ce_clear_cache_nonce' ); ?>
			<input type="submit" name="ce_clear_cache" class="button button-secondary" value="<?php esc_attr_e( 'Clear Event Cache', 'church-events' ); ?>" />
		</form>
		<?php endif; ?>
	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Tab renderers
// ---------------------------------------------------------------------------

function ce_render_tab_import( $s ) {
	$source   = isset( $s['source_type'] ) ? $s['source_type'] : 'churchsuite';
	$cs_url   = isset( $s['churchsuite_url'] ) ? $s['churchsuite_url'] : '';
	$gcal_id  = isset( $s['google_cal_id'] ) ? $s['google_cal_id'] : '';
	$gcal_key = isset( $s['google_api_key'] ) ? $s['google_api_key'] : '';
	$interval  = isset( $s['sync_interval'] ) ? $s['sync_interval'] : 'hourly';
	$sync_key  = isset( $s['sync_key'] ) ? $s['sync_key'] : '';
	$last_sync = get_option( 'ce_last_sync_status', null );
	?>
	<h2><?php esc_html_e( 'Import Settings', 'church-events' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Connect to either ChurchSuite or Google Calendar as your event source.', 'church-events' ); ?></p>

	<table class="form-table">
		<tr>
			<th><?php esc_html_e( 'Event Source', 'church-events' ); ?></th>
			<td>
				<label>
					<input type="radio" name="ce_settings[source_type]" value="churchsuite" <?php checked( $source, 'churchsuite' ); ?> />
					<?php esc_html_e( 'ChurchSuite', 'church-events' ); ?>
				</label>
				&nbsp;&nbsp;
				<label>
					<input type="radio" name="ce_settings[source_type]" value="google" <?php checked( $source, 'google' ); ?> />
					<?php esc_html_e( 'Google Calendar', 'church-events' ); ?>
				</label>
			</td>
		</tr>

		<tr class="ce-source-row ce-source-churchsuite">
			<th><label for="ce_churchsuite_url"><?php esc_html_e( 'ChurchSuite JSON Feed URL', 'church-events' ); ?></label></th>
			<td>
				<input type="url" id="ce_churchsuite_url" name="ce_settings[churchsuite_url]" value="<?php echo esc_url( $cs_url ); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e( 'The public JSON feed URL from your ChurchSuite account.', 'church-events' ); ?></p>
			</td>
		</tr>

		<tr class="ce-source-row ce-source-google">
			<th><label for="ce_google_cal_id"><?php esc_html_e( 'Google Calendar ID', 'church-events' ); ?></label></th>
			<td>
				<input type="text" id="ce_google_cal_id" name="ce_settings[google_cal_id]" value="<?php echo esc_attr( $gcal_id ); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e( 'e.g. example@group.calendar.google.com', 'church-events' ); ?></p>
			</td>
		</tr>

		<tr class="ce-source-row ce-source-google">
			<th><label for="ce_google_api_key"><?php esc_html_e( 'Google API Key', 'church-events' ); ?></label></th>
			<td>
				<input type="text" id="ce_google_api_key" name="ce_settings[google_api_key]" value="<?php echo esc_attr( $gcal_key ); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e( 'Restricted Google Cloud API key with Calendar API access.', 'church-events' ); ?></p>
			</td>
		</tr>

		<tr>
			<th><label for="ce_sync_interval"><?php esc_html_e( 'Sync Frequency', 'church-events' ); ?></label></th>
			<td>
				<select id="ce_sync_interval" name="ce_settings[sync_interval]">
					<option value="hourly" <?php selected( $interval, 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'church-events' ); ?></option>
					<option value="twicedaily" <?php selected( $interval, 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'church-events' ); ?></option>
					<option value="daily" <?php selected( $interval, 'daily' ); ?>><?php esc_html_e( 'Daily', 'church-events' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'How often WP-Cron syncs events. For reliable timing, pair with a real server cron job.', 'church-events' ); ?></p>
			</td>
		</tr>

		<tr>
			<th><label for="ce_sync_key"><?php esc_html_e( 'Server Cron Key', 'church-events' ); ?></label></th>
			<td>
				<input type="text" id="ce_sync_key" name="ce_settings[sync_key]" value="<?php echo esc_attr( $sync_key ); ?>" class="regular-text" />
				<button type="button" id="ce-generate-key" class="button button-secondary" style="margin-left:8px;"><?php esc_html_e( 'Generate', 'church-events' ); ?></button>
				<p class="description"><?php esc_html_e( 'Used to secure the REST sync endpoint for server cron jobs. Send as X-CE-Sync-Key header.', 'church-events' ); ?></p>
			</td>
		</tr>

		<tr>
			<th><?php esc_html_e( 'Manual Sync', 'church-events' ); ?></th>
			<td>
				<button type="button" id="ce-sync-now" class="button button-secondary"><?php esc_html_e( 'Sync Now', 'church-events' ); ?></button>
				<span id="ce-sync-status" style="margin-left:10px;"></span>
				<p class="description"><?php esc_html_e( 'Trigger an immediate sync from the source.', 'church-events' ); ?></p>
			</td>
		</tr>

		<?php if ( $last_sync ) : ?>
		<tr>
			<th><?php esc_html_e( 'Last Sync', 'church-events' ); ?></th>
			<td>
				<span class="ce-sync-badge ce-sync-<?php echo esc_attr( $last_sync['status'] ); ?>">
					<?php echo esc_html( ucfirst( $last_sync['status'] ) ); ?>
				</span>
				<span style="margin-left:8px;color:#646970;"><?php echo esc_html( $last_sync['time'] ); ?></span>
				<p class="description"><?php echo esc_html( $last_sync['message'] ); ?></p>
			</td>
		</tr>
		<?php endif; ?>

	</table>
	<?php
}

function ce_render_tab_display( $s ) {
	$ratio          = isset( $s['image_ratio'] )      ? $s['image_ratio']      : '16:9';
	$enabled_views  = isset( $s['enabled_views'] ) && is_array( $s['enabled_views'] ) ? $s['enabled_views'] : array( 'calendar', 'cards' );
	$view           = isset( $s['default_view'] )     ? $s['default_view']     : $enabled_views[0];
	$mobile_view    = isset( $s['mobile_view'] )      ? $s['mobile_view']      : '';
	$non_cal_views  = array_values( array_filter( $enabled_views, fn( $v ) => $v !== 'calendar' ) );
	$columns        = isset( $s['grid_columns'] )     ? (int) $s['grid_columns'] : 3;
	$date_filter    = isset( $s['date_filter_type'] ) ? $s['date_filter_type'] : 'month';
	$has_calendar   = in_array( 'calendar', $enabled_views, true );
	?>
	<h2><?php esc_html_e( 'Display Settings', 'church-events' ); ?></h2>

	<table class="form-table">
		<tr>
			<th><label for="ce_cpt_slug"><?php esc_html_e( 'Events URL Slug', 'church-events' ); ?></label></th>
			<td>
				<?php $cpt_slug = isset( $s['cpt_slug'] ) ? $s['cpt_slug'] : 'events'; ?>
				<input
					type="text"
					id="ce_cpt_slug"
					name="ce_settings[cpt_slug]"
					value="<?php echo esc_attr( $cpt_slug ); ?>"
					class="regular-text"
					placeholder="events"
				/>
				<p class="description">
					<?php esc_html_e( 'The URL base for individual event pages (e.g. "events" → /events/event-name/). Change this if your site already has a page at /events/. The setting saves and flushes permalink rules automatically.', 'church-events' ); ?>
				</p>
				<?php if ( $cpt_slug !== 'events' ) : ?>
				<p class="description" style="color:#d63638;margin-top:4px;">
					<?php esc_html_e( '⚠ Non-default slug active. If this site was live on /events/ previously, add 301 redirects from the old event URLs.', 'church-events' ); ?>
				</p>
				<?php endif; ?>
			</td>
		</tr>

		<tr>
			<th><?php esc_html_e( 'Featured Image Ratio', 'church-events' ); ?></th>
			<td>
				<?php
				$ratios = array( '1:1', '4:3', '16:9', '4:5' );
				foreach ( $ratios as $r ) :
				?>
				<label style="margin-right:16px;">
					<input type="radio" name="ce_settings[image_ratio]" value="<?php echo esc_attr( $r ); ?>" <?php checked( $ratio, $r ); ?> />
					<?php echo esc_html( $r ); ?>
				</label>
				<?php endforeach; ?>
			</td>
		</tr>

		<tr>
			<th><?php esc_html_e( 'Available Views', 'church-events' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Choose which views to show on the front end', 'church-events' ); ?></legend>
					<?php
					$view_labels = array(
						'calendar' => __( 'Calendar (month grid)', 'church-events' ),
						'cards'    => __( 'Cards (grid / stack)', 'church-events' ),
						'list'     => __( 'List (agenda rows)', 'church-events' ),
					);
					foreach ( $view_labels as $slug => $label ) :
					?>
					<label style="display:block;margin-bottom:6px;">
						<input
							type="checkbox"
							name="ce_settings[enabled_views][]"
							value="<?php echo esc_attr( $slug ); ?>"
							<?php checked( in_array( $slug, $enabled_views, true ) ); ?>
						/>
						<?php echo esc_html( $label ); ?>
					</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Check at least one view. When only one view is checked the toggle buttons are hidden.', 'church-events' ); ?></p>
				</fieldset>
			</td>
		</tr>

		<tr>
			<th><?php esc_html_e( 'Default View', 'church-events' ); ?></th>
			<td>
				<?php foreach ( $view_labels as $slug => $label ) : ?>
				<label style="margin-right:16px;">
					<input
						type="radio"
						name="ce_settings[default_view]"
						value="<?php echo esc_attr( $slug ); ?>"
						<?php checked( $view, $slug ); ?>
						<?php disabled( ! in_array( $slug, $enabled_views, true ) ); ?>
					/>
					<?php echo esc_html( $label ); ?>
				</label>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Which view loads first. Only enabled views can be set as default.', 'church-events' ); ?></p>
			</td>
		</tr>

		<?php if ( $has_calendar && count( $non_cal_views ) > 0 ) : ?>
		<tr>
			<th><?php esc_html_e( 'Mobile View', 'church-events' ); ?></th>
			<td>
				<?php
				$mobile_labels = array(
					'cards' => __( 'Cards (grid / stack)', 'church-events' ),
					'list'  => __( 'List (agenda rows)', 'church-events' ),
				);
				foreach ( $non_cal_views as $slug ) :
					if ( ! isset( $mobile_labels[ $slug ] ) ) continue;
					$is_default = empty( $mobile_view ) ? ( $slug === $non_cal_views[0] ) : ( $mobile_view === $slug );
				?>
				<label style="margin-right:16px;">
					<input
						type="radio"
						name="ce_settings[mobile_view]"
						value="<?php echo esc_attr( $slug ); ?>"
						<?php checked( $is_default ); ?>
					/>
					<?php echo esc_html( $mobile_labels[ $slug ] ); ?>
				</label>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Calendar is hidden on screens narrower than 768px. Choose which view replaces it on mobile.', 'church-events' ); ?></p>
			</td>
		</tr>
		<?php endif; ?>

		<tr>
			<th><?php esc_html_e( 'Date Filter Type', 'church-events' ); ?></th>
			<td>
				<label style="margin-right:16px;">
					<input type="radio" name="ce_settings[date_filter_type]" value="month" <?php checked( $date_filter, 'month' ); ?> />
					<?php esc_html_e( 'Month picker (populated from events)', 'church-events' ); ?>
				</label>
				<label>
					<input type="radio" name="ce_settings[date_filter_type]" value="range" <?php checked( $date_filter, 'range' ); ?> />
					<?php esc_html_e( 'Date range (from/to)', 'church-events' ); ?>
				</label>
			</td>
		</tr>

		<tr>
			<th><label for="ce_grid_columns"><?php esc_html_e( 'Grid Columns (desktop)', 'church-events' ); ?></label></th>
			<td>
				<select id="ce_grid_columns" name="ce_settings[grid_columns]">
					<?php foreach ( array( 2, 3, 4 ) as $col ) : ?>
						<option value="<?php echo $col; ?>" <?php selected( $columns, $col ); ?>><?php echo $col; ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th><label for="ce_per_page"><?php esc_html_e( 'Events Per Page (list view)', 'church-events' ); ?></label></th>
			<td>
				<select id="ce_per_page" name="ce_settings[per_page]">
					<?php
					$per_page = isset( $s['per_page'] ) ? (int) $s['per_page'] : 12;
					foreach ( array( 6, 9, 12, 18, 24 ) as $n ) : ?>
						<option value="<?php echo $n; ?>" <?php selected( $per_page, $n ); ?>><?php echo $n; ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Events shown before the Load More button appears.', 'church-events' ); ?></p>
			</td>
		</tr>

		<tr>
			<th><?php esc_html_e( 'Fallback Image', 'church-events' ); ?></th>
			<td>
				<?php
				$fallback_id  = isset( $s['fallback_image_id'] ) ? (int) $s['fallback_image_id'] : 0;
				$fallback_url = $fallback_id ? wp_get_attachment_image_url( $fallback_id, 'thumbnail' ) : '';
				?>
				<div class="ce-fallback-image-wrap">
					<?php if ( $fallback_url ) : ?>
						<img src="<?php echo esc_url( $fallback_url ); ?>" style="max-width:150px;display:block;margin-bottom:8px;border-radius:4px;" />
					<?php endif; ?>
					<input type="hidden" id="ce_fallback_image_id" name="ce_settings[fallback_image_id]" value="<?php echo esc_attr( $fallback_id ); ?>" />
					<button type="button" class="button button-secondary" id="ce-select-fallback-image"><?php esc_html_e( $fallback_id ? 'Change Image' : 'Select Image', 'church-events' ); ?></button>
					<?php if ( $fallback_id ) : ?>
						<button type="button" class="button button-secondary" id="ce-remove-fallback-image" style="margin-left:4px;"><?php esc_html_e( 'Remove', 'church-events' ); ?></button>
					<?php endif; ?>
				</div>
				<p class="description"><?php esc_html_e( 'Shown when an event has no featured image.', 'church-events' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Site Filter Label', 'church-events' ); ?></th>
			<td>
				<?php $site_label = isset( $s['site_label'] ) ? $s['site_label'] : 'site'; ?>
				<label style="margin-right:16px;">
					<input type="radio" name="ce_settings[site_label]" value="site" <?php checked( $site_label, 'site' ); ?> />
					<?php esc_html_e( 'Site (e.g. "All sites")', 'church-events' ); ?>
				</label>
				<label>
					<input type="radio" name="ce_settings[site_label]" value="church" <?php checked( $site_label, 'church' ); ?> />
					<?php esc_html_e( 'Church (e.g. "All churches")', 'church-events' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Controls the label shown in the site filter dropdown on the frontend. Only visible if site terms exist.', 'church-events' ); ?></p>
			</td>
		</tr>
	</table>
	<?php
}

function ce_render_tab_interactions( $s ) {
	$interaction = isset( $s['card_interaction'] ) ? $s['card_interaction'] : 'modal';
	$hover       = isset( $s['hover_preview'] ) ? (bool) $s['hover_preview'] : false;
	?>
	<h2><?php esc_html_e( 'Interaction Settings', 'church-events' ); ?></h2>

	<table class="form-table">
		<tr>
			<th><?php esc_html_e( 'On Card/Event Click', 'church-events' ); ?></th>
			<td>
				<label style="margin-right:16px;">
					<input type="radio" name="ce_settings[card_interaction]" value="modal" <?php checked( $interaction, 'modal' ); ?> />
					<?php esc_html_e( 'Open modal with event detail', 'church-events' ); ?>
				</label>
				<label>
					<input type="radio" name="ce_settings[card_interaction]" value="page" <?php checked( $interaction, 'page' ); ?> />
					<?php esc_html_e( 'Navigate to single event page', 'church-events' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Modal keeps visitors on the page. Single page allows a full Elementor template per event.', 'church-events' ); ?></p>
			</td>
		</tr>

		<tr>
			<th><?php esc_html_e( 'Hover Preview', 'church-events' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="ce_settings[hover_preview]" value="1" <?php checked( $hover, true ); ?> />
					<?php esc_html_e( 'Show event summary on hover (calendar grid only)', 'church-events' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Displays a small popover with the event title, time and excerpt when hovering over an event.', 'church-events' ); ?></p>
			</td>
		</tr>
	</table>
	<?php
}

function ce_render_tab_fields( $s ) {
	$archive_fields = isset( $s['archive_fields'] ) ? $s['archive_fields'] : ce_default_archive_fields();
	$detail_fields  = isset( $s['detail_fields'] ) ? $s['detail_fields'] : ce_default_detail_fields();

	// Sort by order
	uasort( $archive_fields, fn( $a, $b ) => $a['order'] <=> $b['order'] );
	uasort( $detail_fields,  fn( $a, $b ) => $a['order'] <=> $b['order'] );
	?>
	<h2><?php esc_html_e( 'Field Settings', 'church-events' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Drag to reorder. Check to show a field, uncheck to hide it.', 'church-events' ); ?></p>

	<div class="ce-fields-columns">
		<div class="ce-fields-column">
			<h3><?php esc_html_e( 'Cards &amp; List View', 'church-events' ); ?></h3>
			<p class="description" style="margin-top:0;margin-bottom:12px;"><?php esc_html_e( 'Applies to both the Cards grid and List (agenda) view.', 'church-events' ); ?></p>
			<?php ce_render_fields_list( $archive_fields, 'archive_fields' ); ?>
		</div>
		<div class="ce-fields-column">
			<h3><?php esc_html_e( 'Detail View / Modal', 'church-events' ); ?></h3>
			<?php ce_render_fields_list( $detail_fields, 'detail_fields' ); ?>
		</div>
	</div>
	<?php
}

/**
 * Render a sortable field list.
 *
 * @param array  $fields
 * @param string $input_name  e.g. 'archive_fields'
 */
function ce_render_fields_list( $fields, $input_name ) {
	?>
	<ul class="ce-sortable-fields" data-input="<?php echo esc_attr( $input_name ); ?>">
		<?php $order = 1; foreach ( $fields as $key => $field ) : ?>
		<li class="ce-field-row" data-key="<?php echo esc_attr( $key ); ?>">
			<span class="dashicons dashicons-menu ce-drag-handle"></span>
			<label>
				<input type="checkbox"
					name="ce_settings[<?php echo esc_attr( $input_name ); ?>][<?php echo esc_attr( $key ); ?>][enabled]"
					value="1"
					<?php checked( $field['enabled'], true ); ?> />
				<?php echo esc_html( $field['label'] ); ?>
			</label>
			<input type="hidden"
				name="ce_settings[<?php echo esc_attr( $input_name ); ?>][<?php echo esc_attr( $key ); ?>][order]"
				value="<?php echo esc_attr( $order ); ?>"
				class="ce-order-input" />
			<input type="hidden"
				name="ce_settings[<?php echo esc_attr( $input_name ); ?>][<?php echo esc_attr( $key ); ?>][label]"
				value="<?php echo esc_attr( $field['label'] ); ?>" />
		</li>
		<?php $order++; endforeach; ?>
	</ul>
	<?php
}

function ce_render_tab_styling( $s ) {
	$use_elementor = defined( 'ELEMENTOR_VERSION' );
	$primary    = isset( $s['color_primary'] )   ? $s['color_primary']   : '#083C5E';
	$secondary  = isset( $s['color_secondary'] ) ? $s['color_secondary'] : '#35878C';
	$text       = isset( $s['color_text'] )      ? $s['color_text']      : '#333333';
	$accent     = isset( $s['color_accent'] )    ? $s['color_accent']    : '#C1789C';
	$custom_css = isset( $s['custom_css'] )      ? $s['custom_css']      : '';
	?>
	<h2><?php esc_html_e( 'Styling', 'church-events' ); ?></h2>

	<?php if ( $use_elementor ) : ?>
	<div class="notice notice-info inline">
		<p><?php esc_html_e( 'Elementor is active. The plugin will use your Elementor global colours by default. The colour pickers below are used as fallbacks if Elementor variables are not available.', 'church-events' ); ?></p>
	</div>
	<?php endif; ?>

	<table class="form-table">
		<tr>
			<th><?php esc_html_e( 'Primary Colour', 'church-events' ); ?></th>
			<td><input type="text" name="ce_settings[color_primary]" value="<?php echo esc_attr( $primary ); ?>" class="ce-color-picker" /></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Secondary Colour', 'church-events' ); ?></th>
			<td><input type="text" name="ce_settings[color_secondary]" value="<?php echo esc_attr( $secondary ); ?>" class="ce-color-picker" /></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Text Colour', 'church-events' ); ?></th>
			<td><input type="text" name="ce_settings[color_text]" value="<?php echo esc_attr( $text ); ?>" class="ce-color-picker" /></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Accent Colour', 'church-events' ); ?></th>
			<td><input type="text" name="ce_settings[color_accent]" value="<?php echo esc_attr( $accent ); ?>" class="ce-color-picker" /></td>
		</tr>
		<tr>
			<th><label for="ce_custom_css"><?php esc_html_e( 'Custom CSS', 'church-events' ); ?></label></th>
			<td>
				<textarea id="ce_custom_css" name="ce_settings[custom_css]" rows="12" class="large-text code"><?php echo esc_textarea( $custom_css ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'CSS is scoped under .church-events so it only affects plugin output. Use CSS custom properties to override defaults, e.g.:', 'church-events' ); ?><br>
					<code>.church-events { --ce-primary: #083C5E; --ce-card-radius: 8px; }</code>
				</p>
			</td>
		</tr>
	</table>

	<h3><?php esc_html_e( 'Available CSS Custom Properties', 'church-events' ); ?></h3>
	<table class="widefat striped" style="max-width:600px;">
		<thead><tr><th><?php esc_html_e( 'Property', 'church-events' ); ?></th><th><?php esc_html_e( 'Default', 'church-events' ); ?></th><th><?php esc_html_e( 'Controls', 'church-events' ); ?></th></tr></thead>
		<tbody>
			<?php
			$props = array(
				array( '--ce-primary',              'From settings / Elementor',  'Main brand colour, headings, buttons' ),
				array( '--ce-secondary',            'From settings / Elementor',  'Secondary elements, dates, icons' ),
				array( '--ce-text',                 'From settings / Elementor',  'Body text colour' ),
				array( '--ce-accent',               'From settings / Elementor',  'Tags, badges, highlights' ),
				array( '--ce-muted',                '#6b7280',                    'De-emphasised text (meta, year, icons)' ),
				array( '--ce-border-color',         '#e5e7eb',                    'Agenda row dividers, card borders' ),
				array( '--ce-transition',           '0.15s ease',                 'Hover/focus transition speed' ),
				array( '--ce-card-bg',              '#ffffff',                    'Card background' ),
				array( '--ce-card-radius',          '6px',                        'Card corner radius' ),
				array( '--ce-card-gap',             '1.5rem',                     'Gap between cards in grid' ),
				array( '--ce-card-shadow',          '0 2px 8px rgba(0,0,0,.08)', 'Card box shadow' ),
				array( '--ce-font-size-title',      '1.125rem',                   'Card event title size' ),
				array( '--ce-font-size-meta',       '0.875rem',                   'Date/time/location text size' ),
				array( '--ce-font-size-body',       '1rem',                       'Agenda title font size' ),
				array( '--ce-modal-max-width',      '640px',                      'Maximum width of detail modal' ),
				array( '--ce-calendar-height',      '650px',                      'Height of the calendar grid' ),
				array( '--ce-agenda-image-size',    '3.5rem',                     'Agenda row circle image diameter' ),
			);
			foreach ( $props as $p ) :
			?>
			<tr>
				<td><code><?php echo esc_html( $p[0] ); ?></code></td>
				<td><code><?php echo esc_html( $p[1] ); ?></code></td>
				<td><?php echo esc_html( $p[2] ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Render the Shortcodes reference tab.
 * Read-only — no form fields, no save button.
 */
function ce_render_tab_shortcodes() {
	$base_url = admin_url( '?post_type=event&page=church-events-settings&tab=' );
	?>
	<h2><?php esc_html_e( 'Shortcode Reference', 'church-events' ); ?></h2>
	<p><?php esc_html_e( 'Copy any shortcode below and paste it into a page, post, or Elementor Shortcode widget.', 'church-events' ); ?></p>

	<style>
		.ce-sc-table { border-collapse: collapse; width: 100%; margin-bottom: 2rem; }
		.ce-sc-table th { text-align: left; padding: 10px 12px; background: #f6f7f7; border-bottom: 2px solid #dcdcde; font-size: 13px; }
		.ce-sc-table td { padding: 10px 12px; border-bottom: 1px solid #f0f0f1; vertical-align: top; font-size: 13px; }
		.ce-sc-table tr:last-child td { border-bottom: none; }
		.ce-sc-table code { background: #f0f6fc; border: 1px solid #c8e1ff; border-radius: 3px; padding: 2px 6px; font-size: 12px; white-space: nowrap; cursor: pointer; user-select: all; }
		.ce-sc-table code:hover { background: #e1f0ff; }
		.ce-sc-section-title { margin: 2rem 0 0.5rem; font-size: 14px; font-weight: 600; color: #1d2327; border-bottom: 1px solid #dcdcde; padding-bottom: 6px; }
		.ce-sc-attr { color: #6b7280; font-size: 12px; }
		.ce-sc-note { background: #fff8e5; border-left: 4px solid #f0b429; padding: 10px 14px; margin: 0 0 2rem; font-size: 13px; }
	</style>

	<p class="ce-sc-note">
		<?php esc_html_e( 'Tip: clicking any shortcode code block selects it so you can copy it quickly.', 'church-events' ); ?>
	</p>

	<?php
	// -------------------------------------------------------------------------
	// Event archive / embed shortcodes
	// -------------------------------------------------------------------------
	?>
	<p class="ce-sc-section-title"><?php esc_html_e( 'Event Archive', 'church-events' ); ?></p>
	<table class="ce-sc-table widefat">
		<thead>
			<tr>
				<th style="width:30%"><?php esc_html_e( 'Shortcode', 'church-events' ); ?></th>
				<th style="width:35%"><?php esc_html_e( 'Description', 'church-events' ); ?></th>
				<th><?php esc_html_e( 'Key attributes', 'church-events' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><code>[church_events_calendar]</code></td>
				<td><?php esc_html_e( 'Full event display with whichever views are enabled in Display settings. Use layout= to restrict to a single view per page.', 'church-events' ); ?></td>
				<td class="ce-sc-attr">
					<code>layout="calendar"</code> <?php esc_html_e( '— show only the month grid', 'church-events' ); ?><br>
					<code>layout="cards"</code> <?php esc_html_e( '— show only the card grid', 'church-events' ); ?><br>
					<code>layout="list"</code> <?php esc_html_e( '— show only agenda rows', 'church-events' ); ?><br>
					<code>category="youth"</code> <?php esc_html_e( '— lock to a category slug', 'church-events' ); ?><br>
					<code>site="central"</code> <?php esc_html_e( '— lock to a site slug', 'church-events' ); ?>
				</td>
			</tr>
			<tr>
				<td><code>[church_events_list]</code></td>
				<td><?php esc_html_e( 'Single-view embed — no toggle. Good for a sidebar or a focused page.', 'church-events' ); ?></td>
				<td class="ce-sc-attr">
					<code>layout="cards"</code> <?php esc_html_e( '— card grid (default)', 'church-events' ); ?><br>
					<code>layout="list"</code> <?php esc_html_e( '— agenda rows', 'church-events' ); ?><br>
					<code>layout="calendar"</code> <?php esc_html_e( '— month grid', 'church-events' ); ?><br>
					<code>columns="3"</code> <?php esc_html_e( '— grid column count (cards only)', 'church-events' ); ?><br>
					<code>category="youth"</code><br>
					<code>site="central"</code>
				</td>
			</tr>
		</tbody>
	</table>

	<?php
	// -------------------------------------------------------------------------
	// Single event page shortcodes
	// -------------------------------------------------------------------------
	?>
	<p class="ce-sc-section-title"><?php esc_html_e( 'Single Event Page', 'church-events' ); ?></p>
	<p><?php esc_html_e( 'Use these inside your Elementor single event template where ACF dynamic tags don\'t format the raw values cleanly.', 'church-events' ); ?></p>
	<table class="ce-sc-table widefat">
		<thead>
			<tr>
				<th style="width:30%"><?php esc_html_e( 'Shortcode', 'church-events' ); ?></th>
				<th style="width:35%"><?php esc_html_e( 'Description', 'church-events' ); ?></th>
				<th><?php esc_html_e( 'Key attributes', 'church-events' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><code>[ce_event_time]</code></td>
				<td><?php esc_html_e( 'Outputs "All Day" if the all-day flag is set, otherwise "10:00" or "10:00 – 11:30". Reads the current post automatically.', 'church-events' ); ?></td>
				<td class="ce-sc-attr">
					<code>post_id="123"</code> <?php esc_html_e( '— override post (optional)', 'church-events' ); ?>
				</td>
			</tr>
			<tr>
				<td><code>[ce_event_date]</code></td>
				<td><?php esc_html_e( 'Outputs the formatted start date, or a date range if the event spans multiple days. Uses your WordPress date format by default.', 'church-events' ); ?></td>
				<td class="ce-sc-attr">
					<code>post_id="123"</code> <?php esc_html_e( '— override post (optional)', 'church-events' ); ?><br>
					<code>format="j F Y"</code> <?php esc_html_e( '— PHP date format (optional)', 'church-events' ); ?>
				</td>
			</tr>
		</tbody>
	</table>

	<?php
	// -------------------------------------------------------------------------
	// ACF dynamic fields note
	// -------------------------------------------------------------------------
	?>
	<p class="ce-sc-section-title"><?php esc_html_e( 'ACF Dynamic Fields', 'church-events' ); ?></p>
	<p><?php esc_html_e( 'The following fields are available as ACF Dynamic Tags in Elementor (look for them under "Event Details" in the ACF Field tag). These are read-only — values are managed by the importer.', 'church-events' ); ?></p>
	<table class="ce-sc-table widefat">
		<thead>
			<tr>
				<th style="width:30%"><?php esc_html_e( 'Field key', 'church-events' ); ?></th>
				<th style="width:25%"><?php esc_html_e( 'Label', 'church-events' ); ?></th>
				<th><?php esc_html_e( 'Notes', 'church-events' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			$acf_fields = array(
				'event_start_date'           => array( __( 'Start Date', 'church-events' ),          __( 'YYYYMMDD — use [ce_event_date] for formatted output', 'church-events' ) ),
				'event_start_time'           => array( __( 'Start Time', 'church-events' ),          __( 'HH:MM — hide with a condition when value is 00:00 for all-day events', 'church-events' ) ),
				'event_end_date'             => array( __( 'End Date', 'church-events' ),            __( 'YYYYMMDD', 'church-events' ) ),
				'event_end_time'             => array( __( 'End Time', 'church-events' ),            __( 'HH:MM', 'church-events' ) ),
				'event_all_day'              => array( __( 'All Day Event', 'church-events' ),       __( 'True/false — use for conditional visibility in Elementor', 'church-events' ) ),
				'event_location'             => array( __( 'Location', 'church-events' ),            __( 'Venue name', 'church-events' ) ),
				'event_address'              => array( __( 'Address', 'church-events' ),             __( 'Full address string', 'church-events' ) ),
				'event_map_address'          => array( __( 'Map Address', 'church-events' ),         __( 'Address formatted for Google Maps embed', 'church-events' ) ),
				'event_booking_url'          => array( __( 'Booking URL', 'church-events' ),         __( 'Use with a Button widget link', 'church-events' ) ),
				'event_booking_text'         => array( __( 'Booking Link Text', 'church-events' ),   __( 'Label for the booking button, e.g. "Book Now"', 'church-events' ) ),
				'event_signup_enabled'       => array( __( 'Signup Enabled', 'church-events' ),      __( 'True/false — use for conditional visibility', 'church-events' ) ),
				'event_churchsuite_id'       => array( __( 'ChurchSuite ID', 'church-events' ),      __( 'Internal import reference', 'church-events' ) ),
				'event_churchsuite_category' => array( __( 'ChurchSuite Category', 'church-events' ), __( 'Category name from ChurchSuite', 'church-events' ) ),
			);
			foreach ( $acf_fields as $key => $info ) :
			?>
			<tr>
				<td><code><?php echo esc_html( $key ); ?></code></td>
				<td><?php echo esc_html( $info[0] ); ?></td>
				<td class="ce-sc-attr"><?php echo esc_html( $info[1] ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}
