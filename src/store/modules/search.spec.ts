/* eslint-disable no-console */
import { createPinia, setActivePinia } from 'pinia'

import { useSearchStore } from './search.js'

describe('Search Store', () => {
	beforeEach(
		() => {
			setActivePinia(createPinia())
		},
	)

	it('clear search correctly', () => {
		const store = useSearchStore()

		store.searchObjectsResult = [
			{
				id: 1,
				title: 'Lorem ipsum dolor sit amet',
			},
		]

		store.clearObjectSearch()

		expect(store.searchObjectsResult).toBe({})
	})
})
