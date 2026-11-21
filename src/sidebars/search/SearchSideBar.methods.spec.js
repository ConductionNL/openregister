/**
 * The search sidebar's methods: the route query it writes and compares, the
 * register and schema selection, search terms, facet configuration and facet
 * options. Each method runs against a plain `this` and stub stores.
 *
 * @spec exclude Client-side search sidebar state handling.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import SearchSideBar from './SearchSideBar.vue'

jest.mock('@conduction/nextcloud-vue', () => ({ CnIndexSidebar: {} }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/Filter.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Star.vue', () => ({}))
jest.mock('vue-material-design-icons/StarOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/ViewDashboardOutline.vue', () => ({}))
jest.mock('../../modals/view/DeleteView.vue', () => ({}))
jest.mock('../../modals/view/EditView.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {
		searchParams: { filters: {} },
		pagination: {},
		updateSearchParams: jest.fn(),
		setSearchParams: jest.fn(),
		refetchSearchCollection: jest.fn(),
		clearSearchCollection: jest.fn(),
		setSearchVisibleColumns: jest.fn(),
	},
	registerStore: { registerList: [], setRegisterItem: jest.fn() },
	schemaStore: { schemaList: [], setSchemaItem: jest.fn() },
	viewsStore: { clearActiveView: jest.fn(), fetchView: jest.fn() },
}))

const { objectStore, registerStore, schemaStore, viewsStore } = jest.requireMock('../../store/store.js')
const { methods, computed } = SearchSideBar

/**
 * A plain component instance: the methods bound to a state object.
 *
 * @param {object} state The data and computed values the methods read
 * @return {object} The instance
 */
function sidebar(state = {}) {
	const vm = {
		selectedRegisters: [],
		selectedSchemas: [],
		searchTerms: [],
		searchQuery: '',
		facetFilters: {},
		enabledFacets: {},
		facetableFields: null,
		facetData: null,
		expandedSchemas: {},
		$route: { path: '/tables', query: {} },
		$router: { replace: jest.fn() },
		...state,
	}
	for (const [name, fn] of Object.entries(methods)) {
		if (!(name in state)) vm[name] = fn.bind(vm)
	}
	Object.defineProperty(vm, 'canSearch', {
		get: () => computed.canSearch.call(vm),
		configurable: true,
	})
	return vm
}

beforeEach(() => {
	jest.clearAllMocks()
	objectStore.searchParams = { filters: {} }
	objectStore.pagination = {}
	objectStore.searchFacets = undefined
	objectStore.searchSchema = undefined
	registerStore.registerList = []
	schemaStore.schemaList = []
})

describe('SearchSideBar route query', () => {
	it('builds a query of the selected registers, schemas and terms', () => {
		const vm = sidebar({ selectedRegisters: [1, 2], selectedSchemas: [5], searchTerms: ['a', 'b'] })
		expect(vm.buildQueryFromState()).toEqual({ register: '1,2', schema: '5', q: 'a,b' })
		expect(sidebar().buildQueryFromState()).toEqual({})
	})

	it('compares queries by keys and string values', () => {
		const vm = sidebar()
		expect(vm.queriesEqual({ a: 1, b: '2' }, { b: 2, a: '1' })).toBe(true)
		expect(vm.queriesEqual({ a: 1 }, { a: 1, b: 2 })).toBe(false)
		expect(vm.queriesEqual({ a: 1 }, { b: 1 })).toBe(false)
		expect(vm.queriesEqual({ a: 1 }, { a: 2 })).toBe(false)
	})

	it('replaces the route only on /tables and only when the query changed', () => {
		const vm = sidebar({ selectedRegisters: [1] })
		vm.updateRouteQueryFromState()
		expect(vm.$router.replace).toHaveBeenCalledWith({ path: '/tables', query: { register: '1' } })

		const same = sidebar({ selectedRegisters: [1], $route: { path: '/tables', query: { register: '1' } } })
		same.updateRouteQueryFromState()
		expect(same.$router.replace).not.toHaveBeenCalled()

		const elsewhere = sidebar({ selectedRegisters: [1], $route: { path: '/dashboard', query: {} } })
		elsewhere.updateRouteQueryFromState()
		expect(elsewhere.$router.replace).not.toHaveBeenCalled()
	})
})

