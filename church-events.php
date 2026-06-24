<?php
/**
 * Plugin Name: Church Events
 * Plugin URI: https://github.com/ninefootone/church-events
 * Description: A standalone church events plugin with calendar, list/grid views and ChurchSuite/Google Calendar import.
 * Version: 1.7.30
 * Author: ninefootone creative
 * Author URI: https://github.com/ninefootone
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: church-events
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define( 'CE_VERSION', '1.7.30' );
define( 'CE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CE_PLUGIN_FILE', __FILE__ );

// Plugin Update Checker v5.5 (vendored — do not upgrade without testing)
// https://github.com/YahnisElsts/plugin-update-checker
require_once CE_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
$ce_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/ninefootone/church-events',
	__FILE__,
	'church-events'
);
$ce_update_checker->getVcsApi()->enableReleaseAssets();

// Autoload includes
require_once CE_PLUGIN_DIR . 'includes/class-church-events.php';

/**
 * Returns the main plugin instance.
 */
function church_events() {
	return Church_Events::instance();
}

church_events();