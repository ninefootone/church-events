<?php
/**
 * Main Church Events plugin class.
 * Bootstraps all modules via a singleton instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Church_Events {

	/**
	 * Single instance of the plugin.
	 *
	 * @var Church_Events
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return Church_Events
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — load all modules.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Require all module files.
	 */
	private function load_dependencies() {
		require_once CE_PLUGIN_DIR . 'includes/db.php';
		require_once CE_PLUGIN_DIR . 'includes/cpt.php';
		require_once CE_PLUGIN_DIR . 'includes/meta.php';
		require_once CE_PLUGIN_DIR . 'includes/rest-api.php';
		require_once CE_PLUGIN_DIR . 'includes/shortcodes.php';
		require_once CE_PLUGIN_DIR . 'includes/importer-churchsuite.php';
		require_once CE_PLUGIN_DIR . 'admin/settings.php';
		require_once CE_PLUGIN_DIR . 'admin/ajax.php';
	}

	/**
	 * Register plugin-level hooks.
	 */
	private function init_hooks() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		register_activation_hook( CE_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( CE_PLUGIN_FILE, array( $this, 'deactivate' ) );
	}

	/**
	 * Load plugin text domain for translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'church-events',
			false,
			dirname( plugin_basename( CE_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Plugin activation — flush rewrite rules.
	 */
	public function activate() {
		// Ensure CPT is registered before flushing
		ce_register_cpt();
		flush_rewrite_rules();
		ce_add_meta_indexes();
	}

	/**
	 * Plugin deactivation — flush rewrite rules.
	 */
	public function deactivate() {
		flush_rewrite_rules();
	}
}
