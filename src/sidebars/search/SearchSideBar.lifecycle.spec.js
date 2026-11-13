/**
 * The search sidebar's mount hook, its route-query watcher, the computed
 * values behind the view picker, search placeholder and schema property list,
 * the search terms it reads from the route, and how its facet loaders and
 * resets react to failures and to an incomplete scope. Each part runs against
 * a plain `this` and stub stores.
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
		updateSearchParams: jest.fn(),
		refetchSearchCollection: jest.fn(),
		clearSearchCollection: jest.fn(),
	},
	registerStore: { registerList: [] },
	schemaStore: { schemaList: [] },
	viewsStore: {
		activeView: null,
		getAllViews: [],
		getDefaultView: null,
		fetchViews: jest.fn(),
	},
}))

const { objectStore, registerStore, schemaStore, viewsStore } = jest.requireMock('../../store/store.js')
const { methods, computed, watch, mounted } = SearchSideBar

/**
 * A plain component instance: the methods bound to a state object, with the
 * given names replaced by the given stand-ins.
 *
 * @param {object} state The data and stand-in methods the code reads
 * @return {object} The instance
 */
function sidebar(state = {}) {
	const vm = {
		selectedRegisters: [],
		selectedSchemas: [],
		searchTerms: [],
		enabledFacets: {},
		facetFilters: {},
		facetData: null,
		facetableFields: null,
		facetsLoading: false,
		facetDataLoading: false,
		searchLoading: false,
		registerLoading: false,
		schemaLoading: false,
		activeViewName: '',
		$route: { path: '/tables', query: {} },
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

/**
 * A promise that has settled after the current microtasks.
 *
 * @return {Promise<void>}
 */
function flush() {
	return new Promise((resolve) => setTimeout(resolve, 0))
}

beforeEach(() => {
	jest.clearAllMocks()
	jest.spyOn(console, 'error').mockImplementation(() => {})
	objectStore.searchParams = { filters: {} }
	objectStore.searchFacets = undefined
	objectStore.searchSchema = undefined
	objectStore.refetchSearchCollection = jest.fn()
	registerStore.registerList = []
	schemaStore.schemaList = []
	viewsStore.activeView = null
	viewsStore.getAllViews = []
	viewsStore.getDefaultView = null
	viewsStore.fetchViews = jest.fn().mockResolvedValue(undefined)
})

describe('mounting the search sidebar', () => {
	/**
	 * An instance whose mount-time collaborators are recorded.
	 *
	 * @return {object} The instance
	 */
	function mountable() {
		return sidebar({
			loadScopeLists: jest.fn(),
			applyViewConfiguration: jest.fn(),
			applyQueryParamsFromRoute: jest.fn(),
		})
	}

	it('marks both lists as loading, loads views and lists, then reads the route', () => {
		const vm = mountable()

		mounted.call(vm)

		expect(vm.registerLoading).toBe(true)
		expect(vm.schemaLoading).toBe(true)
		expect(viewsStore.fetchViews).toHaveBeenCalledTimes(1)
		expect(vm.loadScopeLists).toHaveBeenCalledTimes(1)
		expect(vm.applyViewConfiguration).not.toHaveBeenCalled()
		expect(vm.applyQueryParamsFromRoute).toHaveBeenCalledTimes(1)
		expect(vm.activeViewName).toBe('')
	})

	it('takes the active view name and applies the default view', () => {
		const defaultView = { id: 7, name: 'Default pets' }
		viewsStore.activeView = { id: 3, name: 'My pets' }
		viewsStore.getDefaultView = defaultView
		const vm = mountable()

		mounted.call(vm)

		expect(vm.activeViewName).toBe('My pets')
		expect(vm.applyViewConfiguration).toHaveBeenCalledWith(defaultView)
	})

	it('keeps the name empty for an active view without one', () => {
		viewsStore.activeView = { id: 3 }
		const vm = mountable()

		mounted.call(vm)

		expect(vm.activeViewName).toBe('')
	})

	it('logs a view load failure instead of throwing', async () => {
		const failure = new Error('offline')
		viewsStore.fetchViews = jest.fn().mockRejectedValue(failure)
		const vm = mountable()

		mounted.call(vm)
		await flush()

		expect(console.error).toHaveBeenCalledWith('Error loading views:', failure)
		expect(vm.applyQueryParamsFromRoute).toHaveBeenCalledTimes(1)
	})
})

describe('the route query watcher', () => {
	const handler = watch['$route.query'].handler

	it('re-applies the route on the tables page', () => {
		const vm = sidebar({ applyQueryParamsFromRoute: jest.fn() })

		handler.call(vm)

		expect(vm.applyQueryParamsFromRoute).toHaveBeenCalledTimes(1)
	})

	it('ignores query changes on any other page', () => {
		const vm = sidebar({
			$route: { path: '/search', query: {} },
			applyQueryParamsFromRoute: jest.fn(),
		})

		handler.call(vm)

		expect(vm.applyQueryParamsFromRoute).not.toHaveBeenCalled()
	})
})

describe('search terms from the route', () => {
	it('splits, trims, drops empties and de-duplicates the q parameter', () => {
		const vm = sidebar({
			$route: { path: '/tables', query: { q: ' cat, dog ,,cat ,  ' } },
		})

		vm.applyQueryParamsFromRoute()

		expect(vm.searchTerms).toEqual(['cat', 'dog'])
		expect(objectStore.clearSearchCollection).toHaveBeenCalledTimes(1)
	})

	it('leaves the terms alone when q is absent', () => {
		const vm = sidebar({ searchTerms: ['kept'] })

		vm.applyQueryParamsFromRoute()

		expect(vm.searchTerms).toEqual(['kept'])
	})
})

describe('the view picker values', () => {
	it('maps every saved view to an option, by id or else uuid', () => {
		viewsStore.getAllViews = [
			{ id: 1, name: 'Pets', description: 'All pets', isDefault: true, isPublic: false },
			{ uuid: 'u-2', name: 'Cats', description: '', isDefault: false, isPublic: true },
		]

		expect(computed.viewOptions.call(sidebar())).toEqual([
			{ value: 1, label: 'Pets', description: 'All pets', isDefault: true, isPublic: false },
			{ value: 'u-2', label: 'Cats', description: '', isDefault: false, isPublic: true },
		])
	})

	it('selects nothing without an active view', () => {
		expect(computed.selectedViewValue.call(sidebar())).toBeNull()
	})

	it('selects the active view by id, or else by uuid', () => {
		viewsStore.activeView = { id: 4, uuid: 'u-4', name: 'Dogs' }
		expect(computed.selectedViewValue.call(sidebar())).toEqual({ value: 4, label: 'Dogs' })

		viewsStore.activeView = { uuid: 'u-5', name: 'Birds' }
		expect(computed.selectedViewValue.call(sidebar())).toEqual({ value: 'u-5', label: 'Birds' })
	})
})

describe('the search placeholder', () => {
	it('invites a first term while there is none', () => {
		expect(computed.searchPlaceholder.call(sidebar())).toBe('Type to search...')
	})

	it('invites more terms once there is one', () => {
		expect(computed.searchPlaceholder.call(sidebar({ searchTerms: ['cat'] }))).toBe('Add more search terms...')
	})
})

describe('the properties of the selected schemas', () => {
	/**
	 * The computed list for an instance with the given selected schemas.
	 *
	 * @param {Array<number>|null} selectedSchemas The selected schema ids
	 * @return {Array<object>} The list
	 */
	function list(selectedSchemas) {
		return computed.selectedSchemasWithProperties.call(sidebar({ selectedSchemas }))
	}

	it('is empty without a selection', () => {
		expect(list([])).toEqual([])
		expect(list(null)).toEqual([])
	})

	it('lists each selected schema with its own and inherited properties', () => {
		schemaStore.schemaList = [
			{ id: 1, title: 'Animal', properties: { name: { type: 'string' } } },
			{ id: 2, name: 'pet', allOf: [{ id: 1 }], properties: { owner: { type: 'string' } } },
			{ id: 3, properties: { legs: { type: 'integer' } } },
		]

		expect(list([2, 3])).toEqual([
			{
				id: 2,
				title: 'pet',
				properties: { name: { type: 'string' }, owner: { type: 'string' } },
			},
			{ id: 3, title: 'Schema 3', properties: { legs: { type: 'integer' } } },
		])
	})

	it('drops a schema that is unknown or has no properties', () => {
		schemaStore.schemaList = [
			{ id: 1, title: 'Bare' },
			{ id: 2, title: 'Full', properties: { a: {} } },
		]

		expect(list([1, 2, 9])).toEqual([{ id: 2, title: 'Full', properties: { a: {} } }])
	})
})

describe('facet discovery', () => {
	it('clears the facetable fields and stops loading when the fetch fails', async () => {
		objectStore.searchParams = { filters: {}, register: 1, schema: 2 }
		const failure = new Error('boom')
		objectStore.refetchSearchCollection = jest.fn().mockRejectedValue(failure)
		const vm = sidebar({ facetableFields: { object_fields: {} } })

		await vm.discoverFacets()

		expect(vm.facetableFields).toBeNull()
		expect(vm.facetsLoading).toBe(false)
		expect(console.error).toHaveBeenCalledWith('Error loading complete faceting data:', failure)
	})

	it('offers the search schema properties as facetable fields', async () => {
		objectStore.searchParams = { filters: {}, register: 1, schema: 2 }
		objectStore.searchSchema = { properties: { colour: { type: 'string' } } }
		objectStore.searchFacets = { colour: [] }
		const vm = sidebar()

		await vm.discoverFacets()

		expect(objectStore.refetchSearchCollection).toHaveBeenCalledWith({ _facets: 'extend', _limit: 0 })
		expect(vm.facetableFields).toEqual({ object_fields: { colour: { type: 'string' } } })
		expect(vm.facetData).toEqual({ colour: [] })
	})

	it('offers no fields when the search schema has no properties', async () => {
		objectStore.searchParams = { filters: {}, register: 1, schema: 2 }
		const vm = sidebar()

		await vm.discoverFacets()

		expect(vm.facetableFields).toEqual({})
		expect(vm.facetData).toBeNull()
	})
})

describe('resetting the facets', () => {
	it('clears facet state and filters and refetches when a scope is selected', () => {
		const vm = sidebar({
			selectedRegisters: [1],
			selectedSchemas: [2],
			enabledFacets: { colour: true },
			facetFilters: { colour: ['red'] },
			facetData: { colour: [] },
			facetDataLoading: true,
		})

		vm.resetFacets()

		expect(vm.enabledFacets).toEqual({})
		expect(vm.facetFilters).toEqual({})
		expect(vm.facetData).toBeNull()
		expect(vm.facetDataLoading).toBe(false)
		expect(objectStore.updateSearchParams).toHaveBeenCalledWith({ filters: {} })
		expect(objectStore.refetchSearchCollection).toHaveBeenCalledTimes(1)
	})

	it('does not refetch without a complete scope', () => {
		const vm = sidebar({ selectedRegisters: [1] })

		vm.resetFacets()

		expect(objectStore.updateSearchParams).toHaveBeenCalledWith({ filters: {} })
		expect(objectStore.refetchSearchCollection).not.toHaveBeenCalled()
	})
})

describe('a faceted search that fails', () => {
	it('logs the failure and stops loading', async () => {
		const failure = new Error('sync failed')
		const vm = sidebar({
			selectedRegisters: [1],
			selectedSchemas: [2],
			syncSearchParamsAndRefetch: jest.fn(() => {
				throw failure
			}),
		})

		await vm.performSearchWithFacets()

		expect(vm.syncSearchParamsAndRefetch).toHaveBeenCalledTimes(1)
		expect(console.error).toHaveBeenCalledWith('Error performing search with facets:', failure)
		expect(vm.searchLoading).toBe(false)
	})

	it('does nothing without a complete scope', async () => {
		const vm = sidebar({ syncSearchParamsAndRefetch: jest.fn() })

		await vm.performSearchWithFacets()

		expect(vm.syncSearchParamsAndRefetch).not.toHaveBeenCalled()
	})
})
