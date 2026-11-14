import EntitiesIndex from './EntitiesIndex.vue'
import EntityDetail from './EntityDetail.vue'
import { navigationStore } from '../../store/store.js'

/**
 * The entity list's selection, paging and filter handlers, and the entity
 * detail page's relation labels and navigation.
 *
 * @spec exclude Client-side list and detail page behaviour of the entity views.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translate: (app, text) => `t:${text}`,
}))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('../../components/PaginationComponent.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/ActionMenu.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/FilterToggleHeader.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/ListActionsBar.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/ViewModeSwitch.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AccountOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/EyeOutline.vue', () => ({ __esModule: true, default: { name: 'EyeOutline' } }))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/LinkVariantOff.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/TextBoxOutline.vue', () => ({ __esModule: true, default: {} }))
jest.mock('./EntityStatsTable.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../eventBus.js', () => ({ __esModule: true, default: { on: jest.fn(), off: jest.fn() } }))
jest.mock('../../services/menuActions.js', () => ({ __esModule: true, refreshAction() {} }))
jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: { sidebarState: { entities: false }, setSidebarState: jest.fn() },
}))

/**
 * A bare page state carrying the component's methods and computed getters,
 * with the load replaced by a spy.
 *
 * @param {object} component The page component
 * @param {object} data The page data
 * @return {object} The page
 */
function page(component, data = {}) {
	const state = { loadEntities: jest.fn(), loadEntity: jest.fn(), $router: { push: jest.fn() }, ...data }
	for (const [name, fn] of Object.entries(component.methods)) {
		if (!(name in data) && name !== 'loadEntities' && name !== 'loadEntity') {
			state[name] = fn.bind(state)
		}
	}
	for (const [name, getter] of Object.entries(component.computed || {})) {
		Object.defineProperty(state, name, { get: getter.bind(state), configurable: true })
	}
	return state
}

describe('EntitiesIndex computed state', () => {
	const list = [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }, { id: 5 }]

	it('counts pages from the total and the page size, rounding up', () => {
		expect(page(EntitiesIndex, { totalEntities: 41, limit: 20 }).totalPages).toBe(3)
		expect(page(EntitiesIndex, { totalEntities: 40, limit: 20 }).totalPages).toBe(2)
		expect(page(EntitiesIndex, { totalEntities: 0, limit: 20 }).totalPages).toBe(0)
	})

	it('slices the current page out of the list', () => {
		const state = page(EntitiesIndex, { entitiesList: list, currentPage: 2, limit: 2 })
		expect(state.paginatedEntities).toEqual([{ id: 3 }, { id: 4 }])
		state.currentPage = 3
		expect(state.paginatedEntities).toEqual([{ id: 5 }])
	})

	it('reports all, some or none selected', () => {
		const state = page(EntitiesIndex, { entitiesList: list.slice(0, 2), selectedEntities: [] })
		expect(state.allSelected).toBe(false)
		expect(state.someSelected).toBe(false)
		state.selectedEntities = [1]
		expect(state.allSelected).toBe(false)
		expect(state.someSelected).toBe(true)
		state.selectedEntities = [2, 1]
		expect(state.allSelected).toBe(true)
		expect(state.someSelected).toBe(false)
	})

	it('never calls an empty list all selected', () => {
		expect(page(EntitiesIndex, { entitiesList: [], selectedEntities: [] }).allSelected).toBe(false)
	})

	it('reads the sidebar state from the navigation store', () => {
		navigationStore.sidebarState.entities = true
		expect(page(EntitiesIndex).sidebarOpen).toBe(true)
		navigationStore.sidebarState.entities = false
		expect(page(EntitiesIndex).sidebarOpen).toBe(false)
	})
})

