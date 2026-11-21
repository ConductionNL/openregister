/**
 * The deleted-items sidebar: the deleter choices from the list, loading the
 * statistics, the route query it writes and reads back, and the filters it
 * announces on the event bus. Hooks and methods run against a plain `this`
 * with stub stores and event bus.
 *
 * @spec exclude Client-side deleted-items sidebar state.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DeletedSideBar from './DeletedSideBar.vue'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, s, vars) => (vars ? s.replace('{count}', vars.count) : s),
	translatePlural: (app, s) => s,
}))
jest.mock('vue-material-design-icons/AccountCircle.vue', () => ({}))
jest.mock('../../components/shared/CountListItem.vue', () => ({}))
jest.mock('../../components/shared/SidebarStatCard.vue', () => ({}))
jest.mock('../../components/shared/StatisticsSidebarTab.vue', () => ({}))
jest.mock('../ScopeFiltersTab.vue', () => ({}))
jest.mock('../SidebarFilterSelect.vue', () => ({}))
jest.mock('../../eventBus.js', () => ({ __esModule: true, default: { emit: jest.fn(), on: jest.fn(), off: jest.fn() } }))
jest.mock('../../store/store.js', () => ({
	deletedStore: { deletedList: [], fetchStatistics: jest.fn(), fetchTopDeleters: jest.fn() },
	navigationStore: {},
	registerStore: { registerItem: null, registerList: [], refreshRegisterList: jest.fn(), setRegisterItem: jest.fn() },
	schemaStore: { schemaItem: null, schemaList: [], refreshSchemaList: jest.fn(), setSchemaItem: jest.fn() },
}))

const { deletedStore, registerStore, schemaStore } = jest.requireMock('../../store/store.js')
const eventBus = jest.requireMock('../../eventBus.js').default
const { computed, methods } = DeletedSideBar

/**
 * A plain sidebar instance with its data, computed getters and bound methods.
 *
 * @param {object} state Values and methods to replace
 * @return {object} The instance
 */
function sidebar(state = {}) {
	const vm = {
		...DeletedSideBar.data.call({}),
		$route: { path: '/deleted', query: {} },
		t: (app, s) => s,
		updateRouteQueryFromState: jest.fn(),
	}
	for (const [name, fn] of Object.entries(computed)) {
		if (typeof fn === 'function') Object.defineProperty(vm, name, { get: () => fn.call(vm), configurable: true })
	}
	for (const [name, fn] of Object.entries(methods)) {
		if (!(name in state)) vm[name] = fn.bind(vm)
	}
	Object.assign(vm, state)
	return vm
}

let consoleError

