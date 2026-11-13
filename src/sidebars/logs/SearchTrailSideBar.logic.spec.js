/**
 * The search trail sidebar's logic: loading and showing statistics, the
 * filters it sends to the store, the route query it writes and reads, and the
 * labels of complexity, scope and browser rows. Methods run against a plain
 * `this` and a stub search trail store.
 *
 * @spec exclude Client-side search trail sidebar state.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import SearchTrailSideBar from './SearchTrailSideBar.vue'

jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	registerStore: { registerItem: null, registerList: [], setRegisterItem: jest.fn() },
	schemaStore: { schemaItem: null, schemaList: [], setSchemaItem: jest.fn() },
	searchTrailStore: {
		searchTrailList: [],
		refreshSearchTrailList: jest.fn(),
		setSearchTrailFilters: jest.fn(),
		getStatistics: jest.fn(),
		getPopularTerms: jest.fn(),
		getRegisterSchemaStats: jest.fn(),
		getUserAgentStats: jest.fn(),
		getActivity: jest.fn(),
	},
}))
jest.mock('../../eventBus.js', () => ({ __esModule: true, default: { emit: jest.fn(), on: jest.fn(), off: jest.fn() } }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, s, vars) => (vars ? s.replace('{count}', vars.count) : s),
	translatePlural: (app, s) => s,
}))
jest.mock('vue-material-design-icons/ChartLine.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FilterOffOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FilterOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/MagnifyPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/Monitor.vue', () => ({}))
jest.mock('vue-material-design-icons/TrendingUp.vue', () => ({}))

const { registerStore, schemaStore, searchTrailStore } = jest.requireMock('../../store/store.js')
const eventBus = jest.requireMock('../../eventBus.js').default
const { methods, computed } = SearchTrailSideBar

/**
 * A plain sidebar instance: its own initial state with bound methods.
 *
 * @param {object} state Values and methods to replace
 * @return {object} The instance
 */
