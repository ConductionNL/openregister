/**
 * The registers sidebar: the totals and orphaned-items blocks it renders from
 * the dashboard store, their counts, labels and breakdowns, the open state
 * through the navigation store, the date range hand-off, and the breakdown
 * helpers.
 *
 * @spec exclude Client-side registers sidebar statistics.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import RegistersSideBar from './RegistersSideBar.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))
jest.mock('../../services/formatBytes.js', () => ({ __esModule: true, default: (bytes) => `${bytes} B` }))
// The compiled component binds the libraries and `vue` to one variable, so the
// stand-ins have to be reachable through `vue` as well.
jest.mock('@nextcloud/vue', () => mockLibrary())
jest.mock('@conduction/nextcloud-vue', () => mockLibrary())
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockLibrary() }))
jest.mock('../StatisticsScopeFilter.vue', () => ({ name: 'StatisticsScopeFilter', render: () => null }))
jest.mock('../SidebarStatsSection.vue', () => ({
	name: 'SidebarStatsSection',
	props: ['title', 'loading'],
	render() {
		const { h } = jest.requireActual('vue')
		return h('section', { 'data-title': this.title }, this.loading ? 'loading' : this.$slots.default?.())
	},
}))
jest.mock('../../store/store.js', () => ({
	dashboardStore: {
		loading: false,
		registers: [],
		getSystemTotals: null,
		getOrphanedItems: null,
		preload: jest.fn(),
		setDateRange: jest.fn(),
	},
	navigationStore: { sidebarState: { registers: true }, setSidebarState: jest.fn() },
	objectStore: {},
	registerStore: {},
	schemaStore: {},
}))

const { dashboardStore, navigationStore } = jest.requireMock('../../store/store.js')

/**
 * Stand-ins for the sidebar, its tab and the stats block.
 *
 * @return {object} The components
 */
function mockLibrary() {
	const { h } = jest.requireActual('vue')
	return {
		NcAppSidebar: {
			name: 'NcAppSidebar',
			props: ['name', 'subtitle', 'subname', 'open', 'modelValue'],
			emits: ['update:open', 'update:modelValue'],
			render() {
				return h('aside', this.$slots.default?.())
			},
		},
		NcAppSidebarTab: {
			name: 'NcAppSidebarTab',
			props: ['id', 'name', 'order'],
			render() {
				return h('div', this.$slots.default?.())
			},
		},
		CnStatsBlock: {
			name: 'CnStatsBlock',
			props: ['title', 'count', 'countLabel', 'icon', 'variant', 'horizontal', 'showZeroCount', 'breakdown'],
			render: () => null,
		},
	}
}

/**
 * Mount the sidebar.
 *
 * @return {Promise<object>} The wrapper
 */
async function mountSidebar() {
	const wrapper = mount(RegistersSideBar)
	await flushPromises()
	return wrapper
}

/**
 * The stats blocks of one section, as their shown props.
 *
 * @param {object} wrapper The sidebar
 * @param {string} section The section title
 * @return {Array<object>} Title, count, label, variant and breakdown per block
 */
function blocks(wrapper, section) {
	return wrapper
		.find(`section[data-title="${section}"]`)
		.findAllComponents({ name: 'CnStatsBlock' })
		.map((block) => {
			const { title, count, countLabel, variant, breakdown } = block.props()
			return { title, count, countLabel, variant, breakdown }
		})
}

beforeEach(() => {
	jest.clearAllMocks()
	dashboardStore.loading = false
	dashboardStore.registers = []
	dashboardStore.getSystemTotals = null
	dashboardStore.getOrphanedItems = null
	navigationStore.sidebarState = { registers: true }
})

