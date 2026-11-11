/**
 * The initial state of the audit and search trail sidebars, pinned so the
 * way it is built can change without the state changing.
 *
 * @spec exclude Client-side initial component state.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import AuditTrailSideBar from './AuditTrailSideBar.vue'
import SearchTrailSideBar from './SearchTrailSideBar.vue'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `[${text}]`,
	translatePlural: (app, one) => one,
}))
jest.mock('../../eventBus.js', () => ({
	__esModule: true,
	default: { emit: jest.fn(), on: jest.fn(), off: jest.fn() },
}))
jest.mock('vue-material-design-icons/ChartLine.vue', () => ({}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/FilterOffOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FilterOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/MagnifyPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/Monitor.vue', () => ({}))
jest.mock('vue-material-design-icons/TrendingUp.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountCircle.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	auditTrailStore: {},
	navigationStore: {},
	registerStore: {},
	schemaStore: {},
	searchTrailStore: {},
}))

describe('AuditTrailSideBar initial state', () => {
	it('starts with the action options, empty filters and zero statistics', () => {
		expect(AuditTrailSideBar.data()).toEqual({
			actionOptions: [
				{ label: '[Create]', value: 'create' },
				{ label: '[Read]', value: 'read' },
				{ label: '[Update]', value: 'update' },
				{ label: '[Delete]', value: 'delete' },
			],
			activeTab: 'filters-tab',
			selectedActions: [],
			objectFilter: '',
			showOnlyWithChanges: false,
			filteredCount: 0,
			totalAuditTrails: 0,
			createCount: 0,
			updateCount: 0,
			deleteCount: 0,
			actionDistribution: [],
			topObjects: [],
			filterTimeout: null,
		})
	})

	it('gives every instance its own arrays', () => {
		const first = AuditTrailSideBar.data()
		const second = AuditTrailSideBar.data()
		expect(first.actionOptions).not.toBe(second.actionOptions)
		expect(first.actionOptions[0]).not.toBe(second.actionOptions[0])
		expect(first.selectedActions).not.toBe(second.selectedActions)
		expect(first.topObjects).not.toBe(second.topObjects)
	})
})

describe('SearchTrailSideBar initial state', () => {
	it('starts with the options, empty filters and zero statistics', () => {
		expect(SearchTrailSideBar.data()).toEqual({
			successOptions: [
				{ label: '[Successful]', value: 'true' },
				{ label: '[Failed]', value: 'false' },
			],
			activityPeriodOptions: [
				{ label: '[Hourly]', value: 'hourly' },
				{ label: '[Daily]', value: 'daily' },
				{ label: '[Weekly]', value: 'weekly' },
				{ label: '[Monthly]', value: 'monthly' },
			],
			activeTab: 'filters-tab',
			selectedSuccessStatus: null,
			selectedActivityPeriod: { value: 'daily', label: 'Daily' },
			searchTermFilter: '',
			executionTimeFrom: '',
			executionTimeTo: '',
			resultCountFrom: '',
			resultCountTo: '',
			filteredCount: 0,
			totalSearchTrails: 0,
			totalResults: 0,
			averageResultsPerSearch: 0,
			averageExecutionTime: 0,
			successRate: 0,
			uniqueSearchTerms: 0,
			uniqueUsers: 0,
			uniqueOrganizations: 0,
			avgSearchesPerSession: 0,
			avgObjectViewsPerSession: 0,
			queryComplexity: { simple: 0, medium: 0, complex: 0 },
			popularTerms: [],
			registerSchemaStats: [],
			userAgentStats: [],
			currentActivityData: [],
			filterTimeout: null,
		})
	})

	it('gives every instance its own objects', () => {
		const first = SearchTrailSideBar.data()
		const second = SearchTrailSideBar.data()
		expect(first.successOptions).not.toBe(second.successOptions)
		expect(first.activityPeriodOptions).not.toBe(second.activityPeriodOptions)
		expect(first.selectedActivityPeriod).not.toBe(second.selectedActivityPeriod)
		expect(first.queryComplexity).not.toBe(second.queryComplexity)
		expect(first.popularTerms).not.toBe(second.popularTerms)
	})
})
