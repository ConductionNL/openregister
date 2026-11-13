import StatisticsOverview from './StatisticsOverview.vue'

jest.mock('@nextcloud/vue', () => require('../../__mocks__/goldenDom.js').library())
jest.mock('../../../components/shared/SettingsSection.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('SettingsSection'),
)
jest.mock('../../../modals/settings/MassValidateModal.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('MassValidateModal'),
)
jest.mock('../../../dialogs/settings/ClearAuditTrailsDialog.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('ClearAuditTrailsDialog'),
)
jest.mock('../../../dialogs/settings/ClearBlobObjectsDialog.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('ClearBlobObjectsDialog'),
)
jest.mock('../../../dialogs/settings/ClearSearchTrailsDialog.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('ClearSearchTrailsDialog'),
)
jest.mock('../../../dialogs/settings/RebaseConfirmationDialog.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('RebaseConfirmationDialog'),
)
jest.mock('vue-material-design-icons/CheckCircle.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('CheckCircle'),
)
jest.mock('vue-material-design-icons/Delete.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('Delete'),
)
jest.mock('vue-material-design-icons/Refresh.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('Refresh'),
)
jest.mock('../../../store/settings.js', () => {
	const useSettingsStore = () => globalThis.mockSettingsStore
	useSettingsStore.$id = 'settings'
	return { useSettingsStore }
})

/**
 * StatisticsOverview behaviour: the warning and total rows built from the
 * store stats, the mass-validate modal flow (open, run, retry, reset, close)
 * with its object count and memory prediction, and the three clear actions
 * that reload the stats only when the clear succeeded.
 *
 * @spec exclude Client-side behaviour of the statistics section; the settings contract lives in the settings store.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * A section context with its data, its methods and a given store.
 *
 * @param {object} store The settings store double
 * @return {object} The context
 */
function section(store) {
	const ctx = { ...StatisticsOverview.data.call({}), settingsStore: store }
	for (const [name, method] of Object.entries(StatisticsOverview.methods)) {
		ctx[name] = method.bind(ctx)
	}
	for (const [name, getter] of Object.entries(StatisticsOverview.computed)) {
		Object.defineProperty(ctx, name, { get: getter.bind(ctx), configurable: true })
	}
	return ctx
}

