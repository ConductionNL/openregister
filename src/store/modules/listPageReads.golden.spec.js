/**
 * Golden record of the paged list reads of the audit trail, search trail and
 * deleted-objects stores: the URL each one requests, the list and pagination
 * it stores, what it returns and what it throws, over one matrix of options,
 * store state and responses.
 */
import { createPinia, setActivePinia } from 'pinia'
import { toRaw } from 'vue'
import { useAuditTrailStore } from './auditTrail.js'
import { useDeletedStore } from './deleted.js'
import { useSearchTrailStore } from './searchTrail.js'

const STORES = [
	{
		name: 'auditTrail',
		use: useAuditTrailStore,
		action: 'fetchAuditTrails',
		hasSort: true,
	},
	{
		name: 'searchTrail',
		use: useSearchTrailStore,
		action: 'fetchSearchTrails',
		hasSort: false,
	},
	{
		name: 'deleted',
		use: useDeletedStore,
		action: 'fetchDeleted',
		hasSort: false,
	},
]

const OPTIONS = {
	none: undefined,
	empty: {},
	paging: { limit: 20, offset: 40, page: 3 },
	zeroPaging: { limit: 0, offset: 0, page: 0 },
	search: { search: 'needle haystack' },
	filters: {
		filters: { action: 'create', a: null, b: undefined, c: '', d: 0, e: false },
	},
	sort: { sort: { created: 'DESC', action: 'ASC' } },
	all: {
		limit: 5,
		page: 2,
		search: 'q&x',
		filters: { user: 'bob', register: '' },
		sort: { created: 'ASC' },
	},
}

const STATES = {
	clean: {},
	stored: {
		Search: 'stored search',
		Filters: { register: '7', schema: '', user: 'alice' },
		Sort: { object: 'ASC' },
	},
}

const RESPONSES = {
	full: {
		ok: true,
		body: {
			results: [{ id: 1, nested: { a: 1 } }, { id: 2 }],
			total: 12,
			page: 2,
			pages: 3,
			limit: 5,
			offset: 5,
		},
	},
	bare: { ok: true, body: {} },
	errorNamed: { ok: false, body: { error: 'Server says no' } },
	errorUnnamed: { ok: false, body: {} },
}

/**
 * Run one case and describe everything it observably did.
 *
 * @param {object} def The store definition.
 * @param {object} c The case: option, state and response names.
 * @return {Promise<object>} The record.
 */
async function runCase(def, c) {
	setActivePinia(createPinia())
	const store = def.use()
	Object.entries(STATES[c.state]).forEach(([suffix, value]) => {
		if (suffix !== 'Sort' || def.hasSort) {
			store[def.name + suffix] = value
		}
	})
	const response = RESPONSES[c.response]
	const body = JSON.parse(JSON.stringify(response.body))
	fetch.mockReset()
	fetch.mockResolvedValueOnce({ ok: response.ok, json: async () => body })
	console.error.mockClear()

	const record = {}
	const options = OPTIONS[c.options]
	try {
		const result =
			options === undefined
				? await store[def.action]()
				: await store[def.action](options)
		record.returnedBody = result === body
	} catch (error) {
		record.thrown = error.message
	}
	const list = toRaw(store[def.name + 'List'])
	record.fetch = fetch.mock.calls
	record.list = list
	record.listIsResults = list === body.results
	record.pagination = toRaw(store[def.name + 'Pagination'])
	record.loading = store[def.name + 'Loading']
	record.consoleError = console.error.mock.calls.map(([msg, err]) => [
		msg,
		err && err.message,
	])
	return record
}

describe('paged list reads (golden)', () => {
	beforeEach(() => {
		jest.spyOn(console, 'error').mockImplementation(() => {})
		jest.spyOn(console, 'info').mockImplementation(() => {})
		jest.spyOn(console, 'log').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	STORES.forEach((def) => {
		it(`${def.name} matches the recorded behaviour`, async () => {
			const records = {}
			for (const options of Object.keys(OPTIONS)) {
				for (const state of Object.keys(STATES)) {
					records[`${options}/${state}/full`] = await runCase(def, {
						options,
						state,
						response: 'full',
					})
				}
			}
			for (const response of Object.keys(RESPONSES)) {
				records[`paging/clean/${response}`] = await runCase(def, {
					options: 'paging',
					state: 'clean',
					response,
				})
			}
			expect(records).toMatchSnapshot()
		})
	})
})
