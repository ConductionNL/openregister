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

describe('ClearAuditTrails with the real audit trail store', () => {
	let urls

	beforeEach(() => {
		urls = []
		global.fetch = async (url) => {
			urls.push(url)
			return { json: async () => ({ success: true }) }
		}
		auditTrailStore.refreshAuditTrailList = async () => {}
		jest.useFakeTimers()
	})

	afterEach(() => jest.useRealTimers())

	it('sends the active filters with the delete request', async () => {
		auditTrailStore.setAuditTrailFilters({
			action: 'delete',
			user: '',
			register: 5,
		})
		const ctx = context()
		expect(ctx.hasActiveFilters).toBe(true)
		expect(ctx.displayFilters).toEqual({ action: 'delete', register: 5 })
		await ctx.clearAuditTrails()
		expect(urls).toEqual([
			'/index.php/apps/openregister/api/audit-trails?action=delete&register=5',
		])
	})

	it('sends no filters and warns when none are active', async () => {
		auditTrailStore.setAuditTrailFilters({ user: '', register: null })
		const ctx = context()
		expect(ctx.hasActiveFilters).toBe(false)
		expect(ctx.displayFilters).toEqual({})
		await ctx.clearAuditTrails()
		expect(urls).toEqual(['/index.php/apps/openregister/api/audit-trails?'])
	})

	it('counts the entries the active filters match when the dialog opens', () => {
		auditTrailStore.setAuditTrailPagination({ total: 42 })
		const ctx = context()
		ClearAuditTrails.watch['navigationStore.dialog'].call(
			ctx,
			'clearAuditTrails',
		)
		expect(ctx.filteredCount).toBe(42)
	})
})
