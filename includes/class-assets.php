<?php
/**
 * Assets registrar for PRC Firebase.
 *
 * Registers two client-side artifacts produced by this plugin:
 *
 *   1. The `@prc/firebase` script module (built from `src/index.js`),
 *      consumed via `import from '@prc/firebase'` and exposed to other
 *      blocks via the `requestToExternalModule` rule in
 *      [`dependency-extraction.js`](dependency-extraction.js).
 *      Client-side Firebase config is injected through the
 *      `script_module_data_@prc/firebase` filter so the module reads it
 *      from the inline `<script type="application/json"
 *      id="wp-script-module-data-@prc/firebase">` element WordPress emits.
 *
 *   2. The legacy `firebase` script handle (built from `src/compat/index.js`),
 *      which boots the Firebase JS SDK using the `compat/*` API and exposes
 *      `window.firebase`, `window.firebaseDb`, `window.firebaseAuth`, and
 *      `window.interactivesDb`. This is consumed via the existing
 *      `wp_enqueue_script( 'firebase' )` calls in prc-user-accounts,
 *      prc-legacy-content, prc-interactive-features, and prc-datasets.
 *      Required globals (`prcFirebaseConfig`, `prcFirebaseInteractivesConfig`)
 *      are localized onto that handle by `localize_firebase()`.
 *
 * @package PRC\Platform\Firebase
 */

namespace PRC\Platform\Firebase;

/**
 * Registers and localizes Firebase script + script module assets.
 *
 * @package PRC\Platform\Firebase
 */
class Assets {
	/**
	 * The script module handle for the modern @prc/firebase ES module.
	 *
	 * @var string
	 */
	const MODULE_HANDLE = '@prc/firebase';

	/**
	 * The classic script handle for the legacy firebase compat bundle.
	 *
	 * @var string
	 */
	const COMPAT_HANDLE = 'firebase';

