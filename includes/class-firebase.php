<?php
/**
 * Firebase SDK integration class for the PRC Platform.
 *
 * Provides a single entry point for working with the Kreait Firebase PHP SDK
 * on the server. Kept in the PRC\Platform namespace (and class name
 * `Firebase`) for backward compatibility with downstream consumers that
 * already do `new \PRC\Platform\Firebase()` (prc-quiz-builder,
 * prc-user-surveys, prc-user-accounts, prc-quiz-cast, prc-datasets,
 * prc-analytics).
 *
 * Asset registration (the `@prc/firebase` script module + the legacy
 * `firebase` compat script + their localization) lives in
 * `PRC\Platform\Firebase\Assets`.
 *
 * @package PRC\Platform
 * @since   1.0.0
 */

namespace PRC\Platform;

use Kreait\Firebase\Factory;
use WP_Error;

/**
 * Configures and exposes the Kreait Firebase SDK.
 *
 * We use Firebase extensively as an interactives data store and to manage
 * outside user authentication. If your application is writing simultaneous
 * outside-user data, use Firebase, not MySQL. Keep MySQL for PRC editorial
 * data; use Firebase for outside user data.
 */
class Firebase {
	/**
	 * The handle for the firebase JS script module.
	 *
	 * Exposed for downstream consumers that look up the module by name
	 * (e.g. when adding script-module dependencies).
	 *
	 * @var string
	 */
	public static $handle = '@prc/firebase';

	/**
	 * The instance of the Firebase SDK factory.
	 *
	 * @var \Kreait\Firebase\Factory|null
	 */
	public $instance;

	/**
	 * The Realtime Database instance.
	 *
	 * @var \Kreait\Firebase\Contract\Database|null
	 */
	public $db;

	/**
	 * The Auth instance.
	 *
	 * @var \Kreait\Firebase\Contract\Auth|null
	 */
	public $auth;

	/**
	 * Initialize the SDK.
	 *
	 * The optional `$loader` argument is preserved purely for backward
	 * compatibility with the previous platform-core wiring; the new
	 * Bootstrap registers asset hooks via the Assets class instead, so the
	 * loader passed here is unused. Downstream code that already calls
	 * `new \PRC\Platform\Firebase( null )` continues to work.
	 *
	 * @since 1.0.0
	 * @param mixed $loader Unused. Retained for backward compatibility.
	 */
	public function __construct( $loader = null ) {
		unset( $loader );

		if ( ! defined( 'PRC_PLATFORM_FIREBASE_KEY' ) ) {
			do_action( 'qm/critical', 'PRC_PLATFORM_FIREBASE_KEY is not defined. Firebase functionality will be disabled.' );
			return;
		}

		if ( ! class_exists( 'Kreait\\Firebase\\Factory' ) ) {
			do_action( 'qm/critical', 'Kreait Firebase library not found. Firebase functionality will be disabled.' );
			return;
		}

		$credentials = $this->localize_server_side_credentials();
		if ( is_wp_error( $credentials ) ) {
			do_action( 'qm/critical', 'Firebase API can not initialize without service account credentials. Some platform features will not work.' );
			return;
		}

		$this->instance = ( new Factory() )->withServiceAccount( $credentials );
		$this->db       = $this->instance->createDatabase();
		$this->auth     = $this->instance->createAuth();
	}

	/**
	 * Provide the Firebase service account credentials to the server-side SDK.
	 *
	 * Reads the JSON key file out of the VIP private dir based on the current
	 * environment.
	 *
	 * @since 1.0.0
	 * @return string|WP_Error The service account JSON, or WP_Error.
	 */
	public function localize_server_side_credentials() {
		if ( ! defined( 'WPCOM_VIP_PRIVATE_DIR' ) ) {
			return new WP_Error( 'firebase_service_account', 'WPCOM_VIP_PRIVATE_DIR is not defined.' );
		}

		$environment = wp_get_environment_type();
		// Force production credentials. Flip back to `wp_get_environment_type()`
		// when you need to develop against staging Firebase data.
		$environment = 'production';

		$service_account_file = ( 'production' === $environment )
			? \WPCOM_VIP_PRIVATE_DIR . '/firebase-service-account-prod.json'
			: \WPCOM_VIP_PRIVATE_DIR . '/firebase-service-account-staging.json';

		if ( ! file_exists( $service_account_file ) ) {
			return new WP_Error( 'firebase_service_account', 'Service account file does not exist.' );
		}

		$credentials = file_get_contents( $service_account_file );

		if ( false === $credentials ) {
			return new WP_Error( 'firebase_service_account', 'Failed to read service account file.' );
		}

		return $credentials;
	}
}
