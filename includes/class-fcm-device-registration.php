<?php
/**
 * Register Browsers for FCM.
 *
 * @package    PRC\Platform\Firebase
 */

namespace PRC\Platform\Firebase;

use WP_Error;
use donatj\UserAgent\UserAgentParser;
use PRC\Primitives\Concurrency\Lease;


/**
 * FCM Device Registration class.
 *
 * The `device_registration` user meta maps FCM tokens to browser names. Every
 * write goes through change_registrations(), which holds the registry lease
 * for the user while it reads the map from the primary database and writes it
 * back. The TLA+ model is tests/prc-firebase/formal/device-registry/.
 *
 * @package PRC\Platform\Firebase
 */
class FCM_Device_Registration {

	/**
	 * User meta key of the token => browser name map.
	 */
	const META_KEY = 'device_registration';

	/**
	 * Lease row prefix. The row is "{prefix}{user_id}" in the main site's options table.
	 */
	const LEASE_PREFIX = 'prc_fcm_registry_lock_';

	/**
	 * Lease TTL in seconds. The work under the lease is one SELECT and one meta write.
	 */
	const LEASE_TTL = 30;

	/**
	 * Tries to acquire a held lease before the request answers rest_registry_busy.
	 */
	const LEASE_TRIES = 10;

	/**
	 * Register devices.
	 *
	 * @param int   $user_id The user to register the devices for.
	 * @param array $devices The devices to register.
	 * @return array|\WP_Error The response array or error object.
	 */
	public static function register_devices( $user_id, $devices ) {
		if ( ! is_array( $devices ) || array_is_list( $devices ) ) {
			return new WP_Error(
				'rest_improperly_formatted',
				__( 'Devices is not an associative array.', 'prc-firebase' ),
				array( 'status' => 403 )
			);
		}
		foreach ( $devices as $device => &$device_ua ) {
			$device_ua = self::create_browser_name( $device_ua ); 
		}
		unset( $device_ua );

		$changed = self::change_registrations(
			$user_id,
			fn( array $current ) => array_diff_assoc( $devices, $current ) ? array_merge( $current, $devices ) : $current
		);
		if ( is_wp_error( $changed ) ) {
			return $changed;
		}
		if ( $changed['before'] === $changed['after'] ) {
			return array(
				'updated_devices' => false,
				'registrations'   => $devices,
			);
		}
		return array(
			'updated_devices' => true,
			'devices'         => $devices,
		);
	}

	/**
	 * Unregister devices.
	 *
	 * Sends the unregister notice after the write, and only to the tokens this
	 * call removed, so a browser is never told it is unsubscribed while the
	 * map still holds its token for this user.
	 *
	 * @param array $user_id The user to unregister the devices for.
	 * @param array $devices The devices to unregister.
	 * @return array|\WP_Error The response array or error object.
	 */
	public static function unregister_devices( $user_id, $devices ) {
		if ( ! is_array( $devices ) || array_is_list( $devices ) ) {
			return new WP_Error(
				'rest_improperly_formatted',
				__( 'Devices is not an associative array.', 'prc-firebase' ),
				array( 'status' => 403 )
			);
		}

		$changed = self::change_registrations(
			$user_id,
			fn( array $current ) => array_diff_key( $current, $devices )
		);
		if ( is_wp_error( $changed ) ) {
			return $changed;
		}

		$removed = array_keys( array_diff_key( $changed['before'], $changed['after'] ) );
		if ( empty( $removed ) ) {
			return array(
				'updated_devices' => false,
				'registrations'   => $devices,
			);
		}

		FCM_Messaging::send_unregister_notice( $removed );

		return array( 'updated_devices' => true );
	}

	/**
	 * Prune unknown and invalid devices from user registrations.
	 *
	 * FCM is asked outside the lease. Under the lease, only the tokens FCM
	 * rejected are removed from the map as it is then, so a browser that
	 * registers during the FCM call stays.
	 *
	 * @param array $users The users to prune the devices for.
	 * @return array|\WP_Error The response array or error object.
	 */
	public static function prune_unknown_invalid_devices( $users ) {
		if ( ! is_array( $users ) ) {
			return new WP_Error(
				'rest_improperly_formatted',
				__( 'Users is not an array', 'prc-firebase' ),
				array( 'status' => 403 )
			);
		}
		$results = array(
			'no_change' => array(),
			'pruned'    => array(),
		);
		foreach ( $users as $user ) {
			$devices = self::get_device_registrations( array( $user ), true );
			// if devices not empty and devices is a valid array.
			if ( ! is_wp_error( $devices ) && is_array( $devices['devices'] ) && count( $devices['devices'] ) > 0 ) {
				$tokens     = array_keys( $devices['devices'] );
				$validation = self::validate_installations( $tokens );
				// if validation is array and validation.valid exists. 
				if ( ! is_wp_error( $validation ) && is_array( $validation['valid'] ?? null ) ) {
					$rejected = array_flip( array_diff( $tokens, $validation['valid'] ) );
					if ( $rejected ) {
						$changed = self::change_registrations(
							$user,
							fn( array $current ) => array_diff_key( $current, $rejected )
						);
						if ( ! is_wp_error( $changed ) && $changed['before'] !== $changed['after'] ) {
							$results['pruned'][] = $user;
							continue;
						}
					}
				}
			}
			$results['no_change'][] = $user;
		}
		return $results;
	}

