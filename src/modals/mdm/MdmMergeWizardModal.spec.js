/**
 * MdmMergeWizardModal: the preview lists the references that will move onto
 * the survivor by schema and count, and shows no list when nothing moves.
 *
 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import MdmMergeWizardModal from './MdmMergeWizardModal.vue'
import { qualityStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => text }))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('../../components/shared/BusyButton.vue', () => ({ name: 'BusyButton', render: () => null }))
jest.mock('../../store/store.js', () => ({
	__esModule: true,
	qualityStore: {
		schemas: [],
		registers: [],
		previewMerge: jest.fn(),
		executeMerge: jest.fn(),
	},
}))

/**
 * Open the wizard on a preview.
 *
 * @param {object} preview The preview the server answers.
 * @return {Promise<object>} The wrapper.
 */
async function openOn(preview) {
	qualityStore.previewMerge.mockResolvedValue(preview)
	const wrapper = mount(MdmMergeWizardModal, {
		props: { from: 'copy', into: 'original' },
		global: { mocks: { t: (app, text) => text } },
	})
	await flushPromises()
	return wrapper
}

/**
 * The reference rows as [schema, count] text pairs.
 *
 * @param {object} wrapper The wrapper.
 * @return {Array<Array<string>>} The rows.
 */
function referenceRows(wrapper) {
	return wrapper
		.findAll('[data-testid="mdm-merge-reference-row"]')
		.map((row) => row.findAll('td').map((cell) => cell.text()))
}

describe('MdmMergeWizardModal references', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		qualityStore.schemas = [{ id: 41, title: 'Module' }]
		qualityStore.registers = [{ id: 7, schemas: [{ id: 42, title: 'Suite' }, 43] }]
	})

	it('lists the references that move by schema and count, by title where known', async () => {
		const wrapper = await openOn({
			postMergeGoldenRecord: { name: 'Zaaksysteem' },
			references: [
				{ schema: '42', count: 1 },
				{ schema: '41', count: 3 },
				{ schema: '99', count: 2 },
				{ schema: '50', count: 0 },
			],
		})
		expect(qualityStore.previewMerge).toHaveBeenCalledWith('copy', 'original')
		expect(wrapper.text()).toContain('Referenced by')
		expect(referenceRows(wrapper)).toEqual([['Suite', '1'], ['Module', '3'], ['99', '2']])
	})

	it.each([
		['an empty list', { postMergeGoldenRecord: {}, references: [] }],
		['no list', { postMergeGoldenRecord: {} }],
	])('shows no reference list for %s', async (name, preview) => {
		const wrapper = await openOn(preview)
		expect(wrapper.find('[data-testid="mdm-merge-references"]').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('Referenced by')
	})
})
