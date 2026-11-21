/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * EditableCell mounted: the editor it renders, choosing from an enum, the
 * keyboard, click handling and the number conversion edges.
 *
 * @spec exclude Unit test of the client-side inline cell editor.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import EditableCell from './EditableCell.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { patch: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (m, key) => (key in vars ? vars[key] : m)),
}))
jest.mock('@conduction/nextcloud-vue', () => ({
	CnCellRenderer: { name: 'CnCellRenderer', props: ['value'], template: '<span class="shown">{{ value }}</span>' },
}))
jest.mock('@nextcloud/vue', () => ({
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel', 'disabled'],
		emits: ['update:modelValue'],
		render: () => null,
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'type', 'disabled'],
		emits: ['update:modelValue', 'blur'],
		template: '<input class="field" :type="type" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" @blur="$emit(\'blur\')">',
	},
}))

const URL = '/index.php/apps/openregister/api/objects/3/7/u-1'

/**
 * Mount a cell.
 *
 * @param {object} props Overrides
 * @param {boolean} canUpdate Whether the row grants update
 * @return {object} The wrapper
 */
function mountCell(props = {}, canUpdate = true) {
	return mount(EditableCell, {
		props: {
			row: { id: 'u-1', '@self': { register: 3, schema: 7, can: { update: canUpdate } } },
			field: 'status',
			value: 'open',
			property: { type: 'string', title: 'Status' },
			...props,
		},
	})
}

beforeEach(() => {
	axios.patch.mockReset()
})

describe('EditableCell mounted', () => {
	it('is a labelled button for an editor, and plain for a reader', () => {
		const editor = mountCell()
		expect(editor.attributes('role')).toBe('button')
		expect(editor.attributes('tabindex')).toBe('0')
		expect(editor.attributes('aria-label')).toBe('Edit Status')
		const reader = mountCell({}, false)
		expect(reader.attributes('role')).toBeUndefined()
	})

	it('edits on Enter and saves the text field on Enter', async () => {
		axios.patch.mockResolvedValue({})
		const wrapper = mountCell()
		await wrapper.trigger('keydown', { key: 'Enter' })
		const field = wrapper.find('input.field')
		expect(field.element.value).toBe('open')
		expect(field.attributes('type')).toBe('text')
		await field.setValue('closed')
		await field.trigger('keydown', { key: 'Enter' })
		await flushPromises()
		expect(axios.patch).toHaveBeenCalledWith(URL, { status: 'closed' })
		expect(wrapper.find('.shown').text()).toBe('closed')
		expect(wrapper.emitted('saved')[0][0]).toEqual(
			expect.objectContaining({ field: 'status', value: 'closed' }),
		)
	})

	it('cancels on Escape and saves on blur', async () => {
		axios.patch.mockResolvedValue({})
		const wrapper = mountCell()
		await wrapper.trigger('dblclick')
		await wrapper.find('input.field').setValue('x')
		await wrapper.find('input.field').trigger('keydown', { key: 'Escape' })
		expect(wrapper.find('.shown').text()).toBe('open')
		await wrapper.trigger('dblclick')
		await wrapper.find('input.field').setValue('y')
		await wrapper.find('input.field').trigger('blur')
		await flushPromises()
		expect(axios.patch).toHaveBeenCalledTimes(1)
		expect(axios.patch).toHaveBeenCalledWith(URL, { status: 'y' })
	})

	it('offers the enum as a select and saves the choice straight away', async () => {
		axios.patch.mockResolvedValue({})
		const wrapper = mountCell({ property: { type: 'string', enum: ['open', 'closed'] } })
		await wrapper.trigger('dblclick')
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('modelValue')).toEqual({ value: 'open', label: 'open' })
		expect(select.props('inputLabel')).toBe('Edit status')
		select.vm.$emit('update:modelValue', { value: 'closed', label: 'closed' })
		await flushPromises()
		expect(axios.patch).toHaveBeenCalledWith(URL, { status: 'closed' })
	})

	it('saves a cleared choice as null', async () => {
		axios.patch.mockResolvedValue({})
		const wrapper = mountCell({ property: { type: 'string', enum: ['open'] } })
		await wrapper.trigger('dblclick')
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', null)
		await flushPromises()
		expect(axios.patch).toHaveBeenCalledWith(URL, { status: null })
	})

	it.each([
		['integer', '', null],
		['integer', '12.9', 12],
		['number', '1.5', 1.5],
		['number', 'abc', 'abc'],
	])('a %s field sends %j as %j', async (type, typed, sent) => {
		axios.patch.mockResolvedValue({})
		const wrapper = mountCell({ value: 3, property: { type } })
		await wrapper.trigger('dblclick')
		expect(wrapper.find('input.field').attributes('type')).toBe('number')
		// Set the draft directly: jsdom empties non-numeric text in a number input.
		wrapper.vm.draft = typed
		await wrapper.vm.save()
		expect(axios.patch).toHaveBeenCalledWith(URL, { status: sent })
	})

	it('uses the server error, or its own message', async () => {
		const wrapper = mountCell()
		axios.patch.mockRejectedValueOnce({ response: { data: { error: 'Locked' } } })
		wrapper.vm.draft = 'x'
		await wrapper.vm.save()
		expect(wrapper.find('[role="alert"]').text()).toBe('Locked')
		axios.patch.mockRejectedValueOnce(new Error('net'))
		wrapper.vm.draft = 'y'
		await wrapper.vm.save()
		expect(wrapper.find('[role="alert"]').text()).toBe('The value could not be saved')
	})

	it('does not save twice at once', async () => {
		let release
		axios.patch.mockReturnValue(new Promise((r) => (release = r)))
		const wrapper = mountCell()
		wrapper.vm.draft = 'a'
		const first = wrapper.vm.save()
		await wrapper.vm.save()
		release({})
		await first
		expect(axios.patch).toHaveBeenCalledTimes(1)
	})

	it('keeps a click from reaching the row only when the cell is editable', async () => {
		const parentClick = jest.fn()
		const Host = {
			components: { EditableCell },
			props: ['can'],
			methods: { parentClick },
			template: `<div @click="parentClick"><EditableCell :row="{ id: 1, '@self': { can: { update: can } } }"
				field="status" value="open" :property="{ type: 'string' }" /></div>`,
		}
		const editor = mount(Host, { props: { can: true } })
		await editor.find('.editable-cell').trigger('click')
		expect(parentClick).not.toHaveBeenCalled()
		const reader = mount(Host, { props: { can: false } })
		await reader.find('.editable-cell').trigger('click')
		expect(parentClick).toHaveBeenCalledTimes(1)
	})

	it('follows a refreshed value, but not while editing', async () => {
		const wrapper = mountCell()
		await wrapper.setProps({ value: 'new' })
		expect(wrapper.find('.shown').text()).toBe('new')
		await wrapper.trigger('dblclick')
		await wrapper.setProps({ value: 'newer' })
		expect(wrapper.vm.shown).toBe('new')
	})
})
