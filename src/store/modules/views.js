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
		 *
		 * @spec exclude Pure client UI-state setter — active saved view. No backend contract.
		 */
		setActiveView(view) {
			this.activeView = view
		},

		/**
		 * Clear the active view
		 * @return {void}
		 *
		 * @spec exclude Pure client UI-state mutator — clears the active saved view. No backend contract.
		 */
		clearActiveView() {
			this.activeView = null
		},

		/**
		 * Fetch all views from the API
		 * @return {Promise<void>}
		 *
		 * @spec exclude Thin API passthrough — GET /api/views list; observable contract owned by zoeken-filteren.
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
		 *
		 * @spec exclude Thin API passthrough — GET /api/views/{id}; observable contract owned by zoeken-filteren.
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

				const data = await response.json()

				// API returns { view: {...} }, so unwrap it
				const view = data.view || data

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
		 *
		 * @spec exclude Thin API passthrough — POST /api/views; observable contract owned by zoeken-filteren.
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

				const data = await response.json()

				// API returns { view: {...} }, so unwrap it
				const newView = data.view || data

				// Add to views list
				this.viewsList.push(newView)

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
		 *
		 * @spec exclude Thin API passthrough — PUT /api/views/{id}; observable contract owned by zoeken-filteren.
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

				const data = await response.json()

				// API returns { view: {...} }, so unwrap it
				const updatedView = data.view || data

				// Update in views list
				const index = this.viewsList.findIndex(v => v.id === id || v.uuid === id)
				if (index !== -1) {
					this.viewsList[index] = updatedView
				}

				// Update active view if it's the same
				if (this.activeView && (this.activeView.id === id || this.activeView.uuid === id)) {
					this.activeView = updatedView
				}

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
		 *
		 * @spec exclude Client-side payload sanitiser — strips read-only fields before save. No standalone backend contract.
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
		 *
		 * @spec exclude Thin API passthrough — DELETE /api/views/{id}; observable contract owned by zoeken-filteren.
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
		 *
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		applyView(view, searchStore) {
			if (!view || !view.configuration) {
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
		},

		/**
		 * Create a view from current search state
		 * @param {object} searchStore - The search store instance
		 * @param {string} name - The name for the new view
		 * @param {string} description - Optional description
		 * @param {boolean} isDefault - Whether this should be the default view
		 * @param {boolean} isPublic - Whether this view should be public
		 * @return {object} The view configuration
		 *
		 * @spec openspec/specs/frontend-store-client-state/spec.md
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
