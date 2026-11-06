import { flushPromises, mount } from '@vue/test-utils'
/**
 * Golden record of the dashboard page: its rendered DOM in each data state,
 * what mounting fetches, and what the range picker, refresh button and table
 * rows do.
 *
 * @spec exclude Golden record of client-side dashboard markup and actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DashboardIndex from './DashboardIndex.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		vars ? `${text} ${JSON.stringify(vars)}` : text,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcActionButton: mockStub('NcActionButton'),
	NcActions: mockStub('NcActions'),
	NcAppContent: mockStub('NcAppContent'),
	NcButton: mockStub('NcButton'),
	NcLoadingIcon: mockStub('NcLoadingIcon'),
}))
jest.mock('@conduction/nextcloud-vue', () => ({
	__esModule: true,
	CnDashboardPage: mockStub('CnDashboardPage'),
	CnDataTable: mockStub('CnDataTable'),
}))
jest.mock('vue3-apexcharts', () => mockModule('Apexchart'))
jest.mock('vue-material-design-icons/CalendarRange.vue', () =>
	mockModule('CalendarRange'),
)
jest.mock('vue-material-design-icons/CubeOutline.vue', () =>
	mockModule('CubeOutline'),
)
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () =>
	mockModule('DatabaseOutline'),
)
jest.mock('vue-material-design-icons/FileTreeOutline.vue', () =>
	mockModule('FileTreeOutline'),
)
jest.mock('vue-material-design-icons/History.vue', () => mockModule('History'))
jest.mock('vue-material-design-icons/Magnify.vue', () => mockModule('Magnify'))
jest.mock('vue-material-design-icons/Refresh.vue', () => mockModule('Refresh'))
jest.mock('../../store/store.js', () => ({
	dashboardStore: {},
	objectStore: {},
	registerStore: {},
	schemaStore: {},
	searchTrailStore: {},
}))

const stores = jest.requireMock('../../store/store.js')
const { dashboardStore, registerStore, schemaStore, searchTrailStore } = stores

const NOW = new Date('2026-03-15T12:00:00Z')
const RANGE_KEY = 'openregister:dashboard:kpi-range'

/**
 * A stand-in component module.
 *
 * @param {string} name The component name
 * @return {object} The module
 */
function mockModule(name) {
	return { __esModule: true, default: mockStub(name) }
}

/**
 * A stand-in for a component: its tag, attributes as text, and slots.
 *
 * @param {string} name The component name
 * @return {object} The stub
 */
function mockStub(name) {
	const { h: render } = require('vue')
	const text = (value) =>
		typeof value === 'object' ? JSON.stringify(value) : value
	return {
		name,
		inheritAttrs: false,
		setup(props, { attrs, slots }) {
			return () => {
				const listeners = Object.keys(attrs).filter((key) =>
					/^on[A-Z]/.test(key),
				)
				const plain = Object.fromEntries(
					Object.entries(attrs)
						.filter(([key]) => !listeners.includes(key))
						.map(([key, value]) => [key, text(value)]),
				)
				if (listeners.length) plain['data-on'] = listeners.join(' ')
				return render(
					`x-${name}`,
					plain,
					Object.entries(slots).map(([slot, fill]) =>
						render(`slot-${slot}`, fill({})),
					),
				)
			}
		},
	}
}

/**
 * The markup without scoped-style ids, comment nodes and the line break an
 * empty v-if leaves after a span.
 *
 * @param {string} html The markup
 * @return {string} The markup
 */
function normalised(html) {
	return html
		.replace(/ data-v-[a-f0-9]+(="")?/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/<\/span>\s+<\/div>/g, '</span></div>')
		.split('\n')
		.filter((line) => line.trim() !== '')
		.join('\n')
}

/**
 * The page with its setup bindings supplied. Under jest the compiled bindings
 * of an SFC with both a setup and a plain script resolve the library components
 * to undefined, so the page would render nothing.
 *
 * @return {object} The page with stand-in bindings
 */
function dashboardPage() {
	const { translate, translatePlural } = jest.requireMock('@nextcloud/l10n')
	const library = jest.requireMock('@nextcloud/vue')
	const packageParts = jest.requireMock('@conduction/nextcloud-vue')
	const bindings = {
		...DashboardIndex.components,
		...library,
		...packageParts,
		...stores,
		Apexchart: mockStub('Apexchart'),
		CalendarRange: mockStub('CalendarRange'),
		CubeOutline: mockStub('CubeOutline'),
		DatabaseOutline: mockStub('DatabaseOutline'),
		FileTreeOutline: mockStub('FileTreeOutline'),
		History: mockStub('History'),
		Magnify: mockStub('Magnify'),
		Refresh: mockStub('Refresh'),
		n: translatePlural,
		t: translate,
	}
	return { ...DashboardIndex, setup: () => bindings }
}

/**
 * Empty stores with every fetch logged.
 *
 * @return {Array} The log of store calls
 */
