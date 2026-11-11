import DeletedIndex from './DeletedIndex.vue'

/**
 * Golden record of how the deleted-objects page turns its filters into the
 * API's filter parameters: which keys, in which order, and the deletion date
 * range from either or both dates.
 *
 * @spec exclude Golden record of client-side filter mapping.
 */

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translatePlural: (app, one, many, count) => `n:${count}:${many}`,
}))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
// The page mixes <script setup> and an options block; its compiled template
// reads the library components through 'vue', as listActions.dom.golden.spec.js notes.
jest.mock('vue', () => {
	const nc = jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary()
	const names = ['NcActionButton', 'NcActions', 'NcAppContent', 'NcCheckboxRadioSwitch', 'NcEmptyContent', 'NcLoadingIcon']
	return { ...jest.requireActual('vue'), ...Object.fromEntries(names.map((name) => [name, nc[name]])) }
})
jest.mock('@conduction/nextcloud-vue', () => ({
	__esModule: true,
	CnDataTable: jest.requireActual('../../../tests/unit/support/tableGolden.js').CnDataTableStub,
}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({ __esModule: true, default: { name: 'Delete', render: () => null } }))
jest.mock('vue-material-design-icons/DeleteEmpty.vue', () => ({ __esModule: true, default: { name: 'DeleteEmpty', render: () => null } }))
jest.mock('vue-material-design-icons/FormatListChecks.vue', () => ({ __esModule: true, default: { name: 'FormatListChecks', render: () => null } }))
jest.mock('vue-material-design-icons/Restore.vue', () => ({ __esModule: true, default: { name: 'Restore', render: () => null } }))
jest.mock('../../components/shared/ActionMenu.vue', () => ({
	__esModule: true,
	default: {
		name: 'ActionMenu',
		props: ['actions'],
		render() {
			const { h } = jest.requireActual('vue')
			return h('menu', this.actions.map((a) => h('button', { class: 'menuItem', onClick: (event) => { event.stopPropagation(); a.run() } }, a.label)))
		},
	},
}))
jest.mock('../../components/shared/ListActionsBar.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../components/PaginationComponent.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../services/menuActions.js', () => ({ refreshAction: () => ({ label: 'Refresh' }) }))
jest.mock('../../eventBus.js', () => ({ __esModule: true, default: { on: () => {}, off: () => {}, emit: () => {} } }))
jest.mock('../../store/store.js', () => {
	const { reactive } = jest.requireActual('vue')
	return {
		deletedStore: reactive({
			deletedList: [],
			deletedLoading: false,
			deletedPagination: { total: 0, page: 1, pages: 1, limit: 20 },
			fetchDeleted: () => Promise.resolve(),
			setSelectedForBulkAction: jest.fn(),
		}),
		navigationStore: { setDialog: jest.fn() },
		registerStore: reactive({ registerList: [{ id: 4, title: 'Zaken' }], refreshRegisterList: () => Promise.resolve() }),
		schemaStore: reactive({ schemaList: [{ id: 7, title: 'Zaak' }], refreshSchemaList: () => Promise.resolve() }),
	}
})


describe('DeletedIndex.convertFiltersToApiFormat', () => {
	it.each([
		['no filters', {}],
		['every filter', { register: 1, schema: 2, deletedBy: 'alice', dateFrom: '2026-01-01', dateTo: '2026-02-01' }],
		['only a start date', { dateFrom: '2026-01-01' }],
		['only an end date', { dateTo: '2026-02-01' }],
		['empty values', { register: '', schema: 0, deletedBy: null, dateFrom: '', dateTo: undefined }],
		['filters in another order', { dateTo: 'b', deletedBy: 'x', dateFrom: 'a', register: 9 }],
	])('maps %s', (label, filters) => {
		const apiFilters = DeletedIndex.methods.convertFiltersToApiFormat.call({}, filters)
		expect({ apiFilters, order: Object.keys(apiFilters), range: Object.keys(apiFilters['@self.deleted.deleted'] || {}) }).toMatchSnapshot()
	})
})
