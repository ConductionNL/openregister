/**
 * Golden record of the file configuration section's backend actions: what it
 * asks the settings store, and the state and messages that follow, for each
 * shape of answer.
 *
 * @spec exclude Golden record of client-side settings actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import FileConfiguration from './FileConfiguration.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: {
		get: jest.fn(),
		put: jest.fn(),
		post: jest.fn(),
		patch: jest.fn(),
		delete: jest.fn(),
	},
}))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/Check.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsVertical.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocument.vue', () => ({}))
jest.mock('vue-material-design-icons/Information.vue', () => ({}))
jest.mock('vue-material-design-icons/Key.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('@nextcloud/dialogs', () => ({
	__esModule: true,
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('../../../components/shared/SettingsCard.vue', () => ({}))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (app, text, vars) =>
		vars ? `${text} ${JSON.stringify(vars)}` : text,
	t: (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text),
	n: (app, one, many) => many,
}))

const METHODS = [
	{ id: 'regex', label: 'Regex' },
	{ id: 'presidio', label: 'Presidio' },
	{ id: 'openanonymiser', label: 'OpenAnonymiser' },
]

const OPEN_ANONYMISER_DOWN = {
	activeMethod: 'presidio',
	backends: { openanonymiser: { available: false } },
}

/**
 * A section context whose settings store answers from a queue and logs
 * every call.
 *
 * @param {object} state The state to start from
 * @param {Array} replies The store's answers, in order; an Error rejects
 * @return {object} The context
 */
function section(state, replies) {
	const log = []
	const answer =
		(name) =>
		(...args) => {
			log.push([name, ...args])
			const next = replies.shift()
			return next instanceof Error
				? Promise.reject(next)
				: Promise.resolve(next)
		}
	const ctx = {
		log,
		entityRecognitionMethods: METHODS,
		backendTestResults: { presidio: 'success' },
		backendState: 'old',
		openAnonymiserError: 'old',
		testingBackendMethod: 'old',
		extractionStats: 'old',
		openAnonymiserPassword: '',
		openAnonymiserPasswordChanged: false,
		fileTypes: [
			{ extension: 'pdf', enabled: true },
			{ extension: 'doc', enabled: false },
		],
		settingsStore: {
			getAnonymisationBackendState: answer('getAnonymisationBackendState'),
			testAnonymisationBackend: answer('testAnonymisationBackend'),
			saveFileSettings: answer('saveFileSettings'),
			getExtractionStats: answer('getExtractionStats'),
		},
		showSaveMessage: (text, type) => log.push(['message', text, type]),
		...JSON.parse(JSON.stringify(state)),
	}
	for (const [name, method] of Object.entries(FileConfiguration.methods)) {
		if (name !== 'showSaveMessage') {
			ctx[name] = method.bind(ctx)
		}
	}
	return ctx
}

const FULL_SETTINGS = {
	extractionScope: { id: 'files' },
	textExtractor: { id: 'dolphin' },
	extractionMode: { id: 'immediate' },
	includeInSearch: true,
	maxFileSize: 10,
	chunkSize: 500,
	chunkOverlap: 50,
	chunkingStrategy: 'fixed',
	batchSize: 5,
	dolphinApiEndpoint: 'http://d',
	dolphinApiKey: 'dk',
	entityRecognitionEnabled: true,
	entityRecognitionMethod: { id: 'presidio' },
	presidioApiEndpoint: 'http://p',
	openAnonymiserApiEndpoint: 'http://o',
	openAnonymiserSource: 'external',
	openAnonymiserUsername: 'u',
}

