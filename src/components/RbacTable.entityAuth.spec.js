/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which permission boxes the RBAC table ticks and which note it shows, for a
 * nested (organisation) authorization, a flat (application) one and neither.
 *
 * @spec exclude Unit test of client-side RBAC table rendering.
 */
import { mount } from '@vue/test-utils'
import { h, nextTick, reactive } from 'vue'
import RbacTable from './RbacTable.vue'

jest.mock('@nextcloud/vue', () => {
	const { h: render } = require('vue')
	return {
		__esModule: true,
		NcCheckboxRadioSwitch: {
			name: 'NcCheckboxRadioSwitch',
			props: ['modelValue'],
			render() {
				return render('input', {
					type: 'checkbox',
					checked: this.modelValue,
					'data-cell': this.$attrs['aria-labelledby'],
				})
			},
		},
		NcNoteCard: {
			name: 'NcNoteCard',
			props: ['type'],
			render() {
				return render(
					'div',
					{ class: `note-${this.type}` },
					this.$slots.default?.(),
				)
			},
		},
	}
})

const groups = [
	{ id: 'editors', name: 'Editors' },
	{ id: 'admin', name: 'Admin' },
]

/**
 * Mount the table.
 *
 * @param {string} entityType The entity type
 * @param {object} authorization The authorization prop
 * @return {object} The wrapper
 */
function render(entityType, authorization) {
	return mount(RbacTable, {
		props: { entityType, authorization, availableGroups: groups },
		global: { mocks: { t: (app, text) => text } },
	})
}

/**
 * The row and column of every ticked box, without the per-instance id seed
 * and without the admin row, which is always ticked.
 *
 * @param {object} wrapper The mounted table
 * @return {Array<string>} The ticked cells as "row/column"
 */
function ticked(wrapper) {
	return wrapper
		.findAll('input')
		.filter((input) => input.element.checked)
		.map((input) =>
			input
				.attributes('data-cell')
				.replace(/rbac-\d+-(row|col)-/g, '')
				.replace(' ', '/'),
		)
		.filter((cell) => !cell.startsWith('admin/'))
}

describe('RbacTable entity authorization', () => {
	it('reads the nested entry of an organisation entity type', () => {
		const wrapper = render('register', {
			register: { read: ['public', 'editors'], delete: ['authenticated'] },
			schema: { create: ['editors'] },
		})
		expect(ticked(wrapper)).toEqual([
			'public/read',
			'authenticated/delete',
			'editors/read',
		])
		expect(wrapper.find('.note-warning').exists()).toBe(true)
	})

	it('reads a flat application authorization', () => {
		const wrapper = render('application', {
			create: ['editors'],
			read: [],
		})
		expect(ticked(wrapper)).toEqual(['editors/create'])
		expect(wrapper.find('.note-warning').exists()).toBe(true)
	})

	it('ticks nothing and shows the open access note without permissions', () => {
		const wrapper = render('schema', { register: { read: ['editors'] } })
		expect(ticked(wrapper)).toEqual([])
		expect(wrapper.find('.note-warning').exists()).toBe(false)
		expect(wrapper.find('.note-success').exists()).toBe(true)
	})

	it('ignores a flat authorization for a non-application type', () => {
		const wrapper = render('register', { create: ['editors'] })
		expect(ticked(wrapper)).toEqual([])
	})

	it('follows in-place changes to the authorization', async () => {
		const authorization = reactive({})
		const wrapper = mount(
			{
				render: () =>
					h(RbacTable, {
						entityType: 'register',
						authorization,
						availableGroups: groups,
					}),
			},
			{ global: { mocks: { t: (app, text) => text } } },
		)
		expect(ticked(wrapper)).toEqual([])

		authorization.register = { update: [] }
		await nextTick()
		expect(ticked(wrapper)).toEqual([])

		authorization.register.update.push('editors')
		await nextTick()
		expect(ticked(wrapper)).toEqual(['editors/update'])
		expect(wrapper.find('.note-warning').exists()).toBe(true)
	})

	it('emits the change a box asks for', async () => {
		const wrapper = render('register', { register: {} })
		await wrapper
			.findAllComponents({ name: 'NcCheckboxRadioSwitch' })[0]
			.vm.$emit('update:modelValue', true)
		expect(wrapper.emitted('update')).toEqual([
			[
				{
					entityType: 'register',
					groupId: 'public',
					action: 'create',
					hasPermission: true,
				},
			],
		])
	})
})
