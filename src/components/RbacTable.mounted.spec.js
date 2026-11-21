/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The RBAC table mounted: which groups get a row, which boxes are ticked, the
 * update it emits and the access summary.
 *
 * @spec exclude Unit test of the client-side RBAC permission table.
 */
import { mount } from '@vue/test-utils'
import RbacTable from './RbacTable.vue'

jest.mock('@nextcloud/vue', () => ({
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'disabled'],
		emits: ['update:modelValue'],
		template: '<input type="checkbox" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.checked)">',
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		template: '<div class="note" :data-type="type"><slot /></div>',
	},
}))

const GROUPS = [
	{ id: 'zeta', name: 'Zeta' },
	{ id: 'admin', name: 'Admin' },
	{ id: 'alpha', name: 'Alpha' },
	{ id: 'public', name: 'Public' },
	{ id: 'authenticated', name: 'Everyone' },
	{ id: 'other-org', name: 'Other' },
]

/**
 * Mount the table.
 *
 * @param {object} props Overrides
 * @return {object} The wrapper
 */
function mountTable(props = {}) {
	return mount(RbacTable, {
		props: {
			entityType: 'register',
			authorization: {},
			availableGroups: GROUPS,
			...props,
		},
		global: { mocks: { t: (app, text) => text } },
	})
}

/**
 * The ticked state of each box in a row, create/read/update/delete.
 *
 * @param {object} wrapper The wrapper
 * @param {string} rowClass The row's class or a group name
 * @return {Array<boolean>} The ticks
 */
function ticks(wrapper, rowClass) {
	const row = wrapper
		.findAll('tbody tr')
		.find((tr) => tr.classes().includes(rowClass) || tr.find('.group-badge').text() === rowClass)
	return row.findAll('input').map((i) => i.element.checked)
}

describe('RbacTable mounted', () => {
	it('lists the plain groups by name between public, authenticated and admin', () => {
		const wrapper = mountTable()
		expect(wrapper.findAll('tbody .group-badge').map((b) => b.text())).toEqual([
			'public',
			'authenticated',
			'Alpha',
			'Other',
			'Zeta',
			'admin',
		])
	})

	it('narrows the plain groups to the organisation', () => {
		const wrapper = mountTable({ organisationGroups: ['zeta', 'alpha', 'admin'] })
		expect(wrapper.findAll('.group-row .group-badge').map((b) => b.text())).toEqual([
			'Alpha',
			'Zeta',
		])
	})

	it('ticks what the entity grants, and admin always', () => {
		const wrapper = mountTable({
			authorization: {
				register: { create: ['public'], read: ['authenticated', 'alpha'], update: 'alpha' },
			},
		})
		expect(ticks(wrapper, 'public-row')).toEqual([true, false, false, false])
		expect(ticks(wrapper, 'user-row')).toEqual([false, true, false, false])
		expect(ticks(wrapper, 'Alpha')).toEqual([false, true, false, false])
		expect(ticks(wrapper, 'admin-row')).toEqual([true, true, true, true])
		expect(wrapper.find('.admin-row input').attributes('disabled')).toBeDefined()
		expect(wrapper.find('.note').attributes('data-type')).toBe('warning')
	})

	it('reports open access when nothing is granted', () => {
		const wrapper = mountTable({ authorization: { register: { read: [] } } })
		expect(wrapper.find('.note').attributes('data-type')).toBe('success')
		expect(wrapper.find('.note').text()).toContain('Open Access')
	})

	it('reads a flat application authorization', () => {
		const wrapper = mountTable({
			entityType: 'application',
			authorization: { create: ['zeta'], read: [] },
		})
		expect(ticks(wrapper, 'Zeta')).toEqual([true, false, false, false])
	})

	it('emits the change of each box with its group and action', async () => {
		const wrapper = mountTable()
		await wrapper.find('.public-row td:nth-child(3) input').setValue(true)
		const alphaRow = wrapper.findAll('.group-row')[0]
		await alphaRow.find('td:nth-child(5) input').setValue(true)
		await wrapper.find('.user-row td:nth-child(2) input').setValue(true)
		expect(wrapper.emitted('update')).toEqual([
			[{ entityType: 'register', groupId: 'public', action: 'read', hasPermission: true }],
			[{ entityType: 'register', groupId: 'alpha', action: 'delete', hasPermission: true }],
			[{ entityType: 'register', groupId: 'authenticated', action: 'create', hasPermission: true }],
		])
	})

	it('labels every box by its row and column', () => {
		const wrapper = mountTable()
		const box = wrapper.find('.public-row td:nth-child(2) input')
		const [row, col] = box.attributes('aria-labelledby').split(' ')
		expect(wrapper.find(`#${row}`).text()).toContain('public')
		expect(wrapper.find(`#${col}`).text()).toBe('Create')
	})
})
