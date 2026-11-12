/**
 * The audit trail sidebar's filters and statistics: the count and totals, the
 * statistics, action and object lists, the route query it writes and reads
 * back, the filters it sends once to the store, and clearing them. Methods run
 * against a plain `this` with a stub audit trail store.
 *
 * @spec exclude Client-side audit trail sidebar state.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import AuditTrailSideBar from './AuditTrailSideBar.vue'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, s, vars) => (vars ? s.replace('{count}', vars.count) : s),
	translatePlural: (app, s) => s,
}))
jest.mock('../../eventBus.js', () => ({ __esModule: true, default: { emit: jest.fn(), on: jest.fn(), off: jest.fn() } }))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/ChartLine.vue', () => ({}))
jest.mock('vue-material-design-icons/FilterOffOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FilterOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountCircle.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	auditTrailStore: {
		auditTrailList: [],
		auditTrailPagination: {},
		auditTrailFilters: {},
		getStatistics: jest.fn(),
		getActionDistribution: jest.fn(),
		getTopObjects: jest.fn(),
		setAuditTrailFilters: jest.fn(),
		refreshAuditTrailList: jest.fn(),
	},
	navigationStore: {},
	registerStore: { registerItem: null, registerList: [], setRegisterItem: jest.fn() },
	schemaStore: { schemaItem: null, schemaList: [], setSchemaItem: jest.fn() },
}))

const { auditTrailStore, registerStore, schemaStore } = jest.requireMock('../../store/store.js')
const eventBus = jest.requireMock('../../eventBus.js').default
const { methods } = AuditTrailSideBar

/**
 * A plain sidebar instance with its own initial state and bound methods.
 *
 * @param {object} state Values and methods to replace
 * @return {object} The instance
 */
function sidebar(state = {}) {
	const vm = {
		...AuditTrailSideBar.data.call({}),
		selectedUsers: [],
		dateFrom: null,
		dateTo: null,
		$route: { path: '/audit-trails', query: {} },
		updateRouteQueryFromState: jest.fn(),
		debouncedApplyFilters: jest.fn(),
	}
	for (const [name, fn] of Object.entries(methods)) {
		if (!(name in state)) vm[name] = fn.bind(vm)
	}
	Object.assign(vm, state)
	return vm
}

beforeEach(() => {
	jest.clearAllMocks()
	auditTrailStore.auditTrailList = []
	auditTrailStore.auditTrailPagination = {}
	auditTrailStore.auditTrailFilters = {}
	registerStore.registerItem = null
	registerStore.registerList = []
	schemaStore.schemaItem = null
	schemaStore.schemaList = []
})

describe('AuditTrailSideBar counts and statistics', () => {
	it('counts the list and takes the total from the pagination', () => {
		auditTrailStore.auditTrailList = [1, 2]
		const vm = sidebar()
		vm.updateFilteredCount()
		expect(vm.filteredCount).toBe(2)
		expect(vm.totalAuditTrails).toBe(2)
		auditTrailStore.auditTrailPagination = { total: 40 }
		vm.updateFilteredCount()
		expect(vm.totalAuditTrails).toBe(40)
		expect(vm.entriesLabel(5)).toBe('5 entries')
	})

	it('loads the statistics with zero defaults and keeps them on failure', async () => {
		auditTrailStore.getStatistics.mockResolvedValueOnce({ total: 9, create: 4 })
		const vm = sidebar()
		await vm.loadStatistics()
		expect(vm).toMatchObject({ totalAuditTrails: 9, createCount: 4, updateCount: 0, deleteCount: 0 })
		auditTrailStore.getStatistics.mockRejectedValueOnce(new Error('x'))
		await vm.loadStatistics()
		expect(vm.totalAuditTrails).toBe(9)
	})

	it('maps the action distribution and the top objects', async () => {
		auditTrailStore.getActionDistribution.mockResolvedValueOnce([{ action: 'create', count: 3, percentage: 60 }, { name: 'update' }])
		auditTrailStore.getTopObjects.mockResolvedValueOnce([{ objectId: 'o-1', count: 5 }, { name: 'Named' }, { id: 7 }])
		const vm = sidebar()
		await vm.loadActionDistribution()
		await vm.loadTopObjects()
		expect(vm.actionDistribution).toEqual([
			{ action: 'create', count: 3, percentage: 60 },
			{ action: 'update', count: 0, percentage: 0 },
		])
		expect(vm.topObjects).toEqual([
			{ name: 'o-1', count: 5 },
			{ name: 'Named', count: 0 },
			{ name: 'Object 7', count: 0 },
		])
	})

	it('keeps the lists when loading them fails', async () => {
		auditTrailStore.getActionDistribution.mockRejectedValueOnce(new Error('x'))
		auditTrailStore.getTopObjects.mockRejectedValueOnce(new Error('x'))
		const vm = sidebar({ actionDistribution: [{ action: 'a' }], topObjects: [{ name: 'b' }] })
		await vm.loadActionDistribution()
		await vm.loadTopObjects()
		expect(vm.actionDistribution).toEqual([{ action: 'a' }])
		expect(vm.topObjects).toEqual([{ name: 'b' }])
	})

	it('reloads everything when the list changes', () => {
		const vm = sidebar({ updateFilteredCount: jest.fn(), loadStatistics: jest.fn(), loadActionDistribution: jest.fn(), loadTopObjects: jest.fn() })
		AuditTrailSideBar.watch['auditTrailStore.auditTrailList'].call(vm)
		expect(vm.updateFilteredCount).toHaveBeenCalled()
		expect(vm.loadStatistics).toHaveBeenCalled()
		expect(vm.loadActionDistribution).toHaveBeenCalled()
		expect(vm.loadTopObjects).toHaveBeenCalled()
	})
})

