/**
 * MultitenancyConfiguration: the status texts for each state, the tenant selects, and saving.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import MultitenancyConfiguration from './MultitenancyConfiguration.vue'
import { useSettingsStore } from '../../../store/settings.js'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('../../../store/settings.js', () => {
	const { defineStore } = require('pinia')
	return {
		useSettingsStore: defineStore('settings', {
			state: () => ({ loading: false, saving: false, rebasing: false, multitenancyOptions: {}, tenantOptions: [] }),
			actions: { updateMultitenancySettings: jest.fn(), showRebaseDialog: jest.fn() },
		}),
	}
})
jest.mock('../../../components/shared/SettingsSection.vue', () => {
	const { h } = require('vue')
	return { __esModule: true, default: { name: 'SettingsSection', render() { return h('section', Object.values(this.$slots).map((s) => s())) } } }
})
jest.mock('./SectionSaveActions.vue', () => {
	const { h } = require('vue')
	return { __esModule: true, default: { name: 'SectionSaveActions', props: { onSave: Function, onRebase: Function, saving: Boolean }, render: () => h('div', { class: 'save-actions' }) } }
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
	const { h } = jest.requireActual('vue')
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
// The page mixes <script setup> and an options block; its compiled template
// reads the library components through 'vue'.
jest.mock('vue', () => ({ ...jest.requireActual('vue'), NcCheckboxRadioSwitch: mockStub('NcCheckboxRadioSwitch'), NcSelect: mockStub('NcSelect') }))

describe('MultitenancyConfiguration', () => {
	let store
	let pinia
	/**
	 * Mount the section on the test pinia.
	 *
	 * @return {object} The wrapper.
	 */
	const mountIt = () => mount(MultitenancyConfiguration, { global: { plugins: [pinia] } })
	beforeEach(() => {
		const { createPinia, setActivePinia } = require('pinia')
		pinia = createPinia()
		setActivePinia(pinia)
		store = useSettingsStore()
		jest.spyOn(store, 'updateMultitenancySettings').mockResolvedValue(undefined)
		jest.spyOn(store, 'showRebaseDialog').mockImplementation(() => {})
	})

	it('describes enabling while disabled and hides the tenant options', () => {
		store.multitenancyOptions = { enabled: false }
		const wrapper = mountIt()
		expect(wrapper.find('.toggle-status span').classes()).toContain('status-disabled')
		expect(wrapper.find('.impact-description').text()).toContain('Enabling Multitenancy will:')
		expect(wrapper.find('.impact-description').text()).toContain('Provide complete data isolation')
		expect(wrapper.find('.groups-table').exists()).toBe(false)
		expect(wrapper.findAll('.stub-NcCheckboxRadioSwitch')).toHaveLength(1)
	})

	it('describes disabling while enabled and shows override and tenant selects', () => {
		store.multitenancyOptions = { enabled: true, adminOverride: true, defaultUserTenant: 'a' }
		store.tenantOptions = ['a', 'b']
		const wrapper = mountIt()
		expect(wrapper.find('.toggle-status span').classes()).toContain('status-enabled')
		expect(wrapper.find('.impact-description').text()).toContain('Disabling Multitenancy will:')
		expect(wrapper.text()).toContain('Admin override enabled')
		const selects = wrapper.findAll('.stub-NcSelect')
		expect(selects).toHaveLength(2)
		expect(wrapper.findAllComponents({ name: 'NcSelect' })[0].vm.$attrs.options).toEqual(['a', 'b'])
		expect(wrapper.findAllComponents({ name: 'NcSelect' })[0].vm.$attrs.modelValue).toBe('a')
	})

	it('the toggle writes enabled into the store options', async () => {
		store.multitenancyOptions = { enabled: false }
		const wrapper = mountIt()
		wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)
		await wrapper.vm.$nextTick()
		expect(store.multitenancyOptions.enabled).toBe(true)
		expect(wrapper.find('.groups-table').exists()).toBe(true)
	})

	it('shows the admin override as disabled when it is off', () => {
		store.multitenancyOptions = { enabled: true, adminOverride: false }
		expect(mountIt().text()).toContain('Admin override disabled')
	})

	it('saves the current options through the save actions and replaces them through the setter', async () => {
		store.multitenancyOptions = { enabled: true }
		const wrapper = mountIt()
		await wrapper.findComponent({ name: 'SectionSaveActions' }).props('onSave')()
		expect(store.updateMultitenancySettings).toHaveBeenCalledWith({ enabled: true })
		wrapper.vm.$options.computed.multitenancyOptions.set.call(wrapper.vm, { enabled: false })
		expect(store.multitenancyOptions).toEqual({ enabled: false })
		wrapper.findComponent({ name: 'SectionSaveActions' }).props('onRebase')()
		expect(store.showRebaseDialog).toHaveBeenCalled()
	})
})
