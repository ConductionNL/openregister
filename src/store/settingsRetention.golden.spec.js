/**
 * Golden record of the settings store's retention status getters and its
 * mass validate action: the request, the messages shown, what it returns or
 * throws and the results it leaves.
 *
 * @spec exclude Golden record of client-side wording and an API passthrough.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { createPinia, setActivePinia } from 'pinia'
import { useSettingsStore } from './settings.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn(), put: jest.fn() },
}))
jest.mock('@nextcloud/dialogs', () => ({
	__esModule: true,
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: jest.fn(
		(app, text, vars) => `[${text}]${vars ? JSON.stringify(vars) : ''}`,
	),
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `url:${path}`),
}))

const KEYS = [
	'auditTrailsWithoutExpiry',
	'searchTrailsWithoutExpiry',
	'expiredAuditTrails',
	'expiredSearchTrails',
	'expiredObjects',
]

const WARNINGS = [
	['none', {}],
	['all zero', Object.fromEntries(KEYS.map((key) => [key, 0]))],
	...KEYS.map((key, index) => [`only ${key}`, { [key]: index + 1 }]),
	['all', Object.fromEntries(KEYS.map((key, index) => [key, index + 2]))],
	['negative and text', { expiredObjects: -1, expiredAuditTrails: '4' }],
	['other keys only', { somethingElse: 9 }],
	[
		'zero next to an issue',
		{
			searchTrailsWithoutExpiry: 0,
			auditTrailsWithoutExpiry: 0,
			expiredObjects: 1,
		},
	],
	[
		'zeros around an issue',
		{
			expiredAuditTrails: 0,
			expiredSearchTrails: 0,
			expiredObjects: 0,
			searchTrailsWithoutExpiry: 2,
		},
	],
]

const OPTIONS = [
	['no options', []],
	['empty options', [{}]],
	[
		'all options',
		[{ batchSize: 50, maxObjects: 10, mode: 'parallel', collectErrors: true }],
	],
	['falsy options', [{ batchSize: 0, maxObjects: 0, mode: '', collectErrors: 0 }]],
]

const ANSWERS = [
	['success', { data: { success: true, stats: { total_objects: 3 } } }],
	['not successful', { data: { success: false, message: 'Too many' } }],
	[
		'rejected with server message',
		{
			reject: {
				message: 'Network',
				response: { data: { message: 'Server says' } },
			},
		},
	],
	['rejected without server message', { reject: { message: 'Network' } }],
]

describe('settings retention and mass validate golden', () => {
	it('words the retention status', () => {
		setActivePinia(createPinia())
		const store = useSettingsStore()
		expect(
			WARNINGS.map(([name, warnings]) => {
				store.stats = { ...store.stats, warnings }
				return [
					name,
					store.retentionStatusClass,
					store.retentionStatusTextClass,
					store.retentionStatusMessage,
				]
			}),
		).toMatchSnapshot()
	})

	for (const [optionsName, args] of OPTIONS) {
		for (const [answerName, answer] of ANSWERS) {
			it(`mass validates: ${optionsName}, ${answerName}`, async () => {
				setActivePinia(createPinia())
				const store = useSettingsStore()
				jest.clearAllMocks()
				store.massValidateResults = { previous: true }
				const logged = []
				jest.spyOn(console, 'error').mockImplementation((...parts) =>
					logged.push(parts),
				)
				axios.post.mockImplementation(async () => {
					if (answer.reject) {
						throw Object.assign(
							new Error(answer.reject.message),
							answer.reject,
						)
					}
					return answer
				})
				const promise = store.massValidate(...args)
				const whileRunning = [
					store.massValidating,
					store.massValidateResults,
				]
				let outcome
				try {
					outcome = { returned: await promise }
				} catch (error) {
					outcome = { threw: error.message }
				}
				console.error.mockRestore()
				expect({
					posted: axios.post.mock.calls,
					whileRunning,
					outcome,
					results: store.massValidateResults,
					massValidating: store.massValidating,
					shownErrors: showError.mock.calls,
					shownSuccesses: showSuccess.mock.calls,
					logged: logged.map(([text, error]) => [text, error?.message]),
				}).toMatchSnapshot()
			})
		}
	}
})