describe('AuditTrailSideBar filters', () => {
	it('debounces the object filter and applies the changes toggle at once', () => {
		const vm = sidebar()
		vm.handleObjectFilterChange('o-1')
		expect(vm.objectFilter).toBe('o-1')
		expect(vm.debouncedApplyFilters).toHaveBeenCalled()
		vm.updateChangesFilter(true)
		expect(vm.showOnlyWithChanges).toBe(true)
		expect(vm.updateRouteQueryFromState).toHaveBeenCalled()
	})

	it('writes the scope, actions, users, object and changes flag to the route query', () => {
		registerStore.registerItem = { id: 1 }
		const vm = sidebar({
			selectedActions: [{ value: 'create' }, { value: 'delete' }],
			selectedUsers: [{ value: 'ann' }],
			objectFilter: 'o-1',
			showOnlyWithChanges: true,
		})
		expect(vm.buildQueryFromState()).toMatchObject({ register: '1', action: 'create,delete', user: 'ann', object: 'o-1', onlyWithChanges: '1' })
	})

	it('reads the filters back from the route, keeping unknown actions', () => {
		const vm = sidebar({
			$route: { path: '/audit-trails', query: { action: 'create,custom', user: 'ann,bob', object: 'o-2', onlyWithChanges: '1' } },
		})
		vm.applyQueryParamsFromRoute()
		expect(vm.selectedActions[0].value).toBe('create')
		expect(vm.selectedActions[1]).toEqual({ value: 'custom', label: 'custom' })
		expect(vm.selectedUsers).toEqual([{ value: 'ann', label: 'ann' }, { value: 'bob', label: 'bob' }])
		expect(vm.objectFilter).toBe('o-2')
		expect(vm.showOnlyWithChanges).toBe(true)
		expect(auditTrailStore.setAuditTrailFilters).toHaveBeenCalledWith(expect.objectContaining({ object: 'o-2', onlyWithChanges: true }))
		expect(eventBus.emit).toHaveBeenCalledWith('audit-trail-filters-changed', expect.any(Object))
	})

	it('ignores other routes and empty route values', () => {
		const elsewhere = sidebar({ $route: { path: '/deleted', query: { object: 'x' } } })
		elsewhere.applyQueryParamsFromRoute()
		expect(elsewhere.objectFilter).toBe('')
		const vm = sidebar()
		expect(vm.actionsFromRoute('')).toEqual([])
		expect(vm.actionsFromRoute(undefined)).toEqual([])
	})

	it('sends unchanged filters only once', () => {
		const vm = sidebar({ objectFilter: 'o-1' })
		vm.applyFiltersToStore()
		expect(auditTrailStore.setAuditTrailFilters).toHaveBeenCalledTimes(1)
		auditTrailStore.auditTrailFilters = auditTrailStore.setAuditTrailFilters.mock.calls[0][0]
		vm.applyFiltersToStore()
		expect(auditTrailStore.setAuditTrailFilters).toHaveBeenCalledTimes(1)
		expect(auditTrailStore.refreshAuditTrailList).toHaveBeenCalledTimes(1)
	})

	it('clears every filter and the scope', () => {
		const vm = sidebar({ selectedActions: [{ value: 'x' }], objectFilter: 'o', showOnlyWithChanges: true })
		vm.clearAllFilters()
		expect(vm).toMatchObject({ selectedActions: [], selectedUsers: [], objectFilter: '', showOnlyWithChanges: false, dateFrom: null })
		expect(registerStore.setRegisterItem).toHaveBeenCalledWith(null)
		expect(auditTrailStore.setAuditTrailFilters).toHaveBeenCalledWith({})
		expect(auditTrailStore.refreshAuditTrailList).toHaveBeenCalled()
		expect(vm.updateRouteQueryFromState).toHaveBeenCalled()
	})
})
