import axios from '@nextcloud/axios'
import LlmConfiguration from './LlmConfiguration.vue'

// The three loaders that fill the LLM settings section from the API.

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn() }))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/CubeOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsVertical.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocument.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocumentCheckOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('vue-material-design-icons/Robot.vue', () => ({}))
jest.mock('vue-material-design-icons/VectorSquare.vue', () => ({}))
jest.mock('pinia', () => ({ mapStores: () => ({}) }))
jest.mock('../../../store/settings.js', () => ({ useSettingsStore: () => ({}) }))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({}))
jest.mock('../../../modals/settings/FileManagementModal.vue', () => ({}))
jest.mock('../../../modals/settings/FileVectorizationModal.vue', () => ({}))
jest.mock('../../../modals/settings/LLMConfigModal.vue', () => ({}))
jest.mock('../../../modals/settings/ObjectManagementModal.vue', () => ({}))
jest.mock('../../../modals/settings/ObjectVectorizationModal.vue', () => ({}))

/**
 * A bare component context with the given settings store.
 *
 * @param {object} settingsStore The store double
 * @return {object} The context
 */
function context(settingsStore) {
	const ctx = { ...LlmConfiguration.data.call({}) }
	ctx.settingsStore = settingsStore
	ctx.t = (app, text) => text
	for (const [name, method] of Object.entries(LlmConfiguration.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

/**
 * The component's own state, as text so key order counts.
 *
 * @param {object} ctx The context
 * @return {string} The state
 */
function state(ctx) {
	const own = {}
	for (const [key, value] of Object.entries(ctx)) {
		if (typeof value !== 'function' && key !== 'settingsStore') {
			own[key] = value
		}
	}
	return JSON.stringify(own, null, 1)
}

const PROVIDERS = ['openai', 'fireworks', 'ollama', 'other', undefined]

const CONFIGS = {
	full: {
		openaiConfig: { model: 'oa-embed', chatModel: 'oa-chat' },
		fireworksConfig: {
			embeddingModel: 'fw-embed',
			chatModel: 'fw-chat',
			model: 'fw-wrong',
		},
		ollamaConfig: { model: 'ol-embed', chatModel: 'ol-chat' },
	},
	empty: { openaiConfig: {}, fireworksConfig: {}, ollamaConfig: {} },
	missing: {},
}

const SETTINGS_CASES = []
for (const embeddingProvider of PROVIDERS) {
	for (const chatProvider of PROVIDERS) {
		SETTINGS_CASES.push([
			`embed ${embeddingProvider} chat ${chatProvider}`,
			{ enabled: true, embeddingProvider, chatProvider, ...CONFIGS.full },
		])
	}
}
for (const [name, config] of Object.entries(CONFIGS)) {
	for (const provider of ['openai', 'fireworks', 'ollama']) {
		SETTINGS_CASES.push([
			`${name} config for ${provider}`,
			{
				embeddingProvider: provider,
				chatProvider: provider,
				model: 'top',
				...config,
			},
		])
	}
}
SETTINGS_CASES.push([
	'empty-string providers',
	{ embeddingProvider: '', chatProvider: '' },
])
SETTINGS_CASES.push(['no settings', null])

const DATABASES = {
	full: {
		type: 'PostgreSQL',
		version: '16',
		vectorSupport: true,
		recommendedPlugin: 'pgvector',
		performanceNote: 'fast',
		extensions: ['vector'],
		hybridSearch: { enabled: true },
		lastUpdated: '2026-01-01',
	},
	noVector: {
		type: 'MariaDB',
		vectorSupport: false,
		recommendedPlugin: 'none',
		performanceNote: 'slow',
	},
	bare: {},
	noPlugin: { type: 'SQLite', vectorSupport: 1, recommendedPlugin: '' },
}

const BACKENDS = {
	database: () =>
		Promise.resolve({ data: { vectorConfig: { backend: 'database' } } }),
	php: () => Promise.resolve({ data: { vectorConfig: { backend: 'php' } } }),
	unset: () => Promise.resolve({ data: {} }),
	failing: () => Promise.reject(new Error('llm down')),
}

const VECTOR_RESPONSES = {
	nested: {
		success: true,
		stats: {
			total_vectors: 10,
			by_type: { object: 6, file: 4 },
			storage: { total_mb: 12.345 },
			object_vectors: 99,
			file_vectors: 98,
		},
		files: {
			total_files: 5,
			files_extracted: 4,
			vectorized_files: 3,
			pending_files: 2,
			total_chunks: 50,
		},
		objects: {
			total_objects: 20,
			vectorized_objects: 15,
			pending_objects: 5,
			percentage_complete: 75,
		},
	},
	flat: {
		success: false,
		total_vectors: 3,
		object_vectors: 2,
		file_vectors: 1,
		storage: { total_mb: 0 },
		files: {},
		objects: {},
	},
	byTypeZero: {
		success: 1,
		stats: {
			by_type: { object: 0, file: 0 },
			object_vectors: 7,
			file_vectors: 8,
		},
	},
	empty: {},
	none: null,
	filesOnly: { success: true, files: { total_files: 2, total_chunks: 9 } },
	objectsOnly: {
		success: true,
		objects: { total_objects: 6, percentage_complete: 50 },
	},
	stringStorage: {
		success: true,
		stats: { total_vectors: 4, object_vectors: 1, storage: { total_mb: '9' } },
		files: { total_files: 1 },
	},
}

describe('LlmConfiguration.loadSettings', () => {
	beforeEach(() => jest.spyOn(console, 'error').mockImplementation(() => {}))
	afterEach(() => jest.restoreAllMocks())

	it.each(SETTINGS_CASES)('loads %s', async (name, settings) => {
		const ctx = context({ getLlmSettings: () => Promise.resolve(settings) })
		await ctx.loadSettings()
		expect(state(ctx)).toMatchSnapshot()
	})

	it('keeps the defaults when the store fails', async () => {
		const ctx = context({
			getLlmSettings: () => Promise.reject(new Error('down')),
		})
		await ctx.loadSettings()
		expect(state(ctx)).toMatchSnapshot()
	})
})

describe('LlmConfiguration.loadDatabaseInfo', () => {
	beforeEach(() => jest.spyOn(console, 'error').mockImplementation(() => {}))
	afterEach(() => jest.restoreAllMocks())

	for (const [dbName, db] of Object.entries(DATABASES)) {
		for (const [backendName, backend] of Object.entries(BACKENDS)) {
			it(`reads ${dbName} with backend ${backendName}`, async () => {
				const urls = []
				axios.get.mockImplementation((url) => {
					urls.push(url)
					return url.endsWith('/llm')
						? backend()
						: Promise.resolve({ data: { success: true, database: db } })
				})
				const ctx = context({})
				await ctx.loadDatabaseInfo()
				expect({ urls, state: state(ctx) }).toMatchSnapshot()
			})
		}
	}

	it.each([
		[
			'an unsuccessful answer',
			() =>
				Promise.resolve({
					data: { success: false, database: DATABASES.full },
				}),
		],
		['no data', () => Promise.resolve({})],
		[
			'a successful answer without a database',
			() => Promise.resolve({ data: { success: true } }),
		],
		['a failing request', () => Promise.reject(new Error('down'))],
	])('keeps the defaults on %s', async (name, answer) => {
		axios.get.mockReset()
		axios.get.mockImplementation(answer)
		const ctx = context({})
		await ctx.loadDatabaseInfo()
		expect({ calls: axios.get.mock.calls, state: state(ctx) }).toMatchSnapshot()
	})
})

describe('LlmConfiguration.loadVectorStats', () => {
	beforeEach(() => jest.spyOn(console, 'error').mockImplementation(() => {}))
	afterEach(() => jest.restoreAllMocks())

	it('does nothing while the LLM is disabled', async () => {
		const getVectorStats = jest.fn()
		const ctx = context({ getVectorStats })
		await ctx.loadVectorStats()
		expect(getVectorStats).not.toHaveBeenCalled()
		expect(state(ctx)).toMatchSnapshot()
	})

	it.each(Object.keys(VECTOR_RESPONSES))('reads %s', async (name) => {
		let during
		const ctx = context({
			getVectorStats: () => {
				during = state(ctx)
				return Promise.resolve(VECTOR_RESPONSES[name])
			},
		})
		ctx.llmSettings.enabled = true
		ctx.llmError = true
		ctx.llmErrorMessage = 'old'
		await ctx.loadVectorStats()
		expect({ during, after: state(ctx) }).toMatchSnapshot()
	})

	it.each([
		['with a message', new Error('timeout')],
		['without a message', {}],
	])('marks the connection down on a failure %s', async (name, error) => {
		const ctx = context({ getVectorStats: () => Promise.reject(error) })
		ctx.llmSettings.enabled = true
		await ctx.loadVectorStats()
		expect(state(ctx)).toMatchSnapshot()
	})
})

describe('LlmConfiguration.loadChatStats', () => {
	beforeEach(() => jest.spyOn(console, 'error').mockImplementation(() => {}))
	afterEach(() => jest.restoreAllMocks())

	it.each([
		['full', { total_agents: 3, total_conversations: 5, total_messages: 8 }],
		['partial', { total_agents: 2 }],
		['empty', {}],
		['nothing', null],
	])('reads %s stats', async (name, response) => {
		const ctx = context({ getChatStats: () => Promise.resolve(response) })
		ctx.chatStats.totalMessages = 9
		await ctx.loadChatStats()
		expect(state(ctx)).toMatchSnapshot()
	})

	it('keeps the stats when the store fails', async () => {
		const ctx = context({
			getChatStats: () => Promise.reject(new Error('down')),
		})
		ctx.chatStats.totalAgents = 4
		await ctx.loadChatStats()
		expect(state(ctx)).toMatchSnapshot()
	})
})

describe('LlmConfiguration.refreshDatabaseInfo', () => {
	beforeEach(() => jest.spyOn(console, 'error').mockImplementation(() => {}))
	afterEach(() => jest.restoreAllMocks())

	it.each([
		['refreshes', () => Promise.resolve({ data: {} }), () => Promise.resolve()],
		[
			'fails to refresh',
			() => Promise.reject(new Error('down')),
			() => Promise.resolve(),
		],
		[
			'fails to reload',
			() => Promise.resolve({ data: {} }),
			() => Promise.reject(new Error('reload')),
		],
	])('%s', async (name, post, reload) => {
		const { showError, showSuccess } = jest.requireMock('@nextcloud/dialogs')
		showError.mockClear()
		showSuccess.mockClear()
		axios.post.mockReset()
		axios.post.mockImplementation(post)
		const ctx = context({})
		let during
		ctx.loadDatabaseInfo = () => {
			during = ctx.refreshingDatabase
			return reload()
		}
		await ctx.refreshDatabaseInfo()
		expect({
			posted: axios.post.mock.calls,
			during,
			success: showSuccess.mock.calls,
			error: showError.mock.calls,
			state: state(ctx),
		}).toMatchSnapshot()
	})
})
