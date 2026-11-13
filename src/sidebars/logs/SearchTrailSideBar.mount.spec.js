/**
 * The search trail sidebar mounted through its own setup: the statistics,
 * complexity bars, popular terms, register and schema usage, activity and
 * user agents it renders, and how its filter controls, the activity period,
 * the scope tab and the open switch reach the stores and the route.
 *
 * @spec exclude Client-side search trail sidebar markup and event wiring.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import SearchTrailSideBar from './SearchTrailSideBar.vue'
import eventBus from '../../eventBus.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => (vars ? text.replace('{count}', vars.count) : text),
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcAppSidebar: mockStub('NcAppSidebar'),
	NcAppSidebarTab: mockStub('NcAppSidebarTab'),
	NcLoadingIcon: mockStub('NcLoadingIcon'),
	NcSelect: mockStub('NcSelect'),
	NcTextField: mockStub('NcTextField'),
}))
jest.mock('../../eventBus.js', () => ({
	__esModule: true,
	default: { emit: jest.fn(), on: jest.fn(), off: jest.fn() },
}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({ __esModule: true, default: mockStub('DatabaseOutline') }))
jest.mock('vue-material-design-icons/MagnifyPlus.vue', () => ({ __esModule: true, default: mockStub('MagnifyPlus') }))
jest.mock('vue-material-design-icons/Monitor.vue', () => ({ __esModule: true, default: mockStub('Monitor') }))
jest.mock('vue-material-design-icons/TrendingUp.vue', () => ({ __esModule: true, default: mockStub('TrendingUp') }))
jest.mock('../../components/shared/CountListItem.vue', () => ({ __esModule: true, default: mockStub('CountListItem') }))
jest.mock('../../components/shared/SidebarStatCard.vue', () => ({ __esModule: true, default: mockStub('SidebarStatCard') }))
jest.mock('../../components/shared/StatisticsSidebarTab.vue', () => ({ __esModule: true, default: mockStub('StatisticsSidebarTab') }))
jest.mock('./TrailUserDateFields.vue', () => ({ __esModule: true, default: mockStub('TrailUserDateFields') }))
jest.mock('../ScopeFiltersTab.vue', () => ({ __esModule: true, default: mockStub('ScopeFiltersTab') }))
jest.mock('../SidebarFilterSelect.vue', () => ({ __esModule: true, default: mockStub('SidebarFilterSelect') }))
jest.mock('../../store/store.js', () => ({
	auditTrailStore: {},
	navigationStore: {},
	registerStore: {},
	schemaStore: {},
	searchTrailStore: {},
}))

const stores = jest.requireMock('../../store/store.js')

/**
 * A stand-in that renders its tag, its plain attributes and every slot, a
 * scoped slot filled with a sample option.
 *
 * @param {string} name The component name
 * @return {object} The stand-in
 */
function mockStub(name) {
	return {
		name,
		inheritAttrs: false,
		render() {
			const { h } = require('vue')
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs).filter(([key, value]) => !/^on[A-Z]/.test(key) && typeof value !== 'object'),
			)
			return h(`x-${name}`, attrs, Object.values(this.$slots).map((slot) => slot({ label: 'Successful', value: 'true' })))
		},
	}
}

/**
 * The sidebar with its own setup run, and its components bound from its
 * options: under jest the compiled bindings of an SFC with a setup block
 * resolve imported components to undefined.
 *
 * @param {object} component The sidebar
 * @return {object} The sidebar with its components bound
 */
function withRealSetup(component) {
	return {
		...component,
		setup: (props, context) => ({ ...component.setup(props, context), ...component.components }),
	}
}

let router

/**
 * Mount the sidebar on the search trail route and let its loads settle.
 *
 * @return {Promise<object>} The wrapper
 */
async function mounted() {
	const wrapper = mount(withRealSetup(SearchTrailSideBar), {
		global: { mocks: { $route: { path: '/search-trails', query: {} }, $router: router } },
	})
	await flushPromises()
	return wrapper
}

/**
 * The query of the last route replacement.
 *
 * @return {object} The query
 */
function lastQuery() {
	return router.replace.mock.calls.at(-1)[0].query
}

/**
 * The text field with the given id.
 *
 * @param {object} wrapper The sidebar
 * @param {string} id The field id
 * @return {object} The field
 */
function field(wrapper, id) {
	return wrapper.findAllComponents({ name: 'NcTextField' }).find((node) => node.attributes('id') === id)
}

