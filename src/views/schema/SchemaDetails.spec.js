/**
 * The schema detail page: its loading and error states, the header actions,
 * the tabs, the statistics it loads for the schema and the table it renders
 * from them, and the property selection toggle.
 *
 * @spec exclude Client-side schema detail page state.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import SchemaDetails from './SchemaDetails.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one, many) => many,
}))
// The compiled component binds `@nextcloud/vue` and `vue` to one variable, so
// the library stand-ins have to be reachable through `vue` as well.
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockLibrary() }))
jest.mock('../../components/schema/SchemaDraftBar.vue', () => ({
	name: 'SchemaDraftBar',
	props: ['schema'],
	emits: ['editDraft'],
	render: () => null,
}))
jest.mock('../register/DetailChartGrid.vue', () => ({
	name: 'DetailChartGrid',
	props: ['chartData', 'distributionKey', 'distributionTitle', 'auditOptions', 'distributionOptions', 'sizeOptions'],
	render: () => null,
}))
jest.mock('../schemas/SchemaWorkflowTab.vue', () => ({ name: 'SchemaWorkflowTab', props: ['schema'], render: () => null }))
jest.mock('./CalendarProviderTab.vue', () => ({ name: 'CalendarProviderTab', props: ['schema'], render: () => null }))
jest.mock('./SchemaRulesTab.vue', () => ({ name: 'SchemaRulesTab', props: ['schema'], render: () => null }))
jest.mock('../../store/store.js', () => ({
	dashboardStore: { loading: false, error: null, chartData: {}, fetchAllChartData: jest.fn() },
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	schemaStore: {
		schemaItem: null,
		schemaPropertyKey: null,
		setDraftMode: jest.fn(),
		setSchemaPropertyKey: jest.fn(),
		downloadSchema: jest.fn(),
		getSchemaStats: jest.fn(),
	},
}))

/**
 * The library components the page uses, as golden stand-ins.
 *
 * @return {object} The components
 */
