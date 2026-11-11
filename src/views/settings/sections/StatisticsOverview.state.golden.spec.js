import StatisticsOverview from './StatisticsOverview.vue'

/**
 * Golden record of the statistics section's initial state: every value, its
 * key order, and that two sections never share a nested object.
 *
 * @spec exclude Golden record of client-side initial state.
 */

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
 * Every object reachable from a value.
 *
 * @param {*} value The value
 * @param {Array} found The objects found so far
 * @return {Array} The objects
 */
function containers(value, found = []) {
	if (value !== null && typeof value === 'object') {
		found.push(value)
		Object.values(value).forEach((child) => containers(child, found))
	}
	return found
}

describe('StatisticsOverview.data', () => {
	it('starts from these values, in this order', () => {
		const state = StatisticsOverview.data.call({})
		expect({ state, keys: Object.keys(state) }).toMatchSnapshot()
	})

	it('gives every section its own objects', () => {
		const first = containers(StatisticsOverview.data.call({}))
		const second = containers(StatisticsOverview.data.call({}))
		expect(first.some((object) => second.includes(object))).toBe(false)
	})
})
