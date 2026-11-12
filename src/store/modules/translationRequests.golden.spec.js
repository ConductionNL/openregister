/**
 * Golden record of the translations store's object read and status change:
 * the requests, the loading and error state while and after they run, the
 * cache they leave, what they return or throw and what they log.
 *
 * @spec exclude Golden record of API passthroughs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { useTranslationsStore } from './translations.js'

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
		'full',
		() => ({
			data: {
				translations: [
					{ property: 'title', language: 'nl', status: 'draft' },
				],
				completeness: { nl: 50 },
			},
		}),
	],
	['empty', () => ({ data: {} })],
	[
		'rejected',
		() =>
			Promise.reject(
				Object.assign(new Error('Server down'), {
					response: { data: { error: 'srv' } },
				}),
			),
	],
	['rejected without message', () => Promise.reject({})],
]

const CACHE = {
	o1: {
		translations: [
			{ property: 'title', language: 'nl', status: 'draft' },
			{ property: 'title', language: 'en', status: 'draft' },
			{ property: 'body', language: 'nl', status: 'draft' },
		],
		completeness: { nl: 10 },
	},
	o2: { completeness: {} },
}

const CALLS = [
	['fetchByObject', ['o1', 's1']],
	['fetchByObject', ['o/2']],
	['fetchByObject', ['']],
	['setStatus', ['o1', 'title', 'nl', 'human_reviewed']],
	['setStatus', ['o2', 'title', 'nl', 'human_reviewed']],
	['setStatus', ['missing', 'title', 'nl', 'approved']],
	['setStatus', ['o1', 'title', 'nl', 'bogus']],
]

describe('translation requests golden', () => {
	for (const [action, args] of CALLS) {
		for (const [answerName, answer] of ANSWERS) {
			it(`${action}(${JSON.stringify(args)}): ${answerName}`, async () => {
				setActivePinia(createPinia())
				jest.clearAllMocks()
				const requests = []
				for (const method of ['get', 'post']) {
					axios[method].mockImplementation(async (...request) => {
						requests.push([
							method,
							...JSON.parse(JSON.stringify(request)),
						])
						return answer()
					})
				}
				const logged = []
				jest.spyOn(console, 'error').mockImplementation((...parts) =>
					logged.push(
						parts.map((part) =>
							part instanceof Error
								? `Error(${part.message})`
								: JSON.parse(JSON.stringify(part ?? null)),
						),
					),
				)
				const store = useTranslationsStore()
				store.byObject = JSON.parse(JSON.stringify(CACHE))
				store.error = 'earlier'
				let during = null
				let outcome
				try {
					const promise = store[action](...args)
					during = { loading: store.loading, error: store.error }
					outcome = { returned: (await promise) ?? null }
				} catch (error) {
					outcome = { thrown: error.message ?? null }
				}
				console.error.mockRestore()
				expect({
					requests,
					during,
					outcome,
					state: JSON.parse(JSON.stringify(store.$state)),
					logged,
				}).toMatchSnapshot()
			})
		}
	}
})
