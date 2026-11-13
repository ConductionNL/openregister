/**
 * The register detail page mounted through its own setup: what it hands the
 * detail page while loading, when the register is missing and once it is
 * known, the statistics rows, the schema cards with their actions and charts,
 * the empty state, and the edit dialog.
 *
 * @spec exclude Client-side register detail markup and event wiring.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import RegisterDetail from './RegisterDetail.vue'

jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s, translatePlural: (app, s) => s }))
jest.mock('@conduction/nextcloud-vue', () => ({
	CnDetailPage: {
		name: 'CnDetailPage',
		props: ['title', 'loading', 'error', 'errorMessage', 'statsTitle', 'statsColumns'],
		render() {
			const { h } = require('vue')
			const slots = this.$slots
			return h('x-CnDetailPage', {
				'data-title': this.title,
				'data-loading': String(this.loading),
				'data-error': String(this.error),
				'data-error-message': this.errorMessage,
				'data-stats-title': this.statsTitle,
				'data-stats-columns': this.statsColumns.map((column) => column.key).join(','),
			}, [
				h('x-error-actions', slots['error-actions']()),
				slots['stats-rows'] ? h('table', h('tbody', slots['stats-rows']())) : null,
				h('x-body', slots.default()),
			])
		},
	},
}))
jest.mock('@nextcloud/vue', () => ({
	NcAppContent: mockStub('NcAppContent'),
	NcLoadingIcon: mockStub('NcLoadingIcon'),
	NcActions: mockStub('NcActions'),
	NcEmptyContent: mockStub('NcEmptyContent'),
	NcButton: mockButton('NcButton'),
	NcActionButton: mockButton('NcActionButton'),
}))
jest.mock('vue3-apexcharts', () => ({
	__esModule: true,
	default: {
		name: 'Apexchart',
		props: ['options', 'series'],
		render() {
			return require('vue').h('x-Apexchart', { 'data-series': this.series.join(',') })
		},
	},
}))
jest.mock('vue-material-design-icons/Database.vue', () => ({ __esModule: true, default: mockStub('Database') }))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({ __esModule: true, default: mockStub('DotsHorizontal') }))
jest.mock('vue-material-design-icons/FileCodeOutline.vue', () => ({ __esModule: true, default: mockStub('FileCodeOutline') }))
jest.mock('vue-material-design-icons/FolderOutline.vue', () => ({ __esModule: true, default: mockStub('FolderOutline') }))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({ __esModule: true, default: mockStub('Pencil') }))
jest.mock('vue-material-design-icons/TableEye.vue', () => ({ __esModule: true, default: mockStub('TableEye') }))
jest.mock('../../modals/register/EditRegisterFormDialog.vue', () => ({
	__esModule: true,
	default: {
		name: 'EditRegisterFormDialog',
		props: ['item', 'afterSave'],
		emits: ['close'],
		render() {
			return require('vue').h('x-EditRegisterFormDialog', this.item.title)
		},
	},
}))
jest.mock('./DetailChartGrid.vue', () => ({ __esModule: true, default: mockStub('DetailChartGrid') }))
jest.mock('./activeRegister.js', () => ({ findActiveRegister: jest.fn() }))
jest.mock('@/services/getTheme.js', () => ({ getTheme: () => 'light' }))
jest.mock('../../services/formatBytes.js', () => ({ __esModule: true, default: (bytes) => `${bytes}B` }))
jest.mock('../../store/store.js', () => ({
	configurationStore: { configurationList: [] },
	dashboardStore: { chartData: {}, loading: false, error: null, fetchRegisters: jest.fn(), fetchAllChartData: jest.fn() },
	navigationStore: { setModal: jest.fn() },
	registerStore: { getRegisterItem: null, setRegisterItem: jest.fn(), getRegisterStats: jest.fn() },
	schemaStore: { setSchemaItem: jest.fn() },
}))

const { configurationStore, dashboardStore, navigationStore, registerStore, schemaStore } = jest.requireMock('../../store/store.js')
const { findActiveRegister } = jest.requireMock('./activeRegister.js')

/**
 * A stand-in that renders its tag and every slot it is given.
 *
 * @param {string} name The component name
 * @return {object} The stand-in
 */
