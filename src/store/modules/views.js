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
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version 1.0.0
 */
/**
 * The parts of the search state a view saves: the configuration key, the
 * search store field it is read from, the setter that applies it and the
 * value saved when the field is empty. Applied in this order.
 */
const SEARCH_STATE = [
	{
		key: 'registers',
		field: 'selectedRegisters',
		setter: 'setSelectedRegisters',
		empty: () => [],
	},
	{
		key: 'schemas',
		field: 'selectedSchemas',
		setter: 'setSelectedSchemas',
		empty: () => [],
	},
	{ key: 'source', field: 'source', setter: 'setSource', empty: () => 'auto' },
	{
		key: 'searchTerms',
		field: 'searchTerms',
		setter: 'setSearchTerms',
		empty: () => [],
	},
	{
		key: 'facetFilters',
		field: 'facetFilters',
		setter: 'setFacetFilters',
		empty: () => ({}),
	},
	{
		key: 'enabledFacets',
		field: 'enabledFacets',
		setter: 'setEnabledFacets',
		empty: () => ({}),
	},
	{
		key: 'advancedFilters',
		field: 'advancedFilters',
		setter: 'setAdvancedFilters',
		empty: () => ({}),
	},
	{
		key: 'pagination',
		field: 'pagination',
		setter: 'setPagination',
		empty: () => ({ page: 1, limit: 20 }),
	},
	{ key: 'sorting', field: 'sorting', setter: 'setSorting', empty: () => ({}) },
	{ key: 'columns', field: 'columns', setter: 'setColumns', empty: () => ({}) },
]

const VIEWS_API = '/index.php/apps/openregister/api/views'

/**
 * Run one request of the store: loading is on while it runs, and a failure
 * is logged, kept as the store error and thrown on.
 *
 * @param {object} store The views store.
 * @param {{failure: string, run: function(): Promise}} task The log text and the request.
 * @return {Promise} What the request resolves to.
 * @spec openspec/specs/saved-search-views/spec.md
 */
async function tracked(store, task) {
	store.loading = true
	store.error = null
	try {
		return await task.run()
	} catch (error) {
		console.error(task.failure, error)
		store.error = error.message
		throw error
	} finally {
		store.loading = false
	}
}

/**
 * Send one JSON request to the views API.
 *
 * @param {string} path The path below the views API; empty for the list.
 * @param {object} init The fetch options besides the JSON header.
 * @return {Promise<Response>} The response, once it is OK.
 * @spec openspec/specs/saved-search-views/spec.md
 */
async function viewsRequest(path, init) {
	const response = await fetch(`${VIEWS_API}${path}`, {
		...init,
		headers: {
			'Content-Type': 'application/json',
		},
	})
	if (!response.ok) {
		throw new Error(`HTTP error! status: ${response.status}`)
	}
	return response
}

/**
 * The view a views API answer holds: inside `view`, or the answer itself.
 *
 * @param {string} path The path below the views API.
 * @param {object} init The fetch options besides the JSON header.
 * @return {Promise<object>} The view.
 * @spec openspec/specs/saved-search-views/spec.md
 */
async function viewFrom(path, init) {
	const data = await (await viewsRequest(path, init)).json()
	return data.view || data
}

/**
 * GET a presentation of a view (kanban board, calendar). A failure carries
 * the server's own error text when it sent one.
 *
 * @param {string} path The path below the views API.
 * @return {Promise<object>} The answer as the server sent it.
 * @spec openspec/specs/saved-search-views/spec.md#requirement-kanban-columns-and-cards-req-view-kanban-02
 */
async function presentationOf(path) {
	const response = await fetch(`${VIEWS_API}${path}`, {
		method: 'GET',
		headers: {
			'Content-Type': 'application/json',
		},
	})
	if (!response.ok) {
		const body = await response.json().catch(() => null)
		throw new Error(body?.error || `HTTP error! status: ${response.status}`)
	}
	return await response.json()
}

/**
 * The query string of a kanban board request.
 *
 * @param {object} params `_limit` and `_offset`; others are ignored.
 * @return {string} `?…`, or empty without either.
 * @spec openspec/specs/saved-search-views/spec.md#requirement-kanban-columns-and-cards-req-view-kanban-02
 */
