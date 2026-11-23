import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { useAvgStore } from './avg.js'

/**
 * Golden record of the AVG store's request frame, for every action that
 * talks to the server: loading while the request runs, the error cleared
 * before it, and on a refusal the error worded (server error, exception
 * message or default), logged under the action's name and rethrown.
 */

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), put: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: jest.fn((path) => `/index.php${path}`) }))

const ACTIONS = {
	fetchActivities: (store) => store.fetchActivities({ status: 'active' }),
	fetchActivity: (store) => store.fetchActivity('PA-1'),
	createActivity: (store) => store.createActivity({ name: 'N' }),
	updateActivity: (store) => store.updateActivity('PA-1', { name: 'N' }),
	archiveActivity: (store) => store.archiveActivity('PA-1'),
	fetchAccountability: (store) => store.fetchAccountability(),
	runAccess: (store) => store.runAccess({ subject: 's', type: 'bsn', mode: 'full' }),
	runErasure: (store) => store.runErasure({ subject: 's', type: 'bsn', dryRun: true }),
	runPortability: (store) => store.runPortability({ subject: 's', type: 'bsn' }),
	runRectification: (store) => store.runRectification({ subject: 's' }),
	fetchCompliance: (store) => store.fetchCompliance(),
	fetchCases: (store) => store.fetchCases({ status: 'open' }),
	fetchCase: (store) => store.fetchCase('c1'),
	updateCase: (store) => store.updateCase('c1', { a: 1 }),
	transitionCase: (store) => store.transitionCase('c1', 'close'),
	collectEvidence: (store) => store.collectEvidence('c1'),
	applyRedaction: (store) => store.applyRedaction('c1', { r: 1 }),
	generateBundle: (store) => store.generateBundle('c1'),
	downloadBundle: (store) => store.downloadBundle('c1', 'tok'),
	verifyIdentity: (store) => store.verifyIdentity('c1'),
	escalateRegulator: (store) => store.escalateRegulator('c1'),
	fetchActivePolicyPack: (store) => store.fetchActivePolicyPack({ jurisdiction: 'nl' }),
}

const REFUSALS = {
	serverError: { response: { data: { error: 'Server says no' } }, message: 'Exception text' },
	messageOnly: { message: 'Exception text' },
	bare: {},
}

describe.each(Object.keys(ACTIONS))('avg.%s', (action) => {
	let store
	let logged

	beforeEach(() => {
		setActivePinia(createPinia())
		jest.clearAllMocks()
		logged = jest.spyOn(console, 'error').mockImplementation(() => {})
		store = useAvgStore()
		store.error = 'old'
		global.URL.createObjectURL = jest.fn(() => 'blob:x')
		global.URL.revokeObjectURL = jest.fn()
	})

	afterEach(() => logged.mockRestore())

	it.each(Object.keys(REFUSALS))('on a %s refusal', async (refusal) => {
		const seen = []
		for (const verb of ['get', 'post', 'put', 'delete']) {
			axios[verb].mockImplementation(() => {
				seen.push([verb, store.loading, store.error])
				return Promise.reject(REFUSALS[refusal])
			})
		}
		let thrown
		try {
			await ACTIONS[action](store)
		} catch (e) {
			thrown = e
		}
		expect({
			seen: seen.slice(0, 1),
			thrown: thrown === REFUSALS[refusal],
			loading: store.loading,
			error: store.error,
			logged: logged.mock.calls.map(([tag, e]) => [tag, e === REFUSALS[refusal]]),
		}).toMatchSnapshot()
	})

	it('clears the loading flag after an answer', async () => {
		const seen = []
		for (const verb of ['get', 'post', 'put', 'delete']) {
			axios[verb].mockImplementation(() => {
				seen.push([verb, store.loading, store.error])
				return Promise.resolve({ data: { results: [], packs: [], jurisdiction: 'nl' } })
			})
		}
		try {
			await ACTIONS[action](store)
		} catch {
			// The answer's shape may not suit every action; only the frame is pinned.
		}
		expect({ seen: seen.slice(0, 1), loading: store.loading }).toMatchSnapshot()
	})
})
