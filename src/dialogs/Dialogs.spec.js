/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The global dialog host: which dialogs it mounts.
 *
 * @spec exclude Unit test of the client-side global dialog host.
 */
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import Dialogs from './Dialogs.vue'

jest.mock('../store/store.js', () => {
	const { reactive } = require('vue')
	return { navigationStore: reactive({ dialog: null }) }
})
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('../modals/application/DeleteApplication.vue', () => ({
	name: 'DeleteApplication',
	template: '<i class="DeleteApplication" />',
}))
jest.mock('../modals/logs/AuditTrailChanges.vue', () => ({
	name: 'AuditTrailChanges',
	template: '<i class="AuditTrailChanges" />',
}))
jest.mock('../modals/logs/AuditTrailDetails.vue', () => ({
	name: 'AuditTrailDetails',
	template: '<i class="AuditTrailDetails" />',
}))
jest.mock('../modals/logs/ClearAuditTrails.vue', () => ({
	name: 'ClearAuditTrails',
	template: '<i class="ClearAuditTrails" />',
}))
jest.mock('../modals/logs/DeleteAuditTrail.vue', () => ({
	name: 'DeleteAuditTrail',
	template: '<i class="DeleteAuditTrail" />',
}))

const { navigationStore } = jest.requireMock('../store/store.js')

describe('Dialogs', () => {
	it('always mounts the audit trail dialogs, and the application delete only when asked', async () => {
		navigationStore.dialog = null
		const wrapper = mount(Dialogs)
		expect(wrapper.findAll('i').map((i) => i.classes()[0])).toEqual([
			'DeleteAuditTrail',
			'AuditTrailDetails',
			'AuditTrailChanges',
			'ClearAuditTrails',
		])
		navigationStore.dialog = 'deleteApplication'
		await nextTick()
		expect(wrapper.find('.DeleteApplication').exists()).toBe(true)
		navigationStore.dialog = 'other'
		await nextTick()
		expect(wrapper.find('.DeleteApplication').exists()).toBe(false)
	})
})
