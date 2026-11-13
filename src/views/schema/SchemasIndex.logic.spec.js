/**
 * The schemas list page: its ordering, header filters, paging, empty-state
 * label, row classes, mount-time load and the refresh, paging and selection
 * handlers. Hooks, computed values and methods run against a plain `this` and
 * stub stores.
 *
 * @spec exclude Client-side schema list state.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import SchemasIndex from './SchemasIndex.vue'

jest.mock('@conduction/nextcloud-vue', () => ({ CnIndexPage: {} }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one, many) => many,
}))
jest.mock('../../components/shared/ActionMenu.vue', () => ({}))
jest.mock('../../components/shared/ManagedBadge.vue', () => ({}))
jest.mock('../../components/cards/RegisterSchemaCard.vue', () => ({}))
jest.mock('../IndexRowDescription.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	configurationStore: { configurationList: [], refreshConfigurationList: jest.fn() },
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	schemaStore: {
		schemaList: [],
		pagination: {},
		refreshSchemaList: jest.fn(),
		setPagination: jest.fn(),
		setSchemaItem: jest.fn(),
	},
}))

const { configurationStore, schemaStore } = jest.requireMock('../../store/store.js')

const COMPUTED = ['orderedSchemas', 'paginatedSchemas', 'paginationData', 'emptyContentName', 'tableColumns']

/**
 * A plain page instance with its data, live computed values and bound methods.
 *
 * @param {object} state Data to replace
 * @return {object} The instance
 */
