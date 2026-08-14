/**
 * Unit tests for the static Web Push Service Worker (js/openregister-push-sw.js).
 *
 * The SW is a plain `self`-scoped static asset (not a module / not bundled), so
 * we load its source and evaluate it against a fake `self` that captures the
 * registered event listeners, then drive the `push` and `notificationclick`
 * handlers directly.
 *
 * @spec openspec/changes/openregister-web-push-engine/tasks.md#task-6.2
 */
const fs = require('fs')
const path = require('path')
const vm = require('vm')

const SW_SOURCE = fs.readFileSync(
	path.resolve(__dirname, '../../js/openregister-push-sw.js'),
	'utf8',
)

/**
 * Evaluate the SW source against a fresh fake `self` and return the captured
 * event listeners plus the fake self for assertions.
 *
 * @param {object} overrides Extra properties merged onto the fake `self`.
 * @return {{ self: object, listeners: object }} The fake self + handler map.
 */
function loadServiceWorker(overrides = {}) {
	const listeners = {}
	const fakeSelf = Object.assign(
		{
			addEventListener: (type, handler) => {
				listeners[type] = handler
			},
			skipWaiting: jest.fn(),
			location: { origin: 'https://example-host.test' },
			registration: { showNotification: jest.fn(() => Promise.resolve()) },
			clients: {
				claim: jest.fn(() => Promise.resolve()),
				matchAll: jest.fn(() => Promise.resolve([])),
				openWindow: jest.fn(() => Promise.resolve()),
			},
		},
		overrides,
	)

	const context = vm.createContext({ self: fakeSelf, URL })
	vm.runInContext(SW_SOURCE, context)
	return { self: fakeSelf, listeners }
}

/**
 * Build a fake push event whose data.json() returns the given payload.
 *
 * @param {object} payload The JSON push payload.
 * @return {object} A fake ExtendableEvent.
 */
function pushEvent(payload) {
	return {
		data: { json: () => payload, text: () => JSON.stringify(payload) },
		waitUntil: (p) => p,
	}
}

describe('openregister-push-sw', () => {
	it('registers install/activate/push/notificationclick listeners', () => {
		const { listeners } = loadServiceWorker()
		expect(typeof listeners.install).toBe('function')
		expect(typeof listeners.activate).toBe('function')
		expect(typeof listeners.push).toBe('function')
		expect(typeof listeners.notificationclick).toBe('function')
	})

	describe('push handler', () => {
		it('renders a notification from the JSON payload', () => {
			const { self, listeners } = loadServiceWorker()
			listeners.push(
				pushEvent({
					title: 'New lead',
					body: 'Acme Corp',
					icon: '/icon.png',
					badge: '/badge.png',
					tag: 'lead-1',
					actions: [{ action: 'a0', title: 'Open client' }],
					data: { url: '/apps/pipelinq/#/clients/1' },
				}),
			)

			expect(self.registration.showNotification).toHaveBeenCalledTimes(1)
			const [title, options] = self.registration.showNotification.mock.calls[0]
			expect(title).toBe('New lead')
			expect(options.body).toBe('Acme Corp')
			expect(options.tag).toBe('lead-1')
			expect(options.renotify).toBe(true)
			expect(options.actions).toHaveLength(1)
		})

		it('caps actions at two (Web Notification API desktop limit)', () => {
			const { self, listeners } = loadServiceWorker()
			listeners.push(
				pushEvent({
					title: 'x',
					actions: [
						{ action: 'a0', title: '0' },
						{ action: 'a1', title: '1' },
						{ action: 'a2', title: '2' },
					],
				}),
			)
			const [, options] = self.registration.showNotification.mock.calls[0]
			expect(options.actions).toHaveLength(2)
		})

		it('falls back to a default title for an empty payload', () => {
			const { self, listeners } = loadServiceWorker()
			listeners.push({ data: null, waitUntil: (p) => p })
			const [title] = self.registration.showNotification.mock.calls[0]
			expect(title).toBe('OpenRegister')
		})
	})

	describe('notificationclick handler', () => {
		it('closes the notification and opens the top-level url when no tab is open', async () => {
			const openWindow = jest.fn(() => Promise.resolve())
			const { listeners } = loadServiceWorker({
				clients: {
					matchAll: jest.fn(() => Promise.resolve([])),
					openWindow,
				},
			})
			const close = jest.fn()
			await listeners.notificationclick({
				notification: {
					close,
					data: {
						url: 'https://example-host.test/apps/pipelinq/#/clients/1',
					},
				},
				action: '',
				waitUntil: (p) => p,
			})

			expect(close).toHaveBeenCalled()
			expect(openWindow).toHaveBeenCalledWith(
				'https://example-host.test/apps/pipelinq/#/clients/1',
			)
		})

		it('routes a clicked action button to its own deeplink', async () => {
			const openWindow = jest.fn(() => Promise.resolve())
			const { listeners } = loadServiceWorker({
				clients: {
					matchAll: jest.fn(() => Promise.resolve([])),
					openWindow,
				},
			})
			await listeners.notificationclick({
				notification: {
					close: jest.fn(),
					data: {
						url: 'https://example-host.test/top',
						actions: {
							a0: 'https://example-host.test/apps/pipelinq/#/clients/9',
						},
					},
				},
				action: 'a0',
				waitUntil: (p) => p,
			})

			expect(openWindow).toHaveBeenCalledWith(
				'https://example-host.test/apps/pipelinq/#/clients/9',
			)
		})

		it('focuses and navigates an already-open same-origin tab', async () => {
			const focus = jest.fn(() => 'focused')
			const navigate = jest.fn()
			const client = {
				url: 'https://example-host.test/apps/files',
				focus,
				navigate,
			}
			const openWindow = jest.fn()
			const { listeners } = loadServiceWorker({
				clients: {
					matchAll: jest.fn(() => Promise.resolve([client])),
					openWindow,
				},
			})
			await listeners.notificationclick({
				notification: {
					close: jest.fn(),
					data: {
						url: 'https://example-host.test/apps/pipelinq/#/clients/1',
					},
				},
				action: '',
				waitUntil: (p) => p,
			})

			expect(navigate).toHaveBeenCalledWith(
				'https://example-host.test/apps/pipelinq/#/clients/1',
			)
			expect(focus).toHaveBeenCalled()
			expect(openWindow).not.toHaveBeenCalled()
		})
	})
})
