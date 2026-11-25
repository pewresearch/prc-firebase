<?php
/**
 * REST API endpoints for PRC Revisions.
 *
 * @package    PRC\Platform\Firebase
 */

namespace PRC\Platform\Firebase;

use WP_REST_Request;
use WP_REST_Server;
use WP_REST_Response;
use WP_Error;


/**
 * REST API class.
 *
 * Provides endpoints for sending messages via Firebase Cloud Messaging 
 */
class Rest_API {

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The loader object.
	 */
	public function __construct( $loader ) {
		// Register Firebase messaging cookie information with WP Consent API.
		$loader->add_action( 'init', $this, 'register_fcm_cookies' );
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	/**
	 * Register Firebase messaging cookie information with WP Consent API.
	 *
	 * @hook init
	 */
	public function register_fcm_cookies() {
		// Check if WP Consent API functions are available.
		if ( ! function_exists( 'wp_add_cookie_info' ) ) {
			return;
		}

		// Register the quiz progress cookie (JSON format).
		wp_add_cookie_info(
			'fcm_installation_id',             // Cookie name.
			'Firebase',                        // Plugin or service name.
			'functional',                      // Category: functional since it's needed for quiz functionality.
			'30 days',                         // Expiration time.
			'Stores the information for users to manage browser subscriptions and to reduce redundant registrations.', // Function description.
			'Firebase Installation Instance',  // Type of personal data collected.
			true,                              // Not restricted to members only.
			false,                             // Not restricted to administrators only.
			'HTTP',                            // Cookie type.
			''                                 // Use current site domain.
		);
	}



	/**
	 * Register REST routes.
	 *
	 * @hook rest_api_init
	 */
	public function register_routes() {
		// Route 1: Send messages.
		register_rest_route(
			'prc-api/v3',
			'firebase/send-message',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'restfully_send_messages' ),
					'permission_callback' => function ( WP_REST_Request $request ) {
						return $this->can_send_messages( $request->get_param( 'user_ids' ) );
					},
					'args'                => array(
						'user_ids' => array(
							'required' => true,
							'type'     => 'array',
						),
						'message'  => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'title'    => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'link'     => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
							'validate_callback' => array( $this, 'validate_links' ),
						),
						'img'      => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
							'validate_callback' => array( $this, 'validate_links' ),
						),
					),
				),
			)
		);

		// Route 2: Register devices.
		register_rest_route(
			'prc-api/v3',
			'firebase/register-devices',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'restfully_register_devices' ),
					'permission_callback' => function ( WP_REST_Request $request ) {
						return $this->can_send_messages( array( $request->get_param( 'user_id' ) ) );
					},
					'args'                => array(
						'user_id'       => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'registrations' => array(
							'required'          => true,
							'validate_callback' => function ( $value ) {
								// Only allow keyed devices.
								return ( is_array( $value ) && ! array_is_list( $value ) );
							},
						),
						'unregister'    => array(
							'required' => false,
							'type'     => 'boolean',
						),
					),
				),
			)
		);
		// Route 3: validate registration.
		register_rest_route(
			'prc-api/v3',
			'firebase/validate-devices',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'restfully_validate_devices' ),
					'permission_callback' => function () {
						return current_user_can( 'read' );
					},
					'args'                => array(
						'devices' => array(
							'required' => true,
							'type'     => 'array',
						),
					),
				),
			)
		);
	}

	/**
	 * Validate links.
	 *
	 * @param string $value The value to validate.
	 * @return bool True if the value is a valid link, false otherwise.
	 */
	public function validate_links( $value ) {
		if ( null === $value || '' === $value ) {
			return true;
		}
		return is_string( $value ) && false !== wp_http_validate_url( $value );
	}

	/**
	 * Check if the current user can send messages or register devices.
	 *
	 * @param array $user_ids The user IDs.
	 * @return bool True if the current user can send messages or register devices, false otherwise.
	 */
	private function can_send_messages( $user_ids ): bool {
		// Skip empty/non logged in users.
		if ( array() === $user_ids || ! current_user_can( 'read' ) ) {
			return false;
		}
		// If a user is editing themself.
		if ( is_array( $user_ids ) && count( $user_ids ) === 1 && (int) get_current_user_id() === (int) $user_ids[0] ) {
			return true;
		}
		// Otherwise do they have admin level permissions.
		return current_user_can( 'manage_options' );
	}


	/**
	 * Register or unregister devices for a user.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error The response object or error object.
	 */
	public function restfully_register_devices( \WP_REST_Request $request ) {
		// Gather User Ids. 
		$user_id    = $request->get_param( 'user_id' );
		$devices    = $request->get_param( 'registrations' );
		$unregister = $request->get_param( 'unregister' ) ?? false; 

		// Update user registrations. 
		$change_registration = $unregister ? FCM_Device_Registration::unregister_devices( $user_id, $devices ) : 
			FCM_Device_Registration::register_devices( $user_id, $devices );
		return is_wp_error( $change_registration ) ? rest_ensure_response( $change_registration ) : new WP_REST_Response( $change_registration, 200 );
	}


	/**
	 * Send messages to users.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error The response object or error object.
	 */
	public function restfully_send_messages( \WP_REST_Request $request ) {
		// Read the same parameters the permission check read.
		$user_ids = $request->get_param( 'user_ids' );
		$message  = $request->get_param( 'message' );
		// If there is no array of user ids and no message, return an error.
		if ( ! is_array( $user_ids ) || ! is_string( $message ) ) {
			return new WP_Error(
				'rest_improperly_formatted',
				__( 'Request improperly formatted', 'prc-firebase' ),
				array( 'status' => 403 )
			);
		}

		$title = $request->get_param( 'title' ) ?? false;
		$link  = $request->get_param( 'link' ) ?? false;
		$img   = $request->get_param( 'img' ) ?? false;

		$send_message = FCM_Messaging::send_message_to_users( $user_ids, $message, $title, $link, $img );

		if ( is_wp_error( $send_message ) ) {
			return rest_ensure_response( $send_message );
		}

		return new WP_REST_Response( $send_message, 200 );
	}


	/**
	 * Validate devices.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error The response object or error object.
	 */
	public function restfully_validate_devices( \WP_REST_Request $request ) {
		// Get the devices to validate.
		$devices = $request->get_param( 'devices' );
		// Validate the devices.
		$device_validation = FCM_Device_Registration::validate_installations( $devices );
		// A token counts as valid only while the current user has it registered.
		if ( ! is_wp_error( $device_validation ) ) {
			$device_validation = FCM_Device_Registration::limit_validation_to_user( $device_validation, get_current_user_id() );
		}
		// If the validation is an error, return the error.
		return ( is_wp_error( $device_validation ) ) ? rest_ensure_response( $device_validation ) : 
			new WP_REST_Response( $device_validation, 200 );
	}
}
