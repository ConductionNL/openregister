import ObjectsIndex from './ObjectsIndex.vue'
import ObjectsList from './ObjectsList.vue'
import { liveCollectionMethods } from '../../composables/liveCollectionSubscription.js'
import { openAddObjectModal } from '../../composables/openAddObject.js'
import { objectStore } from '../../store/store.js'

/**
 * The objects page's route check and add button, and the objects list's
 * reloads: paging, page size, the debounced search and the mount without a
 * register and schema in scope.
 *
 * @spec exclude Client-side behaviour of the objects list pages.
 */

jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('@conduction/nextcloud-vue', () => ({ __esModule: true, CnPagination: {} }))
jest.mock('./ObjectDetails.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../composables/openAddObject.js', () => ({ __esModule: true, openAddObjectModal: jest.fn() }))
jest.mock('../../composables/liveCollectionSubscription.js', () => ({
	__esModule: true,
	liveCollectionLifecycle: {},
	liveCollectionMethods: jest.fn(() => ({ syncLiveSubscription: jest.fn() })),
}))
jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: {},
	objectStore: { filters: {}, refreshObjectList: jest.fn(() => Promise.resolve()) },
	registerStore: {},
	schemaStore: {},
}))

/**
 * A bare component state carrying its methods, computed getters and watchers.
 *
 * @param {object} component The component
 * @param {object} data The data
 * @return {object} The state
 */
function bare(component, data = {}) {
	const state = { ...(component.data ? component.data() : {}), ...data }
	for (const [name, fn] of Object.entries(component.methods)) {
		state[name] = fn.bind(state)
	}
	state.syncLiveSubscription = jest.fn()
	for (const [name, getter] of Object.entries(component.computed || {})) {
		Object.defineProperty(state, name, { get: getter.bind(state), configurable: true })
	}
	return state
}

const [liveOptions] = liveCollectionMethods.mock.calls[0]

beforeEach(() => {
	jest.clearAllMocks()
	jest.useRealTimers()
	objectStore.filters = {}
	objectStore.refreshObjectList.mockImplementation(() => Promise.resolve())
})

describe('ObjectsIndex', () => {
	it.each([
		[{ path: '/objects', name: 'objects' }, true],
		[{ path: '/objects/1/2/3', name: 'objectDetail' }, true],
		[{ path: '/registers/1', name: 'registerDetail' }, false],
	])('treats %j as an objects route: %s', ($route, expected) => {
		expect(bare(ObjectsIndex, { $route }).isObjectsRoute).toBe(expected)
	})

	it('opens the add-object modal', () => {
		bare(ObjectsIndex).addObject()
		expect(openAddObjectModal).toHaveBeenCalledTimes(1)
	})

	it('loads nothing for a route without register, schema and id', async () => {
		await bare(ObjectsIndex, { $route: { params: { register: '1', schema: '2' } } }).loadFromRoute()
		await bare(ObjectsIndex, { $route: {} }).loadFromRoute()
		expect(objectStore.refreshObjectList).not.toHaveBeenCalled()
	})
})

describe('ObjectsList', () => {
	it('reloads the asked page with its page size and search, flagging loading until done', async () => {
		let release
		objectStore.refreshObjectList.mockImplementation(() => new Promise((resolve) => { release = resolve }))
		const state = bare(ObjectsList)
		state.reloadList(3, 'jan')
		expect(objectStore.refreshObjectList).toHaveBeenCalledWith({ limit: 200, page: 3, search: 'jan' })
		expect(state.loading).toBe(true)
		release()
		await Promise.resolve()
		await Promise.resolve()
		expect(state.loading).toBe(false)
	})

	it('reloads page 1 with a new page size when already on page 1', () => {
		const state = bare(ObjectsList, { search: 'x' })
		state.onPageSizeChanged(50)
		expect(state.limit).toBe(50)
		expect(objectStore.refreshObjectList).toHaveBeenCalledWith({ limit: 50, page: 1, search: 'x' })
	})

	it('only returns to page 1 from another page, leaving the reload to the page watcher', () => {
		const state = bare(ObjectsList, { currentPage: 4 })
		state.onPageSizeChanged(50)
		expect(state.currentPage).toBe(1)
		expect(objectStore.refreshObjectList).not.toHaveBeenCalled()
	})

	it('reloads a changed page with the current search', () => {
		const state = bare(ObjectsList, { search: 'q' })
		ObjectsList.watch.currentPage.call(state, 2)
		expect(objectStore.refreshObjectList).toHaveBeenCalledWith({ limit: 200, page: 2, search: 'q' })
	})

	it('waits 700 ms after the last keystroke before searching', () => {
		jest.useFakeTimers()
		const state = bare(ObjectsList, { currentPage: 2 })
		ObjectsList.watch.search.call(state, 'ja')
		jest.advanceTimersByTime(500)
		ObjectsList.watch.search.call(state, 'jan')
		jest.advanceTimersByTime(699)
		expect(objectStore.refreshObjectList).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect(objectStore.refreshObjectList).toHaveBeenCalledTimes(1)
		expect(objectStore.refreshObjectList).toHaveBeenCalledWith({ limit: 200, page: 2, search: 'jan' })
	})

	it.each([
		[{}],
		[{ register: 1 }],
		[{ schema: 2 }],
	])('mounts without loading when the filters %j lack a register or schema', (filters) => {
		objectStore.filters = filters
		const state = bare(ObjectsList, { loading: true })
		ObjectsList.mounted.call(state)
		expect(state.syncLiveSubscription).toHaveBeenCalledTimes(1)
		expect(state.loading).toBe(false)
		expect(objectStore.refreshObjectList).not.toHaveBeenCalled()
	})

	it('mounts by loading the first page when a register and schema are in scope', () => {
		objectStore.filters = { register: 1, schema: 2 }
		const state = bare(ObjectsList)
		ObjectsList.mounted.call(state)
		expect(objectStore.refreshObjectList).toHaveBeenCalledWith({ limit: 200, page: 1, search: '' })
	})

	it('opens the add-object modal', () => {
		bare(ObjectsList).addObject()
		expect(openAddObjectModal).toHaveBeenCalledTimes(1)
	})

	it('follows the store\'s current register and schema for live updates', () => {
		objectStore.currentType = 'or-collection-1-2'
		expect(bare(ObjectsList).liveCollectionType).toBe('or-collection-1-2')
		expect(liveOptions.tag).toBe('[ObjectsList]')
		expect(liveOptions.currentType()).toBe('or-collection-1-2')
	})
})
