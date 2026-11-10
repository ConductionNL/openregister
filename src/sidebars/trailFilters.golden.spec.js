import AuditTrailSideBar from './logs/AuditTrailSideBar.vue'
import SearchTrailSideBar from './logs/SearchTrailSideBar.vue'
/**
 * Golden record of the store filters the audit trail and search trail
 * sidebars build from their filter state, and of the search trail
 * statistics they show.
 */
import eventBus from '../eventBus.js'

const mockStores = {
	registerStore: { registerItem: null },
	schemaStore: { schemaItem: null },
	searchTrailStore: {
		setSearchTrailFilters: jest.fn(),
		refreshSearchTrailList: jest.fn(),
		getStatistics: jest.fn(),
	},
	auditTrailStore: {
		setAuditTrailFilters: jest.fn(),
		refreshAuditTrailList: jest.fn(),
	},
}

jest.mock(
	'../store/store.js',
	() =>
		new Proxy(
			{},
			{
				get: (_target, name) => mockStores[name] || {},
			},
		),
)
jest.mock('../eventBus.js', () => ({ emit: jest.fn() }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('vue-material-design-icons/ChartLine.vue', () => ({}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/FilterOffOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FilterOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/MagnifyPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/Monitor.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/TrendingUp.vue', () => ({}))

const SCOPES = {
	none: [null, null],
	withIds: [{ id: 7 }, { id: 'abc' }],
	zeroIds: [{ id: 0 }, { id: 0 }],
}

const STATES = {
	empty: {},
	everything: {
		selectedActions: [{ value: 'create' }, { value: 'update' }],
		selectedUsers: [{ value: 'alice' }, { value: 'bob' }],
		selectedSuccessStatus: { value: true },
		dateFrom: '2026-01-02T03:04:05Z',
		dateTo: '2026-02-03',
		objectFilter: 'obj-1',
		showOnlyWithChanges: true,
		searchTermFilter: 'term',
		executionTimeFrom: 10,
		executionTimeTo: '20',
		resultCountFrom: 1,
		resultCountTo: 2,
	},
	falsy: {
		selectedActions: [],
		selectedUsers: [],
		selectedSuccessStatus: { value: false },
		dateFrom: '',
		dateTo: null,
		objectFilter: '',
		showOnlyWithChanges: false,
		searchTermFilter: '',
		executionTimeFrom: 0,
		executionTimeTo: 0,
		resultCountFrom: 0,
		resultCountTo: 0,
	},
	notLists: {
		selectedActions: 'create',
		selectedUsers: { value: 'x', length: 1 },
		showOnlyWithChanges: 'yes',
	},
	oneEach: {
		selectedActions: [{ value: 'delete' }],
		selectedUsers: [{ value: 'carol' }],
		selectedSuccessStatus: { value: 'any' },
		executionTimeTo: 5,
		resultCountFrom: '3',
	},
}

/**
 * Run one filter builder over every scope and state.
 *
 * @param {Function} method The component method
 * @return {object} The recorded calls per case
 */
function recordAll(method) {
	const records = {}
	for (const [scopeName, [register, schema]] of Object.entries(SCOPES)) {
		for (const [stateName, state] of Object.entries(STATES)) {
			jest.clearAllMocks()
			mockStores.registerStore.registerItem = register
			mockStores.schemaStore.schemaItem = schema
			const ctx = { ...state, updateRouteQueryFromState: jest.fn() }
			method.call(ctx)
			records[`${scopeName}/${stateName}`] = JSON.stringify({
				search: mockStores.searchTrailStore.setSearchTrailFilters.mock.calls,
				searchRefresh:
					mockStores.searchTrailStore.refreshSearchTrailList.mock.calls
						.length,
				audit: mockStores.auditTrailStore.setAuditTrailFilters.mock.calls,
				auditRefresh:
					mockStores.auditTrailStore.refreshAuditTrailList.mock.calls
						.length,
				emitted: eventBus.emit.mock.calls,
				route: ctx.updateRouteQueryFromState.mock.calls.length,
			})
		}
	}
	return records
}

describe('sidebar store filters (golden)', () => {
	it('SearchTrailSideBar.applyFilters matches the recording', () => {
		expect(recordAll(SearchTrailSideBar.methods.applyFilters)).toMatchSnapshot()
	})

	it('AuditTrailSideBar.applyFiltersToStore matches the recording', () => {
		expect(
			recordAll(AuditTrailSideBar.methods.applyFiltersToStore),
		).toMatchSnapshot()
	})

	it('throws on a register without an id, as before', () => {
		mockStores.registerStore.registerItem = {}
		mockStores.schemaStore.schemaItem = null
		const ctx = { updateRouteQueryFromState: jest.fn() }
		expect(() => SearchTrailSideBar.methods.applyFilters.call(ctx)).toThrow(
			TypeError,
		)
		expect(() =>
			AuditTrailSideBar.methods.applyFiltersToStore.call(ctx),
		).toThrow(TypeError)
	})
})

const STATISTICS = {
	full: {
		total: 10,
		totalResults: 200,
		averageResultsPerSearch: 20.6,
		averageExecutionTime: 12.4,
		successRate: 0.9,
		uniqueSearchTerms: 5,
		uniqueUsers: 3,
		uniqueOrganizations: 2,
		avgSearchesPerSession: 1.5,
		avgObjectViewsPerSession: 2.5,
		queryComplexity: { simple: 1, medium: 2, complex: 3 },
	},
	empty: {},
	zeros: { total: 0, averageResultsPerSearch: 0, queryComplexity: null },
	strings: { total: '4', averageExecutionTime: '7.5' },
}

describe('SearchTrailSideBar.loadStatistics (golden)', () => {
	beforeEach(() => jest.spyOn(console, 'error').mockImplementation(() => {}))
	afterEach(() => jest.restoreAllMocks())

	it.each(Object.keys(STATISTICS))('shows %s', async (name) => {
		mockStores.searchTrailStore.getStatistics.mockResolvedValue(STATISTICS[name])
		const ctx = { totalSearchTrails: 99, queryComplexity: 'old' }
		await SearchTrailSideBar.methods.loadStatistics.call(ctx)
		expect(JSON.stringify(ctx)).toMatchSnapshot()
	})

	it.each([
		['a failing store', () => Promise.reject(new Error('down'))],
		['no statistics', () => Promise.resolve(null)],
	])('falls back to zeros on %s', async (name, answer) => {
		mockStores.searchTrailStore.getStatistics.mockImplementation(answer)
		const ctx = { totalSearchTrails: 99, queryComplexity: 'old' }
		await SearchTrailSideBar.methods.loadStatistics.call(ctx)
		expect(JSON.stringify(ctx)).toMatchSnapshot()
	})
})
