/**
 * Golden record of the application and webhook stores' list, read, save,
 * delete and toggle actions: the requests, the loading and error state while
 * and after they run, what they return or throw, and what they log.
 *
 * @spec exclude Golden record of API passthroughs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { useApplicationStore } from './application.js'
import { useWebhookStore } from './webhook.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), put: jest.fn(), post: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `url:${path}`),
}))

const ANSWERS = [
	[
		'ok',
		{
			ok: true,
			status: 200,
			body: { id: 'srv', name: 'Server', results: [{ id: 'r1' }] },
		},
	],
	['ok without results', { ok: true, status: 200, body: { id: 'srv' } }],
	['refused', { ok: false, status: 500, body: { error: 'no' } }],
	['network failure', { reject: 'Network down' }],
]

const ACTIONS = [
	[useApplicationStore, 'refreshApplicationList', () => []],
	[useApplicationStore, 'refreshApplicationList', () => ['find me', true]],
	[useApplicationStore, 'refreshApplicationList', () => ['', false]],
	[useApplicationStore, 'getApplication', () => ['a1']],
	[useApplicationStore, 'deleteApplication', () => [{ id: 'a1' }]],
	[useApplicationStore, 'deleteApplication', () => [{}]],
	[
		useApplicationStore,
		'saveApplication',
		() => [
			{ id: 'a1', name: 'App', uuid: 'u', usage: 1, owner: 'o', active: '' },
		],
	],
	[useApplicationStore, 'saveApplication', () => [{ name: 'New' }]],
	[useApplicationStore, 'saveApplication', () => [null]],
	[useWebhookStore, 'refreshWebhookList', () => []],
	[useWebhookStore, 'refreshWebhookList', () => [true]],
	[useWebhookStore, 'saveWebhook', () => [{ id: 'w1', name: 'Hook' }]],
	[useWebhookStore, 'saveWebhook', () => [{ name: 'New' }]],
	[useWebhookStore, 'saveWebhook', () => [null]],
	[useWebhookStore, 'deleteWebhook', () => ['w1']],
	[useWebhookStore, 'deleteWebhook', () => [0]],
	[useWebhookStore, 'toggleWebhookEnabled', () => [{ id: 'w1', enabled: true }]],
	[useWebhookStore, 'toggleWebhookEnabled', () => [{}]],
]

/**
 * The plain state of a store.
 *
 * @param {object} store The store.
 * @return {object} Its state.
 */
function plain(store) {
	return JSON.parse(JSON.stringify(store.$state))
}

describe('item request actions golden', () => {
	for (const [useStore, action, args] of ACTIONS) {
		for (const [answerName, answer] of ANSWERS) {
			it(`${useStore.$id}.${action}(${JSON.stringify(args())}): ${answerName}`, async () => {
				setActivePinia(createPinia())
				jest.clearAllMocks()
				const requests = []
				const respond = async (...request) => {
					requests.push(JSON.parse(JSON.stringify(request)))
					if (answer.reject) {
						throw new Error(answer.reject)
					}
					if (!answer.ok) {
						throw Object.assign(
							new Error(
								`Request failed with status code ${answer.status}`,
							),
							{
								response: {
									status: answer.status,
									data: answer.body,
								},
							},
						)
					}
					return { data: JSON.parse(JSON.stringify(answer.body)) }
				}
				for (const method of ['get', 'put', 'post', 'delete']) {
					axios[method].mockImplementation(respond)
				}
				global.fetch = jest.fn(async (...request) => {
					requests.push(JSON.parse(JSON.stringify(request)))
					if (answer.reject) {
						throw new Error(answer.reject)
					}
					return {
						ok: answer.ok,
						status: answer.status,
						json: async () => JSON.parse(JSON.stringify(answer.body)),
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
				const store = useStore()
				store.error = 'earlier'
				store.loading = 'earlier'
				const promise = store[action](...args())
				const during = { loading: store.loading, error: store.error }
				let outcome
				try {
					const result = await promise
					outcome = {
						returned: JSON.parse(
							JSON.stringify(result ?? null, (key, value) =>
								key === 'response' ? '<response>' : value,
							),
						),
					}
				} catch (error) {
					outcome = {
						thrown: error.message,
						cause: error.cause?.message ?? null,
					}
				}
				console.error.mockRestore()
				expect({
					requests,
					during,
					outcome,
					state: plain(store),
					logged,
				}).toMatchSnapshot()
			})
		}
	}
})
