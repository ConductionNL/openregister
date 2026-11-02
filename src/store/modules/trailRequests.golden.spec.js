/**
 * Golden record of the fetch-based actions of the search trail, deleted and
 * audit trail stores: the request each one sends, what it returns or throws,
 * the state it leaves and what it logs, for a good answer, a failure with a
 * server error text and a failure without one.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useAuditTrailStore } from './auditTrail.js'
import { useDeletedStore } from './deleted.js'
import { useSearchTrailStore } from './searchTrail.js'

const LIST = [{ id: 1 }, { id: 2 }, { id: 3 }]

const ACTIONS = [
	[useSearchTrailStore, 'fetchStatistics', []],
	[
		useSearchTrailStore,
		'fetchStatistics',
		[{ from: '2026-01-01', to: '2026-02-01' }],
	],
	[useSearchTrailStore, 'fetchStatistics', [{ to: '2026-02-01' }]],
	[useSearchTrailStore, 'fetchPopularTerms', []],
	[useSearchTrailStore, 'fetchPopularTerms', [3]],
	[useSearchTrailStore, 'fetchActivity', []],
	[useSearchTrailStore, 'fetchActivity', ['weekly']],
	[useSearchTrailStore, 'fetchRegisterSchemaStats', []],
	[useSearchTrailStore, 'fetchUserAgentStats', []],
	[useSearchTrailStore, 'cleanupSearchTrails', []],
	[useSearchTrailStore, 'cleanupSearchTrails', [7]],
	[useSearchTrailStore, 'getStatistics', []],
	[useSearchTrailStore, 'getPopularTerms', [4]],
	[useSearchTrailStore, 'getActivity', ['monthly']],
	[useSearchTrailStore, 'getRegisterSchemaStats', []],
	[useSearchTrailStore, 'getUserAgentStats', []],
	[useDeletedStore, 'fetchStatistics', []],
	[useDeletedStore, 'fetchTopDeleters', []],
	[useDeletedStore, 'restoreDeleted', [2]],
	[useDeletedStore, 'restoreMultiple', [[1, 3]]],
	[useDeletedStore, 'permanentlyDelete', [2]],
	[useDeletedStore, 'permanentlyDeleteMultiple', [[1, 3]]],
	[useAuditTrailStore, 'fetchStatistics', []],
	[useAuditTrailStore, 'deleteAuditTrail', [2]],
	[useAuditTrailStore, 'deleteMultipleAuditTrails', [[1, 3]]],
	[useAuditTrailStore, 'getStatistics', []],
	[useAuditTrailStore, 'getActionDistribution', []],
	[useAuditTrailStore, 'getTopObjects', []],
]

const ANSWERS = [
	['ok', { ok: true, body: { total: 5, results: [{ id: 9 }], 2: 'x' } }],
	['failure with error text', { ok: false, body: { error: 'Server says no' } }],
	['failure without error text', { ok: false, body: {} }],
]

let consoleError

beforeEach(() => {
	setActivePinia(createPinia())
	global.fetch = jest.fn()
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	consoleError.mockRestore()
})

/**
 * Run one action against one answer and record everything it did.
 *
 * @param {Array} action The store factory, action name and arguments.
 * @param {object} answer The ok flag and JSON body of the answer.
 * @return {Promise<object>} The record.
 */
async function record(action, answer) {
	const [useStore, name, args] = action
	const store = useStore()
	store.$patch({ deletedList: [...LIST], auditTrailList: [...LIST] })
	global.fetch.mockResolvedValue({
		ok: answer.ok,
		json: async () => answer.body,
	})
	let outcome
	try {
		outcome = { returned: await store[name](...args) }
	} catch (error) {
		outcome = { thrown: error.message }
	}
	return {
		...outcome,
		requests: global.fetch.mock.calls,
		state: JSON.parse(JSON.stringify(store.$state)),
		logged: consoleError.mock.calls.map(([text, error]) => [
			text,
			error?.message,
		]),
	}
}

describe('trail store requests golden', () => {
	describe.each(
		ACTIONS.map((action) => [
			`${action[0].$id}.${action[1]}(${JSON.stringify(action[2])})`,
			action,
		]),
	)('%s', (_label, action) => {
		it.each(ANSWERS)('%s', async (_answer, answer) => {
			expect(await record(action, answer)).toMatchSnapshot()
		})
	})
})
