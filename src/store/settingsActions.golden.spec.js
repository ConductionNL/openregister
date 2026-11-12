/**
 * Golden record of the settings store's request actions: for every action and
 * every kind of answer, the requests sent, the flags set while it runs, the
 * messages shown and logged, what it returns or throws and the state it leaves.
 *
 * @spec exclude Golden record of API passthroughs and their client-side messages.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { createPinia, setActivePinia } from 'pinia'
import { useSettingsStore } from './settings.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: {
		get: jest.fn(),
		post: jest.fn(),
		put: jest.fn(),
		patch: jest.fn(),
		delete: jest.fn(),
	},
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

const METHODS = ['get', 'post', 'put', 'patch', 'delete']

const FULL = {
	success: true,
	message: 'Done',
	error: 'Server error',
	rbac: { enabled: true },
	multitenancy: { enabled: false, mode: 'strict' },
	flow: { retentionDays: 7 },
	data: { model: 'nested' },
	availableGroups: { admin: 'Admins' },
	availableUsers: { alice: 'Alice' },
	availableTenants: { t1: 'Tenant one' },
	interval: 60,
	last_run: '2026-01-01',
	deleted: 4,
	loaded_names: 3,
	execution_time: '2ms',
	old_cache: { distributed_name_cache_size: 1 },
	new_cache: { distributed_name_cache_size: 2 },
	invalidated: ['apps', 'categories'],
	total_vectors: 9,
}

const ANSWERS = [
	['full answer', { data: FULL }],
	[
		'shrunk cache',
		{ data: { ...FULL, new_cache: { distributed_name_cache_size: 0 } } },
	],
	[
		'same cache',
		{ data: { ...FULL, new_cache: undefined, old_cache: undefined } },
	],
	['bare success', { data: { success: true } }],
	['not successful', { data: { success: false, message: 'No', error: 'Bad' } }],
	['not successful, no reason', { data: { success: false } }],
	['empty object', { data: {} }],
	['no data', { data: null }],
	[
		'rejected with server error',
		{
			reject: {
				message: 'Network',
				response: {
					data: { error: 'Server says', message: 'Server message' },
				},
			},
		},
	],
	['rejected', { reject: { message: 'Network' } }],
]

const ACTIONS = [
	['loadSettings', [[]]],
	['loadMassValidateMemoryPrediction', [[], [25]]],
	['loadRbacSettings', [[]]],
	['updateRbacSettings', [[{ enabled: false }]]],
	['loadMultitenancySettings', [[]]],
	['updateMultitenancySettings', [[{ enabled: false }]]],
	['loadRetentionSettings', [[]]],
	['loadFlowSettings', [[]]],
	['updateFlowSettings', [[{ retentionDays: 3 }]]],
	['updateRetentionSettings', [[{ objectArchiveRetention: 5 }]]],
	['getLlmSettings', [[]]],
	['saveLlmSettings', [[{ model: 'x' }]]],
	[
		'patchLlmSettings',
		[[{ enabled: true }], [{ enabled: true, model: 'x' }], [{ model: 'x' }]],
	],
	['getVectorStats', [[]]],
	['testLlmConnection', [[{ provider: 'p' }]]],
	['getLlmUsageStats', [[]]],
	['getFileSettings', [[]]],
	['saveFileSettings', [[{ maxSize: 1 }]]],
	['getExtractionStats', [[]]],
	['discoverFiles', [[]]],
	['triggerFileExtraction', [[], ['failed']]],
	['testDolphinConnection', [[{ url: 'd' }]]],
	['testPresidioConnection', [[{ url: 'p' }]]],
	['testOpenAnonymiserConnection', [[{ url: 'o' }]]],
	['getAnonymisationBackendState', [[]]],
	['testAnonymisationBackend', [['regex']]],
	['loadVersionInfo', [[]]],
	['loadAvailableOptions', [[]]],
	['loadStats', [[]]],
	['loadCacheStats', [[]]],
	['getChatStats', [[]]],
	['clearSpecificCache', [['names']]],
	['clearAllCaches', [[]]],
	['warmupNamesCache', [[]]],
	['loadWarmupInterval', [[]]],
	['saveWarmupInterval', [[120]]],
	['rebase', [[]]],
	[
		'saveSettings',
		[
			[{ rbac: { a: 1 } }],
			[{ multitenancy: { b: 2 } }],
			[{ retention: { c: 3 } }],
			[{ other: 4 }],
		],
	],
	['confirmRebase', [[]]],
	['clearCache', [[], ['names']]],
	['clearAllAuditTrails', [[]]],
	['clearAllSearchTrails', [[]]],
	['clearAllBlobObjects', [[]]],
	['performClearCache', [[]]],
	['clearAppStoreCache', [[], ['apps']]],
]

/**
 * The state keys whose value differs from the snapshot taken before.
 *
 * @param {object} before The state before.
 * @param {object} after The state after.
 * @return {object} The changed keys with their new value.
 */
function changed(before, after) {
	const out = {}
	for (const key of Object.keys(after)) {
		const value = JSON.stringify(after[key])
		if (value !== JSON.stringify(before[key])) {
			out[key] = JSON.parse(
				value.replace(/"lastUpdated":"[^"]*"/g, '"lastUpdated":"<now>"'),
			)
		}
	}
	return out
}

/**
 * Give the store open dialogs and loaded options, so a case shows whether an
 * action closes or replaces them.
 *
 * @param {object} store The settings store.
 * @return {void}
 */
function seed(store) {
	for (const key of Object.keys(store.$state)) {
		if (/^show.*Confirmation$/.test(key)) {
			store[key] = true
		}
	}
	store.groupOptions = [{ id: 'old-group', label: 'Old group' }]
	store.userOptions = [{ id: 'old-user', label: 'Old user' }]
	store.tenantOptions = [{ id: 'old-tenant', label: 'Old tenant' }]
}

describe('settings store actions golden', () => {
	for (const [action, argSets] of ACTIONS) {
		for (const args of argSets) {
			for (const [answerName, answer] of ANSWERS) {
				it(`${action}(${JSON.stringify(args)}): ${answerName}`, async () => {
					setActivePinia(createPinia())
					const store = useSettingsStore()
					jest.clearAllMocks()
					const logged = []
					jest.spyOn(console, 'error').mockImplementation((...parts) =>
						logged.push(parts),
					)
					const requests = []
					for (const method of METHODS) {
						axios[method].mockImplementation(async (...request) => {
							requests.push([method, ...request])
							if (answer.reject) {
								throw Object.assign(
									new Error(answer.reject.message),
									answer.reject,
								)
							}
							return JSON.parse(JSON.stringify(answer))
						})
					}
					seed(store)
					const before = JSON.parse(JSON.stringify(store.$state))
					const promise = store[action](...args)
					const whileRunning = changed(
						before,
						JSON.parse(JSON.stringify(store.$state)),
					)
					let outcome
					try {
						outcome = { returned: await promise }
					} catch (error) {
						outcome = { threw: error.message }
					}
					console.error.mockRestore()
					expect({
						requests,
						whileRunning,
						outcome,
						state: changed(
							before,
							JSON.parse(JSON.stringify(store.$state)),
						),
						shownErrors: showError.mock.calls,
						shownSuccesses: showSuccess.mock.calls,
						logged: logged.map(([text, error]) => [
							text,
							error?.message,
						]),
					}).toMatchSnapshot()
				})
			}
		}
	}
})