function kanbanQuery(params) {
	const query = new URLSearchParams()
	if (params._limit != null) query.set('_limit', params._limit)
	if (params._offset != null) query.set('_offset', params._offset)
	const qs = query.toString()
	return qs ? `?${qs}` : ''
}

/**
 * Whether a view is the one an id names, by id or by uuid.
 *
 * @param {object} view The view.
 * @param {string|number} id The view id or uuid.
 * @return {boolean}
 * @spec openspec/specs/saved-search-views/spec.md
 */
function isView(view, id) {
	return view.id === id || view.uuid === id
}

export const useViewsStore = defineStore('views', {
	state: () => ({
		/**
		 * The currently active view
		 *
		 * @type {object|null}
		 */
		activeView: null,

		/**
		 * List of all views
		 *
		 * @type {Array}
		 */
		viewsList: [],

		/**
		 * Loading state
		 *
		 * @type {boolean}
		 */
		loading: false,

		/**
		 * Error state
		 *
		 * @type {string|null}
		 */
		error: null,
	}),

	getters: {
		/**
		 * Get the active view
		 *
		 * @param {object} state - Store state
		 * @return {object|null} The active view
		 */
		getActiveView: (state) => state.activeView,

		/**
		 * Get all views
		 *
		 * @param {object} state - Store state
		 * @return {Array} All views
		 */
		getAllViews: (state) => state.viewsList,

		/**
		 * Get public views (shared by other users)
		 *
		 * @param {object} state - Store state
		 * @return {Array} Public views
		 */
		getPublicViews: (state) =>
			state.viewsList.filter((view) => view.isPublic === true),

		/**
		 * Get user's private views
		 *
		 * @param {object} state - Store state
		 * @return {Array} Private views
		 */
		getPrivateViews: (state) =>
			state.viewsList.filter((view) => view.isPublic !== true),

		/**
		 * Get default view if one exists
		 *
		 * @param {object} state - Store state
		 * @return {object|null} Default view
		 */
		getDefaultView: (state) =>
			state.viewsList.find((view) => view.isDefault === true) || null,

		/**
		 * Check if loading
		 *
		 * @param {object} state - Store state
		 * @return {boolean} Loading state
		 */
		isLoading: (state) => state.loading,

		/**
		 * Get error message
		 *
		 * @param {object} state - Store state
		 * @return {string|null} Error message
		 */
		getError: (state) => state.error,
	},

	actions: {
		/**
		 * Set the active view
		 *
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
		 *
		 * @return {void}
		 *
		 * @spec exclude Pure client UI-state mutator — clears the active saved view. No backend contract.
		 */
		clearActiveView() {
			this.activeView = null
		},

		/**
		 * Fetch all views from the API
		 *
		 * @return {Promise<void>}
		 *
		 * @spec exclude Thin API passthrough — GET /api/views list; observable contract owned by zoeken-filteren.
		 */
		async fetchViews() {
			return tracked(this, {
				failure: 'Error fetching views:',
				run: async () => {
					const data = await (
						await viewsRequest('', { method: 'GET' })
					).json()
					this.viewsList = data.results || []
				},
			})
		},

		/**
		 * Fetch a specific view by ID
		 *
		 * @param {string} id - The view ID
		 * @return {Promise<object>}
		 *
		 * @spec exclude Thin API passthrough — GET /api/views/{id}; observable contract owned by zoeken-filteren.
		 */
		async fetchView(id) {
			return tracked(this, {
				failure: 'Error fetching view:',
				run: () => viewFrom(`/${id}`, { method: 'GET' }),
			})
		},

		/**
		 * Create a new view
		 *
		 * @param {object} viewData - The view data
		 * @return {Promise<object>}
		 *
		 * @spec exclude Thin API passthrough — POST /api/views; observable contract owned by zoeken-filteren.
		 */
		async createView(viewData) {
			return tracked(this, {
				failure: 'Error creating view:',
				run: async () => {
					const newView = await viewFrom('', {
						method: 'POST',
						body: JSON.stringify(viewData),
					})
					this.viewsList.push(newView)
					return newView
				},
			})
		},

		/**
		 * Update an existing view
		 *
		 * @param {string} id - The view ID
		 * @param {object} viewData - The updated view data
		 * @return {Promise<object>}
		 *
		 * @spec exclude Thin API passthrough — PUT /api/views/{id}; observable contract owned by zoeken-filteren.
		 */
		async updateView(id, viewData) {
			return tracked(this, {
				failure: 'Error updating view:',
				run: async () => {
					// Read-only fields are not sent.
					const updatedView = await viewFrom(`/${id}`, {
						method: 'PUT',
						body: JSON.stringify(this.cleanViewForSave(viewData)),
					})
					const index = this.viewsList.findIndex((v) => isView(v, id))
					if (index !== -1) {
						this.viewsList[index] = updatedView
					}
					if (this.activeView && isView(this.activeView, id)) {
						this.activeView = updatedView
					}
					return updatedView
				},
			})
		},

		/**
		 * Clean view data for saving - remove read-only fields
		 *
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
		 *
		 * @param {string} id - The view ID
		 * @return {Promise<void>}
		 *
		 * @spec exclude Thin API passthrough — DELETE /api/views/{id}; observable contract owned by zoeken-filteren.
		 */
		async deleteView(id) {
			return tracked(this, {
				failure: 'Error deleting view:',
				run: async () => {
					await viewsRequest(`/${id}`, { method: 'DELETE' })
					this.viewsList = this.viewsList.filter((v) => !isView(v, id))
					if (this.activeView && isView(this.activeView, id)) {
						this.activeView = null
					}
				},
			})
		},

		/**
		 * Apply a view's configuration to the current search state
		 *
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
			for (const { key, setter } of SEARCH_STATE) {
				if (config[key]) {
					searchStore[setter](config[key])
				}
			}

			this.setActiveView(view)
		},

		/**
		 * Create a view from current search state
		 *
		 * @param {object} searchStore - The search store instance
		 * @param {string} name - The name for the new view
		 * @param {string} description - Optional description
		 * @param {boolean} isDefault - Whether this should be the default view
		 * @param {boolean} isPublic - Whether this view should be public
		 * @return {object} The view configuration
		 *
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		createViewFromSearchState(
			searchStore,
			name,
			description = '',
			isDefault = false,
			isPublic = false,
		) {
			return {
				name,
				description,
				isDefault,
				isPublic,
				configuration: Object.fromEntries(
					SEARCH_STATE.map(({ key, field, empty }) => [
						key,
						searchStore[field] || empty(),
					]),
				),
			}
		},

		/**
		 * Fetch the kanban board for a view: columns derived from
		 * `presentation.kanban.groupByField` (columnOrder > enum > discovered),
		 * with cards paginated per the existing object-query machinery.
		 *
		 * @param {string} id - The view ID
		 * @param {object} [params] - Optional query params (`_limit`/`_offset`, applied to every column)
		 * @return {Promise<{viewType: string, groupByField: string, columns: Array<object>}>}
		 *
		 * @spec openspec/specs/saved-search-views/spec.md#requirement-kanban-columns-and-cards-req-view-kanban-02
		 */
		async fetchKanbanBoard(id, params = {}) {
			return tracked(this, {
				failure: 'Error fetching kanban board:',
				run: () => presentationOf(`/${id}/kanban${kanbanQuery(params)}`),
			})
		},

		/**
		 * Fetch the objects for a calendar view whose `presentation.calendar.dateField`
		 * falls within the visible range (spanning to `endDateField` when configured).
		 *
		 * @param {string} id - The view ID
		 * @param {string} rangeStart - Inclusive range start (ISO 8601 date/datetime)
		 * @param {string} rangeEnd - Inclusive range end (ISO 8601 date/datetime)
		 * @return {Promise<{viewType: string, dateField: string, endDateField: (string|null), rangeStart: string, rangeEnd: string, objects: Array<object>, total: number}>}
		 *
		 * @spec openspec/specs/saved-search-views/spec.md#requirement-calendar-plots-objects-by-a-date-field-over-a-range-req-view-cal-04
		 */
		async fetchCalendarObjects(id, rangeStart, rangeEnd) {
			return tracked(this, {
				failure: 'Error fetching calendar objects:',
				run: () => {
					const query = new URLSearchParams({
						start: rangeStart,
						end: rangeEnd,
					})
					return presentationOf(`/${id}/calendar?${query.toString()}`)
				},
			})
		},
	},
})
