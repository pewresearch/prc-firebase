<?php
/**
 * User Profile class.
 *
 * @package PRC\Platform\Firebase
 */

namespace PRC\Platform\Firebase;

use WP_REST_Request;
use WP_REST_Server;
use WP_REST_Response;
use WP_Error;
use Kreait\Firebase\Messaging\CloudMessage;


/**
 * User Profile class.
 *
 * @package PRC\Platform\Firebase
 */
class User_Profile {
	/**
	 * Constructor.
	 *
	 * @param Loader $loader The loader object.
	 */
	public function __construct( $loader ) {
		$loader->add_action( 'show_user_profile', $this, 'firebase_device_registration_list' );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'add_profile_scripts_and_styles' );
	}

	
	/**
	 * Display the device registration list for the current user
	 *
	 * @return void
	 */
	public function firebase_device_registration_list() {
		$current_user    = wp_get_current_user();
		$current_user_id = $current_user->ID; 
		
		echo '<h2 id="browser-notifications">Browser Notifications</h2>';

		// Remove all invalid or unknown devices. 
		$prune_it = FCM_Device_Registration::prune_unknown_invalid_devices( array( $current_user_id ) );
		// Get all remaining valid devices.
		$device_registrations = FCM_Device_Registration::get_device_registrations( array( $current_user_id ), true );
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- wp-admin experiance only; page is never cached
		$cookie = isset( $_COOKIE['fcm_installation_id'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['fcm_installation_id'] ) ) : false;

		if ( is_wp_error( $device_registrations ) || ! in_array( $cookie, array_keys( $device_registrations['devices'] ) ) ) : ?>
			<button class="button browser-limit-interactions" type="button" onclick="registerCurrentBrowser(<?php echo esc_attr( $current_user_id ); ?>)">
				Subscribe to browser notifications
			</button>
			<?php
		endif; 

		if ( ! is_wp_error( $device_registrations ) ) {
			?>
			<table class="form-table">
				<tr>
					<th>Browser List <div class="browser-spinner spinner"></div></th>
					<td> 
						<div class="browsers browser-limit-interactions">
							<?php
							if ( in_array( $cookie, array_keys( $device_registrations['devices'] ) ) ) : 
								$current_browser_name = $device_registrations['devices'][ $cookie ] ?? 'Unknown';
								?>
								<div class="browser-item">
									<input type="checkbox" id="browser-<?php echo esc_attr( $cookie ); ?>" name="browsers" value="<?php echo esc_attr( $cookie ); ?>">
									<label for="browser-<?php echo esc_attr( $cookie ); ?>"><strong>Current Browser:</strong> <?php echo esc_html( $current_browser_name ); ?></label>
								</div>
							<?php endif; ?>
							<?php
							foreach ( $device_registrations['devices'] as $device => $name ) : 
								if ( $cookie && $device === $cookie ) {
									continue;
								}
								?>
								<div class="browser-item">
									<input type="checkbox" id="browser-<?php echo esc_attr( $device ); ?>" name="browsers" value="<?php echo esc_attr( $device ); ?>">
									<label for="browser-<?php echo esc_attr( $device ); ?>"><?php echo esc_html( $name ); ?></label>
								</div>
							<?php endforeach; ?>
						</div>
						<div>
							<button class="button browser-limit-interactions" type="button" onclick="unregisterDevices(<?php echo esc_attr( $current_user_id ); ?>)">
								Unsubscribe from notifications on selected browsers
							</button>
						</div>
					</td>
				</tr>
			</table>
			<?php
		}
	}

	/**
	 * Add scripts and styles to the profile page
	 *
	 * @param string $hook_suffix The hook suffix for the current admin page.
	 * @return void
	 */
	public function add_profile_scripts_and_styles( $hook_suffix ) {
		// Only load on profile pages. 
		if ( ! in_array( $hook_suffix, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}
		// Load scripts.
		wp_enqueue_script( 'firebase' );
		wp_enqueue_script( 'wp-api-fetch' );
		
		$admin_profile_js = '
            function reloadPage() {
				setTimeout(() => {
					window.location.hash = "#browser-notifications";
					location.reload();
				}, 3000);
            }
            function limitInteractions(){
                const limited = document.querySelectorAll(".browser-limit-interactions");
                limited.forEach((element, index) => {
                	element.style.pointerEvents = "none";
                    element.style.opacity = 0.4;
				});
				const browserSpinner = document.querySelector(".browser-spinner");
                if (browserSpinner){
					browserSpinner.classList.add(\'is-active\');
                }
            }

            async function unregisterDevices(user){
                if(window?.fcm?.unsubscribeDevices){
                    limitInteractions()
                    const browsers = document.querySelectorAll(\'input[name="browsers"]:checked\');
                    const browserIds = Array.from(browsers).map(device => [device.value, \'unregistered\']);
					const browserIdList = Object.fromEntries(browserIds);
                    const unsubscribed = await window?.fcm?.unsubscribeDevices(user, browserIdList);
                    reloadPage()
                }else{
                    console.warn("unsubscribeDevices unavailable.");
                }
            }
            async function registerCurrentBrowser(user){
                if (window?.fcm?.subscribeToBrowserNotifications){
                    limitInteractions()
                	await fcm.subscribeToBrowserNotifications(user);
                    reloadPage()
                }else{
                    console.warn("subscribeToBrowserNotifications unavailable.");
                }
            }
        ';
		wp_add_inline_script( 'firebase', $admin_profile_js );

		$admin_profile_css = '
        .browsers { 
            columns: 300px 3;
			margin-bottom: 20px;
        }
        .browser-item{
            margin-bottom: 20px;
        }
    ';
		wp_add_inline_style( 'prc-platform-wp-admin', $admin_profile_css );
	}
}