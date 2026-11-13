/**
 * ObjectsList: when the list loads (only with a register and a schema in
 * scope), what it asks the store, the loader state, the page-size change
 * that refetches exactly once, the debounced search and the add action.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ObjectsList from './ObjectsList.vue'
import { openAddObjectModal } from '../../composables/openAddObject.js'
import { objectStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {},
	registerStore: {},
	schemaStore: {},
}))
jest.mock('../../composables/openAddObject.js', () => ({ openAddObjectModal: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@conduction/nextcloud-vue', () => ({}))
jest.mock('vue-material-design-icons/CubeOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/LockOpenOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/LockOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Upload.vue', () => ({}))

/**
 * A bare list context with every method bound to it and the live
 * subscription sync replaced by a spy.
 *
 * @param {object} state State to start from
 * @return {object} The context
 */
function context(state = {}) {
	const ctx = { ...ObjectsList.data.call({}), ...state }
	for (const [name, method] of Object.entries(ObjectsList.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.syncLiveSubscription = jest.fn()
	return ctx
}

/**
 * A refresh that stays pending until released.
 *
 * @return {{promise: Promise, release: Function}} The pending refresh
 */
function deferred() {
	let release
	const promise = new Promise((resolve) => { release = resolve })
	return { promise, release }
}

describe('ObjectsList loading', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		objectStore.filters = { register: 1, schema: 2 }
		objectStore.refreshObjectList = jest.fn(() => Promise.resolve())
	})

	afterEach(() => jest.useRealTimers())

	it('asks the store for the page with the limit and search, showing the loader until it settles', async () => {
		const pending = deferred()
		objectStore.refreshObjectList = jest.fn(() => pending.promise)
		const ctx = context({ limit: 50 })
		ctx.reloadList(3, 'abc')
		expect(objectStore.refreshObjectList).toHaveBeenCalledWith({ limit: 50, page: 3, search: 'abc' })
		expect(ctx.loading).toBe(true)
		pending.release()
		await pending.promise
		await Promise.resolve()
		expect(ctx.loading).toBe(false)
	})

	it('loads the first page on mount when a register and a schema are in scope', () => {
		const ctx = context({ search: 'x' })
		ObjectsList.mounted.call(ctx)
		expect(ctx.syncLiveSubscription).toHaveBeenCalledTimes(1)
		expect(objectStore.refreshObjectList).toHaveBeenCalledWith({ limit: 200, page: 1, search: 'x' })
	})

	it.each([
		[{ register: 1 }],
		[{ schema: 2 }],
		[{ register: '', schema: 2 }],
		[{}],
		[undefined],
	])('does not load on mount with filters %p, but still syncs the subscription', (filters) => {
		objectStore.filters = filters
		const ctx = context({ loading: true })
		ObjectsList.mounted.call(ctx)
		expect(objectStore.refreshObjectList).not.toHaveBeenCalled()
		expect(ctx.syncLiveSubscription).toHaveBeenCalledTimes(1)
		expect(ctx.loading).toBe(false)
	})

	it('reloads the new page when the page changes', () => {
		const ctx = context({ search: 'q', limit: 20 })
		ObjectsList.watch.currentPage.call(ctx, 4)
		expect(objectStore.refreshObjectList).toHaveBeenCalledWith({ limit: 20, page: 4, search: 'q' })
	})

	it('debounces search reloads by 700ms and keeps only the last term', () => {
		jest.useFakeTimers()
		const ctx = context({ currentPage: 2 })
		ObjectsList.watch.search.call(ctx, 'a')
		jest.advanceTimersByTime(500)
		ObjectsList.watch.search.call(ctx, 'ab')
		jest.advanceTimersByTime(699)
		expect(objectStore.refreshObjectList).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect(objectStore.refreshObjectList).toHaveBeenCalledTimes(1)
		expect(objectStore.refreshObjectList).toHaveBeenCalledWith({ limit: 200, page: 2, search: 'ab' })
	})

	it('refetches page 1 directly when the page size changes on page 1', () => {
		const ctx = context({ currentPage: 1, search: 's' })
		ctx.onPageSizeChanged(25)
		expect(ctx.limit).toBe(25)
		expect(objectStore.refreshObjectList).toHaveBeenCalledTimes(1)
		expect(objectStore.refreshObjectList).toHaveBeenCalledWith({ limit: 25, page: 1, search: 's' })
	})

	it('only resets to page 1 on another page, leaving the refetch to the page watcher', () => {
		const ctx = context({ currentPage: 5 })
		ctx.onPageSizeChanged(100)
		expect(ctx.limit).toBe(100)
		expect(ctx.currentPage).toBe(1)
		expect(objectStore.refreshObjectList).not.toHaveBeenCalled()
	})

	it('opens the add-object modal', () => {
		context().addObject()
		expect(openAddObjectModal).toHaveBeenCalledTimes(1)
	})

	it('mirrors the store current type for the live subscription', () => {
		objectStore.currentType = 'r1-s2'
		expect(ObjectsList.computed.liveCollectionType.call({})).toBe('r1-s2')
	})
})