function emptyStores() {
	const log = []
	const fn = (name, reply) =>
		jest.fn((...args) => {
			log.push([name, ...args])
			return reply?.(...args)
		})
	Object.assign(dashboardStore, {
		loading: false,
		getSystemTotals: undefined,
		chartData: {},
		preload: fn('preload'),
		fetchAllChartData: fn('fetchAllChartData'),
		fetchRegisters: fn('fetchRegisters'),
		fetchAllStatistics: fn('fetchAllStatistics'),
		setDateRange: fn('setDateRange'),
	})
	Object.assign(registerStore, {
		registerList: [],
		registerItem: null,
		refreshRegisterList: fn('refreshRegisterList'),
	})
	Object.assign(schemaStore, {
		schemaList: [],
		schemaItem: null,
		refreshSchemaList: fn('refreshSchemaList'),
	})
	Object.assign(searchTrailStore, {
		statistics: {},
		statisticsLoading: false,
		popularTerms: [],
		fetchStatistics: fn('fetchStatistics'),
		fetchPopularTerms: fn('fetchPopularTerms'),
		setStatistics: fn('setStatistics'),
		setPopularTerms: fn('setPopularTerms'),
		setActivity: fn('setActivity'),
	})
	return log
}

/**
 * Fill the stores with a dashboard that has data of every kind.
 */
function filledStores() {
	dashboardStore.getSystemTotals = {
		stats: { objects: { total: 12345 }, logs: { total: 678 } },
	}
	dashboardStore.chartData = {
		objectsByRegister: { labels: ['Alpha', 'Beta'], series: [3000, 0] },
		objectsBySchema: { labels: ['One', 'Two', 'Three'], series: [1, 2] },
		auditTrailActions: {
			series: [{ data: [1, 2, null] }, { data: 'odd' }, { data: [4] }],
		},
	}
	registerStore.registerList = [
		{ id: 11, title: 'Alpha' },
		{ id: 12, name: 'Beta' },
		{ title: 'Gamma' },
	]
	schemaStore.schemaList = [
		{ id: 21, title: 'One' },
		{ id: 22, name: 'Two' },
		{ title: 'Three' },
	]
	searchTrailStore.statistics = { total: 2500, successRate: 0.876 }
	searchTrailStore.popularTerms = [
		{ term: 'water', count: 5, effectiveness: 'high' },
	]
}

/**
 * Mount the page and let its fetches settle.
 *
 * @return {Promise<object>} The wrapper
 */
