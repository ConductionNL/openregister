/**
 * Golden record of the four object-relation stores (Deck cards, contacts,
 * emails, calendar events): the requests each action sends, what it returns
 * or throws and the state it leaves, for every answer shape and failure.
 */
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { useContactRelationsStore } from './contacts.js'
import { useDeckRelationsStore } from './deck.js'
import { useEmailRelationsStore } from './emails.js'
import { useEventRelationsStore } from './events.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), delete: jest.fn() },
}))

jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path, params) =>
		path.replace(/\{(\w+)\}/g, (_m, key) => `<${params[key]}>`),
	),
}))

const STORES = [
	['deck', useDeckRelationsStore, ['createOrLink'], 'ref'],
	['contacts', useContactRelationsStore, ['createOrLink'], 'uid'],
	['emails', useEmailRelationsStore, [], 'id'],
	['events', useEventRelationsStore, ['create', 'link'], 'id'],
]

const ANSWERS = [
	[
		'results',
		() => axios.get.mockResolvedValue({ data: { results: [{ id: 1 }] } }),
	],
	['flat list', () => axios.get.mockResolvedValue({ data: [{ id: 2 }] })],
	[
		'object without results',
		() => axios.get.mockResolvedValue({ data: { total: 0 } }),
	],
	['empty', () => axios.get.mockResolvedValue({ data: null })],
	['501', () => axios.get.mockRejectedValue({ response: { status: 501 } })],
	[
		'server error text',
		() =>
			axios.get.mockRejectedValue({
				response: { status: 500, data: { error: 'srv' } },
				message: 'm',
			}),
	],
	['message only', () => axios.get.mockRejectedValue({ message: 'm' })],
	['nothing', () => axios.get.mockRejectedValue({})],
]

/**
 * The plain state of a store.
 *
 * @param {object} store The store.
 * @return {object} Its state as plain data.
 */
function plain(store) {
	return JSON.parse(JSON.stringify(store.$state))
}

/**
 * Run an action and record its outcome.
 *
 * @param {Function} call The action call.
 * @return {Promise<object>} What it returned or threw.
 */
async function outcome(call) {
	try {
		return { returned: await call() }
	} catch (error) {
		return {
			thrown:
				error === undefined
					? 'undefined'
					: JSON.parse(JSON.stringify(error)),
		}
	}
}

beforeEach(() => {
	setActivePinia(createPinia())
	jest.clearAllMocks()
	axios.post.mockResolvedValue({ data: { created: true } })
	axios.delete.mockResolvedValue({ data: {} })
})

describe.each(STORES)(
	'%s relations store golden',
	(_name, useStore, writers, idField) => {
		it.each(ANSWERS)('fetch: %s', async (_label, answer) => {
			answer()
			const store = useStore()
			const result = await outcome(() => store.fetch('r', 's', 7))
			expect({
				result,
				state: plain(store),
				get: axios.get.mock.calls,
			}).toMatchSnapshot()
		})

		it('a second fetch clears the unavailable flag and the error', async () => {
			const store = useStore()
			axios.get.mockRejectedValueOnce({ response: { status: 501 } })
			await store.fetch('r', 's', 1)
			axios.get.mockRejectedValueOnce({ message: 'm' })
			await outcome(() => store.fetch('r', 's', 2))
			const between = plain(store)
			axios.get.mockResolvedValue({ data: [{ id: 3 }] })
			await store.fetch('r', 's', 2)
			expect({ between, after: plain(store) }).toMatchSnapshot()
		})

		it('writers post, refetch and return the answer', async () => {
			axios.get.mockResolvedValue({ data: [{ id: 4 }] })
			const store = useStore()
			const results = []
			for (const writer of writers) {
				results.push(await store[writer]('r', 's', 7, { title: writer }))
			}
			expect({
				results,
				state: plain(store),
				post: axios.post.mock.calls,
				get: axios.get.mock.calls,
			}).toMatchSnapshot()
		})

		it('unlink deletes and prunes the cached list', async () => {
			const store = useStore()
			store.byObject = {
				'r:s:7': [
					{ id: 'a' },
					{ [idField]: 'a', id: 'x' },
					{ ref: 'b', deckRef: 'a', uid: 'b', contactUid: 'a', id: 'a' },
					{ deckRef: 'a', contactUid: 'a', id: 'y' },
					{ id: 'keep' },
				],
			}
			const pruned = await store.unlink('r', 's', 7, 'a')
			const missing = await store.unlink('r', 's', 8, 'a b/c')
			expect({
				pruned,
				missing,
				state: plain(store),
				delete: axios.delete.mock.calls,
			}).toMatchSnapshot()
		})

		it('get and _url', () => {
			const store = useStore()
			store.byObject = { 'r:s:7': [{ id: 1 }] }
			expect({
				hit: store.get('r', 's', 7),
				miss: store.get('r', 's', 8),
				url: store._url('r', 's', 7),
				suffixed: store._url('r', 's', 7, '/x'),
			}).toMatchSnapshot()
		})
	},
)
