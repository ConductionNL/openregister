/**
 * The admin settings page reads in as few waves as it can: the file section
 * and the LLM section send their independent reads at mount, the LLM section
 * reuses the LLM settings it loaded, and the pipeline list does not read again
 * when its parent's first backend state arrives.
 *
 * @spec openspec/specs/platform-administration-modals/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import AnonymisationPipelineList from './AnonymisationPipelineList.vue'
import FileConfiguration from './FileConfiguration.vue'
import LlmConfiguration from './LlmConfiguration.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/gen${url}` }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => text, translatePlural: (app, one, many) => many }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/Check.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsVertical.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocument.vue', () => ({}))
jest.mock('vue-material-design-icons/Information.vue', () => ({}))
jest.mock('vue-material-design-icons/Key.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('vue-material-design-icons/CubeOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocumentCheckOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Robot.vue', () => ({}))
jest.mock('vue-material-design-icons/VectorSquare.vue', () => ({}))
jest.mock('pinia', () => ({ mapStores: () => ({}) }))
jest.mock('../../../store/settings.js', () => ({ useSettingsStore: () => ({}) }))
jest.mock('../../../components/shared/SettingsCard.vue', () => ({}))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({}))
jest.mock('../../../modals/settings/FileManagementModal.vue', () => ({}))
jest.mock('../../../modals/settings/FileVectorizationModal.vue', () => ({}))
jest.mock('../../../modals/settings/LLMConfigModal.vue', () => ({}))
jest.mock('../../../modals/settings/ObjectManagementModal.vue', () => ({}))
jest.mock('../../../modals/settings/ObjectVectorizationModal.vue', () => ({}))

/**
 * A promise with its resolve function, to answer a request when the test says so.
 *
 * @return {{promise: Promise, resolve: Function}}
 */
function deferred() {
	let resolve
	const promise = new Promise((done) => {
		resolve = done
	})
	return { promise, resolve }
}

/**
 * Let pending promise callbacks run.
 *
 * @return {Promise<void>}
 */
function settle() {
	return new Promise((done) => setTimeout(done, 0))
}

/**
 * An Options-API component instance over plain state, its methods bound to it.
 *
 * @param {object} component The component definition
 * @param {object} state Extra state
 * @return {object} The instance
 */
function instance(component, state = {}) {
	const vm = { ...state }
	for (const [name, method] of Object.entries(component.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('AnonymisationPipelineList refreshKey', () => {
	it('does not read again when the parent first receives its backend state', () => {
		const vm = { load: jest.fn() }
		AnonymisationPipelineList.watch.refreshKey.call(vm, { backends: {} }, null)
		expect(vm.load).not.toHaveBeenCalled()
	})

	it('reads again when a save replaces the backend state', () => {
		const vm = { load: jest.fn() }
		AnonymisationPipelineList.watch.refreshKey.call(vm, { backends: { a: 1 } }, { backends: {} })
		expect(vm.load).toHaveBeenCalledTimes(1)
	})
})

describe('FileConfiguration mount', () => {
	it('sends file settings, object settings, extraction stats and backend state before any answers', async () => {
		const answers = { file: deferred(), object: deferred(), stats: deferred(), state: deferred() }
		const settingsStore = {
			getFileSettings: jest.fn(() => answers.file.promise),
			getObjectSettings: jest.fn(() => answers.object.promise),
			getExtractionStats: jest.fn(() => answers.stats.promise),
			getAnonymisationBackendState: jest.fn(() => answers.state.promise),
		}
		const vm = instance(FileConfiguration, { ...FileConfiguration.data.call({}), settingsStore })
		vm.loadBackendState = jest.fn(FileConfiguration.methods.loadBackendState.bind(vm))

		const mounting = FileConfiguration.mounted.call(vm)
		await settle()

		expect(settingsStore.getFileSettings).toHaveBeenCalledTimes(1)
		expect(settingsStore.getObjectSettings).toHaveBeenCalledTimes(1)
		expect(settingsStore.getExtractionStats).toHaveBeenCalledTimes(1)
		expect(settingsStore.getAnonymisationBackendState).toHaveBeenCalledTimes(1)
		// The state is applied only once the settings are in.
		expect(vm.loadBackendState).not.toHaveBeenCalled()

		answers.file.resolve(null)
		answers.object.resolve(null)
		answers.stats.resolve(null)
		answers.state.resolve({ activeMethod: 'regex', backends: {} })
		await mounting

		expect(vm.loadBackendState).toHaveBeenCalledTimes(1)
		expect(vm.backendState).toEqual({ activeMethod: 'regex', backends: {} })
		expect(settingsStore.getAnonymisationBackendState).toHaveBeenCalledTimes(1)
	})

	it('still mounts when the backend state cannot be read', async () => {
		jest.spyOn(console, 'error').mockImplementation(() => {})
		const settingsStore = {
			getFileSettings: jest.fn(async () => null),
			getObjectSettings: jest.fn(async () => null),
			getExtractionStats: jest.fn(async () => null),
			getAnonymisationBackendState: jest.fn(() => {
				throw new Error('not a function')
			}),
		}
		const vm = instance(FileConfiguration, { ...FileConfiguration.data.call({}), settingsStore })

		await expect(FileConfiguration.mounted.call(vm)).resolves.toBeUndefined()
		expect(vm.backendState).toBeNull()
		console.error.mockRestore()
	})
})

describe('LlmConfiguration', () => {
	it('takes the vector backend from the LLM settings it loaded, without a second read', async () => {
		const vm = instance(LlmConfiguration, { llmSettings: { vectorConfig: { backend: 'solr' } } })

		await expect(vm.vectorBackend()).resolves.toBe('solr')
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('reads the LLM settings for the vector backend while they are not loaded', async () => {
		axios.get.mockResolvedValue({ data: { vectorConfig: { backend: 'database' } } })
		const vm = instance(LlmConfiguration, { llmSettings: { enabled: false } })

		await expect(vm.vectorBackend()).resolves.toBe('database')
		expect(axios.get).toHaveBeenCalledWith('/gen/apps/openregister/api/settings/llm')
	})

	it('sends the chat and extraction stats at mount, and the vector stats and database info after the settings', async () => {
		const settings = deferred()
		const order = []
		const vm = {
			settingsStore: { getExtractionStats: jest.fn(async () => order.push('extraction')) },
			loadChatStats: jest.fn(async () => order.push('chat')),
			loadSettings: jest.fn(() => settings.promise),
			loadVectorStats: jest.fn(async () => order.push('vector')),
			loadDatabaseInfo: jest.fn(async () => order.push('database')),
		}

		const mounting = LlmConfiguration.mounted.call(vm)
		await settle()
		expect(order).toEqual(['chat', 'extraction'])
		expect(vm.loadVectorStats).not.toHaveBeenCalled()

		settings.resolve()
		await mounting
		expect(order).toEqual(['chat', 'extraction', 'vector', 'database'])
	})
})
