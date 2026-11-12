/**
 * The register sidebar: the active tab through the store, the object
 * breakdown and size labels, and the size, API specification and schema
 * actions with their error notices. Computed values and methods run against
 * a plain `this` with stub stores and services.
 *
 * @spec exclude Client-side register sidebar actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { showError } from '@nextcloud/dialogs'
import RegisterSideBar from './RegisterSideBar.vue'

jest.mock('@conduction/nextcloud-vue', () => ({ CnItemCard: {}, CnKpiGrid: {}, CnStatsBlock: {} }))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn() }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s, translatePlural: (app, s) => s }))
jest.mock('vue-material-design-icons/Api.vue', () => ({}))
jest.mock('vue-material-design-icons/Calculator.vue', () => ({}))
jest.mock('vue-material-design-icons/ChartBar.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({}))
jest.mock('vue-material-design-icons/Download.vue', () => ({}))
jest.mock('vue-material-design-icons/FileCodeOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/PackageVariantClosed.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/TextBoxOutline.vue', () => ({}))
jest.mock('../../modals/register/EditRegisterFormDialog.vue', () => ({}))
jest.mock('../../services/formatBytes.js', () => ({ __esModule: true, default: (bytes) => `${bytes} B` }))
jest.mock('../../services/registerOas.js', () => ({ downloadRegisterOas: jest.fn(), viewRegisterOas: jest.fn() }))
jest.mock('../../views/register/activeRegister.js', () => ({ findActiveRegister: jest.fn() }))
jest.mock('../../store/store.js', () => ({
	dashboardStore: { calculateSizes: jest.fn(), fetchRegisters: jest.fn() },
	navigationStore: { setModal: jest.fn() },
	registerStore: { getActiveTab: 'stats-tab', setActiveTab: jest.fn(), setSchemaItem: jest.fn() },
}))

const { dashboardStore, navigationStore, registerStore } = jest.requireMock('../../store/store.js')
const { downloadRegisterOas, viewRegisterOas } = jest.requireMock('../../services/registerOas.js')
const { findActiveRegister } = jest.requireMock('../../views/register/activeRegister.js')
const { computed, methods } = RegisterSideBar

/**
 * A plain sidebar instance with computed getters and bound methods.
 *
 * @return {object} The instance
 */
function sidebar() {
	const vm = { ...RegisterSideBar.data.call({}), $route: { params: { id: '1' } } }
	Object.defineProperty(vm, 'register', { get: () => computed.register.call(vm) })
	Object.defineProperty(vm, 'objectsBreakdown', { get: () => computed.objectsBreakdown.call(vm) })
	Object.defineProperty(vm, 'activeTab', {
		get: () => computed.activeTab.get.call(vm),
		set: (value) => {
			computed.activeTab.set.call(vm, value)
		},
	})
	for (const [name, fn] of Object.entries(methods)) vm[name] = fn.bind(vm)
	return vm
}

let consoleError

beforeEach(() => {
	jest.clearAllMocks()
	findActiveRegister.mockReturnValue({ id: 7, stats: { objects: { size: 2048, invalid: 2 } } })
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => consoleError.mockRestore())

describe('RegisterSideBar', () => {
	it('reads and writes the active tab through the store', () => {
		const vm = sidebar()
		expect(vm.activeTab).toBe('stats-tab')
		vm.activeTab = 'schemas-tab'
		expect(registerStore.setActiveTab).toHaveBeenCalledWith('schemas-tab')
		expect(findActiveRegister).toHaveBeenCalledTimes(0)
		expect(vm.register.id).toBe(7)
		expect(findActiveRegister).toHaveBeenCalledWith({ params: { id: '1' } })
	})

	it('breaks the objects down with zero defaults and a size label', () => {
		expect(sidebar().objectsBreakdown).toEqual({ size: '2048 B', invalid: 2, deleted: 0, locked: 0 })
	})

	it('labels a size, or nothing without one', () => {
		const vm = sidebar()
		expect(vm.sizeBreakdown(0)).toBeNull()
		expect(vm.sizeBreakdown(10)).toEqual({ size: '10 B' })
	})

	it('recalculates sizes and reloads the registers', async () => {
		await sidebar().calculateSizes()
		expect(dashboardStore.calculateSizes).toHaveBeenCalledWith(7)
		expect(dashboardStore.fetchRegisters).toHaveBeenCalled()
	})

	it('reports a failed size calculation', async () => {
		dashboardStore.calculateSizes.mockRejectedValueOnce(new Error('x'))
		await sidebar().calculateSizes()
		expect(showError).toHaveBeenCalledWith('Failed to calculate sizes')
		expect(dashboardStore.fetchRegisters).not.toHaveBeenCalled()
	})

	it('downloads and views the API specification of the register', async () => {
		const vm = sidebar()
		await vm.downloadOas()
		expect(downloadRegisterOas).toHaveBeenCalledWith(vm.register)
		vm.viewOasDoc()
		expect(viewRegisterOas).toHaveBeenCalledWith(7)

		downloadRegisterOas.mockRejectedValueOnce(new Error('x'))
		await vm.downloadOas()
		expect(showError).toHaveBeenCalledWith('Failed to download API specification')
	})

	it('does nothing without an active register', async () => {
		findActiveRegister.mockReturnValue(null)
		const vm = sidebar()
		await vm.calculateSizes()
		await vm.downloadOas()
		vm.viewOasDoc()
		expect(dashboardStore.calculateSizes).not.toHaveBeenCalled()
		expect(downloadRegisterOas).not.toHaveBeenCalled()
		expect(viewRegisterOas).not.toHaveBeenCalled()
	})

	it('opens a schema in the edit modal', () => {
		sidebar().editSchema({ id: 3 })
		expect(registerStore.setSchemaItem).toHaveBeenCalledWith({ id: 3 })
		expect(navigationStore.setModal).toHaveBeenCalledWith('editSchema')
	})
})
