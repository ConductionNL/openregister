import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { watch } from 'vue'
import { useRegisterStore } from './register.js'
import { useSchemaStore } from './schema.js'

const CHARTS = [
	'auditTrailActions',
	'objectsByRegister',
	'objectsBySchema',
	'objectsBySize',
]
const STATISTICS = ['auditTrailStats', 'actionDistribution', 'mostActiveObjects']

/**
 * An object with every key set to the same value.
 *
 * @param {Array<string>} keys The keys.
 * @param {null|boolean} value The value.
 * @return {object} The object.
 * @spec exclude Client-side shape of the dashboard panel state.
 */
function each(keys, value) {
	return Object.fromEntries(keys.map((key) => [key, value]))
}

/**
 * The dashboard store's state before anything is loaded.
 *
 * @return {object} The state.
 * @spec openspec/specs/frontend-store-client-state/spec.md
 */
function initialState() {
	return {
		registers: [],
		loading: false,
		error: null,
		isInitialized: false,
		dateRange: { from: null, till: null },
		chartData: each(CHARTS, null),
		chartLoading: each(CHARTS, false),
		statisticsData: each(STATISTICS, null),
		statisticsLoading: each(STATISTICS, false),
	}
}

/**
 * The register and schema the dashboard is scoped to, as request parameters.
 *
 * @return {{registerId: (string|number|undefined), schemaId: (string|number|undefined)}} The parameters.
 * @spec exclude Client-side scope of the dashboard API passthroughs.
 */
function scopeParams() {
	return {
		registerId: useRegisterStore().registerItem?.id,
		schemaId: useSchemaStore().schemaItem?.id,
	}
}

/**
 * Load one dashboard panel into the store, unless it is already loading.
 *
 * @param {object} store The dashboard store.
 * @param {object} panel `group` (`chart` or `statistics`), `key`, `path` below the dashboard API, `params()` and `log`.
 * @return {Promise<void>}
 * @spec exclude API passthrough helper for the dashboard chart and statistics endpoints.
 */
async function fetchPanel(store, panel) {
	const loading = store[`${panel.group}Loading`]
	if (loading[panel.key]) return

	loading[panel.key] = true
	try {
		store[`${panel.group}Data`][panel.key] = await getPanel(panel)
	} finally {
		loading[panel.key] = false
	}
}

/**
 * The data of one dashboard panel. A failure is logged and rethrown.
 *
 * @param {object} panel `path` below the dashboard API, `params()` and `log`.
 * @return {Promise<object>} The panel data.
 * @spec exclude API passthrough helper for the dashboard chart and statistics endpoints.
 */
async function getPanel(panel) {
	try {
		const response = await axios.get(
			generateUrl(`/apps/openregister/api/dashboard/${panel.path}`),
			{ params: panel.params() },
		)
		return response.data
	} catch (error) {
		console.error(panel.log, error)
		throw error
	}
}

/**
 * The register list filter of the dashboard: the active register and schema,
 * where set. The stores are looked up now; their ids are read when the
 * returned function runs.
 *
 * @return {function(): object} The parameters, read on call.
 * @spec exclude Client-side scope of GET /api/dashboard.
 */
function registerListScope() {
	const registerStore = useRegisterStore()
	const schemaStore = useSchemaStore()
	return () => {
		const params = {}
		if (registerStore.registerItem?.id)
			params.registerId = registerStore.registerItem.id
		if (schemaStore.schemaItem?.id) params.schemaId = schemaStore.schemaItem.id
		return params
	}
}

/**
 * Load the dashboard register list into the store.
 *
 * @param {object} store The dashboard store.
 * @param {function(): object} params The register and schema filter, read on call.
 * @return {Promise<Array<object>>} The registers.
 * @spec exclude API passthrough to GET /api/dashboard (register list)
 */
async function loadRegisters(store, params) {
	const response = await axios.get(
		generateUrl('/apps/openregister/api/dashboard'),
		{ params: params() },
	)
	store.registers = response.data.registers
	return store.registers
}