function sidebar(state = {}) {
	const vm = {
		...SearchTrailSideBar.data.call({}),
		selectedUsers: [],
		dateFrom: null,
		dateTo: null,
		$route: { path: '/search-trails', query: {} },
		updateRouteQueryFromState: jest.fn(),
		debouncedApplyFilters: jest.fn(),
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
	registerStore.registerItem = null
	registerStore.registerList = []
	schemaStore.schemaItem = null
	schemaStore.schemaList = []
	searchTrailStore.searchTrailList = []
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => consoleError.mockRestore())

describe('SearchTrailSideBar statistics', () => {
	it('shows the statistics, rounding the averages and defaulting the rest to zero', async () => {
		searchTrailStore.getStatistics.mockResolvedValue({
			total: 12,
			averageResultsPerSearch: 4.6,
			averageExecutionTime: 10.4,
			successRate: 90,
			queryComplexity: { simple: 1, medium: 2, complex: 3 },
		})
		const vm = sidebar()
		await vm.loadStatistics()
		expect(vm.totalSearchTrails).toBe(12)
		expect(vm.averageResultsPerSearch).toBe(5)
		expect(vm.averageExecutionTime).toBe(10)
		expect(vm.successRate).toBe(90)
		expect(vm.uniqueUsers).toBe(0)
		expect(vm.queryComplexity).toEqual({ simple: 1, medium: 2, complex: 3 })
	})

	it('resets the statistics when they fail to load', async () => {
		searchTrailStore.getStatistics.mockRejectedValue(new Error('x'))
		const vm = sidebar({ totalSearchTrails: 5 })
		await vm.loadStatistics()
		expect(vm.totalSearchTrails).toBe(0)
		expect(vm.queryComplexity).toEqual({ simple: 0, medium: 0, complex: 0 })
	})

	it('loads the term, scope, agent and activity lists, empty on failure', async () => {
		searchTrailStore.getPopularTerms.mockResolvedValue([{ term: 'a' }])
		searchTrailStore.getRegisterSchemaStats.mockResolvedValue(null)
		searchTrailStore.getUserAgentStats.mockRejectedValue(new Error('x'))
		searchTrailStore.getActivity.mockResolvedValue([{ period: 'p' }])
		const vm = sidebar({ selectedActivityPeriod: { value: 'weekly' } })
		await vm.loadPopularTerms()
		await vm.loadRegisterSchemaStats()
		await vm.loadUserAgentStats()
		await vm.loadActivityData()
		expect(searchTrailStore.getPopularTerms).toHaveBeenCalledWith(10)
		expect(vm.popularTerms).toEqual([{ term: 'a' }])
		expect(vm.registerSchemaStats).toEqual([])
		expect(vm.userAgentStats).toEqual([])
		expect(searchTrailStore.getActivity).toHaveBeenCalledWith('weekly')
		expect(vm.currentActivityData).toEqual([{ period: 'p' }])
		expect(vm.updateRouteQueryFromState).toHaveBeenCalled()

		searchTrailStore.getPopularTerms.mockRejectedValue(new Error('x'))
		searchTrailStore.getRegisterSchemaStats.mockRejectedValue(new Error('x'))
		searchTrailStore.getActivity.mockRejectedValue(new Error('x'))
		await vm.loadPopularTerms()
		await vm.loadRegisterSchemaStats()
		await vm.loadActivityData()
		expect(vm.popularTerms).toEqual([])
		expect(vm.registerSchemaStats).toEqual([])
		expect(vm.currentActivityData).toEqual([])
	})

	it('reloads the list and counts it, swallowing a failed reload', async () => {
		searchTrailStore.searchTrailList = [1, 2, 3]
		const vm = sidebar()
		await vm.loadSearchTrailData()
		expect(vm.filteredCount).toBe(3)
		searchTrailStore.refreshSearchTrailList.mockRejectedValueOnce(new Error('x'))
		await expect(vm.loadSearchTrailData()).resolves.toBeUndefined()
	})

	it('gives each complexity level its share of all queries', () => {
		const vm = sidebar({ queryComplexity: { simple: 1, medium: 1, complex: 2 } })
		expect(vm.getComplexityPercentage('complex')).toBe(50)
		expect(vm.getComplexityPercentage('simple')).toBe(25)
		expect(sidebar({ queryComplexity: { simple: 0, medium: 0, complex: 0 } }).getComplexityPercentage('simple')).toBe(0)
		expect(computed.complexityLevels.call(vm).map((level) => level.key)).toEqual(['simple', 'medium', 'complex'])
	})

	it('labels counts, scopes and browsers', () => {
		registerStore.registerList = [{ id: 1, title: 'Reg' }]
		schemaStore.schemaList = [{ id: 2, title: 'Sch' }]
		const vm = sidebar()
		expect(vm.searchesLabel(4)).toBe('4 searches')
		expect(vm.getRegisterSchemaName({ register: 1, schema: 2 })).toBe('Reg / Sch')
		expect(vm.getRegisterSchemaName({ register: 8, schema: 9 })).toBe('Register 8 / Schema 9')
		expect(vm.getBrowserName({ browser_info: { browser: 'Firefox', version: '120' } })).toBe('Firefox 120')
		expect(vm.getBrowserName({ browser_info: { browser: 'Edge' } })).toBe('Edge')
		expect(vm.getBrowserName({ user_agent: 'curl/8' })).toBe('curl/8')
		expect(vm.getBrowserName({})).toBe('Unknown Browser')
	})
})

describe('SearchTrailSideBar filters', () => {
	it('sends the chosen filters to the store and announces them', () => {
		registerStore.registerItem = { id: 3 }
		const vm = sidebar({
			selectedSuccessStatus: { value: true },
			selectedUsers: [{ value: 'ann' }, 'bob'],
			searchTermFilter: 'term',
			executionTimeFrom: '10',
			resultCountTo: '',
		})
		vm.applyFilters()
		const filters = searchTrailStore.setSearchTrailFilters.mock.calls[0][0]
		expect(filters).toMatchObject({ success: true, searchTerm: 'term', executionTimeFrom: '10' })
		expect(filters.resultCountTo).toBeUndefined()
		expect(searchTrailStore.refreshSearchTrailList).toHaveBeenCalled()
		expect(eventBus.emit).toHaveBeenCalledWith('search-trail-filters-changed', filters)
		expect(vm.updateRouteQueryFromState).toHaveBeenCalled()
	})

	it('clears every filter and the scope', () => {
		const vm = sidebar({ selectedSuccessStatus: { value: 1 }, searchTermFilter: 'x', executionTimeTo: '5', selectedUsers: ['a'] })
		vm.clearAllFilters()
		expect(vm.selectedSuccessStatus).toBeNull()
		expect(vm.searchTermFilter).toBe('')
		expect(vm.executionTimeTo).toBe('')
		expect(vm.selectedUsers).toEqual([])
		expect(registerStore.setRegisterItem).toHaveBeenCalledWith(null)
		expect(schemaStore.setSchemaItem).toHaveBeenCalledWith(null)
		expect(searchTrailStore.setSearchTrailFilters).toHaveBeenCalledWith({})
	})

	it('debounces typed filters', () => {
		const vm = sidebar()
		vm.handleSearchTermFilterChange('abc')
		vm.handleExecutionTimeChange()
		vm.handleResultCountChange()
		expect(vm.searchTermFilter).toBe('abc')
		expect(vm.debouncedApplyFilters).toHaveBeenCalledTimes(3)
	})
})

describe('SearchTrailSideBar route query', () => {
	it('writes the scope, selections and ranges', () => {
		registerStore.registerItem = { id: 1 }
		schemaStore.schemaItem = { id: 2 }
		const vm = sidebar({
			selectedSuccessStatus: { value: false },
			selectedUsers: [{ value: 'ann' }, 'bob'],
			searchTermFilter: 'q',
			executionTimeFrom: '5',
			resultCountTo: '9',
		})
		expect(vm.buildQueryFromState()).toMatchObject({
			register: '1',
			schema: '2',
			user: 'ann,bob',
			searchTerm: 'q',
			executionTimeFrom: '5',
			resultCountTo: '9',
		})
		expect(sidebar({ selectedSuccessStatus: { value: true } }).buildQueryFromState().success).toBe('true')
	})

	it('reads the selections and ranges back from the route', () => {
		const vm = sidebar({
			$route: { path: '/search-trails', query: { success: 'true', user: 'ann,bob', searchTerm: 'q', resultCountFrom: '2' } },
			applyFilters: jest.fn(),
			loadActivityData: jest.fn(),
		})
		vm.applyQueryParamsFromRoute()
		expect(vm.selectedSuccessStatus).toEqual(vm.successOptions.find((o) => String(o.value) === 'true'))
		expect(vm.selectedUsers).toEqual([{ label: 'ann', value: 'ann' }, { label: 'bob', value: 'bob' }])
		expect(vm.searchTermFilter).toBe('q')
		expect(vm.resultCountFrom).toBe('2')
		expect(vm.executionTimeFrom).toBe('')
		expect(vm.applyFilters).toHaveBeenCalled()
		expect(vm.loadActivityData).toHaveBeenCalled()
	})

	it('ignores other routes and an unknown success value', () => {
		const elsewhere = sidebar({ $route: { path: '/audit-trails', query: { searchTerm: 'q' } } })
		elsewhere.applyQueryParamsFromRoute()
		expect(elsewhere.searchTermFilter).toBe('')

		const vm = sidebar()
		vm.applySuccessFromRoute(undefined)
		expect(vm.selectedSuccessStatus).toBeNull()
		vm.applySuccessFromRoute('maybe')
		expect(vm.selectedSuccessStatus).toBeNull()
	})
})
