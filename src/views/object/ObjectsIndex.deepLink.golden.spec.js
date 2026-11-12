/**
 * Golden record of ObjectsIndex::loadFromRoute: what a deep link to an
 * object primes, loads and fetches, and what it logs, for every answer.
 *
 * @spec exclude Golden record of client-side deep-link plumbing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ObjectsIndex from './ObjectsIndex.vue'
import { objectStore, registerStore, schemaStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	objectStore: {},
	registerStore: {},
	schemaStore: {},
}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('./ObjectDetails.vue', () => ({}))
jest.mock('./ObjectsList.vue', () => ({}))
jest.mock('../../composables/openAddObject.js', () => ({
	openAddObjectModal: jest.fn(),
}))

const FETCHES = {
	found: () =>
		Promise.resolve({
			ok: true,
			status: 200,
			json: async () => ({ id: 'o/1' }),
		}),
	missing: () => Promise.resolve({ ok: false, status: 404 }),
	thrown: () => Promise.reject(new Error('offline')),
	badJson: () =>
		Promise.resolve({
			ok: true,
			json: async () => {
				throw new Error('bad json')
			},
		}),
}
const CONTEXT = {
	loaded: { register: () => Promise.resolve(), list: () => Promise.resolve() },
	failing: {
		register: () => Promise.reject(new Error('no register')),
		list: () => Promise.reject(new Error('no list')),
	},
}

let log
beforeEach(() => {
	log = []
	jest.spyOn(console, 'error').mockImplementation((...args) =>
		log.push([
			'console.error',
			...args.map((arg) =>
				arg instanceof Error ? `Error(${arg.message})` : arg,
			),
		]),
	)
	global.OC = { requestToken: 'tok' }
})
afterEach(() => {
	jest.restoreAllMocks()
	delete global.OC
})

describe('ObjectsIndex.loadFromRoute (golden)', () => {
	for (const [fetchName, answer] of Object.entries(FETCHES)) {
		for (const [contextName, context] of Object.entries(CONTEXT)) {
			for (const withFilters of [true, false]) {
				it(`opens a deep link, fetch ${fetchName}, context ${contextName}, setFilters ${withFilters}`, async () => {
					objectStore.setFilters = withFilters
						? (...args) => log.push(['setFilters', ...args])
						: undefined
					registerStore.getRegister = (...args) => {
						log.push(['getRegister', ...args])
						return context.register()
					}
					schemaStore.getSchema = (...args) => {
						log.push(['getSchema', ...args])
						return Promise.resolve()
					}
					objectStore.refreshObjectList = () => {
						log.push(['refreshObjectList'])
						return context.list()
					}
					objectStore.setObjectItem = (...args) =>
						log.push(['setObjectItem', ...args])
					global.fetch = jest.fn((...args) => {
						log.push(['fetch', ...args])
						return answer()
					})
					const ctx = {
						$route: {
							params: { register: 'r 1', schema: 's/2', id: 'o?3' },
						},
					}
					await ObjectsIndex.methods.loadFromRoute.call(ctx)
					expect(log).toMatchSnapshot()
				})
			}
		}
	}

	it.each([
		[undefined],
		[{}],
		[{ register: 'r', schema: 's' }],
		[{ register: 'r', id: 'i' }],
	])('does nothing for the route params %j', async (params) => {
		global.fetch = jest.fn()
		objectStore.setFilters = jest.fn()
		await ObjectsIndex.methods.loadFromRoute.call({ $route: { params } })
		expect(global.fetch).not.toHaveBeenCalled()
		expect(objectStore.setFilters).not.toHaveBeenCalled()
	})
})
