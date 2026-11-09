import { mount } from '@vue/test-utils'
/**
 * Golden record of the import summary table the register import modal renders
 * after an import: one row per sheet with its status notes and counts, and the
 * error details table under it when expanded.
 *
 * @spec exclude Golden record of client-side import summary markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import ImportRegister from './ImportRegister.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `t:${text}`,
	translatePlural: (app, one) => one,
}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'importRegister', setModal: jest.fn() },
	dashboardStore: { preload: jest.fn() },
	objectStore: { refreshObjectList: jest.fn() },
	registerStore: { registerItem: null, registerList: [], refreshRegisterList: () => Promise.resolve() },
	schemaStore: { schemaItem: null, schemaList: [], refreshSchemaList: () => Promise.resolve() },
}))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('vue-material-design-icons/ChevronDown.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Import.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('vue-material-design-icons/Upload.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../components/shared/CloseCancelButton.vue', () => ({ __esModule: true, default: { name: 'CloseCancelButton', render: () => null } }))

const RESULTS = {
	Missing: { found: 2, errors: [{ type: 'MissingIdColumnException', row: 1, error: 'no id' }] },
	BadUuid: { found: 1, errors: [{ type: 'InvalidUuidException', row: 2, error: 'bad', data: { id: 'x' } }] },
	Invalid: { found: 3, created: [1], errors: [{ type: 'ValidationException', row: 3, error: 'nope', debug: { file: 'f', line: 4, class: 'C' } }], schema: { title: 'Person' } },
	Empty: { found: 0, errors: [], debug: { headers: ['a', 'b'], schemaProperties: ['c'] } },
	NoMatch: { found: 0, errors: [{ type: 'Other', row: 5, error: 'cache miss' }] },
	Good: { found: 4, created: [1, 2], updated: [3], unchanged: [4] },
}

describe('ImportRegister import summary', () => {
	it('renders the summary rows and every expanded error table', async () => {
		const wrapper = mount(withStubs(ImportRegister))
		Object.assign(wrapper.vm, { success: true, importSummary: RESULTS, importResults: RESULTS, expandedErrors: { Missing: true, BadUuid: true, Invalid: true, NoMatch: true } })
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.importResults').html()).toMatchSnapshot()
	})
})

/**
 * A stand-in that renders its tag, attributes and slots.
 *
 * @param {string} name The component name
 * @return {object} The stand-in
 */
function mockStub(name) {
	const { h } = require('vue')
	return {
		name,
		inheritAttrs: false,
		setup(props, { attrs, slots }) {
			return () => h(`x-${name}`, attrs, Object.entries(slots).map(([slot, fill]) => h(`slot-${slot}`, fill())))
		},
	}
}

/**
 * The modal with its setup bindings replaced by stand-ins: under jest the
 * compiled bindings of an SFC that imports from both 'vue' and
 * '@nextcloud/vue' resolve the library components to undefined.
 *
 * @param {object} component The modal
 * @return {object} The modal with stand-in bindings
 */
function withStubs(component) {
	const stores = jest.requireMock('../../store/store.js')
	const names = ['NcDialog', 'NcButton', 'NcCheckboxRadioSwitch', 'NcLoadingIcon', 'NcNoteCard', 'NcSelect', 'ChevronDown', 'Import', 'Upload', 'CloseCancelButton']
	const bindings = {
		...Object.fromEntries(names.map((name) => [name, mockStub(name)])),
		...stores,
		t: jest.requireMock('@nextcloud/l10n').translate,
	}
	return { ...component, setup: () => bindings }
}
