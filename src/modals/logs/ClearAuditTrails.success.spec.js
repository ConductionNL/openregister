import ClearAuditTrails from './ClearAuditTrails.vue'
import { auditTrailStore, navigationStore } from '../../store/store.js'

/**
 * ClearAuditTrails — a successful clear: the message it shows (the server's,
 * else the count), the list refresh and the close three seconds later.
 *
 * @spec exclude Unit spec of client-side dialog logic.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('../../store/store.js', () => {
	const { createPinia, setActivePinia } = require('pinia')
	const { useAuditTrailStore } = require('../../store/modules/auditTrail.js')
	setActivePinia(createPinia())
	return { auditTrailStore: useAuditTrailStore(), navigationStore: { setDialog: jest.fn() } }
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

describe('ClearAuditTrails — a successful clear', () => {
	beforeEach(() => {
		auditTrailStore.refreshAuditTrailList = jest.fn(async () => {})
		navigationStore.setDialog.mockClear()
		jest.useFakeTimers()
	})

	afterEach(() => {
		jest.useRealTimers()
		delete global.fetch
	})

	/**
	 * A fetch stub answering the delete with the given body.
	 *
	 * @param {object} body The JSON answer
	 * @return {Function} The stub
	 */
	const answering = (body) => jest.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve(body) }))

	it('shows the server message, refreshes the list and closes after three seconds', async () => {
		global.fetch = answering({ success: true, message: 'Deleted 4 entries' })
		const ctx = context()
		ctx.error = 'old'
		await ctx.clearAuditTrails()
		expect(ctx.success).toBe(true)
		expect(ctx.error).toBe(false)
		expect(ctx.loading).toBe(false)
		expect(ctx.successMessage).toBe('Deleted 4 entries')
		expect(auditTrailStore.refreshAuditTrailList).toHaveBeenCalledTimes(1)

		jest.advanceTimersByTime(2999)
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(ctx.success).toBeNull()
		expect(ctx.successMessage).toBe('')
	})

	it('falls back to the count message when the server sends none', async () => {
		global.fetch = answering({ success: true })
		const ctx = context()
		const t = jest.fn((app, text) => text)
		ctx.t = t
		ctx.filteredCount = 7
		await ctx.clearAuditTrails()
		expect(ctx.successMessage).toBe('{count} audit trails cleared successfully')
		expect(t).toHaveBeenCalledWith('openregister', '{count} audit trails cleared successfully', { count: 7 })
	})
})