describe('EntitiesIndex handlers', () => {
	it.each([
		['handleSearchUpdate', 'searchQuery', 'jan'],
		['handleTypeUpdate', 'typeFilter', 'person'],
		['handleCategoryUpdate', 'categoryFilter', 'pii'],
	])('%s sets %s, returns to page 1 and reloads', (method, field, value) => {
		const state = page(EntitiesIndex, { currentPage: 4 })
		state[method](value)
		expect(state[field]).toBe(value)
		expect(state.currentPage).toBe(1)
		expect(state.loadEntities).toHaveBeenCalledTimes(1)
	})

	it('changes page and reloads', () => {
		const state = page(EntitiesIndex, { currentPage: 1 })
		state.onPageChanged(3)
		expect(state.currentPage).toBe(3)
		expect(state.loadEntities).toHaveBeenCalledTimes(1)
	})

	it('changes the page size, returns to page 1 and reloads', () => {
		const state = page(EntitiesIndex, { currentPage: 5, limit: 20 })
		state.onPageSizeChanged(50)
		expect(state.limit).toBe(50)
		expect(state.currentPage).toBe(1)
		expect(state.loadEntities).toHaveBeenCalledTimes(1)
	})

	it('refreshes by reloading', () => {
		const state = page(EntitiesIndex)
		state.refreshEntities()
		expect(state.loadEntities).toHaveBeenCalledTimes(1)
	})

	it('flips the sidebar state in the navigation store', () => {
		navigationStore.setSidebarState.mockClear()
		navigationStore.sidebarState.entities = false
		page(EntitiesIndex).toggleSidebar()
		expect(navigationStore.setSidebarState).toHaveBeenCalledWith('entities', true)
		navigationStore.sidebarState.entities = true
		page(EntitiesIndex).toggleSidebar()
		expect(navigationStore.setSidebarState).toHaveBeenLastCalledWith('entities', false)
	})

	it('selects every entity in the list, then none', () => {
		const state = page(EntitiesIndex, { entitiesList: [{ id: 7 }, { id: 9 }], selectedEntities: [] })
		state.toggleSelectAll(true)
		expect(state.selectedEntities).toEqual([7, 9])
		state.toggleSelectAll(false)
		expect(state.selectedEntities).toEqual([])
	})

	it('adds and removes one entity from the selection', () => {
		const state = page(EntitiesIndex, { selectedEntities: [1] })
		state.toggleEntitySelection(2, true)
		expect(state.selectedEntities).toEqual([1, 2])
		state.toggleEntitySelection(1, false)
		expect(state.selectedEntities).toEqual([2])
	})

	it('opens the entity detail route', () => {
		const state = page(EntitiesIndex)
		state.viewEntity({ id: 12 })
		expect(state.$router.push).toHaveBeenCalledWith({ name: 'entityDetails', params: { id: 12 } })
	})

	it('offers one View Details action that opens the entity', () => {
		const state = page(EntitiesIndex)
		const actions = state.entityActions({ id: 3 })
		expect(actions).toHaveLength(1)
		expect(actions[0].label).toBe('View Details')
		expect(actions[0].icon).toEqual({ name: 'EyeOutline' })
		actions[0].run()
		expect(state.$router.push).toHaveBeenCalledWith({ name: 'entityDetails', params: { id: 3 } })
	})

	it('asks for the requested page with only the filters that are set', () => {
		const state = page(EntitiesIndex, { limit: 10, currentPage: 3, searchQuery: '', typeFilter: 'email', categoryFilter: null })
		expect(state.entityListParams()).toEqual({ limit: 10, offset: 20, type: 'email' })
	})

	it('takes the count from the answer, or the page length without one', () => {
		const state = page(EntitiesIndex, { entitiesList: [], totalEntities: 0 })
		state.showEntityList({ success: true, data: [{ id: 1 }, { id: 2 }] })
		expect(state.entitiesList).toEqual([{ id: 1 }, { id: 2 }])
		expect(state.totalEntities).toBe(2)
		state.showEntityList({ success: true, data: [{ id: 1 }], count: 30 })
		expect(state.totalEntities).toBe(30)
	})

	it('keeps the list when the answer is not a success', () => {
		const state = page(EntitiesIndex, { entitiesList: ['kept'], totalEntities: 1 })
		state.showEntityList({ success: false, data: [] })
		expect(state.entitiesList).toEqual(['kept'])
		expect(state.totalEntities).toBe(1)
	})
})

describe('EntityDetail relations', () => {
	it.each([
		[{ fileId: 4, objectId: 5 }, 't:File', 't:File #{id}{"id":4}'],
		[{ objectId: 5, chunkId: 6 }, 't:Object', 't:Object #{id}{"id":5}'],
		[{ chunkId: 6 }, 't:Chunk', 't:Text Chunk #{id}{"id":6}'],
	])('labels %j as %s', (relation, type, title) => {
		const state = page(EntityDetail)
		expect(state.getRelationType(relation)).toBe(type)
		expect(state.getRelationTitle(relation)).toBe(title)
	})

	it('opens a related object in the objects list', () => {
		const state = page(EntityDetail)
		state.viewObject({ objectId: 'abc' })
		expect(state.$router.push).toHaveBeenCalledWith({ path: '/objects', query: { id: 'abc' } })
	})

	it('does not navigate for a relation without an object', () => {
		const state = page(EntityDetail)
		state.viewObject({ fileId: 3 })
		expect(state.$router.push).not.toHaveBeenCalled()
	})

	it('leaves the page for a relation without a file', () => {
		const before = window.location.href
		page(EntityDetail).viewFile({ objectId: 1 })
		expect(window.location.href).toBe(before)
	})

	it('refreshes by reloading the entity', () => {
		const state = page(EntityDetail)
		state.refreshEntity()
		expect(state.loadEntity).toHaveBeenCalledTimes(1)
	})
})
