/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The connected accounts section mounted: the list it shows, connecting,
 * reconnecting and disconnecting, and the failure messages.
 *
 * @spec exclude Unit test of the client-side connected accounts section.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import OAuth2ConnectionsSection from './OAuth2ConnectionsSection.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php' + path.replace(/{(\w+)}/g, (m, key) => params[key]),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (m, key) => (key in vars ? vars[key] : m)),
}))
jest.mock('@nextcloud/vue', () => ({
	NcAppSettingsSection: { name: 'NcAppSettingsSection', template: '<section><slot /></section>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<i class="loading" />' },
	NcNoteCard: { name: 'NcNoteCard', template: '<div><slot /></div>' },
	NcButton: {
		name: 'NcButton',
		props: ['disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		render: () => null,
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<input class="host" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
}))

const CREDENTIALS = '/index.php/apps/openregister/api/credentials'
const PROVIDERS = '/index.php/apps/openregister/api/credentials/providers'
const START = '/index.php/apps/openregister/api/credentials/oauth2/start'
const MASTODON = { identifier: 'mastodon', title: 'Mastodon', kind: 'oauth2-token-set', requiresInstanceBaseUrl: true }

let connections

/**
 * Serve the two lists the section loads.
 *
 * @return {void}
 */
function serve() {
	axios.get.mockImplementation((url) =>
		Promise.resolve({
			data: {
				results: url === PROVIDERS
					? [MASTODON, { identifier: 'key', kind: 'api-key' }]
					: connections,
			},
		}),
	)
}

/**
 * Mount the section and let it load.
 *
 * @return {Promise<object>} The wrapper
 */
async function mountSection() {
	const wrapper = mount(OAuth2ConnectionsSection)
	await flushPromises()
	// Never really leave the page.
	wrapper.vm.navigateTo = jest.fn()
	return wrapper
}

const byTestId = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`)

beforeEach(() => {
	jest.clearAllMocks()
	connections = [
		{
			id: 4,
			kind: 'oauth2-token-set',
			name: 'Social',
			provider: 'mastodon',
			instanceBaseUrl: 'https://m.example',
			status: 'relink_needed',
			account: { handle: '@me' },
			scopes: ['read', 'write'],
		},
		{ id: 5, kind: 'oauth2-token-set', name: 'Old', provider: 'x', status: 'disabled' },
		{ id: 6, kind: 'api-key', name: 'Key' },
	]
	serve()
})

describe('OAuth2ConnectionsSection mounted', () => {
	it('lists only OAuth2 connections, with handle, scopes and status', async () => {
		const wrapper = await mountSection()
		expect(axios.get).toHaveBeenCalledWith(CREDENTIALS)
		expect(axios.get).toHaveBeenCalledWith(PROVIDERS)
		expect(wrapper.findAll('.oauth2-connections__item')).toHaveLength(2)
		const first = byTestId(wrapper, 'oauth2-connection-4')
		expect(first.find('.oauth2-connections__handle').text()).toBe('@me')
		expect(first.find('.oauth2-connections__scopes').text()).toBe('read, write')
		expect(byTestId(wrapper, 'oauth2-status-4').text()).toBe('Relink needed')
		expect(byTestId(wrapper, 'oauth2-connection-5').find('.oauth2-connections__handle').text()).toBe(
			'Not connected yet',
		)
		expect(byTestId(wrapper, 'oauth2-reconnect-5').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual([MASTODON])
	})

	it('says no account is connected yet', async () => {
		connections = []
		const wrapper = await mountSection()
		expect(byTestId(wrapper, 'oauth2-connections-empty').exists()).toBe(true)
	})

	it('connects a provider that needs a server address', async () => {
		axios.post.mockResolvedValue({ data: { authorizationUrl: 'https://m.example/auth' } })
		const wrapper = await mountSection()
		expect(byTestId(wrapper, 'oauth2-connect-button').attributes('disabled')).toBeDefined()
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', MASTODON)
		await flushPromises()
		await wrapper.find('input.host').setValue('https://m.example')
		await byTestId(wrapper, 'oauth2-connect-button').trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith(START, {
			provider: 'mastodon',
			instanceBaseUrl: 'https://m.example',
			returnUrl: window.location.pathname,
		})
		expect(wrapper.vm.navigateTo).toHaveBeenCalledWith('https://m.example/auth')
	})

	it('reconnects an existing connection by its id', async () => {
		axios.post.mockResolvedValue({ data: { authorizationUrl: 'u' } })
		const wrapper = await mountSection()
		await byTestId(wrapper, 'oauth2-reconnect-4').trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith(START, {
			provider: 'mastodon',
			instanceBaseUrl: 'https://m.example',
			credentialId: 4,
			returnUrl: window.location.pathname,
		})
	})

	it.each([
		[409, 'not set up on this server'],
		[502, 'did not accept the connection'],
		[500, 'Could not start the connection'],
	])('explains a %i from the start request', async (status, text) => {
		axios.post.mockRejectedValue({ response: { status } })
		const wrapper = await mountSection()
		await byTestId(wrapper, 'oauth2-reconnect-4').trigger('click')
		await flushPromises()
		expect(byTestId(wrapper, 'oauth2-connections-error').text()).toContain(text)
		expect(wrapper.vm.navigateTo).not.toHaveBeenCalled()
		expect(byTestId(wrapper, 'oauth2-reconnect-4').attributes('disabled')).toBeUndefined()
	})

	it('disconnects and reloads, or says it could not', async () => {
		axios.delete.mockResolvedValueOnce({})
		const wrapper = await mountSection()
		await byTestId(wrapper, 'oauth2-disconnect-4').trigger('click')
		await flushPromises()
		expect(axios.delete).toHaveBeenCalledWith('/index.php/apps/openregister/api/credentials/oauth2/4')
		expect(axios.get).toHaveBeenCalledTimes(4)
		axios.delete.mockRejectedValueOnce(new Error('no'))
		await byTestId(wrapper, 'oauth2-disconnect-4').trigger('click')
		await flushPromises()
		expect(byTestId(wrapper, 'oauth2-connections-error').text()).toBe(
			'Could not disconnect this account.',
		)
	})

	it('says the accounts could not be loaded', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const wrapper = await mountSection()
		expect(byTestId(wrapper, 'oauth2-connections-error').text()).toBe(
			'Could not load your connected accounts.',
		)
		expect(wrapper.find('.loading').exists()).toBe(false)
	})
})
