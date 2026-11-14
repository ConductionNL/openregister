/**
 * The search trail page picks out the searches that found nothing: a row
 * whose search returned no results is marked `failed`, a row with results is
 * marked `success` and never `failed`.
 *
 * @spec openspec/changes/capability-rows-verified-a/specs/search-trail-recording/spec.md
 */
import { mount } from '@vue/test-utils'
import SearchTrailIndex from './SearchTrailIndex.vue'

jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: () => {}, setDialog: () => {} },
	searchTrailStore: {
		searchTrailLoading: false,
		searchTrailFilters: {},
		searchTrailPagination: { total: 2, limit: 20, page: 1, pages: 1 },
		searchTrailList: [
			{ id: 1, searchTerm: 'afvalkalender', totalResults: 0, created: '2026-11-12T10:00:00Z' },
			{ id: 2, searchTerm: 'paspoort', totalResults: 12, created: '2026-11-12T10:01:00Z' },
		],
		fetchSearchTrails: () => Promise.resolve(),
		refreshSearchTrailList: () => Promise.resolve(),
	},
}))
jest.mock('../../eventBus.js', () => ({ on: () => {}, off: () => {}, emit: () => {} }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, singular) => singular,
}))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
// The page mixes <script setup> and an options block; its compiled template
// reads the library components through 'vue' (see listActions.dom.golden.spec.js).
jest.mock('vue', () => {
	const nc = jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary()
	const names = ['NcAppContent', 'NcCheckboxRadioSwitch', 'NcDateTime', 'NcEmptyContent', 'NcLoadingIcon']
	return { ...jest.requireActual('vue'), ...Object.fromEntries(names.map((name) => [name, nc[name]])) }
})
jest.mock('../../components/PaginationComponent.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../components/shared/ActionMenu.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../components/shared/ListActionsBar.vue', () => ({ __esModule: true, default: { render: () => null } }))

/**
 * The table row of one search term.
 *
 * @param {object} wrapper The mounted page.
 * @param {string} term The search term.
 * @return {object} The row wrapper.
 */
function rowOf(wrapper, term) {
	return wrapper
		.findAll('tr.searchTrailRow')
		.find((row) => row.text().includes(term))
}

describe('SearchTrailIndex empty searches', () => {
	it('marks the search that found nothing and not the one that found results', () => {
		const wrapper = mount(SearchTrailIndex, {
			global: { config: { warnHandler: () => {} } },
		})

		const empty = rowOf(wrapper, 'afvalkalender')
		const found = rowOf(wrapper, 'paspoort')

		expect(empty.classes()).toContain('failed')
		expect(empty.classes()).not.toContain('success')
		expect(found.classes()).toContain('success')
		expect(found.classes()).not.toContain('failed')
	})
})
