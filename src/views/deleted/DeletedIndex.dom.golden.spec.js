import { mount } from '@vue/test-utils'
import DeletedIndex from './DeletedIndex.vue'
import { tableText } from '../../../tests/unit/support/tableGolden.js'

/**
 * Golden record of the soft-deleted items table: what it shows (header
 * labels and cell texts, independent of the markup that draws it), its row
 * menu, the empty state, and how a row click and the checkboxes select.
 *
 * @spec exclude Golden record of the client-side deleted items table.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
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

const ITEMS = [
	{
		id: 'd1',
		title: 'First',
		description: 'Gone by mistake',
		'@self': { register: 4, schema: 7, deleted: { deleted: '2026-11-01T10:05:00', deletedBy: 'admin' } },
		deletionWindow: { destroyableFrom: '2026-12-01T10:05:00', daysRemaining: 23 },
	},
	{ id: 'd2', object: { name: 'Second' }, '@self': { register: 9, deleted: { purgeDate: '2026-11-08T00:00:00' } }, deletionWindow: { daysRemaining: 0 } },
	{ id: 'd3', '@self': {} },
]

/**
 * Mount the page over these trash rows.
 *
 * @param {Array} items The rows the store holds
 * @return {object} The wrapper
 */
function mounted(items) {
	const { deletedStore } = jest.requireMock('../../store/store.js')
	deletedStore.deletedList = items
	deletedStore.deletedPagination.total = items.length
	return mount(DeletedIndex)
}

describe('DeletedIndex table', () => {
	beforeEach(() => jest.clearAllMocks())

	it('shows every deleted item with its window and menu', () => {
		const wrapper = mounted(ITEMS)
		expect(tableText(wrapper)).toMatchSnapshot()
	})

	it('shows the empty state without items', () => {
		const wrapper = mounted([])
		expect(wrapper.findAll('table')).toHaveLength(0)
		expect(wrapper.find('[data-stub="empty"]').text()).toMatchSnapshot()
	})

	it('selects a row by clicking it, and opens the dialogs from its menu', async () => {
		const wrapper = mounted(ITEMS)
		await wrapper.findAll('tbody tr')[1].trigger('click')
		expect(wrapper.vm.selectedItems).toEqual(['d2'])
		await wrapper.findAll('tbody tr')[1].trigger('click')
		expect(wrapper.vm.selectedItems).toEqual([])

		const { deletedStore, navigationStore } = jest.requireMock('../../store/store.js')
		await wrapper.findAll('.menuItem')[1].trigger('click')
		expect(deletedStore.setSelectedForBulkAction).toHaveBeenCalledWith([ITEMS[0]])
		expect(navigationStore.setDialog).toHaveBeenCalledWith('permanentlyDeleteMultiple')
		expect(wrapper.vm.selectedItems).toEqual([])
	})
})
