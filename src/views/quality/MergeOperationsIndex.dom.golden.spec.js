import { flushPromises, mount } from '@vue/test-utils'
import MergeOperationsIndex from './MergeOperationsIndex.vue'

/**
 * Golden record of the merge-operations table: its header row and its rows,
 * as markup with the scoped-style attributes stripped.
 *
 * @spec exclude Golden record of client-side merge-operations table markup.
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
jest.mock('vue-material-design-icons/Merge.vue', () => ({ __esModule: true, default: { name: 'Merge', render: () => null } }))
jest.mock('./RangePager.vue', () => ({ __esModule: true, default: { name: 'RangePager', render: () => null } }))
jest.mock('../../store/store.js', () => ({
	qualityStore: {
		isLoading: false,
		mergeOperations: [
			{ id: 1, into: 'a', from: 'b', reason: 'same', mergedAt: '2026-01-02', reversible: true },
			{ id: 2, into: 'c', from: 'd' },
		],
		mergeOperationsTotal: 2,
		mergeOperationsLimit: 20,
		mergeOperationsOffset: 0,
		fetchMergeOperations: () => Promise.resolve(),
	},
}))

describe('MergeOperationsIndex table', () => {
	it('draws the header row and a row per merge operation', async () => {
		const wrapper = mount(MergeOperationsIndex, {
			global: { mocks: { t: (app, text) => `t:${text}` } },
		})
		await flushPromises()
		expect(wrapper.find('table.mergeOperationsTable').html().replace(/ data-v-[0-9a-f]+=""/g, '')).toMatchSnapshot()
	})
})
