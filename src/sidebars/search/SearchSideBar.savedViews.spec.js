/**
 * The search sidebar's saved views: applying, saving, updating, loading,
 * deleting and favouriting a view, and the facet filters it carries. Each
 * method runs against a plain `this` and a stub views store.
 *
 * @spec exclude Client-side saved-view handling in the search sidebar.
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
	objectStore: { updateSearchParams: jest.fn() },
	registerStore: {},
	schemaStore: {},
	viewsStore: {
		activeView: null,
		setActiveView: jest.fn(),
		createView: jest.fn(),
		updateView: jest.fn(),
		fetchView: jest.fn(),
		fetchViews: jest.fn(),
	},
}))

const { objectStore, viewsStore } = jest.requireMock('../../store/store.js')
const { methods, computed } = SearchSideBar

/**
 * A plain component instance: the methods bound to a state object.
 *
 * @param {object} state The data the methods read, and methods to replace
 * @return {object} The instance
 */
function sidebar(state = {}) {
	const vm = {
		selectedRegisters: [],
		selectedSchemas: [],
		searchTerms: [],
		facetFilters: {},
		enabledFacets: {},
		viewName: '',
		viewDescription: '',
		viewIsPublic: false,
		viewIsDefault: false,
		activeViewName: '',
		...state,
	}
	for (const [name, fn] of Object.entries(methods)) {
		if (!(name in state)) vm[name] = fn.bind(vm)
	}
	vm.t = (app, text, vars) => (vars ? text.replace('{error}', vars.error) : text)
	Object.defineProperty(vm, 'canSearch', { get: () => computed.canSearch.call(vm) })
	return vm
}

let showTemporary
let consoleError

beforeEach(() => {
	jest.clearAllMocks()
	viewsStore.activeView = null
	showTemporary = jest.fn()
	global.OC = {
		Notification: { showTemporary },
		getCurrentUser: () => ({ uid: 'ann' }),
		requestToken: 'tok',
	}
	global.fetch = jest.fn()
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	consoleError.mockRestore()
	delete global.fetch
})

describe('SearchSideBar applying a view', () => {
	it('applies the scope, terms and facets of a view and searches', () => {
		const performSearchWithFacets = jest.fn()
		const vm = sidebar({ performSearchWithFacets })
		const view = {
			query: {
				registers: [1],
				schemas: [2],
				searchTerms: ['a'],
				facetFilters: { s: ['x'] },
				enabledFacets: { s: true },
			},
		}
		vm.applyViewConfiguration(view)
		expect(vm.selectedRegisters).toEqual([1])
		expect(vm.selectedSchemas).toEqual([2])
		expect(vm.searchTerms).toEqual(['a'])
		expect(vm.facetFilters).toEqual({ s: ['x'] })
		expect(vm.enabledFacets).toEqual({ s: true })
		expect(viewsStore.setActiveView).toHaveBeenCalledWith(view)
		expect(performSearchWithFacets).toHaveBeenCalled()
	})

	it('reads the older configuration key and does not search without a scope', () => {
		const performSearchWithFacets = jest.fn()
		const vm = sidebar({ performSearchWithFacets })
		vm.applyViewConfiguration({ configuration: { registers: 'not a list', searchTerms: ['b'] } })
		expect(vm.selectedRegisters).toEqual([])
		expect(vm.searchTerms).toEqual(['b'])
		expect(performSearchWithFacets).not.toHaveBeenCalled()
	})

	it('ignores a missing view or a view without a query', () => {
		const vm = sidebar()
		vm.applyViewConfiguration(null)
		vm.applyViewConfiguration({ name: 'empty' })
		expect(viewsStore.setActiveView).not.toHaveBeenCalled()
	})

	it('describes the current state as a view configuration', () => {
		const vm = sidebar({ selectedRegisters: [1], selectedSchemas: [2], searchTerms: ['q'], facetFilters: { f: [1] }, enabledFacets: { f: true } })
		expect(vm.viewConfiguration()).toEqual({
			registers: [1],
			schemas: [2],
			searchTerms: ['q'],
			facetFilters: { f: [1] },
			enabledFacets: { f: true },
		})
	})
})

