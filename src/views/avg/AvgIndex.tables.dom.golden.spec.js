import { flushPromises, mount } from '@vue/test-utils'
import AvgIndex from './AvgIndex.vue'

/**
 * Golden record of the AVG screen's hand-built tables: the header row of
 * every table on every tab, as markup with the scoped-style attributes
 * stripped, so a change to how the headers are drawn shows here.
 *
 * @spec exclude Golden record of client-side AVG table markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translatePlural: (app, one) => `n:${one}`,
}))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('../../components/shared/RefreshButton.vue', () => mockComponent('RefreshButton'))
jest.mock('../../dialogs/avg/DenialComposerDialog.vue', () => mockComponent('DenialComposerDialog'))
jest.mock('../../dialogs/avg/EditActivityDialog.vue', () => mockComponent('EditActivityDialog'))
jest.mock('../../dialogs/avg/RedactionDialog.vue', () => mockComponent('RedactionDialog'))
jest.mock('../../store/modules/avg.js', () => ({
	__esModule: true,
	CASE_LIFECYCLE_TRANSITIONS: [],
	CASE_STATUS_VOCABULARY: [],
	resolveGroundOptions: () => [],
	resolveStatusLabel: (status) => `status:${status}`,
	resolveTierLabel: (tier) => `tier:${tier}`,
}))
jest.mock('vue-material-design-icons/AccountCheckOutline.vue', () => mockComponent('AccountCheckOutline'))
jest.mock('vue-material-design-icons/AccountSearch.vue', () => mockComponent('AccountSearch'))
jest.mock('vue-material-design-icons/AlertCircleOutline.vue', () => mockComponent('AlertCircleOutline'))
jest.mock('vue-material-design-icons/AlertOutline.vue', () => mockComponent('AlertOutline'))
jest.mock('vue-material-design-icons/Archive.vue', () => mockComponent('Archive'))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => mockComponent('ArrowLeft'))
jest.mock('vue-material-design-icons/BankOutline.vue', () => mockComponent('BankOutline'))
jest.mock('vue-material-design-icons/CheckCircleOutline.vue', () => mockComponent('CheckCircleOutline'))
jest.mock('vue-material-design-icons/CheckboxMarkedCircleOutline.vue', () => mockComponent('CheckboxMarkedCircleOutline'))
jest.mock('vue-material-design-icons/ClipboardTextClockOutline.vue', () => mockComponent('ClipboardTextClockOutline'))
jest.mock('vue-material-design-icons/ClockOutline.vue', () => mockComponent('ClockOutline'))
jest.mock('vue-material-design-icons/DatabaseSearchOutline.vue', () => mockComponent('DatabaseSearchOutline'))
jest.mock('vue-material-design-icons/Download.vue', () => mockComponent('Download'))
jest.mock('vue-material-design-icons/EyeOutline.vue', () => mockComponent('EyeOutline'))
jest.mock('vue-material-design-icons/FileDocumentEditOutline.vue', () => mockComponent('FileDocumentEditOutline'))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => mockComponent('FileDocumentOutline'))
jest.mock('vue-material-design-icons/Magnify.vue', () => mockComponent('Magnify'))
jest.mock('vue-material-design-icons/MagnifyClose.vue', () => mockComponent('MagnifyClose'))
jest.mock('vue-material-design-icons/Marker.vue', () => mockComponent('Marker'))
jest.mock('vue-material-design-icons/OpenInNew.vue', () => mockComponent('OpenInNew'))
jest.mock('vue-material-design-icons/Pencil.vue', () => mockComponent('Pencil'))
jest.mock('vue-material-design-icons/Plus.vue', () => mockComponent('Plus'))
jest.mock('vue-material-design-icons/ShieldAlertOutline.vue', () => mockComponent('ShieldAlertOutline'))
jest.mock('vue-material-design-icons/ShieldLockOutline.vue', () => mockComponent('ShieldLockOutline'))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => mockComponent('TrashCanOutline'))
jest.mock('../../store/store.js', () => ({ avgStore: {} }))

/**
 * A stand-in component module that draws nothing.
 *
 * @param {string} name The component
 * @return {object} The module
 */
function mockComponent(name) {
	return { __esModule: true, default: { name, render: () => null } }
}


const CASE = {
	uuid: 'c1',
	subject: 'Jan',
	type: 'access',
	status: 'received',
	evidence: [{ type: 'log', reference: 'r1' }],
	redactions: [{ field: 'name', reason: 'third party' }],
}

/**
 * The header rows of every AVG table on screen, without scoped-style attributes.
 *
 * @param {object} wrapper The mounted view
 * @return {string[]} One HTML string per table head
 */
function heads(wrapper) {
	return wrapper.findAll('table.avgTable thead')
		.map((head) => head.html().replace(/ data-v-[0-9a-f]+=""/g, ''))
}

describe('AvgIndex table headers', () => {
	let wrapper

	beforeEach(async () => {
		const store = jest.requireMock('../../store/store.js').avgStore
		Object.assign(store, {
			getActivities: [{ uuid: 'a1', name: 'Payroll', code: 'P1' }],
			getAccountability: { activities: [{ uuid: 'a1', name: 'Payroll', activity: { totalEvents: 2, byAction: {} } }] },
			getComplianceReport: {
				totals: { unannotatedSchemasWithPii: 1 },
				issues: { unannotatedSchemasWithPii: [{ schemaId: 's1', registerId: 'r1' }] },
			},
			getCases: [CASE],
			getActiveCase: CASE,
			isLoading: false,
			getError: null,
			fetchActivities: () => Promise.resolve(),
			fetchAccountability: () => Promise.resolve(),
			fetchCompliance: () => Promise.resolve(),
			fetchCases: () => Promise.resolve(),
			fetchCase: () => Promise.resolve(),
			fetchActivePolicyPack: () => Promise.resolve(),
		})
		wrapper = mount(AvgIndex, {
			global: { mocks: { t: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}` } },
		})
		await flushPromises()
	})

	it.each([
		['activities', null],
		['accountability', null],
		['compliance', null],
		['cases', null],
		['cases', 'c1'],
	])('draws the %s tab table headers (case %s)', async (tab, caseId) => {
		wrapper.vm.activeTab = tab
		wrapper.vm.selectedCaseId = caseId
		await flushPromises()
		const found = heads(wrapper)
		expect(found.length).toBeGreaterThan(0)
		expect(found).toMatchSnapshot()
	})
})
