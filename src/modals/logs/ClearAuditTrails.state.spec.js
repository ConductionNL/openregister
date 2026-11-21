/**
 * The clear dialog's own state: which filters count as active, which are
 * shown, when the count is taken and what closing resets.
 *
 * @spec exclude Unit tests of client-side clear dialog state.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ClearAuditTrails from './ClearAuditTrails.vue'
import { auditTrailStore, navigationStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	auditTrailStore: { auditTrailFilters: null, auditTrailPagination: { total: 0 } },
	navigationStore: { setDialog: jest.fn() },
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, s) => s,
	translatePlural: (app, s) => s,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Cancel.vue', () => ({}))
jest.mock('vue-material-design-icons/DeleteOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))

/**
 * A bare dialog context with every method and computed bound to it.
 *
 * @param {object} state The state to start from
 * @return {object} The context
 */
function context(state = {}) {
	const parts = [...(ClearAuditTrails.mixins ?? []), ClearAuditTrails]
	const ctx = { ...Object.assign({}, ...parts.map((part) => part.data?.call({}))), ...state }
	for (const [name, method] of Object.entries(
		Object.assign({}, ...parts.map((part) => part.methods)),
	)) {
		ctx[name] = method.bind(ctx)
	}
	for (const [name, getter] of Object.entries(ClearAuditTrails.computed)) {
		Object.defineProperty(ctx, name, { get: () => getter.call(ctx) })
	}
	return ctx
}

describe('ClearAuditTrails state', () => {
	beforeEach(() => {
		navigationStore.setDialog.mockClear()
		auditTrailStore.auditTrailFilters = null
		auditTrailStore.auditTrailPagination = { total: 0 }
	})

	it('has no active or shown filters without a filter object', () => {
		const ctx = context()

		expect(ctx.hasActiveFilters).toBeFalsy()
		expect(ctx.displayFilters).toEqual({})
	})

	it('ignores null, undefined and empty filter values', () => {
		auditTrailStore.auditTrailFilters = { action: null, user: undefined, register: '' }
		const ctx = context()

		expect(ctx.hasActiveFilters).toBe(false)
		expect(ctx.displayFilters).toEqual({})
	})

	it('shows only the filters that hold a value, zero included', () => {
		auditTrailStore.auditTrailFilters = { action: 'update', user: '', register: 0, schema: null }
		const ctx = context()

		expect(ctx.hasActiveFilters).toBe(true)
		expect(ctx.displayFilters).toEqual({ action: 'update', register: 0 })
	})

	it('takes the count only when this dialog opens', () => {
		auditTrailStore.auditTrailPagination = { total: 17 }
		const ctx = context({ filteredCount: 3 })
		const watcher = ClearAuditTrails.watch['navigationStore.dialog']

		watcher.call(ctx, 'deleteAuditTrail')
		expect(ctx.filteredCount).toBe(3)

		watcher.call(ctx, 'clearAuditTrails')
		expect(ctx.filteredCount).toBe(17)
	})

	it('closes the dialog and resets its outcome', () => {
		jest.useFakeTimers()
		const closed = jest.fn()
		const ctx = context({ success: true, loading: true, error: 'x', successMessage: 'Done' })
		ctx.closeModalTimeout = setTimeout(closed, 10)

		ctx.closeDialog()
		jest.advanceTimersByTime(20)
		jest.useRealTimers()

		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(closed).not.toHaveBeenCalled()
		expect(ctx.success).toBeNull()
		expect(ctx.loading).toBe(false)
		expect(ctx.error).toBe(false)
		expect(ctx.successMessage).toBe('')
	})
})
