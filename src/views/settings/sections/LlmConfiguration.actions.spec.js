/**
 * The LLM settings section's actions: retrying the connection, saving and
 * switching the LLM on, reloading after the configuration dialog, and the
 * status class and number and date formatting the tiles read.
 *
 * @spec exclude Client-side settings section behaviour.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import LlmConfiguration from './LlmConfiguration.vue'

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
 * A settings store whose loaders all answer empty.
 *
 * @param {object} overrides Members to replace
 * @return {object} The store double
 */
function store(overrides = {}) {
	return {
		getChatStats: jest.fn(() => Promise.resolve(null)),
		getVectorStats: jest.fn(() => Promise.resolve(null)),
		getExtractionStats: jest.fn(() => Promise.resolve()),
		saveLlmSettings: jest.fn(() => Promise.resolve()),
		patchLlmSettings: jest.fn(() => Promise.resolve()),
		...overrides,
	}
}

let consoleError

beforeEach(() => {
	axios.get.mockReset()
	axios.get.mockResolvedValue({ data: {} })
	consoleError = jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	consoleError.mockRestore()
})

describe('LlmConfiguration.retryConnection', () => {
	it('reloads every statistic and marks the connection up', async () => {
		const settingsStore = store()
		const ctx = context(settingsStore)
		ctx.llmError = true
		let loadingDuring
		settingsStore.getExtractionStats.mockImplementation(() => {
			loadingDuring = ctx.loadingStats
			return Promise.resolve()
		})
		await ctx.retryConnection()
		expect(loadingDuring).toBe(true)
		expect(settingsStore.getChatStats).toHaveBeenCalledTimes(1)
		expect(settingsStore.getExtractionStats).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/settings/database')
		expect(ctx.llmError).toBe(false)
		expect(ctx.llmConnectionStatus).toBe('Connected ✓')
		expect(ctx.loadingStats).toBe(false)
	})

	it('marks the connection down with the failure message', async () => {
		const ctx = context(store({ getExtractionStats: jest.fn(() => Promise.reject(new Error('timeout'))) }))
		await ctx.retryConnection()
		expect(ctx.llmError).toBe(true)
		expect(ctx.llmErrorMessage).toBe('timeout')
		expect(ctx.llmConnectionStatus).toBe('Disconnected ✗')
		expect(ctx.loadingStats).toBe(false)
	})

	it('falls back to its own message when the failure has none', async () => {
		const ctx = context(store({ getExtractionStats: jest.fn(() => Promise.reject({})) }))
		await ctx.retryConnection()
		expect(ctx.llmErrorMessage).toBe('Failed to connect to LLM service')
	})
})

describe('LlmConfiguration.saveSettings', () => {
	it('saves the current settings and clears the saving flag', async () => {
		const settingsStore = store()
		const ctx = context(settingsStore)
		let savingDuring
		settingsStore.saveLlmSettings.mockImplementation(() => {
			savingDuring = ctx.saving
			return Promise.resolve()
		})
		await ctx.saveSettings()
		expect(settingsStore.saveLlmSettings).toHaveBeenCalledWith(ctx.llmSettings)
		expect(savingDuring).toBe(true)
		expect(ctx.saving).toBe(false)
	})

	it('logs a failed save and clears the saving flag', async () => {
		const failure = new Error('refused')
		const ctx = context(store({ saveLlmSettings: jest.fn(() => Promise.reject(failure)) }))
		await ctx.saveSettings()
		expect(consoleError).toHaveBeenCalledWith('Failed to save LLM settings:', failure)
		expect(ctx.saving).toBe(false)
	})
})

describe('LlmConfiguration.onLlmEnabledChange', () => {
	it('patches only the enabled flag, then loads the vector statistics', async () => {
		const settingsStore = store({ getVectorStats: jest.fn(() => Promise.resolve({ success: true })) })
		const ctx = context(settingsStore)
		ctx.llmSettings.enabled = true
		await ctx.onLlmEnabledChange()
		expect(settingsStore.patchLlmSettings).toHaveBeenCalledWith({ enabled: true })
		expect(settingsStore.getVectorStats).toHaveBeenCalledTimes(1)
		expect(ctx.llmConnectionStatus).toBe('Connected')
	})

	it('does not load the vector statistics when switched off', async () => {
		const settingsStore = store()
		const ctx = context(settingsStore)
		ctx.llmSettings.enabled = false
		await ctx.onLlmEnabledChange()
		expect(settingsStore.patchLlmSettings).toHaveBeenCalledWith({ enabled: false })
		expect(settingsStore.getVectorStats).not.toHaveBeenCalled()
	})
})

describe('LlmConfiguration dialogs', () => {
	it('closes the configuration dialog and reloads the statistics', async () => {
		const settingsStore = store()
		const ctx = context(settingsStore)
		ctx.showLLMConfigDialog = true
		ctx.onLLMConfigClosed()
		expect(ctx.showLLMConfigDialog).toBe(false)
		expect(settingsStore.getChatStats).toHaveBeenCalledTimes(1)
		expect(settingsStore.getExtractionStats).toHaveBeenCalledTimes(1)
	})

	it('opens the object and the file vectorization dialogs', () => {
		const ctx = context(store())
		ctx.showVectorizeObjectsDialog()
		expect(ctx.showObjectVectorizationModal).toBe(true)
		expect(ctx.showFileVectorizationModal).toBe(false)
		ctx.showVectorizeFilesDialog()
		expect(ctx.showFileVectorizationModal).toBe(true)
	})
})

describe('LlmConfiguration display helpers', () => {
	const statusClass = (llmConnectionStatus) =>
		LlmConfiguration.computed.connectionStatusClass.call({ llmConnectionStatus })

	it('classes the connection status', () => {
		expect(statusClass('Connected')).toBe('status-connected')
		expect(statusClass('Connected ✓')).toBe('status-connected')
		expect(statusClass('Disconnected')).toBe('status-disconnected')
		expect(statusClass('Disconnected ✗')).toBe('status-disconnected')
		expect(statusClass('Unknown')).toBe('status-unknown')
	})

	it('formats numbers with the locale grouping', () => {
		const ctx = context(store())
		expect(ctx.formatNumber(1234567)).toBe(new Intl.NumberFormat().format(1234567))
		expect(ctx.formatNumber(0)).toBe('0')
	})

	it('formats a date in the locale, and nothing as empty', () => {
		const ctx = context(store())
		expect(ctx.formatDate('')).toBe('')
		expect(ctx.formatDate(null)).toBe('')
		expect(ctx.formatDate('2026-01-02T03:04:05Z')).toBe(new Date('2026-01-02T03:04:05Z').toLocaleString())
	})
})
