/**
 * The register sidebar as rendered: the edit dialog it opens from the menu and
 * from an empty schema list, what the dialog's save and close do, the open
 * state it hands to the navigation store, the tab it keeps in the register
 * store, and the schema edit and size actions it wires to its buttons.
 *
 * @spec exclude Client-side register sidebar wiring.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import RegisterSideBar from './RegisterSideBar.vue'

const mockActive = { register: null }

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn() }))
jest.mock('../../services/formatBytes.js', () => ({ __esModule: true, default: (bytes) => `${bytes} B` }))
jest.mock('../../services/registerOas.js', () => ({ downloadRegisterOas: jest.fn(), viewRegisterOas: jest.fn() }))
jest.mock('../../views/register/activeRegister.js', () => ({ findActiveRegister: () => mockActive.register }))
jest.mock('../../modals/register/EditRegisterFormDialog.vue', () => ({
	name: 'EditRegisterFormDialog',
	props: ['item', 'withLanguages', 'afterSave'],
	emits: ['close'],
	render() {
		const { h } = jest.requireActual('vue')
		return h('dialog', { 'data-edit': 'register' })
	},
}))
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () => mockLibrary())
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockLibrary() }))
jest.mock('../../store/store.js', () => ({
	dashboardStore: { fetchRegisters: jest.fn(), calculateSizes: jest.fn() },
	navigationStore: { sidebarState: { register: true }, setSidebarState: jest.fn(), setModal: jest.fn() },
	registerStore: { getActiveTab: 'stats-tab', setActiveTab: jest.fn(), setSchemaItem: jest.fn() },
	schemaStore: { setSchemaItem: jest.fn() },
	objectStore: {},
}))

const { dashboardStore, navigationStore, registerStore } = jest.requireMock('../../store/store.js')

/**
 * Stand-ins that render every slot, so the template's handlers are reachable.
 *
 * @return {object} The components
 */
function mockLibrary() {
	const { h } = jest.requireActual('vue')
	const slots = (vm) => Object.values(vm.$slots).map((slot) => slot())
	return {
		NcAppSidebar: {
			name: 'NcAppSidebar',
			props: ['name', 'subtitle', 'subname', 'open', 'modelValue'],
			emits: ['update:open', 'update:modelValue'],
			render() {
				return h('aside', slots(this))
			},
		},
		NcAppSidebarTab: { name: 'NcAppSidebarTab', props: ['id', 'name', 'order'], render() { return h('div', slots(this)) } },
		NcActions: { name: 'NcActions', props: ['primary', 'menuName'], render() { return h('div', slots(this)) } },
		NcActionButton: {
			name: 'NcActionButton',
			props: ['closeAfterClick'],
			emits: ['click'],
			render() {
				return h('button', { class: 'action', onClick: () => this.$emit('click') }, slots(this))
			},
		},
		NcButton: {
			name: 'NcButton',
			emits: ['click'],
			render() {
				return h('button', { class: 'plain', onClick: () => this.$emit('click') }, slots(this))
			},
		},
		NcEmptyContent: { name: 'NcEmptyContent', props: ['title', 'icon'], render() { return h('div', slots(this)) } },
		CnItemCard: { name: 'CnItemCard', props: ['title', 'icon'], render() { return h('article', slots(this)) } },
		CnKpiGrid: { name: 'CnKpiGrid', props: ['columns'], render() { return h('div', slots(this)) } },
		CnStatsBlock: {
			name: 'CnStatsBlock',
			props: ['title', 'count', 'countLabel', 'icon', 'variant', 'horizontal', 'showZeroCount', 'breakdown'],
			render: () => null,
		},
	}
}

/**
 * Mount the sidebar for a register.
 *
 * @param {object} register The active register
 * @return {object} The wrapper
 */
function mountFor(register) {
	mockActive.register = register
	return mount(RegisterSideBar, {
		global: {
			mocks: { $route: { params: {} } },
			stubs: {
				Pencil: true, Calculator: true, Download: true, ApiIcon: true,
				ChartBar: true, FileCodeOutline: true, DotsHorizontal: true,
			},
		},
	})
}

const STATS = { objects: { total: 3, size: 10, invalid: 0, deleted: 0, locked: 0 }, logs: { total: 0, size: 0 }, files: { total: 0, size: 0 } }

beforeEach(() => jest.clearAllMocks())

describe('RegisterSideBar rendered', () => {
	it('renders nothing without an active register', () => {
		const wrapper = mountFor(null)

		expect(wrapper.find('aside').exists()).toBe(false)
	})

	it('opens the edit dialog from the menu, refreshes the registers on save and closes it', async () => {
		const register = { id: 7, title: 'Zaken', schemas: [], stats: STATS }
		const wrapper = mountFor(register)
		expect(wrapper.find('[data-edit="register"]').exists()).toBe(false)

		await wrapper.findAll('button.action')[0].trigger('click')
		const dialog = wrapper.findComponent({ name: 'EditRegisterFormDialog' })
		expect(dialog.props('item')).toEqual(register)

		dialog.props('afterSave')()
		expect(dashboardStore.fetchRegisters).toHaveBeenCalledTimes(1)

		dialog.vm.$emit('close')
		await flushPromises()
		expect(wrapper.find('[data-edit="register"]').exists()).toBe(false)
	})

	it('opens the edit dialog from an empty schema list', async () => {
		const wrapper = mountFor({ id: 7, title: 'Zaken', schemas: [], stats: STATS })

		await wrapper.find('button.plain').trigger('click')

		expect(wrapper.find('[data-edit="register"]').exists()).toBe(true)
	})

	it('hands the open state to the navigation store and the tab to the register store', async () => {
		const wrapper = mountFor({ id: 7, title: 'Zaken', schemas: [], stats: STATS })
		const sidebar = wrapper.findComponent({ name: 'NcAppSidebar' })

		expect(sidebar.props('modelValue')).toBe('stats-tab')
		sidebar.vm.$emit('update:open', false)
		sidebar.vm.$emit('update:modelValue', 'schemas-tab')

		expect(navigationStore.setSidebarState).toHaveBeenCalledWith('register', false)
		expect(registerStore.setActiveTab).toHaveBeenCalledWith('schemas-tab')
	})

	it('lists each schema with its own edit action', async () => {
		const wrapper = mountFor({
			id: 7,
			title: 'Zaken',
			schemas: [{ id: 1, title: 'Zaak', stats: { objects: { total: 4, size: 2048 } } }, { id: 2, title: 'Besluit' }],
			stats: STATS,
		})
		const cards = wrapper.findAllComponents({ name: 'CnItemCard' })

		expect(cards.map((card) => card.props('title'))).toEqual(['Zaak', 'Besluit'])
		await cards[1].find('button.action').trigger('click')

		expect(registerStore.setSchemaItem).toHaveBeenCalledWith({ id: 2, title: 'Besluit' })
		expect(navigationStore.setModal).toHaveBeenCalledWith('editSchema')
	})
})
