import axios from '@nextcloud/axios'
/**
 * The registers list page mounted through its own setup: the rows it hands
 * the index page, the cells, form fields and action-bar entries it renders,
 * and what the page's and the entries' events do.
 *
 * @spec exclude Client-side register list markup and event wiring.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import RegistersIndex from './RegistersIndex.vue'

jest.mock('@nextcloud/axios', () => ({ post: jest.fn() }))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one, many, count, vars) => (count === 1 ? one : many).replace('{count}', vars.count),
}))
jest.mock('@conduction/nextcloud-vue', () => ({ CnIndexPage: mockIndexPage() }))
jest.mock('@nextcloud/vue', () => ({
	NcAppContent: mockStub('NcAppContent'),
	NcActionButton: {
		name: 'NcActionButton',
		emits: ['click'],
		render() {
			const { h } = require('vue')
			return h('button', { onClick: () => this.$emit('click') }, [this.$slots.icon(), this.$slots.default()])
		},
	},
}))
jest.mock('vue-material-design-icons/Api.vue', () => ({ __esModule: true, default: mockStub('ApiIcon') }))
jest.mock('vue-material-design-icons/CloudUploadOutline.vue', () => ({ __esModule: true, default: mockStub('CloudUploadOutline') }))
jest.mock('vue-material-design-icons/Download.vue', () => ({ __esModule: true, default: mockStub('Download') }))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({ __esModule: true, default: mockStub('InformationOutline') }))
jest.mock('vue-material-design-icons/Upload.vue', () => ({ __esModule: true, default: mockStub('Upload') }))
jest.mock('../../components/cards/RegisterSchemaCard.vue', () => ({ __esModule: true, default: mockStub('RegisterSchemaCard') }))
jest.mock('../../components/register/RegisterFormFields.vue', () => ({
	__esModule: true,
	default: {
		name: 'RegisterFormFields',
		props: ['schemaOptions', 'schemasLoading', 'mode'],
		render() {
			return require('vue').h('x-RegisterFormFields', `${this.mode}:${this.schemaOptions.map((option) => option.label).join(',')}`)
		},
	},
}))
jest.mock('../../components/shared/ActionMenu.vue', () => ({
	__esModule: true,
	default: {
		name: 'ActionMenu',
		props: ['actions'],
		render() {
			return require('vue').h('x-ActionMenu', this.actions.map((action) => action.label).join('|'))
		},
	},
}))
jest.mock('../../components/shared/ManagedBadge.vue', () => ({
	__esModule: true,
	default: {
		name: 'ManagedBadge',
		props: ['config'],
		render() {
			return require('vue').h('x-ManagedBadge', this.config ? this.config.title : '-')
		},
	},
}))
jest.mock('../IndexRowDescription.vue', () => ({
	__esModule: true,
	default: {
		name: 'IndexRowDescription',
		props: ['text'],
		render() {
			return require('vue').h('x-IndexRowDescription', this.text || '')
		},
	},
}))
jest.mock('../../services/listDateTime.js', () => ({ listDateTime: (value) => `at:${value}` }))
jest.mock('../../store/store.js', () => ({
	configurationStore: { configurationList: [], refreshConfigurationList: jest.fn() },
	navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
	registerStore: {
		registerList: [],
		pagination: {},
		viewMode: 'table',
		loading: false,
		refreshRegisterList: jest.fn(),
		saveRegister: jest.fn(),
		setPagination: jest.fn(),
		setRegisterItem: jest.fn(),
		setViewMode: jest.fn(),
	},
	schemaStore: { schemaList: [], refreshSchemaList: jest.fn() },
}))

const { configurationStore, navigationStore, registerStore, schemaStore } = jest.requireMock('../../store/store.js')

/**
 * The results the page reported to the index page's form.
 */
const mockFormResults = []

/**
 * A stand-in that renders its tag and its default slot.
 *
 * @param {string} name The component name
 * @return {object} The stand-in
 */
function mockStub(name) {
	return {
		name,
		inheritAttrs: false,
		setup(props, { slots }) {
			return () => require('vue').h(`x-${name}`, slots.default ? slots.default() : [])
		},
	}
}

/**
 * A stand-in index page: the form fields and action items, then one block
 * per object filled from the column, row-actions and card slots.
 *
 * @return {object} The stand-in
 */
