/* eslint-disable no-console */
import { defineStore } from 'pinia'

/**
 * Store for managing saved search views
 *
 * This store handles creating, reading, updating, and deleting saved search views.
 * Views allow users to save complex search configurations including multiple
 * registers, schemas, filters, and display settings.
 *
 * @module Store
 * @package
 * @author Conduction Development Team
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */
export const useViewsStore = defineStore('views', {
	state: () => ({
		/**
		 * The currently active view
		 * @type {object|null}
		 */
		activeView: null,

		/**
		 * List of all views
		 * @type {Array}
		 */
		viewsList: [],

		/**
		 * Loading state
		 * @type {boolean}
		 */
		loading: false,

		/**
		 * Error state
		 * @type {string|null}
		 */
		error: null,
	}),

	getters: {
		/**
		 * Get the active view
		 * @param {object} state - Store state
		 * @return {object|null} The active view
		 */
		getActiveView: (state) => state.activeView,

		/**
		 * Get all views
		 * @param {object} state - Store state
		 * @return {Array} All views
		 */
		getAllViews: (state) => state.viewsList,

		/**
		 * Get public views (shared by other users)
		 * @param {object} state - Store state
		 * @return {Array} Public views
		 */
		getPublicViews: (state) => state.viewsList.filter(view => view.isPublic === true),

		/**
		 * Get user's private views
		 * @param {object} state - Store state
		 * @return {Array} Private views
		 */
		getPrivateViews: (state) => state.viewsList.filter(view => view.isPublic !== true),

		/**
		 * Get default view if one exists
		 * @param {object} state - Store state
		 * @return {object|null} Default view
		 */
		getDefaultView: (state) => state.viewsList.find(view => view.isDefault === true) || null,

		/**
		 * Check if loading
		 * @param {object} state - Store state
		 * @return {boolean} Loading state
		 */
		isLoading: (state) => state.loading,

		/**
		 * Get error message
		 * @param {object} state - Store state
		 * @return {string|null} Error message
		 */
		getError: (state) => state.error,
	},

	actions: {
		/**
		 * Set the active view
		 * @param {object|null} view - The view to set as active
		 * @return {void}
		 */
		setActiveView(view) {
			this.activeView = view
			console.info('Active view set:', view)
		},

		/**
		 * Clear the active view
		 * @return {void}
		 */
		clearActiveView() {
			this.activeView = null
			console.info('Active view cleared')
		},

		/**
		 * Fetch all views from the API
		 * @return {Promise<void>}
		 */
		async fetchViews() {
			this.loading = true
			this.error = null

			try {
				const response = await fetch('/index.php/apps/openregister/api/views', {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				this.viewsList = data.results || []

				console.info('Views fetched successfully:', this.viewsList.length, 'views')
			} catch (error) {
				console.error('Error fetching views:', error)
				this.error = error.message
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch a specific view by ID
		 * @param {string} id - The view ID
		 * @return {Promise<object>}
		 */
		async fetchView(id) {
			this.loading = true
			this.error = null

			try {
				const response = await fetch(`/index.php/apps/openregister/api/views/${id}`, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const view = await response.json()

				console.info('View fetched successfully:', view)
				return view
			} catch (error) {
				console.error('Error fetching view:', error)
				this.error = error.message
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a new view
		 * @param {object} viewData - The view data
		 * @return {Promise<object>}
		 */
		async createView(viewData) {
			this.loading = true
			this.error = null

			try {
				const response = await fetch('/index.php/apps/openregister/api/views', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(viewData),
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const newView = await response.json()

				// Add to views list
				this.viewsList.push(newView)

				console.info('View created successfully:', newView)
				return newView
			} catch (error) {
				console.error('Error creating view:', error)
				this.error = error.message
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Update an existing view
		 * @param {string} id - The view ID
		 * @param {object} viewData - The updated view data
		 * @return {Promise<object>}
		 */
		async updateView(id, viewData) {
			this.loading = true
			this.error = null

			// Clean the data before sending - remove read-only fields
			const cleanedData = this.cleanViewForSave(viewData)

			try {
				const response = await fetch(`/index.php/apps/openregister/api/views/${id}`, {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(cleanedData),
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const updatedView = await response.json()

				// Update in views list
				const index = this.viewsList.findIndex(v => v.id === id || v.uuid === id)
				if (index !== -1) {
					this.viewsList[index] = updatedView
				}

				// Update active view if it's the same
				if (this.activeView && (this.activeView.id === id || this.activeView.uuid === id)) {
					this.activeView = updatedView
				}

				console.info('View updated successfully:', updatedView)
				return updatedView
			} catch (error) {
				console.error('Error updating view:', error)
				this.error = error.message
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Clean view data for saving - remove read-only fields
		 * @param {object} viewData - The view data to clean
		 * @return {object} Cleaned view data
		 */
		cleanViewForSave(viewData) {
			const cleaned = { ...viewData }

			// Remove read-only/calculated fields that should not be sent to the server
			delete cleaned.id
			delete cleaned.uuid
			delete cleaned.created
			delete cleaned.updated

			return cleaned
		},

		/**
		 * Delete a view
		 * @param {string} id - The view ID
		 * @return {Promise<void>}
		 */
		async deleteView(id) {
			this.loading = true
			this.error = null

			try {
				const response = await fetch(`/index.php/apps/openregister/api/views/${id}`, {
					method: 'DELETE',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				// Remove from views list
				this.viewsList = this.viewsList.filter(v => v.id !== id && v.uuid !== id)

				// Clear active view if it's the same
				if (this.activeView && (this.activeView.id === id || this.activeView.uuid === id)) {
					this.activeView = null
				}

				console.info('View deleted successfully')
			} catch (error) {
				console.error('Error deleting view:', error)
				this.error = error.message
				throw error
			} finally {
				this.loading = false
			}
		},

		/**
		 * Apply a view's configuration to the current search state
		 * @param {object} view - The view to apply
		 * @param {object} searchStore - The search store instance
		 * @return {void}
		 */
		applyView(view, searchStore) {
			if (!view || !view.configuration) {
				console.warn('Invalid view provided to applyView')
				return
			}

			const config = view.configuration

			// Apply registers and schemas
			if (config.registers) {
				searchStore.setSelectedRegisters(config.registers)
			}
			if (config.schemas) {
				searchStore.setSelectedSchemas(config.schemas)
			}

			// Apply source
			if (config.source) {
				searchStore.setSource(config.source)
			}

			// Apply search terms
			if (config.searchTerms) {
				searchStore.setSearchTerms(config.searchTerms)
			}

			// Apply facet filters
			if (config.facetFilters) {
				searchStore.setFacetFilters(config.facetFilters)
			}

			// Apply enabled facets
			if (config.enabledFacets) {
				searchStore.setEnabledFacets(config.enabledFacets)
			}

			// Apply advanced filters
			if (config.advancedFilters) {
				searchStore.setAdvancedFilters(config.advancedFilters)
			}

			// Apply pagination
			if (config.pagination) {
				searchStore.setPagination(config.pagination)
			}

			// Apply sorting
			if (config.sorting) {
				searchStore.setSorting(config.sorting)
			}

			// Apply columns
			if (config.columns) {
				searchStore.setColumns(config.columns)
			}

			this.setActiveView(view)

			console.info('View applied successfully:', view.name)
		},

		/**
		 * Create a view from current search state
		 * @param {object} searchStore - The search store instance
		 * @param {string} name - The name for the new view
		 * @param {string} description - Optional description
		 * @param {boolean} isDefault - Whether this should be the default view
		 * @param {boolean} isPublic - Whether this view should be public
		 * @return {object} The view configuration
		 */
		createViewFromSearchState(searchStore, name, description = '', isDefault = false, isPublic = false) {
			return {
				name,
				description,
				isDefault,
				isPublic,
				configuration: {
					registers: searchStore.selectedRegisters || [],
					schemas: searchStore.selectedSchemas || [],
					source: searchStore.source || 'auto',
					searchTerms: searchStore.searchTerms || [],
					facetFilters: searchStore.facetFilters || {},
					enabledFacets: searchStore.enabledFacets || {},
					advancedFilters: searchStore.advancedFilters || {},
					pagination: searchStore.pagination || { page: 1, limit: 20 },
					sorting: searchStore.sorting || {},
					columns: searchStore.columns || {},
				},
			}
		},
	},
})