describe('SearchSideBar store events', () => {
	it('splits the typed search into terms and refetches', () => {
		const vm = sidebar()
		vm.onSearchInput('  foo   bar ')
		expect(objectStore.updateSearchParams).toHaveBeenCalledWith({ search: '  foo   bar ' })
		expect(vm.searchTerms).toEqual(['foo', 'bar'])
		expect(objectStore.refetchSearchCollection).toHaveBeenCalled()

		vm.onSearchInput(null)
		expect(objectStore.updateSearchParams).toHaveBeenLastCalledWith({ search: '' })
		expect(vm.searchTerms).toEqual([])
	})

	it('merges a filter change into the store filters and the local facet filters', () => {
		objectStore.searchParams = { filters: { status: ['open'] } }
		const vm = sidebar({ facetFilters: { status: ['open'] } })
		vm.onFilterChange({ key: 'type', values: ['a'] })
		expect(objectStore.updateSearchParams).toHaveBeenCalledWith({ filters: { status: ['open'], type: ['a'] } })
		expect(vm.facetFilters).toEqual({ status: ['open'], type: ['a'] })

		vm.onFilterChange({ key: 'type' })
		expect(vm.facetFilters.type).toEqual([])
	})

	it('persists the visible columns', () => {
		sidebar().onColumnsChange(['title'])
		expect(objectStore.setSearchVisibleColumns).toHaveBeenCalledWith(['title'])
	})
})

describe('SearchSideBar register and schema selection', () => {
	it('keeps only the schemas of the selected registers', () => {
		registerStore.registerList = [{ id: 1, schemas: [10, 11] }, { id: 2, schemas: [20] }]
		const vm = sidebar({ selectedSchemas: [10, 20, 99] })
		vm.handleRegisterChange([1])
		expect(vm.selectedRegisters).toEqual([1])
		expect(vm.selectedSchemas).toEqual([10])
		expect(vm.$router.replace).toHaveBeenCalled()
	})

	it('accepts a single value and clears everything on an empty selection', () => {
		registerStore.registerList = [{ id: 3, schemas: [30] }]
		const vm = sidebar({ selectedSchemas: [30] })
		vm.handleRegisterChange(3)
		expect(vm.selectedRegisters).toEqual([3])
		expect(vm.selectedSchemas).toEqual([30])

		vm.handleRegisterChange([])
		expect(vm.selectedRegisters).toEqual([])
		expect(vm.selectedSchemas).toEqual([])
		expect(objectStore.clearSearchCollection).toHaveBeenCalled()
		expect(objectStore.updateSearchParams).toHaveBeenCalledWith({ filters: {} })
	})

	it('sets the schema selection and clears the results when none is left', async () => {
		const vm = sidebar({ selectedRegisters: [1] })
		await vm.handleSchemaChange(7)
		expect(vm.selectedSchemas).toEqual([7])
		await vm.handleSchemaChange([7, 8])
		expect(vm.selectedSchemas).toEqual([7, 8])
		expect(objectStore.clearSearchCollection).not.toHaveBeenCalled()

		await vm.handleSchemaChange(null)
		expect(vm.selectedSchemas).toEqual([])
		expect(objectStore.clearSearchCollection).toHaveBeenCalled()
	})

	it('pushes the first register and schema, the joined terms and the filters to the store', () => {
		registerStore.registerList = [{ id: 1, title: 'R' }]
		schemaStore.schemaList = [{ id: 5, title: 'S' }]
		const vm = sidebar({ selectedRegisters: [1, 2], selectedSchemas: [5], searchTerms: ['a', 'b'], facetFilters: { x: ['y'] } })
		vm.syncSearchParamsAndRefetch()
		expect(registerStore.setRegisterItem).toHaveBeenCalledWith({ id: 1, title: 'R' })
		expect(schemaStore.setSchemaItem).toHaveBeenCalledWith({ id: 5, title: 'S' })
		expect(objectStore.setSearchParams).toHaveBeenCalledWith({
			register: 1,
			schema: 5,
			search: 'a b',
			filters: { x: ['y'] },
			_extend: ['@self.can'],
		})
		expect(objectStore.refetchSearchCollection).toHaveBeenCalled()
	})

	it('clears the register and schema items without a selection', () => {
		sidebar().syncSearchParamsAndRefetch()
		expect(registerStore.setRegisterItem).toHaveBeenCalledWith(null)
		expect(schemaStore.setSchemaItem).toHaveBeenCalledWith(null)
		expect(objectStore.setSearchParams).toHaveBeenCalledWith(expect.objectContaining({ register: null, schema: null, search: '' }))
	})
})

