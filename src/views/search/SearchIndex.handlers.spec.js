/**
 * The search page's list wiring: whether a scope is chosen, the page title,
 * the selected ids, the schema with inherited and ordered properties, the
 * view presentation settings, and the row, sort and paging handlers that
 * talk to the object store and open modals. Computed values and methods run
 * against a plain `this`.
 *
 * @spec exclude Client-side search page list wiring.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
// Mock the store singletons the component imports so we can assert dispatches.
import SearchIndex from './SearchIndex.vue'
import {
	navigationStore as mockNavigationStore,
	objectStore as mockObjectStore,
	viewsStore as mockViewsStore,
} from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn(), modal: null },
	objectStore: {},
	registerStore: { registerList: [] },
	schemaStore: { schemaList: [] },
	viewsStore: {},
}))
// The inline cell editor has its own spec; here it only has to resolve.
jest.mock('../../components/tables/EditableCell.vue', () => ({
	__esModule: true,
	default: { name: 'EditableCell', render: () => null },
}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (_app, text) => text,
}))
jest.mock(
	'@nextcloud/vue',
	() =>
		new Proxy(
			{ __esModule: true },
			{
				get: (target, prop) =>
					prop in target
						? target[prop]
						: { name: String(prop), render: () => null },
			},
		),
)
jest.mock(
	'vue-material-design-icons/Pencil.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/ContentCopy.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/TrashCanOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)

jest.mock(
	'vue-material-design-icons/CheckCircle.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/DatabaseExport.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)
jest.mock(
	'vue-material-design-icons/Merge.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
)

const { registerStore, schemaStore } = jest.requireMock('../../store/store.js')
const computedCall = (key, ctx) => SearchIndex.computed[key].call(ctx)
const methodCall = (key, ctx, ...args) => SearchIndex.methods[key].call(ctx, ...args)

beforeEach(() => {
	jest.clearAllMocks()
	registerStore.registerList = []
	registerStore.registerItem = null
	registerStore.setRegisterItem = jest.fn()
	schemaStore.schemaList = []
	schemaStore.schemaItem = null
	schemaStore.setSchemaItem = jest.fn()
	Object.assign(mockObjectStore, {
		searchCollection: [],
		searchParams: { register: null, schema: null },
		searchSchema: null,
		selectedObjects: [],
		setObjectItem: jest.fn(),
		setSelectedObjects: jest.fn(),
		refetchSearchCollection: jest.fn(),
		updateSearchParams: jest.fn(),
	})
	Object.assign(mockViewsStore, { activeView: null, fetchKanbanBoard: jest.fn() })
})

describe('SearchIndex scope and title', () => {
	it('knows whether a register and a schema are chosen', () => {
		expect(computedCall('hasSelectedRegisters', {})).toBe(false)
		mockObjectStore.searchParams = { register: 0, schema: undefined }
		expect(computedCall('hasSelectedRegisters', {})).toBe(true)
		expect(computedCall('hasSelectedSchemas', {})).toBe(false)
	})

	it('titles the page by register and schema, preferring labels', () => {
		expect(computedCall('pageTitle', { hasSelectedRegisters: false })).toBe('No register selected')

		registerStore.registerList = [{ id: 1, title: 'Reg', label: 'Register One' }]
		schemaStore.schemaList = [{ id: 2, title: 'Persons' }]
		mockObjectStore.searchParams = { register: 1, schema: 2 }
		expect(computedCall('pageTitle', { hasSelectedRegisters: true, hasSelectedSchemas: false })).toBe('Register One / No schema selected')
		expect(computedCall('pageTitle', { hasSelectedRegisters: true, hasSelectedSchemas: true })).toBe('Register One / Persons')

		mockObjectStore.searchParams = { register: 8, schema: 9 }
		expect(computedCall('pageTitle', { hasSelectedRegisters: true, hasSelectedSchemas: true })).toBe('Register / Schema')
	})

	it('gives the selected ids as strings', () => {
		mockObjectStore.selectedObjects = [1, 'b']
		expect(computedCall('selectedIdsForPage', {})).toEqual(['1', 'b'])
		mockObjectStore.selectedObjects = null
		expect(computedCall('selectedIdsForPage', {})).toEqual([])
	})

	it('merges inherited properties and makes the order a number', () => {
		schemaStore.schemaList = [{ id: 1, uuid: 'p', properties: { a: { title: 'parent' }, b: { title: 'b', order: '2' } } }]
		mockObjectStore.searchSchema = { id: 5, allOf: ['p'], properties: { a: { title: 'own', order: '1' } } }
		expect(computedCall('normalizedSchema', {}).properties).toEqual({
			a: { title: 'own', order: 1 },
			b: { title: 'b', order: 2 },
		})
		mockObjectStore.searchSchema = { id: 6 }
		expect(computedCall('normalizedSchema', {})).toEqual({ id: 6 })
	})

	it('reads the kanban and calendar settings of the view, empty without one', () => {
		expect(computedCall('kanbanConfig', { activeView: null })).toEqual({})
		expect(computedCall('calendarConfig', { activeView: { presentation: {} } })).toEqual({})
		const view = { presentation: { kanban: { groupBy: 'status' }, calendar: { dateField: 'due' } } }
		expect(computedCall('kanbanConfig', { activeView: view })).toEqual({ groupBy: 'status' })
		expect(computedCall('calendarConfig', { activeView: view })).toEqual({ dateField: 'due' })
	})
})

describe('SearchIndex list handlers', () => {
	it('opens a new record only with a register and schema chosen', () => {
		const ctx = { hasSelectedRegisters: true, hasSelectedSchemas: false }
		methodCall('handleAddObject', ctx)
		expect(mockNavigationStore.setModal).not.toHaveBeenCalled()

		registerStore.registerItem = { id: 1 }
		schemaStore.schemaItem = { id: 2 }
		ctx.hasSelectedSchemas = true
		methodCall('handleAddObject', ctx)
		expect(ctx.isAddingNewObject).toBe(true)
		expect(mockObjectStore.setObjectItem).toHaveBeenCalledWith(null)
		expect(registerStore.setRegisterItem).toHaveBeenCalledWith({ id: 1 })
		expect(schemaStore.setSchemaItem).toHaveBeenCalledWith({ id: 2 })
		expect(mockNavigationStore.setModal).toHaveBeenCalledWith('viewObject')
	})

	it('refreshes, sorts and pages through the store', () => {
		methodCall('handleRefresh', {})
		methodCall('handleSort', {}, { key: 'title', order: 'desc' })
		methodCall('handlePageChanged', {}, 3)
		methodCall('handlePageSizeChanged', {}, 50)
		expect(mockObjectStore.updateSearchParams.mock.calls).toEqual([
			[{ sortKey: 'title', sortOrder: 'desc' }],
			[{ page: 3 }],
			[{ page: 1, limit: 50 }],
		])
		expect(mockObjectStore.refetchSearchCollection).toHaveBeenCalledTimes(4)
	})

	it('opens the record, copy and delete dialogs for a row', () => {
		const row = { id: 4 }
		methodCall('handleRowClick', {}, row)
		expect(mockNavigationStore.setModal).toHaveBeenCalledWith('viewObject')
		methodCall('handleCopyRow', {}, row)
		expect(mockNavigationStore.setDialog).toHaveBeenCalledWith('copyObject')
		methodCall('handleDeleteRow', {}, row)
		expect(mockNavigationStore.setDialog).toHaveBeenCalledWith('deleteObject')
		expect(mockObjectStore.setObjectItem).toHaveBeenCalledTimes(3)
	})

	it('selects rows and stages a mass delete', () => {
		methodCall('handleSelect', {}, ['1'])
		expect(mockObjectStore.setSelectedObjects).toHaveBeenCalledWith(['1'])

		const ctx = { normalizedObjects: [{ id: 1, '@self': { id: 'u-1' } }, { id: 2 }] }
		ctx.stageSelection = (ids) => SearchIndex.methods.stageSelection.call(ctx, ids)
		methodCall('handleMassDelete', ctx, ['1', '2'])
		expect(mockObjectStore.setSelectedObjects).toHaveBeenLastCalledWith(['u-1', 2])
		expect(mockNavigationStore.setDialog).toHaveBeenCalledWith('massDeleteObject')
	})

	it('logs a rejected kanban move only with a reason', () => {
		const warn = jest.spyOn(console, 'warn').mockImplementation(() => {})
		methodCall('handleKanbanMoveRejected', {}, {})
		expect(warn).not.toHaveBeenCalled()
		methodCall('handleKanbanMoveRejected', {}, { reason: 'locked' })
		expect(warn).toHaveBeenCalledWith('[SearchIndex] Kanban move rejected:', 'locked')
		warn.mockRestore()
	})

	it('ends adding a record when the record modal closes', () => {
		const ctx = { isAddingNewObject: true }
		SearchIndex.watch['navigationStore.modal'].call(ctx, null, 'other')
		expect(ctx.isAddingNewObject).toBe(true)
		SearchIndex.watch['navigationStore.modal'].call(ctx, null, 'viewObject')
		expect(ctx.isAddingNewObject).toBe(false)
	})

	it('asks for update rights and fetches a kanban board on mount', () => {
		const ctx = { activeViewId: 'v', presentationType: 'kanban', requestUpdateRights: jest.fn(), fetchKanbanBoard: jest.fn() }
		SearchIndex.mounted.call(ctx)
		expect(ctx.requestUpdateRights).toHaveBeenCalled()
		expect(ctx.fetchKanbanBoard).toHaveBeenCalled()
		const table = { activeViewId: 'v', presentationType: 'table', requestUpdateRights: jest.fn(), fetchKanbanBoard: jest.fn() }
		SearchIndex.mounted.call(table)
		expect(table.fetchKanbanBoard).not.toHaveBeenCalled()
	})
})
