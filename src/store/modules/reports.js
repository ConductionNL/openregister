/**
 * Reports / Rapportage Store Module
 *
 * Wraps the OpenRegister rapportage surface:
 *   - List + load dashboards (objects in the `reports` register).
 *   - Fetch widget data per widget descriptor:
 *       - aggregation -> GET /api/objects/aggregations/{register}/{schema}/{name}
 *       - graphql     -> POST /api/graphql
 *       - statistics  -> GET /api/dashboard/statistics
 *   - Per-widget memo cache keyed on data-source identity so a
 *     dashboard with two widgets that share the same aggregation
 *     doesn't fire the request twice.
 *
 * Dashboards themselves are first-class objects — operators manage
 * them via the standard schema/object UI. The list/show actions here
 * are convenience wrappers that filter the existing object endpoint
 * to the `reports` register.
 *
 * @package
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

/* eslint-disable no-console */
import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const API_BASE = generateUrl('/apps/openregister/api')

/**
 * Slug of the operator-imported `reports` register. Operators can
 * rename / re-slug locally; `REPORTS_REGISTER_OVERRIDE` on the window
 * object lets a different deployment pin the dashboard list to a
 * non-default register.
 */
export const DEFAULT_REPORTS_REGISTER = 'reports'

/**
 * Slug of the `dashboard` schema inside the reports register.
 */
export const DEFAULT_DASHBOARD_SCHEMA = 'dashboard'

/**
 * Build a stable cache key for a widget's data source.
 *
 * @param {object} dataSource - Widget data-source descriptor.
 * @return {string} Cache key.
 */
function widgetCacheKey(dataSource) {
	if (!dataSource || !dataSource.mode) return ''
	const mode = dataSource.mode
	if (mode === 'aggregation') {
		return `agg:${dataSource.register || ''}:${dataSource.schema || ''}:${dataSource.aggregation || ''}`
	}
	if (mode === 'graphql') {
		return `gql:${(dataSource.graphqlQuery || '').slice(0, 200)}`
	}
	if (mode === 'statistics') {
		return `stats:${dataSource.register || ''}:${dataSource.schema || ''}`
	}
	return `unknown:${mode}`
}

