import SearchSideBar from './SearchSideBar.vue'

/**
 * Golden record of the facet request the search sidebar builds from the
 * enabled facets: metadata facets under `@self`, object facets by name,
 * each typed by its first facet type (terms by default) and a date histogram
 * bucketed by month.
 *
 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-7
 */

jest.mock('@conduction/nextcloud-vue', () => ({ CnIndexSidebar: {} }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/Filter.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Star.vue', () => ({}))
jest.mock('vue-material-design-icons/StarOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/ViewDashboardOutline.vue', () => ({}))
jest.mock('../../modals/view/DeleteView.vue', () => ({}))
jest.mock('../../modals/view/EditView.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {},
	registerStore: {},
	schemaStore: {},
	viewsStore: {},
}))


const FIELDS = {
	'@self': {
		created: { facet_types: ['date_histogram', 'range'] },
		owner: { facet_types: ['terms'] },
		register: {},
	},
	object_fields: {
		status: { facet_types: ['terms'] },
		born: { facet_types: ['date_histogram'] },
		size: { facet_types: [] },
	},
}

describe('SearchSideBar.buildFacetConfiguration', () => {
	it.each([
		['nothing enabled', {}, FIELDS],
		['every kind of facet', { '@self.created': true, '@self.owner': true, '@self.register': true, '@self.gone': true, status: true, born: true, size: true, missing: true, off: false, '@self.offToo': false }, FIELDS],
		['no facetable fields', { '@self.created': true, status: true }, null],
	])('builds the request for %s', (label, enabledFacets, facetableFields) => {
		const config = SearchSideBar.methods.buildFacetConfiguration.call({ enabledFacets, facetableFields })
		expect({ config, order: Object.keys(config._facets), selfOrder: Object.keys(config._facets['@self'] || {}) }).toMatchSnapshot()
	})
})