describe('RegistersSideBar sections', () => {
	it('renders the totals of the real registers, without the summary rows', async () => {
		dashboardStore.registers = [
			{ title: 'Persons', schemas: [{ id: 1 }, { id: 2 }] },
			{ title: 'Cases' },
			{ title: 'System Totals', schemas: [{ id: 9 }] },
			{ title: 'Orphaned Items', schemas: [{ id: 8 }] },
		]
		dashboardStore.getSystemTotals = {
			stats: {
				objects: { total: 12, size: 4096, invalid: 2, deleted: 0, locked: 1 },
				logs: { total: 1, size: 100 },
				files: { total: 0, size: 0 },
			},
		}
		const wrapper = await mountSidebar()

		expect(blocks(wrapper, 'Register Totals')).toEqual([
			{ title: 'Registers', count: 2, countLabel: 'registers', variant: 'primary', breakdown: undefined },
			{ title: 'Schemas', count: 2, countLabel: 'schemas', variant: 'primary', breakdown: undefined },
			{ title: 'Objects', count: 12, countLabel: 'objects', variant: 'primary', breakdown: { size: '4096 B', invalid: 2, locked: 1 } },
			{ title: 'Logs', count: 1, countLabel: 'log', variant: undefined, breakdown: { size: '100 B' } },
			{ title: 'Files', count: 0, countLabel: 'files', variant: undefined, breakdown: null },
		])
	})

	it('renders the orphaned items as warnings', async () => {
		dashboardStore.getOrphanedItems = {
			stats: { objects: { total: 1, deleted: 3 }, logs: { total: 4 }, files: { total: 2, size: 50 } },
		}
		const wrapper = await mountSidebar()

		expect(blocks(wrapper, 'Orphaned Items')).toEqual([
			{ title: 'Objects', count: 1, countLabel: 'object', variant: 'warning', breakdown: { deleted: 3 } },
			{ title: 'Logs', count: 4, countLabel: 'logs', variant: 'warning', breakdown: null },
			{ title: 'Files', count: 2, countLabel: 'files', variant: 'warning', breakdown: { size: '50 B' } },
		])
	})

	it('counts zero for totals without stats', async () => {
		dashboardStore.getSystemTotals = {}
		dashboardStore.getOrphanedItems = {}
		const wrapper = await mountSidebar()

		expect(blocks(wrapper, 'Register Totals').map((block) => block.count)).toEqual([0, 0, 0, 0, 0])
		expect(blocks(wrapper, 'Orphaned Items').map((block) => block.count)).toEqual([0, 0, 0])
		expect(blocks(wrapper, 'Orphaned Items')[0].breakdown).toBe(null)
	})

	it('renders no blocks before the totals are known', async () => {
		const wrapper = await mountSidebar()

		expect(wrapper.findAllComponents({ name: 'CnStatsBlock' })).toHaveLength(0)
	})

	it('shows the loading state in both sections while the dashboard loads', async () => {
		dashboardStore.loading = true
		dashboardStore.getSystemTotals = { stats: {} }
		const wrapper = await mountSidebar()

		expect(wrapper.findAll('section').map((section) => section.text())).toEqual(['loading', 'loading'])
		expect(wrapper.findAllComponents({ name: 'CnStatsBlock' })).toHaveLength(0)
	})
})

describe('RegistersSideBar state', () => {
	it('opens from the navigation store and writes a close back to it', async () => {
		navigationStore.sidebarState = { registers: false }
		const wrapper = await mountSidebar()
		const sidebar = wrapper.findComponent({ name: 'NcAppSidebar' })

		expect(sidebar.props('open')).toBe(false)
		expect(sidebar.props('name')).toBe('Registers')
		sidebar.vm.$emit('update:open', true)

		expect(navigationStore.setSidebarState).toHaveBeenCalledWith('registers', true)
	})

	it('hands the selected date range to the dashboard store', () => {
		const vm = { dateRange: { from: '2026-01-01', till: '2026-02-01' } }

		RegistersSideBar.methods.onDateRangeChange.call(vm)

		expect(dashboardStore.setDateRange).toHaveBeenCalledWith('2026-01-01', '2026-02-01')
	})
})

describe('RegistersSideBar breakdowns', () => {
	const { objectsBreakdown, sizeBreakdown } = RegistersSideBar.methods

	it('has no object breakdown without object stats', () => {
		expect(objectsBreakdown(null)).toBe(null)
		expect(objectsBreakdown({ stats: {} })).toBe(null)
	})

	it('has no object breakdown when every count is zero', () => {
		expect(objectsBreakdown({ stats: { objects: { size: 0, invalid: 0, deleted: 0, locked: 0 } } })).toBe(null)
	})

	it('lists the size and every non-zero count', () => {
		expect(objectsBreakdown({ stats: { objects: { size: 10, invalid: 1, deleted: 2, locked: 3, total: 9 } } })).toEqual({
			size: '10 B',
			invalid: 1,
			deleted: 2,
			locked: 3,
		})
	})

	it('formats a size and has none for zero', () => {
		expect(sizeBreakdown(2048)).toEqual({ size: '2048 B' })
		expect(sizeBreakdown(0)).toBe(null)
		expect(sizeBreakdown(undefined)).toBe(null)
	})
})