function mockStub(name) {
	return {
		name,
		inheritAttrs: false,
		render() {
			const { h } = require('vue')
			return h(`x-${name}`, { 'data-name': this.$attrs.name }, Object.values(this.$slots).map((slot) => slot()))
		},
	}
}

/**
 * A stand-in button that emits click and renders every slot it is given.
 *
 * @param {string} name The component name
 * @return {object} The stand-in
 */
function mockButton(name) {
	return {
		name,
		emits: ['click'],
		render() {
			const { h } = require('vue')
			return h('button', { class: name, onClick: () => this.$emit('click') }, Object.values(this.$slots).map((slot) => slot()))
		},
	}
}

/**
 * The page with its own setup run, and its components bound from its
 * options: under jest the compiled bindings of an SFC with a setup block
 * resolve imported components to undefined.
 *
 * @param {object} component The page
 * @return {object} The page with its components bound
 */
function withRealSetup(component) {
	return {
		...component,
		setup: (props, context) => ({ ...component.setup(props, context), ...component.components }),
	}
}

const SCHEMAS = [
	{ id: 11, title: 'Person', properties: [], stats: { objects: { total: 5, size: 2048, valid: 4, invalid: 1, deleted: 0, locked: 0 } } },
	{ id: 12, title: 'Case' },
]

let router

/**
 * Mount the page for a register and let its load settle.
 *
 * @param {object|null} register The active register
 * @return {Promise<object>} The wrapper
 */
async function mounted(register) {
	registerStore.getRegisterItem = register ? { id: register.id } : null
	findActiveRegister.mockReturnValue(register)
	const wrapper = mount(withRealSetup(RegisterDetail), {
		global: { mocks: { $route: { params: {} }, $router: router } },
	})
	await flushPromises()
	return wrapper
}

/**
 * The detail page stand-in's attributes.
 *
 * @param {object} wrapper The page
 * @return {object} The attributes
 */
function detail(wrapper) {
	return wrapper.find('x-CnDetailPage').attributes()
}