const SCENARIOS = [
	[
		'loadBackendState auto resolves to active',
		'loadBackendState',
		[],
		{ fileSettings: { entityRecognitionMethod: { id: 'auto' } } },
		[{ activeMethod: 'presidio' }],
	],
	[
		'loadBackendState auto listed still resolves',
		'loadBackendState',
		[],
		{
			entityRecognitionMethods: [{ id: 'auto' }, ...METHODS],
			fileSettings: { entityRecognitionMethod: { id: 'auto' } },
		},
		[{ activeMethod: 'regex' }],
	],
	[
		'loadBackendState auto without active',
		'loadBackendState',
		[],
		{ fileSettings: { entityRecognitionMethod: { id: 'auto' } } },
		[{}],
	],
	[
		'loadBackendState unknown resolves to first',
		'loadBackendState',
		[],
		{ fileSettings: { entityRecognitionMethod: { id: 'gone' } } },
		[{ activeMethod: 'nope' }],
	],
	[
		'loadBackendState missing method',
		'loadBackendState',
		[],
		{ fileSettings: {} },
		[{ activeMethod: 'openanonymiser' }],
	],
	[
		'loadBackendState known method kept',
		'loadBackendState',
		[],
		{ fileSettings: { entityRecognitionMethod: { id: 'regex' } } },
		[{ activeMethod: 'presidio' }],
	],
	[
		'loadBackendState no state',
		'loadBackendState',
		[],
		{ fileSettings: { entityRecognitionMethod: { id: 'auto' } } },
		[null],
	],
	[
		'loadBackendState probes unavailable exapp',
		'loadBackendState',
		[],
		{ fileSettings: { entityRecognitionMethod: { id: 'regex' } } },
		[OPEN_ANONYMISER_DOWN, { error: 'exapp_disabled' }],
	],
	[
		'loadBackendState probe without error',
		'loadBackendState',
		[],
		{ fileSettings: { entityRecognitionMethod: { id: 'regex' } } },
		[OPEN_ANONYMISER_DOWN, null],
	],
	[
		'loadBackendState exapp available',
		'loadBackendState',
		[],
		{ fileSettings: { entityRecognitionMethod: { id: 'regex' } } },
		[{ backends: { openanonymiser: { available: true } } }],
	],
	[
		'loadBackendState exapp unknown',
		'loadBackendState',
		[],
		{ fileSettings: { entityRecognitionMethod: { id: 'regex' } } },
		[{ backends: { openanonymiser: {} } }],
	],
	[
		'loadBackendState no exapp',
		'loadBackendState',
		[],
		{ fileSettings: { entityRecognitionMethod: { id: 'regex' } } },
		[{ backends: {} }],
	],
	[
		'loadBackendState fails',
		'loadBackendState',
		[],
		{ fileSettings: {} },
		[new Error('down')],
	],

	[
		'testBackend reachable exapp',
		'testBackend',
		['openanonymiser'],
		{},
		[{ reachable: true }, { fresh: 1 }],
	],
	[
		'testBackend unreachable exapp',
		'testBackend',
		['openanonymiser'],
		{},
		[{ reachable: false, error: 'exapp_disabled' }, null],
	],
	['testBackend no answer', 'testBackend', ['presidio'], {}, [null, { fresh: 2 }]],
	[
		'testBackend unreachable other',
		'testBackend',
		['presidio'],
		{},
		[{ error: 'timeout' }, { fresh: 3 }],
	],
	['testBackend fails', 'testBackend', ['regex'], {}, [new Error('boom')]],
	[
		'testBackend refresh fails',
		'testBackend',
		['regex'],
		{},
		[{ reachable: true }, new Error('boom')],
	],

	[
		'saveSettings full',
		'saveSettings',
		[],
		{ fileSettings: FULL_SETTINGS },
		[{ openAnonymiserPasswordSet: true }, { activeMethod: 'presidio' }],
	],
	[
		'saveSettings defaults',
		'saveSettings',
		[],
		{ fileSettings: { entityRecognitionMethod: { id: 'regex' } } },
		[null, null],
	],
	[
		'saveSettings new password',
		'saveSettings',
		[],
		{
			fileSettings: FULL_SETTINGS,
			openAnonymiserPassword: 'secret',
			openAnonymiserPasswordChanged: true,
		},
		[{ openAnonymiserPasswordSet: 'yes' }, null],
	],
	[
		'saveSettings cleared password',
		'saveSettings',
		[],
		{
			fileSettings: FULL_SETTINGS,
			openAnonymiserPassword: '',
			openAnonymiserPasswordChanged: true,
		},
		[{ openAnonymiserPasswordSet: false }, null],
	],
	[
		'saveSettings fails',
		'saveSettings',
		[],
		{
			fileSettings: FULL_SETTINGS,
			openAnonymiserPassword: 'secret',
			openAnonymiserPasswordChanged: true,
		},
		[new Error('denied')],
	],

	[
		'loadExtractionStats camel case',
		'loadExtractionStats',
		[],
		{},
		[
			{
				totalFiles: 1,
				untrackedFiles: 2,
				pendingFiles: 3,
				processedFiles: 4,
				failedFiles: 5,
				totalChunks: 6,
				totalObjects: 7,
				totalEntities: 8,
				total_objects: 70,
				total_entities: 80,
			},
		],
	],
	[
		'loadExtractionStats snake case',
		'loadExtractionStats',
		[],
		{},
		[{ total_objects: 70, total_entities: 80 }],
	],
	['loadExtractionStats empty', 'loadExtractionStats', [], {}, [{}]],
	['loadExtractionStats none', 'loadExtractionStats', [], {}, [null]],
	['loadExtractionStats fails', 'loadExtractionStats', [], {}, [new Error('x')]],
]

describe('file configuration actions golden', () => {
	beforeEach(() => {
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	for (const [name, method, args, state, replies] of SCENARIOS) {
		it(name, async () => {
			const ctx = section(state, [...replies])
			await ctx[method](...args)
			const result = Object.fromEntries(
				Object.entries(ctx).filter(
					([, value]) => typeof value !== 'function' && value !== METHODS,
				),
			)
			delete result.settingsStore
			expect(result).toMatchSnapshot()
		})
	}
})
