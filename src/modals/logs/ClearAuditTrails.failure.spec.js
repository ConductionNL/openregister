import ClearAuditTrails from './ClearAuditTrails.vue'
import { auditTrailStore } from '../../store/store.js'

// The clear dialog works on the filters the audit trail store really holds,
// set through its own action, not on a field the store does not have.

jest.mock('../../store/store.js', () => {
	const { createPinia, setActivePinia } = require('pinia')
	const { useAuditTrailStore } = require('../../store/modules/auditTrail.js')
	setActivePinia(createPinia())
	return { auditTrailStore: useAuditTrailStore(), navigationStore: {} }
})
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
 * @return {object} The context
 */
function context() {
	const parts = [...(ClearAuditTrails.mixins ?? []), ClearAuditTrails]
	const ctx = { ...Object.assign({}, ...parts.map((part) => part.data?.call({}))) }
	ctx.t = (app, text) => text
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

describe('ClearAuditTrails — a failed clear', () => {
	let errorSpy

	beforeEach(() => {
		auditTrailStore.refreshAuditTrailList = jest.fn(async () => {})
		errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {})
		jest.useFakeTimers()
	})

	afterEach(() => {
		jest.useRealTimers()
		errorSpy.mockRestore()
		delete global.fetch
	})

	it('shows the request error, logs it and neither refreshes nor closes', async () => {
		const failure = new Error('Audit trail is immutable')
		global.fetch = jest.fn(() => Promise.reject(failure))
		const ctx = context()
		ctx.closeDialog = jest.fn()
		await ctx.clearAuditTrails()
		expect(ctx.success).toBe(false)
		expect(ctx.error).toBe('Audit trail is immutable')
		expect(ctx.loading).toBe(false)
		expect(errorSpy).toHaveBeenCalledWith('Error clearing audit trails:', failure)
		expect(auditTrailStore.refreshAuditTrailList).not.toHaveBeenCalled()
		jest.advanceTimersByTime(3000)
		expect(ctx.closeDialog).not.toHaveBeenCalled()
	})

	it('falls back to the generic message when the error has none', async () => {
		global.fetch = jest.fn(() => Promise.reject({}))
		const ctx = context()
		await ctx.clearAuditTrails()
		expect(ctx.error).toBe('An error occurred while clearing audit trails')
	})
})
