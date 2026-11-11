/**
 * Golden record of reports.fetchWidgetData: the requests it makes, what it
 * returns, the widget cache it leaves and what it logs, for each data-source
 * mode, cache hits and misses, a forced refresh and each kind of failure.
 */
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { useReportsStore } from './reports.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))

jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
}))

const AGG = { mode: 'aggregation', register: 'r', schema: 's', aggregation: 'a' }
const GQL = { mode: 'graphql', graphqlQuery: '{ q }', variables: { v: 1 } }
const STATS = { mode: 'statistics', register: 'r' }

let consoleError

beforeEach(() => {
	setActivePinia(createPinia())
	jest.clearAllMocks()
	jest.useFakeTimers({ now: new Date('2026-11-01T10:00:00Z') })
	axios.get.mockResolvedValue({ data: { got: true } })
	axios.post.mockResolvedValue({ data: { data: { posted: true } } })
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.useRealTimers()
	consoleError.mockRestore()
})

/**
 * Run fetchWidgetData calls in order and record each result.
 *
 * @param {Array<Array>} steps Argument lists for fetchWidgetData.
 * @return {Promise<object>} The results, cache, requests and logs.
 */
async function run(steps) {
	const store = useReportsStore()
	const results = []
	for (const args of steps) {
		results.push({
			returned: await store.fetchWidgetData(...args),
			cache: JSON.parse(JSON.stringify(store.widgetData)),
		})
		jest.advanceTimersByTime(1000)
	}
	return {
		results,
		widgetData: store.widgetData,
		get: axios.get.mock.calls,
		post: axios.post.mock.calls,
		logged: consoleError.mock.calls.map(([tag, info]) => [
			tag,
			{ widget: info.widget, error: info.error?.message },
		]),
	}
}

describe('reports.fetchWidgetData golden', () => {
	it.each([
		[
			'no widget',
			[[undefined], [null], [{}], [{ dataSource: { register: 'r' } }]],
		],
		[
			'each mode, then a cache hit each',
			[
				[{ dataSource: AGG }],
				[{ dataSource: GQL }],
				[{ dataSource: STATS }],
				[{ dataSource: AGG }],
				[{ dataSource: GQL }],
				[{ dataSource: STATS }],
			],
		],
		[
			'unknown mode is cached as null and refetched',
			[
				[{ dataSource: { mode: 'other' } }],
				[{ dataSource: { mode: 'other' } }],
			],
		],
		['forced refresh', [[{ dataSource: AGG }], [{ dataSource: AGG }, true]]],
		[
			'inherited key as mode',
			[
				[{ dataSource: { mode: 'toString' } }],
				[{ dataSource: { mode: 'constructor' } }],
			],
		],
		[
			'aggregation without a name',
			[[{ dataSource: { mode: 'aggregation', register: 'r' } }]],
		],
		['graphql without a query', [[{ dataSource: { mode: 'graphql' } }]]],
	])('%s', async (_label, steps) => {
		expect(await run(steps)).toMatchSnapshot()
	})

	it('falsy data is not a cache hit', async () => {
		axios.get.mockResolvedValue({ data: 0 })
		expect(
			await run([[{ dataSource: STATS }], [{ dataSource: STATS }]]),
		).toMatchSnapshot()
	})

	it.each([
		[
			'server error text',
			Object.assign(new Error('msg'), {
				response: { data: { error: 'srv' } },
			}),
		],
		['message only', new Error('msg')],
		['empty message', new Error('')],
		['nothing', { response: {} }],
	])('failure: %s, then a retry', async (_label, err) => {
		axios.get.mockRejectedValueOnce(err)
		expect(
			await run([[{ dataSource: AGG }], [{ dataSource: AGG }]]),
		).toMatchSnapshot()
	})
})

describe('reports dashboard failures golden', () => {
	it.each([
		[
			'server error text',
			Object.assign(new Error('msg'), {
				response: { data: { error: 'srv' } },
			}),
		],
		['message only', new Error('msg')],
		['empty message', new Error('')],
		['nothing', { response: {} }],
	])('%s', async (_label, err) => {
		const store = useReportsStore()
		axios.get.mockRejectedValue(err)
		const list = await store
			.fetchDashboards({ register: 'r' })
			.catch((e) => e === err)
		const listState = { error: store.error, loading: store.loading }
		const one = await store.fetchDashboard('d').catch((e) => e === err)
		expect({
			list,
			listState,
			one,
			oneState: { error: store.error, loading: store.loading },
			logged: consoleError.mock.calls.map(([tag, e]) => [tag, e === err]),
		}).toMatchSnapshot()
	})
})
