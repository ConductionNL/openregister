import { flushPromises, mount } from '@vue/test-utils'
import DeleteAuditTrail from './DeleteAuditTrail.vue'
import { auditTrailStore, navigationStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (app, s) => s,
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))
jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	return {
		__esModule: true,
		auditTrailStore: reactive({ auditTrailItem: null, refreshAuditTrailList: jest.fn() }),
		navigationStore: reactive({ dialog: 'deleteAuditTrail', setDialog: jest.fn() }),
	}
})
// The shared frame is presentational; a stand-in exposes its props, renders
// the slot and lets the spec raise its cancel / confirm events.
jest.mock('../shared/ConfirmDialogFrame.vue', () => ({
	__esModule: true,
	default: {
		name: 'ConfirmDialogFrame',
		inheritAttrs: false,
		emits: ['cancel', 'confirm'],
		template: '<div class="frame-stub"><slot /></div>',
	},
}))

const frame = (wrapper) => wrapper.findComponent({ name: 'ConfirmDialogFrame' })
const attrs = (wrapper) => frame(wrapper).vm.$attrs

/**
 * Answer the DELETE request.
 *
 * @param {object|Error} body The JSON body, or a rejection.
 */
function answer(body) {
	global.fetch = jest.fn(() => (body instanceof Error
		? Promise.reject(body)
		: Promise.resolve({ json: () => Promise.resolve(body) })))
}

describe('DeleteAuditTrail', () => {
	beforeEach(() => {
		jest.useFakeTimers()
		jest.clearAllMocks()
		navigationStore.dialog = 'deleteAuditTrail'
		auditTrailStore.auditTrailItem = { id: 42, action: 'update', created: null }
	})

	afterEach(() => {
		jest.useRealTimers()
		delete global.fetch
	})

	it('renders only while its dialog is open and shows the entry', () => {
		navigationStore.dialog = 'other'
		expect(mount(DeleteAuditTrail).find('.frame-stub').exists()).toBe(false)
		navigationStore.dialog = 'deleteAuditTrail'
		const wrapper = mount(DeleteAuditTrail)
		expect(wrapper.find('.audit-trail-info').text()).toContain('42')
		expect(attrs(wrapper).showConfirm).toBe(true)
		expect(attrs(wrapper).closeLabel).toBe('Cancel')
	})

	it('deletes the entry, refreshes the list, reports success and closes after two seconds', async () => {
		answer({ success: true })
		const wrapper = mount(DeleteAuditTrail)
		frame(wrapper).vm.$emit('confirm')
		await flushPromises()
		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/audit-trails/42', { method: 'DELETE' })
		expect(auditTrailStore.refreshAuditTrailList).toHaveBeenCalledTimes(1)
		expect(attrs(wrapper).success).toBe(true)
		expect(attrs(wrapper).loading).toBe(false)
		expect(attrs(wrapper).closeLabel).toBe('Close')
		expect(wrapper.find('.audit-trail-info').exists()).toBe(false)
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
		jest.advanceTimersByTime(2000)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it.each([
		[{ success: false, error: 'Immutable entry' }, 'Immutable entry'],
		[{ success: false }, 'Deletion failed'],
		[new Error(''), 'An error occurred while deleting the audit trail'],
	])('reports a refused or failed delete %#', async (body, message) => {
		answer(body)
		const wrapper = mount(DeleteAuditTrail)
		frame(wrapper).vm.$emit('confirm')
		await flushPromises()
		expect(attrs(wrapper).error).toBe(message)
		expect(attrs(wrapper).success).toBe(false)
		expect(auditTrailStore.refreshAuditTrailList).not.toHaveBeenCalled()
	})

	it('cancel closes the dialog', () => {
		const wrapper = mount(DeleteAuditTrail)
		frame(wrapper).vm.$emit('cancel')
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	describe('formatDate', () => {
		it('prints a dash for a missing date', () => {
			expect(mount(DeleteAuditTrail).vm.formatDate('')).toBe('-')
		})

		it('prints a valid date in the local format', () => {
			const iso = '2026-01-02T03:04:05Z'
			expect(mount(DeleteAuditTrail).vm.formatDate(iso)).toBe(new Date(iso).toLocaleString())
		})

		it('returns the raw value when formatting throws', () => {
			const spy = jest.spyOn(Date.prototype, 'toLocaleString').mockImplementation(() => { throw new RangeError('bad') })
			expect(mount(DeleteAuditTrail).vm.formatDate('2026-01-02')).toBe('2026-01-02')
			spy.mockRestore()
		})
	})
})
