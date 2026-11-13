/**
 * The schemas list page mounted through its own setup: the rows it hands the
 * index page, the cells it renders for them, and what the page's add,
 * view-mode, paging and refresh events do.
 *
 * @spec exclude Client-side schema list markup and event wiring.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import SchemasIndex from './SchemasIndex.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))
jest.mock('@conduction/nextcloud-vue', () => ({ CnIndexPage: mockIndexPage() }))
jest.mock('@nextcloud/vue', () => ({ NcAppContent: mockStub('NcAppContent') }))
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
jest.mock('../../components/cards/RegisterSchemaCard.vue', () => ({ __esModule: true, default: mockStub('RegisterSchemaCard') }))
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
	schemaStore: {
		schemaList: [],
		pagination: {},
		viewMode: 'table',
		refreshSchemaList: jest.fn(),
		setPagination: jest.fn(),
		setSchemaItem: jest.fn(),
		setViewMode: jest.fn(),
	},
}))

const { configurationStore, navigationStore, schemaStore } = jest.requireMock('../../store/store.js')

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
 * A stand-in index page: one block per object, filled from the column,
 * row-actions and card slots the page provides.
 *
 * @return {object} The stand-in
 */
function mockIndexPage() {
	return {
		name: 'CnIndexPage',
		props: ['objects', 'columns', 'emptyText', 'viewMode', 'addLabel'],
		emits: ['add', 'refresh', 'pageChanged', 'pageSizeChanged', 'viewModeChange', 'select'],
		setup(props, { slots }) {
			const { h } = require('vue')
			return () => h('x-CnIndexPage', { 'data-empty': props.emptyText, 'data-add': props.addLabel }, props.objects.map((row) => h(
				'x-row',
				{ 'data-id': row.id },
				[
					...props.columns.map((column) => h(`x-cell-${column.key}`, slots[`column-${column.key}`]({ row }))),
					h('x-actions', slots['row-actions']({ row })),
					h('x-card', slots.card({ object: row })),
				],
			)))
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

const MANAGER = { id: 9, title: 'Remote config', sourceType: 'github', schemas: [{ id: 1 }] }

/**
 * Mount the page and let its mount-time load settle.
 *
 * @return {Promise<object>} The wrapper
 */
async function mounted() {
	const wrapper = mount(withRealSetup(SchemasIndex))
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	jest.clearAllMocks()
	schemaStore.pagination = {}
	schemaStore.viewMode = 'table'
	schemaStore.schemaList = [
		{
			id: 1,
			title: 'Person',
			description: 'A human',
			extend: 'base',
			properties: { name: {}, age: {} },
			stats: { objects: { total: 3 } },
			created: '2026-01-01',
			updated: '2026-02-01',
		},
		{ id: 2, title: 'Empty', created: '2025-01-01', updated: '2025-01-02' },
	]
	configurationStore.configurationList = [MANAGER]
})

describe('SchemasIndex mounted', () => {
	it('loads the schemas and the configurations on mount', async () => {
		await mounted()

		expect(schemaStore.refreshSchemaList).toHaveBeenCalledTimes(1)
		expect(configurationStore.refreshConfigurationList).toHaveBeenCalledTimes(1)
	})

	it('renders a row per schema with its badges, property count and dates', async () => {
		const wrapper = await mounted()
		const rows = wrapper.findAll('x-row')

		expect(rows.map((row) => row.attributes('data-id')).sort()).toEqual(['1', '2'])

		const person = wrapper.find('x-row[data-id="1"]')
		expect(person.find('x-cell-title strong').text()).toBe('Person')
		expect(person.find('x-cell-title .statusPill--alert').text()).toBe('Extended')
		expect(person.find('x-cell-title .statusPill--success').text()).toBe('In use')
		expect(person.find('x-ManagedBadge').text()).toBe('Remote config')
		expect(person.find('x-IndexRowDescription').text()).toBe('A human')
		expect(person.find('x-cell-properties').text()).toBe('2')
		expect(person.find('x-cell-created').text()).toBe('at:2026-01-01')
		expect(person.find('x-cell-updated').text()).toBe('at:2026-02-01')
		expect(person.find('x-actions x-ActionMenu').text()).not.toBe('')
		expect(person.find('x-card x-RegisterSchemaCard').exists()).toBe(true)

		const empty = wrapper.find('x-row[data-id="2"]')
		expect(empty.find('.statusPill').exists()).toBe(false)
		expect(empty.find('x-ManagedBadge').text()).toBe('-')
		expect(empty.find('x-cell-properties').text()).toBe('0')
	})

	it('opens an empty schema form on add', async () => {
		const wrapper = await mounted()

		wrapper.findComponent({ name: 'CnIndexPage' }).vm.$emit('add')

		expect(schemaStore.setSchemaItem).toHaveBeenCalledWith(null)
		expect(navigationStore.setModal).toHaveBeenCalledWith('editSchema')
	})

	it('hands the view mode, paging and selection events to the store and page', async () => {
		const wrapper = await mounted()
		const page = wrapper.findComponent({ name: 'CnIndexPage' })

		page.vm.$emit('viewModeChange', 'cards')
		page.vm.$emit('pageChanged', 3)
		page.vm.$emit('pageSizeChanged', 50)
		page.vm.$emit('select', [2])

		expect(schemaStore.setViewMode).toHaveBeenCalledWith('cards')
		expect(schemaStore.setPagination).toHaveBeenNthCalledWith(1, 3, undefined)
		expect(schemaStore.setPagination).toHaveBeenNthCalledWith(2, 1, 50)
		expect(wrapper.vm.selectedSchemas).toEqual([2])
	})

	it('refreshes the list when a card asks for it', async () => {
		const wrapper = await mounted()

		wrapper.findComponent({ name: 'RegisterSchemaCard' }).vm.$emit('refresh')
		await flushPromises()

		expect(schemaStore.refreshSchemaList).toHaveBeenCalledTimes(2)
	})
})
