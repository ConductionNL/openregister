/**
 * The search sidebar keeps the picked columns per schema (REQ-RAT-002).
 *
 * @spec openspec/changes/records-api-and-table-stated/specs/zoeken-filteren/spec.md#REQ-RAT-002
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

const { objectStore, registerStore, schemaStore } = jest.requireMock('../../store/store.js')
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
	window.localStorage.clear()
	objectStore.searchParams = { filters: {} }
	registerStore.registerList = []
	schemaStore.schemaList = [{ id: 7 }, { id: 8 }]
})

describe('the picked columns are kept for the schema', () => {
	it('remembers the columns picked under the current schema', () => {
		objectStore.searchParams = { filters: {}, schema: 7 }

		sidebar().onColumnsChange(['prioriteit'])

		expect(objectStore.setSearchVisibleColumns).toHaveBeenCalledWith(['prioriteit'])
		expect(JSON.parse(window.localStorage.getItem('openregister:search-columns:7'))).toEqual(['prioriteit'])
	})

	it('brings the remembered columns back when the schema is opened again', () => {
		window.localStorage.setItem('openregister:search-columns:7', JSON.stringify(['prioriteit']))

		sidebar({ selectedSchemas: [7] }).syncSearchParamsAndRefetch()

		expect(objectStore.setSearchVisibleColumns).toHaveBeenCalledWith(['prioriteit'])
	})

	it('shows the defaults for a schema with no choice, not the previous schema\'s', () => {
		objectStore.searchParams = { filters: {}, schema: 7 }

		sidebar({ selectedSchemas: [8] }).syncSearchParamsAndRefetch()

		expect(objectStore.setSearchVisibleColumns).toHaveBeenCalledWith([])
	})

	it('leaves the columns alone while the schema stays the same', () => {
		objectStore.searchParams = { filters: {}, schema: 7 }

		sidebar({ selectedSchemas: [7] }).syncSearchParamsAndRefetch()

		expect(objectStore.setSearchVisibleColumns).not.toHaveBeenCalled()
	})
})
