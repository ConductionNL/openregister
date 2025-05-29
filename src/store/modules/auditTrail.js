/* eslint-disable no-console */
import { defineStore } from 'pinia'
import { AuditTrail } from '../../entities/index.js'

/**
 * Store for managing audit trail logs
 * Provides functionality for retrieving, filtering, and managing audit trail entries
 */
export const useAuditTrailStore = defineStore('auditTrail', {
	state: () => ({
		/**
		 * Currently selected audit trail item
		 * @type {AuditTrail|false}
		 */
		auditTrailItem: false,

		/**
		 * List of audit trail entries
		 * @type {AuditTrail[]}
		 */
		auditTrailList: [],

		/**
		 * View mode for displaying audit trails
		 * @type {string}
		 */
		viewMode: 'list',

		/**
		 * Filter criteria for audit trail queries
		 * @type {object}
		 */
		filters: {},

		/**
		 * Pagination settings
		 * @type {object}
		 */
		pagination: {
			page: 1,
			limit: 20,
			total: 0,
			pages: 0,
		},

		/**
		 * Loading state
		 * @type {boolean}
		 */
		loading: false,
	}),
	getters: {
		/**
		 * Get current view mode
		 * @param {object} state Current state
		 * @return {string} Current view mode
		 */
		getViewMode: (state) => state.viewMode,

		/**
		 * Get loading state
		 * @param {object} state Current state
		 * @return {boolean} Loading state
		 */
		isLoading: (state) => state.loading,

		/**
		 * Get filtered audit trails count
		 * @param {object} state Current state
		 * @return {number} Number of audit trails
		 */
		auditTrailCount: (state) => state.auditTrailList.length,
	},
	actions: {
		/**
		 * Set view mode for audit trail display
		 * @param {string} mode View mode ('list', 'table', 'detail')
		 */
		setViewMode(mode) {
			this.viewMode = mode
			console.log('AuditTrail view mode set to:', mode)
		},

		/**
		 * Set currently selected audit trail item
		 * @param {object|null} auditTrailItem Audit trail item or null
		 */
		setAuditTrailItem(auditTrailItem) {
			this.auditTrailItem = auditTrailItem && new AuditTrail(auditTrailItem)
			console.log('Active audit trail item set to ' + (auditTrailItem?.id || 'null'))
		},

		/**
		 * Set the list of audit trail entries
		 * @param {object[]} auditTrails Array of audit trail objects
		 */
		setAuditTrailList(auditTrails) {
			this.auditTrailList = auditTrails.map(auditTrail => new AuditTrail(auditTrail))
			console.log('AuditTrail list set to ' + auditTrails.length + ' items')
		},

		/**
		 * Set pagination details
		 * @param {number} page Current page number
		 * @param {number} limit Number of items per page
		 * @param {number} total Total number of items
		 * @param {number} pages Total number of pages
		 */
		setPagination(page, limit = 20, total = 0, pages = 0) {
			this.pagination = { page, limit, total, pages }
			console.info('AuditTrail pagination set to', { page, limit, total, pages })
		},

		/**
		 * Set query filters for audit trail list
		 * @param {object} filters Filter criteria to apply
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
			console.info('AuditTrail query filters set to', this.filters)
		},

		/**
		 * Clear all filters
		 */
		clearFilters() {
			this.filters = {}
			console.info('AuditTrail filters cleared')
		},

		/**
		 * Set loading state
		 * @param {boolean} loading Loading state
		 */
		setLoading(loading) {
			this.loading = loading
		},

		/**
		 * Refresh audit trail list from API
		 * @param {object} options Query options
		 * @param {string|null} options.search Search term
		 * @param {object} options.filters Additional filters
		 * @param {number} options.page Page number
		 * @param {number} options.limit Items per page
		 * @return {Promise<object>} Response data
		 */
		async refreshAuditTrailList(options = {}) {
			this.setLoading(true)

			try {
				// Build query parameters
				const params = new URLSearchParams()

				// Add pagination
				if (options.page) params.append('page', options.page.toString())
				if (options.limit) params.append('limit', options.limit.toString())

				// Add search
				if (options.search) params.append('_search', encodeURIComponent(options.search))

				// Add filters
				const allFilters = { ...this.filters, ...options.filters }
				Object.entries(allFilters).forEach(([key, value]) => {
					if (value !== null && value !== undefined && value !== '') {
						params.append(key, value.toString())
					}
				})

				// Build endpoint
				const endpoint = `/index.php/apps/openregister/api/audit-trails?${params.toString()}`

				const response = await fetch(endpoint, {
					method: 'GET',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				// Set data in store
				this.setAuditTrailList(data.results || [])
				this.setPagination(
					data.page || 1,
					data.limit || 20,
					data.total || 0,
					data.pages || 0,
				)

				return { response, data }
			} catch (error) {
				console.error('Error refreshing audit trail list:', error)
				throw error
			} finally {
				this.setLoading(false)
			}
		},

		/**
		 * Get audit trails for a specific object
		 * @param {string} register Register identifier
		 * @param {string} schema Schema identifier
		 * @param {string} objectId Object identifier
		 * @param {object} options Query options
		 * @return {Promise<object>} Response data
		 */
		async getObjectAuditTrails(register, schema, objectId, options = {}) {
			this.setLoading(true)

			try {
				// Build query parameters
				const params = new URLSearchParams()

				// Add pagination
				if (options.page) params.append('page', options.page.toString())
				if (options.limit) params.append('limit', options.limit.toString())

				// Add search
				if (options.search) params.append('_search', encodeURIComponent(options.search))

				// Add filters
				const allFilters = { ...this.filters, ...options.filters }
				Object.entries(allFilters).forEach(([key, value]) => {
					if (value !== null && value !== undefined && value !== '') {
						params.append(key, value.toString())
					}
				})

				// Build endpoint for object-specific audit trails
				const endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/audit-trails?${params.toString()}`

				const response = await fetch(endpoint, {
					method: 'GET',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				// Set data in store
				this.setAuditTrailList(data.results || [])
				this.setPagination(
					data.page || 1,
					data.limit || 20,
					data.total || 0,
					data.pages || 0,
				)

				return { response, data }
			} catch (error) {
				console.error('Error getting object audit trails:', error)
				throw error
			} finally {
				this.setLoading(false)
			}
		},

		/**
		 * Get a single audit trail by ID
		 * @param {string|number} id Audit trail ID
		 * @param {object} options Options
		 * @param {boolean} options.setItem Whether to set as active item
		 * @return {Promise<AuditTrail>} Audit trail data
		 */
		async getAuditTrail(id, options = { setItem: false }) {
			this.setLoading(true)

			try {
				const endpoint = `/index.php/apps/openregister/api/audit-trails/${id}`

				const response = await fetch(endpoint, {
					method: 'GET',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				const auditTrail = new AuditTrail(data)

				if (options.setItem) {
					this.setAuditTrailItem(auditTrail)
				}

				return auditTrail
			} catch (error) {
				console.error('Error getting audit trail:', error)
				throw error
			} finally {
				this.setLoading(false)
			}
		},
	},
})
