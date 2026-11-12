import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
/**
 * ReportView: loading a dashboard and its widget data, rendering each widget
 * type, the export downloads and their error messages, and the formatting
 * helpers behind the widgets.
 *
 * @spec exclude Unit tests of the report dashboard view.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import ReportView from './ReportView.vue'
import { reportsStore } from '../../store/store.js'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { post: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text),
}))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('@conduction/nextcloud-vue', () => {
	const { h } = jest.requireActual('vue')
	const stub = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			return h(`x-${name}`, { 'data-props': JSON.stringify(this.$attrs) })
		},
	})
	return { __esModule: true, CnChartWidget: stub('CnChartWidget'), CnDataTable: stub('CnDataTable') }
})
jest.mock('../../store/store.js', () => {
	const { reactive } = jest.requireActual('vue')
	return {
		__esModule: true,
		reportsStore: reactive({
			getActiveDashboard: null,
			isLoading: false,
			fetchDashboard: jest.fn(),
			fetchWidgetData: jest.fn(),
			getWidgetData: jest.fn(),
		}),
	}
})

const WIDGETS = [
	{ type: 'kpi', title: 'Objects', subtitle: 'All objects', options: { icon: 'DatabaseOutline' }, dataSource: { mode: 'statistics', register: 'r', schema: 's' }, layout: { w: 2, h: 1 } },
	{ type: 'chart', title: 'By status', options: { chartType: 'bar' }, dataSource: { mode: 'aggregation', register: 'r', schema: 's', aggregation: 'status' } },
	{ type: 'table', title: 'Rows', options: { columns: [{ key: 'name' }, { key: 'size', label: 'Size' }] }, dataSource: { mode: 'graphql', graphqlQuery: '{ a }' } },
	{ type: 'sparkline', title: 'Trend', dataSource: { mode: 'other' } },
	{ type: 'tile', title: 'Share', options: { valueFormat: 'percent', valueField: 'ratio' } },
	{ type: 'stats', title: 'Stats' },
	{ type: 'gauge', title: 'Unknown' },
]

const DATA = [
	{ count: 1234 },
	{ groups: [{ key: 'open', value: 3 }, { key: 'closed', value: 5 }] },
	[{ name: 'a', size: 1 }],
	{ groups: [{ key: 'mon', value: 1 }, { key: 'tue', value: 2 }] },
	{ ratio: 0.25 },
	{ count: 7, name: 'n', metric: null },
	null,
]

/**
 * Mount the view on a route.
 *
 * @param {string|undefined} id The route id.
 * @return {object} The wrapper and the router double.
 */
function mountView(id = 'dash-1') {
	const router = { push: jest.fn() }
	const wrapper = mount(ReportView, {
		global: {
			mocks: {
				$route: { params: { id } },
				$router: router,
				t: (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text),
			},
		},
	})
	return { wrapper, router }
}

beforeEach(() => {
	jest.clearAllMocks()
	reportsStore.getActiveDashboard = null
	reportsStore.isLoading = false
	reportsStore.fetchDashboard.mockImplementation(async () => {
		reportsStore.getActiveDashboard = { titel: 'Overview', beschrijving: 'All of it', layout: { cols: 3 }, widgets: WIDGETS }
	})
	reportsStore.fetchWidgetData.mockImplementation(async (widget) => DATA[WIDGETS.findIndex((w) => w.title === widget.title)])
	reportsStore.getWidgetData.mockReturnValue(null)
})

