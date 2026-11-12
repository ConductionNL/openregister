/**
 * Golden record of the search store's shape and its legacy search: the state
 * and actions it exposes, the legacy setters, and the request and state of a
 * legacy search for an answer, a refusal and a failure.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useSearchStore } from './search.ts'

/**
 * Let queued promise callbacks run.
 *
 * @return {Promise<void>}
 */
async function flush() {
	await new Promise((resolve) => setTimeout(resolve, 0))
	await new Promise((resolve) => setTimeout(resolve, 0))
}

describe('the search store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => jest.restoreAllMocks())

	it('exposes the same state, in the same order, and the same actions', () => {
		const store = useSearchStore()
		expect(Object.keys(store.$state)).toMatchSnapshot('state')
		expect(Object.keys(store).filter((key) => typeof store[key] === 'function' && !key.startsWith('$'))).toMatchSnapshot('actions')
		expect(store.$state).toMatchSnapshot('initial state')
	})

	it('sets and clears the legacy search', () => {
		const store = useSearchStore()
		store.setSearch('jan')
		store.setSearchResults('results')
		expect([store.search, store.searchResults]).toEqual(['jan', 'results'])
		store.searchError = 'old'
		store.clearSearch()
		expect([store.search, store.searchError, store.searchResults]).toEqual(['', '', 'results'])
	})

	it.each([
		['an answer', () => Promise.resolve({ json: () => Promise.resolve({ results: [1] }) })],
		['a refusal with a message', () => Promise.resolve({ json: () => Promise.resolve({ code: 403, message: 'Denied' }) })],
		['a refusal without a message', () => Promise.resolve({ json: () => Promise.resolve({ code: 403 }) })],
		['a failure', () => Promise.reject(new Error('offline'))],
		['a failure without a message', () => Promise.reject({})],
	])('runs a legacy search for %s', async (label, answer) => {
		const store = useSearchStore()
		store.setSearch('a b')
		store.searchError = 'old'
		const calls = []
		global.fetch = jest.fn((...args) => {
			calls.push(args)
			return answer()
		})
		store.getSearchResults()
		await flush()
		expect({
			calls,
			results: store.searchResults,
			error: store.searchError,
			logged: console.error.mock.calls,
		}).toMatchSnapshot()
	})
})
