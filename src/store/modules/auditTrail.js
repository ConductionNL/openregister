/**
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
 */
import { defineStore } from 'pinia'

const apiUrl = '/index.php/apps/openregister/api'

/**
 * Store for managing audit trail logs
 * Provides functionality for retrieving, filtering, and managing audit trail entries
 */
export const useAuditTrailStore = defineStore('auditTrail', {
	state: () => ({
		// Loading states
		auditTrailLoading: false,
		statisticsLoading: false,

		// Data
		auditTrailList: [],
		auditTrailItem: null,

		// Pagination
		auditTrailPagination: {
			total: 0,
			page: 1,
			pages: 1,
			limit: 50,
			offset: 0,
		},

		// Statistics
		statistics: {
			total: 0,
			create: 0,
			update: 0,
			delete: 0,
			read: 0,
		},

		// Filters
		auditTrailFilters: {},
		auditTrailSearch: '',
	}),

	actions: {
		/**
		 * Set audit trail list
		 * @param {Array} auditTrailList - The audit trail list to set
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		setAuditTrailList(auditTrailList) {
			// Ensure we have a clean array without reactive references
			this.auditTrailList = Array.isArray(auditTrailList) ? [...auditTrailList] : []
		},

		/**
		 * Set audit trail item
		 * @param {object} auditTrailItem - The audit trail item to set
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		setAuditTrailItem(auditTrailItem) {
			this.auditTrailItem = auditTrailItem
		},

		/**
		 * Set audit trail pagination
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
		 * @param {object} filters - The filters to set
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		setAuditTrailFilters(filters) {
			this.auditTrailFilters = filters
		},

		/**
		 * Set audit trail search
		 * @param {string} search - The search term
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		setAuditTrailSearch(search) {
			this.auditTrailSearch = search
		},

		/**
		 * Fetch audit trails with optional filtering and pagination
		 * @param {object} options - Options for fetching
		 * @return {Promise<object>} The fetched data
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async fetchAuditTrails(options = {}) {
			this.auditTrailLoading = true

			try {
				// Build query parameters
				const params = new URLSearchParams()

				// Add pagination
				if (options.limit) params.append('limit', options.limit)
				if (options.offset) params.append('offset', options.offset)
				if (options.page) params.append('page', options.page)

				// Add search
				if (options.search || this.auditTrailSearch) {
					params.append('search', options.search || this.auditTrailSearch)
				}

				// Add filters
				const filters = { ...this.auditTrailFilters, ...options.filters }
				Object.entries(filters).forEach(([key, value]) => {
					if (value !== null && value !== undefined && value !== '') {
						params.append(key, value)
					}
				})

				// Add sort
				if (options.sort) {
					Object.entries(options.sort).forEach(([field, direction]) => {
						params.append('sort', field)
						params.append('order', direction)
					})
				}

				const url = `${apiUrl}/audit-trails?${params.toString()}`

				const response = await fetch(url, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to fetch audit trails')
				}

				// Update store state - ensure we pass clean data
				this.setAuditTrailList(data.results ? JSON.parse(JSON.stringify(data.results)) : [])
				this.setAuditTrailPagination({
					total: data.total || 0,
					page: data.page || 1,
					pages: data.pages || 1,
					limit: data.limit || 50,
					offset: data.offset || 0,
				})

				return data
			} catch (error) {
				console.error('Error fetching audit trails:', error)
				throw error
			} finally {
				this.auditTrailLoading = false
			}
		},

		/**
		 * Fetch audit trail statistics
		 * @return {Promise<object>} The statistics data
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async fetchStatistics() {
			this.statisticsLoading = true

			try {
				const response = await fetch(`${apiUrl}/audit-trails/statistics`, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to fetch statistics')
				}

				this.setStatistics(data)
				return data
			} catch (error) {
				console.error('Error fetching statistics:', error)
				throw error
			} finally {
				this.statisticsLoading = false
			}
		},

		/**
		 * Delete a single audit trail
		 * @param {string|number} id - The ID of the audit trail to delete
		 * @return {Promise<object>} The response data
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async deleteAuditTrail(id) {
			try {
				const response = await fetch(`${apiUrl}/audit-trails/${id}`, {
					method: 'DELETE',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to delete audit trail')
				}

				// Remove from audit trail list
				this.auditTrailList = this.auditTrailList.filter(item => item.id !== id)

				return data
			} catch (error) {
				console.error('Error deleting audit trail:', error)
				throw error
			}
		},

		/**
		 * Delete multiple audit trails
		 * @param {Array} ids - Array of audit trail IDs to delete
		 * @return {Promise<object>} The response data
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async deleteMultipleAuditTrails(ids) {
			try {
				const response = await fetch(`${apiUrl}/audit-trails`, {
					method: 'DELETE',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ ids }),
				})

				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to delete audit trails')
				}

				// Remove deleted audit trails from list
				this.auditTrailList = this.auditTrailList.filter(item => !ids.includes(item.id))

				return data
			} catch (error) {
				console.error('Error deleting audit trails:', error)
				throw error
			}
		},

		/**
		 * Refresh audit trail list with current filters
		 * @return {Promise} The refresh promise
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async refreshAuditTrailList() {
			return this.fetchAuditTrails({
				limit: this.auditTrailPagination.limit,
				page: this.auditTrailPagination.page,
			})
		},

		/**
		 * Get audit trail statistics
		 * @return {Promise<object>} The statistics
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async getStatistics() {
			try {
				await this.fetchStatistics()
				return this.statistics
			} catch (error) {
				console.error('Error getting statistics:', error)
				return {
					total: 0,
					create: 0,
					update: 0,
					delete: 0,
					read: 0,
				}
			}
		},

		/**
		 * Get action distribution data
		 * @return {Promise<Array>} The action distribution
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async getActionDistribution() {
			try {
				// Calculate from current audit trail list
				const actions = ['create', 'update', 'delete', 'read']
				const total = this.auditTrailList.length

				return actions.map(action => {
					const count = this.auditTrailList.filter(item => item.action === action).length
					return {
						action,
						count,
						percentage: total > 0 ? Math.round((count / total) * 100) : 0,
					}
				}).filter(item => item.count > 0)
			} catch (error) {
				console.error('Error getting action distribution:', error)
				return []
			}
		},

		/**
		 * Get top objects by audit trail count
		 * @return {Promise<Array>} The top objects
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async getTopObjects() {
			try {
				// Count audit trails per object
				const objectCounts = {}
				this.auditTrailList.forEach(item => {
					if (item.object) {
						objectCounts[item.object] = (objectCounts[item.object] || 0) + 1
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
			this.auditTrailPagination = {
				total: 0,
				page: 1,
				pages: 1,
				limit: 50,
				offset: 0,
			}
			this.statistics = {
				total: 0,
				create: 0,
				update: 0,
				delete: 0,
				read: 0,
			}
			this.auditTrailFilters = {}
			this.auditTrailSearch = ''
		},
	},
})
