/**
 * Golden record of the state the file configuration section starts from: the
 * file types with their default flags, the extractors, scopes and modes.
 *
 * @spec exclude Golden record of client-side settings form defaults.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import FileConfiguration from './FileConfiguration.vue'

jest.mock('@nextcloud/vue', () => require('../../__mocks__/goldenDom.js').library())
jest.mock('vue-material-design-icons/AlertCircle.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('AlertCircle'),
)
jest.mock('vue-material-design-icons/Check.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('Check'),
)
jest.mock('vue-material-design-icons/DotsVertical.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('DotsVertical'),
)
jest.mock('vue-material-design-icons/FileDocument.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('FileDocument'),
)
jest.mock('vue-material-design-icons/Information.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('Information'),
)
jest.mock('vue-material-design-icons/Key.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('Key'),
)
jest.mock('vue-material-design-icons/Magnify.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('Magnify'),
)
jest.mock('vue-material-design-icons/Refresh.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('Refresh'),
)
jest.mock('../../../components/shared/LabeledOption.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('LabeledOption'),
)
jest.mock('../../../components/shared/SettingsCard.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('SettingsCard'),
)
jest.mock('../../../components/shared/SettingsSection.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('SettingsSection'),
)
jest.mock('../../../store/settings.js', () => ({
	useSettingsStore: Object.assign(() => ({}), { $id: 'settings' }),
}))

describe('file configuration initial state', () => {
	it('starts from the recorded defaults', () => {
		expect(FileConfiguration.data.call({})).toMatchSnapshot()
	})

	it('gives every instance its own file types', () => {
		const first = FileConfiguration.data.call({})
		const second = FileConfiguration.data.call({})
		first.fileTypes[0].enabled = false
		expect(second.fileTypes[0].enabled).toBe(true)
		expect(first.fileTypes).toHaveLength(second.fileTypes.length)
	})
})
