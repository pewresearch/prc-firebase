<?php
/**
 * Named Realtime Database URL registry for PRC Firebase.
 *
 * Maps logical database names (quiz-builder, user-accounts, …) to the
 * environment-specific VIP URL constants. Callers ask by name; prod vs
 * `__DEV` resolution stays at this boundary.
 *
 * @package PRC\Platform\Firebase
 */

namespace PRC\Platform\Firebase;

use WP_Error;

/**
 * Resolves logical RTDB names to URLs for the current WordPress environment.
 */
final class Database_Registry {
	public const INTERACTIVE_FEATURES = 'interactive-features';
	public const QUIZ_BUILDER         = 'quiz-builder';
	public const DATASETS             = 'datasets';
	public const USER_ACCOUNTS        = 'user-accounts';
	public const USER_SURVEYS         = 'user-surveys';
	public const DATA_TABLE_BUILDER   = 'data-table-builder';
	public const EMAIL_BUILDER        = 'email-builder';

	/**
	 * Default HTTP timeouts (seconds) for every named database client.
	 *
	 * A slow or hung database fails fast instead of holding a PHP worker for
	 * the default HTTP timeout (PRC-1197). Callers that read large subtrees or
	 * run exports override per call; `0` removes a timeout.
	 */
	public const DEFAULT_HTTP_OPTIONS = array(
		'timeout'         => 3.0,
		'connect_timeout' => 1.0,
	);

	/**
	 * Process-wide cache keyed by environment type.
	 *
	 * @var array<string, self>
	 */
	private static $instances = array();

	/**
	 * Resolved name => URL map for this environment.
	 *
	 * @var array<string, string>
	 */
	private $urls;

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $urls Resolved name => URL map.
	 */
	private function __construct( array $urls ) {
		$this->urls = $urls;
	}

	/**
	 * Registry for the current WordPress environment.
	 *
	 * @return self
	 */
	public static function from_wordpress(): self {
		$environment = function_exists( 'wp_get_environment_type' )
			? wp_get_environment_type()
			: 'production';

		return self::for_environment( $environment );
	}

	/**
	 * Registry for an explicit environment type (testable).
	 *
	 * @param string $environment WordPress environment type (`production`, `staging`, …).
	 * @return self
	 */
	public static function for_environment( string $environment ): self {
		if ( isset( self::$instances[ $environment ] ) ) {
			return self::$instances[ $environment ];
		}

		$is_production = 'production' === $environment;
		$urls          = array();

		foreach ( self::constant_map() as $name => $bases ) {
			$url = self::resolve_url( $bases, $is_production );
			if ( null !== $url ) {
				$urls[ $name ] = $url;
			}
		}

		self::$instances[ $environment ] = new self( $urls );

		return self::$instances[ $environment ];
	}

	/**
	 * Reset the process cache (for tests).
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$instances = array();
	}

	/**
	 * Resolve a logical database name to its URL.
	 *
	 * @param string $name Logical database name.
	 * @return string|WP_Error URL, or WP_Error when unknown / unconfigured.
	 */
	public function get_url( string $name ) {
		if ( ! isset( self::constant_map()[ $name ] ) ) {
			return new WP_Error(
				'firebase_unknown_database',
				sprintf( 'Unknown Firebase database name: %s', $name )
			);
		}

		if ( ! isset( $this->urls[ $name ] ) || '' === $this->urls[ $name ] ) {
			return new WP_Error(
				'firebase_database_unconfigured',
				sprintf( 'Firebase database "%s" URL is not configured.', $name )
			);
		}

		return $this->urls[ $name ];
	}

	/**
	 * All successfully resolved name => URL pairs.
	 *
	 * @return array<string, string>
	 */
	public function get_urls(): array {
		return $this->urls;
	}

	/**
	 * Logical name => ordered constant bases (primary, then fallbacks).
	 *
	 * Each base is the production constant name; `__DEV` is appended for
	 * non-production. Fallbacks keep rollout working before new secrets exist
	 * (`user-accounts` → AUTH_DB, `interactive-features` → INTERACTIVES_DB).
	 *
	 * @return array<string, list<string>>
	 */
	private static function constant_map(): array {
		return array(
			self::INTERACTIVE_FEATURES => array(
				'PRC_PLATFORM_FIREBASE_INTERACTIVE_FEATURES_DB',
				'PRC_PLATFORM_FIREBASE_INTERACTIVES_DB',
			),
			self::QUIZ_BUILDER         => array(
				'PRC_PLATFORM_FIREBASE_QUIZ_BUILDER_DB',
			),
			self::DATASETS             => array(
				'PRC_PLATFORM_FIREBASE_DATASETS_DB',
			),
			self::USER_ACCOUNTS        => array(
				'PRC_PLATFORM_FIREBASE_USER_ACCOUNTS_DB',
				'PRC_PLATFORM_FIREBASE_AUTH_DB',
			),
			self::USER_SURVEYS         => array(
				'PRC_PLATFORM_FIREBASE_USER_SURVEYS_DB',
			),
			self::DATA_TABLE_BUILDER   => array(
				'PRC_PLATFORM_FIREBASE_DATA_TABLE_BUILDER_DB',
			),
			self::EMAIL_BUILDER        => array(
				'PRC_PLATFORM_FIREBASE_EMAIL_BUILDER_DB',
			),
		);
	}

	/**
	 * Resolve the first defined non-empty constant among $bases for the env.
	 *
	 * @param array $bases         Production constant names.
	 * @param bool  $is_production Whether to use production constants.
	 * @return string|null URL or null when none are defined.
	 */
	private static function resolve_url( array $bases, bool $is_production ): ?string {
		foreach ( $bases as $base ) {
			$constant = $is_production ? $base : $base . '__DEV';
			if ( ! defined( $constant ) ) {
				continue;
			}
			$value = constant( $constant );
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		return null;
	}
}
