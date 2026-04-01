/* eslint-disable no-console */
import { createPinia, setActivePinia } from 'pinia'

import { useSearchStore } from './search.js'

describe('Search Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('has correct initial state', () => {
		const store = useSearchStore()

		expect(store.search).toBe('')
		expect(store.searchResults).toBe('')
		expect(store.searchError).toBe('')
		expect(store.searchObjectsSuccess).toBe(false)
		expect(store.searchObjectsLoading).toBe(false)
		expect(store.searchObjectsResult).toEqual([])
		expect(store.searchObjectsError).toBe('')
	})

	it('sets legacy search correctly', () => {
		const store = useSearchStore()

		store.setSearch('test query')

		expect(store.search).toBe('test query')
	})

	it('sets legacy search results', () => {
		const store = useSearchStore()
		const results = [{ id: 1, title: 'Result 1' }]

		store.setSearchResults(results)

		expect(store.searchResults).toEqual(results)
	})

	it('clears legacy search', () => {
		const store = useSearchStore()

		store.setSearch('query')
		store.clearSearch()

		expect(store.search).toBe('')
		expect(store.searchError).toBe('')
	})

	it('clears object search results correctly', () => {
		const store = useSearchStore()

		store.searchObjectsResult = [
			{ id: 1, title: 'Lorem ipsum dolor sit amet' },
		]

		store.clearObjectSearchResults()

		expect(store.searchObjectsResult).toEqual([])
		expect(store.searchObjectsSuccess).toBe(false)
		expect(store.searchObjectsLoading).toBe(false)
		expect(store.searchObjectsError).toBe('')
	})

	it('has search data state for register and schema', () => {
		const store = useSearchStore()

		expect(store.searchObjectsDataRegister).toBeNull()
		expect(store.searchObjectsDataSchema).toBeNull()
		expect(store.searchObjectsDataPagination).toBe(1)
		expect(store.searchObjectsDataPaginationLimit).toBe(14)
	})
})