function page(state = {}) {
	const vm = { ...SchemasIndex.data(), ...state }
	for (const name of COMPUTED) {
		Object.defineProperty(vm, name, {
			get: () => SchemasIndex.computed[name].call(vm),
			configurable: true,
		})
	}
	for (const [name, method] of Object.entries(SchemasIndex.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

/**
 * The ids of a list of schemas.
 *
 * @param {Array<object>} schemas The schemas
 * @return {Array<number>} Their ids
 */
function ids(schemas) {
	return schemas.map((schema) => schema.id)
}

let consoleError

beforeEach(() => {
	jest.clearAllMocks()
	schemaStore.schemaList = []
	schemaStore.pagination = {}
	configurationStore.configurationList = []
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	consoleError.mockRestore()
})

describe('SchemasIndex ordering and filtering', () => {
	it('lists the highest id first until a header is sorted', () => {
		schemaStore.schemaList = [{ id: 2, title: 'b' }, { id: 7, title: 'a' }, { id: 4, title: 'c' }]

		expect(ids(page().orderedSchemas)).toEqual([7, 4, 2])
	})

	it('sorts by the clicked header and returns to the first page', () => {
		schemaStore.schemaList = [{ id: 2, title: 'b' }, { id: 7, title: 'a' }, { id: 4, title: 'c' }]
		schemaStore.pagination = { page: 3, limit: 10 }
		const vm = page()

		vm.onSort({ key: 'title', order: 'desc' })

		expect(ids(vm.orderedSchemas)).toEqual([4, 2, 7])
		expect(schemaStore.setPagination).toHaveBeenCalledWith(1, 10)
	})

	it('filters on the number of properties rather than the property map', () => {
		schemaStore.schemaList = [
			{ id: 1, title: 'one', properties: { a: {} } },
			{ id: 2, title: 'two', properties: { a: {}, b: {} } },
			{ id: 3, title: 'none' },
		]
		const vm = page()

		vm.onFilterChange({ key: 'properties', values: [2] })

		expect(vm.activeFilters).toEqual({ properties: ['2'] })
		expect(ids(vm.orderedSchemas)).toEqual([2])
		expect(schemaStore.setPagination).toHaveBeenCalledWith(1, 20)
	})

	it('filters on any other field by its own value', () => {
		schemaStore.schemaList = [{ id: 1, title: 'Person' }, { id: 2, title: 'Address' }]
		const vm = page({ activeFilters: { title: ['Address'] } })

		expect(ids(vm.orderedSchemas)).toEqual([2])
	})
})

describe('SchemasIndex paging', () => {
	it('defaults to page 1 of 20 and counts the pages', () => {
		schemaStore.schemaList = Array.from({ length: 45 }, (_, i) => ({ id: i + 1 }))

		expect(page().paginationData).toEqual({ page: 1, pages: 3, total: 45, limit: 20 })
	})

	it('slices the ordered list to the current page', () => {
		schemaStore.schemaList = Array.from({ length: 7 }, (_, i) => ({ id: i + 1 }))
		schemaStore.pagination = { page: 2, limit: 3 }

		expect(ids(page().paginatedSchemas)).toEqual([4, 3, 2])
	})

	it('keeps the page size on a page change and starts over on a size change', () => {
		schemaStore.pagination = { page: 1, limit: 50 }
		const vm = page()

		vm.onPageChanged(4)
		vm.onPageSizeChanged(10)

		expect(schemaStore.setPagination.mock.calls).toEqual([[4, 50], [1, 10]])
	})
})

describe('SchemasIndex empty state and columns', () => {
	it('says there are no schemas when the store holds none', () => {
		expect(page().emptyContentName).toBe('No schemas found')
	})

	it('says nothing matches when the filters hide every schema', () => {
		schemaStore.schemaList = [{ id: 1, title: 'Person' }]

		expect(page({ activeFilters: { title: ['Other'] } }).emptyContentName).toBe('No matches')
	})

	it('falls back to the loading label while schemas are shown', () => {
		schemaStore.schemaList = [{ id: 1, title: 'Person' }]

		expect(page().emptyContentName).toBe('Loading schemas...')
	})

	it('offers a sortable title and an integer properties column first', () => {
		const columns = page().tableColumns

		expect(columns.slice(0, 2)).toEqual([
			{ key: 'title', label: 'Title', sortable: true },
			{ key: 'properties', label: 'Properties', type: 'integer' },
		])
		expect(columns.length).toBeGreaterThan(2)
	})
})

describe('SchemasIndex row state', () => {
	it('marks an externally managed schema as managed', () => {
		configurationStore.configurationList = [{ id: 1, sourceType: 'github', schemas: [{ id: 5 }] }]
		const vm = page()

		expect(vm.getRowClass({ id: 5, stats: { objects: { total: 3 } } })).toBe('viewTableRow--managed')
		expect(vm.isManagedByExternalConfig({ id: 5 })).toBe(true)
		expect(vm.isManagedByLocalConfig({ id: 5 })).toBe(false)
	})

	it('marks a locally managed schema as local', () => {
		configurationStore.configurationList = [{ id: 1, sourceType: 'manual', schemas: [{ id: 5 }] }]
		const vm = page()

		expect(vm.getRowClass({ id: 5 })).toBe('viewTableRow--local')
		expect(vm.isManagedByLocalConfig({ id: 5 })).toBe(true)
	})

	it('marks an unmanaged schema in use only while it has objects', () => {
		const vm = page()

		expect(vm.getRowClass({ id: 5, stats: { objects: { total: 1 } } })).toBe('viewTableRow--in-use')
		expect(vm.getRowClass({ id: 5, stats: { objects: { total: 0 } } })).toBe('')
		expect(vm.hasObjects({ id: 5 })).toBe(false)
	})

	it('finds no managing configuration for a schema without an id', () => {
		configurationStore.configurationList = [{ id: 1, sourceType: 'github', schemas: [{ id: undefined }] }]

		expect(page().getManagingConfiguration({ title: 'new' })).toBe(null)
		expect(page().getManagingConfiguration(null)).toBe(null)
	})

	it('skips configurations that list no schemas', () => {
		configurationStore.configurationList = [{ id: 1 }, { id: 2, schemas: [{ id: 5 }] }]

		expect(page().getManagingConfiguration({ id: 5 })).toEqual({ id: 2, schemas: [{ id: 5 }] })
	})

	it('remembers the selected ids', () => {
		const vm = page()

		vm.onSelect([3, 4])

		expect(vm.selectedSchemas).toEqual([3, 4])
	})
})

describe('SchemasIndex loading', () => {
	it('loads the schemas and configurations on mount', async () => {
		await SchemasIndex.mounted.call(page())

		expect(schemaStore.refreshSchemaList).toHaveBeenCalledTimes(1)
		expect(configurationStore.refreshConfigurationList).toHaveBeenCalledTimes(1)
		expect(consoleError).not.toHaveBeenCalled()
	})

	it('logs a failed mount-time load instead of throwing', async () => {
		const failure = new Error('offline')
		schemaStore.refreshSchemaList.mockRejectedValueOnce(failure)

		await expect(SchemasIndex.mounted.call(page())).resolves.toBeUndefined()
		expect(consoleError).toHaveBeenCalledWith('Failed to load data:', failure)
	})

	it('flags the refresh while it runs and clears it afterwards', async () => {
		const vm = page()
		let during
		schemaStore.refreshSchemaList.mockImplementationOnce(() => {
			during = vm.isRefreshing
			return Promise.resolve()
		})

		await vm.handleRefresh()

		expect(during).toBe(true)
		expect(vm.isRefreshing).toBe(false)
	})

	it('clears the refresh flag when the refresh fails', async () => {
		schemaStore.refreshSchemaList.mockRejectedValueOnce(new Error('offline'))
		const vm = page()

		await expect(vm.handleRefresh()).rejects.toThrow('offline')
		expect(vm.isRefreshing).toBe(false)
	})
})
