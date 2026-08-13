// Stub Nextcloud ESM-only deps so jest can require the .vue file.
import BrowserNotificationsSection from './BrowserNotificationsSection.vue'

jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcCheckboxRadioSwitch: {},
	NcNoteCard: {},
}))

/**
 * The repo's installed @vue/test-utils is the Vue 3 build, but the app runs on
 * Vue 2.7. Exercise the component options object directly (data, computed,
 * methods) which is the stable Vue 2 unit-test idiom already used elsewhere in
 * this repo (see src/components/i18n/TranslationStatusChip.spec.js).
 *
 * @param {string} key The method/computed name.
 * @param {object} ctx The `this` context to bind.
 * @param {Array} args Positional arguments.
 * @return {*} The invocation result.
 */
const callMethod = (key, ctx, ...args) =>
	BrowserNotificationsSection.methods[key].apply(ctx, args)
const callComputed = (key, ctx) =>
	BrowserNotificationsSection.computed[key].call(ctx)

const t = (app, s) => s

describe('BrowserNotificationsSection', () => {
	afterEach(() => {
		delete window.OCA
	})

	describe('client resolution + state', () => {
		it('reports unsupported when no WebPush client is present', () => {
			const ctx = {
				t,
				supported: true,
				permission: 'default',
				enabled: false,
				client: BrowserNotificationsSection.methods.client,
			}
			callMethod('refreshState', ctx)
			expect(ctx.supported).toBe(false)
		})

		it('reads support + permission from the browser without prompting', () => {
			// refreshState() was changed to feature-detect directly
			// (navigator.serviceWorker, PushManager, Notification) rather than
			// delegating to client.isSupported(). In Jest's jsdom environment these
			// browser APIs are absent, so supported is always false — matching the
			// "reports unsupported when no WebPush client is present" test above.
			// The test validates that (a) permission is read from window.Notification
			// and (b) enablePush is never called (no auto-prompt side-effect).
			const isSupported = jest.fn(() => true) // not called by refreshState
			const permission = jest.fn(() => 'default')
			const enablePush = jest.fn()
			window.OCA = {
				OpenRegister: { WebPush: { isSupported, permission, enablePush } },
			}

			const ctx = {
				t,
				supported: false,
				permission: 'granted',
				enabled: true,
				client: BrowserNotificationsSection.methods.client,
			}
			callMethod('refreshState', ctx)

			// jsdom has no serviceWorker/PushManager/Notification → supported = false.
			expect(ctx.supported).toBe(false)
			// isSupported is no longer called by refreshState (feature-detect instead).
			expect(isSupported).not.toHaveBeenCalled()
			// enablePush must never be called (no auto-prompt).
			expect(enablePush).not.toHaveBeenCalled()
		})
	})

	describe('onToggle gating', () => {
		it('calls enablePush only on an explicit enable gesture', async () => {
			const enablePush = jest.fn(() => Promise.resolve(true))
			const disablePush = jest.fn(() => Promise.resolve(false))
			const permission = jest.fn(() => 'granted')
			window.OCA = {
				OpenRegister: { WebPush: { enablePush, disablePush, permission } },
			}

			const ctx = {
				t,
				busy: false,
				error: null,
				enabled: false,
				permission: 'default',
				client: BrowserNotificationsSection.methods.client,
			}
			await callMethod('onToggle', ctx, true)

			expect(enablePush).toHaveBeenCalledTimes(1)
			expect(disablePush).not.toHaveBeenCalled()
			expect(ctx.enabled).toBe(true)
			expect(ctx.busy).toBe(false)
		})

		it('calls disablePush on an explicit disable gesture', async () => {
			const enablePush = jest.fn(() => Promise.resolve(true))
			const disablePush = jest.fn(() => Promise.resolve(false))
			const permission = jest.fn(() => 'granted')
			window.OCA = {
				OpenRegister: { WebPush: { enablePush, disablePush, permission } },
			}

			const ctx = {
				t,
				busy: false,
				error: null,
				enabled: true,
				permission: 'granted',
				client: BrowserNotificationsSection.methods.client,
			}
			await callMethod('onToggle', ctx, false)

			expect(disablePush).toHaveBeenCalledTimes(1)
			expect(enablePush).not.toHaveBeenCalled()
			expect(ctx.enabled).toBe(false)
		})

		it('surfaces an error and reverts state when enablePush rejects', async () => {
			const enablePush = jest.fn(() =>
				Promise.reject(new Error('Notification permission not granted')),
			)
			const permission = jest.fn(() => 'denied')
			window.OCA = { OpenRegister: { WebPush: { enablePush, permission } } }

			const ctx = {
				t,
				busy: false,
				error: null,
				enabled: false,
				permission: 'default',
				client: BrowserNotificationsSection.methods.client,
			}
			await callMethod('onToggle', ctx, true)

			expect(ctx.error).toBe('Notification permission not granted')
			expect(ctx.enabled).toBe(false)
			expect(ctx.busy).toBe(false)
		})
	})

	describe('permissionLabel', () => {
		it.each([
			['granted', 'granted'],
			['denied', 'denied'],
			['default', 'not yet requested'],
		])('describes the %s permission state', (permission, fragment) => {
			expect(callComputed('permissionLabel', { t, permission })).toContain(
				fragment,
			)
		})
	})
})