function mockIndexPage() {
	return {
		name: 'CnIndexPage',
		props: ['objects', 'columns', 'emptyText', 'addLabel'],
		emits: ['create', 'edit', 'refresh', 'pageChanged', 'pageSizeChanged', 'viewModeChange', 'select', 'rowClick'],
		methods: {
			setFormResult(result) {
				mockFormResults.push(result)
			},
		},
		render() {
			const { h } = require('vue')
			const slots = this.$slots
			return h('x-CnIndexPage', { 'data-empty': this.emptyText }, [
				h('x-form', slots['form-fields']({ mode: 'create' })),
				h('x-action-items', slots['action-items']()),
				...this.objects.map((row) => h(
					'x-row',
					{ 'data-id': row.id },
					[
						...this.columns.map((column) => h(`x-cell-${column.key}`, slots[`column-${column.key}`]({ row }))),
						h('x-actions', slots['row-actions']({ row })),
						h('x-card', slots.card({ object: row })),
					],
				)),
			])
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

const router = { push: jest.fn() }

/**
 * Mount the page and let its mount-time load settle.
 *
 * @return {Promise<object>} The wrapper
 */
async function mounted() {
	const wrapper = mount(withRealSetup(RegistersIndex), { global: { mocks: { $router: router } } })
	await flushPromises()
	return wrapper
}

/**
 * The index page stand-in of a mounted page.
 *
 * @param {object} wrapper The page
 * @return {object} The stand-in's wrapper
 */
function indexPage(wrapper) {
	return wrapper.findComponent({ name: 'CnIndexPage' })
}

beforeEach(() => {
	jest.clearAllMocks()
	mockFormResults.length = 0
	registerStore.pagination = {}
	registerStore.registerList = [
		{
			id: 4,
			title: 'Zaken',
			description: 'Case files',
			schemas: [{ id: 1 }, { id: 2 }],
			created: '2026-01-01',
			updated: '2026-02-01',
		},
		{ id: 5, title: 'Single', schemas: [{ id: 3 }] },
		{ id: 6, title: 'Bare' },
		{ id: 98, title: 'System Totals' },
	]
	schemaStore.schemaList = [{ id: 1, title: 'Person' }, { id: 2, title: 'Case' }]
	configurationStore.configurationList = [{ id: 9, title: 'Remote config', sourceType: 'github', registers: [4] }]
})

describe('RegistersIndex mounted', () => {
	it('loads registers, configurations and schemas, and offers the schemas in the form', async () => {
		const wrapper = await mounted()

		expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(1)
		expect(configurationStore.refreshConfigurationList).toHaveBeenCalledTimes(1)
		expect(schemaStore.refreshSchemaList).toHaveBeenCalledTimes(1)
		expect(wrapper.find('x-form x-RegisterFormFields').text()).toBe('create:Person,Case')
	})

	it('renders a row per real register with its badge, schema count and dates', async () => {
		const wrapper = await mounted()

		expect(wrapper.findAll('x-row').map((row) => row.attributes('data-id'))).toEqual(['4', '5', '6'])

		const zaken = wrapper.find('x-row[data-id="4"]')
		expect(zaken.find('x-cell-title strong').text()).toContain('Zaken')
		expect(zaken.find('x-ManagedBadge').text()).toBe('Remote config')
		expect(zaken.find('x-IndexRowDescription').text()).toBe('Case files')
		expect(zaken.find('x-cell-schemas').text()).toBe('2 schemas')
		expect(zaken.find('x-cell-created').text()).toBe('at:2026-01-01')
		expect(zaken.find('x-cell-updated').text()).toBe('at:2026-02-01')
		expect(zaken.find('x-actions x-ActionMenu').text()).toContain('View Details')
		expect(zaken.find('x-card x-RegisterSchemaCard').exists()).toBe(true)

		expect(wrapper.find('x-row[data-id="5"] x-cell-schemas').text()).toBe('1 schema')
		expect(wrapper.find('x-row[data-id="6"] x-cell-schemas').text()).toBe('0 schemas')
		expect(wrapper.find('x-row[data-id="6"] x-ManagedBadge').text()).toBe('-')
	})

	it('opens the import modal with no register selected', async () => {
		const wrapper = await mounted()

		await wrapper.findAll('x-action-items button')[0].trigger('click')

		expect(registerStore.setRegisterItem).toHaveBeenCalledWith(null)
		expect(navigationStore.setModal).toHaveBeenCalledWith('importRegister')
	})

	it('opens the documentation of all APIs in a new tab', async () => {
		const open = jest.spyOn(window, 'open').mockImplementation(() => null)
		const wrapper = await mounted()

		await wrapper.findAll('x-action-items button')[1].trigger('click')

		expect(open).toHaveBeenCalledWith(
			`https://redocly.github.io/redoc/?url=${encodeURIComponent(`${window.location.origin}/apps/openregister/api/registers/oas`)}`,
			'_blank',
		)
		open.mockRestore()
	})

	it('asks the server to warm up the names cache', async () => {
		axios.post.mockResolvedValue({ data: null })
		const wrapper = await mounted()

		await wrapper.findAll('x-action-items button')[2].trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			`${window.location.origin}/index.php/apps/openregister/api/settings/cache/warmup-names`,
			{},
			expect.any(Object),
		)
	})

	it('saves a created or edited register and reports the outcome to the form', async () => {
		registerStore.saveRegister.mockResolvedValueOnce({}).mockRejectedValueOnce(new Error('Title taken'))
		const wrapper = await mounted()

		indexPage(wrapper).vm.$emit('create', { title: 'New', schemas: [{ id: 1 }] })
		await flushPromises()
		indexPage(wrapper).vm.$emit('edit', { id: 4, title: 'Zaken', schemas: [] })
		await flushPromises()

		expect(registerStore.saveRegister).toHaveBeenCalledTimes(2)
		expect(mockFormResults).toEqual([{ success: true }, { error: 'Title taken' }])
	})

	it('opens a clicked row and hands view mode, paging and selection on', async () => {
		const wrapper = await mounted()
		const page = indexPage(wrapper)

		page.vm.$emit('rowClick', { id: 5, title: 'Single' })
		page.vm.$emit('viewModeChange', 'cards')
		page.vm.$emit('pageChanged', 2)
		page.vm.$emit('pageSizeChanged', 10)
		page.vm.$emit('select', [4, 5])

		expect(registerStore.setRegisterItem).toHaveBeenCalledWith({ id: 5 })
		expect(router.push).toHaveBeenCalledWith('/registers/5')
		expect(registerStore.setViewMode).toHaveBeenCalledWith('cards')
		expect(registerStore.setPagination).toHaveBeenNthCalledWith(1, 2, undefined)
		expect(registerStore.setPagination).toHaveBeenNthCalledWith(2, 1, 10)
		expect(wrapper.vm.selectedRegisters).toEqual([4, 5])
	})

	it('refreshes the list when a card asks for it', async () => {
		const wrapper = await mounted()

		wrapper.findComponent({ name: 'RegisterSchemaCard' }).vm.$emit('refresh')
		await flushPromises()

		expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(2)
	})
})
