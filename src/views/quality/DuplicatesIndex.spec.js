/**
 * DuplicatesIndex opened from a link: `?register=&schema=` as ids or slugs
 * loads that pair's candidates with no picking, and an unknown value loads
 * nothing and says which value it did not know.
 *
 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#scenario-an-app-sends-the-steward-to-the-duplicates-of-one-schema
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import DuplicatesIndex from './DuplicatesIndex.vue'
import { qualityStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => text }))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('vue-material-design-icons/ContentDuplicate.vue', () => ({ name: 'ContentDuplicate', render: () => null }))
jest.mock('../../modals/mdm/MdmMergeWizardModal.vue', () => ({ name: 'MdmMergeWizardModal', render: () => null }))
jest.mock('../../store/store.js', () => {
	const { reactive } = require('vue')
	const store = reactive({
		registers: [],
		schemas: [],
		selectedRegister: null,
		selectedSchema: null,
		isLoading: false,
		duplicates: [],
		duplicatesTotal: 0,
		duplicatesLimit: 20,
		duplicatesOffset: 0,
		get hasSelection() {
			return Boolean(this.selectedRegister && this.selectedSchema)
		},
		fetchRegisters: jest.fn(),
		fetchSchemasForRegister: jest.fn(),
		fetchDuplicates: jest.fn(),
		setSelection: jest.fn(),
	})
	return { __esModule: true, qualityStore: store }
})

const REGISTERS = [{ id: 7, title: 'Catalogue', slug: 'catalogus' }, { id: 8, title: 'Other', slug: 'other' }]
const SCHEMAS = [{ id: 41, title: 'Module', slug: 'module' }, { id: 42, title: 'Suite', slug: 'suite' }]

// Every page mounted by a test, unmounted after it so its selection watcher
// does not reload when the next test resets the store.
const mounted = []

/**
 * Mount the page on a route query.
 *
 * @param {object} query The route query.
 * @return {Promise<object>} The wrapper.
 */
async function openWith(query) {
	const wrapper = mount(DuplicatesIndex, {
		global: {
			mocks: {
				$route: { query },
				$router: { replace: jest.fn(() => Promise.resolve()) },
				t: (app, text) => text,
			},
		},
	})
	mounted.push(wrapper)
	await flushPromises()
	return wrapper
}

afterEach(() => {
	mounted.splice(0).forEach((wrapper) => wrapper.unmount())
})

describe('DuplicatesIndex deep link', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		Object.assign(qualityStore, { registers: [], schemas: [], selectedRegister: null, selectedSchema: null, duplicates: [] })
		qualityStore.fetchRegisters.mockImplementation(async () => {
			qualityStore.registers = REGISTERS
		})
		qualityStore.fetchSchemasForRegister.mockImplementation(async () => {
			qualityStore.schemas = SCHEMAS
		})
		qualityStore.setSelection.mockImplementation((register, schema) => {
			qualityStore.selectedRegister = register
			qualityStore.selectedSchema = schema
		})
		qualityStore.fetchDuplicates.mockResolvedValue({})
	})

	it.each([
		['slugs', { register: 'catalogus', schema: 'module' }],
		['ids', { register: '7', schema: '41' }],
	])('loads the candidate pairs of the pair named by %s', async (name, query) => {
		const wrapper = await openWith(query)
		expect(qualityStore.fetchDuplicates).toHaveBeenCalledTimes(1)
		expect(qualityStore.fetchDuplicates).toHaveBeenCalledWith(7, 41, { limit: 20, offset: 0 })
		expect(wrapper.find('[data-testid="mdm-unknown-register"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="mdm-unknown-schema"]').exists()).toBe(false)
	})

	it('loads nothing for an unknown register and names it', async () => {
		const wrapper = await openWith({ register: 'kataloog', schema: 'module' })
		expect(qualityStore.fetchDuplicates).not.toHaveBeenCalled()
		expect(qualityStore.fetchSchemasForRegister).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="mdm-unknown-register"]').text()).toContain('kataloog')
		expect(wrapper.text()).toContain('Select a register and schema')
	})

	it('loads nothing for an unknown schema and names it, with the register kept', async () => {
		const wrapper = await openWith({ register: 'catalogus', schema: 'modules' })
		expect(qualityStore.fetchDuplicates).not.toHaveBeenCalled()
		expect(qualityStore.selectedRegister).toBe(7)
		expect(qualityStore.selectedSchema).toBeNull()
		expect(wrapper.find('[data-testid="mdm-unknown-schema"]').text()).toContain('modules')
		expect(wrapper.find('[data-testid="mdm-unknown-register"]').exists()).toBe(false)
	})
})