describe('SearchSideBar search terms', () => {
	it('adds only new terms, split on commas and spaces', () => {
		const vm = sidebar({ searchTerms: ['a'], searchQuery: 'a, b  c,,' })
		vm.handleSearchInput()
		expect(vm.searchTerms).toEqual(['a', 'b', 'c'])
	})

	it('commits the input, clears it and updates the route', () => {
		const vm = sidebar({ searchQuery: 'x' })
		vm.addSearchTerms()
		expect(vm.searchTerms).toEqual(['x'])
		expect(vm.searchQuery).toBe('')
		expect(vm.$router.replace).toHaveBeenCalledWith({ path: '/tables', query: { q: 'x' } })
	})

	it('removes a term and re-runs the search only when it can search', async () => {
		const applyFacetFilters = jest.fn()
		const vm = sidebar({ searchTerms: ['a', 'b'], applyFacetFilters })
		await vm.removeSearchTerm(0)
		expect(vm.searchTerms).toEqual(['b'])
		expect(vm.searchQuery).toBe('b')
		expect(applyFacetFilters).not.toHaveBeenCalled()

		const ready = sidebar({ searchTerms: ['a'], selectedRegisters: [1], selectedSchemas: [2], applyFacetFilters })
		await ready.removeSearchTerm(0)
		expect(applyFacetFilters).toHaveBeenCalledTimes(1)
	})

	it('records the total of a search and resets the loading flags', async () => {
		objectStore.pagination = { total: 42 }
		const vm = sidebar({ selectedRegisters: [1], selectedSchemas: [2], searchQuery: 'q' })
		await vm.performSearch()
		expect(vm.lastSearchStats.total).toBe(42)
		expect(vm.searchTerms).toEqual(['q'])
		expect(vm.searchLoading).toBe(false)
		expect(objectStore.loading).toBe(false)
	})

	it('records zero stats when the search fails, and does nothing without a scope', async () => {
		const vm = sidebar({
			selectedRegisters: [1],
			selectedSchemas: [2],
			performSearchWithFacets: jest.fn().mockRejectedValue(new Error('down')),
		})
		await vm.performSearch()
		expect(vm.lastSearchStats).toEqual({ total: 0, time: 0 })

		const idle = sidebar({ performSearchWithFacets: jest.fn() })
		await idle.performSearch()
		expect(idle.performSearchWithFacets).not.toHaveBeenCalled()
	})
})

