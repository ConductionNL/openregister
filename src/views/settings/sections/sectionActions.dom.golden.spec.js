import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
/**
 * Golden record of the action buttons of the settings sections: their markup
 * in each state and what they call on the settings store.
 *
 * @spec exclude Golden record of client-side settings markup and actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import MultitenancyConfiguration from './MultitenancyConfiguration.vue'
import RbacConfiguration from './RbacConfiguration.vue'
import RetentionConfiguration from './RetentionConfiguration.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue-material-design-icons/ContentSave.vue', () => mockModule('ContentSave'))
jest.mock('vue-material-design-icons/Refresh.vue', () => mockModule('Refresh'))
jest.mock('../../../components/shared/SettingsSection.vue', () => mockModule('SettingsSection'))
jest.mock('../../../store/settings.js', () => {
	const useSettingsStore = () => globalThis.settingsStore
	useSettingsStore.$id = 'settings'
	return { useSettingsStore }
})

/**
 * Library stand-ins: every named export is a stub of that name.
 *
 * @return {object} The module
 */
function mockLibrary() {
	const made = {}
	return new Proxy(
		{ __esModule: true },
		{
			get(target, key) {
				if (key in target || typeof key !== 'string') return target[key]
				made[key] = made[key] || mockStub(key)
				return made[key]
			},
			has: () => true,
		},
	)
}

/**
 * A stand-in component module.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockModule(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, plain attributes and slots.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = require('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs)
					.filter(([key]) => !/^on[A-Z]/.test(key))
					.map(([key, value]) => [key, typeof value === 'object' ? JSON.stringify(value) : value]),
			)
			return render(
				`x-${name}`,
				attrs,
				Object.keys(this.$slots)
					.sort()
					.map((slot) => render(`slot-${slot}`, this.$slots[slot]({}))),
			)
		},
	}
}

const SECTIONS = {
	rbac: [RbacConfiguration, 'updateRbacSettings', 'rbacOptions'],
	multitenancy: [MultitenancyConfiguration, 'updateMultitenancySettings', 'multitenancyOptions'],
	retention: [RetentionConfiguration, 'updateRetentionSettings', 'retentionOptions'],
}

/**
 * Mounts a section on a store that logs its calls.
 *
 * @param {object} component The section
 * @param {object} state The store state
 * @return {object} The wrapper and the log
 */
function section(component, state) {
	const log = []
	const fields = { rbacOptions: { a: 1 }, multitenancyOptions: { b: 2 }, retentionOptions: { c: 3 } }
	globalThis.settingsStore = new Proxy(reactive({ ...fields, ...state }), {
		get(target, prop) {
			if (prop in target || typeof prop !== 'string') return target[prop]
			if (/Options$|Stats$/.test(prop)) return []
			return (...args) => log.push([prop, ...args])
		},
	})
	const t = jest.requireMock('@nextcloud/l10n').translate
	const bindings = { ...component.components, t }
	const wrapper = mount({ ...component, setup: () => bindings }, {
		global: { mocks: { t: (app, text) => `t:${text}` }, config: { warnHandler: () => {} } },
	})
	return { log, wrapper }
}

/**
 * The markup of the action slot, proven not empty.
 *
 * @param {object} wrapper The mounted section
 * @return {string} The markup
 */
function actions(wrapper) {
	const html = wrapper
		.find('slot-actions')
		.html()
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/\s+<\/slot-default>/g, '</slot-default>')
		.split('\n')
		.filter((line) => line.trim() !== '')
		.join('\n')
	expect(html).toContain('x-ncbutton')
	return html
}

describe.each(Object.keys(SECTIONS))('settings section %s', (name) => {
	const [component] = SECTIONS[name]

	it.each([
		['idle', {}],
		['loading', { loading: true }],
		['saving', { saving: true }],
		['rebasing', { rebasing: true }],
	])('shows its actions while %s', (state, flags) => {
		const base = { loading: false, saving: false, rebasing: false }
		const { wrapper } = section(component, { ...base, ...flags })
		expect(actions(wrapper)).toMatchSnapshot()
	})

	it('rebases and saves through the store', async () => {
		const base = { loading: false, saving: false, rebasing: false }
		const { log, wrapper } = section(component, base)
		const buttons = wrapper.find('slot-actions').findAllComponents({ name: 'NcButton' })
		for (const button of buttons) {
			button.vm.$emit('click')
			await flushPromises()
		}
		expect(log.length).toBeGreaterThan(1)
		expect(log).toMatchSnapshot()
	})
})
