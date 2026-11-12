/**
 * Golden record of the configuration store's source actions: discovering
 * configurations, listing branches and files, and importing from GitHub,
 * GitLab or a URL. Records the requests, what each returns or throws and
 * what it logs, for every kind of answer.
 *
 * @spec exclude Golden record of API passthroughs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { createPinia, setActivePinia } from 'pinia'
import { useConfigurationStore } from './configuration.js'

const ANSWERS = [
	[
		'ok',
		{
			ok: true,
			status: 200,
			body: {
				results: [{ id: 'c1' }],
				branches: ['main'],
				files: ['a.json'],
				imported: 2,
			},
		},
	],
	['ok empty', { ok: true, status: 200, body: {} }],
	[
		'refused with error',
		{ ok: false, status: 422, body: { error: 'Source says no' } },
	],
	['refused without error', { ok: false, status: 500, body: {} }],
	['unreadable', { ok: false, status: 502, unreadable: true }],
	['network failure', { reject: 'Network down' }],
]

const ACTIONS = [
	['discoverConfigurations', ['github']],
	['discoverConfigurations', ['gitlab', 'needle']],
	['getBranches', ['github', { owner: 'o', repo: 'r' }]],
	['getConfigurationFiles', ['gitlab', { project: 'p', branch: 'main' }]],
	['importFromGitHub', [{ owner: 'o', repo: 'r', path: 'x.json' }]],
	['importFromGitLab', [{ project: 'p', path: 'x.json' }]],
	['importFromUrl', [{ url: 'https://example.org/x.json' }]],
]

describe('configuration source actions golden', () => {
	for (const [action, args] of ACTIONS) {
		for (const [answerName, answer] of ANSWERS) {
			it(`${action}(${JSON.stringify(args)}): ${answerName}`, async () => {
				setActivePinia(createPinia())
				global.fetch = jest.fn(async () => {
					if (global.fetch.mock.calls.length > 1) {
						return {
							ok: true,
							status: 200,
							json: async () => ({ results: [] }),
						}
					}
					if (answer.reject) {
						throw new Error(answer.reject)
					}
					return {
						ok: answer.ok,
						status: answer.status,
						json: async () => {
							if (answer.unreadable) {
								throw new SyntaxError('Unexpected token')
							}
							return JSON.parse(JSON.stringify(answer.body))
						},
					}
				})
				const logged = []
				jest.spyOn(console, 'error').mockImplementation((...parts) =>
					logged.push(
						parts.map((part) =>
							part instanceof Error ? `Error(${part.message})` : part,
						),
					),
				)
				const store = useConfigurationStore()
				let outcome
				try {
					outcome = { returned: await store[action](...args) }
				} catch (error) {
					outcome = { thrown: error.message }
				}
				console.error.mockRestore()
				expect({
					requests: global.fetch.mock.calls,
					outcome,
					logged,
				}).toMatchSnapshot()
			})
		}
	}
})
