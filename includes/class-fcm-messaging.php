<?php
/**
 * FCM Messaging class.
 *
 * @package PRC\Platform\Firebase
 */

namespace PRC\Platform\Firebase;

use WP_Error;
use Kreait\Firebase\Messaging\CloudMessage;


/**
 * FCM Messaging class.
 *
 * @package PRC\Platform\Firebase
 */
class FCM_Messaging {

	/**
	 * The unregister notice's data.prc_action. firebase-messaging-sw.js matches it.
	 */
	const UNREGISTER_ACTION = 'unregister';

	/**
	 * Send a message to a user.
	 *
	 * @param array  $user_ids The user ids to send the message to.
	 * @param string $message The message to send.
	 * @param string $title The title of the message.
	 * @param string $link The link to the message.
	 * @param string $img The image to the message.
	 * @return array|\WP_Error The response array or error object.
	 */
	public static function send_message_to_users( $user_ids, $message, $title = false, $link = false, $img = false ) {

		$registration_list = FCM_Device_Registration::get_device_registrations( $user_ids );
		if ( is_wp_error( $registration_list ) ) {
			return $registration_list;
		}
		$sent = self::send_message_to_devices( $registration_list['devices'], $message, $title, $link, $img );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}
		return array(
			'send_report'   => $sent, 
			'users_skipped' => $registration_list['not_found'],
		);
	}

	/**
	 * Sanitize a value.
	 *
	 * @param string $value The value to sanitize.
	 * @param bool   $link Whether the value is a link.
	 * @return string|false The sanitized value or false if the value is not a string or is empty.
	 */
	private static function sanitize_value( $value, $link = false ) {
		if ( is_string( $value ) && 0 < mb_strlen( trim( $value ) ) ) {
			$trimmed = trim( $value );
			return $link ? wp_http_validate_url( $trimmed ) : $trimmed;
		}
		return false; 
	}

	/**
	 * Send a message to a device.
	 *
	 * @param array  $device_list The device list to send the message to.
	 * @param string $message The message to send.
	 * @param string $title The title of the message.
	 * @param string $link The link to the message.
	 * @param string $img The image to the message.
	 * @return array|\WP_Error The response array or error object.
	 */
	public static function send_message_to_devices( $device_list, $message, $title, $link, $img ) {
		$firebase = new \PRC\Platform\Firebase();
		// If the messaging is not available, return error.
		if ( null === $firebase->messaging ) {
			return new WP_Error( 'rest_firebase_unavailable', __( 'Unable to send message. Firebase Cloud Messaging unavailable.', 'prc-firebase' ), array( 'status' => 404 ) );
		}
		$clean_message = self::sanitize_value( $message );
		if ( ! $clean_message ) {
			return new WP_Error( 'rest_missing_message', __( 'Please provide a message to send.', 'prc-firebase' ), array( 'status' => 400 ) );
		}

		$clean_title = self::sanitize_value( $title );
		$clean_link  = self::sanitize_value( $link, true );
		$clean_img   = self::sanitize_value( $img, true );

		// Otherwise create the message.
		$data          = array_merge(
			array(
				'body' => $clean_message,
			),
			$clean_title ? array( 'title' => $clean_title ) : array(),
			$clean_link ? array( 'link' => $clean_link ) : array(),
			$clean_img ? array( 'img' => $clean_img ) : array(),
		);
		$cloud_message = CloudMessage::new()->withData( $data ); 
		// Send the message.
		$send_report = $firebase->messaging->sendMulticast( $cloud_message, $device_list );
		// Return the send report.
		return $send_report;
	}

	/**
	 * Tell browsers that they were unregistered.
	 *
	 * The service worker acts on data.prc_action, which send_message_to_devices()
	 * never sets, so no send-message request can make a browser drop its cookie.
	 *
	 * @param array $device_list The removed tokens.
	 * @return mixed|\WP_Error The send report or error object.
	 */
	public static function send_unregister_notice( $device_list ) {
		$firebase = new \PRC\Platform\Firebase();
		if ( null === $firebase->messaging ) {
			return new WP_Error( 'rest_firebase_unavailable', __( 'Unable to send message. Firebase Cloud Messaging unavailable.', 'prc-firebase' ), array( 'status' => 404 ) );
		}
		$cloud_message = CloudMessage::new()->withData(
			array(
				'prc_action' => self::UNREGISTER_ACTION,
				'title'      => 'Unregister Request',
				'body'       => 'User has decided to unregister this device from receiving browser notifications from PRC.',
			)
		);
		try {
			return $firebase->messaging->sendMulticast( $cloud_message, array_values( $device_list ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'rest_firebase_unavailable', $e->getMessage(), array( 'status' => 502 ) );
		}
	}
}