describe('ReportView loading', () => {
	it('loads the dashboard of the route and the data of every widget', async () => {
		const { wrapper } = mountView()
		await flushPromises()

		expect(reportsStore.fetchDashboard).toHaveBeenCalledWith('dash-1')
		expect(reportsStore.fetchWidgetData).toHaveBeenCalledTimes(WIDGETS.length)
		expect(reportsStore.fetchWidgetData).toHaveBeenCalledWith(WIDGETS[0], false)
		expect(reportsStore.getWidgetData).toHaveBeenCalledWith('stats:r:s')
		expect(reportsStore.getWidgetData).toHaveBeenCalledWith('agg:r:s:status')
		expect(reportsStore.getWidgetData).toHaveBeenCalledWith('gql:{ a }')
		expect(reportsStore.getWidgetData).toHaveBeenCalledWith('unknown:other')
		expect(reportsStore.getWidgetData).toHaveBeenCalledWith('')

		expect(wrapper.find('h1').text()).toBe('Overview')
		expect(wrapper.text()).toContain('All of it')
		expect(wrapper.find('.reportGrid').attributes('style')).toContain('--report-cols: 3')
		expect(wrapper.findAll('section.reportWidget')).toHaveLength(WIDGETS.length)
	})

	it('renders each widget type from its data', async () => {
		const { wrapper } = mountView()
		await flushPromises()
		const sections = wrapper.findAll('section.reportWidget')

		expect(sections[0].attributes('style')).toContain('grid-column: span 2')
		expect(sections[0].attributes('style')).toContain('grid-row: span 1')
		expect(sections[0].find('.widgetKpiValue').text()).toBe('1.234')
		expect(sections[0].find('.widgetKpiLabel').text()).toBe('All objects')

		const chart = JSON.parse(sections[1].find('x-cnchartwidget').attributes('data-props'))
		expect(chart.series).toEqual([{ name: 'By status', data: [3, 5] }])
		expect(chart.categories).toEqual(['open', 'closed'])

		const table = JSON.parse(sections[2].find('x-cndatatable').attributes('data-props'))
		expect(table.rows).toEqual([{ name: 'a', size: 1 }])
		expect(table.columns).toEqual([
			{ key: 'name', label: 'name', sortable: true },
			{ key: 'size', label: 'Size', sortable: true },
		])

		const spark = JSON.parse(sections[3].find('x-cnchartwidget').attributes('data-props'))
		expect(spark.series).toEqual([{ name: 'Trend', data: [1, 2] }])
		expect(spark.categories).toEqual(['mon', 'tue'])

		expect(sections[4].find('.widgetTileValue').text()).toBe('25.0%')
		expect(sections[4].find('.widgetTileLabel').text()).toBe('Share')

		expect(sections[5].findAll('dt').map((n) => n.text())).toEqual(['name', 'count'])
		expect(sections[5].findAll('dd').map((n) => n.text())).toEqual(['n', '7'])

		expect(sections[6].find('.widgetUnknown').text()).toBe('Unknown widget type: {type} {"type":"gauge"}')
	})

	it('shows a widget error read from the cached widget data', async () => {
		reportsStore.getWidgetData.mockReturnValue({ error: 'Query failed' })
		const { wrapper } = mountView()
		await flushPromises()

		expect(wrapper.findAll('.widgetError')).toHaveLength(WIDGETS.length)
		expect(wrapper.find('.widgetError').text()).toBe('Query failed')
	})

	it('shows the not-found state when no dashboard loads', async () => {
		reportsStore.fetchDashboard.mockResolvedValue(undefined)
		const { wrapper } = mountView()
		await flushPromises()

		expect(reportsStore.fetchWidgetData).not.toHaveBeenCalled()
		expect(wrapper.find('[data-stub="empty"]').text()).toContain('Dashboard not found')
		expect(wrapper.find('h1').text()).toBe('Dashboard')
	})

	it('swallows a failed dashboard fetch', async () => {
		reportsStore.fetchDashboard.mockRejectedValue(new Error('boom'))
		const { wrapper } = mountView()
		await flushPromises()

		expect(wrapper.find('[data-stub="empty"]').exists()).toBe(true)
	})

	it('loads nothing without a route id', async () => {
		mountView('')
		await flushPromises()

		expect(reportsStore.fetchDashboard).not.toHaveBeenCalled()
	})

	it('reloads on refresh and navigates back to the list', async () => {
		const { wrapper, router } = mountView()
		await flushPromises()
		const buttons = wrapper.findAll('button')

		await buttons[1].trigger('click')
		await flushPromises()
		expect(reportsStore.fetchDashboard).toHaveBeenCalledTimes(2)

		await buttons[0].trigger('click')
		expect(router.push).toHaveBeenCalledWith('/reports')
	})
})

