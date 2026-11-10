/**
 * Golden record of searchStore.searchObjects(): the request it sends and the
 * state it leaves for a successful, a failed and a rejected response, with
 * and without a register, schema and query.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useSearchStore } from './search.ts'

const SELECTIONS = {
	none: { register: null, schema: null },
	both: { register: { label: 'R', id: '5' }, schema: { label: 'S', id: 'abc' } },
	registerOnly: { register: { label: 'R', id: '5' }, schema: null },
	emptyIds: { register: { label: 'R', id: '' }, schema: { label: 'S', id: '' } },
}

const QUERIES = {
	none: undefined,
	empty: {},
	two: { _search: 'a b', _page: '2' },
}

const RESPONSES = {
	ok: () =>
		Promise.resolve({
			ok: true,
			statusText: 'OK',
			json: () => Promise.resolve([{ id: 1 }]),
		}),
	notOk: () =>
		Promise.resolve({
			ok: false,
			statusText: 'Bad Request',
			json: () => Promise.resolve({ e: 1 }),
		}),
	notOkNoText: () =>
		Promise.resolve({
			ok: false,
			statusText: '',
			json: () => Promise.resolve([{ id: 2 }]),
		}),
	rejected: () => Promise.reject(new Error('offline')),
}

/**
 * Run one search and keep the request and the resulting state.
 *
 * @param {object} c The selection, query and response names.
 * @return {Promise<object>} The recorded outcome.
 */
async function run(c) {
	setActivePinia(createPinia())
	const store = useSearchStore()
	store.searchObjectsDataRegister = SELECTIONS[c.selection].register
	store.searchObjectsDataSchema = SELECTIONS[c.selection].schema
	const calls = []
	global.fetch = jest.fn((...args) => {
		calls.push(args)
		return RESPONSES[c.response]()
	})
	const query = QUERIES[c.query]
	const refs =
		query === undefined ? store.searchObjects() : store.searchObjects(query)
	const loadingAtReturn = refs.loading.value
	await new Promise((resolve) => setTimeout(resolve, 0))
	await new Promise((resolve) => setTimeout(resolve, 0))
	return {
		calls,
		loadingAtReturn,
		success: store.searchObjectsSuccess,
		loading: store.searchObjectsLoading,
		result: store.searchObjectsResult,
		error: String(store.searchObjectsError),
		sameRefs:
			refs.result === store.$state.searchObjectsResult
			|| refs.result.value === store.searchObjectsResult,
	}
}

const CASES = []
Object.keys(SELECTIONS).forEach((selection) => {
	Object.keys(QUERIES).forEach((query) => {
		CASES.push({ selection, query, response: 'ok' })
	})
})
Object.keys(RESPONSES).forEach((response) => {
	CASES.push({ selection: 'both', query: 'two', response })
})

describe('searchStore.searchObjects golden', () => {
	beforeEach(() => {
		jest.spyOn(console, 'group').mockImplementation(() => {})
		jest.spyOn(console, 'groupEnd').mockImplementation(() => {})
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	it.each(CASES.map((c) => [`${c.selection} ${c.query} ${c.response}`, c]))(
		'%s',
		async (_name, c) => {
			expect(await run(c)).toMatchSnapshot()
		},
	)

	it('re-runs the last query', async () => {
		setActivePinia(createPinia())
		const store = useSearchStore()
		const calls = []
		global.fetch = jest.fn((...args) => {
			calls.push(args)
			return RESPONSES.ok()
		})
		store.searchObjects({ a: '1' })
		store.reDoSearch()
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(calls).toMatchSnapshot()
	})
})