/**
 * The name and subname of each count item in a section.
 *
 * @param {object} wrapper The sidebar
 * @param {string} section The section class
 * @return {Array<Array<string>>} The pairs
 */
function counts(wrapper, section) {
	return wrapper.findAll(`.${section} x-CountListItem`).map((item) => [item.attributes('name'), item.attributes('subname')])
}

const ACTIVITY = [{ period: '2026-03-02T00:00:00Z', searches: 4 }]

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
	router = { replace: jest.fn() }
	stores.searchTrailStore = {
		activityLoading: false,
		searchTrailList: [{ id: 1, user: 'alice' }],
		refreshSearchTrailList: jest.fn().mockResolvedValue(undefined),
		setSearchTrailFilters: jest.fn(),
		getStatistics: jest.fn().mockResolvedValue({
			total: 12,
			totalResults: 300,
			averageResultsPerSearch: 24.6,
			averageExecutionTime: 41.2,
			successRate: 0.875,
			uniqueSearchTerms: 5,
			avgSearchesPerSession: 2,
			avgObjectViewsPerSession: 3,
			queryComplexity: { simple: 6, medium: 3, complex: 3 },
		}),
		getPopularTerms: jest.fn().mockResolvedValue([{ term: 'cats', count: 4 }]),
		getRegisterSchemaStats: jest.fn().mockResolvedValue([{ register: 1, schema: 2, count: 3 }, { register: 8, schema: 9, count: 1 }]),
		getUserAgentStats: jest.fn().mockResolvedValue([
			{ browser_info: { browser: 'Firefox', version: '140' }, count: 2 },
			{ browser_info: { browser: 'Safari' }, count: 1 },
			{ user_agent: 'curl/8', count: 1 },
			{ count: 1 },
		]),
		getActivity: jest.fn().mockResolvedValue([]),
	}
	stores.navigationStore = { sidebarState: { searchTrail: true }, setSidebarState: jest.fn() }
	stores.registerStore = {
		registerItem: null,
		registerList: [{ id: 1, title: 'Pets' }],
		refreshRegisterList: jest.fn(),
		setRegisterItem: jest.fn(),
	}
	stores.schemaStore = {
		schemaItem: null,
		schemaList: [{ id: 2, title: 'Cat' }],
		refreshSchemaList: jest.fn(),
		setSchemaItem: jest.fn(),
	}
})

