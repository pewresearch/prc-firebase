/* eslint-env browser */
/**
 * External Dependencies
 */
import firebase from 'firebase/compat/app';
import 'firebase/compat/database';
import 'firebase/compat/auth';
import { getMessaging, register, onRegistered } from 'firebase/messaging';
import { getApp } from 'firebase/app';

function loadScript(slug, script) {
	if (!window[slug]) {
		window[slug] = script;
	}
}

function isUsableFirebaseConfig(config) {
	return (
		config && typeof config.apiKey === 'string' && config.apiKey.length > 0
	);
}

const { prcFirebaseConfig, prcFirebaseInteractivesConfig } = window;

if (isUsableFirebaseConfig(prcFirebaseConfig)) {
	loadScript('firebase', firebase.initializeApp(prcFirebaseConfig));
	loadScript('firebaseDb', firebase.database());
	loadScript('firebaseAuth', firebase.auth());
}

if (isUsableFirebaseConfig(prcFirebaseInteractivesConfig)) {
	loadScript(
		'interactivesDb',
		firebase.initializeApp(prcFirebaseInteractivesConfig, 'interactivesDb')
	);
}

window.interactivesDB = window.interactivesDb;

/**
 * Verify messaging availability
 *
 * @param {string} userId - The user ID
 * @return {Promise<void>}
 */
async function verifyMessagingAvailability(userId) {
	if (isNaN(userId) || +userId <= 0) {
		throw new Error('User data unavailable.');
	}
	if (
		'' === (prcFirebaseConfig.messagingSwUrl ?? '') ||
		'' === (prcFirebaseConfig.vapidKey ?? '')
	) {
		throw new Error('Firebase messaging config missing.');
	}
	if (
		'function' !== typeof getMessaging ||
		'function' !== typeof register ||
		'function' !== typeof onRegistered ||
		'function' !== typeof getApp
	) {
		throw new Error('Firebase messaging not configured.');
	}
	if (!('serviceWorker' in navigator)) {
		throw new Error('Service worker unsupported.');
	}
	if (!('Notification' in window)) {
		throw new Error('Notifications unsupported.');
	}
	if (!window.wp?.apiFetch) {
		throw new Error(
			"REST client unavailable. Enqueue 'wp-api-fetch' on this screen."
		);
	}
}

/**
 * Request permissions for notifications
 *
 * @return {Promise<void>}
 */
async function requestPermissions() {
	if (
		typeof Notification !== 'undefined' &&
		Notification.permission === 'default'
	) {
		const permission = await Notification.requestPermission();
		if (permission !== 'granted') {
			throw new Error('Notifications blocked');
		}
	} else if (
		typeof Notification !== 'undefined' &&
		Notification.permission === 'denied'
	) {
		throw new Error('Notifications blocked');
	}
}

/**
 * Register a device to a user
 *
 * @param {string} user  - The user ID
 * @param {Object} token - The device token : user agent string
 * @return {Promise<boolean>} - The result of the API fetch
 */
async function registerDeviceToUser(user, token) {
	const deviceToken = Object.keys(token)[0];
	const results = window.wp
		.apiFetch({
			path: '/prc-api/v3/firebase/register-devices',
			method: 'POST',
			data: {
				user_id: user,
				registrations: token,
			},
		})
		.then((res) => {
			if (res?.updated_devices) {
				const deviceName = res?.devices?.[deviceToken] ?? 'Unknown';
				window.wp
					.apiFetch({
						path: '/prc-api/v3/firebase/send-message',
						method: 'POST',
						data: {
							user_ids: [user],
							message:
								'A new device (' +
								deviceName +
								') has been registered!',
						},
					})
					.then(() => {});
			}
			return {
				saved: true,
				results: res,
			};
		})
		.catch((err) => {
			return {
				saved: false,
				results: err,
			};
		});
	return results;
}

/**
 * Subscribe to browser notifications
 *
 * @param {string} userId - The user ID
 * @return {Promise<boolean>} - The result of the API fetch
 */