async function mountPage() {
	const wrapper = mount(dashboardPage(), {
		global: {
			mocks: {
				$router: {
					push: jest.fn(() => Promise.resolve()),
				},
			},
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * The rendered markup, which must hold the page.
 *
 * @param {object} wrapper The wrapper
 * @return {string} The normalised markup
 */
function domOf(wrapper) {
	const html = normalised(wrapper.html())
	expect(html).toContain('x-cndashboardpage')
	expect(html).toContain('slot-widget-count-objects')
	return html
}

/**
 * The first stand-in with a name.
 *
 * @param {object} wrapper The wrapper
 * @param {string} name The stand-in's component name
 * @return {object} Its attributes
 */
function attrsOf(wrapper, name) {
	return wrapper.findComponent({ name }).vm.$attrs
}

/**
 * Pick a range preset the way the Searches card's menu does.
 *
 * @param {object} wrapper The wrapper
 * @param {number} index The menu item
 * @return {Promise<void>}
 */
async function pickFromMenu(wrapper, index) {
	wrapper.findAllComponents({ name: 'NcActionButton' })[index].vm.$attrs.onClick()
	await flushPromises()
}

let log

beforeEach(() => {
	jest.useFakeTimers({
		now: NOW,
		doNotFake: [
			'hrtime',
			'nextTick',
			'performance',
			'queueMicrotask',
			'requestAnimationFrame',
			'cancelAnimationFrame',
			'requestIdleCallback',
			'cancelIdleCallback',
			'setImmediate',
			'clearImmediate',
			'setInterval',
			'clearInterval',
			'setTimeout',
			'clearTimeout',
		],
	})
	localStorage.clear()
	log = emptyStores()
	jest.spyOn(console, 'warn').mockImplementation(() => {})
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.useRealTimers()
	jest.restoreAllMocks()
})

describe('DashboardIndex markup', () => {
	it('renders the empty dashboard', async () => {
		expect(domOf(await mountPage())).toMatchSnapshot()
	})

	it('renders the loading dashboard', async () => {
		dashboardStore.loading = true
		expect(domOf(await mountPage())).toMatchSnapshot()
	})

	it('keeps showing data while the search statistics load', async () => {
		filledStores()
		searchTrailStore.statisticsLoading = true
		expect(attrsOf(await mountPage(), 'CnDashboardPage').loading).toBe(false)
	})

	it('renders a dashboard with data on all time', async () => {
		filledStores()
		expect(domOf(await mountPage())).toMatchSnapshot()
	})

	it('renders a dashboard with data on a bounded range', async () => {
		filledStores()
		localStorage.setItem(RANGE_KEY, JSON.stringify({ preset: '30d' }))
		expect(domOf(await mountPage())).toMatchSnapshot()
	})

	it('renders no events on a bounded range without a series', async () => {
		localStorage.setItem(RANGE_KEY, JSON.stringify({ preset: '7d' }))
		dashboardStore.chartData = { auditTrailActions: { series: 'odd' } }
		expect(domOf(await mountPage())).toMatchSnapshot()
	})

	it('renders the refresh button while refreshing', async () => {
		const wrapper = await mountPage()
		await wrapper.setData({ refreshing: true })
		expect(domOf(wrapper)).toMatchSnapshot()
	})
})

describe('DashboardIndex mount', () => {
	it('fetches everything for all time', async () => {
		await mountPage()
		expect(log).toMatchSnapshot()
	})

	it('restores a stored range and fetches for it', async () => {
		localStorage.setItem(RANGE_KEY, JSON.stringify({ preset: '90d' }))
		const wrapper = await mountPage()
		expect(wrapper.vm.kpiRange).toMatchSnapshot()
		expect(log).toMatchSnapshot()
	})

	it.each([
		['an unknown preset', JSON.stringify({ preset: 'nope' })],
		['a null value', 'null'],
		['corrupt text', '{'],
	])('keeps all time for %s', async (name, stored) => {
		localStorage.setItem(RANGE_KEY, stored)
		const wrapper = await mountPage()
		expect(wrapper.vm.kpiRange).toEqual({
			from: null,
			till: null,
			preset: 'all',
		})
	})

	it('falls back to empty search data when statistics fail', async () => {
		searchTrailStore.fetchStatistics.mockRejectedValueOnce(new Error('down'))
		await mountPage()
		expect(log).toMatchSnapshot()
		expect(console.error.mock.calls[0][0]).toBe(
			'Error loading search trail data:',
		)
	})

	it('falls back to empty search data when popular terms fail', async () => {
		searchTrailStore.fetchPopularTerms.mockRejectedValueOnce(new Error('down'))
		await mountPage()
		expect(log).toMatchSnapshot()
	})
})

describe('DashboardIndex range picker', () => {
	it.each([
		[0, 'all'],
		[1, '7d'],
		[2, '30d'],
		[3, '90d'],
		[4, '12m'],
	])('applies menu item %i', async (index, preset) => {
		const wrapper = await mountPage()
		log.length = 0
		await pickFromMenu(wrapper, index)
		expect(wrapper.vm.kpiRange).toMatchSnapshot()
		expect(log).toMatchSnapshot()
		expect(JSON.parse(localStorage.getItem(RANGE_KEY))).toEqual({ preset })
	})

	it('applies the Events card menu the same way', async () => {
		const wrapper = await mountPage()
		await pickFromMenu(wrapper, 6)
		expect(wrapper.vm.kpiRange.preset).toBe('7d')
	})

	it('still applies the range when it cannot be stored', async () => {
		const wrapper = await mountPage()
		jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
			throw new Error('quota')
		})
		await pickFromMenu(wrapper, 1)
		expect(wrapper.vm.kpiRange.preset).toBe('7d')
		expect(log).toContainEqual(['setDateRange', '2026-03-08', '2026-03-15'])
	})

	it('marks the active preset in both menus after a pick', async () => {
		const wrapper = await mountPage()
		await pickFromMenu(wrapper, 2)
		expect(domOf(wrapper)).toMatchSnapshot()
	})
})

describe('DashboardIndex actions', () => {
	it('refreshes in order and clears the flag', async () => {
		const wrapper = await mountPage()
		log.length = 0
		attrsOf(wrapper, 'NcButton').onClick()
		expect(wrapper.vm.refreshing).toBe(true)
		await flushPromises()
		expect(wrapper.vm.refreshing).toBe(false)
		expect(log).toMatchSnapshot()
	})

	it('clears the flag when a refresh fails', async () => {
		const wrapper = await mountPage()
		log.length = 0
		dashboardStore.preload.mockRejectedValueOnce(new Error('down'))
		attrsOf(wrapper, 'NcButton').onClick()
		await flushPromises()
		expect(wrapper.vm.refreshing).toBe(false)
		expect(log).toEqual([])
	})

	it('keeps a layout the page reports', async () => {
		const wrapper = await mountPage()
		const layout = [{ id: 1, widgetId: 'count-objects' }]
		attrsOf(wrapper, 'CnDashboardPage').onLayoutChange(layout)
		await flushPromises()
		expect(attrsOf(wrapper, 'CnDashboardPage').layout).toEqual(layout)
	})

	it('opens the register and schema behind a row', async () => {
		filledStores()
		const wrapper = await mountPage()
		const [terms, byRegister, bySchema] = wrapper
			.findAllComponents({ name: 'CnDataTable' })
			.map((table) => table.vm.$attrs.onRowClick)
		byRegister({ label: 'Alpha' })
		byRegister({ label: 'Beta' })
		byRegister({ label: 'Gamma' })
		byRegister({ label: 'Nobody' })
		bySchema({ label: 'One' })
		bySchema({ label: 'Two' })
		bySchema({ label: 'Three' })
		bySchema({ label: 'Nothing' })
		terms({ term: 'water' })
		expect(wrapper.vm.$router.push.mock.calls).toMatchSnapshot()
	})
})
