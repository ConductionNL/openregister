/**
 * The audit-trail entry modal: it loads the schema and register of the entry,
 * formats change values for display, and navigates to the schema or register
 * while closing itself. Hooks and methods run against a plain `this` with stub
 * stores and router.
 *
 * @spec exclude Client-side audit-trail entry modal.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ViewObjectAuditTrail from './ViewObjectAuditTrail.vue'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('vue-material-design-icons/OpenInApp.vue', () => ({}))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn() },
	objectStore: { auditTrailItem: null, setAuditTrailItem: jest.fn() },
	registerStore: { getRegister: jest.fn(), setRegisterItem: jest.fn() },
	schemaStore: { getSchema: jest.fn(), setSchemaItem: jest.fn() },
}))

const { navigationStore, objectStore, registerStore, schemaStore } = jest.requireMock('../../store/store.js')

/**
 * A plain modal instance with its data and bound methods.
 *
 * @return {object} The instance
 */
function modal() {
	const vm = { ...ViewObjectAuditTrail.data(), $router: { push: jest.fn() } }
	for (const [name, fn] of Object.entries(ViewObjectAuditTrail.methods)) vm[name] = fn.bind(vm)
	return vm
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

beforeEach(() => {
	jest.clearAllMocks()
	objectStore.auditTrailItem = null
	schemaStore.getSchema.mockResolvedValue({ id: 2, title: 'Persons' })
	registerStore.getRegister.mockResolvedValue({ id: 1, title: 'Reg' })
})

describe('ViewObjectAuditTrail', () => {
	it('loads the schema and register of the entry on mount', async () => {
		objectStore.auditTrailItem = { schema: 2, register: 1, action: 'update' }
		const vm = modal()
		ViewObjectAuditTrail.mounted.call(vm)
		expect(vm.schemaLoading).toBe(true)
		expect(vm.registerLoading).toBe(true)
		await flush()
		expect(schemaStore.getSchema).toHaveBeenCalledWith(2)
		expect(registerStore.getRegister).toHaveBeenCalledWith(1)
		expect(vm.auditTrail.action).toBe('update')
		expect(vm.schemaItem).toEqual({ id: 2, title: 'Persons' })
		expect(vm.registerItem).toEqual({ id: 1, title: 'Reg' })
		expect(vm.schemaLoading).toBe(false)
		expect(vm.registerLoading).toBe(false)
	})

	it('starts from an empty entry when none is set', () => {
		const vm = modal()
		ViewObjectAuditTrail.mounted.call(vm)
		expect(vm.auditTrail).toEqual({})
	})

	it('formats change values', () => {
		const vm = modal()
		expect(vm.formatValue(null)).toBe('N/A')
		expect(vm.formatValue(undefined)).toBe('N/A')
		expect(vm.formatValue({ a: 1 })).toBe('{\n  "a": 1\n}')
		expect(vm.formatValue(true)).toBe('True')
		expect(vm.formatValue(false)).toBe('False')
		expect(vm.formatValue(0)).toBe(0)
		expect(vm.formatValue('text')).toBe('text')
	})

	it('closes and clears the entry', () => {
		modal().closeDialog()
		expect(navigationStore.setModal).toHaveBeenCalledWith(null)
		expect(objectStore.setAuditTrailItem).toHaveBeenCalledWith(null)
	})

	it('goes to the schema or register of the entry', () => {
		const vm = modal()
		vm.schemaItem = { id: 2 }
		vm.registerItem = { id: 1 }
		vm.goToSchema()
		expect(vm.$router.push).toHaveBeenCalledWith('/schemas')
		expect(schemaStore.setSchemaItem).toHaveBeenCalledWith({ id: 2 })
		vm.goToRegister()
		expect(vm.$router.push).toHaveBeenCalledWith('/registers')
		expect(registerStore.setRegisterItem).toHaveBeenCalledWith({ id: 1 })
		expect(navigationStore.setModal).toHaveBeenCalledTimes(2)
		expect(objectStore.setAuditTrailItem).toHaveBeenCalledTimes(2)
	})
})