describe('SearchTrailSideBar mounted', () => {
	it('renders the statistics and the complexity bars', async () => {
		const wrapper = await mounted()

		expect(wrapper.findAll('x-SidebarStatCard').map((card) => card.attributes('value'))).toEqual([
			'12', '300', '25', '41ms', '87.5%', '5', '2', '3',
		])
		const bars = wrapper.findAll('.complexityBar')
		expect(bars.map((bar) => bar.find('.complexityLabel').text())).toEqual(['Simple6', 'Medium3', 'Complex3'])
		expect(bars.map((bar) => bar.find('.complexityProgressBar').attributes('style'))).toEqual([
			'width: 50%;',
			'width: 25%;',
			'width: 25%;',
		])
		expect(bars[0].find('.complexity-label-with-tooltip').attributes('title')).toContain('Simple queries')
	})

	it('renders popular terms, register and schema usage, and user agents', async () => {
		const wrapper = await mounted()

		expect(counts(wrapper, 'popularTermsSection')).toEqual([['cats', '4 searches']])
		expect(counts(wrapper, 'registerSchemaSection')).toEqual([
			['Pets / Cat', '3 searches'],
			['Register 8 / Schema 9', '1 searches'],
		])
		expect(counts(wrapper, 'userAgentSection')).toEqual([
			['Firefox 140', '2 searches'],
			['Safari', '1 searches'],
			['curl/8', '1 searches'],
			['Unknown Browser', '1 searches'],
		])
		expect(wrapper.find('.popularTermsSection x-MagnifyPlus').exists()).toBe(true)
		expect(wrapper.find('.registerSchemaSection x-DatabaseOutline').exists()).toBe(true)
		expect(wrapper.find('.userAgentSection x-Monitor').exists()).toBe(true)
		expect(wrapper.find('x-sidebarfilterselect .statusOption.true').text()).toBe('Successful')
		expect(eventBus.on).toHaveBeenCalledWith('search-trail-filtered-count', expect.any(Function))
	})

	it('shows the activity of the picked period, a loader while it loads and a note without any', async () => {
		const wrapper = await mounted()

		expect(wrapper.find('.noActivityData').text()).toBe('No activity data available')

		stores.searchTrailStore.getActivity.mockResolvedValue(ACTIVITY)
		const select = wrapper.findComponent({ name: 'NcSelect' })
		select.vm.$emit('update:modelValue', { value: 'weekly', label: 'Weekly' })
		await flushPromises()

		expect(stores.searchTrailStore.getActivity).toHaveBeenLastCalledWith('weekly')
		expect(wrapper.find('.activityPeriod').text()).toBe(`Week of ${new Date(ACTIVITY[0].period).toLocaleDateString()}`)
		expect(wrapper.find('.activityCount').text()).toBe('4 searches')

		wrapper.unmount()
		stores.searchTrailStore.activityLoading = true
		const loading = await mounted()

		expect(loading.find('.loadingSpinner x-ncloadingicon').exists()).toBe(true)
		expect(loading.find('.activityList').exists()).toBe(false)
	})

	it('reports the open switch to the navigation store', async () => {
		const wrapper = await mounted()

		wrapper.findComponent({ name: 'NcAppSidebar' }).vm.$emit('update:open', false)

		expect(stores.navigationStore.setSidebarState).toHaveBeenCalledWith('searchTrail', false)
	})

	it('filters on the picked status and users at once', async () => {
		const wrapper = await mounted()

		wrapper.findComponent({ name: 'SidebarFilterSelect' }).vm.$emit('update:modelValue', { value: 'false', label: 'Failed' })
		await flushPromises()

		expect(stores.searchTrailStore.setSearchTrailFilters).toHaveBeenLastCalledWith({ success: 'false' })
		expect(lastQuery()).toEqual({ success: 'false' })

		const users = wrapper.findComponent({ name: 'TrailUserDateFields' })
		users.vm.$emit('update:users', [{ value: 'alice' }])
		users.vm.$emit('change')
		await flushPromises()

		expect(stores.searchTrailStore.setSearchTrailFilters).toHaveBeenLastCalledWith({ success: 'false', user: 'alice' })
		expect(eventBus.emit).toHaveBeenLastCalledWith('search-trail-filters-changed', { success: 'false', user: 'alice' })
		expect(lastQuery()).toEqual({ success: 'false', user: 'alice' })
	})

	it('filters on the term and the ranges only after typing pauses', async () => {
		jest.useFakeTimers()
		try {
			const wrapper = await mounted()
			stores.searchTrailStore.setSearchTrailFilters.mockClear()

			field(wrapper, 'searchTermFilter').vm.$emit('update:modelValue', 'cats')
			field(wrapper, 'executionTimeFrom').vm.$emit('update:modelValue', '10')
			field(wrapper, 'executionTimeTo').vm.$emit('update:modelValue', '90')
			field(wrapper, 'resultCountFrom').vm.$emit('update:modelValue', '1')
			field(wrapper, 'resultCountTo').vm.$emit('update:modelValue', '50')
			await flushPromises()
			expect(stores.searchTrailStore.setSearchTrailFilters).not.toHaveBeenCalled()

			jest.advanceTimersByTime(500)

			expect(stores.searchTrailStore.setSearchTrailFilters).toHaveBeenCalledTimes(1)
			expect(stores.searchTrailStore.setSearchTrailFilters).toHaveBeenCalledWith({
				searchTerm: 'cats',
				executionTimeFrom: '10',
				executionTimeTo: '90',
				resultCountFrom: '1',
				resultCountTo: '50',
			})
		} finally {
			jest.useRealTimers()
		}
	})

	it('scopes to a picked register and clears every filter', async () => {
		const wrapper = await mounted()
		const scope = wrapper.findComponent({ name: 'ScopeFiltersTab' })

		scope.vm.$emit('registerChange', { id: 1 })
		expect(stores.registerStore.setRegisterItem).toHaveBeenCalledWith({ id: 1 })
		expect(stores.schemaStore.setSchemaItem).toHaveBeenCalledWith(null)

		await wrapper.setData({ searchTermFilter: 'cats', executionTimeFrom: '5' })
		scope.vm.$emit('clear')
		await flushPromises()

		expect(wrapper.vm.searchTermFilter).toBe('')
		expect(wrapper.vm.executionTimeFrom).toBe('')
		expect(stores.searchTrailStore.setSearchTrailFilters).toHaveBeenLastCalledWith({})
	})

	it('stops listening for the filtered count when it goes away', async () => {
		const wrapper = await mounted()

		wrapper.unmount()

		expect(eventBus.off).toHaveBeenCalledWith('search-trail-filtered-count')
	})
})
