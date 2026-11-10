/**
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
 */
import { defineStore } from 'pinia'
import {
	copiedResults,
	getRequest,
	listPageQuery,
	listPagination,
	statisticsOrEmpty,
	storeRequest,
	withoutIds,
} from '../../services/listPage.js'

const apiUrl = '/index.php/apps/openregister/api'

/**
 * The statistics with every count at 0.
 *
 * @return {object} Every count at 0.
 * @spec exclude Client-side empty state of the statistics; no backend contract.
 */
function emptyStatistics() {
	return {
		total: 0,
		create: 0,
		update: 0,
		delete: 0,
		read: 0,
	}
}

/**
 * Store for managing audit trail logs
 * Provides functionality for retrieving, filtering, and managing audit trail entries
 */
let pendingRefresh = null
let requestInFlight = null

export const useAuditTrailStore = defineStore('auditTrail', {
	state: () => ({
		// Loading states
		auditTrailLoading: false,
		statisticsLoading: false,

		// Data
		auditTrailList: [],
		auditTrailItem: null,

		// Pagination
		auditTrailPagination: listPagination({}, 50),

		// Statistics
		statistics: emptyStatistics(),

		// Filters
		auditTrailFilters: {},
		auditTrailSearch: '',

		// Sort: `{ field: 'ASC' | 'DESC' }`, empty for the server default
		// (newest first). Kept here so paging keeps the order a header chose.
		auditTrailSort: {},
	}),

	actions: {
		/**
		 * Set audit trail list
		 *
		 * @param {Array} auditTrailList - The audit trail list to set
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		setAuditTrailList(auditTrailList) {
			// Ensure we have a clean array without reactive references
			this.auditTrailList = Array.isArray(auditTrailList)
				? [...auditTrailList]
				: []
		},

		/**
		 * Set audit trail item
		 *
		 * @param {object} auditTrailItem - The audit trail item to set
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		setAuditTrailItem(auditTrailItem) {
			this.auditTrailItem = auditTrailItem
		},

		/**
		 * Set audit trail pagination
		 *
		 * @param {object} pagination - The pagination object
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		setAuditTrailPagination(pagination) {
			this.auditTrailPagination = {
				...this.auditTrailPagination,
				...pagination,
			}
		},

		/**
		 * Set statistics
		 *
		 * @param {object} stats - The statistics object
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		setStatistics(stats) {
			this.statistics = {
				...this.statistics,
				...stats,
			}
		},

		/**
		 * Set audit trail filters
		 *
		 * @param {object} filters - The filters to set
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		setAuditTrailFilters(filters) {
			this.auditTrailFilters = filters
		},

		/**
		 * Set audit trail search
		 *
		 * @param {string} search - The search term
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		setAuditTrailSearch(search) {
			this.auditTrailSearch = search
		},

		/**
		 * Set the sort the list is fetched in.
		 *
		 * @param {object} sort `{ field: 'ASC' | 'DESC' }`, or `{}` for the default.
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md
		 */
		setAuditTrailSort(sort) {
			this.auditTrailSort = sort && typeof sort === 'object' ? { ...sort } : {}
		},

		/**
		 * Fetch audit trails with optional filtering and pagination
		 *
		 * @param {object} options - Options for fetching
		 * @return {Promise<object>} The fetched data
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async fetchAuditTrails(options = {}) {
			const query = listPageQuery(options, {
				search: this.auditTrailSearch,
				filters: this.auditTrailFilters,
				sort: this.auditTrailSort,
			})
			return storeRequest(this, {
				flag: 'auditTrailLoading',
				url: `${apiUrl}/audit-trails?${query}`,
				request: getRequest('Failed to fetch audit trails'),
				log: (error) => console.error('Error fetching audit trails:', error),
				keep: (data) => {
					this.setAuditTrailList(copiedResults(data))
					this.setAuditTrailPagination(listPagination(data, 50))
				},
			})
		},

		/**
		 * Fetch audit trail statistics
		 *
		 * @return {Promise<object>} The statistics data
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async fetchStatistics() {
			return storeRequest(this, {
				flag: 'statisticsLoading',
				url: `${apiUrl}/audit-trails/statistics`,
				request: { method: 'GET', failure: 'Failed to fetch statistics' },
				log: (error) => console.error('Error fetching statistics:', error),
				keep: (data) => this.setStatistics(data),
			})
		},

		/**
		 * Delete a single audit trail
		 *
		 * @param {string|number} id - The ID of the audit trail to delete
		 * @return {Promise<object>} The response data
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async deleteAuditTrail(id) {
			return storeRequest(this, {
				url: `${apiUrl}/audit-trails/${id}`,
				request: {
					method: 'DELETE',
					failure: 'Failed to delete audit trail',
				},
				log: (error) => console.error('Error deleting audit trail:', error),
				keep: () => {
					this.auditTrailList = withoutIds(this.auditTrailList, [id])
				},
			})
		},

		/**
		 * Delete multiple audit trails
		 *
		 * @param {Array} ids - Array of audit trail IDs to delete
		 * @return {Promise<object>} The response data
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async deleteMultipleAuditTrails(ids) {
			return storeRequest(this, {
				url: `${apiUrl}/audit-trails`,
				request: {
					method: 'DELETE',
					body: { ids },
					failure: 'Failed to delete audit trails',
				},
				log: (error) => console.error('Error deleting audit trails:', error),
				keep: () => {
					this.auditTrailList = withoutIds(this.auditTrailList, ids)
				},
			})
		},

		/**
		 * Refresh audit trail list with current filters. The page and its
		 * sidebar both ask for this while mounting, so the refreshes of one
		 * tick share one request, which reads the filters as they end up.
		 *
		 * @return {Promise} The refresh promise
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		refreshAuditTrailList() {
			pendingRefresh ??= Promise.resolve().then(() => {
				pendingRefresh = null
				return this.sharedListRequest()
			})
			return pendingRefresh
		},

		/**
		 * The list request for the current query: one already on its way is
		 * shared, so a sidebar and a page that mount a moment apart ask once.
		 *
		 * @return {Promise} The request
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-has
		 */
		sharedListRequest() {
			const { limit, page } = this.auditTrailPagination
			const key = JSON.stringify([
				this.auditTrailFilters,
				this.auditTrailSearch,
				this.auditTrailSort,
				limit,
				page,
			])
			if (requestInFlight?.key === key) {
				return requestInFlight.promise
			}
			const request = { key }
			request.promise = this.fetchAuditTrails({ limit, page }).finally(() => {
				if (requestInFlight === request) requestInFlight = null
			})
			requestInFlight = request
			return request.promise
		},

		/**
		 * Get audit trail statistics
		 *
		 * @return {Promise<object>} The statistics
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async getStatistics() {
			return statisticsOrEmpty(this, {
				empty: emptyStatistics,
				log: (error) => console.error('Error getting statistics:', error),
			})
		},

		/**
		 * Get action distribution data
		 *
		 * @return {Promise<Array>} The action distribution
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async getActionDistribution() {
			try {
				// Calculate from current audit trail list
				const actions = ['create', 'update', 'delete', 'read']
				const total = this.auditTrailList.length

				return actions
					.map((action) => {
						const count = this.auditTrailList.filter(
							(item) => item.action === action,
						).length
						return {
							action,
							count,
							percentage:
								total > 0 ? Math.round((count / total) * 100) : 0,
						}
					})
					.filter((item) => item.count > 0)
			} catch (error) {
				console.error('Error getting action distribution:', error)
				return []
			}
		},

		/**
		 * Get top objects by audit trail count
		 *
		 * @return {Promise<Array>} The top objects
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async getTopObjects() {
			try {
				// Count audit trails per object
				const objectCounts = {}
				this.auditTrailList.forEach((item) => {
					if (item.object) {
						objectCounts[item.object] =
							(objectCounts[item.object] || 0) + 1
					}
				})

				// Sort by count and return top 10
				return Object.entries(objectCounts)
					.map(([objectId, count]) => ({
						id: objectId,
						name: `Object ${objectId}`,
						count,
					}))
					.sort((a, b) => b.count - a.count)
					.slice(0, 10)
			} catch (error) {
				console.error('Error getting top objects:', error)
				return []
			}
		},

		/**
		 * Clear all audit trail store data
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		clearAuditTrailStore() {
			this.auditTrailList = []
			this.auditTrailItem = null
			this.auditTrailPagination = listPagination({}, 50)
			this.statistics = emptyStatistics()
			this.auditTrailFilters = {}
			this.auditTrailSearch = ''
		},
	},
})
