/**
 * The register detail page: hydrating the register from the route, its stats,
 * the schemas it lists (inline or fetched by id), the configuration that
 * manages it, and the navigation actions. Hooks and methods run against a
 * plain `this` and stub stores.
 *
 * @spec exclude Client-side register detail page state.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import RegisterDetail from './RegisterDetail.vue'

jest.mock('@conduction/nextcloud-vue', () => ({ CnDetailPage: {} }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s, translatePlural: (app, s) => s }))
jest.mock('vue3-apexcharts', () => ({}))
jest.mock('vue-material-design-icons/Database.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({}))
jest.mock('vue-material-design-icons/FileCodeOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FolderOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/TableEye.vue', () => ({}))
jest.mock('../../modals/register/EditRegisterFormDialog.vue', () => ({}))
jest.mock('./DetailChartGrid.vue', () => ({}))
jest.mock('./activeRegister.js', () => ({ findActiveRegister: jest.fn() }))
jest.mock('@/services/getTheme.js', () => ({ getTheme: () => 'light' }))
jest.mock('../../store/store.js', () => ({
	configurationStore: { configurationList: [] },
	dashboardStore: { chartData: {}, fetchRegisters: jest.fn(), fetchAllChartData: jest.fn() },
	navigationStore: { setModal: jest.fn() },
	registerStore: { getRegisterItem: null, setRegisterItem: jest.fn(), getRegisterStats: jest.fn() },
	schemaStore: { setSchemaItem: jest.fn() },
}))

const { configurationStore, dashboardStore, navigationStore, registerStore, schemaStore } = jest.requireMock('../../store/store.js')
const { findActiveRegister } = jest.requireMock('./activeRegister.js')

/**
 * A plain page instance with its data, computed register and bound methods.
 *
 * @param {object} state Data and methods to replace
 * @return {object} The instance
 */
function page(state = {}) {
	const vm = {
		...RegisterDetail.data(),
		$route: { params: {} },
		$router: { push: jest.fn(() => Promise.resolve()) },
	}
	Object.defineProperty(vm, 'register', { get: () => RegisterDetail.computed.register.call(vm), configurable: true })
	for (const [name, fn] of Object.entries(RegisterDetail.methods)) {
		vm[name] = fn.bind(vm)
	}
	Object.assign(vm, state)
	return vm
}

let consoleError

beforeEach(() => {
	jest.clearAllMocks()
	registerStore.getRegisterItem = null
	configurationStore.configurationList = []
	dashboardStore.chartData = {}
	findActiveRegister.mockReturnValue(null)
	global.fetch = jest.fn()
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	consoleError.mockRestore()
	delete global.fetch
})

describe('RegisterDetail mounting', () => {
	it('sends the user back to the list when no register can be resolved', async () => {
		const vm = page()
		await RegisterDetail.mounted.call(vm)
		expect(vm.$router.push).toHaveBeenCalledWith('/registers')
		expect(vm.hydrating).toBe(false)
	})

	it('seeds the register from the route, fetches it when unknown, then loads stats, schemas and configuration', async () => {
		registerStore.setRegisterItem.mockImplementation((item) => {
			registerStore.getRegisterItem = item
		})
		const loadRegisterStats = jest.fn()
		const loadSchemas = jest.fn()
		const checkManagingConfiguration = jest.fn()
		const vm = page({ $route: { params: { id: '7' } }, loadRegisterStats, loadSchemas, checkManagingConfiguration })
		await RegisterDetail.mounted.call(vm)
		expect(registerStore.setRegisterItem).toHaveBeenCalledWith({ id: '7' })
		expect(dashboardStore.fetchRegisters).toHaveBeenCalled()
		expect(dashboardStore.fetchAllChartData).toHaveBeenCalled()
		expect(loadRegisterStats).toHaveBeenCalled()
		expect(loadSchemas).toHaveBeenCalled()
		expect(checkManagingConfiguration).toHaveBeenCalled()
		expect(vm.hydrating).toBe(false)
	})

	it('keeps going when the register fetch fails, and skips it for a known register', async () => {
		registerStore.getRegisterItem = { id: 7 }
		dashboardStore.fetchRegisters.mockRejectedValueOnce(new Error('down'))
		const vm = page({ $route: { params: { id: '7' } }, loadRegisterStats: jest.fn(), loadSchemas: jest.fn(), checkManagingConfiguration: jest.fn() })
		await RegisterDetail.mounted.call(vm)
		expect(registerStore.setRegisterItem).not.toHaveBeenCalled()
		expect(consoleError).toHaveBeenCalled()
		expect(vm.loadSchemas).toHaveBeenCalled()

		jest.clearAllMocks()
		findActiveRegister.mockReturnValue({ id: 7 })
		await RegisterDetail.mounted.call(vm)
		expect(dashboardStore.fetchRegisters).not.toHaveBeenCalled()
	})

	it('reloads schemas and configuration when the register changes', () => {
		const vm = page({ loadSchemas: jest.fn(), checkManagingConfiguration: jest.fn() })
		RegisterDetail.watch.register.handler.call(vm)
		expect(vm.loadSchemas).toHaveBeenCalled()
		expect(vm.checkManagingConfiguration).toHaveBeenCalled()
	})
})