beforeEach(() => {
	jest.clearAllMocks()
	deletedStore.deletedList = []
	registerStore.registerItem = null
	registerStore.registerList = []
	schemaStore.schemaItem = null
	schemaStore.schemaList = []
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => consoleError.mockRestore())

describe('DeletedSideBar', () => {
	it('offers each distinct deleter, or Admin when there is none', () => {
		expect(sidebar().userOptions).toEqual([{ label: 'Admin', value: 'admin' }])
		deletedStore.deletedList = [
			{ '@self': { deleted: { deletedBy: 'ann' } } },
			{ '@self': { deleted: { deletedBy: 'bob' } } },
			{ '@self': { deleted: { deletedBy: 'ann' } } },
			{ '@self': {} },
		]
		expect(sidebar().userOptions).toEqual([{ label: 'ann', value: 'ann' }, { label: 'bob', value: 'bob' }])
	})

	it('labels the deletion count', () => {
		expect(sidebar().deletionsLabel(3)).toBe('3 deletions')
	})

	it('loads the scope lists and statistics on mount and listens for the count', async () => {
		const vm = sidebar({ applyQueryParamsFromRoute: jest.fn() })
		await DeletedSideBar.mounted.call(vm)
		expect(registerStore.refreshRegisterList).toHaveBeenCalled()
		expect(schemaStore.refreshSchemaList).toHaveBeenCalled()
		expect(deletedStore.fetchStatistics).toHaveBeenCalled()
		expect(deletedStore.fetchTopDeleters).toHaveBeenCalled()
		expect(vm.applyQueryParamsFromRoute).toHaveBeenCalled()

		const [event, listener] = eventBus.on.mock.calls[0]
		expect(event).toBe('deleted-filtered-count')
		listener(12)
		expect(vm.filteredCount).toBe(12)

		DeletedSideBar.beforeUnmount.call(vm)
		expect(eventBus.off).toHaveBeenCalledWith('deleted-filtered-count')
	})

	it('skips loaded scope lists and survives failed statistics', async () => {
		registerStore.registerList = [{ id: 1 }]
		schemaStore.schemaList = [{ id: 2 }]
		deletedStore.fetchStatistics.mockRejectedValueOnce(new Error('x'))
		deletedStore.fetchTopDeleters.mockRejectedValueOnce(new Error('y'))
		const vm = sidebar({ applyQueryParamsFromRoute: jest.fn() })
		await DeletedSideBar.mounted.call(vm)
		expect(registerStore.refreshRegisterList).not.toHaveBeenCalled()
		expect(consoleError).toHaveBeenCalledTimes(2)
	})

	it('writes the scope, deleter and dates to the route query', () => {
		registerStore.registerItem = { id: 1 }
		schemaStore.schemaItem = { id: 2 }
		const vm = sidebar({ selectedDeletedBy: { value: 'ann' }, dateFrom: new Date('2026-01-02T00:00:00Z') })
		const query = vm.buildQueryFromState()
		expect(query).toMatchObject({ register: '1', schema: '2', deletedBy: 'ann' })
		expect(query.dateFrom).toBeTruthy()
		registerStore.registerItem = null
		schemaStore.schemaItem = null
		expect(sidebar().buildQueryFromState()).toEqual({})
	})

	it('applies the filters by updating the route', () => {
		const vm = sidebar()
		vm.applyFilters()
		expect(vm.updateRouteQueryFromState).toHaveBeenCalled()
	})

	it('reads the deleter and dates from the route and announces the filters', () => {
		const vm = sidebar({ $route: { path: '/deleted', query: { deletedBy: 'bob', dateFrom: '2026-03-01' } } })
		vm.applyQueryParamsFromRoute()
		expect(vm.selectedDeletedBy).toEqual({ value: 'bob', label: 'bob' })
		expect(vm.dateFrom).toBeInstanceOf(Date)
		expect(vm.dateTo).toBeNull()
		expect(eventBus.emit).toHaveBeenCalledWith('deleted-filters-changed', {
			register: null,
			schema: null,
			deletedBy: 'bob',
			dateFrom: vm.dateFrom,
			dateTo: null,
		})
	})

	it('ignores other routes and clears an empty deleter', () => {
		const elsewhere = sidebar({ $route: { path: '/registers', query: { deletedBy: 'bob' } } })
		elsewhere.applyQueryParamsFromRoute()
		expect(elsewhere.selectedDeletedBy).toBeNull()

		const vm = sidebar({ selectedDeletedBy: { value: 'x' }, $route: { path: '/deleted', query: { deletedBy: '' } } })
		vm.applyQueryParamsFromRoute()
		expect(vm.selectedDeletedBy).toBeNull()
	})

	it('announces the selected scope ids', () => {
		registerStore.registerItem = { id: 4 }
		schemaStore.schemaItem = { id: 5 }
		sidebar().applyFiltersToStore()
		expect(eventBus.emit).toHaveBeenCalledWith('deleted-filters-changed', { register: 4, schema: 5, deletedBy: null, dateFrom: null, dateTo: null })
	})
})

describe('DeletedSideBar labels', () => {
	it('labels a top-list row with its deletion count', () => {
		expect(sidebar().deletionsLabel(4)).toBe('4 deletions')
		expect(sidebar().deletionsLabel(0)).toBe('0 deletions')
	})
})
