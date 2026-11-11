/**
 * Golden record of branch-heavy store and service steps: the register import
 * heartbeat, the schema object count and save cleaning, the quality store's
 * scoped listings, the reports store's dashboard and widget reads, and the
 * app install service's install and uninstall.
 *
 * @spec exclude Golden record of API passthroughs and client-side helpers.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { useQualityStore } from '../store/modules/quality.js'
import { useRegisterStore } from '../store/modules/register.js'
import { useReportsStore } from '../store/modules/reports.js'
import { useSchemaStore } from '../store/modules/schema.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), put: jest.fn(), post: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
}))

/**
 * A fetch double that answers each call with the next answer of a list.
 *
 * @param {Array<object>} answers `{ ok, status, body }` or `{ reject }` per call; the last one repeats.
 * @return {Function} The double; its calls are in `.mock.calls`.
 */
function fetchAnswering(answers) {
	let call = 0
	return jest.fn(async () => {
		const answer = answers[Math.min(call++, answers.length - 1)]
		if (answer.reject) {
			throw new Error(answer.reject)
		}
		return {
			ok: answer.ok,
			status: answer.status,
			statusText: answer.statusText ?? 'Status',
			json: async () => {
				if (answer.unreadable) {
					throw new SyntaxError('Unexpected token')
				}
				return answer.body
			},
		}
	})
}

/**
 * Run an async call and record what it returned or threw.
 *
 * @param {Function} call The call.
 * @return {Promise<object>} The outcome.
 */
async function outcome(call) {
	try {
		return { returned: JSON.parse(JSON.stringify((await call()) ?? null)) }
	} catch (error) {
		return { thrown: error.message, status: error.response?.status ?? null }
	}
}

/**
 * An OK answer.
 *
 * @param {object} body The body.
 * @return {object} The answer.
 */
function OK(body) {
	return { ok: true, status: 200, body }
}

/**
 * A refused answer.
 *
 * @param {number} status The status.
 * @return {object} The answer.
 */
function FAIL(status) {
	return { ok: false, status, body: { error: 'no' }, statusText: 'Bad' }
}

let logged

beforeEach(() => {
	setActivePinia(createPinia())
	jest.clearAllMocks()
	logged = []
	jest.spyOn(console, 'error').mockImplementation((...parts) =>
		logged.push(
			parts.map((part) =>
				part instanceof Error
					? part.message
					: JSON.parse(JSON.stringify(part ?? null)),
			),
		),
	)
})

afterEach(() => {
	console.error.mockRestore()
	jest.useRealTimers()
})

