import { flushPromises, mount } from '@vue/test-utils'
import DashboardSideBar from './DashboardSideBar.vue'
import { dashboardStore, objectStore, registerStore, schemaStore } from '../../store/store.js'

jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: (app, text) => text,
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))
// The sidebar mixes <script setup> with <script>; under vue3-jest the template
// reads the library components through the `vue` module, so the stand-ins are
// exposed on both modules.
jest.mock('@nextcloud/vue', () => ({ __esModule: true, ...mockLibrary() }))
jest.mock('vue', () => ({ ...jest.requireActual('vue'), ...mockLibrary() }))
jest.mock('vue-material-design-icons/Magnify.vue', () => ({ __esModule: true, default: { template: '<i />' } }))
jest.mock('../../store/store.js', () => {
	const { reactive } = jest.requireActual('vue')
	return {
		__esModule: true,
		dashboardStore: reactive({ loading: false, registers: [] }),
		objectStore: reactive({
			metadata: {},
			initializeColumnFilters: jest.fn(),
			refreshObjectList: jest.fn(),
			setFilters: jest.fn(),
		}),
		registerStore: reactive({ registerList: [{ id: 1, schemas: [] }], registerItem: null, refreshRegisterList: jest.fn() }),
		schemaStore: reactive({ schemaList: [{ id: 2 }], schemaItem: null, refreshSchemaList: jest.fn() }),
		navigationStore: reactive({}),
	}
})

/**
 * Library stand-ins for the sidebar shell (hoisted for the factories).
 *
 * @return {object}
 */
function mockLibrary() {
	const slot = (name) => ({ name, template: '<div><slot /></div>' })
	return {
		NcAppSidebar: slot('NcAppSidebar'),
		NcAppSidebarTab: slot('NcAppSidebarTab'),
		NcLoadingIcon: slot('NcLoadingIcon'),
		NcSelect: slot('NcSelect'),
	}
}

// The scope filter and stats tables are separate components with their own
// specs; they are stubbed so this spec only drives the sidebar's own logic.
const STUBS = { StatisticsScopeFilter: true, SidebarStatsSection: true, StatisticsTotalsTable: true }

/**
 * Mount the sidebar with its child sections stubbed.
 *
 * @return {object} The wrapper.
 */
const mountSidebar = () => mount(DashboardSideBar, { global: { stubs: STUBS } })

describe('DashboardSideBar — search and object reload', () => {
	beforeEach(() => {
		jest.useFakeTimers()
		jest.clearAllMocks()
		registerStore.registerItem = null
		schemaStore.schemaItem = null
		dashboardStore.registers = []
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	it('initialises column filters and loads objects on mount when a register and schema are selected', async () => {
		registerStore.registerItem = { id: 1 }
		schemaStore.schemaItem = { id: 2 }
		mountSidebar()
		await flushPromises()
		expect(objectStore.initializeColumnFilters).toHaveBeenCalledTimes(1)
		expect(objectStore.refreshObjectList).toHaveBeenCalledWith()
	})

	it('does not load objects on mount without a selection', async () => {
		mountSidebar()
		await flushPromises()
		expect(objectStore.initializeColumnFilters).toHaveBeenCalledTimes(1)
		expect(objectStore.refreshObjectList).not.toHaveBeenCalled()
	})

	it('debounces a search: one filter write and reload, a second after the last keystroke', async () => {
		registerStore.registerItem = { id: 1 }
		schemaStore.schemaItem = { id: 2 }
		const wrapper = mountSidebar()
		await flushPromises()
		objectStore.refreshObjectList.mockClear()

		wrapper.vm.$data.searchQuery = 'jan'
		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()
		jest.advanceTimersByTime(500)
		wrapper.vm.$data.searchQuery = 'jansen'
		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()
		jest.advanceTimersByTime(999)
		expect(objectStore.setFilters).not.toHaveBeenCalled()

		jest.advanceTimersByTime(1)
		expect(objectStore.setFilters).toHaveBeenCalledTimes(1)
		expect(objectStore.setFilters).toHaveBeenCalledWith({ _search: 'jansen' })
		expect(objectStore.refreshObjectList).toHaveBeenCalledWith({ register: 1, schema: 2 })
	})

	it('a cleared search writes an empty filter and skips the reload without a selection', async () => {
		const wrapper = mountSidebar()
		wrapper.vm.$data.searchQuery = 'x'
		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()
		jest.advanceTimersByTime(1000)
		wrapper.vm.$data.searchQuery = ''
		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()
		jest.advanceTimersByTime(1000)
		expect(objectStore.setFilters).toHaveBeenLastCalledWith({ _search: '' })
		expect(objectStore.refreshObjectList).not.toHaveBeenCalled()
	})

	it('handleSearch reloads with the search term only when a register and schema are selected', async () => {
		const wrapper = mountSidebar()
		wrapper.vm.$data.searchQuery = 'q'
		await wrapper.vm.$nextTick()
		wrapper.vm.handleSearch()
		expect(objectStore.refreshObjectList).not.toHaveBeenCalled()

		registerStore.registerItem = { id: 1 }
		schemaStore.schemaItem = { id: 2 }
		wrapper.vm.handleSearch()
		expect(objectStore.refreshObjectList).toHaveBeenCalledWith({ register: 1, schema: 2, search: 'q' })
	})
})
