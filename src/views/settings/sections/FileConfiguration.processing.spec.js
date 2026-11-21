/**
 * The file configuration section's processing actions and connection tests:
 * discovering, extracting and retrying files and the message each answer
 * gives, the Dolphin, Presidio and OpenAnonymiser connection tests, the
 * object extraction mode, and the small labels and switches the template
 * reads. Each method runs against the section's own data and a stub store.
 *
 * @spec exclude Client-side settings actions of the file configuration section.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import FileConfiguration from './FileConfiguration.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/Check.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsVertical.vue', () => ({}))
jest.mock('vue-material-design-icons/FileDocument.vue', () => ({}))
jest.mock('vue-material-design-icons/Information.vue', () => ({}))
jest.mock('vue-material-design-icons/Key.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('../../../components/shared/SettingsCard.vue', () => ({}))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => text }))

const { computed, methods } = FileConfiguration

/**
 * A section instance: its own initial data, its methods bound to it and a
 * settings store whose every call is a jest mock.
 *
 * @param {object} store The store methods to provide
 * @return {object} The instance
 */
function section(store = {}) {
	const vm = { ...FileConfiguration.data(), settingsStore: store }
	for (const [name, fn] of Object.entries(methods)) vm[name] = fn.bind(vm)
	return vm
}

/**
 * The message the section shows last.
 *
 * @param {object} vm The instance
 * @return {Array} [text, type]
 */
const message = (vm) => [vm.saveMessage, vm.saveMessageType]

const STATS = { totalFiles: 4 }

beforeEach(() => {
	jest.useFakeTimers()
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	console.error.mockRestore()
	jest.useRealTimers()
})

describe('FileConfiguration file processing', () => {
	it.each([
		['discoverFiles', 'discoverFiles', [], { discovered: 3 }, 'Discovered 3 new files', 'success', 'discoveringFiles'],
		['discoverFiles', 'discoverFiles', [], { discovered: 3, failed: 2 }, 'Discovered 3 new files, 2 failed to stage', 'error', 'discoveringFiles'],
		['extractAllPendingFiles', 'triggerFileExtraction', ['pending'], { processed: 5 }, 'Processed 5 pending files', 'success', 'extractingFiles'],
		['extractAllPendingFiles', 'triggerFileExtraction', ['pending'], { processed: 5, failed: 1 }, 'Processed 5 pending files, 1 failed', 'error', 'extractingFiles'],
		['reprocessFailedFiles', 'triggerFileExtraction', ['failed'], { retried: 2 }, 'Retried 2 failed extractions', 'success', 'retryingFiles'],
		['reprocessFailedFiles', 'triggerFileExtraction', ['failed'], { retried: 2, failed: 2 }, 'Retried 2 failed extractions, 2 failed again', 'error', 'retryingFiles'],
	])('%s reports %j', async (action, storeCall, args, data, text, type, flag) => {
		const store = {
			[storeCall]: jest.fn().mockResolvedValue({ data }),
			getExtractionStats: jest.fn().mockResolvedValue(STATS),
		}
		const vm = section(store)

		await vm[action]()

		expect(store[storeCall]).toHaveBeenCalledWith(...args)
		expect(message(vm)).toEqual([text, type])
		expect(vm[flag]).toBe(false)
		// The counts are reloaded after every run.
		expect(vm.extractionStats.totalFiles).toBe(4)
	})

	it.each([
		['discoverFiles', 'discoverFiles', 'Discovered 0 new files'],
		['extractAllPendingFiles', 'triggerFileExtraction', 'Processed 0 pending files'],
		['reprocessFailedFiles', 'triggerFileExtraction', 'Retried 0 failed extractions'],
	])('%s counts zero for an answer without data', async (action, storeCall, text) => {
		const vm = section({ [storeCall]: jest.fn().mockResolvedValue(null), getExtractionStats: jest.fn() })

		await vm[action]()

		expect(message(vm)).toEqual([text, 'success'])
	})

	it.each([
		['discoverFiles', 'discoverFiles', 'Failed to discover files', 'discoveringFiles'],
		['extractAllPendingFiles', 'triggerFileExtraction', 'Failed to extract files', 'extractingFiles'],
		['reprocessFailedFiles', 'triggerFileExtraction', 'Failed to retry extractions', 'retryingFiles'],
	])('%s reports a failure and clears its busy flag', async (action, storeCall, text, flag) => {
		const stats = jest.fn()
		const vm = section({ [storeCall]: jest.fn().mockRejectedValue(new Error('down')), getExtractionStats: stats })

		await vm[action]()

		expect(message(vm)).toEqual([text, 'error'])
		expect(vm[flag]).toBe(false)
		expect(stats).not.toHaveBeenCalled()
	})
})

