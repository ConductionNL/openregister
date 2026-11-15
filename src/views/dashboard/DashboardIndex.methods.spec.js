/**
 * DashboardIndex — KPI values, the range picker, the search-trail load and
 * the debounced live refetch, exercised on the options object against a
 * synthetic `this`, with the stores mocked.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DashboardIndex from './DashboardIndex.vue'
import { dashboardStore, registerStore, schemaStore, searchTrailStore } from '../../store/store.js'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/CalendarRange.vue', () => ({}))
jest.mock('vue-material-design-icons/CubeOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FileTreeOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/History.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	dashboardStore: {},
	registerStore: {},
	schemaStore: {},
	objectStore: {},
	searchTrailStore: {},
}))

const computedCall = (key, ctx) => DashboardIndex.computed[key].call(ctx)

/**
 * A view context with the view's methods bound to it.
 *
 * @param {object} state The state to start from
 * @return {object} The context
 */
function view(state = {}) {
	const ctx = {
		refreshing: false,
		kpiRange: { from: null, till: null, preset: 'all' },
		liveHandle: null,
		liveEpoch: 0,
		liveRefetchTimer: null,
		...state,
	}
	for (const [name, method] of Object.entries(DashboardIndex.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

/**
 * Empty every mocked store.
 *
 * @return {void}
 */
function resetStores() {
	for (const store of [dashboardStore, registerStore, schemaStore, searchTrailStore]) {
		for (const key of Object.keys(store)) delete store[key]
	}
}

beforeEach(() => {
	resetStores()
	localStorage.clear()
})

describe('DashboardIndex — KPI values', () => {
	it('is loading while the dashboard or the search statistics load', () => {
		Object.assign(dashboardStore, { loading: false })
		Object.assign(searchTrailStore, { statisticsLoading: true })
		expect(computedCall('isLoading', {})).toBe(true)

		searchTrailStore.statisticsLoading = false
		expect(computedCall('isLoading', {})).toBe(false)
	})

	it('scopes the live collection to the register and schema the sidebar picked', () => {
		Object.assign(registerStore, { registerItem: { id: 5 } })
		Object.assign(schemaStore, { schemaItem: { id: 9 } })
		expect(computedCall('liveCollectionType', {})).toBe('5-9')

		schemaStore.schemaItem = null
		expect(computedCall('liveCollectionType', {})).toBe('')
	})

	it('reads the totals from the stores, zero when absent', () => {
		Object.assign(dashboardStore, { getSystemTotals: { stats: { objects: { total: 12 }, logs: { total: 30 } } } })
		Object.assign(registerStore, { registerList: [{}, {}] })
		Object.assign(schemaStore, { schemaList: [{}] })
		Object.assign(searchTrailStore, { statistics: { total: 4 } })

		expect(computedCall('totalObjects', {})).toBe(12)
		expect(computedCall('totalEvents', {})).toBe(30)
		expect(computedCall('totalRegisters', {})).toBe(2)
		expect(computedCall('totalSchemas', {})).toBe(1)
		expect(computedCall('totalSearches', {})).toBe(4)

		resetStores()
		Object.assign(searchTrailStore, { statistics: {} })
		expect(computedCall('totalObjects', {})).toBe(0)
		expect(computedCall('totalEvents', {})).toBe(0)
		expect(computedCall('totalRegisters', {})).toBe(0)
		expect(computedCall('totalSchemas', {})).toBe(0)
		expect(computedCall('totalSearches', {})).toBe(0)
	})

	it('has data once there are searches, register rows or objects', () => {
		Object.assign(searchTrailStore, { statistics: { total: 0 } })
		expect(computedCall('hasData', { registerData: [], totalObjects: 0 })).toBe(false)
		expect(computedCall('hasData', { registerData: [{}], totalObjects: 0 })).toBe(true)
		expect(computedCall('hasData', { registerData: [], totalObjects: 1 })).toBe(true)
		searchTrailStore.statistics.total = 2
		expect(computedCall('hasData', { registerData: [], totalObjects: 0 })).toBe(true)
	})

	it('counts every event for all time, and sums the range chart otherwise', () => {
		Object.assign(dashboardStore, {
			chartData: { auditTrailActions: { series: [{ data: [1, 2, null] }, { data: [4] }, { data: 'x' }] } },
		})

		expect(computedCall('totalEventsInRange', { kpiRange: { preset: 'all' }, totalEvents: 99 })).toBe(99)
		expect(computedCall('totalEventsInRange', { kpiRange: { preset: '7d' }, totalEvents: 99 })).toBe(7)

		dashboardStore.chartData = {}
		expect(computedCall('totalEventsInRange', { kpiRange: { preset: '7d' }, totalEvents: 99 })).toBe(0)
	})

	it('says how many registers the objects span, nothing without registers', () => {
		expect(computedCall('objectsSub', { totalRegisters: 3 })).toBe('across 3 registers')
		expect(computedCall('objectsSub', { totalRegisters: 0 })).toBe('')
	})

	it('gives the search success rate as a whole percentage, nothing without searches', () => {
		Object.assign(searchTrailStore, { statistics: { successRate: 0.876 } })
		expect(computedCall('searchesSub', { totalSearches: 5 })).toBe('88% success rate')
		expect(computedCall('searchesSub', { totalSearches: 0 })).toBe('')
	})
})

describe('DashboardIndex — search trail and range', () => {
	beforeEach(() => {
		Object.assign(searchTrailStore, {
			fetchStatistics: jest.fn().mockResolvedValue(),
			fetchPopularTerms: jest.fn().mockResolvedValue(),
			setStatistics: jest.fn(),
			setPopularTerms: jest.fn(),
			setActivity: jest.fn(),
		})
		Object.assign(dashboardStore, { setDateRange: jest.fn() })
	})

	it('loads the statistics for the range, then the popular terms', async () => {
		const ctx = view({ kpiRange: { from: '2026-11-01', till: '2026-11-14', preset: '30d' } })

		await ctx.loadSearchTrailData()

		expect(searchTrailStore.fetchStatistics).toHaveBeenCalledWith({ from: '2026-11-01', to: '2026-11-14' })
		expect(searchTrailStore.fetchPopularTerms).toHaveBeenCalledTimes(1)
		expect(searchTrailStore.setStatistics).not.toHaveBeenCalled()
	})

	it('falls back to empty search data when the statistics fail', async () => {
		const error = jest.spyOn(console, 'error').mockImplementation(() => {})
		searchTrailStore.fetchStatistics.mockRejectedValue(new Error('down'))

		await view().loadSearchTrailData()

		expect(searchTrailStore.fetchPopularTerms).not.toHaveBeenCalled()
		expect(searchTrailStore.setStatistics).toHaveBeenCalledWith(expect.objectContaining({ total_searches: 0, success_rate: 0 }))
		expect(searchTrailStore.setPopularTerms).toHaveBeenCalledWith({ results: [] })
		expect(searchTrailStore.setActivity).toHaveBeenCalledWith({ daily: { activity: [] } })
		error.mockRestore()
	})

	it('picks a range: applies it, remembers it, and refetches events and searches', () => {
		const ctx = view()
		ctx.loadSearchTrailData = jest.fn()

		ctx.pickRange('7d')

		expect(ctx.kpiRange.preset).toBe('7d')
		expect(ctx.kpiRange.from).not.toBeNull()
		expect(JSON.parse(localStorage.getItem(Object.keys(localStorage)[0]))).toEqual({ preset: '7d' })
		expect(dashboardStore.setDateRange).toHaveBeenCalledWith(ctx.kpiRange.from, ctx.kpiRange.till)
		expect(ctx.loadSearchTrailData).toHaveBeenCalledTimes(1)
	})

	it('restores a remembered range, and keeps the default without one', () => {
		const fresh = view()
		fresh.loadPersistedRange()
		expect(fresh.kpiRange).toEqual({ from: null, till: null, preset: 'all' })

		const picker = view()
		picker.loadSearchTrailData = jest.fn()
		picker.pickRange('30d')

		const later = view()
		later.loadPersistedRange()
		expect(later.kpiRange.preset).toBe('30d')
	})
})

describe('DashboardIndex — refresh and live refetch', () => {
	beforeEach(() => {
		Object.assign(dashboardStore, {
			preload: jest.fn().mockResolvedValue(),
			fetchAllChartData: jest.fn().mockResolvedValue(),
			fetchRegisters: jest.fn().mockResolvedValue(),
			fetchAllStatistics: jest.fn().mockResolvedValue(),
		})
		Object.assign(registerStore, { refreshRegisterList: jest.fn().mockResolvedValue() })
		Object.assign(schemaStore, { refreshSchemaList: jest.fn().mockResolvedValue() })
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	it('refreshes every source of the dashboard and ends not refreshing', async () => {
		const ctx = view()
		ctx.loadSearchTrailData = jest.fn().mockResolvedValue()

		const done = ctx.refreshDashboard()
		expect(ctx.refreshing).toBe(true)
		await done

		expect(dashboardStore.preload).toHaveBeenCalledTimes(1)
		expect(dashboardStore.fetchAllChartData).toHaveBeenCalledTimes(1)
		expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(1)
		expect(schemaStore.refreshSchemaList).toHaveBeenCalledTimes(1)
		expect(ctx.loadSearchTrailData).toHaveBeenCalledTimes(1)
		expect(ctx.refreshing).toBe(false)
	})

	it('ends not refreshing when a refresh step fails', async () => {
		const error = jest.spyOn(console, 'error').mockImplementation(() => {})
		dashboardStore.fetchAllChartData.mockRejectedValue(new Error('down'))
		const ctx = view()
		ctx.loadSearchTrailData = jest.fn()

		await ctx.refreshDashboard()

		expect(registerStore.refreshRegisterList).not.toHaveBeenCalled()
		expect(ctx.refreshing).toBe(false)
		error.mockRestore()
	})

	it('refetches the live widgets, and only warns when that fails', async () => {
		await view().refetchLiveWidgets()
		expect(dashboardStore.fetchRegisters).toHaveBeenCalledTimes(1)
		expect(dashboardStore.fetchAllStatistics).toHaveBeenCalledTimes(1)

		const warn = jest.spyOn(console, 'warn').mockImplementation(() => {})
		dashboardStore.fetchRegisters.mockRejectedValue(new Error('offline'))
		await expect(view().refetchLiveWidgets()).resolves.toBeUndefined()
		expect(warn).toHaveBeenCalledWith('[DashboardIndex] live refetch failed:', 'offline')
		warn.mockRestore()
	})

	it('coalesces a burst of live events into one refetch after the debounce', () => {
		jest.useFakeTimers()
		const ctx = view({ liveHandle: 'h' })
		ctx.refetchLiveWidgets = jest.fn()

		ctx.scheduleLiveRefetch()
		ctx.scheduleLiveRefetch()
		jest.advanceTimersByTime(749)
		expect(ctx.refetchLiveWidgets).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)

		expect(ctx.refetchLiveWidgets).toHaveBeenCalledTimes(1)
		expect(ctx.liveRefetchTimer).toBeNull()
	})

	it('skips the refetch when the subscription was released meanwhile', () => {
		jest.useFakeTimers()
		const ctx = view({ liveHandle: 'h' })
		ctx.refetchLiveWidgets = jest.fn()

		ctx.scheduleLiveRefetch()
		ctx.liveEpoch += 1
		jest.advanceTimersByTime(750)

		expect(ctx.refetchLiveWidgets).not.toHaveBeenCalled()
	})

	it('schedules a refetch on a live event only while subscribed', () => {
		const subscribed = { liveHandle: 'h', scheduleLiveRefetch: jest.fn() }
		DashboardIndex.watch.liveLastEventAt.call(subscribed)
		expect(subscribed.scheduleLiveRefetch).toHaveBeenCalledTimes(1)

		const unsubscribed = { liveHandle: null, scheduleLiveRefetch: jest.fn() }
		DashboardIndex.watch.liveLastEventAt.call(unsubscribed)
		expect(unsubscribed.scheduleLiveRefetch).not.toHaveBeenCalled()
	})
})
