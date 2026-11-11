/**
 * Golden record of the dashboard store: its initial state, reset, and every
 * request action for each kind of answer: the requests sent, the flags set
 * while it runs, what it logs, returns or throws and the state it leaves.
 *
 * @spec exclude Golden record of API passthroughs and client-side state.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { useDashboardStore } from './dashboard.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `url:${path}`),
}))
jest.mock('./register.js', () => ({
	__esModule: true,
	useRegisterStore: jest.fn(() => globalThis.goldenScope.register),
}))
jest.mock('./schema.js', () => ({
	__esModule: true,
	useSchemaStore: jest.fn(() => globalThis.goldenScope.schema),
}))

const SCOPES = [
	['no scope', { register: {}, schema: {} }],
	[
		'register and schema',
		{ register: { registerItem: { id: 7 } }, schema: { schemaItem: { id: 9 } } },
	],
]

const ANSWERS = [
	[
		'answer',
		{ data: { registers: [{ title: 'System Totals' }], points: [1, 2] } },
	],
	['no data', { data: null }],
	['rejected', { reject: { message: 'Network' } }],
	['rejected without message', { reject: { message: '' } }],
]

const ACTIONS = [
	['fetchAuditTrailActionChart', [[]]],
	['fetchObjectsByRegisterChart', [[]]],
	['fetchObjectsBySchemaChart', [[]]],
	['fetchObjectsBySizeChart', [[]]],
	['fetchAllChartData', [[]]],
	['fetchAuditTrailStatistics', [[], [6]]],
	['fetchActionDistribution', [[], [6]]],
	['fetchMostActiveObjects', [[], [5, 6]]],
	['fetchAllStatistics', [[], [6, 5]]],
	['fetchRegisters', [[]]],
	['calculateSizes', [['reg-1']]],
	['setDateRange', [[], ['2026-01-01', '2026-02-01']]],
	['preload', [[]]],
]

/**
 * The state keys whose value differs from the snapshot taken before.
 *
 * @param {object} before The state before.
 * @param {object} after The state after.
 * @return {object} The changed keys with their new value.
 */
function changed(before, after) {
	const out = {}
	for (const key of Object.keys(after)) {
		if (JSON.stringify(after[key]) !== JSON.stringify(before[key])) {
			out[key] = after[key]
		}
	}
	return out
}

/**
 * A fresh dashboard store, with the request mocks answering `answer`.
 *
 * @param {object} answer The answer every request gets.
 * @param {Array} requests Where the requests are recorded.
 * @return {object} The store.
 */
function freshStore(answer, requests) {
	setActivePinia(createPinia())
	jest.clearAllMocks()
	for (const method of ['get', 'post']) {
		axios[method].mockImplementation(async (...request) => {
			requests.push([method, ...JSON.parse(JSON.stringify(request))])
			if (answer.reject) {
				throw Object.assign(new Error(answer.reject.message), answer.reject)
			}
			return JSON.parse(JSON.stringify(answer))
		})
	}
	return useDashboardStore()
}

describe('dashboard store golden', () => {
	it('starts from the initial state and resets to it', () => {
		const store = freshStore(ANSWERS[0][1], [])
		const initial = JSON.parse(JSON.stringify(store.$state))
		store.registers = [{ id: 1 }]
		store.loading = true
		store.error = 'x'
		store.isInitialized = true
		store.dateRange = { from: 'a', till: 'b' }
		store.chartData.auditTrailActions = [1]
		store.chartLoading.objectsBySize = true
		store.statisticsData.mostActiveObjects = [2]
		store.statisticsLoading.actionDistribution = true
		store.reset()
		expect({
			initial,
			reset: JSON.parse(JSON.stringify(store.$state)),
		}).toMatchSnapshot()
	})

	it('skips a fetch that is already loading', async () => {
		const requests = []
		const store = freshStore(ANSWERS[0][1], requests)
		globalThis.goldenScope = SCOPES[1][1]
		for (const key of Object.keys(store.chartLoading)) {
			store.chartLoading[key] = true
		}
		for (const key of Object.keys(store.statisticsLoading)) {
			store.statisticsLoading[key] = true
		}
		await store.fetchAllChartData()
		await store.fetchAllStatistics()
		expect({
			requests,
			state: JSON.parse(JSON.stringify(store.$state)),
		}).toMatchSnapshot()
	})

	for (const [action, argSets] of ACTIONS) {
		for (const args of argSets) {
			for (const [scopeName, scope] of SCOPES) {
				for (const [answerName, answer] of ANSWERS) {
					// setDateRange does not wait for its fetch, so a rejection would go unhandled.
					if (action === 'setDateRange' && answer.reject) {
						continue
					}
					it(`${action}(${JSON.stringify(args)}): ${scopeName}, ${answerName}`, async () => {
						globalThis.goldenScope = scope
						const requests = []
						const store = freshStore(answer, requests)
						const logged = []
						jest.spyOn(console, 'error').mockImplementation((...parts) =>
							logged.push(parts),
						)
						const before = JSON.parse(JSON.stringify(store.$state))
						const promise = store[action](...args)
						const whileRunning = changed(
							before,
							JSON.parse(JSON.stringify(store.$state)),
						)
						let outcome
						try {
							outcome = { returned: await promise }
						} catch (error) {
							outcome = { threw: error.message }
						}
						// setDateRange starts a fetch it does not wait for.
						await new Promise((resolve) => setTimeout(resolve, 0))
						console.error.mockRestore()
						expect({
							requests,
							whileRunning,
							outcome,
							state: changed(
								before,
								JSON.parse(JSON.stringify(store.$state)),
							),
							logged: logged.map(([text, error]) => [
								text,
								error?.message,
							]),
						}).toMatchSnapshot()
					})
				}
			}
		}
	}
})
