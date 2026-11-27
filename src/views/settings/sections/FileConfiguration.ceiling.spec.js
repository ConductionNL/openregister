/**
 * The file configuration page states the text extraction ceiling: the stored
 * `textExtraction.maxBytes` is shown in MB and saved back in bytes.
 *
 * @spec openspec/changes/large-file-handling/tasks.md#task-1-5
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import FileConfiguration from './FileConfiguration.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: {
		get: jest.fn(() => Promise.reject(new Error('offline'))),
		put: jest.fn(() => Promise.reject(new Error('offline'))),
		post: jest.fn(() => Promise.reject(new Error('offline'))),
	},
}))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => `/index.php${path}` }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (app, text) => text,
	t: (app, text) => text,
	n: (app, one, many) => many,
}))
jest.mock('@nextcloud/vue', () => require('../../__mocks__/goldenDom.js').library())
jest.mock('vue-material-design-icons/AlertCircle.vue', () => require('../../__mocks__/goldenDom.js').stubModule('AlertCircle'))
jest.mock('vue-material-design-icons/Check.vue', () => require('../../__mocks__/goldenDom.js').stubModule('Check'))
jest.mock('vue-material-design-icons/DotsVertical.vue', () => require('../../__mocks__/goldenDom.js').stubModule('DotsVertical'))
jest.mock('vue-material-design-icons/FileDocument.vue', () => require('../../__mocks__/goldenDom.js').stubModule('FileDocument'))
jest.mock('vue-material-design-icons/Information.vue', () => require('../../__mocks__/goldenDom.js').stubModule('Information'))
jest.mock('vue-material-design-icons/Key.vue', () => require('../../__mocks__/goldenDom.js').stubModule('Key'))
jest.mock('vue-material-design-icons/Magnify.vue', () => require('../../__mocks__/goldenDom.js').stubModule('Magnify'))
jest.mock('vue-material-design-icons/Refresh.vue', () => require('../../__mocks__/goldenDom.js').stubModule('Refresh'))
jest.mock('../../../components/shared/SettingsCard.vue', () => require('../../__mocks__/goldenDom.js').stubModule('SettingsCard'))
jest.mock('../../../components/shared/SettingsSection.vue', () => require('../../__mocks__/goldenDom.js').stubModule('SettingsSection'))

// The store the section reads, replaced per test.
let mockStore = {}
jest.mock('../../../store/settings.js', () => ({
	useSettingsStore: Object.assign(() => mockStore, { $id: 'settings' }),
}))

/**
 * A settings store answering the given file settings; every other call resolves empty.
 *
 * @param {object} fileSettings The stored file settings
 * @return {object} The store
 */
function storeWith(fileSettings) {
	return {
		getFileSettings: jest.fn().mockResolvedValue(fileSettings),
		saveFileSettings: jest.fn().mockImplementation((payload) => Promise.resolve(payload)),
		getObjectSettings: jest.fn().mockResolvedValue({}),
		getExtractionStats: jest.fn().mockResolvedValue({}),
		getAnonymisationBackendState: jest.fn().mockResolvedValue({}),
		loadSettings: jest.fn().mockResolvedValue({}),
	}
}

/**
 * The section mounted over a store, its settings loaded.
 *
 * @param {object} store The settings store
 * @return {Promise<object>} The wrapper
 */
async function mounted(store) {
	mockStore = store
	const wrapper = mount(FileConfiguration, {
		global: { config: { globalProperties: { t: (app, text) => text, n: (app, one, many) => many } } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	// The stand-in store answers only what the ceiling needs; the rest of the section logs.
	jest.spyOn(console, 'error').mockImplementation(() => {})
	jest.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
	jest.restoreAllMocks()
})

describe('FileConfiguration extraction ceiling', () => {
	it('renders the stored ceiling in MB', async () => {
		const wrapper = await mounted(storeWith({ textExtraction: { maxBytes: 52428800 } }))

		expect(wrapper.find('#max-file-size').element.value).toBe('50')
		expect(wrapper.find('label[for="max-file-size"]').text()).toBe('Text Extraction Ceiling (MB)')
	})

	it.each([
		['the default ceiling', { textExtraction: { maxBytes: 104857600 } }, 100],
		['a legacy ceiling in MB', { maxFileSizeMB: 25 }, 25],
		['no ceiling at all', {}, 100],
		['an unusable ceiling', { textExtraction: { maxBytes: 0 } }, 100],
	])('shows %s', async (label, settings, megabytes) => {
		const wrapper = await mounted(storeWith(settings))

		expect(wrapper.vm.fileSettings.maxFileSize).toBe(megabytes)
	})

	it('saves the ceiling in bytes and no legacy key', async () => {
		const store = storeWith({ textExtraction: { maxBytes: 104857600 } })
		const wrapper = await mounted(store)

		wrapper.vm.fileSettings.maxFileSize = 20
		await wrapper.vm.saveSettings()

		const payload = store.saveFileSettings.mock.calls[0][0]
		expect(payload.textExtraction).toEqual({ maxBytes: 20971520 })
		expect(payload).not.toHaveProperty('maxFileSize')
		expect(payload).not.toHaveProperty('maxFileSizeMB')
	})
})