function mockLibrary() {
	const library = jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary()
	const names = ['NcActionButton', 'NcActions', 'NcAppContent', 'NcEmptyContent', 'NcLoadingIcon']
	const { h } = jest.requireActual('vue')
	return {
		...Object.fromEntries(names.map((name) => [name, library[name]])),
		NcActionButton: {
			name: 'NcActionButton',
			emits: ['click'],
			render() {
				return h('button', { class: 'actionButton', onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		},
	}
}

const { dashboardStore, navigationStore, schemaStore } = jest.requireMock('../../store/store.js')

const SCHEMA = { id: 12, title: 'Person', description: 'A natural person' }

/**
 * Mount the page and let its mount-time loads finish.
 *
 * @return {Promise<object>} The wrapper
 */
async function mountPage() {
	const wrapper = mount(SchemaDetails)
	await flushPromises()
	return wrapper
}

/**
 * The header action button whose text matches.
 *
 * @param {object} wrapper The page
 * @param {string} text The button text
 * @return {object} The button wrapper
 */
function action(wrapper, text) {
	return wrapper.findAllComponents({ name: 'NcActionButton' }).find((button) => button.text().trim() === text)
}

/**
 * The tab button whose text matches.
 *
 * @param {object} wrapper The page
 * @param {string} text The tab label
 * @return {object} The button wrapper
 */
function tab(wrapper, text) {
	return wrapper.findAll('button.tabButton').find((button) => button.text().trim() === text)
}

/**
 * The statistics table as rows of cell texts.
 *
 * @param {object} wrapper The page
 * @return {Array<Array<string>>} The body rows
 */
function statsRows(wrapper) {
	return wrapper.findAll('table.schemaStats tbody tr').map((row) => row.findAll('td').map((cell) => cell.text().trim()))
}

let consoleError

beforeEach(() => {
	jest.clearAllMocks()
	dashboardStore.loading = false
	dashboardStore.error = null
	dashboardStore.chartData = { auditTrailActions: { labels: ['create'] } }
	schemaStore.schemaItem = { ...SCHEMA }
	schemaStore.schemaPropertyKey = null
	schemaStore.getSchemaStats.mockResolvedValue(null)
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	consoleError.mockRestore()
})

describe('SchemaDetails states', () => {
	it('shows only the loading state while the dashboard loads', async () => {
		dashboardStore.loading = true
		const wrapper = await mountPage()

		expect(wrapper.find('[data-stub="empty"]').text()).toBe('Loading | Loading schema statistics...')
		expect(wrapper.find('h2.pageHeader').exists()).toBe(false)
	})

	it('shows the dashboard error instead of the page', async () => {
		dashboardStore.error = 'Server unreachable'
		const wrapper = await mountPage()

		expect(wrapper.find('[data-stub="empty"]').text()).toBe('Error | Server unreachable')
		expect(wrapper.find('h2.pageHeader').exists()).toBe(false)
	})

	it('titles the page with the schema and shows its description on the dashboard tab', async () => {
		const wrapper = await mountPage()

		expect(wrapper.find('h2.pageHeader').text()).toBe('Person')
		expect(wrapper.find('.dashboardContent').text()).toContain('A natural person')
		expect(wrapper.findComponent({ name: 'SchemaDraftBar' }).props('schema')).toEqual(SCHEMA)
	})
})

describe('SchemaDetails loading', () => {
	it('loads the chart data when none is loaded yet, and the stats of the schema', async () => {
		dashboardStore.chartData = {}
		schemaStore.getSchemaStats.mockResolvedValue({ registers: 2 })
		const wrapper = await mountPage()

		expect(dashboardStore.fetchAllChartData).toHaveBeenCalledTimes(1)
		expect(schemaStore.getSchemaStats).toHaveBeenCalledWith(12)
		expect(wrapper.vm.schemaStats).toEqual({ registers: 2 })
		expect(wrapper.vm.statsLoading).toBe(false)
		expect(wrapper.vm.statsError).toBe(null)
	})

	it('keeps chart data that is already loaded', async () => {
		await mountPage()

		expect(dashboardStore.fetchAllChartData).not.toHaveBeenCalled()
	})

	it('fetches chart data when the store holds none at all', async () => {
		dashboardStore.chartData = null
		await mountPage()

		expect(dashboardStore.fetchAllChartData).toHaveBeenCalledTimes(1)
	})

	it('loads no stats without a schema id', async () => {
		schemaStore.schemaItem = { title: 'Unsaved' }
		const wrapper = await mountPage()

		expect(schemaStore.getSchemaStats).not.toHaveBeenCalled()
		await wrapper.vm.loadSchemaStats()
		expect(schemaStore.getSchemaStats).not.toHaveBeenCalled()
		expect(wrapper.vm.statsLoading).toBe(false)
	})

	it('keeps the error message when the stats fail to load', async () => {
		const failure = new Error('Stats unavailable')
		schemaStore.getSchemaStats.mockRejectedValue(failure)
		const wrapper = await mountPage()

		expect(wrapper.vm.statsError).toBe('Stats unavailable')
		expect(wrapper.vm.statsLoading).toBe(false)
		expect(wrapper.vm.schemaStats).toBe(null)
		expect(consoleError).toHaveBeenCalledWith('Error loading schema stats:', failure)
		expect(wrapper.find('table.schemaStats').exists()).toBe(false)
	})

	it('clears an earlier error when the stats load again', async () => {
		const vm = { ...SchemaDetails.data(), statsError: 'old' }
		schemaStore.getSchemaStats.mockResolvedValue({ registers: 1 })

		await SchemaDetails.methods.loadSchemaStats.call(vm)

		expect(vm.statsError).toBe(null)
		expect(vm.statsLoading).toBe(false)
		expect(vm.schemaStats).toEqual({ registers: 1 })
	})
})

describe('SchemaDetails statistics table', () => {
	it('renders counts and sizes from the stats', async () => {
		schemaStore.getSchemaStats.mockResolvedValue({
			objects: { total: 40, size: 2048, invalid: 3, deleted: 1 },
			files: { total: 5, size: 0 },
			logs: { total: 9, size: 1024 },
			registers: 2,
		})
		const wrapper = await mountPage()
		const rows = statsRows(wrapper)

		expect(rows.map((row) => row.slice(0, 2))).toEqual([
			['Objects', '40'],
			['Invalid', '3'],
			['Deleted', '1'],
			['Files', '5'],
			['Logs', '9'],
			['Registers', '2'],
		])
		expect(rows[1][2]).toBe('-')
		expect(rows[5][2]).toBe('-')
		expect(rows[0][2]).not.toBe(rows[3][2])
	})

	it('shows zeros for stats that are missing', async () => {
		schemaStore.getSchemaStats.mockResolvedValue({})
		const wrapper = await mountPage()

		expect(statsRows(wrapper).map((row) => row[1])).toEqual(['0', '0', '0', '0', '0', '0'])
	})

	it('hands the chart grid the dashboard data and the register distribution', async () => {
		const wrapper = await mountPage()
		const grid = wrapper.findComponent({ name: 'DetailChartGrid' })

		expect(grid.props('chartData')).toBe(dashboardStore.chartData)
		expect(grid.props('distributionKey')).toBe('objectsByRegister')
		expect(grid.props('distributionTitle')).toBe('Objects by Register')
	})
})

describe('SchemaDetails tabs', () => {
	it('starts on the dashboard and mounts one tab at a time for the schema', async () => {
		const wrapper = await mountPage()
		expect(tab(wrapper, 'Dashboard').classes()).toContain('active')
		expect(wrapper.findComponent({ name: 'CalendarProviderTab' }).exists()).toBe(false)

		await tab(wrapper, 'Calendar').trigger('click')
		expect(wrapper.findComponent({ name: 'CalendarProviderTab' }).props('schema')).toEqual(SCHEMA)
		expect(tab(wrapper, 'Calendar').classes()).toContain('active')
		expect(tab(wrapper, 'Dashboard').classes()).not.toContain('active')

		await tab(wrapper, 'Workflows').trigger('click')
		expect(wrapper.findComponent({ name: 'CalendarProviderTab' }).exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'SchemaWorkflowTab' }).props('schema')).toEqual(SCHEMA)

		await tab(wrapper, 'Rules').trigger('click')
		expect(wrapper.findComponent({ name: 'SchemaWorkflowTab' }).exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'SchemaRulesTab' }).props('schema')).toEqual(SCHEMA)
		expect(wrapper.find('.dashboardContent').isVisible()).toBe(false)
	})
})

