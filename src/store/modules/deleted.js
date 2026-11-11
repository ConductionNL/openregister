import { defineStore } from 'pinia'
import {
	getRequest,
	listPageQuery,
	listPagination,
	storeRequest,
	withoutIds,
} from '../../services/listPage.js'
import { mergedStatisticsActions } from './trailStatistics.js'

const apiUrl = '/index.php/apps/openregister/api'

/**
 * The statistics with every count at 0.
 *
 * @return {object} Every count at 0.
 * @spec exclude Client-side empty state of the statistics; no backend contract.
 */
function emptyStatistics() {
	return {
		totalDeleted: 0,
		deletedToday: 0,
		deletedThisWeek: 0,
		oldestDays: 0,
	}
}

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
		deletedPagination: listPagination({}, 20),

		// Statistics
		statistics: emptyStatistics(),

		// Top deleters
		topDeleters: [],

		// Filters
		deletedFilters: {},
		deletedSearch: '',
		selectedForBulkAction: [],
	}),

	actions: {
		...mergedStatisticsActions('deleted'),
		/**
		 * Set items for bulk action
		 *
		 * @param {Array} items - The items to set for bulk action
		 */
		setSelectedForBulkAction(items) {
			this.selectedForBulkAction = items
		},

		/**
		 * Clear items for bulk action
		 *
		 * @spec exclude store setter (clears local bulk-selection state)
		 */
		clearSelectedForBulkAction() {
			this.selectedForBulkAction = []
		},

		/**
		 * Set deleted list
		 *
		 * @param {Array} deletedList - The deleted list to set
		 */
		setDeletedList(deletedList) {
			this.deletedList = deletedList
		},

		/**
		 * Set deleted item
		 *
		 * @param {object} deletedItem - The deleted item to set
		 */
		setDeletedItem(deletedItem) {
			this.deletedItem = deletedItem
		},

		/**
		 * Set deleted pagination
		 *
		 * @param {object} pagination - The pagination object
		 * @spec exclude store setter (local pagination state)
		 */
		setDeletedPagination(pagination) {
			this.deletedPagination = {
				...this.deletedPagination,
				...pagination,
			}
		},

		/**
		 * Set top deleters
		 *
		 * @param {Array} deleters - Array of top deleters
		 */
		setTopDeleters(deleters) {
			this.topDeleters = deleters
		},

		/**
		 * Set deleted filters
		 *
		 * @param {object} filters - The filters to set
		 */
		setDeletedFilters(filters) {
			this.deletedFilters = filters
		},

		/**
		 * Set deleted search
		 *
		 * @param {string} search - The search term
		 */
		setDeletedSearch(search) {
			this.deletedSearch = search
		},

		/**
		 * Fetch deleted objects with optional filtering and pagination
		 *
		 * @param {object} options - Options for fetching
		 * @return {Promise<object>} The fetched data
		 * @spec exclude API passthrough to GET /api/deleted (list + pagination state)
		 */
		async fetchDeleted(options = {}) {
			const query = listPageQuery(options, {
				search: this.deletedSearch,
				filters: this.deletedFilters,
			})
			return storeRequest(this, {
				flag: 'deletedLoading',
				url: `${apiUrl}/deleted?${query}`,
				request: getRequest('Failed to fetch deleted objects'),
				log: (error) =>
					console.error('Error fetching deleted objects:', error),
				keep: (data) => {
					this.setDeletedList(data.results || [])
					this.setDeletedPagination(listPagination(data, 20))
				},
			})
		},

		/**
		 * Fetch top deleters
		 *
		 * @return {Promise<Array>} The top deleters data
		 * @spec exclude API passthrough to GET /api/deleted/top-deleters
		 */
		async fetchTopDeleters() {
			return storeRequest(this, {
				flag: 'topDeletersLoading',
				url: `${apiUrl}/deleted/top-deleters`,
				request: { method: 'GET', failure: 'Failed to fetch top deleters' },
				log: (error) => console.error('Error fetching top deleters:', error),
				keep: (data) => this.setTopDeleters(data),
			})
		},

		/**
		 * Restore a deleted object
		 *
		 * @param {string|number} id - The ID of the object to restore
		 * @return {Promise<object>} The response data
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		async restoreDeleted(id) {
			return storeRequest(this, {
				url: `${apiUrl}/deleted/${id}/restore`,
				request: { method: 'POST', failure: 'Failed to restore object' },
				log: (error) => console.error('Error restoring object:', error),
				keep: () => {
					this.deletedList = withoutIds(this.deletedList, [id])
				},
			})
		},

		/**
		 * Restore multiple deleted objects
		 *
		 * @param {Array} ids - Array of object IDs to restore
		 * @return {Promise<object>} The response data
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		async restoreMultiple(ids) {
			return storeRequest(this, {
				url: `${apiUrl}/deleted/restore`,
				request: {
					method: 'POST',
					body: { ids },
					failure: 'Failed to restore objects',
				},
				log: (error) => console.error('Error restoring objects:', error),
				keep: () => {
					this.deletedList = withoutIds(this.deletedList, ids)
				},
			})
		},

		/**
		 * Permanently delete an object
		 *
		 * @param {string|number} id - The ID of the object to permanently delete
		 * @return {Promise<object>} The response data
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		async permanentlyDelete(id) {
			return storeRequest(this, {
				url: `${apiUrl}/deleted/${id}`,
				request: {
					method: 'DELETE',
					failure: 'Failed to permanently delete object',
				},
				log: (error) =>
					console.error('Error permanently deleting object:', error),
				keep: () => {
					this.deletedList = withoutIds(this.deletedList, [id])
				},
			})
		},

		/**
		 * Permanently delete multiple objects
		 *
		 * @param {Array} ids - Array of object IDs to permanently delete
		 * @return {Promise<object>} The response data
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		async permanentlyDeleteMultiple(ids) {
			return storeRequest(this, {
				url: `${apiUrl}/deleted`,
				request: {
					method: 'DELETE',
					body: { ids },
					failure: 'Failed to permanently delete objects',
				},
				log: (error) =>
					console.error('Error permanently deleting objects:', error),
				keep: () => {
					this.deletedList = withoutIds(this.deletedList, ids)
				},
			})
		},

		/**
		 * Refresh deleted list with current filters
		 *
		 * @return {Promise} The refresh promise
		 * @spec exclude convenience wrapper over fetchDeleted (API passthrough)
		 */
		async refreshDeletedList() {
			return this.fetchDeleted({
				limit: this.deletedPagination.limit,
				page: this.deletedPagination.page,
			})
		},

		/**
		 * Clear all deleted store data
		 *
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		clearDeletedStore() {
			this.deletedList = []
			this.deletedItem = null
			this.deletedPagination = listPagination({}, 20)
			this.statistics = emptyStatistics()
			this.topDeleters = []
			this.deletedFilters = {}
			this.deletedSearch = ''
			this.selectedForBulkAction = []
		},
	},
})
