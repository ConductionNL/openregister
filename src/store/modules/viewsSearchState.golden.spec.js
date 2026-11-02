/**
 * Golden record of the views store's search-state actions: which search
 * store setters applyView calls and with what, and the view that
 * createViewFromSearchState builds, for full, partial, empty and falsy input.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useViewsStore } from './views.js'

const SETTERS = [
	'setSelectedRegisters',
	'setSelectedSchemas',
	'setSource',
	'setSearchTerms',
	'setFacetFilters',
	'setEnabledFacets',
	'setAdvancedFilters',
	'setPagination',
	'setSorting',
	'setColumns',
]

/**
 * Build a search store double that records every setter call in order.
 *
 * @return {object} The double, with its `log` of calls.
 */
function recordingSearchStore() {
	const log = []
	const store = { log }
	for (const name of SETTERS) {
		store[name] = (value) => log.push([name, value])
	}
	return store
}

const FULL = {
	registers: [1, 2],
	schemas: [3],
	source: 'database',
	searchTerms: ['a'],
	facetFilters: { f: 1 },
	enabledFacets: { e: true },
	advancedFilters: { x: 'y' },
	pagination: { page: 2, limit: 50 },
	sorting: { title: 'asc' },
	columns: { c: true },
}

const FALSY = {
	registers: null,
	schemas: undefined,
	source: '',
	searchTerms: 0,
	facetFilters: false,
	enabledFacets: null,
	advancedFilters: '',
	pagination: 0,
	sorting: null,
	columns: undefined,
}

beforeEach(() => {
	setActivePinia(createPinia())
})

describe('views store search-state golden', () => {
	it.each([
		['null view', null],
		['no configuration', { id: 1 }],
		['full configuration', { id: 2, configuration: FULL }],
		['empty configuration', { id: 3, configuration: {} }],
		['falsy values', { id: 4, configuration: FALSY }],
		[
			'partial configuration',
			{ id: 5, configuration: { schemas: [], sorting: { a: 1 }, extra: 1 } },
		],
	])('applyView: %s', (_label, view) => {
		const store = useViewsStore()
		const search = recordingSearchStore()
		store.applyView(view, search)
		expect({ calls: search.log, activeView: store.activeView }).toMatchSnapshot()
	})

	it.each([
		[
			'full state, defaults',
			[
				{
					selectedRegisters: [1],
					selectedSchemas: [2],
					source: 'index',
					searchTerms: ['q'],
					facetFilters: { a: 1 },
					enabledFacets: { b: 1 },
					advancedFilters: { c: 1 },
					pagination: { page: 3, limit: 5 },
					sorting: { d: 1 },
					columns: { e: 1 },
				},
				'name',
			],
		],
		['empty state, all arguments', [{}, 'n', 'desc', true, true]],
		[
			'falsy state',
			[
				{
					selectedRegisters: null,
					source: '',
					pagination: 0,
					columns: false,
				},
				'',
				undefined,
				undefined,
				1,
			],
		],
	])('createViewFromSearchState: %s', (_label, args) => {
		const store = useViewsStore()
		const view = store.createViewFromSearchState(...args)
		expect(view).toMatchSnapshot()
	})

	it('createViewFromSearchState returns fresh default objects each call', () => {
		const store = useViewsStore()
		const a = store.createViewFromSearchState({}, 'a')
		const b = store.createViewFromSearchState({}, 'b')
		expect(a.configuration.pagination).not.toBe(b.configuration.pagination)
		expect(a.configuration.registers).not.toBe(b.configuration.registers)
	})
})