describe('SchemaDetails actions', () => {
	it('opens the editor for a direct edit or a draft', async () => {
		const wrapper = await mountPage()

		await action(wrapper, 'Edit').trigger('click')
		await action(wrapper, 'Edit as draft').trigger('click')

		expect(schemaStore.setDraftMode.mock.calls).toEqual([[false], [true]])
		expect(navigationStore.setModal.mock.calls).toEqual([['editSchema'], ['editSchema']])
	})

	it('opens the draft editor from the draft bar', async () => {
		const wrapper = await mountPage()

		wrapper.findComponent({ name: 'SchemaDraftBar' }).vm.$emit('editDraft')

		expect(schemaStore.setDraftMode).toHaveBeenCalledWith(true)
		expect(navigationStore.setModal).toHaveBeenCalledWith('editSchema')
	})

	it('adds a property with no property selected', async () => {
		const wrapper = await mountPage()

		await action(wrapper, 'Add Property').trigger('click')

		expect(schemaStore.setSchemaPropertyKey).toHaveBeenCalledWith(null)
		expect(navigationStore.setModal).toHaveBeenCalledWith('editSchemaProperty')
	})

	it('uploads, downloads and deletes the schema', async () => {
		const wrapper = await mountPage()

		await action(wrapper, 'Upload').trigger('click')
		await action(wrapper, 'Download').trigger('click')
		await action(wrapper, 'Delete').trigger('click')

		expect(navigationStore.setModal).toHaveBeenCalledWith('uploadSchema')
		expect(schemaStore.downloadSchema).toHaveBeenCalledWith(schemaStore.schemaItem)
		expect(navigationStore.setDialog).toHaveBeenCalledWith('deleteSchema')
	})
})

describe('SchemaDetails property selection', () => {
	it('selects a property that is not selected', async () => {
		const wrapper = await mountPage()

		wrapper.vm.setActiveProperty('name')

		expect(schemaStore.setSchemaPropertyKey).toHaveBeenCalledWith('name')
	})

	it('deselects the property that is already selected', async () => {
		schemaStore.schemaPropertyKey = 'name'
		const wrapper = await mountPage()

		wrapper.vm.setActiveProperty('name')

		expect(schemaStore.setSchemaPropertyKey).toHaveBeenCalledWith(null)
	})
})
