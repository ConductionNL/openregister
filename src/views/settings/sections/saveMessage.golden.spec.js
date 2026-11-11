/**
 * Golden record of the transient save message of the file configuration and
 * API token sections: it shows the message with its type and clears the text
 * after three seconds, leaving the type as it was.
 *
 * @spec exclude Golden record of a client-side transient message.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
const { stubModule } = require('../../__mocks__/goldenDom.js')

const ICONS = [
	'AlertCircle', 'Check', 'CheckCircle', 'ContentSave', 'Delete', 'DotsVertical', 'FileDocument', 'Github', 'Gitlab',
	'Information', 'InformationOutline', 'Key', 'LockOutline', 'Magnify', 'Refresh', 'TestTube', 'Web',
]
ICONS.forEach((name) => jest.mock(`vue-material-design-icons/${name}.vue`, () => stubModule(name)))
jest.mock('@nextcloud/vue', () => require('../../__mocks__/goldenDom.js').library())
jest.mock('../../../components/shared/SettingsCard.vue', () => require('../../__mocks__/goldenDom.js').stubModule('SettingsCard'))
jest.mock('../../../components/shared/SettingsSection.vue', () => require('../../__mocks__/goldenDom.js').stubModule('SettingsSection'))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn(), put: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ __esModule: true, showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (url) => url }))
jest.mock('../../../store/settings.js', () => ({
	useSettingsStore: Object.assign(() => ({}), { $id: 'settings' }),
}))

// Required after the icon mocks above, which are not hoisted over an import.
describe.each([
	['ApiTokenConfiguration', () => require('./ApiTokenConfiguration.vue').default],
	['FileConfiguration', () => require('./FileConfiguration.vue').default],
])('%s save message', (name, load) => {
	const component = load()

	beforeEach(() => {
		jest.useFakeTimers()
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	it('shows a success message by default and clears its text after three seconds', () => {
		const view = { saveMessage: '', saveMessageType: '' }
		component.methods.showSaveMessage.call(view, 'Saved')
		expect(view).toEqual({ saveMessage: 'Saved', saveMessageType: 'success' })
		jest.advanceTimersByTime(2999)
		expect(view.saveMessage).toBe('Saved')
		jest.advanceTimersByTime(1)
		expect(view).toEqual({ saveMessage: '', saveMessageType: 'success' })
	})

	it('keeps the type it is given', () => {
		const view = { saveMessage: '', saveMessageType: '' }
		component.methods.showSaveMessage.call(view, 'Failed', 'error')
		expect(view).toEqual({ saveMessage: 'Failed', saveMessageType: 'error' })
	})
})
