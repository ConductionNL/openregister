/**
 * Golden record of the LLM configuration modal's connection tests and save:
 * the request each sends for every provider, and the state, toasts and
 * events that follow a success or a failure.
 *
 * @spec exclude Golden record of client-side settings actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import LLMConfigModal from './LLMConfigModal.vue'

const mockLog = []

jest.mock('@nextcloud/axios', () => {
	const call = (verb) =>
		jest.fn((...args) => {
			mockLog.push([verb, ...args])
			return globalThis.nextAnswer()
		})
	return {
		__esModule: true,
		default: {
			get: call('get'),
			put: call('put'),
			post: call('post'),
			patch: call('patch'),
		},
	}
})
jest.mock('@nextcloud/dialogs', () => ({
	__esModule: true,
	showError: (text) => mockLog.push(['error', text]),
	showSuccess: (text) => mockLog.push(['success', text]),
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: (path) => `url:${path}`,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSave.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/TestTube.vue', () => ({}))

const answers = []
globalThis.nextAnswer = () => {
	const next = answers.shift()
	return next instanceof Error ? Promise.reject(next) : Promise.resolve(next)
}

/**
 * A server refusal the way axios reports it.
 *
 * @param {object|null} data The response body, or null for a network error
 * @return {Error} The error
 */
function refusal(data) {
	const error = new Error('network down')
	if (data) {
		error.response = { data }
	}
	return error
}

const OBJECT_MODELS = {
	openaiConfig: {
		apiKey: 'ok',
		model: { id: 'oe' },
		chatModel: { id: 'oc' },
		organizationId: 'org',
	},
	ollamaConfig: {
		url: 'http://ollama',
		model: { id: 'le' },
		chatModel: { id: 'lc' },
	},
	fireworksConfig: {
		apiKey: 'fk',
		embeddingModel: { id: 'fe' },
		chatModel: { id: 'fc' },
		baseUrl: 'http://fw',
	},
}

const STRING_MODELS = {
	openaiConfig: { apiKey: 'ok', model: 'oe', chatModel: 'oc' },
	ollamaConfig: { url: 'http://ollama', model: 'le', chatModel: 'lc' },
	fireworksConfig: {
		apiKey: 'fk',
		embeddingModel: 'fe',
		chatModel: 'fc',
		baseUrl: 'http://fw',
	},
}

/**
 * A modal context with the modal's methods bound to it.
 *
 * @param {object} state The state to start from
 * @return {object} The context
 */
function modal(state) {
	const ctx = {
		t: (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text),
		$emit: (name) => mockLog.push(['emit', name]),
		aiFeatures: [
			{ id: 'a', enabled: true },
			{ id: 'b', enabled: false },
		],
		...JSON.parse(JSON.stringify(state)),
	}
	for (const [name, method] of Object.entries(LLMConfigModal.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

const STATE_KEYS = [
	'loadingBackends',
	'vectorBackendOptions',
	'selectedVectorBackend',
	'testingEmbedding',
	'embeddingTestResult',
	'testingChat',
	'chatTestResult',
	'saving',
]

/**
 * The state the actions write.
 *
 * @param {object} ctx The context
 * @return {object} The state
 */
function written(ctx) {
	return Object.fromEntries(
		STATE_KEYS.filter((key) => key in ctx).map((key) => [key, ctx[key]]),
	)
}

const PROVIDERS = ['openai', 'fireworks', 'ollama', 'other', undefined]
const SCENARIOS = []
for (const models of [
	['object', OBJECT_MODELS],
	['string', STRING_MODELS],
]) {
	for (const provider of PROVIDERS) {
		const selected = provider ? { id: provider } : null
		SCENARIOS.push([
			`testEmbeddingConnection ${models[0]} ${provider}`,
			'testEmbeddingConnection',
			{ ...models[1], selectedEmbeddingProvider: selected },
			[{}],
		])
		SCENARIOS.push([
			`testChatConnection ${models[0]} ${provider}`,
			'testChatConnection',
			{ ...models[1], selectedChatProvider: selected },
			[{}],
		])
	}
	SCENARIOS.push([
		`saveConfiguration ${models[0]}`,
		'saveConfiguration',
		{
			...models[1],
			selectedEmbeddingProvider: { id: 'openai' },
			selectedChatProvider: { id: 'ollama' },
			selectedVectorBackend: { id: 'database' },
		},
		[{}],
	])
}
SCENARIOS.push(
	[
		'testEmbeddingConnection server error',
		'testEmbeddingConnection',
		{ ...STRING_MODELS, selectedEmbeddingProvider: { id: 'openai' } },
		[refusal({ error: 'bad key' })],
	],
	[
		'testEmbeddingConnection network error',
		'testEmbeddingConnection',
		{ ...STRING_MODELS, selectedEmbeddingProvider: { id: 'ollama' } },
		[refusal(null)],
	],
	[
		'testEmbeddingConnection missing config',
		'testEmbeddingConnection',
		{ selectedEmbeddingProvider: { id: 'openai' } },
		[],
	],
	[
		'testChatConnection server error',
		'testChatConnection',
		{ ...STRING_MODELS, selectedChatProvider: { id: 'fireworks' } },
		[refusal({ error: 'no model' })],
	],
	[
		'testChatConnection network error',
		'testChatConnection',
		{ ...STRING_MODELS, selectedChatProvider: { id: 'openai' } },
		[refusal(null)],
	],
	[
		'saveConfiguration no selections',
		'saveConfiguration',
		{ ...STRING_MODELS },
		[{}],
	],
	[
		'saveConfiguration server error',
		'saveConfiguration',
		{ ...STRING_MODELS },
		[refusal({ error: 'denied' })],
	],
	[
		'saveConfiguration network error',
		'saveConfiguration',
		{ ...STRING_MODELS },
		[refusal(null)],
	],
)

const DATABASES = {
	pgvector: {
		data: {
			success: true,
			database: {
				type: 'PostgreSQL',
				vectorSupport: true,
				performanceNote: 'n',
			},
		},
	},
	plain: {
		data: { success: true, database: { type: 'MySQL', vectorSupport: false } },
	},
	unknown: { data: { success: false } },
	refused: {
		data: {
			success: false,
			database: { type: 'PostgreSQL', vectorSupport: true },
		},
	},
	missing: { data: { success: true } },
}
for (const [db, answer] of Object.entries(DATABASES)) {
	for (const [stored, llm] of Object.entries({
		database: { data: { vectorConfig: { backend: 'database' } } },
		none: { data: {} },
		other: { data: { vectorConfig: { backend: 'solr' } } },
	})) {
		SCENARIOS.push([
			`loadAvailableBackends ${db} ${stored}`,
			'loadAvailableBackends',
			{},
			[answer, llm],
		])
	}
}
SCENARIOS.push(
	[
		'loadAvailableBackends database fails',
		'loadAvailableBackends',
		{},
		[refusal(null)],
	],
	[
		'loadAvailableBackends settings fail',
		'loadAvailableBackends',
		{},
		[DATABASES.pgvector, refusal({ error: 'x' })],
	],
)

describe('LLM configuration actions golden', () => {
	beforeEach(() => {
		mockLog.length = 0
		answers.length = 0
	})

	for (const [name, method, state, replies] of SCENARIOS) {
		it(name, async () => {
			answers.push(...replies)
			const ctx = modal(state)
			await ctx[method]()
			expect({ log: mockLog, state: written(ctx) }).toMatchSnapshot()
		})
	}
})
