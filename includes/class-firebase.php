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

use Google\Auth\Credentials\ServiceAccountCredentials;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Http\HttpClientOptions;
use PRC\Platform\Firebase\Database_Registry;
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

	public const USER_TOKEN_HEADER = 'X-PRC-User-Token';

	public const REQUEST_UID_PARAM = '_prc_firebase_uid';

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
	 * Cached Realtime Database instances keyed by normalized URL and HTTP options.
	 *
	 * @var array<string, \Kreait\Firebase\Contract\Database>
	 */
	private $databases = array();

	/**
	 * Memoized service account project id.
	 *
	 * @var string|null
	 */
	private $service_account_project_id = null;

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
	 * Return a Realtime Database handle by logical name.
	 *
	 * With no name (or null/empty) and no options, returns the default
	 * `$this->db` for backward compatibility. Named databases resolve through
	 * {@see Database_Registry}. Handles are cached per URL and options.
	 *
	 * `$options` sets HTTP timeouts for this database client only (`timeout`
	 * and `connect_timeout`, in seconds). Named databases default to
	 * {@see Database_Registry::DEFAULT_HTTP_OPTIONS}; pass larger values for
	 * big reads or exports, or `0` to remove a timeout. The default database
	 * (`$name` null) and `$this->db` keep Guzzle's defaults (no timeout), so
	 * existing callers, exports and CLI jobs are unaffected. The timeouts also
	 * cover the Google OAuth token fetch made by that client.
	 *
	 * @since 1.1.0
	 * @param string|null $name    Logical name (`quiz-builder`, `user-accounts`, …), or null for default.
	 * @param array       $options Optional HTTP timeouts in seconds: `timeout`, `connect_timeout`.
	 * @return \Kreait\Firebase\Contract\Database|WP_Error|null Database, WP_Error when unconfigured, or null when SDK did not init.
	 */
	public function get_database( ?string $name = null, array $options = array() ) {
		if ( null === $name || '' === $name ) {
			$options = self::normalize_http_options( $options );
			if ( empty( $options ) ) {
				return $this->db;
			}
			return $this->create_database( null, $options );
		}

		if ( null === $this->instance ) {
			return null;
		}

		$url = Database_Registry::from_wordpress()->get_url( $name );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		return $this->get_database_for_uri( $url, array_merge( Database_Registry::DEFAULT_HTTP_OPTIONS, $options ) );
	}

	/**
	 * Return a Realtime Database handle for an explicit URL.
	 *
	 * Escape hatch for transitional cutovers when a caller already has a URL.
	 * Prefer {@see get_database()} with a logical name.
	 *
	 * @since 1.1.0
	 * @param string $uri     Realtime Database URL.
	 * @param array  $options Optional HTTP timeouts in seconds: `timeout`, `connect_timeout`.
	 * @return \Kreait\Firebase\Contract\Database|WP_Error|null
	 */
	public function get_database_for_uri( string $uri, array $options = array() ) {
		$uri = trim( $uri );
		if ( '' === $uri ) {
			return new WP_Error( 'firebase_database_unconfigured', 'Firebase database URL is empty.' );
		}

		return $this->create_database( $uri, self::normalize_http_options( $options ) );
	}

	/**
	 * Identify the database a handle talks to, for namespacing object-cache keys.
	 *
	 * Combines the service account's project, the logical name and the
	 * registry URL host, so cached values cannot collide across databases and
	 * stop being reachable when a name is repointed to another project or
	 * instance. Pass the same `$name` given to {@see get_database()}.
	 *
	 * @since 1.1.0
	 * @param string|null $name Logical name, or null for the default database.
	 * @return string
	 */
	public function get_database_scope( ?string $name = null ): string {
		$project = $this->get_service_account_project_id();
		$host    = '';

		if ( null !== $name && '' !== $name ) {
			$url  = Database_Registry::from_wordpress()->get_url( $name );
			$host = is_wp_error( $url ) ? 'unconfigured' : (string) wp_parse_url( $url, PHP_URL_HOST );
		} else {
			$name = 'default';
		}

		return implode( ':', array( '' !== $project ? $project : 'unknown-project', $name, $host ) );
	}

	/**
	 * Build (or reuse) a Database client for a URL and set of HTTP options.
	 *
	 * @param string|null $uri     Database URL, or null for the project's default database.
	 * @param array       $options Normalized HTTP options.
	 * @return \Kreait\Firebase\Contract\Database|WP_Error|null
	 */
	private function create_database( ?string $uri, array $options ) {
		if ( null === $this->instance ) {
			return null;
		}

		$cache_key = ( null === $uri ? 'default' : rtrim( $uri, '/' ) ) . '|' . wp_json_encode( $options );
		if ( isset( $this->databases[ $cache_key ] ) ) {
			return $this->databases[ $cache_key ];
		}

		try {
			$factory = $this->instance;
			if ( ! empty( $options ) ) {
				$http_options = HttpClientOptions::default();
				if ( isset( $options['timeout'] ) ) {
					$http_options = $http_options->withTimeout( $options['timeout'] );
				}
				if ( isset( $options['connect_timeout'] ) ) {
					$http_options = $http_options->withConnectTimeout( $options['connect_timeout'] );
				}
				$factory = $factory->withHttpClientOptions( $http_options );
			}
			if ( null !== $uri ) {
				$factory = $factory->withDatabaseUri( $uri );
			}
			$db = $factory->createDatabase();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'firebase_database_init',
				'Failed to initialize Firebase database: ' . $e->getMessage()
			);
		}

		$this->databases[ $cache_key ] = $db;

		return $db;
	}

	/**
	 * Keep only positive `timeout` / `connect_timeout` values, as floats.
	 *
	 * @param array $options Raw options.
	 * @return array<string, float>
	 */
	private static function normalize_http_options( array $options ): array {
		$normalized = array();
		foreach ( array( 'connect_timeout', 'timeout' ) as $key ) {
			if ( isset( $options[ $key ] ) && (float) $options[ $key ] > 0 ) {
				$normalized[ $key ] = (float) $options[ $key ];
			}
		}
		return $normalized;
	}

	/**
	 * Read the project id from the service account key (memoized).
	 *
	 * @return string Project id, or an empty string when unavailable.
	 */
	private function get_service_account_project_id(): string {
		if ( null === $this->service_account_project_id ) {
			$this->service_account_project_id = '';
			$credentials                      = $this->localize_server_side_credentials();
			if ( is_string( $credentials ) ) {
				$decoded = json_decode( $credentials, true );
				if ( is_array( $decoded ) && is_string( $decoded['project_id'] ?? null ) ) {
					$this->service_account_project_id = $decoded['project_id'];
				}
			}
		}
		return $this->service_account_project_id;
	}

	/**
	 * Provide the Firebase service account credentials to the server-side SDK.
	 *
	 * Reads the JSON key file from the VIP private dir. The file is written at
	 * deploy/local-gen time with the correct env's key (see
	 * bin/setup/generate-firebase-service-account.sh).
	 *
	 * @since 1.0.0
	 * @return string|WP_Error The service account JSON, or WP_Error.
	 */
	public function localize_server_side_credentials() {
		$service_account_file = $this->get_service_account_path();
		if ( is_wp_error( $service_account_file ) ) {
			return $service_account_file;
		}

		$credentials = file_get_contents( $service_account_file );

		if ( false === $credentials ) {
			return new WP_Error( 'firebase_service_account', 'Failed to read service account file.' );
		}

		return $credentials;
	}

	/**
	 * Resolve the Firebase service account JSON key file path.
	 *
	 * @since 1.0.0
	 * @return string|WP_Error Absolute path to the service account file, or WP_Error.
	 */
	private function get_service_account_path() {
		if ( ! defined( 'WPCOM_VIP_PRIVATE_DIR' ) ) {
			return new WP_Error( 'firebase_service_account', 'WPCOM_VIP_PRIVATE_DIR is not defined.' );
		}

		$service_account_file = \WPCOM_VIP_PRIVATE_DIR . '/firebase-service-account.json';

		if ( ! file_exists( $service_account_file ) ) {
			return new WP_Error( 'firebase_service_account', 'Service account file does not exist.' );
		}

		return $service_account_file;
	}

	/**
	 * Verify a Firebase Auth ID token and return the user's UID.
	 *
	 * @since 1.4.0
	 * @param string|null $token Raw ID token from the client.
	 * @return string|WP_Error Verified UID, or a WP_Error carrying an HTTP status.
	 */
	public function verify_user_token( ?string $token ) {
		if ( null === $token || '' === trim( $token ) ) {
			return new WP_Error( 'missing_token', 'X-PRC-User-Token header is required.', array( 'status' => 401 ) );
		}

		if ( ! $this->auth ) {
			return new WP_Error( 'firebase_unavailable', 'Firebase is not configured.', array( 'status' => 503 ) );
		}

		try {
			$uid = $this->auth->verifyIdToken( $token )->claims()->get( 'sub' );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'invalid_token', 'Invalid authentication token.', array( 'status' => 401 ) );
		}

		if ( ! is_string( $uid ) || '' === $uid ) {
			return new WP_Error( 'invalid_token', 'Invalid authentication token.', array( 'status' => 401 ) );
		}

		return $uid;
	}

	/**
	 * REST permission callback: verify the `X-PRC-User-Token` header.
	 *
	 * Stores the verified UID on the request so handlers read it with
	 * {@see get_request_uid()} instead of verifying a second time. The
	 * handler never sees a client-supplied value, because this callback
	 * always overwrites the param before the handler runs.
	 *
	 * @since 1.4.0
	 * @param \WP_REST_Request $request The incoming request.
	 * @return true|WP_Error
	 */
	public function authorize_rest_request( \WP_REST_Request $request ) {
		$uid = $this->verify_user_token( $request->get_header( self::USER_TOKEN_HEADER ) );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}

		$request->set_param( self::REQUEST_UID_PARAM, $uid );

		return true;
	}

	/**
	 * Read the UID that {@see authorize_rest_request()} verified for this request.
	 *
	 * @since 1.4.0
	 * @param \WP_REST_Request $request The incoming request.
	 * @return string Verified UID, or an empty string when the route did not authorize.
	 */
	public static function get_request_uid( \WP_REST_Request $request ): string {
		$uid = $request->get_param( self::REQUEST_UID_PARAM );
		return is_string( $uid ) ? $uid : '';
	}

	/**
	 * Mint a Google-issued OIDC ID token signed by the platform Firebase
	 * service account, scoped to a specific target audience (a Cloud Function
	 * URL or any other Google service URL). Tokens are cached per-request.
	 *
	 * @since 1.0.0
	 * @param string $target_audience The full URL of the function or service to invoke.
	 * @return string|WP_Error The ID token string, or WP_Error on failure.
	 */
	public function get_id_token( string $target_audience ) {
		static $cache = array();

		if ( '' === $target_audience ) {
			return new WP_Error( 'firebase_id_token', 'target_audience is required.' );
		}

		if ( isset( $cache[ $target_audience ] ) && $cache[ $target_audience ]['exp'] > time() + 60 ) {
			return $cache[ $target_audience ]['token'];
		}

		if ( ! class_exists( ServiceAccountCredentials::class ) ) {
			return new WP_Error( 'firebase_id_token', 'google/auth library not available.' );
		}

		$sa_file = $this->get_service_account_path();
		if ( is_wp_error( $sa_file ) ) {
			return $sa_file;
		}

		try {
			// scope = null + targetAudience = URL → OIDC ID token via Google token endpoint.
			$creds = new ServiceAccountCredentials( null, $sa_file, null, $target_audience );
			$resp  = $creds->fetchAuthToken();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'firebase_id_token', 'Failed to fetch ID token: ' . $e->getMessage() );
		}

		if ( empty( $resp['id_token'] ) ) {
			return new WP_Error( 'firebase_id_token', 'No id_token in token response.' );
		}

		$cache[ $target_audience ] = array(
			'token' => $resp['id_token'],
			'exp'   => time() + 3300,
		);

		return $resp['id_token'];
	}

	/**
	 * Capture a page element with the screenshotElement Cloud Function.
	 *
	 * `$args` is the function's request body: `url` (required), `selector`,
	 * `waitMs`, `viewportWidth`, `viewportHeight`, `captureElement`,
	 * `deviceScaleFactor`. When `basicAuth` is not set, the VIP basic auth
	 * gate login for `url` is added, which is null outside this
	 * non-production environment's own hosts.
	 *
	 * @since 1.3.0
	 * @param array<string, mixed> $args Request body.
	 * @return string|WP_Error PNG bytes, or WP_Error on failure.
	 */
	public function screenshot_element( array $args ) {
		$url = is_string( $args['url'] ?? null ) ? $args['url'] : '';
		if ( '' === $url ) {
			return new WP_Error( 'firebase_screenshot', 'url is required.' );
		}

		$endpoints = apply_filters( 'prc_platform_firebase_render_endpoints', array() );
		$endpoint  = is_array( $endpoints ) && is_string( $endpoints['screenshot_element'] ?? null ) ? $endpoints['screenshot_element'] : '';
		if ( '' === $endpoint ) {
			return new WP_Error( 'firebase_screenshot', 'screenshot_element endpoint is not configured.' );
		}

		$id_token = $this->get_id_token( $endpoint );
		if ( is_wp_error( $id_token ) ) {
			return $id_token;
		}

		if ( ! array_key_exists( 'basicAuth', $args ) && function_exists( '\PRC\Platform\Basic_Auth_Gate\credentials_for' ) ) {
			$args['basicAuth'] = \PRC\Platform\Basic_Auth_Gate\credentials_for( $url );
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 120, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- screenshotElement waits waitMs plus render time.
				'headers' => array(
					'Authorization' => 'Bearer ' . $id_token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'image/png',
				),
				'body'    => wp_json_encode( $args ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code >= 200 && $code < 300 && str_starts_with( $body, "\x89PNG" ) ) {
			return $body;
		}

		$message = 'screenshotElement request failed.';
		$json    = json_decode( $body, true );
		if ( is_array( $json ) && is_string( $json['error'] ?? null ) && '' !== $json['error'] ) {
			$message = $json['error'];
		}

		return new WP_Error( 'firebase_screenshot', $message, array( 'status' => $code ) );
	}
}
