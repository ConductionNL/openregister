/* eslint-disable no-console */
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
		 */
		setAuditTrailList(auditTrailList) {
			// Ensure we have a clean array without reactive references
			this.auditTrailList = Array.isArray(auditTrailList) ? [...auditTrailList] : []
			console.info('Audit trail list set to:', this.auditTrailList.length, 'items')
		},

		/**
		 * Set audit trail item
		 * @param {object} auditTrailItem - The audit trail item to set
		 */
		setAuditTrailItem(auditTrailItem) {
			this.auditTrailItem = auditTrailItem
			console.info('Audit trail item set to:', auditTrailItem)
		},

		/**
		 * Set audit trail pagination
		 * @param {object} pagination - The pagination object
		 */
		setAuditTrailPagination(pagination) {
			this.auditTrailPagination = {
				...this.auditTrailPagination,
				...pagination,
			}
			console.info('Audit trail pagination set to:', this.auditTrailPagination)
		},

		/**
		 * Set statistics
		 * @param {object} stats - The statistics object
		 */
		setStatistics(stats) {
			this.statistics = {
				...this.statistics,
				...stats,
			}
			console.info('Statistics set to:', this.statistics)
		},

		/**
		 * Set audit trail filters
		 * @param {object} filters - The filters to set
		 */
		setAuditTrailFilters(filters) {
			this.auditTrailFilters = filters
			console.info('Audit trail filters set to:', filters)
		},

		/**
		 * Set audit trail search
		 * @param {string} search - The search term
		 */
		setAuditTrailSearch(search) {
			this.auditTrailSearch = search
			console.info('Audit trail search set to:', search)
		},

		/**
		 * Fetch audit trails with optional filtering and pagination
		 * @param {object} options - Options for fetching
		 * @return {Promise<object>} The fetched data
		 */
		async fetchAuditTrails(options = {}) {
			this.auditTrailLoading = true

			try {
				console.info('Fetching audit trails with options:', options)

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
				console.info('Fetching from URL:', url)

				const response = await fetch(url, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				const data = await response.json()
				console.info('Audit trail fetch response:', data)

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
		 */
		async fetchStatistics() {
			this.statisticsLoading = true

			try {
				console.info('Fetching audit trail statistics')

				const response = await fetch(`${apiUrl}/audit-trails/statistics`, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				const data = await response.json()
				console.info('Statistics response:', data)

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
		 */
		async deleteAuditTrail(id) {
			try {
				console.info('Deleting audit trail:', id)

				const response = await fetch(`${apiUrl}/audit-trails/${id}`, {
					method: 'DELETE',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				const data = await response.json()
				console.info('Delete response:', data)

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
		 */
		async deleteMultipleAuditTrails(ids) {
			try {
				console.info('Deleting multiple audit trails:', ids)

				const response = await fetch(`${apiUrl}/audit-trails`, {
					method: 'DELETE',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ ids }),
				})

				const data = await response.json()
				console.info('Bulk delete response:', data)

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
			console.info('Audit trail store cleared')
		},
	},
})
