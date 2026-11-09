/**
 * Golden record of the file configuration selects: each select's attributes
 * and the option it draws through LabeledOption, with or without an icon.
 *
 * @spec exclude Golden record of client-side settings select markup.
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
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => path }))
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

describe('file configuration selects', () => {
	it('draws every select and its option the same way', async () => {
		// The store is a stand-in, so the section logs that it could not load.
		jest.spyOn(console, 'error').mockImplementation(() => {})
		const wrapper = mount(FileConfiguration, {
			global: { config: { globalProperties: { t: (app, text) => `[${text}]`, n: (app, one, many) => many } } },
		})
		await flushPromises()
		await wrapper.setData({ fileSettings: { ...wrapper.vm.fileSettings, entityRecognitionEnabled: true, piiDetectionEnabled: true } })
		const selects = wrapper.findAll('x-NcSelect')
		expect(selects.length).toBeGreaterThan(0)
		expect(selects.map((select) => normalise(select))).toMatchSnapshot()
	})

	it('writes a picked option back through v-model', async () => {
		jest.spyOn(console, 'error').mockImplementation(() => {})
		const wrapper = mount(FileConfiguration, {
			global: { config: { globalProperties: { t: (app, text) => `[${text}]`, n: (app, one, many) => many } } },
		})
		await flushPromises()
		const picked = wrapper.vm.extractionScopes[0]
		const select = wrapper.findAllComponents({ name: 'NcSelect' })
			.find((one) => one.attributes('inputid') === 'extraction-scope')
		select.vm.$emit('update:modelValue', picked)
		await flushPromises()
		expect(wrapper.vm.fileSettings.extractionScope).toEqual(picked)
	})
})
