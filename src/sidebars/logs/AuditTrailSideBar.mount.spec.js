/**
 * The audit trail sidebar mounted through its own setup: the statistics,
 * action distribution and most active objects it renders, and how each filter
 * control, the scope tab and the open switch reach the route and the stores.
 *
 * @spec exclude Client-side audit trail sidebar markup and event wiring.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import AuditTrailSideBar from './AuditTrailSideBar.vue'
import eventBus from '../../eventBus.js'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => (vars ? text.replace('{count}', vars.count) : text),
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => ({
	__esModule: true,
	NcAppSidebar: mockStub('NcAppSidebar'),
	NcCheckboxRadioSwitch: mockStub('NcCheckboxRadioSwitch'),
	NcTextField: mockStub('NcTextField'),
}))
jest.mock('../../eventBus.js', () => ({
	__esModule: true,
	default: { emit: jest.fn(), on: jest.fn(), off: jest.fn() },
}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({ __esModule: true, default: mockStub('CogOutline') }))
jest.mock('vue-material-design-icons/Delete.vue', () => ({ __esModule: true, default: mockStub('Delete') }))
jest.mock('vue-material-design-icons/Eye.vue', () => ({ __esModule: true, default: mockStub('Eye') }))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({ __esModule: true, default: mockStub('Pencil') }))
jest.mock('vue-material-design-icons/Plus.vue', () => ({ __esModule: true, default: mockStub('Plus') }))
jest.mock('../../components/shared/CountListItem.vue', () => ({ __esModule: true, default: mockStub('CountListItem') }))
jest.mock('../../components/shared/SidebarStatCard.vue', () => ({ __esModule: true, default: mockStub('SidebarStatCard') }))
jest.mock('../../components/shared/StatisticsSidebarTab.vue', () => ({ __esModule: true, default: mockStub('StatisticsSidebarTab') }))
jest.mock('./TrailUserDateFields.vue', () => ({ __esModule: true, default: mockStub('TrailUserDateFields') }))
jest.mock('../ScopeFiltersTab.vue', () => ({ __esModule: true, default: mockStub('ScopeFiltersTab') }))
jest.mock('../SidebarFilterSelect.vue', () => ({ __esModule: true, default: mockStub('SidebarFilterSelect') }))
jest.mock('../../store/store.js', () => ({
	auditTrailStore: {},
	navigationStore: {},
	registerStore: {},
	schemaStore: {},
	searchTrailStore: {},
}))

const stores = jest.requireMock('../../store/store.js')

/**
 * A stand-in that renders its tag, its plain attributes and every slot.
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
			const attrs = Object.fromEntries(
				Object.entries(this.$attrs).filter(([key, value]) => !/^on[A-Z]/.test(key) && typeof value !== 'object'),
			)
			return h(`x-${name}`, attrs, Object.values(this.$slots).map((slot) => slot()))
		},
	}
}

/**
 * The sidebar with its own setup run, and its components bound from its
 * options: under jest the compiled bindings of an SFC with a setup block
 * resolve imported components to undefined.
 *
 * @param {object} component The sidebar
 * @return {object} The sidebar with its components bound
 */
function withRealSetup(component) {
	return {
		...component,
		setup: (props, context) => ({ ...component.setup(props, context), ...component.components }),
	}
}

let router

/**
 * Mount the sidebar on the audit trail route and let its loads settle.
 *
 * @return {Promise<object>} The wrapper
 */
async function mounted() {
	const wrapper = mount(withRealSetup(AuditTrailSideBar), {
		global: { mocks: { $route: { path: '/audit-trails', query: {} }, $router: router } },
	})
	await flushPromises()
	return wrapper
}

/**
 * The query of the last route replacement.
 *
 * @return {object} The query
 */
function lastQuery() {
	return router.replace.mock.calls.at(-1)[0].query
}

/**
 * A child stand-in by name.
 *
 * @param {object} wrapper The sidebar
 * @param {string} name The component name
 * @return {object} The child
 */
function child(wrapper, name) {
	return wrapper.findComponent({ name })
}

beforeEach(() => {
	jest.clearAllMocks()
	router = { replace: jest.fn() }
	stores.auditTrailStore = {
		auditTrailFilters: {},
		auditTrailList: [{ id: 1, user: 'alice' }, { id: 2, user: 'bob' }],
		auditTrailPagination: { total: 40 },
		getStatistics: jest.fn().mockResolvedValue({ total: 40, create: 10, update: 25 }),
		getActionDistribution: jest.fn().mockResolvedValue([
			{ action: 'update', count: 25 },
			{ name: 'create', count: 10 },
			{ action: 'delete' },
			{ action: 'read', count: 5 },
		]),
		getTopObjects: jest.fn().mockResolvedValue([{ objectId: 'obj-1', count: 7 }, { id: 3 }]),
		setAuditTrailFilters: jest.fn(),
		refreshAuditTrailList: jest.fn(),
	}
	stores.navigationStore = { sidebarState: { auditTrail: true }, setSidebarState: jest.fn() }
	stores.registerStore = { registerItem: null, registerList: [{ id: 1 }], refreshRegisterList: jest.fn(), setRegisterItem: jest.fn() }
	stores.schemaStore = { schemaItem: null, schemaList: [{ id: 2 }], refreshSchemaList: jest.fn(), setSchemaItem: jest.fn() }
})

