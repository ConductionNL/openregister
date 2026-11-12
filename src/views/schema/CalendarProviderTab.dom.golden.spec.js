import { mount } from '@vue/test-utils'
/**
 * Golden record of the calendar provider tab: switched off, and switched on
 * with its fields and the save button, idle and saving.
 *
 * @spec exclude Golden record of client-side calendar provider markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import CalendarProviderTab from './CalendarProviderTab.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('../../store/store.js', () => ({ schemaStore: { saveSchema: jest.fn() } }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/Circle.vue', () => ({ __esModule: true, default: mockStub('CircleIcon') }))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({ __esModule: true, default: mockStub('ContentSave') }))

const SCHEMA = {
	id: 7,
	title: 'Meetings',
	properties: { start: { type: 'string', format: 'date-time' }, place: { type: 'string' } },
	configuration: { calendarProvider: { enabled: true, displayName: 'Meetings', dtstart: 'start', titleTemplate: '{title}' } },
}

describe('CalendarProviderTab', () => {
	it.each([
		['switched off', { id: 1, title: 'Plain', properties: {} }, false],
		['switched on', SCHEMA, false],
		['saving', SCHEMA, true],
	])('renders %s', async (label, schema, saving) => {
		const wrapper = mount(withStubs(CalendarProviderTab), { props: { schema } })
		wrapper.vm.saving = saving
		await wrapper.vm.$nextTick()
		expect(wrapper.html()).toMatchSnapshot()
	})
})

/**
 * A stand-in that renders its tag, attributes, listeners and slots.
 *
 * @param {string} name The component name
 * @return {object} The stand-in
 */
function mockStub(name) {
	const { h } = require('vue')
	return {
		name,
		inheritAttrs: false,
		setup(props, { attrs, slots }) {
			return () => {
				const plain = Object.fromEntries(Object.entries(attrs).filter(([key]) => !/^on[A-Z]/.test(key)))
				const listeners = Object.keys(attrs).filter((key) => /^on[A-Z]/.test(key))
				if (listeners.length) plain['data-on'] = listeners.join(' ')
				return h(`x-${name}`, plain, Object.entries(slots).map(([slot, fill]) => h(`slot-${slot}`, fill())))
			}
		},
	}
}

/**
 * The tab with its library components supplied: under jest the compiled
 * bindings of an SFC with a setup block resolve them to undefined.
 *
 * @param {object} component The tab
 * @return {object} The tab with stand-in components
 */
function withStubs(component) {
	const library = ['NcButton', 'NcCheckboxRadioSwitch', 'NcColorPicker', 'NcLoadingIcon', 'NcSelect', 'NcTextField']
	const stubs = Object.fromEntries(library.map((name) => [name, mockStub(name)]))
	const components = Object.fromEntries(
		Object.entries(component.components).map(([name, value]) => [name, stubs[name] || value]),
	)
	const save = components.SaveButton
	if (save) {
		save.components = { ...save.components, NcButton: stubs.NcButton, NcLoadingIcon: stubs.NcLoadingIcon }
	}
	return {
		...component,
		components,
		setup: () => ({ t: (app, text) => text, ...components }),
	}
}