	/**
	 * Constructor.
	 *
	 * @param Loader|null $loader The loader instance.
	 */
	public function __construct( $loader = null ) {
		if ( null === $loader ) {
			return;
		}

		// Script module registration + client-side credentials.
		$loader->add_action( 'init', $this, 'register_script_module' );
		$loader->add_filter(
			'script_module_data_' . self::MODULE_HANDLE,
			$this,
			'filter_script_module_data'
		);

		// Legacy compat script registration + localized config globals.
		$loader->add_action( 'wp_enqueue_scripts', $this, 'register_compat_script', 0 );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'register_compat_script', 0 );
	}

	/**
	 * Register the modern @prc/firebase ES script module.
	 *
	 * @hook init
	 *
	 * @return void
	 */
	public function register_script_module(): void {
		$asset_path = plugin_dir_path( __DIR__ ) . 'build/module.min.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			return;
		}

		$asset_file = include $asset_path;
		if ( ! is_array( $asset_file ) ) {
			return;
		}

		wp_register_script_module(
			self::MODULE_HANDLE,
			plugin_dir_url( __DIR__ ) . 'build/module.min.js',
			$asset_file['dependencies'] ?? array(),
			$asset_file['version'] ?? null
		);
	}

	/**
	 * Register the legacy `firebase` compat script and localize its config globals.
	 *
	 * @hook wp_enqueue_scripts (priority 0)
	 * @hook admin_enqueue_scripts (priority 0)
	 *
	 * @return void
	 */
	public function register_compat_script(): void {
		$asset_path = plugin_dir_path( __DIR__ ) . 'build/compat/index.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			return;
		}

		$asset_file = include $asset_path;
		if ( ! is_array( $asset_file ) ) {
			return;
		}

		$registered = wp_register_script(
			self::COMPAT_HANDLE,
			plugin_dir_url( __DIR__ ) . 'build/compat/index.js',
			$asset_file['dependencies'] ?? array(),
			$asset_file['version'] ?? null,
			true
		);

		if ( $registered ) {
			$this->localize_firebase( self::COMPAT_HANDLE );
		}
	}

	/**
	 * Inject client-side Firebase credentials into the @prc/firebase
	 * script module's data payload.
	 *
	 * Read by `loadFirebaseConfig()` in `src/index.js` from the
	 * `wp-script-module-data-@prc/firebase` inline JSON element.
	 *
	 * @since 1.0.0
	 * @param array $data The data to localize.
	 * @return array
	 */
	public function filter_script_module_data( $data ) {
		if ( ! defined( 'PRC_PLATFORM_FIREBASE_KEY' ) && ! defined( 'PRC_PLATFORM_FIREBASE_KEY__DEV' ) ) {
			return $data;
		}
		if ( ! defined( 'PRC_PLATFORM_FIREBASE_AUTH_DOMAIN' ) && ! defined( 'PRC_PLATFORM_FIREBASE_AUTH_DOMAIN__DEV' ) ) {
			return $data;
		}
		if ( ! defined( 'PRC_PLATFORM_FIREBASE_AUTH_DB' ) && ! defined( 'PRC_PLATFORM_FIREBASE_AUTH_DB__DEV' ) ) {
			return $data;
		}
		if ( ! defined( 'PRC_PLATFORM_FIREBASE_INTERACTIVES_DB' ) && ! defined( 'PRC_PLATFORM_FIREBASE_INTERACTIVES_DB__DEV' ) ) {
			return $data;
		}
		if ( ! defined( 'PRC_PLATFORM_FIREBASE_PROJECT_ID' ) && ! defined( 'PRC_PLATFORM_FIREBASE_PROJECT_ID__DEV' ) ) {
			return $data;
		}

		$environment = wp_get_environment_type();
		// Force production credentials; flip back to `wp_get_environment_type()`
		// to point local dev at staging Firebase data.
		// $environment = 'production';

		$api_key     = 'production' === $environment ? \PRC_PLATFORM_FIREBASE_KEY : \PRC_PLATFORM_FIREBASE_KEY__DEV;
		$auth_domain = 'production' === $environment ? \PRC_PLATFORM_FIREBASE_AUTH_DOMAIN : \PRC_PLATFORM_FIREBASE_AUTH_DOMAIN__DEV;
		$auth_db     = 'production' === $environment ? \PRC_PLATFORM_FIREBASE_AUTH_DB : \PRC_PLATFORM_FIREBASE_AUTH_DB__DEV;
		$project_id  = 'production' === $environment ? \PRC_PLATFORM_FIREBASE_PROJECT_ID : \PRC_PLATFORM_FIREBASE_PROJECT_ID__DEV;

		$data['apiKey']      = $api_key;
		$data['authDomain']  = $auth_domain;
		$data['databaseURL'] = $auth_db;
		$data['projectId']   = $project_id;

		return $data;
	}

	/**
	 * Localize Firebase config globals onto a registered script handle.
	 *
	 * Provides:
	 *   - `prcFirebaseConfig`             — main app credentials
	 *   - `prcFirebaseInteractivesConfig` — credentials for the legacy
	 *     interactives database (read by `src/compat/index.js`).
	 *
	 * Moved verbatim from
	 * `prc-scripts/includes/scripts/class-scripts.php::localize_firebase()`.
	 *
	 * @param string $script_slug The script handle to localize onto.
	 * @return void
	 */
	public function localize_firebase( string $script_slug ): void {
		if ( ! defined( 'PRC_PLATFORM_FIREBASE_KEY' ) && ! defined( 'PRC_PLATFORM_FIREBASE_KEY__DEV' ) ) {
			return;
		}
		if ( ! defined( 'PRC_PLATFORM_FIREBASE_AUTH_DOMAIN' ) && ! defined( 'PRC_PLATFORM_FIREBASE_AUTH_DOMAIN__DEV' ) ) {
			return;
		}
		if ( ! defined( 'PRC_PLATFORM_FIREBASE_AUTH_DB' ) && ! defined( 'PRC_PLATFORM_FIREBASE_AUTH_DB__DEV' ) ) {
			return;
		}
		if ( ! defined( 'PRC_PLATFORM_FIREBASE_INTERACTIVES_DB' ) && ! defined( 'PRC_PLATFORM_FIREBASE_INTERACTIVES_DB__DEV' ) ) {
			return;
		}
		if ( ! defined( 'PRC_PLATFORM_FIREBASE_PROJECT_ID' ) && ! defined( 'PRC_PLATFORM_FIREBASE_PROJECT_ID__DEV' ) ) {
			return;
		}

		$environment = wp_get_environment_type();

		$api_key         = 'production' === $environment ? \PRC_PLATFORM_FIREBASE_KEY : \PRC_PLATFORM_FIREBASE_KEY__DEV;
		$auth_domain     = 'production' === $environment ? \PRC_PLATFORM_FIREBASE_AUTH_DOMAIN : \PRC_PLATFORM_FIREBASE_AUTH_DOMAIN__DEV;
		$auth_db         = 'production' === $environment ? \PRC_PLATFORM_FIREBASE_AUTH_DB : \PRC_PLATFORM_FIREBASE_AUTH_DB__DEV;
		$interactives_db = 'production' === $environment ? \PRC_PLATFORM_FIREBASE_INTERACTIVES_DB : \PRC_PLATFORM_FIREBASE_INTERACTIVES_DB__DEV;
		$project_id      = 'production' === $environment ? \PRC_PLATFORM_FIREBASE_PROJECT_ID : \PRC_PLATFORM_FIREBASE_PROJECT_ID__DEV;

		wp_localize_script(
			$script_slug,
			'prcFirebaseConfig',
			array(
				'apiKey'      => $api_key,
				'authDomain'  => $auth_domain,
				'databaseURL' => $auth_db,
				'projectId'   => $project_id,
			)
		);
		wp_localize_script(
			$script_slug,
			'prcFirebaseInteractivesConfig',
			array(
				'apiKey'      => $api_key,
				'databaseURL' => $interactives_db,
				'projectId'   => $project_id,
			)
		);
	}
}