beforeEach(() => {
	jest.clearAllMocks()
	router = { push: jest.fn(() => Promise.resolve()) }
	configurationStore.configurationList = []
	dashboardStore.loading = false
	dashboardStore.error = null
	registerStore.getRegisterStats.mockResolvedValue(null)
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => jest.restoreAllMocks())

describe('RegisterDetail mounted', () => {
	it('reports a missing register and offers the way back to the list', async () => {
		const wrapper = await mounted(null)

		expect(detail(wrapper)).toMatchObject({
			'data-title': '',
			'data-loading': 'false',
			'data-error': 'true',
			'data-error-message': 'Register not found',
			'data-stats-title': '',
			'data-stats-columns': '',
		})
		router.push.mockClear()

		await wrapper.find('x-error-actions button').trigger('click')

		expect(router.push).toHaveBeenCalledWith('/registers')
	})

	it('shows the dashboard\'s own error while it is loading', async () => {
		dashboardStore.loading = true
		dashboardStore.error = 'Server unreachable'
		const wrapper = await mounted({ id: 4, title: 'Zaken', schemas: [] })

		expect(detail(wrapper)).toMatchObject({
			'data-loading': 'true',
			'data-error': 'true',
			'data-error-message': 'Server unreachable',
		})
	})

	it('renders the statistics rows of the register', async () => {
		registerStore.getRegisterStats.mockResolvedValue({
			objects: { total: 9, size: 100, invalid: 2, deleted: 1 },
			files: { total: 3, size: 50 },
			logs: {},
			schemas: 2,
		})
		const wrapper = await mounted({ id: 4, title: 'Zaken', schemas: [] })

		expect(detail(wrapper)).toMatchObject({
			'data-title': 'Zaken',
			'data-error': 'false',
			'data-stats-title': 'Register Statistics',
			'data-stats-columns': 'type,total,size',
		})
		const rows = wrapper.findAll('tbody tr').map((row) => row.findAll('td').map((cell) => cell.text()))
		expect(rows).toEqual([
			['Objects', '9', '100B'],
			['Invalid', '2', '-'],
			['Deleted', '1', '-'],
			['Files', '3', '50B'],
			['Logs', '0', '0B'],
			['Schemas', '2', '-'],
		])
	})

	it('offers to add a schema to a register without any, and opens the edit dialog', async () => {
		dashboardStore.fetchRegisters.mockResolvedValue([])
		const wrapper = await mounted({ id: 4, title: 'Zaken', schemas: [] })

		expect(wrapper.find('x-NcEmptyContent').attributes('data-name')).toBe('No schemas found')
		expect(wrapper.find('x-EditRegisterFormDialog').exists()).toBe(false)

		await wrapper.find('.emptyContainer button').trigger('click')

		expect(wrapper.find('x-EditRegisterFormDialog').text()).toBe('Zaken')
		dashboardStore.fetchRegisters.mockClear()
		wrapper.findComponent({ name: 'EditRegisterFormDialog' }).props('afterSave')()
		expect(dashboardStore.fetchRegisters).toHaveBeenCalledTimes(1)

		wrapper.findComponent({ name: 'EditRegisterFormDialog' }).vm.$emit('close')
		await flushPromises()

		expect(wrapper.find('x-EditRegisterFormDialog').exists()).toBe(false)
	})

	it('offers no add button for a register a configuration manages', async () => {
		configurationStore.configurationList = [{ id: 9, title: 'Remote', registers: [4] }]
		const wrapper = await mounted({ id: 4, title: 'Zaken', schemas: [] })

		expect(wrapper.find('.emptyContainer button').exists()).toBe(false)
	})

	it('renders a card per schema with its stats, chart and actions', async () => {
		const wrapper = await mounted({ id: 4, title: 'Zaken', schemas: SCHEMAS })
		const cards = wrapper.findAll('.card')

		expect(cards.map((card) => card.find('h3').text())).toEqual(['Person', 'Case'])
		expect(cards[0].findAll('.statValue').map((value) => value.text())).toEqual(['5', '2048B'])
		expect(cards[0].find('x-Apexchart').attributes('data-series')).toBe('4,1,0,0')
		expect(cards[1].findAll('.statValue').map((value) => value.text())).toEqual(['0', '0B'])
		expect(cards[1].find('x-Apexchart').attributes('data-series')).toBe('0,0,0,0')
		expect(wrapper.find('.managedBadge').exists()).toBe(false)

		const [view, edit] = cards[0].findAll('button.NcActionButton')
		await view.trigger('click')
		await edit.trigger('click')

		expect(router.push).toHaveBeenCalledWith({ path: '/tables', query: { register: '4', schema: '11' } })
		expect(schemaStore.setSchemaItem).toHaveBeenCalledWith({ ...SCHEMAS[0], properties: {} })
		expect(navigationStore.setModal).toHaveBeenCalledWith('editSchema')
	})

	it('marks the cards of a managed register and leaves out their edit action', async () => {
		configurationStore.configurationList = [{ id: 9, title: 'Remote', registers: [4] }]
		const wrapper = await mounted({ id: 4, title: 'Zaken', schemas: SCHEMAS })
		const badge = wrapper.find('.card .managedBadge')

		expect(badge.attributes('title')).toBe('Managed by configuration: Remote')
		expect(badge.text()).toBe('Managed')
		expect(wrapper.findAll('.card')[0].findAll('button.NcActionButton')).toHaveLength(1)
	})
})
