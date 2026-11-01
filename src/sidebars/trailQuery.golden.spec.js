/**
 * Golden record of the route query the audit trail, search trail and
 * deleted-objects sidebars build from their filter state: the keys, their
 * order and their values, over register and schema selections, picked
 * filters, valid and invalid dates and the remaining filters.
 */
import DeletedSideBar from './deleted/DeletedSideBar.vue'
import AuditTrailSideBar from './logs/AuditTrailSideBar.vue'
import SearchTrailSideBar from './logs/SearchTrailSideBar.vue'

const mockStores = {
	registerStore: { registerItem: null },
	schemaStore: { schemaItem: null },
}

jest.mock(
	'../store/store.js',
	() =>
		new Proxy(
			{},
			{
				get: (_target, name) => mockStores[name] || {},
			},
		),
)
jest.mock('../eventBus.js', () => ({
	EventBus: { $on: () => {}, $off: () => {}, $emit: () => {} },
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
	t: (app, text) => text,
	n: (app, one) => one,
}))
jest.mock('vue-material-design-icons/AccountCircle.vue', () => ({}))
jest.mock('vue-material-design-icons/ChartLine.vue', () => ({}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/FilterOffOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FilterOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/MagnifyPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/Monitor.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/TrendingUp.vue', () => ({}))

const COMPONENTS = { AuditTrailSideBar, SearchTrailSideBar, DeletedSideBar }

const SCOPES = {
	none: [null, null],
	withIds: [{ id: 7 }, { id: 'abc' }],
	withoutIds: [{ title: 'r' }, { id: 0 }],
}

const STATES = {
	empty: {},
	everything: {
		selectedActions: [{ value: 'create' }, { value: 'update' }],
		selectedUsers: [{ value: 'alice' }, 'bob'],
		selectedSuccessStatus: { value: true },
		selectedDeletedBy: { value: 'carol' },
		dateFrom: '2026-01-02T03:04:05Z',
		dateTo: '2026-02-03',
		objectFilter: 'obj-1',
		showOnlyWithChanges: true,
		searchTermFilter: 'term',
		executionTimeFrom: 10,
		executionTimeTo: '20',
		resultCountFrom: 1,
		resultCountTo: 0,
	},
	invalidDates: { dateFrom: 'not a date', dateTo: 'also not' },
	emptyLists: {
		selectedActions: [],
		selectedUsers: [],
		selectedSuccessStatus: { value: 0 },
		selectedDeletedBy: {},
	},
	notLists: { selectedActions: 'create', selectedUsers: { value: 'x' } },
}

describe('sidebar route queries (golden)', () => {
	Object.entries(COMPONENTS).forEach(([name, component]) => {
		it(`${name} matches the recorded query`, () => {
			const records = {}
			for (const [scopeName, [register, schema]] of Object.entries(SCOPES)) {
				for (const [stateName, state] of Object.entries(STATES)) {
					mockStores.registerStore.registerItem = register
					mockStores.schemaStore.schemaItem = schema
					const query = component.methods.buildQueryFromState.call({
						...state,
					})
					records[`${scopeName}/${stateName}`] = JSON.stringify(query)
				}
			}
			expect(records).toMatchSnapshot()
		})
	})
})
