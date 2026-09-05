// Stub the Nextcloud ESM-only deps so jest can require the .vue file.
import OAuth2ConnectionsSection from './OAuth2ConnectionsSection.vue'

jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcButton: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcSelect: {},
	NcSettingsSection: {},
	NcTextField: {},
}))

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), delete: jest.fn() },
}))

jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: (url, params = {}) =>
		Object.entries(params).reduce(
			(acc, [key, value]) => acc.replace(`{${key}}`, value),
			url,
		),
}))

jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (app, text, vars = {}) =>
		Object.entries(vars).reduce(
			(acc, [key, value]) => acc.replace(`{${key}}`, value),
			text,
		),
}))

import axios from '@nextcloud/axios'

/**
 * The repo's installed @vue/test-utils is the Vue 3 build while the app runs on
 * Vue 2.7, so the stable idiom here is to exercise the options object directly.
 * That is also the right level for this component: what matters is which requests
 * it makes and what it decides to show, not how it renders.
 *
 * @param {string} key The method name.
 * @param {object} ctx The `this` context to bind.
 * @param {Array} args Positional arguments.
 * @return {*} The invocation result.
 */
function callMethod(key, ctx, ...args) {
	return OAuth2ConnectionsSection.methods[key].apply(ctx, args)
}

/**
 * A translator that behaves like `@nextcloud/l10n`'s, including placeholders.
 *
 * @param {string} app The app id.
 * @param {string} text The source string.
 * @param {object} vars The placeholder values.
 * @return {string} The interpolated string.
 */
function t(app, text, vars = {}) {
	return Object.entries(vars).reduce(
		(acc, [key, value]) => acc.replace(`{${key}}`, value),
		text,
	)
}

describe('OAuth2ConnectionsSection', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	describe('loading', () => {
		it('keeps only OAuth2 connections and OAuth2 providers', async () => {
			axios.get
				.mockResolvedValueOnce({
					data: {
						results: [
							{ id: 'a', kind: 'oauth2-token-set', status: 'active' },
							{ id: 'b', kind: 'secret' },
						],
					},
				})
				.mockResolvedValueOnce({
					data: {
						results: [
							{ identifier: 'mastodon', kind: 'oauth2-token-set' },
							{ identifier: 'github', kind: 'secret' },
						],
					},
				})

			const ctx = {
				t,
				loading: true,
				error: '',
				connections: [],
				providers: [],
			}
			await callMethod('load', ctx)

			expect(ctx.connections.map((entry) => entry.id)).toEqual(['a'])
			expect(ctx.providers.map((entry) => entry.identifier)).toEqual([
				'mastodon',
			])
			expect(ctx.loading).toBe(false)
		})

		it('reports a load failure without leaving the panel spinning', async () => {
			axios.get.mockRejectedValue(new Error('network'))

			const ctx = {
				t,
				loading: true,
				error: '',
				connections: [],
				providers: [],
			}
			await callMethod('load', ctx)

			expect(ctx.error).toBe('Could not load your connected accounts.')
			expect(ctx.loading).toBe(false)
		})
	})

	describe('starting a flow', () => {
		it('sends the browser to the authorization URL the server returned', async () => {
			axios.post.mockResolvedValue({
				data: {
					authorizationUrl: 'https://mastodon.example/oauth/authorize?x=1',
				},
			})
			const navigateTo = jest.fn()
			const ctx = { t, busy: false, error: '', navigateTo }

			await callMethod('startFlow', ctx, { provider: 'mastodon' })

			expect(axios.post).toHaveBeenCalledWith(
				'/apps/openregister/api/credentials/oauth2/start',
				{ provider: 'mastodon', returnUrl: window.location.pathname },
			)
			expect(navigateTo).toHaveBeenCalledWith(
				'https://mastodon.example/oauth/authorize?x=1',
			)
		})

		it('reconnects onto the same credential id', async () => {
			const startFlow = jest.fn()
			const ctx = { t, startFlow }

			await callMethod('reconnect', ctx, {
				id: 'existing-uuid',
				provider: 'mastodon',
				instanceBaseUrl: 'https://mastodon.example',
			})

			expect(startFlow).toHaveBeenCalledWith({
				provider: 'mastodon',
				instanceBaseUrl: 'https://mastodon.example',
				credentialId: 'existing-uuid',
			})
		})

		it('reports a start failure and stops being busy', async () => {
			axios.post.mockRejectedValue(new Error('refused'))
			const ctx = { t, busy: false, error: '', navigateTo: jest.fn() }

			await callMethod('startFlow', ctx, { provider: 'mastodon' })

			expect(ctx.error).toContain('Could not start the connection')
			expect(ctx.busy).toBe(false)
		})
	})

	describe('disconnecting', () => {
		it('calls the disconnect endpoint and reloads', async () => {
			axios.delete.mockResolvedValue({})
			const load = jest.fn()
			const ctx = { t, busy: false, error: '', load }

			await callMethod('disconnect', ctx, { id: 'existing-uuid' })

			expect(axios.delete).toHaveBeenCalledWith(
				'/apps/openregister/api/credentials/oauth2/existing-uuid',
			)
			expect(load).toHaveBeenCalled()
			expect(ctx.busy).toBe(false)
		})
	})

	describe('what the panel shows', () => {
		it('labels every status in the stored vocabulary', () => {
			const ctx = { t }

			expect(callMethod('statusLabel', ctx, 'active')).toBe('Active')
			expect(callMethod('statusLabel', ctx, 'relink_needed')).toBe(
				'Relink needed',
			)
			expect(callMethod('statusLabel', ctx, 'disabled')).toBe('Disabled')
			expect(callMethod('statusLabel', ctx, 'pending')).toBe('Pending')
			expect(callMethod('statusLabel', ctx, 'expired')).toBe('Expired')
		})

		it('falls back to a placeholder while a connection has no handle yet', () => {
			const ctx = { t }

			expect(
				callMethod('handleOf', ctx, {
					account: { handle: '@example@mastodon.example' },
				}),
			).toBe('@example@mastodon.example')
			expect(callMethod('handleOf', ctx, {})).toBe('Not connected yet')
		})

		it('shows the granted scopes and never a token', () => {
			const ctx = { t }
			const connection = {
				scopes: ['read:accounts', 'write:statuses'],
				account: { handle: '@example@mastodon.example' },
				expiresAt: '2026-09-04T12:00:00+00:00',
			}

			const rendered = [
				callMethod('handleOf', ctx, connection),
				callMethod('scopesOf', ctx, connection),
				callMethod('expiryOf', ctx, connection),
			].join(' ')

			expect(rendered).toContain('read:accounts, write:statuses')
			expect(rendered).not.toContain('accessToken')
			expect(rendered).not.toContain('Bearer')
		})

		it('shows no expiry when a connection declares none', () => {
			expect(callMethod('expiryOf', { t }, {})).toBe('')
		})
	})
})
