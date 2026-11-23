import SearchIndex from './SearchIndex.vue'
import { objectStore, schemaStore } from '../../store/store.js'

/**
 * Golden record of how the search page merges a schema's inherited (allOf)
 * properties for its columns: which parents it finds, by id, uuid or a string
 * id, that own properties win, the key order, and that each order becomes a
 * number.
 *
 * @spec exclude Golden record of client-side schema property merging.
 */

jest.mock('../../store/store.js', () => ({
	__esModule: true,
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn(), modal: null },
	objectStore: {},
	registerStore: { registerList: [] },
	schemaStore: { schemaList: [] },
	viewsStore: {},
}))
// The inline cell editor has its own spec; here it only has to resolve.
jest.mock('../../components/tables/EditableCell.vue', () => ({
	__esModule: true,
	default: { name: 'EditableCell', render: () => null },
}))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (_app, text) => text,
}))
jest.mock(
	'@nextcloud/vue',
	() =>
		new Proxy(
			{ __esModule: true },
			{
				get: (target, prop) =>
					prop in target
						? target[prop]
						: { name: String(prop), render: () => null },
			},
		),
)
jest.mock(
	'vue-material-design-icons/Pencil.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/ContentCopy.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/TrashCanOutline.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)

jest.mock(
	'vue-material-design-icons/CheckCircle.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/DatabaseExport.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)
jest.mock(
	'vue-material-design-icons/Merge.vue',
	() => ({ __esModule: true, default: { name: 'icon', render: () => null } }),
	{ virtual: true },
)


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

describe.each(Object.keys(SCHEMAS))('the search page schema with %s', (key) => {
	it('merges and normalizes the properties', () => {
		objectStore.searchSchema = SCHEMAS[key]
		const normalized = SearchIndex.computed.normalizedSchema.call({})
		expect({ normalized, order: normalized ? Object.keys(normalized.properties || {}) : null, same: normalized === SCHEMAS[key] }).toMatchSnapshot()
	})
})
