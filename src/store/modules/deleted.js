/* eslint-disable no-console */
import { defineStore } from 'pinia'

const apiUrl = '/index.php/apps/openregister/api'

/**
 * Store for managing deleted objects
 */
export const useDeletedStore = defineStore('deleted', {
	state: () => ({
		// Loading states
		deletedLoading: false,
		statisticsLoading: false,
		topDeletersLoading: false,

		// Data
		deletedList: [],
		deletedItem: null,

		// Pagination
		deletedPagination: {
			total: 0,
			page: 1,
			pages: 1,
			limit: 20,
			offset: 0,
		},

		// Statistics
		statistics: {
			totalDeleted: 0,
			deletedToday: 0,
			deletedThisWeek: 0,
			oldestDays: 0,
		},

		// Top deleters
		topDeleters: [],

		// Filters
		deletedFilters: {},
		deletedSearch: '',
		selectedForBulkAction: [],
	}),

	actions: {
		/**
		 * Set items for bulk action
		 * @param {Array} items - The items to set for bulk action
		 */
		setSelectedForBulkAction(items) {
			this.selectedForBulkAction = items
		},

		/**
		 * Clear items for bulk action
		 */
		clearSelectedForBulkAction() {
			this.selectedForBulkAction = []
		},

		/**
		 * Set deleted list
		 * @param {Array} deletedList - The deleted list to set
		 */
		setDeletedList(deletedList) {
			this.deletedList = deletedList
		},

		/**
		 * Set deleted item
		 * @param {object} deletedItem - The deleted item to set
		 */
		setDeletedItem(deletedItem) {
			this.deletedItem = deletedItem
		},

		/**
		 * Set deleted pagination
		 * @param {object} pagination - The pagination object
		 */
		setDeletedPagination(pagination) {
			this.deletedPagination = {
				...this.deletedPagination,
				...pagination,
			}
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
		},

		/**
		 * Set top deleters
		 * @param {Array} deleters - Array of top deleters
		 */
		setTopDeleters(deleters) {
			this.topDeleters = deleters
		},

		/**
		 * Set deleted filters
		 * @param {object} filters - The filters to set
		 */
		setDeletedFilters(filters) {
			this.deletedFilters = filters
		},

		/**
		 * Set deleted search
		 * @param {string} search - The search term
		 */
		setDeletedSearch(search) {
			this.deletedSearch = search
		},

		/**
		 * Fetch deleted objects with optional filtering and pagination
		 * @param {object} options - Options for fetching
		 * @return {Promise<object>} The fetched data
		 */
		async fetchDeleted(options = {}) {
			this.deletedLoading = true

			try {
				// Build query parameters
				const params = new URLSearchParams()

				// Add pagination
				if (options.limit) params.append('limit', options.limit)
				if (options.offset) params.append('offset', options.offset)
				if (options.page) params.append('page', options.page)

				// Add search
				if (options.search || this.deletedSearch) {
					params.append('search', options.search || this.deletedSearch)
				}

				// Add filters
				const filters = { ...this.deletedFilters, ...options.filters }
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

				const url = `${apiUrl}/deleted?${params.toString()}`

				const response = await fetch(url, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to fetch deleted objects')
				}

				// Update store state
				this.setDeletedList(data.results || [])
				this.setDeletedPagination({
					total: data.total || 0,
					page: data.page || 1,
					pages: data.pages || 1,
					limit: data.limit || 20,
					offset: data.offset || 0,
				})

				return data
			} catch (error) {
				console.error('Error fetching deleted objects:', error)
				throw error
			} finally {
				this.deletedLoading = false
			}
		},

		/**
		 * Fetch deleted object statistics
		 * @return {Promise<object>} The statistics data
		 */
		async fetchStatistics() {
			this.statisticsLoading = true

			try {
				const response = await fetch(`${apiUrl}/deleted/statistics`, {
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
		 * Fetch top deleters
		 * @return {Promise<Array>} The top deleters data
		 */
		async fetchTopDeleters() {
			this.topDeletersLoading = true

			try {
				const response = await fetch(`${apiUrl}/deleted/top-deleters`, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to fetch top deleters')
				}

				this.setTopDeleters(data)
				return data
			} catch (error) {
				console.error('Error fetching top deleters:', error)
				throw error
			} finally {
				this.topDeletersLoading = false
			}
		},

		/**
		 * Restore a deleted object
		 * @param {string|number} id - The ID of the object to restore
		 * @return {Promise<object>} The response data
		 */
		async restoreDeleted(id) {
			try {
				const response = await fetch(`${apiUrl}/deleted/${id}/restore`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to restore object')
				}

				// Remove from deleted list
				this.deletedList = this.deletedList.filter(item => item.id !== id)

				return data
			} catch (error) {
				console.error('Error restoring object:', error)
				throw error
			}
		},

		/**
		 * Restore multiple deleted objects
		 * @param {Array} ids - Array of object IDs to restore
		 * @return {Promise<object>} The response data
		 */
		async restoreMultiple(ids) {
			try {
				const response = await fetch(`${apiUrl}/deleted/restore`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ ids }),
				})

				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to restore objects')
				}

				// Remove restored objects from list
				this.deletedList = this.deletedList.filter(item => !ids.includes(item.id))

				return data
			} catch (error) {
				console.error('Error restoring objects:', error)
				throw error
			}
		},

		/**
		 * Permanently delete an object
		 * @param {string|number} id - The ID of the object to permanently delete
		 * @return {Promise<object>} The response data
		 */
		async permanentlyDelete(id) {
			try {
				const response = await fetch(`${apiUrl}/deleted/${id}`, {
					method: 'DELETE',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to permanently delete object')
				}

				// Remove from deleted list
				this.deletedList = this.deletedList.filter(item => item.id !== id)

				return data
			} catch (error) {
				console.error('Error permanently deleting object:', error)
				throw error
			}
		},

		/**
		 * Permanently delete multiple objects
		 * @param {Array} ids - Array of object IDs to permanently delete
		 * @return {Promise<object>} The response data
		 */
		async permanentlyDeleteMultiple(ids) {
			try {
				const response = await fetch(`${apiUrl}/deleted`, {
					method: 'DELETE',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ ids }),
				})

				const data = await response.json()

				if (!response.ok) {
					throw new Error(data.error || 'Failed to permanently delete objects')
				}

				// Remove deleted objects from list
				this.deletedList = this.deletedList.filter(item => !ids.includes(item.id))

				return data
			} catch (error) {
				console.error('Error permanently deleting objects:', error)
				throw error
			}
		},

		/**
		 * Refresh deleted list with current filters
		 * @return {Promise} The refresh promise
		 */
		async refreshDeletedList() {
			return this.fetchDeleted({
				limit: this.deletedPagination.limit,
				page: this.deletedPagination.page,
			})
		},

		/**
		 * Clear all deleted store data
		 */
		clearDeletedStore() {
			this.deletedList = []
			this.deletedItem = null
			this.deletedPagination = {
				total: 0,
				page: 1,
				pages: 1,
				limit: 20,
				offset: 0,
			}
			this.statistics = {
				totalDeleted: 0,
				deletedToday: 0,
				deletedThisWeek: 0,
				oldestDays: 0,
			}
			this.topDeleters = []
			this.deletedFilters = {}
			this.deletedSearch = ''
			this.selectedForBulkAction = []
			console.info('Deleted store cleared')
		},
	},
})