describe('FileConfiguration connection tests', () => {
	it('refuses a Dolphin test without both endpoint and key', async () => {
		const store = { testDolphinConnection: jest.fn() }
		const vm = section(store)
		vm.fileSettings.dolphinApiEndpoint = 'https://dolphin.example'

		await vm.testDolphinConnection()

		expect(store.testDolphinConnection).not.toHaveBeenCalled()
		expect(vm.dolphinConnectionTested).toBe('error')
		expect(message(vm)).toEqual(['Please provide both API endpoint and API key', 'error'])
	})

	it('tests Dolphin with the endpoint and key and reports success', async () => {
		const store = { testDolphinConnection: jest.fn().mockResolvedValue({ success: true }) }
		const vm = section(store)
		vm.fileSettings.dolphinApiEndpoint = 'https://dolphin.example'
		vm.fileSettings.dolphinApiKey = 'k'

		await vm.testDolphinConnection()

		expect(store.testDolphinConnection).toHaveBeenCalledWith({ apiEndpoint: 'https://dolphin.example', apiKey: 'k' })
		expect(vm.dolphinConnectionTested).toBe('success')
		expect(message(vm)).toEqual(['Dolphin connection successful!', 'success'])
	})

	it('reports the error a Presidio service answers with', async () => {
		const store = { testPresidioConnection: jest.fn().mockResolvedValue({ success: false, error: 'timeout' }) }
		const vm = section(store)
		vm.fileSettings.presidioApiEndpoint = 'http://presidio:3000'

		await vm.testPresidioConnection()

		expect(store.testPresidioConnection).toHaveBeenCalledWith({ apiEndpoint: 'http://presidio:3000' })
		expect(vm.presidioConnectionTested).toBe('error')
		expect(vm.testingPresidio).toBe(false)
		expect(message(vm)).toEqual(['Connection failed: timeout', 'error'])
	})

	it('refuses a Presidio test without an endpoint', async () => {
		const vm = section({ testPresidioConnection: jest.fn() })

		await vm.testPresidioConnection()

		expect(vm.presidioConnectionTested).toBe('error')
		expect(message(vm)).toEqual(['Please provide the Presidio API endpoint', 'error'])
	})

	it('reports a thrown OpenAnonymiser test as a failed test', async () => {
		const store = { testOpenAnonymiserConnection: jest.fn().mockRejectedValue(new Error('refused')) }
		const vm = section(store)
		vm.fileSettings.openAnonymiserApiEndpoint = 'http://oa:8080'

		await vm.testOpenAnonymiserConnection()

		expect(store.testOpenAnonymiserConnection).toHaveBeenCalledWith({ apiEndpoint: 'http://oa:8080' })
		expect(vm.openAnonymiserConnectionTested).toBe('error')
		expect(vm.testingOpenAnonymiser).toBe(false)
		expect(message(vm)).toEqual(['Connection test failed', 'error'])
	})

	it('refuses an OpenAnonymiser test without an endpoint', async () => {
		const vm = section({ testOpenAnonymiserConnection: jest.fn() })

		await vm.testOpenAnonymiserConnection()

		expect(vm.openAnonymiserConnectionTested).toBe('error')
		expect(message(vm)).toEqual(['Please provide the OpenAnonymiser API endpoint', 'error'])
	})
})

