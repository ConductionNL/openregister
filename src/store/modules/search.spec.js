/* eslint-disable no-console */
import { createPinia, setActivePinia } from 'pinia'

import { useSearchStore } from './search.js'

describe(
	'Search Store', () => {
		beforeEach(
			() => {
				setActivePinia(createPinia())
			},
		)

		it(
			'set search correctly', () => {
				const store = useSearchStore()

				store.setSearch('cata')
				expect(store.search).toBe('cata')

				store.setSearch('woo')
				expect(store.search).toBe('woo')

				store.setSearch('foo bar')
				expect(store.search).toBe('foo bar')
			},
		)

		it(
			'set search result correctly', () => {
				const store = useSearchStore()

				store.setSearchResults(['foo', 'bar', 'bux'])
				expect(store.searchResults).toEqual(['foo', 'bar', 'bux'])
			},
		)

		it(
			'clear search correctly', () => {
				const store = useSearchStore()

				store.setSearch('Lorem ipsum dolor sit amet')
				expect(store.search).toBe('Lorem ipsum dolor sit amet')

				store.clearSearch()

				expect(store.search).toBe('')
			},
		)
	},
)
