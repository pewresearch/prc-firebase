import { app as __WEBPACK_EXTERNAL_MODULE__prc_firebase_999ee737_app__, getMessaging as __WEBPACK_EXTERNAL_MODULE__prc_firebase_999ee737_getMessaging__, messagingSwUrl as __WEBPACK_EXTERNAL_MODULE__prc_firebase_999ee737_messagingSwUrl__, onRegistered as __WEBPACK_EXTERNAL_MODULE__prc_firebase_999ee737_onRegistered__, register as __WEBPACK_EXTERNAL_MODULE__prc_firebase_999ee737_register__, vapidKey as __WEBPACK_EXTERNAL_MODULE__prc_firebase_999ee737_vapidKey__ } from "@prc/firebase";
/******/ // The require scope
/******/ var __webpack_require__ = {};
/******/ 
/************************************************************************/
/******/ /* webpack/runtime/define property getters */
/******/ (() => {
/******/ 	// define getter functions for harmony exports
/******/ 	__webpack_require__.d = (exports, definition) => {
/******/ 		for(var key in definition) {
/******/ 			if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 				Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 			}
/******/ 		}
/******/ 	};
/******/ })();
/******/ 
/******/ /* webpack/runtime/hasOwnProperty shorthand */
/******/ (() => {
/******/ 	__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ })();
/******/ 
/************************************************************************/

;// external "@prc/firebase"

;// ./src/browser-messaging/index.js
/* eslint-env browser */


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
  if (!__WEBPACK_EXTERNAL_MODULE__prc_firebase_999ee737_app__ || '' === __WEBPACK_EXTERNAL_MODULE__prc_firebase_999ee737_messagingSwUrl__) {
    throw new Error('Firebase not configured.');
  }
  if (!('serviceWorker' in navigator)) {
    throw new Error('Service worker unsupported.');
  }
  if (!('Notification' in window)) {
    throw new Error('Notifications unsupported.');
  }
  if (!window.wp?.apiFetch) {
    throw new Error("REST client unavailable. Enqueue 'wp-api-fetch' on this screen.");
  }
}

/**
 * Request permissions for notifications
 *
 * @return {Promise<void>}
 */
async function requestPermissions() {
  if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
      throw new Error('Notifications blocked');
    }
  } else if (typeof Notification !== 'undefined' && Notification.permission === 'denied') {
    throw new Error('Notifications blocked');
  }
}

/**
 * Register a device to a user
 *
 * @param {string} user  - The user ID
 * @param {string} token - The device token
 * @return {Promise<void>}
 */
async function registerDeviceToUser(user, token) {
  const deviceToken = Object.keys(token)[0];
  const results = window.wp.apiFetch({
    path: '/prc-api/v3/firebase/register-devices',
    method: 'POST',
    data: {
      user_id: user,
      registrations: token
    }
  }).then(res => {
    if (res?.updated_devices) {
      const deviceName = res?.devices?.[deviceToken] ?? 'Unknown';
      window.wp.apiFetch({
        path: '/prc-api/v3/firebase/send-message',
        method: 'POST',
        data: {
          user_ids: [user],
          message: 'A new device (' + deviceName + ') has been registered!'
        }
      }).then(() => {});
    }
    return {
      saved: true,
      results: res
    };
  }).catch(err => {
    return {
      saved: false,
      results: err
    };
  });
  return results;
}

/**
 * Unsubscribe devices from a user
 *
 * @param {string} user    - The user ID
 * @param {Object} devices - Associative array of device token and a string (may be user agent string)
 * @return {Promise<{saved: boolean, results: any}>} - The result of the API fetch
 */
