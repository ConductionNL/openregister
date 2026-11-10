import SearchSideBar from '../../sidebars/search/SearchSideBar.vue'
import SearchIndex from './SearchIndex.vue'
import { objectStore, schemaStore } from '../../store/store.js'

/**
 * Golden record of how the search page and its sidebar merge a schema's
 * inherited (allOf) properties: which parents they find, by id, uuid or a
 * string id, that own properties win, and that the page turns each order
 * into a number.
 *
 * @spec exclude Golden record of client-side schema property merging.
 */

jest.mock('@conduction/nextcloud-vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, one, many) => many }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentCopy.vue', () => ({}))
jest.mock('vue-material-design-icons/ContentSaveOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseExport.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/Filter.vue', () => ({}))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({}))
jest.mock('vue-material-design-icons/Merge.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Star.vue', () => ({}))
jest.mock('vue-material-design-icons/StarOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/ViewDashboardOutline.vue', () => ({}))
jest.mock('../../components/shared/SelectOption.vue', () => ({}))
jest.mock('../../components/tables/EditableCell.vue', () => ({}))
jest.mock('../../modals/view/DeleteView.vue', () => ({}))
jest.mock('../../modals/view/EditView.vue', () => ({}))
jest.mock('../../services/initialState.js', () => ({}))
jest.mock('../../services/objectCopy.js', () => ({}))
jest.mock('../../services/propertyEditor.js', () => ({}))
jest.mock('../../sidebars/scopeListLoader.js', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {},
	registerStore: {},
	schemaStore: {},
	viewsStore: {},
}))

const PARENTS = [
	{ id: 1, uuid: 'u-1', properties: { name: { type: 'string', order: '2' }, shared: { from: 'parent-1' } } },
	{ id: 2, uuid: 'u-2', properties: { age: { type: 'integer' } } },
	{ id: 3, uuid: 'u-3' },
	{ id: 4, uuid: 'u-4', properties: { shared: { from: 'parent-4' }, city: { order: 1 } } },
]

const SCHEMAS = {
	byEveryReference: { allOf: [{ id: 1 }, 'u-2', 3, '4'], properties: { shared: { from: 'own', order: '0' }, own: { order: 5 } } },
	unknownParent: { allOf: ['nope'], properties: { own: {} } },
	noAllOf: { properties: { own: { order: '7' } } },
	noProperties: { allOf: [1] },
	none: null,
}

beforeEach(() => {
	schemaStore.schemaList = PARENTS
})

describe.each(Object.keys(SCHEMAS))('a schema with %s', (key) => {
	it('merges into the sidebar properties', () => {
		const merged = SearchSideBar.methods.resolveInheritedProperties.call({}, SCHEMAS[key])
		expect({ merged, order: Object.keys(merged) }).toMatchSnapshot()
	})

	it('normalizes into the page schema', () => {
		objectStore.searchSchema = SCHEMAS[key]
		const normalized = SearchIndex.computed.normalizedSchema.call({})
		expect({ normalized, order: normalized ? Object.keys(normalized.properties || {}) : null, same: normalized === SCHEMAS[key] }).toMatchSnapshot()
	})
})