describe('FileConfiguration object extraction mode', () => {
	it.each([
		['an id', 'cron', 'cron'],
		['an option', { id: 'manual' }, 'manual'],
		['an unknown id, falling back to background', 'nightly', 'background'],
	])('loads %s', async (label, stored, expected) => {
		const vm = section({ getObjectSettings: jest.fn().mockResolvedValue({ objectExtractionMode: stored }) })

		await vm.loadObjectSettings()

		expect(vm.objectSettings.extractionMode.id).toBe(expected)
	})

	it('keeps its mode when nothing is stored or loading fails', async () => {
		const empty = section({ getObjectSettings: jest.fn().mockResolvedValue({}) })
		const failing = section({ getObjectSettings: jest.fn().mockRejectedValue(new Error('x')) })

		await empty.loadObjectSettings()
		await failing.loadObjectSettings()

		expect(empty.objectSettings.extractionMode.id).toBe('background')
		expect(failing.objectSettings.extractionMode.id).toBe('background')
	})

	it('saves the mode id, defaulting to background, and reports a failure', async () => {
		const save = jest.fn().mockResolvedValueOnce(undefined).mockResolvedValueOnce(undefined).mockRejectedValueOnce(new Error('x'))
		const vm = section({ saveObjectSettings: save })

		vm.objectSettings.extractionMode = { id: 'cron' }
		await vm.saveObjectSettings()
		vm.objectSettings.extractionMode = null
		await vm.saveObjectSettings()
		expect(vm.saveMessage).toBe('')
		await vm.saveObjectSettings()

		expect(save.mock.calls).toEqual([
			[{ objectExtractionMode: 'cron' }],
			[{ objectExtractionMode: 'background' }],
			[{ objectExtractionMode: 'background' }],
		])
		expect(message(vm)).toEqual(['Failed to save object settings', 'error'])
	})
})

describe('FileConfiguration small switches', () => {
	it('is processing while any of the three runs is busy', () => {
		const vm = section()
		expect(computed.isProcessing.call(vm)).toBe(false)
		for (const flag of ['discoveringFiles', 'extractingFiles', 'retryingFiles']) {
			expect(computed.isProcessing.call({ ...vm, [flag]: true })).toBe(true)
		}
	})

	it.each([
		[undefined, false, false],
		['regex', false, false],
		['presidio', true, false],
		['hybrid', true, false],
		['openanonymiser', false, true],
	])('for method %s shows Presidio %s and OpenAnonymiser %s', (id, presidio, openAnonymiser) => {
		const vm = { fileSettings: { entityRecognitionMethod: id ? { id } : null } }

		expect(computed.showPresidioConfig.call(vm)).toBe(presidio)
		expect(computed.showOpenAnonymiserConfig.call(vm)).toBe(openAnonymiser)
	})

	it.each([
		[{ available: true, configured: true }, 'Available'],
		[{ available: false, configured: true }, 'Unavailable'],
		[{ available: false, configured: false }, 'Not configured'],
	])('labels backend %j as %s', (backend, label) => {
		expect(section().backendStateLabel(backend)).toBe(label)
	})

	it('saves only a changed OpenAnonymiser password, and the source when it is switched', async () => {
		const vm = section()
		vm.saveSettings = jest.fn()

		await vm.saveOpenAnonymiserPassword()
		expect(vm.saveSettings).not.toHaveBeenCalled()

		vm.openAnonymiserPasswordChanged = true
		await vm.saveOpenAnonymiserPassword()
		await vm.setOpenAnonymiserSource('external')

		expect(vm.saveSettings).toHaveBeenCalledTimes(2)
		expect(vm.fileSettings.openAnonymiserSource).toBe('external')
	})

	it('formats a missing number as zero', () => {
		const vm = section()

		expect(vm.formatNumber(undefined)).toBe(new Intl.NumberFormat().format(0))
		expect(vm.formatNumber(1234567)).toBe(new Intl.NumberFormat().format(1234567))
	})
})