export const useDashboardStore = defineStore('dashboard', {
	state: initialState,

	getters: {
		getRegisters: (state) => state.registers,
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
		getSystemTotals: (state) =>
			state.registers.find((register) => register.title === 'System Totals'),
		getOrphanedItems: (state) =>
			state.registers.find((register) => register.title === 'Orphaned Items'),
		getDateRange: (state) => state.dateRange,
		getChartData: (state) => state.chartData,
		isChartLoading: (state) => state.chartLoading,
		getStatisticsData: (state) => state.statisticsData,
		isStatisticsLoading: (state) => state.statisticsLoading,
	},

	actions: {
		/**
		 * Set the date range for audit trail chart and refresh it
		 *
		 * @param {string|null} from - Start date
		 * @param {string|null} till - End date
		 * @return {void}
		 * @spec exclude store setter (local date-range state; triggers a fetch)
		 */
		setDateRange(from = null, till = null) {
			this.dateRange = { from, till }
			// Refresh audit trail chart when date range changes
			this.fetchAuditTrailActionChart()
		},

		/**
		 * Initialize the dashboard store and set up watchers for register and schema changes
		 *
		 * @return {void}
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		init() {
			const registerStore = useRegisterStore()
			const schemaStore = useSchemaStore()

			// Watch for changes in the active register or schema and refresh dashboard data
			watch(
				[
					() => registerStore.registerItem?.id,
					() => schemaStore.schemaItem?.id,
				],
				async () => {
					// Fetch registers to update sidebar tables
					await this.fetchRegisters()
					// Fetch all chart data to update dashboard charts
					await this.fetchAllChartData()
					// Fetch all statistics to update audit trail sidebar
					await this.fetchAllStatistics()
				},
			)
		},

		/**
		 * Fetch audit trail action chart data
		 *
		 * @return {Promise<void>}
		 * @spec exclude API passthrough to GET /api/dashboard/charts/audit-trail-actions
		 */
		async fetchAuditTrailActionChart() {
			return fetchPanel(this, {
				group: 'chart',
				key: 'auditTrailActions',
				path: 'charts/audit-trail-actions',
				params: () => ({
					from: this.dateRange.from,
					till: this.dateRange.till,
					...scopeParams(),
				}),
				log: 'Error fetching audit trail action chart:',
			})
		},

		/**
		 * Fetch objects by register chart data
		 *
		 * @return {Promise<void>}
		 * @spec exclude API passthrough to GET /api/dashboard/charts/objects-by-register
		 */
		async fetchObjectsByRegisterChart() {
			return fetchPanel(this, {
				group: 'chart',
				key: 'objectsByRegister',
				path: 'charts/objects-by-register',
				params: scopeParams,
				log: 'Error fetching objects by register chart:',
			})
		},

		/**
		 * Fetch objects by schema chart data
		 *
		 * @return {Promise<void>}
		 * @spec exclude API passthrough to GET /api/dashboard/charts/objects-by-schema
		 */
		async fetchObjectsBySchemaChart() {
			return fetchPanel(this, {
				group: 'chart',
				key: 'objectsBySchema',
				path: 'charts/objects-by-schema',
				params: scopeParams,
				log: 'Error fetching objects by schema chart:',
			})
		},

		/**
		 * Fetch objects by size chart data
		 *
		 * @return {Promise<void>}
		 * @spec exclude API passthrough to GET /api/dashboard/charts/objects-by-size
		 */
		async fetchObjectsBySizeChart() {
			return fetchPanel(this, {
				group: 'chart',
				key: 'objectsBySize',
				path: 'charts/objects-by-size',
				params: scopeParams,
				log: 'Error fetching objects by size chart:',
			})
		},

		/**
		 * Fetch all chart data in parallel
		 *
		 * @return {Promise<void>}
		 * @spec exclude parallel fan-out wrapper over chart-fetch passthroughs
		 */
		async fetchAllChartData() {
			await Promise.all([
				this.fetchAuditTrailActionChart(),
				this.fetchObjectsByRegisterChart(),
				this.fetchObjectsBySchemaChart(),
				this.fetchObjectsBySizeChart(),
			])
		},

		/**
		 * Fetch audit trail statistics
		 *
		 * @param {number|null} hours - Number of hours to look back for recent activity (default: 24)
		 * @return {Promise<void>}
		 * @spec exclude API passthrough to GET /api/dashboard/statistics/audit-trail
		 */
		async fetchAuditTrailStatistics(hours = 24) {
			return fetchPanel(this, {
				group: 'statistics',
				key: 'auditTrailStats',
				path: 'statistics/audit-trail',
				params: () => ({ ...scopeParams(), hours }),
				log: 'Error fetching audit trail statistics:',
			})
		},

		/**
		 * Fetch action distribution data
		 *
		 * @param {number|null} hours - Number of hours to look back (default: 24)
		 * @return {Promise<void>}
		 * @spec exclude API passthrough to GET /api/dashboard/statistics/audit-trail-distribution
		 */
		async fetchActionDistribution(hours = 24) {
			return fetchPanel(this, {
				group: 'statistics',
				key: 'actionDistribution',
				path: 'statistics/audit-trail-distribution',
				params: () => ({ ...scopeParams(), hours }),
				log: 'Error fetching action distribution:',
			})
		},

		/**
		 * Fetch most active objects
		 *
		 * @param {number|null} limit - Number of results to return (default: 10)
		 * @param {number|null} hours - Number of hours to look back (default: 24)
		 * @return {Promise<void>}
		 * @spec exclude API passthrough to GET /api/dashboard/statistics/most-active-objects
		 */
		async fetchMostActiveObjects(limit = 10, hours = 24) {
			return fetchPanel(this, {
				group: 'statistics',
				key: 'mostActiveObjects',
				path: 'statistics/most-active-objects',
				params: () => ({ ...scopeParams(), limit, hours }),
				log: 'Error fetching most active objects:',
			})
		},

		/**
		 * Fetch all statistics data in parallel
		 *
		 * @param {number|null} hours - Number of hours to look back (default: 24)
		 * @param {number|null} limit - Number of results for most active objects (default: 10)
		 * @return {Promise<void>}
		 * @spec exclude parallel fan-out wrapper over statistic-fetch passthroughs
		 */
		async fetchAllStatistics(hours = 24, limit = 10) {
			await Promise.all([
				this.fetchAuditTrailStatistics(hours),
				this.fetchActionDistribution(hours),
				this.fetchMostActiveObjects(limit, hours),
			])
		},

		/**
		 * Preload dashboard data
		 *
		 * @return {Promise<Array>}
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		async preload() {
			if (!this.isInitialized && !this.loading) {
				await this.fetchRegisters()
				await this.fetchAllChartData()
				await this.fetchAllStatistics()
				this.isInitialized = true
			}
			return this.registers
		},

		/**
		 * Fetch registers for dashboard, filtered by the current register and schema from the stores
		 *
		 * @return {Promise<Array>}
		 * @spec exclude API passthrough to GET /api/dashboard (register list)
		 */
		async fetchRegisters() {
			const params = registerListScope()
			try {
				this.loading = true
				this.error = null
				return await loadRegisters(this, params)
			} catch (error) {
				console.error('Error fetching registers:', error)
				this.error = error.message || 'Failed to fetch registers'
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Calculate sizes for a register and refresh
		 *
		 * @param {string} registerId - The ID of the register
		 * @return {Promise<boolean>}
		 * @spec exclude API passthrough to POST /api/dashboard/calculate/{registerId}
		 */
		async calculateSizes(registerId) {
			try {
				await axios.post(
					generateUrl(
						`/apps/openregister/api/dashboard/calculate/${registerId}`,
					),
				)
				// Refresh the registers after calculation
				await this.fetchRegisters()
				return true
			} catch (error) {
				console.error('Error calculating sizes:', error)
				throw error
			}
		},

		/**
		 * Reset dashboard store state
		 *
		 * @return {void}
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		reset() {
			Object.assign(this, initialState())
		},
	},
})

/**
 * Sets up watchers for register and schema changes to refresh dashboard data.
 * Call this function once in your app entry point after creating the stores.
 *
 * @return {void}
 * @spec openspec/specs/frontend-store-client-state/spec.md
 */
export function setupDashboardStoreWatchers() {
	const dashboardStore = useDashboardStore()
	const registerStore = useRegisterStore()
	const schemaStore = useSchemaStore()

	// Watch for changes in the active register or schema and refresh dashboard data
	watch(
		[() => registerStore.registerItem?.id, () => schemaStore.schemaItem?.id],
		() => {
			// Fetch registers to update sidebar tables, using current store state
			dashboardStore.fetchRegisters()
			// Fetch all chart data to update dashboard charts
			dashboardStore.fetchAllChartData()
			// Fetch all statistics to update audit trail sidebar
			dashboardStore.fetchAllStatistics()
		},
	)
}
