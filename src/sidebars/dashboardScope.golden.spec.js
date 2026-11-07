/**
 * Golden record of the register statistics scope the dashboard and registers
 * sidebars share: which registers count, how many schemas they hold, and what
 * picking a register or schema does to the stores.
 *
 * @spec exclude Golden record of client-side sidebar plumbing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import DashboardSideBar from './dashboard/DashboardSideBar.vue'
import RegistersSideBar from './register/RegistersSideBar.vue'
import {
	dashboardStore,
	objectStore,
	registerStore,
	schemaStore,
} from '../store/store.js'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@conduction/nextcloud-vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/ChartBar.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/PackageVariantClosed.vue', () => ({}))
jest.mock('vue-material-design-icons/Table.vue', () => ({}))
jest.mock('vue-material-design-icons/TextBoxOutline.vue', () => ({}))
jest.mock('./dashboard/StatisticsTotalsTable.vue', () => ({}))
jest.mock('../store/store.js', () => ({
	dashboardStore: { registers: [], preload: jest.fn() },
	objectStore: {
		initializeProperties: jest.fn(),
		refreshObjectList: jest.fn(),
	},
	registerStore: { setRegisterItem: jest.fn() },
	schemaStore: { setSchemaItem: jest.fn() },
	navigationStore: {},
}))

/**
 * The options of one kind a component ends up with: its mixins' first, its
 * own on top.
 *
 * @param {object} component The component options
 * @param {string} kind 'methods' or 'computed'
 * @return {object} The options by name
 */
function merged(component, kind) {
	return Object.assign(
		{},
		...(component.mixins || []).map((mixin) => mixin[kind] || {}),
		component[kind],
	)
}

/**
 * A bare instance of a component whose computed values are plain getters.
 *
 * @param {object} component The component options
 * @return {object} The instance
 */
function instanceOf(component) {
	const vm = { ...merged(component, 'methods') }
	for (const [name, fn] of Object.entries(merged(component, 'computed'))) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm) })
	}
	return vm
}

describe.each([
	['DashboardSideBar', DashboardSideBar],
	['RegistersSideBar', RegistersSideBar],
])('%s statistics scope', (_name, component) => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('counts only real registers and their schemas', () => {
		dashboardStore.registers = [
			{ title: 'System Totals', schemas: [1, 2, 3] },
			{ title: 'A', schemas: [1, 2] },
			{ title: 'Orphaned Items', schemas: [1] },
			{ title: 'B' },
			{ title: 'C', schemas: [1] },
		]
		const vm = instanceOf(component)

		expect(vm.filteredRegisters.map((r) => r.title)).toEqual(['A', 'B', 'C'])
		expect(vm.totalSchemas).toBe(3)
	})

	it('clears the schema when a register is picked', () => {
		const vm = instanceOf(component)
		const register = { id: 4 }

		vm.handleRegisterChange(register)

		expect(registerStore.setRegisterItem).toHaveBeenCalledWith(register)
		expect(schemaStore.setSchemaItem).toHaveBeenCalledWith(null)
		expect(objectStore.refreshObjectList).not.toHaveBeenCalled()
	})

	it('loads the objects of a picked schema', async () => {
		const vm = instanceOf(component)
		const schema = { id: 9 }

		await vm.handleSchemaChange(schema)

		expect(schemaStore.setSchemaItem).toHaveBeenCalledWith(schema)
		expect(objectStore.initializeProperties).toHaveBeenCalledWith(schema)
		expect(objectStore.refreshObjectList).toHaveBeenCalledTimes(1)
	})

	it('loads nothing when the schema is cleared', async () => {
		const vm = instanceOf(component)

		await vm.handleSchemaChange(null)

		expect(schemaStore.setSchemaItem).toHaveBeenCalledWith(null)
		expect(objectStore.initializeProperties).not.toHaveBeenCalled()
		expect(objectStore.refreshObjectList).not.toHaveBeenCalled()
	})
})
