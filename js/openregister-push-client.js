/**
 * OpenRegister Web Push subscribe client.
 *
 * Loaded on EVERY full-page Nextcloud render by PushClientScriptListener
 * (openregister-web-push-engine). It is OPT-IN ONLY: it NEVER calls
 * Notification.requestPermission() or subscribes on load. It exposes
 * window.OCA.OpenRegister.WebPush.{ enablePush, disablePush, isSupported,
 * permission } for the settings UI to call on an explicit user gesture, per
 * Chrome's permission-abuse rules.
 *
 * Plain static asset (NOT bundled through webpack); uses the OC.* globals and
 * fetch so it can run standalone on any page.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 */

(function() {
	'use strict'

	var SW_URL = OC.filePath('openregister', 'js', 'openregister-push-sw.js')
	var SW_SCOPE = OC.generateUrl('/apps/openregister/js/')

	function isSupported() {
		return (typeof navigator !== 'undefined'
			&& 'serviceWorker' in navigator
			&& typeof window !== 'undefined'
			&& 'PushManager' in window
			&& 'Notification' in window)
	}

	/** Decode a base64url VAPID public key into the Uint8Array applicationServerKey expects. */
	function urlBase64ToUint8Array(base64String) {
		var padding = '='.repeat((4 - (base64String.length % 4)) % 4)
		var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
		var raw = window.atob(base64)
		var output = new Uint8Array(raw.length)
		for (var i = 0; i < raw.length; ++i) {
			output[i] = raw.charCodeAt(i)
		}
		return output
	}

	function apiUrl(path) {
		return OC.generateUrl('/apps/openregister/webpush' + path)
	}

	function fetchVapidKey() {
		return fetch(apiUrl('/vapid-public-key'), {
			headers: { requesttoken: OC.requestToken },
		}).then(function(r) { return r.json() })
	}

	function postSubscription(subscription) {
		var json = subscription.toJSON()
		return fetch(apiUrl('/subscription'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
			},
			body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys }),
		})
	}

	function deleteSubscription(endpoint) {
		return fetch(apiUrl('/subscription'), {
			method: 'DELETE',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
			},
			body: JSON.stringify({ endpoint: endpoint }),
		})
	}

	/**
	 * Enable browser push for the current user. MUST be called from a user
	 * gesture (button/toggle) — it requests notification permission and
	 * registers the Service Worker only then.
	 *
	 * @return {Promise<boolean>} true when subscribed.
	 */
	function enablePush() {
		if (!isSupported()) {
			return Promise.reject(new Error('Web Push is not supported in this browser'))
		}
		return Notification.requestPermission().then(function(permission) {
			if (permission !== 'granted') {
				throw new Error('Notification permission not granted')
			}
			return Promise.all([
				navigator.serviceWorker.register(SW_URL, { scope: SW_SCOPE }),
				fetchVapidKey(),
			])
		}).then(function(results) {
			var registration = results[0]
			var vapid = results[1]
			if (!vapid || !vapid.configured || !vapid.publicKey) {
				throw new Error('Server VAPID key is not configured (run occ openregister:web-push:generate-vapid)')
			}
			return registration.pushManager.subscribe({
				userVisibleOnly: true,
				applicationServerKey: urlBase64ToUint8Array(vapid.publicKey),
			})
		}).then(function(subscription) {
			return postSubscription(subscription).then(function() { return true })
		})
	}

	/**
	 * Disable browser push: unsubscribe in the browser and delete the
	 * server-side subscription.
	 *
	 * @return {Promise<boolean>} true when unsubscribed.
	 */
	function disablePush() {
		if (!isSupported()) {
			return Promise.resolve(false)
		}
		return navigator.serviceWorker.getRegistration(SW_SCOPE).then(function(registration) {
			if (!registration) {
				return false
			}
			return registration.pushManager.getSubscription().then(function(subscription) {
				if (!subscription) {
					return false
				}
				var endpoint = subscription.endpoint
				return subscription.unsubscribe().then(function() {
					return deleteSubscription(endpoint).then(function() { return true })
				})
			})
		})
	}

	window.OCA = window.OCA || {}
	window.OCA.OpenRegister = window.OCA.OpenRegister || {}
	window.OCA.OpenRegister.WebPush = {
		isSupported: isSupported,
		enablePush: enablePush,
		disablePush: disablePush,
		permission: function() {
			return ('Notification' in window) ? Notification.permission : 'unsupported'
		},
	}
}())
