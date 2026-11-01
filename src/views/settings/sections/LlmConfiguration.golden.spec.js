/**
 * Golden record of LlmConfiguration's loaders: the component state that
 * loadSettings, loadVectorStats and loadDatabaseInfo leave behind for a range
 * of store and API answers, plus the requests and console errors they make.
 */
import axios from '@nextcloud/axios'
import LlmConfiguration from './LlmConfiguration.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../../store/settings.js', () => ({ useSettingsStore: () => ({}) }))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({}))
jest.mock('../../../modals/settings/FileManagementModal.vue', () => ({}))
jest.mock('../../../modals/settings/FileVectorizationModal.vue', () => ({}))
jest.mock('../../../modals/settings/LLMConfigModal.vue', () => ({}))
jest.mock('../../../modals/settings/ObjectManagementModal.vue', () => ({}))
jest.mock('../../../modals/settings/ObjectVectorizationModal.vue', () => ({}))
jest.mock('vue-material-design-icons/CubeOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsVertical.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocument.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocumentCheckOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('vue-material-design-icons/Robot.vue', () => ({}))
jest.mock('vue-material-design-icons/VectorSquare.vue', () => ({}))

const { methods } = LlmConfiguration

/**
 * A fresh component context with the store answering `store`.
 *
 * @param {object} store The settings store double.
 * @param {object} overrides State to set before the call.
 * @return {object} The context.
 */
function context(store, overrides = {}) {
	return {
		...LlmConfiguration.data(),
		...methods,
		settingsStore: store,
		t: (app, text) => text,
		...overrides,
	}
}

/**
 * A logged value as JSON-safe text. A TypeError from reading a missing answer
 * is recorded by its kind only, since its message names whichever field was
 * read first.
 *
 * @param {*} arg The logged value.
 * @return {*} The value, or its description.
 */
function describeLogged(arg) {
	if (arg instanceof TypeError) {
		return 'TypeError'
	}
	return arg instanceof Error ? `Error:${arg.message}` : arg
}

/**
 * Run one method on a context and describe what it left behind.
 *
 * @param {string} name The method.
 * @param {object} ctx The context.
 * @return {Promise<string>} The trace as JSON text.
 */
async function trace(name, ctx) {
	const errors = jest.spyOn(console, 'error').mockImplementation(() => {})
	let thrown = null
	try {
		await methods[name].call(ctx)
	} catch (error) {
		thrown = String(error)
	}
	const logged = errors.mock.calls.map((args) => args.map(describeLogged))
	errors.mockRestore()
	const state = Object.fromEntries(
		Object.entries(ctx).filter(([, value]) => typeof value !== 'function'),
	)
	delete state.settingsStore
	return JSON.stringify({
		state,
		thrown,
		logged,
		gets: axios.get.mock.calls.map((args) => args[0]),
	})
}

const SETTINGS = [
	null,
	{},
	{ enabled: true, model: 'm' },
	{
		embeddingProvider: 'openai',
		chatProvider: 'openai',
		openaiConfig: { model: 'e1', chatModel: 'c1' },
	},
	{ embeddingProvider: 'openai', chatProvider: 'openai' },
	{
		embeddingProvider: 'fireworks',
		chatProvider: 'fireworks',
		fireworksConfig: { model: 'no', embeddingModel: 'e2', chatModel: 'c2' },
	},
	{
		embeddingProvider: 'fireworks',
		chatProvider: 'fireworks',
		fireworksConfig: {},
	},
	{
		embeddingProvider: 'ollama',
		chatProvider: 'ollama',
		ollamaConfig: { model: 'e3', chatModel: 'c3' },
	},
	{
		embeddingProvider: 'ollama',
		chatProvider: 'fireworks',
		ollamaConfig: { model: 'e3', chatModel: 'c3' },
		fireworksConfig: { chatModel: 'c2' },
	},
	{
		embeddingProvider: 'openai',
		chatProvider: 'ollama',
		openaiConfig: { model: '', chatModel: 'x' },
		ollamaConfig: { chatModel: 'c3' },
	},
	{
		embeddingProvider: 'other',
		chatProvider: 'constructor',
		openaiConfig: { model: 'x' },
	},
	{ embeddingProvider: 'toString', chatProvider: '' },
]

const VECTOR_ANSWERS = [
	null,
	{},
	{ success: true },
	{
		success: true,
		stats: {
			total_vectors: 9,
			by_type: { object: 4, file: 5 },
			object_vectors: 1,
			file_vectors: 2,
			storage: { total_mb: 1.26 },
		},
	},
	{
		success: false,
		stats: {
			by_type: {},
			object_vectors: 1,
			file_vectors: 2,
			storage: { total_mb: 0 },
		},
	},
	{ success: 1, total_vectors: 3, object_vectors: 7, storage: {} },
	{
		success: true,
		stats: { total_vectors: 0 },
		files: {
			total_files: 10,
			files_extracted: 8,
			vectorized_files: 6,
			pending_files: 2,
			total_chunks: 99,
		},
		objects: {
			total_objects: 20,
			vectorized_objects: 15,
			pending_objects: 5,
			percentage_complete: 75,
		},
	},
	{ success: true, files: {}, objects: {} },
	{ success: true, files: { total_files: 4 } },
	{ success: true, objects: { total_objects: 6 } },
	{ success: true, files: 0, objects: '' },
]

const DATABASE = {
	type: 'PostgreSQL',
	version: '16',
	vectorSupport: true,
	recommendedPlugin: 'pgvector',
	performanceNote: 'fast',
	extensions: [{ name: 'vector', version: '0.7' }],
	hybridSearch: { hnswIndex: true },
	lastUpdated: '2026-01-01',
}

const DATABASE_ANSWERS = [
	{ data: null },
	{ data: { success: false, database: DATABASE } },
	{ data: { success: true, database: DATABASE } },
	{ data: { success: true, database: { ...DATABASE, vectorSupport: false } } },
	{ data: { success: true, database: {} } },
	{
		data: {
			success: true,
			database: { vectorSupport: 1, recommendedPlugin: '' },
		},
	},
	{
		data: {
			success: true,
			database: { type: 'MySQL', recommendedPlugin: null },
		},
	},
	{ data: { success: true } },
	{ data: { success: true, database: null } },
]

const LLM_ANSWERS = [
	{ data: { vectorConfig: { backend: 'database' } } },
	{ data: { vectorConfig: { backend: 'php' } } },
	{ data: { vectorConfig: {} } },
	{ data: {} },
	{ data: null },
	new Error('llm down'),
]

/**
 * Answer the two GETs of loadDatabaseInfo.
 *
 * @param {object|Error} database The database endpoint's answer.
 * @param {object|Error} llm The LLM settings endpoint's answer.
 */
function answerGets(database, llm) {
	axios.get.mockReset()
	axios.get.mockImplementation((url) => {
		const answer = url.endsWith('/llm') ? llm : database
		return answer instanceof Error
			? Promise.reject(answer)
			: Promise.resolve(answer)
	})
}

describe('LlmConfiguration loaders (golden)', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('loadSettings matches the recorded state', async () => {
		const traces = []
		for (const settings of SETTINGS) {
			const ctx = context({ getLlmSettings: async () => settings })
			ctx.providerConfig = {
				embeddingProvider: 'p0',
				embeddingModel: 'm0',
				chatProvider: 'p1',
				chatModel: 'm1',
			}
			traces.push(await trace('loadSettings', ctx))
		}
		traces.push(
			await trace(
				'loadSettings',
				context({
					getLlmSettings: async () => {
						throw new Error('store down')
					},
				}),
			),
		)
		expect(traces).toMatchSnapshot()
	})

	it('loadVectorStats matches the recorded state', async () => {
		const traces = []
		const store = (answer) => ({ getVectorStats: async () => answer })
		traces.push(
			await trace('loadVectorStats', context(store({ success: true }))),
		)
		for (const answer of VECTOR_ANSWERS) {
			const ctx = context(store(answer))
			ctx.llmSettings.enabled = true
			ctx.llmError = true
			ctx.llmErrorMessage = 'old'
			ctx.fileStats.totalFiles = 'kept'
			ctx.objectStats.totalObjects = 'kept'
			traces.push(await trace('loadVectorStats', ctx))
		}
		for (const failure of [new Error('vector down'), new Error(''), {}]) {
			const ctx = context({
				getVectorStats: async () => {
					throw failure
				},
			})
			ctx.llmSettings.enabled = true
			ctx.vectorStats.totalVectors = 'kept'
			traces.push(await trace('loadVectorStats', ctx))
		}
		expect(traces).toMatchSnapshot()
	})

	it('loadDatabaseInfo matches the recorded state', async () => {
		const traces = []
		for (const database of DATABASE_ANSWERS) {
			for (const llm of LLM_ANSWERS) {
				answerGets(database, llm)
				traces.push(await trace('loadDatabaseInfo', context({})))
			}
		}
		answerGets(new Error('database down'), LLM_ANSWERS[0])
		traces.push(await trace('loadDatabaseInfo', context({})))
		answerGets(undefined, LLM_ANSWERS[0])
		traces.push(await trace('loadDatabaseInfo', context({})))
		expect(traces).toMatchSnapshot()
	})
})