export const useReportsStore = defineStore('reports', {
	state: () => ({
		dashboards: [],
		activeDashboard: null,
		// Per-widget data cache keyed by data-source identity.
		// { [cacheKey]: { data, fetchedAt, error } }
		widgetData: {},
		loading: false,
		error: null,
	}),
	getters: {
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
		getDashboards: (state) => state.dashboards,
		getActiveDashboard: (state) => state.activeDashboard,
		/**
		 * @param {object} state Pinia state.
		 */
		getWidgetData: (state) => (cacheKey) => state.widgetData[cacheKey] ?? null,
	},
	actions: {
		clearError() {
			this.error = null
		},

		/**
		 * Reset cached widget data — useful after editing a dashboard
		 * to force fresh fetches on the next render.
		 */
		clearWidgetCache() {
			this.widgetData = {}
		},

		/**
		 * List dashboards in the configured `reports` register.
		 *
		 * @param {object} params Optional filters (status, category, …).
		 */
		async fetchDashboards(params = {}) {
			this.loading = true
			this.error = null
			const register = params.register || DEFAULT_REPORTS_REGISTER
			const schema = params.schema || DEFAULT_DASHBOARD_SCHEMA
			try {
				const response = await axios.get(
					`${API_BASE}/objects/${encodeURIComponent(register)}/${encodeURIComponent(schema)}`,
					{ params: { _limit: 200, ...params } },
				)
				this.dashboards = response.data?.results ?? response.data ?? []
				return this.dashboards
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to fetch dashboards'
				console.error('[reports.fetchDashboards]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch a single dashboard by id|uuid|slug.
		 *
		 * @param {string} identifier
		 * @param {object} params {register?, schema?}
		 */
		async fetchDashboard(identifier, params = {}) {
			if (!identifier) return null
			this.loading = true
			this.error = null
			const register = params.register || DEFAULT_REPORTS_REGISTER
			const schema = params.schema || DEFAULT_DASHBOARD_SCHEMA
			try {
				const response = await axios.get(
					`${API_BASE}/objects/${encodeURIComponent(register)}/${encodeURIComponent(schema)}/${encodeURIComponent(identifier)}`,
				)
				this.activeDashboard = response.data ?? null
				return this.activeDashboard
			} catch (e) {
				this.error = e.response?.data?.error ?? e.message ?? 'Failed to fetch dashboard'
				console.error('[reports.fetchDashboard]', e)
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the data for a single widget. Memoised by data-source
		 * identity so two widgets sharing the same source dispatch one
		 * network call.
		 *
		 * @param {object} widget Widget descriptor with `dataSource`.
		 * @param {boolean} forceRefresh When true, bypasses the cache.
		 * @return {Promise<object>}
		 */
		async fetchWidgetData(widget, forceRefresh = false) {
			const dataSource = widget?.dataSource
			if (!dataSource) return null
			const key = widgetCacheKey(dataSource)
			if (!key) return null

			if (!forceRefresh && this.widgetData[key]?.data) {
				return this.widgetData[key].data
			}

			try {
				let data = null
				if (dataSource.mode === 'aggregation') {
					data = await this._fetchAggregation(dataSource)
				} else if (dataSource.mode === 'graphql') {
					data = await this._fetchGraphql(dataSource)
				} else if (dataSource.mode === 'statistics') {
					data = await this._fetchStatistics(dataSource)
				}

				this.widgetData = {
					...this.widgetData,
					[key]: {
						data,
						fetchedAt: new Date().toISOString(),
						error: null,
					},
				}
				return data
			} catch (e) {
				const message = e.response?.data?.error ?? e.message ?? 'Failed to fetch widget data'
				this.widgetData = {
					...this.widgetData,
					[key]: {
						data: null,
						fetchedAt: new Date().toISOString(),
						error: message,
					},
				}
				console.error('[reports.fetchWidgetData]', { widget, error: e })
				return null
			}
		},

		/**
		 * Fetch data for every widget on a dashboard. Returns a map
		 * keyed on widget index.
		 *
		 * @param {object} dashboard Dashboard object with `widgets[]`.
		 * @param {boolean} forceRefresh Bypass cache.
		 */
		async fetchDashboardData(dashboard, forceRefresh = false) {
			const widgets = dashboard?.widgets ?? []
			if (!Array.isArray(widgets) || widgets.length === 0) return {}
			const results = await Promise.all(
				widgets.map((widget) => this.fetchWidgetData(widget, forceRefresh)),
			)
			const out = {}
			widgets.forEach((widget, i) => {
				out[i] = results[i]
			})
			return out
		},

		// --- Internal fetchers below -----------------------------------

		async _fetchAggregation(dataSource) {
			const register = encodeURIComponent(dataSource.register || '')
			const schema = encodeURIComponent(dataSource.schema || '')
			const name = encodeURIComponent(dataSource.aggregation || '')
			if (!register || !schema || !name) {
				throw new Error('aggregation data-source requires register, schema, and aggregation name')
			}
			const response = await axios.get(
				`${API_BASE}/objects/aggregations/${register}/${schema}/${name}`,
			)
			return response.data
		},

		async _fetchGraphql(dataSource) {
			const query = dataSource.graphqlQuery
			if (!query) {
				throw new Error('graphql data-source requires graphqlQuery')
			}
			const response = await axios.post(`${API_BASE}/graphql`, { query })
			return response.data?.data ?? response.data
		},

		async _fetchStatistics(dataSource) {
			const register = dataSource.register || ''
			const schema = dataSource.schema || ''
			const params = {}
			if (register) params.registerId = register
			if (schema) params.schemaId = schema
			const response = await axios.get(`${API_BASE}/dashboard/statistics`, { params })
			return response.data
		},
	},
})
