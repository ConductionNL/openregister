import DeletedIndex from './DeletedIndex.vue'
import eventBus from '../../eventBus.js'
import { deletedStore, navigationStore } from '../../store/store.js'

/**
 * The deleted-objects page's computed state, item labels, dialog hand-offs,
 * selection bookkeeping and paging through the deleted store.
 *
 * @spec exclude Client-side behaviour of the deleted-objects list page.
 */

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translatePlural: (app, one, many, count) => `n:${count}:${many}`,
}))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('@conduction/nextcloud-vue', () => ({ __esModule: true, CnDataTable: {} }))
jest.mock('vue-material-design-icons/Delete.vue', () => ({ __esModule: true, default: { name: 'Delete' } }))
jest.mock('vue-material-design-icons/DeleteEmpty.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/FormatListChecks.vue', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/Restore.vue', () => ({ __esModule: true, default: { name: 'Restore' } }))
jest.mock('../../components/shared/ActionMenu.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/shared/ListActionsBar.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../components/PaginationComponent.vue', () => ({ __esModule: true, default: {} }))
jest.mock('../../services/menuActions.js', () => ({ refreshAction: () => ({ label: 'Refresh' }) }))
jest.mock('../../composables/registerSchemaLists.js', () => ({ ensureRegisterAndSchemaLists: jest.fn(() => Promise.resolve()) }))
jest.mock('../../eventBus.js', () => ({ __esModule: true, default: { on: jest.fn(), off: jest.fn(), emit: jest.fn() } }))
jest.mock('../../store/store.js', () => ({
	deletedStore: {
		deletedList: [],
		deletedLoading: false,
		deletedPagination: { total: 0, page: 1, pages: 1, limit: 20 },
		fetchDeleted: jest.fn(() => Promise.resolve()),
		setDeletedFilters: jest.fn(),
		setSelectedForBulkAction: jest.fn(),
	},
	navigationStore: { setDialog: jest.fn() },
	registerStore: { registerList: [{ id: 4, title: 'Zaken' }] },
	schemaStore: { schemaList: [{ id: 7, title: 'Zaak' }, { id: 8 }] },
}))

/**
 * A bare page state carrying the page's methods (its own and the paging
 * mixin's) and computed getters.
 *
 * @param {object} data The page data
 * @return {object} The page
 */
function page(data = {}) {
	const state = { selectedItems: [], ...data }
	const methods = { ...DeletedIndex.mixins[0].methods, ...DeletedIndex.methods }
	for (const [name, fn] of Object.entries(methods)) {
		state[name] = fn.bind(state)
	}
	for (const [name, getter] of Object.entries(DeletedIndex.computed)) {
		Object.defineProperty(state, name, { get: getter.bind(state), configurable: true })
	}
	return state
}

const ITEMS = [{ id: 'a' }, { id: 'b' }, { id: 'c' }]

beforeEach(() => {
	jest.clearAllMocks()
	deletedStore.deletedList = []
	deletedStore.deletedLoading = false
	deletedStore.deletedPagination = { total: 0, page: 1, pages: 1, limit: 20 }
	deletedStore.fetchDeleted.mockImplementation(() => Promise.resolve())
})

describe('DeletedIndex computed state', () => {
	it('takes the items and the paging from the store', () => {
		deletedStore.deletedList = ITEMS
		deletedStore.deletedPagination = { page: 2, pages: 5, limit: 20 }
		const state = page()
		expect(state.filteredItems).toBe(ITEMS)
		expect(state.paginatedItems).toBe(ITEMS)
		expect(state.currentPage).toBe(2)
		expect(state.totalPages).toBe(5)
	})

	it('falls back to an empty list and to page 1 of 1', () => {
		deletedStore.deletedList = null
		deletedStore.deletedPagination = {}
		const state = page()
		expect(state.filteredItems).toEqual([])
		expect(state.currentPage).toBe(1)
		expect(state.totalPages).toBe(1)
	})

	it('names the empty state while loading, when empty, and not at all with items', () => {
		deletedStore.deletedLoading = true
		expect(page().emptyContentName).toBe('t:Loading deleted items...')
		expect(page().emptyContentDescription).toBe('t:Please wait while we fetch your deleted items.')
		deletedStore.deletedLoading = false
		expect(page().emptyContentName).toBe('t:No deleted items found')
		expect(page().emptyContentDescription).toBe('t:There are no deleted items matching your current filters.')
		deletedStore.deletedList = ITEMS
		expect(page().emptyContentName).toBe('')
		expect(page().emptyContentDescription).toBe('')
	})

	it('lists the seven table columns in order', () => {
		expect(page().columns.map((column) => column.key)).toEqual(['title', 'register', 'schema', 'deleted', 'deletedBy', 'destroyableFrom', 'daysLeft'])
	})
})

describe('DeletedIndex labels', () => {
	it.each([
		[{ id: 1, title: 'T', fileName: 'F' }, 'T'],
		[{ id: 1, fileName: 'F', name: 'N' }, 'F'],
		[{ id: 1, name: 'N' }, 'N'],
		[{ id: 1, object: { title: 'OT', name: 'ON' } }, 'OT'],
		[{ id: 1, object: { name: 'ON' } }, 'ON'],
		[{ id: 'uuid-1' }, 'uuid-1'],
	])('titles %j as %s', (item, title) => {
		expect(page().getItemTitle(item)).toBe(title)
	})

	it.each([
		[{ description: 'D', object: { description: 'OD' } }, 'D'],
		[{ object: { description: 'OD', summary: 'S' } }, 'OD'],
		[{ object: { summary: 'S' } }, 'S'],
		[{}, null],
	])('describes %j as %s', (item, description) => {
		expect(page().getItemDescription(item)).toBe(description)
	})

	it('names a known register and schema, numbers an unknown one, and flags a missing one', () => {
		const state = page()
		expect(state.getRegisterName('4')).toBe('Zaken')
		expect(state.getRegisterName(9)).toBe('Register 9')
		expect(state.getRegisterName(null)).toBe('t:Unknown Register')
		expect(state.getSchemaName(7)).toBe('Zaak')
		expect(state.getSchemaName(8)).toBe('Schema 8')
		expect(state.getSchemaName('')).toBe('t:Unknown Schema')
	})

	it.each([
		[{ deletionWindow: { daysRemaining: 3 } }, 'n:3:%n days left'],
		[{ deletionWindow: { daysRemaining: 1 } }, 'n:1:%n days left'],
		[{ deletionWindow: { daysRemaining: 0 } }, 't:Destroyable now'],
		[{ deletionWindow: { daysRemaining: null } }, 't:Unknown'],
		[{}, 't:Unknown'],
		[null, 't:Unknown'],
	])('labels the days left of %j as %s', (item, label) => {
		expect(page().daysLeftLabel(item)).toBe(label)
	})

	it.each([
		[{ deletionWindow: { destroyableFrom: 'w' }, '@self': { deleted: { destroyableFrom: 's', purgeDate: 'p' } } }, 'w'],
		[{ '@self': { deleted: { destroyableFrom: 's', purgeDate: 'p' } } }, 's'],
		[{ '@self': { deleted: { purgeDate: 'p' } } }, 'p'],
		[{}, null],
		[undefined, null],
	])('reads the destroyable date of %j as %s', (item, date) => {
		expect(page().destroyableFrom(item)).toBe(date)
	})

	it('formats a purge date as year:month:day hours:minutes in local time', () => {
		const stamp = new Date(2026, 0, 5, 7, 9).getTime()
		expect(page().formatPurgeDate(stamp)).toBe('2026:01:05 07:09')
	})
})

describe('DeletedIndex dialogs', () => {
	it('offers Restore and Permanently Delete for an item', () => {
		const state = page()
		const item = { id: 'a' }
		const [restore, remove] = state.itemActions(item)
		expect([restore.label, remove.label]).toEqual(['t:Restore', 't:Permanently Delete'])
		expect([restore.icon, remove.icon]).toEqual([{ name: 'Restore' }, { name: 'Delete' }])
		restore.run()
		expect(deletedStore.setSelectedForBulkAction).toHaveBeenLastCalledWith([item])
		expect(navigationStore.setDialog).toHaveBeenLastCalledWith('restoreMultiple')
		remove.run()
		expect(deletedStore.setSelectedForBulkAction).toHaveBeenLastCalledWith([item])
		expect(navigationStore.setDialog).toHaveBeenLastCalledWith('permanentlyDeleteMultiple')
	})

	it.each([
		['bulkRestore', 'restoreMultiple'],
		['bulkDelete', 'permanentlyDeleteMultiple'],
	])('%s hands the selected items on the page to the %s dialog', (method, dialog) => {
		deletedStore.deletedList = ITEMS
		page({ selectedItems: ['c', 'a', 'gone'] })[method]()
		expect(deletedStore.setSelectedForBulkAction).toHaveBeenCalledWith([{ id: 'a' }, { id: 'c' }])
		expect(navigationStore.setDialog).toHaveBeenCalledWith(dialog)
	})

	it.each(['bulkRestore', 'bulkDelete'])('%s does nothing without a selection', (method) => {
		deletedStore.deletedList = ITEMS
		page({ selectedItems: [] })[method]()
		expect(deletedStore.setSelectedForBulkAction).not.toHaveBeenCalled()
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
	})
})

describe('DeletedIndex selection and reloads', () => {
	it.each(['handleObjectsDeleted', 'handleObjectsRestored'])('%s drops the handled ids from the selection and reloads', async (method) => {
		const state = page({ selectedItems: ['a', 'b', 'c'] })
		await state[method](['a', 'c', 'x'])
		expect(state.selectedItems).toEqual(['b'])
		expect(deletedStore.fetchDeleted).toHaveBeenCalledTimes(1)
	})

	it('reloads and clears the selection on refresh', async () => {
		const state = page({ selectedItems: ['a'] })
		await state.refreshItems()
		expect(deletedStore.fetchDeleted).toHaveBeenCalledTimes(1)
		expect(state.selectedItems).toEqual([])
	})

	it('emits the selection and filtered counts', () => {
		deletedStore.deletedList = ITEMS
		page({ selectedItems: ['a'] }).updateCounts()
		expect(eventBus.emit.mock.calls).toEqual([
			['deleted-selection-count', 1],
			['deleted-filtered-count', 3],
		])
	})

	it('stores the converted filters, fetches page 1 with them and clears the selection', async () => {
		const state = page({ selectedItems: ['a'] })
		await state.handleFiltersChanged({ register: 4, dateTo: '2026-02-01' })
		const filters = { '@self.register': 4, '@self.deleted.deleted': { lte: '2026-02-01' } }
		expect(deletedStore.setDeletedFilters).toHaveBeenCalledWith(filters)
		expect(deletedStore.fetchDeleted).toHaveBeenCalledWith({ page: 1, filters })
		expect(state.selectedItems).toEqual([])
	})

	it('keeps the selection and logs when applying filters fails', async () => {
		deletedStore.fetchDeleted.mockImplementation(() => Promise.reject(new Error('down')))
		const logged = jest.spyOn(console, 'error').mockImplementation(() => {})
		const state = page({ selectedItems: ['a'] })
		await state.handleFiltersChanged({})
		expect(state.selectedItems).toEqual(['a'])
		expect(logged).toHaveBeenCalledWith('Error applying filters:', expect.any(Error))
		logged.mockRestore()
	})

	it('pages through the store with its page size and clears the selection', async () => {
		deletedStore.deletedPagination = { limit: 50 }
		const state = page({ selectedItems: ['a'] })
		await state.onPageChanged(3)
		expect(deletedStore.fetchDeleted).toHaveBeenCalledWith({ page: 3, limit: 50 })
		expect(state.selectedItems).toEqual([])
	})

	it('returns to page 1 with a new page size', async () => {
		const state = page({ selectedItems: ['a'] })
		await state.onPageSizeChanged(100)
		expect(deletedStore.fetchDeleted).toHaveBeenCalledWith({ page: 1, limit: 100 })
		expect(state.selectedItems).toEqual([])
	})

	it('keeps the selection without logging when a page fails to load', async () => {
		deletedStore.fetchDeleted.mockImplementation(() => Promise.reject(new Error('down')))
		const logged = jest.spyOn(console, 'error').mockImplementation(() => {})
		const state = page({ selectedItems: ['a'] })
		await state.onPageChanged(2)
		expect(state.selectedItems).toEqual(['a'])
		expect(logged).not.toHaveBeenCalled()
		logged.mockRestore()
	})
})
