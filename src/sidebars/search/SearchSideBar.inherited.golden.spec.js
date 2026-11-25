import SearchSideBar from './SearchSideBar.vue'
import { schemaStore } from '../../store/store.js'

/**
 * Golden record of how the search sidebar merges a schema's inherited (allOf)
 * properties: which parents it finds, by id, uuid or a string id, that own
 * properties win, and the key order.
 *
 * @spec openspec/changes/retrofit-2026-05-25-fe-sidebars/tasks.md#task-8
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

describe.each(Object.keys(SCHEMAS))('the sidebar schema with %s', (key) => {
	it('merges the properties', () => {
		const merged = SearchSideBar.methods.resolveInheritedProperties.call({}, SCHEMAS[key])
		expect({ merged, order: Object.keys(merged) }).toMatchSnapshot()
	})
})