describe('SearchSideBar facets', () => {
	it('merges the properties of allOf parents under the own properties', () => {
		schemaStore.schemaList = [
			{ id: 1, uuid: 'p-1', properties: { a: { title: 'parent a' }, b: { title: 'b' } } },
			{ id: 2, properties: { c: { title: 'c' } } },
		]
		const merged = sidebar().resolveInheritedProperties({
			allOf: ['p-1', { id: 2 }, 'missing'],
			properties: { a: { title: 'own a' } },
		})
		expect(merged).toEqual({ a: { title: 'own a' }, b: { title: 'b' }, c: { title: 'c' } })
		expect(sidebar().resolveInheritedProperties(undefined)).toEqual({})
	})

	it('discovers facets from the search schema', async () => {
		objectStore.searchParams = { register: 1, schema: 2, filters: {} }
		objectStore.searchFacets = { status: {} }
		objectStore.searchSchema = { properties: { title: { type: 'string' } } }
		const vm = sidebar()
		await vm.discoverFacets()
		expect(objectStore.refetchSearchCollection).toHaveBeenCalledWith({ _facets: 'extend', _limit: 0 })
		expect(vm.facetData).toEqual({ status: {} })
		expect(vm.facetableFields).toEqual({ object_fields: { title: { type: 'string' } } })
		expect(vm.facetsLoading).toBe(false)
	})

	it('skips discovery without a scope and clears it on failure', async () => {
		const vm = sidebar()
		await vm.discoverFacets()
		expect(objectStore.refetchSearchCollection).not.toHaveBeenCalled()

		objectStore.searchParams = { register: 1, schema: 2, filters: {} }
		objectStore.refetchSearchCollection.mockRejectedValueOnce(new Error('x'))
		const spy = jest.spyOn(console, 'error').mockImplementation(() => {})
		await vm.discoverFacets()
		expect(vm.facetableFields).toBeNull()
		expect(vm.facetData).toBeNull()
		spy.mockRestore()
	})

	it('drops the filter of a facet that is switched off', () => {
		const vm = sidebar({ enabledFacets: { status: true }, facetFilters: { status: ['a'], other: ['b'] } })
		vm.toggleFacet('status')
		expect(vm.facetFilters).toEqual({ other: ['b'] })
		vm.toggleFacet('other')
		expect(vm.facetFilters).toEqual({ other: ['b'] })
	})

	it('builds the facet request from the enabled facets and their first type', () => {
		const vm = sidebar({
			enabledFacets: { '@self.created': true, '@self.owner': true, status: true, date: true, off: false },
			facetableFields: {
				'@self': { created: { facet_types: ['date_histogram'] } },
				object_fields: { date: { facet_types: ['date_histogram'] }, status: { facet_types: ['terms'] } },
			},
		})
		expect(vm.buildFacetConfiguration()).toEqual({
			_facets: {
				'@self': { created: { type: 'date_histogram', interval: 'month' }, owner: { type: 'terms' } },
				status: { type: 'terms' },
				date: { type: 'date_histogram', interval: 'month' },
			},
		})
	})

	it('searches with facets only when it can search', async () => {
		objectStore.searchFacets = { a: 1 }
		const vm = sidebar({ selectedRegisters: [1], selectedSchemas: [2] })
		await vm.performSearchWithFacets()
		expect(objectStore.setSearchParams).toHaveBeenCalled()
		expect(vm.facetData).toEqual({ a: 1 })
		expect(vm.searchLoading).toBe(false)

		jest.clearAllMocks()
		await sidebar().performSearchWithFacets()
		expect(objectStore.setSearchParams).not.toHaveBeenCalled()
	})

	it('labels facets by description, title or the split field name', () => {
		const vm = sidebar({
			facetableFields: {
				'@self': { created: { description: 'Created at' } },
				object_fields: { status: { title: 'Status' }, kind: { description: 'Kind of thing' } },
			},
		})
		expect(vm.getFacetLabel('created', {}, true)).toBe('Created at')
		expect(vm.getFacetLabel('updatedAt', {}, true)).toBe('Updated At')
		expect(vm.getFacetLabel('status', {}, false)).toBe('Status')
		expect(vm.getFacetLabel('kind', {}, false)).toBe('Kind of thing')
		expect(vm.getFacetLabel('tooiCategorieNaam', {}, false)).toBe('Tooi Categorie Naam')
	})

	it('turns store-format and legacy facet data into options', () => {
		const vm = sidebar()
		expect(vm.getFacetOptions({ values: [{ value: 'a', count: 2 }, { value: 'b' }] })).toEqual([
			{ value: 'a', label: 'a (2)' },
			{ value: 'b', label: 'b (0)' },
		])
		expect(vm.getFacetOptions(null)).toEqual([])
		expect(vm.getFacetOptions({ data: { type: 'terms', buckets: [{ value: 'x', label: 'X', count: 3 }, { value: 'y' }] } })).toEqual([
			{ value: 'x', label: 'X (3)' },
			{ value: 'y', label: 'y (0)' },
		])
		expect(vm.getFacetOptions({ data: { type: 'range', buckets: [{ from: 0, to: 10, count: 1 }] } })).toEqual([
			{ value: '0-10', label: '0 - 10 (1)' },
		])
		expect(vm.getFacetOptions({ data: { type: 'unknown' } })).toEqual([])
	})

	it('turns a date histogram into yearly, monthly and at most 30 daily options', () => {
		const daily = Array.from({ length: 40 }, (_, i) => ({ date: `d${i}`, label: `D${i}`, count: 1 }))
		const options = sidebar().getFacetOptions({
			data: {
				type: 'date_histogram',
				brackets: {
					yearly: { buckets: [{ date: '2026', label: '2026', count: 5 }] },
					monthly: { buckets: [{ date: '2026-01', label: 'Jan' }] },
					daily: { buckets: daily },
				},
			},
		})
		expect(options[0]).toEqual({ value: 'year:2026', label: '2026 (5)' })
		expect(options[1]).toEqual({ value: 'month:2026-01', label: 'Jan (0)' })
		expect(options).toHaveLength(32)
		expect(options[31].value).toBe('day:d29')
	})

	it('toggles a schema group open and closed', () => {
		const vm = sidebar()
		vm.toggleSchemaGroup(4)
		expect(vm.expandedSchemas).toEqual({ 4: true })
		vm.toggleSchemaGroup(4)
		expect(vm.expandedSchemas).toEqual({ 4: false })
	})
})

describe('SearchSideBar views', () => {
	it('clears the active view when the selection is emptied', async () => {
		await sidebar().handleViewChange(null)
		expect(viewsStore.clearActiveView).toHaveBeenCalled()
	})

	it('loads and applies a chosen view', async () => {
		viewsStore.fetchView.mockResolvedValue({ query: { registers: [1], schemas: [2] } })
		const applyViewConfiguration = jest.fn()
		const vm = sidebar({ applyViewConfiguration })
		await vm.handleViewChange({ value: 'v-1' })
		expect(viewsStore.fetchView).toHaveBeenCalledWith('v-1')
		expect(applyViewConfiguration).toHaveBeenCalledWith({ query: { registers: [1], schemas: [2] } })
	})

	it('logs a view that fails to load', async () => {
		viewsStore.fetchView.mockRejectedValue(new Error('gone'))
		const spy = jest.spyOn(console, 'error').mockImplementation(() => {})
		await sidebar({ applyViewConfiguration: jest.fn() }).handleViewChange({ value: 'v-2' })
		expect(spy).toHaveBeenCalled()
		spy.mockRestore()
	})
})
