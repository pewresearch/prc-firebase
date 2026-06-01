<?php
/**
 * Bootstrap class.
 *
 * @package    PRC\Platform\Firebase
 */

namespace PRC\Platform\Firebase;

/**
 * Bootstrap class.
 *
 * Loads the SDK class and the asset registrar, wires their hooks via the
 * Loader, and exposes `run()` to register everything with WordPress.
 *
 * @package    PRC\Platform\Firebase
 */
class Bootstrap {
	/**
	 * The loader that's responsible for maintaining and registering all hooks
	 * that power the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Loader $loader
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string $plugin_name
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string $version
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->version     = defined( 'PRC_FIREBASE_VERSION' ) ? PRC_FIREBASE_VERSION : '1.0.0';
		$this->plugin_name = 'prc-firebase';

		$this->load_dependencies();
		$this->init_dependencies();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-loader.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/trait-cli-audience-verification.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-firebase.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-assets.php';

		$this->loader = new Loader();
	}

	/**
	 * Instantiate the plugin's components and register their hooks with the loader.
	 *
	 * The Firebase SDK class lives in the global PRC\Platform namespace so
	 * downstream consumers (e.g. `new \PRC\Platform\Firebase()`) keep working
	 * without a namespace change. The Assets class lives in this plugin's
	 * namespace and owns all script/script-module registration + localization.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function init_dependencies() {
		new \PRC\Platform\Firebase( $this->get_loader() );
		new Assets( $this->get_loader() );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the loader that orchestrates the plugin's hooks.
	 *
	 * @since     1.0.0
	 * @return    Loader
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the plugin version.
	 *
	 * @since     1.0.0
	 * @return    string
	 */
	public function get_version() {
		return $this->version;
	}
}
