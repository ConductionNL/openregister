/**
 * Golden record of the OpenAnonymiser hint markup: the note with its install
 * link, under the backend status list and under the internal-source choice.
 *
 * @spec exclude Golden record of client-side settings hint markup.
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
 * Mount the section with the OpenAnonymiser backend chosen and its probe failed.
 *
 * @param {string|null} error The probe error code
 * @return {Promise<object>} The wrapper
 */
async function mounted(error) {
	// The store is a stand-in, so the section logs that it could not load.
	jest.spyOn(console, 'error').mockImplementation(() => {})
	const wrapper = mount(FileConfiguration, {
		global: { config: { globalProperties: { t: (app, text) => `[${text}]`, n: (app, one, many) => many } } },
	})
	await flushPromises()
	await wrapper.setData({
		fileSettings: {
			...wrapper.vm.fileSettings,
			entityRecognitionEnabled: true,
			entityRecognitionMethod: { id: 'openanonymiser', label: 'OpenAnonymiser' },
			openAnonymiserSource: 'internal',
		},
		backendState: { backends: {} },
		openAnonymiserError: error,
	})
	return wrapper
}

describe('file configuration OpenAnonymiser hint', () => {
	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('shows the hint with its install link in both places', async () => {
		const wrapper = await mounted('exapp_not_installed')
		const hints = wrapper.findAll('.backend-hint')
		expect(hints).toHaveLength(2)
		expect(hints.map((hint) => normalise(hint))).toMatchSnapshot()
	})

	it('shows no hint without a probe error', async () => {
		const wrapper = await mounted(null)
		expect(wrapper.findAll('.backend-hint')).toHaveLength(0)
		expect(wrapper.findAll('.backend-install-link')).toHaveLength(0)
	})
})

describe('file configuration backend labels', () => {
	it('labels the backend status rows and the method ids', () => {
		const backendState = { backends: { a: { name: 'regex', available: true }, b: { name: 'auto' }, c: { name: 'hybrid' }, d: { name: 'custom' } } }
		const rows = FileConfiguration.computed.backendStatusItems.call({ backendState })
		expect(rows.map((row) => [row.name, row.label])).toEqual([['regex', 'Regex'], ['auto', 'auto'], ['hybrid', 'Hybrid'], ['custom', 'custom']])
		expect(FileConfiguration.computed.methodLabels.call({})).toEqual({
			auto: 'Auto', regex: 'Regex', presidio: 'Presidio', openanonymiser: 'OpenAnonymiser', llm: 'LLM', hybrid: 'Hybrid',
		})
	})
})
