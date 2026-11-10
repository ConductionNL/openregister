/**
 * Golden record of the statistics overview: its tables, clear buttons and
 * maintenance sections in each state, and which store action each button runs.
 *
 * @spec exclude Golden record of client-side settings section markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
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

const { normalise } = require('../../__mocks__/goldenDom.js')

/**
 * A settings store in a state.
 *
 * @param {object} state The fields to override
 * @return {object} The store
 */
function store(state = {}) {
	return reactive({
		stats: {
			warnings: {
				objectsWithoutOwner: 1,
				objectsWithoutOrganisation: 0,
				auditTrailsWithoutExpiry: 3,
				searchTrailsWithoutExpiry: 0,
				expiredAuditTrails: 5,
				expiredSearchTrails: 0,
				expiredObjects: 7,
			},
			totals: {
				totalObjects: 11,
				totalSize: 2048,
				totalBlobObjects: 12,
				totalMagicObjects: 13,
				totalMagicSize: 4096,
				totalConfigurations: 14,
				totalAuditTrails: 15,
				totalSearchTrails: 16,
				totalWebhookLogs: 17,
				deletedObjects: 18,
				totalOrganisations: 19,
				totalRegisters: 20,
				totalSchemas: 21,
				totalSources: 22,
			},
		},
		loadingStats: false,
		loading: false,
		saving: false,
		rebasing: false,
		showRebaseConfirmation: false,
		massValidating: false,
		showMassValidateConfirmation: false,
		massValidateResults: null,
		clearingAuditTrails: false,
		clearingSearchTrails: false,
		clearingBlobObjects: false,
		showClearAuditTrailsConfirmation: false,
		showClearSearchTrailsConfirmation: false,
		showClearBlobObjectsConfirmation: false,
		hasWarnings: true,
		loadStats: jest.fn(),
		showRebaseDialog: jest.fn(),
		hideRebaseDialog: jest.fn(),
		confirmRebase: jest.fn(),
		showClearAuditTrailsDialog: jest.fn(),
		showClearSearchTrailsDialog: jest.fn(),
		showClearBlobObjectsDialog: jest.fn(),
		...state,
	})
}

/**
 * Mounts the section on a store.
 *
 * @param {object} state The store fields to override
 * @return {object} The wrapper
 */
function section(state) {
	globalThis.mockSettingsStore = store(state)
	return mount(StatisticsOverview, {
		global: {
			config: { warnHandler: () => {} },
			mocks: { t: (app, text) => `t:${text}` },
		},
	})
}

/**
 * The buttons of the section with this label text.
 *
 * @param {object} wrapper The mounted section
 * @param {string} text The label
 * @return {Array} The buttons
 */
function buttons(wrapper, text) {
	return wrapper
		.findAllComponents({ name: 'NcButton' })
		.filter((button) => button.text().includes(text))
}

describe('statistics overview', () => {
	it.each([
		['with warnings and data', {}],
		[
			'while loading and busy',
			{
				loading: true,
				rebasing: true,
				massValidating: true,
				clearingAuditTrails: true,
			},
		],
		[
			'while clearing each kind',
			{ clearingBlobObjects: true, clearingSearchTrails: true },
		],
		[
			'without warnings or data',
			{
				hasWarnings: false,
				stats: {
					warnings: {
						objectsWithoutOwner: 0,
						objectsWithoutOrganisation: 2,
						auditTrailsWithoutExpiry: 0,
						searchTrailsWithoutExpiry: 4,
						expiredAuditTrails: 0,
						expiredSearchTrails: 6,
						expiredObjects: 0,
					},
					totals: {
						totalObjects: 0,
						totalSize: 0,
						totalBlobObjects: 0,
						totalMagicObjects: 0,
						totalMagicSize: 0,
						totalConfigurations: 0,
						totalAuditTrails: 0,
						totalSearchTrails: 0,
						totalWebhookLogs: 0,
						deletedObjects: 0,
						totalOrganisations: 0,
						totalRegisters: 0,
						totalSchemas: 0,
						totalSources: 0,
					},
				},
			},
		],
		[
			'with every dialog open',
			{
				showRebaseConfirmation: true,
				showClearAuditTrailsConfirmation: true,
				showClearSearchTrailsConfirmation: true,
				showClearBlobObjectsConfirmation: true,
			},
		],
	])('renders %s', (name, state) => {
		const html = normalise(section(state))
		expect(html).toContain('stats-table')
		expect(html).toMatchSnapshot()
	})

	it('runs the store action of each clear and rebase button', () => {
		const wrapper = section()
		const clears = buttons(wrapper, 'Clear All')
		expect(clears).toHaveLength(3)
		clears.forEach((button) => button.vm.$attrs.onClick())
		buttons(wrapper, 'Rebase All')[0].vm.$attrs.onClick()
		const settings = globalThis.mockSettingsStore
		expect(settings.showClearBlobObjectsDialog).toHaveBeenCalledTimes(1)
		expect(settings.showClearAuditTrailsDialog).toHaveBeenCalledTimes(1)
		expect(settings.showClearSearchTrailsDialog).toHaveBeenCalledTimes(1)
		expect(settings.showRebaseDialog).toHaveBeenCalledTimes(1)
	})
})
