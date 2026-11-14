/**
 * Settings: loads every settings section's data on creation and wires the app-store cache button.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import Settings from './Settings.vue'
import { useSettingsStore } from '../../store/settings.js'

jest.mock('../../store/settings.js', () => {
	const { defineStore } = require('pinia')
	return {
		useSettingsStore: defineStore('settings', {
			state: () => ({ versionInfo: {}, clearingAppStoreCache: false }),
			actions: {
				loadSettings() {},
				loadStats() {},
				loadCacheStats() {},
				clearAppStoreCache() {},
			},
		}),
	}
})
jest.mock('vue-material-design-icons/Refresh.vue', () => ({ __esModule: true, default: { name: 'Refresh', render: () => null } }))
jest.mock('../../dialogs/Dialogs.vue', () => ({ __esModule: true, default: { name: 'Dialogs', props: ['pushStatus'], render: () => null } }))
jest.mock('./sections/ApiTokenConfiguration.vue', () => ({ __esModule: true, default: { name: 'ApiTokenConfiguration', props: ['pushStatus'], render: () => null } }))
jest.mock('./sections/CacheManagement.vue', () => ({ __esModule: true, default: { name: 'CacheManagement', props: ['pushStatus'], render: () => null } }))
jest.mock('./sections/EdepotHostKeyConfiguration.vue', () => ({ __esModule: true, default: { name: 'EdepotHostKeyConfiguration', props: ['pushStatus'], render: () => null } }))
jest.mock('./sections/FileConfiguration.vue', () => ({ __esModule: true, default: { name: 'FileConfiguration', props: ['pushStatus'], render: () => null } }))
jest.mock('./sections/FlowConfiguration.vue', () => ({ __esModule: true, default: { name: 'FlowConfiguration', props: ['pushStatus'], render: () => null } }))
jest.mock('./sections/LlmConfiguration.vue', () => ({ __esModule: true, default: { name: 'LlmConfiguration', props: ['pushStatus'], render: () => null } }))
jest.mock('./sections/LogIntegrity.vue', () => ({ __esModule: true, default: { name: 'LogIntegrity', props: ['pushStatus'], render: () => null } }))
jest.mock('./sections/MultitenancyConfiguration.vue', () => ({ __esModule: true, default: { name: 'MultitenancyConfiguration', props: ['pushStatus'], render: () => null } }))
jest.mock('./sections/OrganisationConfiguration.vue', () => ({ __esModule: true, default: { name: 'OrganisationConfiguration', props: ['pushStatus'], render: () => null } }))
jest.mock('./sections/PermissionMatrix.vue', () => ({ __esModule: true, default: { name: 'PermissionMatrix', props: ['pushStatus'], render: () => null } }))
jest.mock('./sections/PushNotificationsConfiguration.vue', () => ({ __esModule: true, default: { name: 'PushNotificationsConfiguration', props: ['pushStatus'], render: () => null } }))
jest.mock('./sections/RbacConfiguration.vue', () => ({ __esModule: true, default: { name: 'RbacConfiguration', props: ['pushStatus'], render: () => null } }))
jest.mock('./sections/ReadEventVisibilityConfiguration.vue', () => ({ __esModule: true, default: { name: 'ReadEventVisibilityConfiguration', props: ['pushStatus'], render: () => null } }))
jest.mock('./sections/RegisterDescriptors.vue', () => ({ __esModule: true, default: { name: 'RegisterDescriptors', props: ['pushStatus'], render: () => null } }))
jest.mock('./sections/RetentionConfiguration.vue', () => ({ __esModule: true, default: { name: 'RetentionConfiguration', props: ['pushStatus'], render: () => null } }))
jest.mock('./sections/StatisticsOverview.vue', () => ({ __esModule: true, default: { name: 'StatisticsOverview', props: ['pushStatus'], render: () => null } }))
jest.mock('./sections/WorkingCalendars.vue', () => ({ __esModule: true, default: { name: 'WorkingCalendars', props: ['pushStatus'], render: () => null } }))
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

describe('Settings', () => {
	let store
	let pinia
	let errSpy
	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		store = useSettingsStore()
		errSpy = jest.spyOn(console, 'error').mockImplementation(() => {})
	})
	afterEach(() => errSpy.mockRestore())

	/**
	 * Mount the page.
	 *
	 * @param {object} props The props.
	 * @return {Promise<object>} The wrapper.
	 */
	async function mountIt(props = {}) {
		const wrapper = mount(Settings, { props, global: { plugins: [pinia] } })
		await flushPromises()
		return wrapper
	}

	it('loads the settings, then the stats and cache stats', async () => {
		const order = []
		jest.spyOn(store, 'loadSettings').mockImplementation(async () => order.push('settings'))
		jest.spyOn(store, 'loadStats').mockImplementation(async () => order.push('stats'))
		jest.spyOn(store, 'loadCacheStats').mockImplementation(async () => order.push('cache'))
		await mountIt()
		expect(order).toEqual(['settings', 'stats', 'cache'])
	})

	it('logs a failed load and still renders', async () => {
		jest.spyOn(store, 'loadSettings').mockRejectedValue(new Error('down'))
		const loadStats = jest.spyOn(store, 'loadStats')
		const wrapper = await mountIt()
		expect(errSpy).toHaveBeenCalledWith('❌ Failed to load settings data:', expect.any(Error))
		expect(loadStats).not.toHaveBeenCalled()
		expect(wrapper.find('.stub-CnAdminSettingsShell').exists()).toBe(true)
	})

	it('passes the version and the push status down, falling back to Unknown', async () => {
		let wrapper = await mountIt({ pushStatus: 'installed' })
		expect(wrapper.findComponent({ name: 'CnAdminSettingsShell' }).vm.$attrs.appVersion).toBe('Unknown')
		expect(wrapper.findComponent({ name: 'PushNotificationsConfiguration' }).props('pushStatus')).toBe('installed')
		store.versionInfo = { appVersion: '2.1.0' }
		wrapper = await mountIt()
		expect(wrapper.findComponent({ name: 'CnAdminSettingsShell' }).vm.$attrs.appVersion).toBe('2.1.0')
		expect(wrapper.findComponent({ name: 'PushNotificationsConfiguration' }).props('pushStatus')).toBe('not_installed')
	})

	it('clears the whole app-store cache from the button and shows progress', async () => {
		const clear = jest.spyOn(store, 'clearAppStoreCache').mockImplementation(() => {})
		const wrapper = await mountIt()
		const button = wrapper.find('.stub-NcButton')
		expect(button.text()).toBe('Clear App Store Cache')
		await button.trigger('click')
		expect(clear).toHaveBeenCalledWith('all')
		store.clearingAppStoreCache = true
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.stub-NcButton').text()).toBe('Clearing...')
		expect(wrapper.find('.stub-NcLoadingIcon').exists()).toBe(true)
	})
})
