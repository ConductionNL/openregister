/**
 * FlowConfiguration: loads the flow defaults into a local form and saves them as numbers.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import FlowConfiguration from './FlowConfiguration.vue'
import { useSettingsStore } from '../../../store/settings.js'

jest.mock('../../../store/settings.js', () => {
	const { reactive } = require('vue')
	const store = reactive({ saving: false, flowOptions: {}, loadFlowSettings: jest.fn(), updateFlowSettings: jest.fn() })
	return { useSettingsStore: () => store }
})
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () => mockLibrary())

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
 * Mount the section.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountIt() {
	const wrapper = mount(FlowConfiguration, { global: { mocks: { t: (app, text) => text } } })
	await flushPromises()
	return wrapper
}

describe('FlowConfiguration', () => {
	let store
	beforeEach(() => {
		jest.clearAllMocks()
		store = useSettingsStore()
		store.loadFlowSettings.mockReset()
		store.saving = false
		store.flowOptions = {}
	})

	it('loads the flow settings and merges them over the defaults', async () => {
		store.loadFlowSettings.mockImplementation(async () => { store.flowOptions = { retentionDays: 7, killSwitch: true } })
		const wrapper = await mountIt()
		expect(store.loadFlowSettings).toHaveBeenCalled()
		expect(wrapper.vm.form).toEqual({ retentionDays: 7, auditEnabled: false, killSwitch: true })
		expect(wrapper.find('.stub-NcNoteCard').exists()).toBe(true)
	})

	it('hides the kill-switch warning while it is off', async () => {
		const wrapper = await mountIt()
		expect(wrapper.find('.stub-NcNoteCard').exists()).toBe(false)
	})

	it('saves the form with the retention as a number', async () => {
		const wrapper = await mountIt()
		wrapper.vm.form.retentionDays = '14'
		wrapper.vm.form.auditEnabled = true
		await wrapper.find('.stub-NcButton').trigger('click')
		expect(store.updateFlowSettings).toHaveBeenCalledWith({ retentionDays: 14, auditEnabled: true, killSwitch: false })
	})

	it('disables the button and shows Saving while saving', async () => {
		store.saving = true
		const wrapper = await mountIt()
		const button = wrapper.find('.stub-NcButton')
		expect(button.attributes('disabled')).toBeDefined()
		expect(button.text()).toBe('Saving…')
	})
})