describe('branchy stores golden', () => {
	it('beats the import heartbeat and reports health changes', async () => {
		jest.useFakeTimers()
		const sequences = [
			[OK({}), OK({})],
			[FAIL(502), FAIL(503), FAIL(504), FAIL(500), OK({}), OK({})],
			[
				{ reject: 'offline' },
				OK({}),
				{ reject: 'offline' },
				{ reject: 'x' },
				{ reject: 'y' },
				OK({}),
			],
		]
		const out = []
		for (const [index, answers] of sequences.entries()) {
			for (const withCallback of [true, false]) {
				global.fetch = fetchAnswering(answers)
				const changes = []
				const store = useRegisterStore()
				logged = []
				const heartbeat = store.startImportHeartbeat(
					100,
					withCallback ? (change) => changes.push(change) : null,
				)
				const statuses = []
				for (let beat = 0; beat < answers.length; beat++) {
					await jest.advanceTimersByTimeAsync(100)
					statuses.push(heartbeat.getStatus())
				}
				heartbeat.stop()
				await jest.advanceTimersByTimeAsync(500)
				out.push({
					index,
					withCallback,
					changes,
					statuses,
					requests: global.fetch.mock.calls.map(([url, init]) => [
						url,
						init.method,
						init.headers,
						typeof init.signal,
					]),
					logged,
				})
			}
		}
		expect(out).toMatchSnapshot()
	})

	it('counts the objects of a schema', async () => {
		const cases = [
			[[{ id: 7, stats: { objects: { total: 4 } } }], [OK({ count: 9 })]],
			[[{ id: '7', stats: { objects: { total: 0 } } }], [OK({ count: 9 })]],
			[[{ id: 7, stats: {} }], [OK({ count: 9 })]],
			[[], [OK({ total: 5 })]],
			[[], [OK({})]],
			[[], [FAIL(404), OK({ objectCount: 3 })]],
			[[], [FAIL(404), OK({ objects_count: 2 })]],
			[[], [FAIL(404), OK({})]],
			[[], [FAIL(404), FAIL(500)]],
			[[], [FAIL(404), { ok: false, status: 500, body: { objectCount: 5 } }]],
			[[], [{ reject: 'down' }, OK({ objectCount: 6 })]],
			[[], [OK({}), OK({})]],
			[
				[],
				[
					{ ok: true, status: 200, unreadable: true },
					OK({ objectCount: 8 }),
				],
			],
			[[], [{ reject: 'down' }, { reject: 'down' }]],
			[[], [FAIL(404), { ok: true, status: 200, unreadable: true }]],
			[null, [OK({ count: 1 })]],
		]
		const out = []
		for (const [schemas, answers] of cases) {
			global.fetch = fetchAnswering(answers)
			const store = useSchemaStore()
			store.schemas = schemas
			out.push([
				await outcome(() => store.getObjectCount(7)),
				global.fetch.mock.calls.map(([url]) => url),
			])
		}
		expect(out).toMatchSnapshot()
	})

	it('cleans a schema for saving', () => {
		const store = useSchemaStore()
		const items = [
			{},
			{
				id: '',
				title: 'New',
				updated: 'u',
				created: 'c',
				stats: {},
				archive: {},
				version: '1',
				draft: {},
			},
			{ id: 5, configuration: { objectNameField: 'name' } },
			{
				id: 5,
				required: ['a', 'b'],
				properties: { a: { type: 'string' }, c: {} },
			},
			{ id: 5, required: ['a'] },
			{ id: 5, required: 'a', properties: { a: {} } },
			{ id: 5, required: [], properties: {} },
			{ id: 0, configuration: null },
		]
		expect(
			items.map((item) => {
				const copy = JSON.parse(JSON.stringify(item))
				return { cleaned: store.cleanSchemaForSave(copy), input: copy }
			}),
		).toMatchSnapshot()
	})

	it('reads quality listings', async () => {
		const answers = [
			[
				'answer',
				() =>
					axios.get.mockResolvedValue({
						data: {
							items: [{ id: 1 }],
							results: [{ id: 2 }],
							total: 9,
							limit: 5,
							offset: 10,
						},
					}),
			],
			[
				'bare answer',
				() =>
					axios.get.mockResolvedValue({ data: { results: [{ id: 3 }] } }),
			],
			[
				'items only',
				() => axios.get.mockResolvedValue({ data: { items: [{ id: 4 }] } }),
			],
			['no data', () => axios.get.mockResolvedValue({ data: null })],
			[
				'server error',
				() =>
					axios.get.mockRejectedValue(
						Object.assign(new Error('m'), {
							response: { data: { error: 'srv' } },
						}),
					),
			],
			['message only', () => axios.get.mockRejectedValue(new Error('m'))],
			['nothing', () => axios.get.mockRejectedValue({})],
		]
		const calls = [
			['fetchMasterEntities', ['r', 's', { limit: 3 }]],
			['fetchMasterEntities', ['', 's']],
			['fetchMasterEntities', ['r', null]],
			['fetchMergeOperations', [{ limit: 2, offset: 4 }]],
			['fetchMergeOperations', []],
			['fetchLowQualityObjects', ['r', 's', { limit: 7 }]],
			['fetchLowQualityObjects', ['r', 's']],
			['fetchDuplicates', ['r', 's', { offset: 7 }]],
		]
		const out = []
		for (const [answerName, answer] of answers) {
			for (const [action, args] of calls) {
				setActivePinia(createPinia())
				jest.clearAllMocks()
				logged = []
				answer()
				const store = useQualityStore()
				store.error = 'earlier failure'
				const before = JSON.parse(JSON.stringify(store.$state))
				const result = await outcome(() => store[action](...args))
				const after = JSON.parse(JSON.stringify(store.$state))
				out.push({
					answerName,
					action,
					args,
					result,
					requests: axios.get.mock.calls,
					state: Object.fromEntries(
						Object.keys(after)
							.filter(
								(key) =>
									JSON.stringify(after[key])
									!== JSON.stringify(before[key]),
							)
							.map((key) => [key, after[key]]),
					),
					logged,
				})
			}
		}
		expect(out).toMatchSnapshot()
	})

	it('reads report dashboards and widgets', async () => {
		const answers = [
			[
				'answer',
				() =>
					axios.get.mockResolvedValue({
						data: { results: [{ id: 1 }], id: 'd' },
					}),
			],
			['plain list', () => axios.get.mockResolvedValue({ data: [{ id: 2 }] })],
			['no data', () => axios.get.mockResolvedValue({ data: null })],
			[
				'server error',
				() =>
					axios.get.mockRejectedValue(
						Object.assign(new Error('m'), {
							response: { data: { error: 'srv' } },
						}),
					),
			],
			['message only', () => axios.get.mockRejectedValue(new Error('m'))],
		]
		const calls = [
			['fetchDashboards', []],
			['fetchDashboards', [{ register: 'r', schema: 's', status: 'live' }]],
			['fetchDashboard', ['dash-1']],
			['fetchDashboard', ['dash-1', { register: 'r', schema: 's' }]],
			['fetchDashboard', ['']],
		]
		const out = []
		for (const [answerName, answer] of answers) {
			for (const [action, args] of calls) {
				setActivePinia(createPinia())
				jest.clearAllMocks()
				logged = []
				answer()
				const store = useReportsStore()
				store.error = 'earlier failure'
				const before = JSON.parse(JSON.stringify(store.$state))
				const result = await outcome(() => store[action](...args))
				const after = JSON.parse(JSON.stringify(store.$state))
				out.push({
					answerName,
					action,
					args,
					result,
					requests: axios.get.mock.calls,
					state: Object.fromEntries(
						Object.keys(after)
							.filter(
								(key) =>
									JSON.stringify(after[key])
									!== JSON.stringify(before[key]),
							)
							.map((key) => [key, after[key]]),
					),
					logged,
				})
			}
		}
		expect(out).toMatchSnapshot()
	})

	it('caches widget data', async () => {
		jest.useFakeTimers()
		jest.setSystemTime(new Date('2026-01-01T00:00:00Z'))
		const out = []
		const store = useReportsStore()
		const sources = []
		store._fetchWidgetSource = jest.fn(async (source) => {
			sources.push(source)
			if (source?.fail) {
				throw Object.assign(new Error('widget broke'), {
					response: { data: { error: 'srv' } },
				})
			}
			return { rows: [source?.register] }
		})
		const widgets = [
			[null, false],
			[{}, false],
			[{ dataSource: { mode: 'statistics', register: 1 } }, false],
			[{ dataSource: { mode: 'statistics', register: 1 } }, false],
			[{ dataSource: { mode: 'statistics', register: 1 } }, true],
			[{ dataSource: { mode: 'statistics', register: 2, fail: true } }, false],
			[{ dataSource: { mode: 'statistics', register: 2, fail: true } }, false],
		]
		for (const [widget, force] of widgets) {
			logged = []
			out.push({
				result: await outcome(() => store.fetchWidgetData(widget, force)),
				cache: JSON.parse(JSON.stringify(store.widgetData)),
				logged,
			})
		}
		expect({ out, sources }).toMatchSnapshot()
	})
})