describe('ReportView exports', () => {
	let click
	beforeEach(() => {
		click = jest.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
		URL.createObjectURL = jest.fn(() => 'blob:url')
		URL.revokeObjectURL = jest.fn()
	})
	afterEach(() => click.mockRestore())

	it('downloads the rendered report under the server filename', async () => {
		axios.post.mockResolvedValue({ data: new Blob(['x']), headers: { 'content-disposition': 'attachment; filename="report.xlsx"' } })
		const { wrapper } = mountView('a b')
		await flushPromises()

		await wrapper.vm.downloadAs('xlsx')

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/reports/a%20b/render',
			null,
			{ params: { format: 'xlsx' }, responseType: 'blob' },
		)
		expect(click).toHaveBeenCalledTimes(1)
		expect(click.mock.instances[0].download).toBe('report.xlsx')
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:url')
	})

	it('names the download after the dashboard without a filename header', async () => {
		axios.post.mockResolvedValue({ data: new Blob(['x']), headers: {} })
		const { wrapper } = mountView()
		await flushPromises()

		await wrapper.vm.downloadAs('csv')

		expect(click.mock.instances[0].download).toBe('Overview.csv')
	})

	it('does nothing without a route id', async () => {
		const { wrapper } = mountView('')
		await wrapper.vm.downloadAs('pdf')
		wrapper.vm.openHtmlPreview()

		expect(axios.post).not.toHaveBeenCalled()
	})

	it.each([
		['the office server message', { error: 'office_server_required' }, 'A PDF needs an office server'],
		['the server message', { message: 'Too large' }, 'Too large'],
		['the error code', { error: 'bad_format' }, 'bad_format'],
	])('shows %s when the download fails', async (label, body, expected) => {
		axios.post.mockRejectedValue({ response: { data: { text: async () => JSON.stringify(body) } } })
		const { wrapper } = mountView()
		await flushPromises()

		await wrapper.vm.downloadAs('pdf')

		expect(showError).toHaveBeenCalledWith(expect.stringContaining(expected))
	})

	it('shows the generic message when the error body is not JSON', async () => {
		axios.post.mockRejectedValue({ response: { data: { text: async () => '<html>' } } })
		const { wrapper } = mountView()
		await flushPromises()

		await wrapper.vm.downloadAs('pdf')

		expect(showError).toHaveBeenCalledWith('The report could not be downloaded.')
	})

	it('opens the HTML preview in a new tab', async () => {
		const open = jest.spyOn(window, 'open').mockImplementation(() => null)
		const { wrapper } = mountView()
		wrapper.vm.openHtmlPreview()

		expect(open).toHaveBeenCalledWith('/apps/openregister/api/reports/dash-1/preview', '_blank')
		open.mockRestore()
	})
})

describe('ReportView helpers', () => {
	let vm
	beforeEach(() => {
		reportsStore.fetchDashboard.mockResolvedValue(undefined)
		vm = mountView('').wrapper.vm
	})

	it.each([
		[null, {}, '—'],
		[{ total: 5 }, { options: { valueField: 'total', valueFormat: 'duration' } }, '5s'],
		[12.5, { options: { valueFormat: 'currency' } }, new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(12.5)],
		['2026-01-02', { options: { valueFormat: 'date' } }, new Date('2026-01-02').toLocaleDateString()],
		['text', {}, 'text'],
	])('formats %p with %p', (data, widget, expected) => {
		expect(vm.formatValue(data, widget)).toBe(expected)
	})

	it('maps chart data for pie and donut charts', () => {
		const pie = { title: 'P', options: { chartType: 'pie' } }
		expect(vm.chartSeries([{ value: 1 }, { count: 2 }, {}], pie)).toEqual([1, 2, 0])
		expect(vm.chartSeries({ x: 1 }, pie)).toEqual([])
		expect(vm.chartSeries({ x: 1 }, { options: {} })).toEqual([])
		expect(vm.chartSeries(null, pie)).toEqual([])
		expect(vm.chartLabels({ groups: [{ label: 'a' }, {}] }, { options: { chartType: 'donut' } })).toEqual(['a', ''])
		expect(vm.chartLabels({ groups: [] }, { options: {} })).toEqual([])
		expect(vm.chartCategories(null)).toEqual([])
	})

	it('derives table rows and default columns', () => {
		expect(vm.tableRows(null)).toEqual([])
		expect(vm.tableRows({ groups: [1] })).toEqual([1])
		expect(vm.tableRows({ results: [2] })).toEqual([2])
		expect(vm.tableRows({})).toEqual([])
		expect(vm.tableColumns({}).map((c) => c.key)).toEqual(['key', 'value'])
	})

	it('derives sparkline and stats values', () => {
		expect(vm.sparklineData(null, {})).toEqual([])
		expect(vm.sparklineData([{ count: 4 }, 6], {})).toEqual([4, 6])
		expect(vm.sparklineData({ other: 1 }, {})).toEqual([])
		expect(vm.sparklineCategories(null)).toEqual([])
		expect(vm.statsEntries(null, {})).toEqual([])
		expect(vm.statsEntries({ groups: [{ label: 'g', count: 2 }] }, {})).toEqual([{ label: 'g', value: '2' }])
	})

	it('resolves widget icons, cell styles and the default state', () => {
		expect(vm.widgetIcon({ options: { icon: 'nope' } }).name).toBe(vm.widgetIcon({}).name)
		expect(vm.widgetCellStyle({})).toEqual({})
		expect(vm.widgetCellStyle({ layout: {} })).toEqual({})
		expect(vm.widgetState(9)).toEqual({ loading: false, error: null, data: null })
		expect(vm.gridStyles).toEqual({ '--report-cols': 4 })
	})
})