	/**
	 * Get the device registrations for a user.
	 *
	 * @param array   $users The users to get the device registrations for.
	 * @param boolean $with_names Whether to return the device names.
	 * @return array|\WP_Error The response array or error object.
	 */
	public static function get_device_registrations( $users, $with_names = false ) {
		if ( ! is_array( $users ) ) {
			return new WP_Error(
				'rest_improperly_formatted',
				__( 'Users is not an array', 'prc-firebase' ),
				array( 'status' => 403 )
			);
		}
		$invalid       = array();
		$registrations = array();
		// For each user passed.
		foreach ( $users as $user_id ) {
			// Gather their registrations.
			$registration_set = self::get_current_device_registrations( $user_id );
			// If no registrations are found, or the user id is invalid.
			if ( is_wp_error( $registration_set ) || empty( $registration_set ) ) {
				// note that and continue.
				array_push( $invalid, $user_id );
				continue;
			}
			// otherwise (TK extract the registrations).
			// save the registrations to the registration set. 
			$registration_set_version = $with_names ? $registration_set : array_keys( $registration_set );
			$registrations            = array( ...$registrations, ...$registration_set_version );
		}
		// if no valid registrations were found. 
		if ( 0 >= count( $registrations ) ) {
			// return an error.
			return new WP_Error(
				'rest_no_devices',
				__( 'No device registrations found', 'prc-firebase' ),
				array( 'status' => 404 )
			);
		}
		// otherwise return the registrations and failures.
		return array(
			'devices'   => $registrations,
			'not_found' => $invalid,
		);
	}

	/**
	 * Keep only the tokens that are registered to a user.
	 *
	 * Moves tokens FCM reports as valid, but that the user has not registered,
	 * from `valid` to `not_registered`. Reads the map from the primary database.
	 *
	 * @param array $validation The validate_installations() result.
	 * @param int   $user_id    The user.
	 * @return array|\WP_Error The narrowed result or error object.
	 */
	public static function limit_validation_to_user( $validation, $user_id ) {
		$registered = self::read_registrations_from_primary( (int) $user_id );
		if ( is_wp_error( $registered ) ) {
			return $registered;
		}
		$valid = is_array( $validation['valid'] ?? null ) ? $validation['valid'] : array();

		$validation['valid']          = array_values( array_intersect( $valid, array_keys( $registered ) ) );
		$validation['not_registered'] = array_values( array_diff( $valid, $validation['valid'] ) );
		return $validation;
	}
	