async function subscribeToBrowserNotifications(userId) {
	try {
		// Confirm messaging is available
		await verifyMessagingAvailability(userId);
		// request permissions as needed
		await requestPermissions();
	} catch (err) {
		// eslint-disable-next-line no-console -- surfaced for local FCM debugging until REST wiring lands
		console.error(err);
		// eslint-disable-next-line no-alert -- user-visible permission / config failures
		window.alert(err.message);
		return false;
	}
	// Register the Firebase Messaging Service Worker
	const serviceWorkerRegistration = await navigator.serviceWorker.register(
		prcFirebaseConfig.messagingSwUrl
	);
	// Create the messaging function
	const messaging = getMessaging(getApp());

	let resolveDeviceSaved;
	const deviceSaved = new Promise((resolve) => {
		resolveDeviceSaved = resolve;
	});
	// On registration, save the data to the user
	onRegistered(messaging, async (installationId) => {
		const device = {
			[installationId]: navigator.userAgent ?? 'Unknown',
		};

		try {
			const results = await registerDeviceToUser(userId, device);
			if (results?.saved) {
				if (
					!(
						'function' === typeof window.wp_has_consent &&
						false === window.wp_has_consent('functional')
					)
				) {
					try {
						const maxAge = 30 * 24 * 60 * 60;
						await window.cookieStore.set({
							name: 'fcm_installation_id',
							value: installationId,
							maxAge,
						});
						resolveDeviceSaved(true);
						return;
					} catch (error) {
						// eslint-disable-next-line no-console -- surfaced for reference
						console.log(
							`Error saving installation id as cookie: ${error}`
						);
					}
				}
			}
			resolveDeviceSaved(false);
		} catch (error) {
			// eslint-disable-next-line no-console -- surfaced for reference
			console.log(`Error saving installation: ${error}`);
			resolveDeviceSaved(false);
		}
	});

	const vapidKey = prcFirebaseConfig.vapidKey;
	// Add the public key generated from the Firebase console here.
	const registered = await register(messaging, {
		vapidKey,
		serviceWorkerRegistration,
	})
		.then(() => {
			// Success! The Firebase Installation ID can be used to target messages to this app
			// instance and will be delivered asynchronously to your onRegistered() callback.
			return true;
		})
		.catch((err) => {
			// eslint-disable-next-line no-console -- surfaced for reference
			console.error('An error occurred while registering', err);
			return false;
		});
	if (!registered) {
		return false;
	}
	// Wait for onRegistered work for up to 10sec.
	const timeout = new Promise((resolve) => {
		setTimeout(() => resolve(false), 10000);
	});
	return Promise.race([deviceSaved, timeout]);
}

/**
 * Unsubscribe devices from a user
 *
 * @param {string} user    - The user ID
 * @param {Object} devices - Associative array of device token and a string (may be user agent string)
 * @return {Promise<{saved: boolean, results: any}>} - The result of the API fetch
 */
async function unsubscribeDevices(user, devices) {
	const results = window.wp
		.apiFetch({
			path: '/prc-api/v3/firebase/register-devices',
			method: 'POST',
			data: {
				user_id: user,
				registrations: devices,
				unregister: true,
			},
		})
		.then((res) => {
			return {
				saved: true,
				results: res,
			};
		})
		.catch((err) => {
			return {
				saved: false,
				results: err,
			};
		});
	return results;
}

/**
 * Get the browser subscription
 *
 * @param {boolean} asBoolean - Whether to return a boolean or the registration object
 * @return {Promise<ServiceWorkerRegistration | boolean>} - The browser subscription or boolean
 */
async function getBrowserSubscription(asBoolean = true) {
	if (
		typeof Notification === 'undefined' ||
		Notification.permission !== 'granted'
	) {
		return false;
	}
	const sw = prcFirebaseConfig.messagingSwUrl ?? '';
	if (!('serviceWorker' in navigator) || '' === sw) {
		const errMsg =
			'' === sw
				? 'Firebase messaging config missing.'
				: 'Service worker unsupported.';
		throw new Error(errMsg);
	}
	const registration =
		(await navigator.serviceWorker.getRegistration(sw)) ?? false;
	return asBoolean ? !!registration : registration;
}

/**
 * Send a message to users via the REST API
 *
 * @param {string[]} userIds - The user IDs
 * @param {string}   message - The message
 * @param {string}   title   - The title
 * @param {string}   url     - The URL
 * @param {string}   img     - The image URL
 * @return {Promise<{success: boolean, payload: any}>} The result of the API fetch
 */
async function restfullySendMessageToUsers(
	userIds,
	message,
	title = '',
	url = '',
	img = ''
) {
	if (!window.wp?.apiFetch) {
		throw new Error(
			"REST client unavailable. Enqueue 'wp-api-fetch' on this screen."
		);
	}
	if (!Array.isArray(userIds)) {
		throw new Error(
			'Improperly formatted. Users must be passed as an array.'
		);
	}

	const data = {
		user_ids: userIds,
		title,
		message,
		link: url,
		img,
	};

	const results = await window.wp
		.apiFetch({
			path: '/prc-api/v3/firebase/send-message',
			method: 'POST',
			data,
		})
		.then((res) => {
			return {
				success: true,
				payload: res,
			};
		})
		.catch((err) => {
			return {
				success: false,
				payload: err,
			};
		});
	return results;
}

/**
 * Validate an installation
 *
 * @param {boolean} asBoolean - Whether to return a boolean or the installation ID
 * @return {Promise<boolean | string>} - The validity of the installation or the installation ID
 */
async function validateInstallation(asBoolean = true) {
	const cookie = await window.cookieStore.get('fcm_installation_id');
	// If the cookie is set and has a value
	if (cookie && cookie?.value) {
		// Get the validity of the cookie
		const validity = await window.wp
			.apiFetch({
				path: '/prc-api/v3/firebase/validate-devices',
				method: 'POST',
				data: {
					devices: [cookie.value],
				},
			})
			.then((validation) => {
				return Array.isArray(validation?.valid)
					? validation.valid.includes(cookie.value)
					: false;
			})
			.catch(() => {
				return false;
			});
		const asCookie = validity ? cookie.value : false;
		return asBoolean ? validity : asCookie;
	}
	return false;
}

window.fcm =
	'undefined' === typeof window.fcm
		? {
				restfullySendMessageToUsers,
				subscribeToBrowserNotifications,
				getBrowserSubscription,
				validateInstallation,
				unsubscribeDevices,
			}
		: window.fcm;
