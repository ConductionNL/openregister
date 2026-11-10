import RegisterDetail from './RegisterDetail.vue'
import SchemaDetails from '../schema/SchemaDetails.vue'
import RegisterSideBar from '../../sidebars/register/RegisterSideBar.vue'

// The register and schema detail pages draw the same three charts; the
// register detail page and the register sidebar resolve the same register.

jest.mock('vue3-apexcharts', () => ({}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@conduction/nextcloud-vue', () => ({}))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('../../components/schema/SchemaDraftBar.vue', () => ({}))
jest.mock('../../modals/register/EditRegisterFormDialog.vue', () => ({}))
jest.mock('../../services/registerOas.js', () => ({}))
jest.mock('../schemas/SchemaWorkflowTab.vue', () => ({}))
jest.mock('../schema/CalendarProviderTab.vue', () => ({}))
jest.mock('../schema/SchemaRulesTab.vue', () => ({}))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/Api.vue', () => ({}))
jest.mock('vue-material-design-icons/Calculator.vue', () => ({}))
jest.mock('vue-material-design-icons/CalendarMonth.vue', () => ({}))
jest.mock('vue-material-design-icons/ChartBar.vue', () => ({}))
jest.mock('vue-material-design-icons/ChartBox.vue', () => ({}))
jest.mock('vue-material-design-icons/Cog.vue', () => ({}))
jest.mock('vue-material-design-icons/Database.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({}))
jest.mock('vue-material-design-icons/Download.vue', () => ({}))
jest.mock('vue-material-design-icons/FileCodeOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocumentEditOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FolderOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Gavel.vue', () => ({}))
jest.mock('vue-material-design-icons/PackageVariantClosed.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/PlusCircleOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/TableEye.vue', () => ({}))
jest.mock('vue-material-design-icons/TextBoxOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Upload.vue', () => ({}))
jest.mock('@/services/getTheme.js', () => ({ getTheme: () => 'dark' }))
jest.mock('../../store/store.js', () => ({
	dashboardStore: {
		chartData: {},
		registers: [],
	},
	registerStore: { getRegisterItem: null },
	navigationStore: {},
	schemaStore: {},
	configurationStore: {},
	objectStore: {},
}))

const { dashboardStore, registerStore } = jest.requireMock('../../store/store.js')

const FILLED = {
	auditTrailActions: { labels: ['Mon', 'Tue'], series: [{ name: 'create', data: [1, 2] }] },
	objectsBySchema: { labels: ['A', 'B'], series: [3, 4] },
	objectsByRegister: { labels: ['R1'], series: [5] },
	objectsBySize: { labels: ['0-1KB', '1-10KB'], series: [6, 7] },
}

const CHART_DATA = {
	filled: FILLED,
	empty: {},
	nulls: {
		auditTrailActions: null,
		objectsBySchema: null,
		objectsByRegister: null,
		objectsBySize: null,
	},
}

const PAGES = { RegisterDetail, SchemaDetails }

const computedOf = (component, name) => component.computed[name].call({})

describe.each(Object.keys(CHART_DATA))('chart options with %s chart data', (mode) => {
	beforeEach(() => {
		dashboardStore.chartData = CHART_DATA[mode]
	})

	it.each([
		['RegisterDetail', 'auditTrailChartOptions'],
		['RegisterDetail', 'schemaChartOptions'],
		['RegisterDetail', 'sizeChartOptions'],
		['SchemaDetails', 'auditTrailChartOptions'],
		['SchemaDetails', 'registerChartOptions'],
		['SchemaDetails', 'sizeChartOptions'],
	])('%s.%s', (label, name) => {
		const component = PAGES[label]
		expect(computedOf(component, name)).toMatchSnapshot(
			`${component.name}.${name}`,
		)
	})
})

describe('the schema detail page without chart data', () => {
	it('still builds its options', () => {
		dashboardStore.chartData = undefined
		expect(computedOf(SchemaDetails, 'auditTrailChartOptions').xaxis.categories).toEqual([])
		expect(computedOf(SchemaDetails, 'registerChartOptions').labels).toEqual([])
		expect(computedOf(SchemaDetails, 'sizeChartOptions').xaxis.categories).toEqual([])
	})
})

describe.each([
	['RegisterDetail', RegisterDetail],
	['RegisterSideBar', RegisterSideBar],
])('%s.register', (label, component) => {
	const registers = [{ id: 7, title: 'Seven' }, { id: '8', title: 'Eight' }]
	const resolve = (route) => component.computed.register.call({ $route: route })

	beforeEach(() => {
		dashboardStore.registers = registers
		registerStore.getRegisterItem = null
	})

	it('prefers the register store item over the route', () => {
		registerStore.getRegisterItem = { id: '7' }
		expect(resolve({ params: { id: 8 } })).toBe(registers[0])
	})

	it('does not read the route when the store has an item', () => {
		registerStore.getRegisterItem = { id: 8 }
		expect(resolve(undefined)).toBe(registers[1])
	})

	it('falls back to the route param', () => {
		expect(resolve({ params: { id: '7' } })).toBe(registers[0])
	})

	it('is undefined without any id', () => {
		expect(resolve({ params: {} })).toBeUndefined()
	})

	it('is undefined for an unknown id', () => {
		expect(resolve({ params: { id: 99 } })).toBeUndefined()
	})

	it('throws without a route when the store has no item', () => {
		expect(() => resolve(undefined)).toThrow(TypeError)
	})
})
