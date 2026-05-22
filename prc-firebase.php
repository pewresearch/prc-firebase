<?php
/**
 * PRC Firebase
 *
 * @package           PRC\Platform\Firebase
 * @author            Seth Rubenstein
 * @copyright         2026 Pew Research Center
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       PRC Firebase
 * Plugin URI:        https://github.com/pewresearch/prc-platform
 * Description:       Google Firebase integration for the PRC Platform. Initializes the Kreait PHP SDK on the server, registers the @prc/firebase JavaScript script module, and provides the legacy `firebase` script handle (compat API) for older interactives.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Pew Research Center
 * Author URI:        https://[REDACTED]
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       prc-firebase
 * Requires Plugins:  prc-scripts
 */

namespace PRC\Platform\Firebase;

if ( ! defined( 'WPINC' ) ) {
	die;
}
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// When running inside the PRC Platform monorepo the root autoloader already
// provides every dependency; skip per-plugin Jetpack Autoloader initialization.
if ( ! defined( 'PRC_PLATFORM' ) ) {
	$prc_firebase_autoloader = __DIR__ . '/vendor/autoload_packages.php';
	if ( file_exists( $prc_firebase_autoloader ) ) {
		require_once $prc_firebase_autoloader;
	}
	unset( $prc_firebase_autoloader );
}

define( 'PRC_FIREBASE_FILE', __FILE__ );
define( 'PRC_FIREBASE_DIR', __DIR__ );
define( 'PRC_FIREBASE_VERSION', '1.0.0' );

/**
 * Helper utilities.
 */
require plugin_dir_path( __FILE__ ) . 'includes/utils.php';

/**
 * The core bootstrap class.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-bootstrap.php';

/**
 * Begin execution of the plugin.
 *
 * Registered on `plugins_loaded` so the `\PRC\Platform\Firebase` SDK class
 * is available before consumer plugins (prc-user-accounts, prc-quiz-cast,
 * prc-quiz-builder, prc-datasets, prc-analytics, prc-user-surveys) try to
 * instantiate it.
 */
function run_prc_firebase() {
	$plugin = new Bootstrap();
	$plugin->run();
}
run_prc_firebase();