describe('AuditTrailSideBar mounted', () => {
	it('renders the statistics, the action distribution and the most active objects', async () => {
		const wrapper = await mounted()

		expect(wrapper.findAll('x-SidebarStatCard').map((card) => [card.attributes('label'), card.attributes('value')])).toEqual([
			['Total Audit Trails', '40'],
			['Create Operations', '10'],
			['Update Operations', '25'],
			['Delete Operations', '0'],
		])

		const actions = wrapper.findAll('.actionDistribution x-CountListItem')
		expect(actions.map((item) => [item.attributes('name'), item.attributes('subname')])).toEqual([
			['update', '25 entries'],
			['create', '10 entries'],
			['delete', '0 entries'],
			['read', '5 entries'],
		])
		expect(actions.map((item) => item.element.firstElementChild.tagName.toLowerCase())).toEqual([
			'x-pencil',
			'x-plus',
			'x-delete',
			'x-eye',
		])

		const objects = wrapper.findAll('.topObjects x-CountListItem')
		expect(objects.map((item) => [item.attributes('name'), item.attributes('subname')])).toEqual([
			['obj-1', '7 entries'],
			['Object 3', '0 entries'],
		])
		expect(objects[0].find('x-CogOutline').exists()).toBe(true)
		expect(eventBus.on).toHaveBeenCalledWith('audit-trail-filtered-count', expect.any(Function))
	})

	it('reports the open switch to the navigation store', async () => {
		const wrapper = await mounted()

		child(wrapper, 'NcAppSidebar').vm.$emit('update:open', false)

		expect(stores.navigationStore.setSidebarState).toHaveBeenCalledWith('auditTrail', false)
	})

	it('writes the picked actions, users and the changes switch to the route', async () => {
		const wrapper = await mounted()

		child(wrapper, 'SidebarFilterSelect').vm.$emit('update:modelValue', [{ value: 'create' }, { value: 'delete' }])
		await flushPromises()
		expect(lastQuery()).toEqual({ action: 'create,delete' })

		child(wrapper, 'TrailUserDateFields').vm.$emit('update:users', [{ value: 'alice' }])
		child(wrapper, 'TrailUserDateFields').vm.$emit('change')
		await flushPromises()
		expect(lastQuery()).toEqual({ action: 'create,delete', user: 'alice' })

		child(wrapper, 'NcCheckboxRadioSwitch').vm.$emit('update:modelValue', true)
		await flushPromises()
		expect(lastQuery()).toEqual({ action: 'create,delete', user: 'alice', onlyWithChanges: '1' })
	})

	it('writes the object id to the route only after typing pauses', async () => {
		jest.useFakeTimers()
		try {
			const wrapper = await mounted()

			child(wrapper, 'NcTextField').vm.$emit('update:modelValue', 'obj-9')
			await flushPromises()
			expect(router.replace).not.toHaveBeenCalled()

			jest.advanceTimersByTime(500)
			expect(lastQuery()).toEqual({ object: 'obj-9' })
		} finally {
			jest.useRealTimers()
		}
	})

	it('scopes to a picked register or schema and clears every filter', async () => {
		const wrapper = await mounted()
		const scope = child(wrapper, 'ScopeFiltersTab')

		scope.vm.$emit('registerChange', { id: 1 })
		scope.vm.$emit('schemaChange', { id: 2 })
		expect(stores.registerStore.setRegisterItem).toHaveBeenCalledWith({ id: 1 })
		expect(stores.schemaStore.setSchemaItem).toHaveBeenNthCalledWith(1, null)
		expect(stores.schemaStore.setSchemaItem).toHaveBeenNthCalledWith(2, { id: 2 })

		await wrapper.setData({ selectedActions: [{ value: 'create' }], objectFilter: 'x' })
		scope.vm.$emit('clear')
		await flushPromises()

		expect(wrapper.vm.selectedActions).toEqual([])
		expect(wrapper.vm.objectFilter).toBe('')
		expect(stores.auditTrailStore.setAuditTrailFilters).toHaveBeenCalledWith({})
		expect(stores.auditTrailStore.refreshAuditTrailList).toHaveBeenCalled()
	})

	it('stops listening for the filtered count when it goes away', async () => {
		const wrapper = await mounted()

		wrapper.unmount()

		expect(eventBus.off).toHaveBeenCalledWith('audit-trail-filtered-count')
	})
})
