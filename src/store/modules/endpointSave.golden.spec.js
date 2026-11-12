/**
 * Golden record of the endpoint store's create and update actions: the
 * requests each sends, what it returns or throws, the state it leaves and
 * what it logs, for a good answer, a failed answer and a rejected fetch.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useEndpointStore } from './endpoints.ts'

const LIST_URL = '/index.php/apps/openregister/api/endpoints'

let answer
let calls
let logged

/**
 * Answer a fetch: the list endpoint (GET) always answers with one row; every
 * other request gets the current `answer`.
 *
 * @param {string} url The request URL.
 * @param {object} init The request options.
 * @return {Promise<object>} The fake response.
 */
function fakeFetch(url, init) {
	calls.push([url, init])
	if (url === LIST_URL && init.method === 'GET') {
		return Promise.resolve({
			ok: true,
			json: () => Promise.resolve({ results: [{ id: 'listed' }] }),
		})
	}
	if (answer.reject) {
		return Promise.reject(new Error(answer.reject))
	}
	return Promise.resolve({
		ok: answer.ok,
		json: () => Promise.resolve(answer.body),
	})
}

const ANSWERS = {
	ok: { ok: true, body: { id: 9, name: 'saved' } },
	notOk: { ok: false, body: { error: 'nope' } },
	rejected: { reject: 'offline' },
}

const ITEMS = {
	withId: { id: 4, name: 'E', path: '/x' },
	withoutId: { name: 'E' },
}

/**
 * Run one action and keep what it did.
 *
 * @param {string} action The store action name.
 * @param {object} c The answer and item names.
 * @return {Promise<object>} The recorded outcome.
 */
async function run(action, c) {
	setActivePinia(createPinia())
	const store = useEndpointStore()
	store.setEndpointItem({ id: 'before' })
	answer = ANSWERS[c.answer]
	let returned
	let thrown
	try {
		returned = await store[action](ITEMS[c.item])
	} catch (error) {
		thrown = String(error)
	}
	await new Promise((resolve) => setTimeout(resolve, 0))
	return {
		calls,
		returned,
		thrown,
		item: store.endpointItem,
		list: store.endpointList,
		logged,
	}
}

describe('endpoint store save golden', () => {
	beforeEach(() => {
		calls = []
		logged = []
		global.fetch = jest.fn(fakeFetch)
		jest.spyOn(console, 'error').mockImplementation((...args) => {
			logged.push(args.map(String))
		})
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	const cases = []
	;['createEndpoint', 'updateEndpoint'].forEach((action) => {
		Object.keys(ANSWERS).forEach((a) => {
			Object.keys(ITEMS).forEach((item) => {
				cases.push([`${action} ${a} ${item}`, action, { answer: a, item }])
			})
		})
	})

	it.each(cases)('%s', async (_name, action, c) => {
		expect(await run(action, c)).toMatchSnapshot()
	})
})
