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
 * Register all settings.
 */
function ce_register_settings() {
	register_setting( 'ce_settings', 'ce_settings', array(
		'sanitize_callback' => 'ce_sanitize_settings',
	) );
}
add_action( 'admin_init', 'ce_register_settings' );

/**
 * Sanitize settings on save.
 *
 * @param array $input
 * @return array
 */
function ce_sanitize_settings( $input ) {
	$sanitized = array();

	// Import
	$sanitized['source_type']       = isset( $input['source_type'] ) ? sanitize_text_field( $input['source_type'] ) : 'churchsuite';
	$sanitized['churchsuite_url']   = isset( $input['churchsuite_url'] ) ? esc_url_raw( $input['churchsuite_url'] ) : '';
	$sanitized['google_cal_id']     = isset( $input['google_cal_id'] ) ? sanitize_text_field( $input['google_cal_id'] ) : '';
	$sanitized['google_api_key']    = isset( $input['google_api_key'] ) ? sanitize_text_field( $input['google_api_key'] ) : '';
	$sanitized['sync_interval']     = isset( $input['sync_interval'] ) ? sanitize_text_field( $input['sync_interval'] ) : 'hourly';

	// Display
	$sanitized['image_ratio']       = isset( $input['image_ratio'] ) ? sanitize_text_field( $input['image_ratio'] ) : '16:9';
	$sanitized['default_view']      = isset( $input['default_view'] ) ? sanitize_text_field( $input['default_view'] ) : 'toggle';
	$sanitized['grid_columns']      = isset( $input['grid_columns'] ) ? absint( $input['grid_columns'] ) : 3;
	$sanitized['per_page']          = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 12;

	// Interactions
	$sanitized['card_interaction']  = isset( $input['card_interaction'] ) ? sanitize_text_field( $input['card_interaction'] ) : 'modal';
	$sanitized['hover_preview']     = isset( $input['hover_preview'] ) ? (bool) $input['hover_preview'] : false;

	// Fields — archive (serialized order + visibility)
	$sanitized['archive_fields']    = isset( $input['archive_fields'] ) ? ce_sanitize_fields_config( $input['archive_fields'] ) : ce_default_archive_fields();

	// Fields — detail/modal
	$sanitized['detail_fields']     = isset( $input['detail_fields'] ) ? ce_sanitize_fields_config( $input['detail_fields'] ) : ce_default_detail_fields();

	// Styling
	$sanitized['color_primary']     = isset( $input['color_primary'] ) ? sanitize_hex_color( $input['color_primary'] ) : '#083C5E';
	$sanitized['color_secondary']   = isset( $input['color_secondary'] ) ? sanitize_hex_color( $input['color_secondary'] ) : '#35878C';
	$sanitized['color_text']        = isset( $input['color_text'] ) ? sanitize_hex_color( $input['color_text'] ) : '#333333';
	$sanitized['color_accent']      = isset( $input['color_accent'] ) ? sanitize_hex_color( $input['color_accent'] ) : '#C1789C';
	$sanitized['custom_css']        = isset( $input['custom_css'] ) ? wp_strip_all_tags( $input['custom_css'] ) : '';

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
	$interval = isset( $s['sync_interval'] ) ? $s['sync_interval'] : 'hourly';
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
			<th><?php esc_html_e( 'Manual Sync', 'church-events' ); ?></th>
			<td>
				<button type="button" id="ce-sync-now" class="button button-secondary"><?php esc_html_e( 'Sync Now', 'church-events' ); ?></button>
				<span id="ce-sync-status" style="margin-left:10px;"></span>
				<p class="description"><?php esc_html_e( 'Trigger an immediate sync from the source.', 'church-events' ); ?></p>
			</td>
		</tr>
	</table>
	<?php
}

function ce_render_tab_display( $s ) {
	$ratio   = isset( $s['image_ratio'] ) ? $s['image_ratio'] : '16:9';
	$view    = isset( $s['default_view'] ) ? $s['default_view'] : 'toggle';
	$columns = isset( $s['grid_columns'] ) ? (int) $s['grid_columns'] : 3;
	?>
	<h2><?php esc_html_e( 'Display Settings', 'church-events' ); ?></h2>

	<table class="form-table">
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
			<th><?php esc_html_e( 'Default View', 'church-events' ); ?></th>
			<td>
				<label style="margin-right:16px;">
					<input type="radio" name="ce_settings[default_view]" value="calendar" <?php checked( $view, 'calendar' ); ?> />
					<?php esc_html_e( 'Calendar only', 'church-events' ); ?>
				</label>
				<label style="margin-right:16px;">
					<input type="radio" name="ce_settings[default_view]" value="list" <?php checked( $view, 'list' ); ?> />
					<?php esc_html_e( 'List/Grid only', 'church-events' ); ?>
				</label>
				<label>
					<input type="radio" name="ce_settings[default_view]" value="toggle" <?php checked( $view, 'toggle' ); ?> />
					<?php esc_html_e( 'Both with toggle', 'church-events' ); ?>
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
					<?php esc_html_e( 'Show event summary on hover (calendar grid and list cards)', 'church-events' ); ?>
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
			<h3><?php esc_html_e( 'Archive / List View', 'church-events' ); ?></h3>
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
				array( '--ce-primary',         'From settings / Elementor', 'Main brand colour, headings, buttons' ),
				array( '--ce-secondary',        'From settings / Elementor', 'Secondary elements, hover states' ),
				array( '--ce-text',             'From settings / Elementor', 'Body text colour' ),
				array( '--ce-accent',           'From settings / Elementor', 'Tags, badges, highlights' ),
				array( '--ce-card-bg',          '#ffffff',                   'Card background' ),
				array( '--ce-card-radius',      '6px',                       'Card corner radius' ),
				array( '--ce-card-gap',         '1.5rem',                    'Gap between cards in grid' ),
				array( '--ce-card-shadow',      '0 2px 8px rgba(0,0,0,.08)', 'Card box shadow' ),
				array( '--ce-font-size-title',  '1.125rem',                  'Event title font size' ),
				array( '--ce-font-size-meta',   '0.875rem',                  'Date/time/location font size' ),
				array( '--ce-modal-max-width',  '640px',                     'Maximum width of detail modal' ),
				array( '--ce-calendar-height',  '650px',                     'Height of the calendar grid' ),
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
