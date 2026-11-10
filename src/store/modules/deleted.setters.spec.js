import { createPinia, setActivePinia } from 'pinia'
import { useDeletedStore } from './deleted.js'

describe('deleted store — local state setters', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useDeletedStore()
	})

	it('sets and clears the bulk-action selection', () => {
		store.setSelectedForBulkAction(['a', 'b'])
		expect(store.selectedForBulkAction).toEqual(['a', 'b'])
		store.clearSelectedForBulkAction()
		expect(store.selectedForBulkAction).toEqual([])
	})

	it('sets the list, the item, the filters and the search term', () => {
		store.setDeletedList([{ id: 1 }])
		store.setDeletedItem({ id: 1 })
		store.setDeletedFilters({ schema: 3 })
		store.setDeletedSearch('needle')
		expect(store.deletedList).toEqual([{ id: 1 }])
		expect(store.deletedItem).toEqual({ id: 1 })
		expect(store.deletedFilters).toEqual({ schema: 3 })
		expect(store.deletedSearch).toBe('needle')
	})

	it('merges pagination and statistics over their current values', () => {
		const before = { ...store.deletedPagination }
		store.setDeletedPagination({ page: 3 })
		expect(store.deletedPagination).toEqual({ ...before, page: 3 })

		store.setStatistics({ totalDeleted: 9 })
		expect(store.statistics).toEqual({ totalDeleted: 9, deletedToday: 0, deletedThisWeek: 0, oldestDays: 0 })
		store.setStatistics({ deletedToday: 2 })
		expect(store.statistics.totalDeleted).toBe(9)
		expect(store.statistics.deletedToday).toBe(2)
	})

	it('sets the top deleters', () => {
		store.setTopDeleters([{ user: 'admin', count: 4 }])
		expect(store.topDeleters).toEqual([{ user: 'admin', count: 4 }])
	})

	it('refreshDeletedList fetches with the current page and limit', async () => {
		store.fetchDeleted = jest.fn().mockResolvedValue('done')
		store.setDeletedPagination({ page: 4, limit: 50 })
		await expect(store.refreshDeletedList()).resolves.toBe('done')
		expect(store.fetchDeleted).toHaveBeenCalledWith({ limit: 50, page: 4 })
	})
})
