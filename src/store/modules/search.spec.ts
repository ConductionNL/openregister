/* eslint-disable no-console */
import { createPinia, setActivePinia } from 'pinia'

import { useSearchStore } from './search'

describe('Search Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('clears the object search results', () => {
		const store = useSearchStore()

		store.searchObjectsResult = [
			{ id: 1, title: 'Lorem ipsum dolor sit amet' },
		]

		store.clearObjectSearchResults()

		expect(store.searchObjectsResult).toEqual([])
	})
})
