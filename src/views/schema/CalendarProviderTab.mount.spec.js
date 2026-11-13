/**
 * The calendar provider tab mounted through its own setup: switching the
 * provider on, filling its fields through their v-model events, and saving
 * the result onto the schema.
 *
 * @spec exclude Client-side calendar provider form wiring.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import CalendarProviderTab from './CalendarProviderTab.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('../../store/store.js', () => ({ schemaStore: { saveSchema: jest.fn() } }))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcButton: mockField('NcButton'),
	NcCheckboxRadioSwitch: mockField('NcCheckboxRadioSwitch'),
	NcColorPicker: mockField('NcColorPicker'),
	NcSelect: mockField('NcSelect'),
	NcTextField: mockField('NcTextField'),
}))
jest.mock('vue-material-design-icons/Circle.vue', () => ({ __esModule: true, default: mockField('CircleIcon') }))
jest.mock('../../components/shared/SaveButton.vue', () => ({
	__esModule: true,
	default: {
		name: 'SaveButton',
		props: ['saving', 'disabled'],
		emits: ['click'],
		render() {
			return require('vue').h('button', { class: 'save', disabled: this.disabled, onClick: () => this.$emit('click') })
		},
	},
}))

const { schemaStore } = jest.requireMock('../../store/store.js')

/**
 * A stand-in form control: renders its tag with its id and value, and its
 * default and icon slots.
 *
 * @param {string} name The component name
 * @return {object} The stand-in
 */
function mockField(name) {
	return {
		name,
		props: ['modelValue', 'id'],
		emits: ['update:modelValue'],
		render() {
			const { h } = require('vue')
			const slots = [this.$slots.icon, this.$slots.default].filter(Boolean).map((slot) => slot())
			return h(`x-${name}`, { id: this.id, 'data-value': JSON.stringify(this.modelValue ?? null) }, slots)
		},
	}
}

/**
 * The tab with its own setup run, and its components bound from its options:
 * under jest the compiled bindings of an SFC with a setup block resolve
 * imported components to undefined.
 *
 * @param {object} component The tab
 * @return {object} The tab with its components bound
 */
function withRealSetup(component) {
	return {
		...component,
		setup: (props, context) => ({ ...component.setup(props, context), ...component.components }),
	}
}

const SCHEMA = {
	id: 7,
	title: 'Meetings',
	properties: { start: { type: 'string', format: 'date-time' }, place: { type: 'string' } },
	configuration: { other: true },
}

/**
 * Set a control's value the way the user does: through its v-model event.
 *
 * @param {object} wrapper The tab
 * @param {string} name The control's component name
 * @param {number} index Which of those controls
 * @param {unknown} value The new value
 * @return {Promise<void>}
 */
async function enter(wrapper, name, index, value) {
	wrapper.findAllComponents({ name })[index].vm.$emit('update:modelValue', value)
	await flushPromises()
}

beforeEach(() => {
	jest.clearAllMocks()
	schemaStore.saveSchema.mockResolvedValue({})
})

describe('CalendarProviderTab mounted', () => {
	it('shows only the switch while the provider is off', () => {
		const wrapper = mount(withRealSetup(CalendarProviderTab), { props: { schema: SCHEMA } })

		expect(wrapper.find('h3').text()).toBe('Calendar Provider Configuration')
		expect(wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })).toHaveLength(1)
		expect(wrapper.find('button.save').exists()).toBe(false)
	})

	it('saves the fields the user fills in onto the schema configuration', async () => {
		const wrapper = mount(withRealSetup(CalendarProviderTab), { props: { schema: SCHEMA } })

		await enter(wrapper, 'NcCheckboxRadioSwitch', 0, true)

		expect(wrapper.findAll('.placeholder').map((span) => span.text())).toEqual(['{start}', '{place}'])
		expect(wrapper.find('x-CircleIcon').exists()).toBe(true)
		expect(wrapper.find('button.save').attributes('disabled')).toBeDefined()

		await enter(wrapper, 'NcTextField', 0, 'Team meetings')
		await enter(wrapper, 'NcColorPicker', 0, '#ff0000')
		await enter(wrapper, 'NcSelect', 0, 'start')
		await enter(wrapper, 'NcSelect', 1, 'start')
		await enter(wrapper, 'NcTextField', 1, '{place}')
		await wrapper.find('#cal-desc').setValue('At {place}')
		await enter(wrapper, 'NcSelect', 2, 'place')
		await enter(wrapper, 'NcCheckboxRadioSwitch', 1, true)

		expect(wrapper.find('x-NcButton').text()).toBe('#ff0000')
		expect(wrapper.find('button.save').attributes('disabled')).toBeUndefined()

		await wrapper.find('button.save').trigger('click')
		await flushPromises()

		expect(schemaStore.saveSchema).toHaveBeenCalledWith({
			...SCHEMA,
			configuration: {
				other: true,
				calendarProvider: {
					enabled: true,
					displayName: 'Team meetings',
					color: '#ff0000',
					dtstart: 'start',
					dtend: 'start',
					titleTemplate: '{place}',
					descriptionTemplate: 'At {place}',
					locationField: 'place',
					allDay: true,
				},
			},
		})
	})
})