async function unsubscribeDevices(user, devices) {
  const results = window.wp.apiFetch({
    path: '/prc-api/v3/firebase/register-devices',
    method: 'POST',
    data: {
      user_id: user,
      registrations: devices,
      unregister: true
    }
  }).then(res => {
    return {
      saved: true,
      results: res
    };
  }).catch(err => {
    return {
      saved: false,
      results: err
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
    return;
  }
  // Register the Firebase Messaging Service Worker
  const serviceWorkerRegistration = await navigator.serviceWorker.register(__WEBPACK_EXTERNAL_MODULE__prc_firebase_999ee737_messagingSwUrl__);
  // Create the messaging function
  const messaging = __WEBPACK_EXTERNAL_MODULE__prc_firebase_999ee737_getMessaging__(__WEBPACK_EXTERNAL_MODULE__prc_firebase_999ee737_app__);
  let resolveDeviceSaved;
  const deviceSaved = new Promise(resolve => {
    resolveDeviceSaved = resolve;
  });
  // On registration, save the data to the user
  __WEBPACK_EXTERNAL_MODULE__prc_firebase_999ee737_onRegistered__(messaging, async installationId => {
    const device = {
      [installationId]: navigator.userAgent ?? 'Unknown'
    };
    try {
      const results = await registerDeviceToUser(userId, device);
      if (results?.saved) {
        if (!('function' === typeof window.wp_has_consent && false === window.wp_has_consent('functional'))) {
          try {
            const maxAge = 30 * 24 * 60 * 60;
            await window.cookieStore.set({
              name: 'fcm_installation_id',
              value: installationId,
              maxAge
            });
            resolveDeviceSaved(true);
            return;
          } catch (error) {
            // eslint-disable-next-line no-console -- surfaced for reference
            console.log(`Error saving installation id as cookie: ${error}`);
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

  // Add the public key generated from the Firebase console here.
  const registered = await __WEBPACK_EXTERNAL_MODULE__prc_firebase_999ee737_register__(messaging, {
    vapidKey: __WEBPACK_EXTERNAL_MODULE__prc_firebase_999ee737_vapidKey__,
    serviceWorkerRegistration
  }).then(() => {
    // Success! The Firebase Installation ID can be used to target messages to this app
    // instance and will be delivered asynchronously to your onRegistered() callback.
    return true;
  }).catch(err => {
    // eslint-disable-next-line no-console -- surfaced for local FCM debugging until REST wiring lands
    console.error('An error occurred while registering', err);
    return false;
  });
  if (!registered) {
    return false;
  }
  // Wait for onRegistered work for up to 10sec.
  const timeout = new Promise(resolve => {
    setTimeout(() => resolve(false), 10000);
  });
  return Promise.race([deviceSaved, timeout]);
}

/**
 * Get the browser subscription
 *
 * @param {boolean} asBoolean - Whether to return a boolean or the registration object
 * @return {Promise<boolean|object>} - The browser subscription
 */
async function getBrowserSubscription(asBoolean = true) {
  if (typeof Notification === 'undefined' || Notification.permission !== 'granted') {
    return false;
  }
  if (!('serviceWorker' in navigator) || '' === __WEBPACK_EXTERNAL_MODULE__prc_firebase_999ee737_messagingSwUrl__) {
    const errMsg = '' === __WEBPACK_EXTERNAL_MODULE__prc_firebase_999ee737_messagingSwUrl__ ? 'Firebase not configured.' : 'Service worker unsupported.';
    throw new Error(errMsg);
  }
  const registration = (await navigator.serviceWorker.getRegistration(__WEBPACK_EXTERNAL_MODULE__prc_firebase_999ee737_messagingSwUrl__)) ?? false;
  return asBoolean ? !!registration : registration;
}

/**
 * Validate the installation
 *
 * @param {boolean} asBoolean - Whether to return a boolean or the installation object
 * @return {Promise<boolean|object>} - The installation
 */
async function validateInstallation(asBoolean = true) {
  // Get the cookie
  const cookie = await window.cookieStore.get('fcm_installation_id');
  // If the cookie is set and has a value
  if (cookie && cookie?.value) {
    // Get the validity of the cookie
    const validity = await window.wp.apiFetch({
      path: '/prc-api/v3/firebase/validate-devices',
      method: 'POST',
      data: {
        devices: [cookie.value]
      }
    }).then(validation => {
      return Array.isArray(validation?.valid) ? validation.valid.includes(cookie.value) : false;
    }).catch(() => {
      return false;
    });
    const asCookie = validity ? cookie.value : false;
    return asBoolean ? validity : asCookie;
  }
  return false;
}

/**
 * Send a message to users via the REST API
 *
 * @param {string[]} userIds - The user IDs
 * @param {string}   message - The message
 * @param {string}   title   - The title
 * @param {string}   url     - The URL
 * @param {string}   img     - The image URL
 * @return {Promise<{success: boolean, payload: any}>} - The result of the API fetch
 */
async function restfullySendMessageToUsers(userIds, message, title = '', url = '', img = '') {
  if (!window.wp?.apiFetch) {
    throw new Error("REST client unavailable. Enqueue 'wp-api-fetch' on this screen.");
  }
  if (!Array.isArray(userIds)) {
    throw new Error('Improperly formatted. Users must be passed as an array.');
  }
  const data = {
    user_ids: userIds,
    message,
    title,
    link: url,
    img
  };
  const results = window.wp.apiFetch({
    path: '/prc-api/v3/firebase/send-message',
    method: 'POST',
    data
  }).then(res => {
    return {
      success: true,
      payload: res
    };
  }).catch(err => {
    return {
      success: false,
      payload: err
    };
  });
  return results;
}
export { getBrowserSubscription, restfullySendMessageToUsers, subscribeToBrowserNotifications, unsubscribeDevices, validateInstallation };