	/**
	 * Get the current device registrations for a user.
	 *
	 * Reads through the object cache. Decisions that write go through
	 * change_registrations() instead.
	 *
	 * @param int $user_id The user to get the device registrations for.
	 * @return array|WP_Error The device registrations or error object.
	 */
	private static function get_current_device_registrations( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error(
				'rest_no_user_found',
				__( 'Unable to find user.', 'prc-firebase' ),
				array( 'status' => 404 )
			);
		}
		return self::as_registration_map( get_user_meta( $user_id, self::META_KEY, true ) );
	}

	/**
	 * Read, change, and write one user's map under the registry lease.
	 *
	 * The lease row lives in the main site's options table, because user meta
	 * is shared by every site of the network. The lease does not fence a
	 * request that stalls past LEASE_TTL, so nothing slow runs under it.
	 *
	 * @param int      $user_id The user.
	 * @param callable $change  Gets the stored map and returns the map to store.
	 * @return array|\WP_Error array( 'before' => map, 'after' => map ), or an error when nothing was written.
	 */
	private static function change_registrations( $user_id, callable $change ) {
		$user_id = (int) $user_id;
		if ( ! get_user_by( 'id', $user_id ) ) {
			return new WP_Error(
				'rest_no_user_found',
				__( 'Unable to find user.', 'prc-firebase' ),
				array( 'status' => 404 )
			);
		}

		$switched = is_multisite() && get_current_blog_id() !== get_main_site_id();
		if ( $switched ) {
			switch_to_blog( get_main_site_id() );
		}
		try {
			$lease = new Lease( self::LEASE_PREFIX, self::LEASE_TTL, 'fcm_' );
			$token = self::acquire_lease( $lease, $user_id );
			if ( '' === $token ) {
				return new WP_Error(
					'rest_registry_busy',
					__( 'Another request is changing these browser registrations. Try again.', 'prc-firebase' ),
					array( 'status' => 409 )
				);
			}
			try {
				$before = self::read_registrations_from_primary( $user_id );
				if ( is_wp_error( $before ) ) {
					return $before;
				}
				$after = $change( $before );
				if ( $after !== $before ) {
					// update_user_meta() skips the write when the cached value equals the new one.
					wp_cache_delete( $user_id, 'user_meta' );
					if ( ! update_user_meta( $user_id, self::META_KEY, $after ) ) {
						return new WP_Error(
							'rest_not_updated',
							__( 'The browser registrations could not be saved.', 'prc-firebase' ),
							array( 'status' => 500 )
						);
					}
				}
				return array(
					'before' => $before,
					'after'  => $after,
				);
			} finally {
				$lease->release( $user_id, $token );
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Acquire the registry lease, waiting briefly while another request holds it.
	 *
	 * @param Lease $lease   The registry lease.
	 * @param int   $user_id The user.
	 * @return string The owner token, or '' when the lease stayed held.
	 */
	private static function acquire_lease( Lease $lease, int $user_id ): string {
		for ( $try = 1; $try <= self::LEASE_TRIES; $try++ ) {
			$token = $lease->acquire( $user_id );
			if ( '' !== $token || self::LEASE_TRIES === $try ) {
				return $token;
			}
			usleep( wp_rand( 20000, 60000 ) );
		}
		return '';
	}

	/**
	 * Read a user's map from the primary database, past the object cache.
	 *
	 * @param int $user_id The user.
	 * @return array|\WP_Error The map or error object.
	 */
	private static function read_registrations_from_primary( int $user_id ) {
		global $wpdb;

		// HyperDB sends reads of a table listed in $srtm to the primary for the rest of the request.
		if ( is_array( $wpdb->srtm ?? null ) ) {
			$wpdb->srtm[ $wpdb->usermeta ] = true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- get_user_meta() can return a cached or replica value from before the last write.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id ASC LIMIT 1",
				$user_id,
				self::META_KEY
			)
		);
		if ( '' !== $wpdb->last_error ) {
			return new WP_Error(
				'rest_registry_unreadable',
				__( 'The browser registrations could not be read.', 'prc-firebase' ),
				array( 'status' => 500 )
			);
		}
		return self::as_registration_map( maybe_unserialize( $value ) );
	}

	/**
	 * Normalize a stored value to a token => name map.
	 *
	 * @param mixed $registration_set The stored value.
	 * @return array The map, or an empty array.
	 */
	private static function as_registration_map( $registration_set ): array {
		return ! is_array( $registration_set ) || array_is_list( $registration_set ) ? array() : $registration_set;
	}

	/**
	 * Validate installations.
	 *
	 * @param array $devices The devices to validate.
	 * @return array|\WP_Error The response array or error object.
	 */
	public static function validate_installations( $devices ) {
		if ( ! is_array( $devices ) ) {
			return new WP_Error(
				'rest_improperly_formatted',
				__( 'Devices is not an array', 'prc-firebase' ),
				array( 'status' => 403 )
			);
		}
		$firebase = new \PRC\Platform\Firebase();
		// If the messaging is not available, return error.
		if ( null === $firebase->messaging ) {
			return new WP_Error( 'rest_firebase_unavailable', __( 'Unable to validate devices. Firebase Cloud Messaging unavailable.', 'prc-firebase' ), array( 'status' => 404 ) );
		}
		try {
			return $firebase->messaging->validateRegistrationTokens( $devices );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'rest_firebase_unavailable', $e->getMessage(), array( 'status' => 502 ) );
		}
	}

	/**
	 * Creates a browser name from a user agent string.
	 *
	 * @param string $ua User agent string.
	 * @return string Browser name.
	 */
	public static function create_browser_name( $ua ) {
		if ( 'unknown' === strtolower( $ua ) || is_null( $ua ) ) {
			return 'Unknown Browser, Unknown Platform';
		}
		$parser          = new UserAgentParser();
		$browser_details = $parser->parse( $ua );

		$browser  = ( is_null( $browser_details->browser() ) ) ? 'Unknown Browser' : $browser_details->browser() . ' ' . ( $browser_details->browserVersion() ?? '' );
		$platform = $browser_details->platform() ?? 'Unknown Platform';
		return trim( $browser ) . ', ' . $platform;
	}
}
