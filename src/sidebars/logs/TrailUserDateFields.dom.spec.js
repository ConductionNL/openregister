/**
 * The user and date range fields the audit and search trail sidebars share:
 * what each field is handed, and that every change is passed up under its own
 * name and then announced once.
 *
 * @spec exclude Presentational filter fields; the sidebars own the filter state.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import TrailUserDateFields from './TrailUserDateFields.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcDateTimePickerNative: mockField('NcDateTimePickerNative'),
}))
jest.mock('../SidebarFilterSelect.vue', () => mockField('SidebarFilterSelect'))

/**
 * A stand-in field that renders nothing but keeps the props it was handed.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockField(name) {
	const { h } = require('vue')
	return {
		name,
		props: ['modelValue', 'options', 'placeholder', 'label', 'multiple', 'type', 'id'],
		emits: ['update:modelValue'],
		render() {
			return h('x-field')
		},
	}
}

/**
 * Mount the fields.
 *
 * @param {object} props The props
 * @return {object} The wrapper
 */
function fields(props = {}) {
	return mount(TrailUserDateFields, { props })
}

describe('TrailUserDateFields', () => {
	it('hands the user select its selection and options, and each picker its date', () => {
		const from = new Date('2026-01-02T03:04:00Z')
		const wrapper = fields({
			users: ['alice'],
			userOptions: [{ label: 'Alice', value: 'alice' }, { label: 'Bob', value: 'bob' }],
			dateFrom: from,
			dateTo: '2026-02-01T00:00',
		})

		const select = wrapper.findComponent({ name: 'SidebarFilterSelect' })
		expect(select.props('modelValue')).toEqual(['alice'])
		expect(select.props('options')).toEqual([
			{ label: 'Alice', value: 'alice' },
			{ label: 'Bob', value: 'bob' },
		])
		expect(select.props('label')).toBe('Users')
		expect(select.props('placeholder')).toBe('All users')

		const [fromPicker, toPicker] = wrapper.findAllComponents({ name: 'NcDateTimePickerNative' })
		expect(fromPicker.props('modelValue')).toBe(from)
		expect(fromPicker.props('label')).toBe('From date')
		expect(fromPicker.props('type')).toBe('datetime-local')
		expect(toPicker.props('modelValue')).toBe('2026-02-01T00:00')
		expect(toPicker.props('label')).toBe('To date')
		expect(wrapper.find('.filterGroup label').text()).toBe('Date Range')
	})

	it('defaults to no users, no options and no dates', () => {
		const wrapper = fields()

		const select = wrapper.findComponent({ name: 'SidebarFilterSelect' })
		expect(select.props('modelValue')).toEqual([])
		expect(select.props('options')).toEqual([])
		for (const picker of wrapper.findAllComponents({ name: 'NcDateTimePickerNative' })) {
			expect(picker.props('modelValue')).toBeNull()
		}
	})

	it('passes a user change up as update:users, then announces one change', async () => {
		const wrapper = fields()

		await wrapper.findComponent({ name: 'SidebarFilterSelect' }).vm.$emit('update:modelValue', ['bob'])

		expect(wrapper.emitted('update:users')).toEqual([[['bob']]])
		expect(wrapper.emitted('change')).toEqual([[]])
		expect(wrapper.emitted('update:dateFrom')).toBeUndefined()
		expect(wrapper.emitted('update:dateTo')).toBeUndefined()
	})

	it('passes each date change up under its own name', async () => {
		const wrapper = fields()
		const [fromPicker, toPicker] = wrapper.findAllComponents({ name: 'NcDateTimePickerNative' })
		const from = new Date('2026-03-01T00:00:00Z')

		await fromPicker.vm.$emit('update:modelValue', from)
		await toPicker.vm.$emit('update:modelValue', null)

		expect(wrapper.emitted('update:dateFrom')).toEqual([[from]])
		expect(wrapper.emitted('update:dateTo')).toEqual([[null]])
		expect(wrapper.emitted('change')).toHaveLength(2)
		expect(wrapper.emitted('update:users')).toBeUndefined()
	})
})
