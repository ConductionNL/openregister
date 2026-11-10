/**
 * Golden record of every quality store action: the requests it makes, what
 * it returns or throws, the store state it leaves and what it logs, for a
 * full answer, an empty answer and three kinds of failure.
 */
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { useQualityStore } from './quality.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: {
		get: jest.fn(),
		post: jest.fn(),
		patch: jest.fn(),
	},
}))

jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
}))

const SAMPLE = {
	items: [{ id: 1 }],
	results: [{ id: 'w1' }],
	sources: [{ id: 3 }],
	total: 9,
	limit: 7,
	offset: 2,
	delivered: 4,
}

const ACTIONS = [
	['fetchRegisters', []],
	['fetchSchemasForRegister', ['5']],
	['fetchSchemasForRegister', ['']],
	['fetchQualityStats', ['r', 's']],
	['fetchQualityStats', ['', 's']],
	[
		'fetchLowQualityObjects',
		['r 1', 's/2', { limit: 3, offset: 4, qualityStatus: 'low' }],
	],
	['fetchLowQualityObjects', ['r', 's']],
	['fetchLowQualityObjects', ['r', '']],
	['fetchDuplicates', ['r 1', 's/2', { limit: 3, offset: 0, threshold: 0.8 }]],
	['fetchDuplicates', ['r', 's']],
	['fetchDuplicates', ['', 's']],
	['fetchMasterEntities', ['r', 's', { limit: 2 }]],
	['fetchMasterEntities', ['r', '']],
	['fetchGoldenRecord', ['r', 's', 'i']],
	['fetchGoldenRecord', ['r', 's', '']],
	['fetchMasterSources', ['i']],
	['fetchMasterSources', ['']],
	['previewMerge', ['a', 'b']],
	['executeMerge', ['a', 'b', 'why']],
	['fetchMergeOperations', [{ limit: 2 }]],
	['fetchMergeOperations', []],
	['reverseMerge', ['i']],
	['setAttributeOverride', ['i', 'attr', 'v', 'why']],
	['clearAttributeOverride', ['i', 'attr']],
	[
		'persistTrustRule',
		[
			{
				entityType: 'e',
				attribute: 'a',
				sourceSystem: 's',
				trustTier: 't',
				rationale: 'r',
			},
		],
	],
	['touchObject', ['r', 's', 'i']],
	['fetchWebhookHealth', []],
]

const MODES = {
	full: () => Promise.resolve({ data: JSON.parse(JSON.stringify(SAMPLE)) }),
	empty: () => Promise.resolve({ data: undefined }),
	apiError: () =>
		Promise.reject(
			Object.assign(new Error('transport'), {
				response: { data: { error: 'api says no' } },
			}),
		),
	messageError: () => Promise.reject(new Error('boom')),
	bareError: () => Promise.reject({}),
}

/**
 * Run one action in one mode and describe everything it observably did.
 *
 * @param {string} action The action name.
 * @param {Array} args Its arguments.
 * @param {string} mode The response mode.
 * @return {Promise<object>} The record.
 */
async function runCase(action, args, mode) {
	setActivePinia(createPinia())
	const store = useQualityStore()
	;['get', 'post', 'patch'].forEach((method) => {
		axios[method].mockReset()
		axios[method].mockImplementation(MODES[mode])
	})
	console.error.mockClear()
	const record = {}
	try {
		record.result = await store[action](...JSON.parse(JSON.stringify(args)))
	} catch (error) {
		record.thrown =
			error instanceof Error ? error.message : JSON.stringify(error)
	}
	record.calls = Object.fromEntries(
		['get', 'post', 'patch'].map((method) => [method, axios[method].mock.calls]),
	)
	record.state = JSON.parse(JSON.stringify(store.$state))
	record.consoleError = console.error.mock.calls.map(([msg, detail]) => [
		msg,
		typeof detail,
	])
	return record
}

describe('quality store actions (golden)', () => {
	beforeEach(() => {
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	Object.keys(MODES).forEach((mode) => {
		it(`matches the recorded behaviour on a ${mode} answer`, async () => {
			const records = {}
			for (const [action, args] of ACTIONS) {
				records[`${action}(${JSON.stringify(args)})`] = await runCase(
					action,
					args,
					mode,
				)
			}
			expect(records).toMatchSnapshot()
		})
	})
})