describe('RegisterDetail stats', () => {
	it('loads the stats of the current register', async () => {
		registerStore.getRegisterItem = { id: 3 }
		registerStore.getRegisterStats.mockResolvedValue({ objects: 10 })
		const vm = page()
		await vm.loadRegisterStats()
		expect(registerStore.getRegisterStats).toHaveBeenCalledWith(3)
		expect(vm.registerStats).toEqual({ objects: 10 })
		expect(vm.statsLoading).toBe(false)
	})

	it('keeps the error of a failed stats load, and skips it without a register', async () => {
		const vm = page()
		await vm.loadRegisterStats()
		expect(registerStore.getRegisterStats).not.toHaveBeenCalled()

		registerStore.getRegisterItem = { id: 3 }
		registerStore.getRegisterStats.mockRejectedValue(new Error('boom'))
		await vm.loadRegisterStats()
		expect(vm.statsError).toBe('boom')
		expect(vm.statsLoading).toBe(false)
	})

	it('describes the schema pie chart', () => {
		const options = page().getSchemaChartOptions()
		expect(options.labels).toEqual(['Valid', 'Invalid', 'Deleted', 'Locked'])
		expect(options.tooltip.y.formatter(4)).toBe('4 objects')
	})

	it('builds the chart options from the dashboard chart labels', () => {
		dashboardStore.chartData = {
			auditTrailActions: { labels: ['a'] },
			objectsBySchema: { labels: ['s'] },
			objectsBySize: { labels: ['z'] },
		}
		const vm = page()
		expect(RegisterDetail.computed.auditTrailChartOptions.call(vm)).toBeTruthy()
		expect(RegisterDetail.computed.schemaChartOptions.call(vm)).toBeTruthy()
		expect(RegisterDetail.computed.sizeChartOptions.call(vm)).toBeTruthy()
	})
})

describe('RegisterDetail schemas', () => {
	it('keeps inline schemas and empties array properties', async () => {
		findActiveRegister.mockReturnValue({ id: 1, schemas: [{ id: 5, properties: [] }, { id: 6, properties: { a: {} } }] })
		const vm = page()
		await vm.loadSchemas()
		expect(vm.loadedSchemas).toEqual([{ id: 5, properties: {} }, { id: 6, properties: { a: {} } }])
		expect(global.fetch).not.toHaveBeenCalled()
		expect(vm.loadingSchemas).toBe(false)
	})

	it('fetches schemas given by id and drops the ones that fail', async () => {
		findActiveRegister.mockReturnValue({ id: 1, schemas: [8, 9, 10] })
		global.fetch.mockImplementation(async (url) => {
			if (url.endsWith('/8')) return { ok: true, json: async () => ({ id: 8, title: 'Eight' }) }
			if (url.endsWith('/9')) return { ok: false }
			throw new Error('network')
		})
		const vm = page()
		await vm.loadSchemas()
		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/schemas/8')
		expect(vm.loadedSchemas).toEqual([{ id: 8, title: 'Eight' }])
	})

	it('has no schemas without a register list', async () => {
		const vm = page({ loadedSchemas: [{ id: 1 }] })
		await vm.loadSchemas()
		expect(vm.loadedSchemas).toEqual([])
	})

	it('opens a schema in the edit modal', () => {
		page().editSchema({ id: 2 })
		expect(schemaStore.setSchemaItem).toHaveBeenCalledWith({ id: 2 })
		expect(navigationStore.setModal).toHaveBeenCalledWith('editSchema')
	})

	it('opens the objects of a schema in the table view', () => {
		registerStore.getRegisterItem = { id: 3 }
		const vm = page()
		vm.viewObjects({ id: 4 })
		expect(vm.$router.push).toHaveBeenCalledWith({ path: '/tables', query: { register: '3', schema: '4' } })

		vm.$router.push.mockClear()
		vm.viewObjects(null)
		registerStore.getRegisterItem = null
		vm.viewObjects({ id: 4 })
		expect(vm.$router.push).not.toHaveBeenCalled()
	})
})

describe('RegisterDetail managing configuration', () => {
	it('finds the configuration that lists the register', async () => {
		findActiveRegister.mockReturnValue({ id: 2 })
		configurationStore.configurationList = [{ id: 'a', registers: [1] }, { id: 'b' }, { id: 'c', registers: [2, 3] }]
		const vm = page()
		await vm.checkManagingConfiguration()
		expect(vm.managingConfiguration).toEqual({ id: 'c', registers: [2, 3] })
	})

	it('has none when no configuration lists it or there is no register', async () => {
		findActiveRegister.mockReturnValue({ id: 9 })
		configurationStore.configurationList = [{ id: 'a', registers: [1] }]
		const vm = page({ managingConfiguration: { id: 'old' } })
		await vm.checkManagingConfiguration()
		expect(vm.managingConfiguration).toBeNull()

		findActiveRegister.mockReturnValue(null)
		vm.managingConfiguration = { id: 'old' }
		await vm.checkManagingConfiguration()
		expect(vm.managingConfiguration).toBeNull()
	})
})