describe('SearchSideBar saving a view', () => {
	it('creates the view with the trimmed name, reloads and resets the form', async () => {
		viewsStore.createView.mockResolvedValue({ id: 9, name: 'Mine' })
		const vm = sidebar({ viewName: '  Mine ', viewDescription: 'd', viewIsPublic: true, showSaveForm: true, selectedRegisters: [1] })
		await vm.saveView()
		expect(viewsStore.createView).toHaveBeenCalledWith({
			name: 'Mine',
			description: 'd',
			isPublic: true,
			isDefault: false,
			configuration: expect.objectContaining({ registers: [1] }),
		})
		expect(viewsStore.fetchViews).toHaveBeenCalled()
		expect(vm.activeViewName).toBe('Mine')
		expect(vm.viewName).toBe('')
		expect(vm.viewIsPublic).toBe(false)
		expect(vm.showSaveForm).toBe(false)
		expect(showTemporary).toHaveBeenCalledWith('View saved successfully!')
	})

	it('does nothing without a name and reports a failed save', async () => {
		await sidebar({ viewName: '   ' }).saveView()
		expect(viewsStore.createView).not.toHaveBeenCalled()

		viewsStore.createView.mockRejectedValue(new Error('quota'))
		await sidebar({ viewName: 'x' }).saveView()
		expect(showTemporary).toHaveBeenCalledWith('Failed to save view: quota')
	})

	it('cancels the save form', () => {
		const vm = sidebar({ viewName: 'x', viewDescription: 'y', showSaveForm: true, viewNameTouched: true })
		vm.cancelSaveView()
		expect(vm).toMatchObject({ viewName: '', viewDescription: '', showSaveForm: false, viewNameTouched: false })
	})
})

describe('SearchSideBar updating the active view', () => {
	it('updates the active view by id with the current configuration', async () => {
		viewsStore.activeView = { id: 4, name: 'Old', description: 'keep', isPublic: true, isDefault: false }
		viewsStore.updateView.mockResolvedValue({ id: 4, name: 'New' })
		const vm = sidebar({ activeViewName: ' New ' })
		await vm.updateActiveView()
		expect(viewsStore.updateView).toHaveBeenCalledWith(4, expect.objectContaining({ name: 'New', description: 'keep', isPublic: true }))
		expect(vm.activeViewName).toBe('New')
		expect(showTemporary).toHaveBeenCalledWith('View updated successfully!')
	})

	it('needs an active view and a name, and reports a failed update', async () => {
		await sidebar({ activeViewName: 'x' }).updateActiveView()
		viewsStore.activeView = { uuid: 'u-1' }
		await sidebar({ activeViewName: ' ' }).updateActiveView()
		expect(viewsStore.updateView).not.toHaveBeenCalled()

		viewsStore.updateView.mockRejectedValue(new Error('locked'))
		await sidebar({ activeViewName: 'x' }).updateActiveView()
		expect(viewsStore.updateView).toHaveBeenCalledWith('u-1', expect.anything())
		expect(showTemporary).toHaveBeenCalledWith('Failed to update view: locked')
	})

	it('opens the edit and delete dialogs only with an active view', () => {
		const vm = sidebar()
		vm.openEditDialogForActiveView()
		vm.confirmDeleteActiveView()
		expect(vm.showEditDialog).toBeUndefined()
		expect(vm.showDeleteDialog).toBeUndefined()

		viewsStore.activeView = { id: 1 }
		vm.openEditDialogForActiveView()
		expect(vm.editingView).toEqual({ id: 1 })
		expect(vm.showEditDialog).toBe(true)
		vm.confirmDeleteActiveView()
		expect(vm.viewToDelete).toEqual({ id: 1 })
		expect(vm.showDeleteDialog).toBe(true)

		vm.cancelEditView()
		expect(vm.showEditDialog).toBe(false)
		expect(vm.editingView).toBeNull()
	})
})

