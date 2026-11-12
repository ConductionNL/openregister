/**
 * Golden record of the configuration store's save, upload, source-list and
 * import actions: the requests each sends, what it returns or throws (with
 * the cause), the state it leaves and what it logs, for good answers and
 * every kind of failure.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useConfigurationStore } from './configuration.js'

const LIST_URL = '/index.php/apps/openregister/api/configurations'

let consoleError
let answer

/**
 * Answer a fetch: the configuration list endpoint always answers with one
 * row; every other URL gets the current `answer`.
 *
 * @param {string} url The request URL.
 * @return {Promise<object>} The fake response.
 */
function fakeFetch(url) {
	if (url === LIST_URL) {
		return Promise.resolve({
			ok: true,
			status: 200,
			json: async () => ({ results: [{ id: 'listed' }] }),
		})
	}
	if (answer.reject) {
		return Promise.reject(new Error(answer.reject))
	}
	return Promise.resolve({
		ok: answer.ok,
		status: answer.status,
		json: async () => answer.body,
	})
}

const ANSWERS = [
	[
		'ok object',
		{
			ok: true,
			status: 200,
			body: {
				id: 5,
				title: 'T',
				branches: ['main'],
				files: ['a.json'],
				results: [{ id: 1 }],
			},
		},
	],
	['ok empty object', { ok: true, status: 200, body: {} }],
	['ok null', { ok: true, status: 200, body: null }],
	['ok string', { ok: true, status: 200, body: 'text' }],
	['error with text', { ok: false, status: 422, body: { error: 'Bad input' } }],
	['error without text', { ok: false, status: 500, body: {} }],
	['network failure', { reject: 'offline' }],
]

const ACTIONS = [
	[
		'saveConfiguration new',
		(s) =>
			s.saveConfiguration({
				title: 'n',
				id: undefined,
				uuid: 'u',
				created: 1,
				updated: 2,
			}),
	],
	[
		'saveConfiguration existing',
		(s) => s.saveConfiguration({ id: 9, title: 'e' }),
	],
	['saveConfiguration missing', (s) => s.saveConfiguration(null)],
	['uploadConfiguration new', (s) => s.uploadConfiguration({ json: '{}' })],
	[
		'uploadConfiguration existing',
		(s) => {
			s.configurationItem = { id: 4 }
			return s.uploadConfiguration({ json: '{}' })
		},
	],
	['uploadConfiguration missing', (s) => s.uploadConfiguration(undefined)],
	['getBranches', (s) => s.getBranches('github', { owner: 'o', repo: 'r' })],
	[
		'getConfigurationFiles',
		(s) => s.getConfigurationFiles('gitlab', { branch: 'main' }),
	],
	['importFromGitHub', (s) => s.importFromGitHub({ owner: 'o', repo: 'r' })],
	['importFromGitLab', (s) => s.importFromGitLab({ project: 'p' })],
	['importFromUrl', (s) => s.importFromUrl({ url: 'https://example.com/c.json' })],
]

beforeEach(() => {
	setActivePinia(createPinia())
	global.fetch = jest.fn(fakeFetch)
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	consoleError.mockRestore()
})

describe.each(ACTIONS)('configuration %s golden', (_label, run) => {
	it.each(ANSWERS)('%s', async (_answer, given) => {
		answer = given
		const store = useConfigurationStore()
		let outcome
		try {
			const result = await run(store)
			outcome = {
				returned:
					result && result.response
						? {
								...result,
								response: {
									ok: result.response.ok,
									status: result.response.status,
								},
							}
						: result,
			}
		} catch (error) {
			outcome = { thrown: error.message, cause: error.cause?.message }
		}
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect({
			outcome: JSON.parse(JSON.stringify(outcome)),
			requests: global.fetch.mock.calls,
			state: JSON.parse(JSON.stringify(store.$state)),
			logged: consoleError.mock.calls.map(([text, error]) => [
				text,
				error?.message,
			]),
		}).toMatchSnapshot()
	})
})
