/**
 * Golden record of the API backend fields of the file settings: the label,
 * text field and hint of the Dolphin, Presidio and external OpenAnonymiser
 * connections.
 *
 * @spec exclude Golden record of client-side settings field markup.
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
	translate: (app, text) => `[${text}]`,
	t: (app, text) => `[${text}]`,
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
jest.mock('../../../store/settings.js', () => ({
	useSettingsStore: Object.assign(() => ({}), { $id: 'settings' }),
}))

const { normalise } = require('../../__mocks__/goldenDom.js')

/**
 * Mount the section with one API backend's fields on screen.
 *
 * @param {object} settings The file settings that pick the backend
 * @return {Promise<object>} The wrapper
 */
async function mounted(settings) {
	// The store is a stand-in, so the section logs that it could not load.
	jest.spyOn(console, 'error').mockImplementation(() => {})
	const wrapper = mount(FileConfiguration, {
		global: { config: { globalProperties: { t: (app, text) => `[${text}]`, n: (app, one, many) => many } } },
	})
	await flushPromises()
	await wrapper.setData({
		fileSettings: { ...wrapper.vm.fileSettings, ...settings },
		backendState: { backends: {} },
	})
	return wrapper
}

describe('file configuration API fields', () => {
	afterEach(() => {
		jest.restoreAllMocks()
	})

	it.each([
		['Dolphin', { textExtractor: { id: 'dolphin', label: 'Dolphin' } }],
		['Presidio', { entityRecognitionEnabled: true, entityRecognitionMethod: { id: 'presidio', label: 'Presidio' } }],
		['external OpenAnonymiser', {
			entityRecognitionEnabled: true,
			entityRecognitionMethod: { id: 'openanonymiser', label: 'OpenAnonymiser' },
			openAnonymiserSource: 'external',
		}],
	])('renders the %s fields', async (backend, settings) => {
		const wrapper = await mounted(settings)
		const groups = wrapper.findAll('.api-config .field-group')
		expect(groups.length).toBeGreaterThan(0)
		expect(groups.map((group) => normalise(group))).toMatchSnapshot()
	})
})