describe('StatisticsOverview behaviour', () => {
	let errors
	let warnings

	beforeEach(() => {
		errors = jest.spyOn(console, 'error').mockImplementation(() => {})
		warnings = jest.spyOn(console, 'warn').mockImplementation(() => {})
	})

	afterEach(() => {
		errors.mockRestore()
		warnings.mockRestore()
	})

	it('flags only the warnings with a positive count', () => {
		const ctx = section({ stats: { warnings: { objectsWithoutOwner: 3, objectsWithoutOrganisation: 0, expiredObjects: 1 } } })
		const rows = ctx.warningRows
		expect(rows).toHaveLength(7)
		expect(rows[0]).toEqual({ label: 'Objects without owner', count: 3, danger: true, size: '-' })
		expect(rows[1]).toEqual({ label: 'Objects without organisation', count: 0, danger: false, size: '-' })
		expect(rows[2].danger).toBe(false)
		expect(rows[6]).toEqual({ label: 'Expired objects', count: 1, danger: true, size: '-' })
	})

	it('builds a total row with its size, its clearing state and its clear action', () => {
		const store = { stats: { totals: { totalObjects: 5, totalSize: 2048, totalAuditTrails: 9 } }, clearingAuditTrails: true }
		const ctx = section(store)
		expect(ctx.totalRow({ label: 'Objects', count: 'totalObjects', size: 'totalSize' })).toEqual({
			label: 'Objects', count: 5, size: ctx.formatBytes(2048), clearing: false, clear: null,
		})
		const audit = ctx.totalRow({ label: 'Audit Trails', count: 'totalAuditTrails', clear: 'AuditTrails' })
		expect(audit.count).toBe(9)
		expect(audit.size).toBe('-')
		expect(audit.clearing).toBe(true)
		expect(audit.clear).toBe(ctx.showClearAuditTrailsDialog)
	})

	it('formats a missing size as zero bytes', () => {
		const ctx = section({})
		expect(ctx.formatBytes(undefined)).toBe(ctx.formatBytes(0))
		expect(ctx.formatBytes(null)).toBe(ctx.formatBytes(0))
	})

	it('opens the modal at once and loads the object count and an all-objects prediction', async () => {
		const prediction = { prediction_safe: false, formatted: { total_predicted: '2 GB', available: '1 GB' } }
		const store = {
			stats: { totals: { totalObjects: 42 } },
			loadMassValidateMemoryPrediction: jest.fn(() => Promise.resolve(prediction)),
		}
		const ctx = section(store)
		const opening = ctx.openMassValidateModal()
		expect(ctx.showMassValidateModal).toBe(true)
		expect(ctx.memoryPredictionLoading).toBe(true)
		await opening
		await Promise.resolve()
		expect(store.loadMassValidateMemoryPrediction).toHaveBeenCalledWith(0)
		expect(ctx.objectStats).toEqual({ loading: false, totalObjects: 42 })
		expect(ctx.memoryPrediction).toBe(prediction)
		expect(ctx.memoryPredictionLoading).toBe(false)
	})

	it('counts zero objects when the store has no totals', async () => {
		const ctx = section({ stats: {} })
		ctx.objectStats.totalObjects = 9
		await ctx.loadObjectStats()
		expect(ctx.objectStats).toEqual({ loading: false, totalObjects: 0 })
	})

	it('keeps the unknown prediction when the prediction cannot be loaded', async () => {
		const store = { loadMassValidateMemoryPrediction: jest.fn(() => Promise.reject(new Error('down'))) }
		const ctx = section(store)
		await ctx.loadMemoryPrediction(500)
		expect(store.loadMassValidateMemoryPrediction).toHaveBeenCalledWith(500)
		expect(ctx.memoryPrediction).toEqual({ prediction_safe: true, formatted: { total_predicted: 'Unknown', available: 'Unknown' } })
		expect(ctx.memoryPredictionLoading).toBe(false)
		expect(warnings).toHaveBeenCalled()
	})

	it('runs a mass validation with a copy of the config and marks it completed', async () => {
		const store = { massValidate: jest.fn(() => Promise.resolve()) }
		const ctx = section(store)
		const config = { mode: 'parallel', maxObjects: 10, batchSize: 5, collectErrors: true }
		const running = ctx.handleStartMassValidate(config)
		expect(ctx.massValidateCompleted).toBe(false)
		await running
		expect(store.massValidate).toHaveBeenCalledWith(config)
		expect(ctx.massValidateConfig).toEqual(config)
		expect(ctx.massValidateConfig).not.toBe(config)
		expect(ctx.massValidateCompleted).toBe(true)
	})

	it('marks a failed mass validation completed too and logs it', async () => {
		const failure = new Error('timeout')
		const ctx = section({ massValidate: jest.fn(() => Promise.reject(failure)) })
		await ctx.handleStartMassValidate({ mode: 'serial' })
		expect(ctx.massValidateCompleted).toBe(true)
		expect(errors).toHaveBeenCalledWith('Mass validate failed:', failure)
	})

	it('retries with the last config', async () => {
		const store = { massValidate: jest.fn(() => Promise.resolve()) }
		const ctx = section(store)
		await ctx.handleStartMassValidate({ mode: 'parallel', maxObjects: 3, batchSize: 1, collectErrors: false })
		await ctx.handleRetryMassValidate()
		expect(store.massValidate).toHaveBeenCalledTimes(2)
		expect(store.massValidate.mock.calls[1][0]).toEqual({ mode: 'parallel', maxObjects: 3, batchSize: 1, collectErrors: false })
	})

	it('resets to the default config and closing clears the completed state', () => {
		const ctx = section({})
		ctx.massValidateCompleted = true
		ctx.massValidateConfig = { mode: 'parallel', maxObjects: 3, batchSize: 1, collectErrors: true }
		ctx.handleResetMassValidate()
		expect(ctx.massValidateCompleted).toBe(false)
		expect(ctx.massValidateConfig).toEqual({ mode: 'serial', maxObjects: 0, batchSize: 1000, collectErrors: false })
		ctx.showMassValidateModal = true
		ctx.massValidateCompleted = true
		ctx.closeMassValidateModal()
		expect(ctx.showMassValidateModal).toBe(false)
		expect(ctx.massValidateCompleted).toBe(false)
	})

	it.each([
		['clearAllAuditTrails'],
		['clearAllSearchTrails'],
		['clearAllBlobObjects'],
	])('%s clears through the store and reloads the stats', async (action) => {
		const store = { [action]: jest.fn(() => Promise.resolve()), loadStats: jest.fn() }
		await section(store)[action]()
		expect(store[action]).toHaveBeenCalledTimes(1)
		expect(store.loadStats).toHaveBeenCalledTimes(1)
	})

	it.each([
		['clearAllAuditTrails'],
		['clearAllSearchTrails'],
		['clearAllBlobObjects'],
	])('%s does not reload the stats when clearing fails', async (action) => {
		const store = { [action]: jest.fn(() => Promise.reject(new Error('no'))), loadStats: jest.fn() }
		await expect(section(store)[action]()).resolves.toBeUndefined()
		expect(store.loadStats).not.toHaveBeenCalled()
		expect(errors).toHaveBeenCalled()
	})

	it.each([
		['showClearAuditTrailsDialog'],
		['hideClearAuditTrailsDialog'],
		['showClearSearchTrailsDialog'],
		['hideClearSearchTrailsDialog'],
		['showClearBlobObjectsDialog'],
		['hideClearBlobObjectsDialog'],
		['loadStats'],
	])('%s passes through to the store', (method) => {
		const store = { [method]: jest.fn() }
		section(store)[method]()
		expect(store[method]).toHaveBeenCalledTimes(1)
	})
})
