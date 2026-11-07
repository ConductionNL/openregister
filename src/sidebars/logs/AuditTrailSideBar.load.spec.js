/**
 * The audit trail page loads its list once: the index loads it on mount, the
 * sidebar only when the filters differ from what the store holds.
 *
 * @spec exclude Client-side load plumbing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import AuditTrailSideBar from './AuditTrailSideBar.vue'
import eventBus from '../../eventBus.js'
import { auditTrailStore, registerStore, schemaStore } from '../../store/store.js'

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
	auditTrailStore: {
		auditTrailFilters: {},
		auditTrailList: [],
		refreshAuditTrailList: jest.fn(),
		setAuditTrailFilters: jest.fn(function (filters) {
			this.auditTrailFilters = filters
		}),
	},
	registerStore: {
		registerItem: null,
		registerList: [{ id: 1 }],
		refreshRegisterList: jest.fn(),
	},
	schemaStore: {
		schemaItem: null,
		schemaList: [{ id: 2 }],
		refreshSchemaList: jest.fn(),
	},
	searchTrailStore: {},
}))

/**
 * A sidebar instance on the audit trail route.
 *
 * @param {object} query The route query.
 * @return {object} The instance.
 */
function sidebar(query = {}) {
	return {
		...AuditTrailSideBar.methods,
		...AuditTrailSideBar.data(),
		$route: { path: '/audit-trails', query },
		actionOptions: [],
		loadStatistics: jest.fn(),
		loadActionDistribution: jest.fn(),
		loadTopObjects: jest.fn(),
		updateFilteredCount: jest.fn(),
	}
}

describe('AuditTrailSideBar list load', () => {
	beforeEach(() => {
		auditTrailStore.auditTrailFilters = {}
		auditTrailStore.refreshAuditTrailList.mockClear()
		eventBus.emit.mockClear()
		registerStore.registerItem = null
		schemaStore.schemaItem = null
	})

	it('leaves the first load to the index when the route names no filter', () => {
		AuditTrailSideBar.mounted.call(sidebar())

		expect(auditTrailStore.refreshAuditTrailList).not.toHaveBeenCalled()
		expect(eventBus.emit).not.toHaveBeenCalledWith(
			'audit-trail-filters-changed',
			expect.anything(),
		)
	})

	it('loads the list when the route names a filter', () => {
		AuditTrailSideBar.mounted.call(sidebar({ object: 'u-1' }))

		expect(auditTrailStore.refreshAuditTrailList).toHaveBeenCalledTimes(1)
		expect(auditTrailStore.auditTrailFilters).toEqual({ object: 'u-1' })
		expect(eventBus.emit).toHaveBeenCalledWith('audit-trail-filters-changed', {
			object: 'u-1',
		})
	})

	it('loads the list when the filters are cleared again', () => {
		auditTrailStore.auditTrailFilters = { object: 'u-1' }

		sidebar().applyFiltersToStore()

		expect(auditTrailStore.auditTrailFilters).toEqual({})
		expect(auditTrailStore.refreshAuditTrailList).toHaveBeenCalledTimes(1)
	})
})
