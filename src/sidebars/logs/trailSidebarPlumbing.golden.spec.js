/**
 * Golden record of the plumbing the trail sidebars share: the debounced
 * filter apply, the clear-filters alias, the mount-time list loads and the
 * filtered-count listener.
 *
 * @spec exclude Golden record of client-side sidebar plumbing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import AuditTrailSideBar from './AuditTrailSideBar.vue'
import SearchTrailSideBar from './SearchTrailSideBar.vue'
import eventBus from '../../eventBus.js'
import { registerStore, schemaStore } from '../../store/store.js'

jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('../../eventBus.js', () => ({
	__esModule: true,
	default: { emit: jest.fn(), on: jest.fn(), off: jest.fn() },
}))
jest.mock('vue-material-design-icons/ChartLine.vue', () => ({}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Delete.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/FilterOffOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/FilterOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Plus.vue', () => ({}))
jest.mock('vue-material-design-icons/DatabaseOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/MagnifyPlus.vue', () => ({}))
jest.mock('vue-material-design-icons/Monitor.vue', () => ({}))
jest.mock('vue-material-design-icons/TrendingUp.vue', () => ({}))
jest.mock('vue-material-design-icons/AccountCircle.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	auditTrailStore: { auditTrailList: [], auditTrailPagination: {} },
	searchTrailStore: { searchTrailList: [] },
	registerStore: { registerList: [], refreshRegisterList: jest.fn() },
	schemaStore: { schemaList: [], refreshSchemaList: jest.fn() },
	navigationStore: {},
}))

/**
 * The methods an instance of a component ends up with: its mixins' first,
 * its own on top.
 *
 * @param {object} component The component options
 * @return {object} The methods by name
 */
function methodsOf(component) {
	return Object.assign(
		{},
		...(component.mixins || []).map((mixin) => mixin.methods || {}),
		component.methods,
	)
}

/**
 * A bare instance of a component with its methods and data.
 *
 * @param {object} component The component options
 * @return {object} The instance
 */
function instanceOf(component) {
	return {
		...methodsOf(component),
		...component.data(),
		$route: { path: '/elsewhere', query: {} },
	}
}

const SIDEBARS = [
	['AuditTrailSideBar', AuditTrailSideBar, 'audit-trail-filtered-count'],
	['SearchTrailSideBar', SearchTrailSideBar, 'search-trail-filtered-count'],
]

describe.each(SIDEBARS)('%s plumbing', (_name, component, countEvent) => {
	beforeEach(() => {
		jest.useFakeTimers()
		eventBus.on.mockClear()
		registerStore.refreshRegisterList.mockClear()
		schemaStore.refreshSchemaList.mockClear()
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	it('applies the filters once, 500 ms after the last keystroke', () => {
		const vm = instanceOf(component)
		vm.applyFilters = jest.fn()

		vm.debouncedApplyFilters()
		jest.advanceTimersByTime(300)
		vm.debouncedApplyFilters()
		jest.advanceTimersByTime(499)
		expect(vm.applyFilters).not.toHaveBeenCalled()

		jest.advanceTimersByTime(1)
		expect(vm.applyFilters).toHaveBeenCalledTimes(1)
	})

	it('clears the filters through clearAllFilters', () => {
		const vm = instanceOf(component)
		vm.clearAllFilters = jest.fn()

		vm.clearFilters()

		expect(vm.clearAllFilters).toHaveBeenCalledTimes(1)
	})

	it('loads only the empty scope lists on mount and follows the count', () => {
		const vm = instanceOf(component)
		for (const name of Object.keys(vm)) {
			if (/^load/.test(name)) vm[name] = jest.fn()
		}
		vm.updateFilteredCount = jest.fn()
		vm.applyQueryParamsFromRoute = jest.fn()
		registerStore.registerList = []
		schemaStore.schemaList = [{ id: 1 }]

		component.mounted.call(vm)

		expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(1)
		expect(schemaStore.refreshSchemaList).not.toHaveBeenCalled()
		expect(vm.updateFilteredCount).toHaveBeenCalled()
		expect(vm.applyQueryParamsFromRoute).toHaveBeenCalledTimes(1)
		expect(eventBus.on).toHaveBeenCalledWith(countEvent, expect.any(Function))

		const [, listener] = eventBus.on.mock.calls.find(([e]) => e === countEvent)
		listener(7)
		expect(vm.filteredCount).toBe(7)
	})

	it('loads both lists when both are empty', () => {
		const vm = instanceOf(component)
		for (const name of Object.keys(vm)) {
			if (/^load|^update|^apply/.test(name)) vm[name] = jest.fn()
		}
		registerStore.registerList = []
		schemaStore.schemaList = []

		component.mounted.call(vm)

		expect(registerStore.refreshRegisterList).toHaveBeenCalledTimes(1)
		expect(schemaStore.refreshSchemaList).toHaveBeenCalledTimes(1)
	})
})
