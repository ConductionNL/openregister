import { flushPromises, mount } from '@vue/test-utils'
import ConfigurationsIndex from './ConfigurationsIndex.vue'
import { tableText } from '../../../tests/unit/support/tableGolden.js'

/**
 * Golden record of the configurations table view: what it shows (header
 * labels and cell texts, independent of the markup that draws it), its row
 * menu, the empty state, and the selection.
 *
 * @spec exclude Golden record of the client-side configurations table.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translatePlural: (app, one, many, count) => `n:${count}:${many}`,
}))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
// The page mixes <script setup> and an options block; its compiled template
// reads the library components through 'vue', as listActions.dom.golden.spec.js notes.
jest.mock('vue', () => {
	const nc = jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary()
	const names = ['NcActionButton', 'NcActions', 'NcAppContent', 'NcCheckboxRadioSwitch', 'NcEmptyContent', 'NcLoadingIcon']
	return { ...jest.requireActual('vue'), ...Object.fromEntries(names.map((name) => [name, nc[name]])) }
})
jest.mock('@conduction/nextcloud-vue', () => {
	const { CnDataTableStub } = jest.requireActual('../../../tests/unit/support/tableGolden.js')
	// With rowClickToView a row click navigates (row-click) instead of selecting,
	// as CnDataTable does; the stand-in's only row-driven emit is that select.
	return {
		__esModule: true,
		CnDataTable: {
			...CnDataTableStub,
			props: { ...CnDataTableStub.props, rowClickToView: { type: Boolean, default: false } },
			render() {
				const view = new Proxy(this, {
					get: (target, key) => (key === '$emit' && target.rowClickToView
						? (event, ...args) => target.$emit(event === 'select' ? 'row-click' : event, ...args)
						: target[key]),
				})
				return CnDataTableStub.render.call(view)
			},
		},
	}
})
jest.mock('vue-material-design-icons/AlertCircle.vue', () => ({ __esModule: true, default: { name: 'AlertCircle', render: () => null } }))
jest.mock('vue-material-design-icons/ApplicationCog.vue', () => ({ __esModule: true, default: { name: 'ApplicationCog', render: () => null } }))
jest.mock('vue-material-design-icons/CheckCircle.vue', () => ({ __esModule: true, default: { name: 'CheckCircle', render: () => null } }))
jest.mock('vue-material-design-icons/ClockOutline.vue', () => ({ __esModule: true, default: { name: 'ClockOutline', render: () => null } }))
jest.mock('vue-material-design-icons/Cloud.vue', () => ({ __esModule: true, default: { name: 'Cloud', render: () => null } }))
jest.mock('vue-material-design-icons/CloudUpload.vue', () => ({ __esModule: true, default: { name: 'CloudUpload', render: () => null } }))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({ __esModule: true, default: { name: 'CogOutline', render: () => null } }))
jest.mock('vue-material-design-icons/Download.vue', () => ({ __esModule: true, default: { name: 'Download', render: () => null } }))
jest.mock('vue-material-design-icons/EyeOutline.vue', () => ({ __esModule: true, default: { name: 'EyeOutline', render: () => null } }))
jest.mock('vue-material-design-icons/Plus.vue', () => ({ __esModule: true, default: { name: 'Plus', render: () => null } }))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({ __esModule: true, default: { name: 'Refresh', render: () => null } }))
jest.mock('vue-material-design-icons/Sync.vue', () => ({ __esModule: true, default: { name: 'Sync', render: () => null } }))
jest.mock('vue-material-design-icons/Update.vue', () => ({ __esModule: true, default: { name: 'Update', render: () => null } }))
jest.mock('vue-material-design-icons/Eye.vue', () => ({ __esModule: true, default: { name: 'Eye', render: () => null } }))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({ __esModule: true, default: { name: 'Pencil', render: () => null } }))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({ __esModule: true, default: { name: 'TrashCanOutline', render: () => null } }))
jest.mock('../../components/shared/ActionMenu.vue', () => ({
	__esModule: true,
	default: {
		name: 'ActionMenu',
		props: ['actions'],
		render() {
			const { h } = jest.requireActual('vue')
			return h('menu', this.actions.filter((a) => !a.hidden).map((a) => h('button', { class: 'menuItem', onClick: (event) => { event.stopPropagation(); a.run() } }, a.label)))
		},
	},
}))
jest.mock('../../components/cards/ConfigurationCard.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../components/shared/ListActionsBar.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../components/shared/ViewModeSwitch.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../components/PaginationComponent.vue', () => ({ __esModule: true, default: { render: () => null } }))
jest.mock('../../store/store.js', () => {
	const { reactive } = jest.requireActual('vue')
	return {
		configurationStore: reactive({
			configurationList: [],
			loading: false,
			error: null,
			refreshConfigurationList: () => Promise.resolve(),
			setConfigurationItem: jest.fn(),
		}),
		navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	}
})

const CONFIGURATIONS = [
	{ id: 1, title: 'Local', description: 'Shipped', sourceType: 'local', isLocal: true, app: 'opencatalogi', localVersion: '1.0' },
	{ id: 2, title: 'Remote', sourceType: 'github', localVersion: '1', remoteVersion: '2', syncEnabled: true, syncStatus: 'failed' },
	{ id: 3, title: 'Synced', sourceType: 'url', syncEnabled: true, syncStatus: 'success' },
]

/**
 * Mount the page in table view over these configurations.
 *
 * @param {Array} list The configurations the store holds
 * @return {Promise<object>} The wrapper
 */
async function mounted(list) {
	jest.requireMock('../../store/store.js').configurationStore.configurationList = list
	const wrapper = mount(ConfigurationsIndex)
	wrapper.vm.$.proxy.viewMode = 'table'
	await flushPromises()
	return wrapper
}

describe('ConfigurationsIndex table', () => {
	beforeEach(() => jest.clearAllMocks())

	it('shows every configuration with its badges and menu', async () => {
		const wrapper = await mounted(CONFIGURATIONS)
		expect(tableText(wrapper)).toMatchSnapshot()
	})

	it('lets the title and status cells wrap', async () => {
		const wrapper = await mounted(CONFIGURATIONS)
		const wrapping = wrapper.findComponent({ name: 'CnDataTable' }).props('columns')
			.filter((column) => column.cellClass === 'cn-cell--wrap')
			.map((column) => column.key)
		expect(wrapping).toEqual(['title', 'status'])
	})

	it('shows the empty state without configurations', async () => {
		const wrapper = await mounted([])
		expect(wrapper.findAll('table')).toHaveLength(0)
		expect(wrapper.find('[data-stub="empty"]').text()).toMatchSnapshot()
	})

	it('does not select a row on a plain click, and opens the edit modal from its menu', async () => {
		const wrapper = await mounted(CONFIGURATIONS)
		await wrapper.findAll('tbody tr')[0].trigger('click')
		expect(wrapper.vm.selectedConfigurations).toEqual([])
		const { configurationStore, navigationStore } = jest.requireMock('../../store/store.js')
		await wrapper.findAll('tbody tr')[0].findAll('.menuItem')[1].trigger('click')
		expect(configurationStore.setConfigurationItem).toHaveBeenCalledWith(CONFIGURATIONS[0])
		expect(navigationStore.setModal).toHaveBeenCalledWith('editConfiguration')
	})

	it('selects every configuration from select all', async () => {
		const wrapper = await mounted(CONFIGURATIONS)
		wrapper.vm.toggleSelectAll(true)
		expect(wrapper.vm.selectedConfigurations).toEqual([1, 2, 3])
	})
})
