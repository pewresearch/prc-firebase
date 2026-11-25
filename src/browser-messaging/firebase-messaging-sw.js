/* eslint-env serviceworker */

// Matches FCM_Messaging::UNREGISTER_ACTION. Only send_unregister_notice() sets prc_action.
const unregisterAction = 'unregister';
/**
 * Handle push messages
 *
 * @param {Object} event - The event object
 * @return {Promise<void>}
 */
self.addEventListener('push', (event) => {
	const payload = event.data?.json() ?? {};
	const data = payload.data ?? {};
	if (unregisterAction === data?.prc_action) {
		event.waitUntil(unregisterServiceWorker());
	} else {
		event.waitUntil(sendMessage(data));
	}
});

function sendMessage(data) {
	const img = data?.img ?? '/wp-content/images/symbol-alt.svg';
	const notificationTitle = data?.title ?? 'Pew Research Center';
	const notificationOptions = {
		body: data.body,
		icon: img,
	};

	if (data?.link) {
		notificationOptions.data = {
			url: data.link,
		};
	}

	return self.registration.showNotification(
		notificationTitle,
		notificationOptions
	);
}

async function unregisterServiceWorker() {
	if (self?.cookieStore) {
		await self.cookieStore.delete('fcm_installation_id');
	}
	await self.registration.showNotification('Pew Research Center', {
		body: 'This browser will no longer recieve notifications from Pew Research Center.',
		icon: '/wp-content/images/symbol-alt.svg',
	});
	// At the moment, if we unregister the sw and then register it, it doesn't work.
	// Therefore, for now, we will just remove the SW from our database and delete our cookie.
	// self.registration.unregister(); // maybe someday.
}

/**
 * Handle notification clicks
 *
 * @param {Object} event - The event object
 * @return {Promise<void>}
 */
self.addEventListener('notificationclick', (event) => {
	event.notification.close();
	if (event?.notification?.data?.url) {
		event.waitUntil(
			// Get all the Window clients
			clients
				.matchAll({ type: 'window', includeUncontrolled: true })
				.then((clientsArr) => {
					const windowToFocus = clientsArr.find(
						(windowClient) =>
							windowClient.url === event.notification.data.url
					);
					if (windowToFocus) {
						// If a Window tab matching the targeted URL already exists, focus that;
						windowToFocus.focus();
					} else {
						// Otherwise, open a new tab to the applicable URL and focus it.
						clients
							.openWindow(event.notification.data.url)
							.then((windowClient) =>
								windowClient ? windowClient.focus() : null
							);
					}
				})
		);
	}
});

/**
 * Handle installation
 *
 * @param {Object} event - The event object
 * @return {Promise<void>}
 */
self.addEventListener('install', () => {
	// Force the waiting service worker to become the active service worker immediately
	self.skipWaiting();
});
