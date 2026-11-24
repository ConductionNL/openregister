/**
 * DashboardIndex mounted as shipped, with its own setup bindings: what mounting
 * fetches, what the KPI cards and widgets show, and the refresh button.
 *
 * The golden DOM spec swaps the setup for test bindings; this one keeps the
 * component's own, with the stores, the live subscription and the library
 * components mocked.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import DashboardIndex from './DashboardIndex.vue'
import { dashboardStore, registerStore, schemaStore, searchTrailStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('../../store/store.js', () => ({
	dashboardStore: {},
	objectStore: {},
	registerStore: {},
	schemaStore: {},
	searchTrailStore: {},
}))
jest.mock('../../composables/liveCollectionSubscription.js', () => ({
	liveCollectionLifecycle: {},
	liveCollectionMethods: () => ({ syncLiveSubscription: () => {} }),
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () => mockLibrary())
// The page mixes <script setup> and an options block; its compiled template
// reads the library components through 'vue'.
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockLibrary() }))
jest.mock('vue3-apexcharts', () => ({ __esModule: true, default: mockStub('Apexchart') }))

/**
 * A stand-in component that renders its attributes and every slot.
 *
 * @param {string} name The component name.
 * @param {string} tag The element to render.
 * @return {object} The component.
 */
function mockStub(name, tag = 'div') {
	const { h } = jest.requireActual('vue')
	return {
		name,
		inheritAttrs: false,
		render() {
			const slots = Object.values(this.$slots).map((s) => s())
			return h(tag, { ...this.$attrs, class: [`stub-${name}`, this.$attrs.class] }, slots)
		},
	}
}

/**
 * The library components the page uses, as stand-ins.
 *
 * @return {object} The module.
 */
function mockLibrary() {
	return {
		__esModule: true,
		NcAppContent: mockStub('NcAppContent'),
		NcButton: mockStub('NcButton', 'button'),
		NcLoadingIcon: mockStub('NcLoadingIcon'),
		NcActions: mockStub('NcActions'),
		NcActionButton: mockStub('NcActionButton', 'button'),
		CnDashboardPage: mockStub('CnDashboardPage'),
		CnDataTable: mockStub('CnDataTable'),
	}
}

/**
 * Fill the stores; every action resolves.
 *
 * @param {object} data Store state to merge over the empty defaults.
 * @return {void}
 */
function stores(data = {}) {
	Object.assign(dashboardStore, {
		loading: false,
		getSystemTotals: { stats: { objects: { total: 42 }, logs: { total: 7 } } },
		chartData: {},
		preload: jest.fn().mockResolvedValue(),
		fetchAllChartData: jest.fn().mockResolvedValue(),
		fetchRegisters: jest.fn().mockResolvedValue(),
		fetchAllStatistics: jest.fn().mockResolvedValue(),
		setDateRange: jest.fn(),
	}, data.dashboard)
	Object.assign(registerStore, { registerList: [], registerItem: null, refreshRegisterList: jest.fn().mockResolvedValue() }, data.register)
	Object.assign(schemaStore, { schemaList: [], schemaItem: null, refreshSchemaList: jest.fn().mockResolvedValue() }, data.schema)
	Object.assign(searchTrailStore, {
		statistics: {},
		statisticsLoading: false,
		popularTerms: [],
		fetchStatistics: jest.fn().mockResolvedValue(),
		fetchPopularTerms: jest.fn().mockResolvedValue(),
		setStatistics: jest.fn(),
		setPopularTerms: jest.fn(),
		setActivity: jest.fn(),
	}, data.searchTrail)
}

/**
 * Mount the page and let its fetches settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountPage() {
	const wrapper = mount(DashboardIndex, { global: { mocks: { $router: { push: jest.fn() } } } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	localStorage.clear()
})

describe('DashboardIndex — mounted with its own setup', () => {
	it('loads every widget source on mount', async () => {
		stores()

		await mountPage()

		expect(dashboardStore.preload).toHaveBeenCalledTimes(1)
		expect(dashboardStore.fetchAllChartData).toHaveBeenCalledTimes(1)
		expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(1)
		expect(schemaStore.refreshSchemaList).toHaveBeenCalledTimes(1)
		expect(dashboardStore.setDateRange).toHaveBeenCalledTimes(1)
		expect(searchTrailStore.fetchStatistics).toHaveBeenCalledTimes(1)
	})

	it('fills the KPI cards from the stores and says when the widgets are empty', async () => {
		stores({ register: { registerList: [{ id: 1 }, { id: 2 }] }, schema: { schemaList: [{ id: 3 }] } })

		const text = (await mountPage()).text()

		for (const label of ['Objects', 'Registers', 'Schemas', 'Searches', 'Events']) {
			expect(text).toContain(label)
		}
		expect(text).toContain('42')
		expect(text).toContain('No search terms data available')
		expect(text).toContain('No register data available')
		expect(text).toContain('No schema data available')
		expect(text).toContain('No data available for chart')
	})

	it('shows the tables and the chart once there is data', async () => {
		stores({
			dashboard: { chartData: { objectsByRegister: { labels: ['Alpha'], series: [5] }, objectsBySchema: { labels: ['One'], series: [2] } } },
			register: { registerList: [{ id: 11, title: 'Alpha' }] },
			schema: { schemaList: [{ id: 21, title: 'One' }] },
			searchTrail: { popularTerms: [{ term: 'water', count: 5 }] },
		})

		const wrapper = await mountPage()

		expect(wrapper.findAll('.stub-CnDataTable')).toHaveLength(3)
		expect(wrapper.find('.stub-Apexchart').exists()).toBe(true)
		expect(wrapper.text()).not.toContain('No data available for chart')
	})

	it('refreshes every source from the header button', async () => {
		stores()
		const wrapper = await mountPage()

		await wrapper.find('button.stub-NcButton').trigger('click')
		await flushPromises()

		expect(dashboardStore.preload).toHaveBeenCalledTimes(2)
		expect(dashboardStore.fetchAllChartData).toHaveBeenCalledTimes(2)
		expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(2)
		expect(schemaStore.refreshSchemaList).toHaveBeenCalledTimes(2)
		expect(searchTrailStore.fetchStatistics).toHaveBeenCalledTimes(2)
	})
})
