import { flushPromises, mount } from '@vue/test-utils'
import MergeOperationsIndex from './MergeOperationsIndex.vue'

/**
 * The merge-operations list names the survivor and the merged objects as the
 * stored `mergeOperation` object records them.
 *
 * @spec openspec/changes/mdm-merge-relinks-every-reference/tasks.md#task-3.1
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text) => text,
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('vue-material-design-icons/Merge.vue', () => ({ __esModule: true, default: { name: 'Merge', render: () => null } }))
jest.mock('./RangePager.vue', () => ({ __esModule: true, default: { name: 'RangePager', render: () => null } }))
jest.mock('../../store/store.js', () => ({
	qualityStore: {
		isLoading: false,
		mergeOperations: [
			{
				id: 'op-1',
				mergedIntoUuid: 'survivor-uuid',
				mergedFromUuids: ['merged-uuid', 'other-merged-uuid'],
				reason: 'duplicate-confirmed',
				mergedAt: '2026-11-25T23:45:32+00:00',
				reversible: true,
			},
		],
		mergeOperationsTotal: 1,
		mergeOperationsLimit: 20,
		mergeOperationsOffset: 0,
		fetchMergeOperations: () => Promise.resolve(),
	},
}))

describe('MergeOperationsIndex', () => {
	it('names the survivor and the merged objects from the stored operation', async () => {
		const wrapper = mount(MergeOperationsIndex, {
			global: { mocks: { t: (app, text) => text } },
		})
		await flushPromises()
		const cells = wrapper.find('[data-testid="mdm-merge-operation-row"]').findAll('td')
		expect(cells[0].text()).toBe('survivor-uuid')
		expect(cells[1].text()).toBe('merged-uuid, other-merged-uuid')
	})
})