describe('SearchSideBar loading and deleting views', () => {
	it('loads a view, applies it and returns to the search tab', async () => {
		viewsStore.fetchView.mockResolvedValue({ id: 3, name: 'Three', query: {} })
		const applyViewConfiguration = jest.fn()
		const vm = sidebar({ applyViewConfiguration })
		await vm.loadView({ uuid: 'u-3' })
		expect(viewsStore.fetchView).toHaveBeenCalledWith('u-3')
		expect(viewsStore.activeView).toEqual({ id: 3, name: 'Three', query: {} })
		expect(vm.activeViewName).toBe('Three')
		expect(applyViewConfiguration).toHaveBeenCalled()
		expect(vm.activeTab).toBe('search-tab')
	})

	it('reports a view that fails to load', async () => {
		viewsStore.fetchView.mockRejectedValue(new Error('404'))
		await sidebar().loadView({ id: 1 })
		expect(showTemporary).toHaveBeenCalledWith('Failed to load view: 404')
	})

	it('marks the active view by id or uuid', () => {
		const vm = sidebar()
		expect(vm.isActiveView({ id: 1 })).toBe(false)
		viewsStore.activeView = { uuid: 'u' }
		expect(vm.isActiveView({ uuid: 'u' })).toBe(true)
		expect(vm.isActiveView({ uuid: 'v' })).toBe(false)
	})

	it('clears the active view after deleting it, and keeps it after deleting another', async () => {
		viewsStore.activeView = { id: 2, uuid: 'u-2' }
		const vm = sidebar({ viewToDelete: { id: 5, uuid: 'u-5' }, showDeleteDialog: true, activeViewName: 'Two' })
		await vm.handleDeleteClose()
		expect(vm.showDeleteDialog).toBe(false)
		expect(vm.viewToDelete).toBeNull()
		expect(viewsStore.activeView).toEqual({ id: 2, uuid: 'u-2' })

		vm.confirmDeleteView({ id: 2, uuid: 'u-2' })
		await vm.handleDeleteClose()
		expect(viewsStore.fetchViews).toHaveBeenCalledTimes(2)
		expect(viewsStore.activeView).toBeNull()
		expect(vm.activeViewName).toBe('')
	})
})

describe('SearchSideBar favourites', () => {
	it('reads whether the current user favoured a view', () => {
		const vm = sidebar()
		expect(vm.isFavorited({ favoredBy: ['ann'] })).toBe(true)
		expect(vm.isFavorited({ favoredBy: ['bob'] })).toBe(false)
		expect(vm.isFavorited({})).toBe(false)
		global.OC.getCurrentUser = () => null
		expect(vm.isFavorited({ favoredBy: ['ann'] })).toBe(false)
	})

	it('adds the current user to the favourites with a PATCH', async () => {
		global.fetch.mockResolvedValue({ ok: true })
		await sidebar().toggleFavorite({ id: 7, favoredBy: ['bob'] })
		const [url, init] = global.fetch.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/views/7')
		expect(init.method).toBe('PATCH')
		expect(init.headers.requesttoken).toBe('tok')
		expect(JSON.parse(init.body)).toEqual({ favoredBy: ['bob', 'ann'] })
		expect(showTemporary).toHaveBeenCalledWith('Added to favorites')
	})

	it('removes the current user from the favourites', async () => {
		global.fetch.mockResolvedValue({ ok: true })
		await sidebar().toggleFavorite({ uuid: 'u', favoredBy: ['ann', 'bob'] })
		expect(JSON.parse(global.fetch.mock.calls[0][1].body)).toEqual({ favoredBy: ['bob'] })
		expect(showTemporary).toHaveBeenCalledWith('Removed from favorites')
	})

	it('refuses anonymous users and reports a failed request', async () => {
		global.OC.getCurrentUser = () => null
		await sidebar().toggleFavorite({ id: 1 })
		expect(global.fetch).not.toHaveBeenCalled()
		expect(showTemporary).toHaveBeenCalledWith('You must be logged in to favorite views')

		global.OC.getCurrentUser = () => ({ uid: 'ann' })
		global.fetch.mockResolvedValue({ ok: false })
		await sidebar().toggleFavorite({ id: 1 })
		expect(showTemporary).toHaveBeenLastCalledWith('Failed to update favorite status')
		expect(viewsStore.fetchViews).not.toHaveBeenCalled()
	})
})

describe('SearchSideBar facet filters', () => {
	it('stores a facet filter and re-applies the filters', () => {
		const applyFacetFilters = jest.fn()
		const vm = sidebar({ facetFilters: { a: [1] }, applyFacetFilters })
		vm.updateFacetFilter('b', [2])
		expect(vm.facetFilters).toEqual({ a: [1], b: [2] })
		expect(applyFacetFilters).toHaveBeenCalled()
	})

	it('writes the non-empty filters as plain values with the joined terms', () => {
		const vm = sidebar({ searchTerms: ['x', 'y'], facetFilters: { a: [{ value: 'v1' }, 'raw'], empty: [], none: null } })
		vm.applyFiltersToObjectStore()
		expect(objectStore.updateSearchParams).toHaveBeenCalledWith({ search: 'x y', filters: { a: ['v1', 'raw'] } })
	})

	it('marks the store loading while the filtered search runs, also when it fails', async () => {
		let during
		const vm = sidebar({
			performSearchWithFacets: jest.fn(async () => {
				during = objectStore.loading
				throw new Error('x')
			}),
		})
		await expect(vm.applyFacetFilters()).rejects.toThrow('x')
		expect(during).toBe(true)
		expect(objectStore.loading).toBe(false)
	})
})
