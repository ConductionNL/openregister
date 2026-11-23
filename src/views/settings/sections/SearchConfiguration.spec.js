import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
/**
 * SearchConfiguration: shows the accent folding state and switches it through PATCH.
 *
 * @spec openspec/changes/search-accent-insensitive/specs/zoeken-filteren/spec.md#requirement-accent-folding-is-visible-to-administrators-and-can-be-bypassed
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import SearchConfiguration from './SearchConfiguration.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), patch: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('../../../components/shared/SettingsSection.vue', () => mockStub('SettingsSection'))

/**
 * A stand-in component that renders its attributes and every slot.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
function mockStub(name) {
	const { h } = require('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const slots = Object.values(this.$slots).map((s) => s({}))
			return h('div', { ...this.$attrs, class: [`stub-${name}`, this.$attrs.class] }, slots)
		},
	}
}

/**
 * A module whose every named export is a stand-in component.
 *
 * @return {object} The module.
 */
function mockLibrary() {
	const made = {}
	return new Proxy({ __esModule: true }, {
		get(target, key) {
			if (key in target || typeof key !== 'string') return target[key]
			made[key] = made[key] || mockStub(key)
			return made[key]
		},
		has: () => true,
	})
}

/**
 * Mount the section after the GET answered $config.
 *
 * @param {object} config The search backend configuration.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(config) {
	axios.get.mockResolvedValue({ data: config })
	const wrapper = mount(SearchConfiguration, { global: { mocks: { t: (app, text) => text } } })
	await flushPromises()
	return wrapper
}

const ON = { active: 'database', accentInsensitive: { enabled: true, available: true, reason: '' } }
const UNAVAILABLE = { active: 'database', accentInsensitive: { enabled: false, available: false, reason: 'the unaccent extension could not be created' } }

describe('SearchConfiguration', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('reads the state from the search backend settings', async () => {
		const wrapper = await mountIt(ON)
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/settings/search-backend')
		const toggle = wrapper.find('.stub-NcCheckboxRadioSwitch')
		expect(toggle.attributes('modelvalue')).toBe('true')
		expect(toggle.attributes('disabled')).toBe('false')
		expect(wrapper.find('.stub-NcNoteCard').exists()).toBe(false)
	})

	it('disables the switch and names the reason where folding is unavailable', async () => {
		const wrapper = await mountIt(UNAVAILABLE)
		expect(wrapper.find('.stub-NcCheckboxRadioSwitch').attributes('disabled')).toBe('true')
		expect(wrapper.find('.stub-NcNoteCard').text()).toContain('the unaccent extension could not be created')
	})

	it('switches folding off with a PATCH and shows the new state', async () => {
		const wrapper = await mountIt(ON)
		axios.patch.mockResolvedValue({ data: { accentInsensitive: { enabled: false, available: true, reason: '' } } })
		await wrapper.vm.save(false)
		expect(axios.patch).toHaveBeenCalledWith('/apps/openregister/api/settings/search-backend', { accentInsensitive: false })
		expect(wrapper.vm.state).toEqual({ enabled: false, available: true, reason: '' })
		expect(showSuccess).toHaveBeenCalledWith('Configuration saved')
	})

	it('shows the server refusal and keeps the state', async () => {
		const wrapper = await mountIt(ON)
		axios.patch.mockRejectedValue({ response: { status: 400, data: { error: 'Accent-insensitive search is not available: x.' } } })
		await wrapper.vm.save(true)
		expect(showError).toHaveBeenCalledWith('Accent-insensitive search is not available: x.')
		expect(wrapper.vm.state.enabled).toBe(true)
		expect(wrapper.vm.saving).toBe(false)
	})
})
