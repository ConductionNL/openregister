/**
 * OpenRegister Web Push Service Worker.
 *
 * Renders rich background OS notifications pushed by the OpenRegister web-push
 * engine (openregister-web-push-engine) and routes clicks (top-level + per
 * action button) to the resolved deeplink. This file is a plain static asset
 * served at /apps/openregister/js/openregister-push-sw.js and registered by
 * js/openregister-push-client.js; it is NOT bundled through webpack.
 *
 * Payload shape (JSON, produced by WebPushService):
 *   { title, body, icon, badge, tag, actions: [{action, title}], data: { url, actions: { <action>: <url> } } }
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

self.addEventListener('install', () => {
	// Activate immediately so the first subscription starts receiving pushes.
	self.skipWaiting()
})

self.addEventListener('activate', (event) => {
	event.waitUntil(self.clients.claim())
})

self.addEventListener('push', (event) => {
	let payload = {}
	try {
		payload = event.data ? event.data.json() : {}
	} catch (e) {
		// Non-JSON payloads degrade to a plain text body.
		payload = { title: 'OpenRegister', body: event.data ? event.data.text() : '' }
	}

	const title = payload.title || 'OpenRegister'
	const options = {
		body: payload.body || '',
		icon: payload.icon || undefined,
		badge: payload.badge || undefined,
		// At most two actions render on desktop (Web Notification API limit).
		actions: Array.isArray(payload.actions) ? payload.actions.slice(0, 2) : [],
		// A stable tag collapses duplicates of the same logical notification
		// (and lets the foreground client suppress the stock popup).
		tag: payload.tag || undefined,
		renotify: !!payload.tag,
		data: payload.data || {},
	}

	event.waitUntil(self.registration.showNotification(title, options))
})

self.addEventListener('notificationclick', (event) => {
	event.notification.close()

	const data = event.notification.data || {}
	// A clicked action button resolves to its own deeplink; a click on the
	// notification body falls back to the top-level url.
	let targetUrl = data.url || '/'
	if (event.action && data.actions && data.actions[event.action]) {
		targetUrl = data.actions[event.action]
	}

	event.waitUntil(
		self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
			// Focus an already-open tab on the same origin instead of opening a new one.
			for (const client of clientList) {
				try {
					const sameOrigin = new URL(client.url).origin === new URL(targetUrl, self.location.origin).origin
					if (sameOrigin && 'focus' in client) {
						client.navigate(targetUrl)
						return client.focus()
					}
				} catch (e) {
					// Ignore malformed client URLs and fall through to openWindow.
				}
			}
			if (self.clients.openWindow) {
				return self.clients.openWindow(targetUrl)
			}
			return undefined
		})
	)
})
