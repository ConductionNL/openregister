import { defineStore } from 'pinia'
// The OpenRegister schema API contract lives in nc-vue, shared with OpenBuild's
// editor, so the two cannot drift on what a 409 means (breaking change / schema
// still has objects). See @conduction/nextcloud-vue src/utils/schemaApi.js.
// Aliased: this store's own actions are also called saveSchema/deleteSchema, and an
// unaliased call inside them would read like recursion.
import { saveSchema as apiSaveSchema, deleteSchema as apiDeleteSchema } from '@conduction/nextcloud-vue'
import { Schema } from '../../entities/index.js'

// Module-scoped single-flight for refreshSchemaList; same rationale as the
// register store — AppInitializationService and every search/dashboard
// sidebar mount calls refreshSchemaList in parallel on boot. Coalescing
// here keeps the SearchSideBar's schemaLoading flag from racing past the
// e2e budget. CRUD-driven callers that pass a custom `search` bypass the
// cache.
let inFlightSchemaRefresh = null

export const useSchemaStore = defineStore('schema', {
	state: () => ({
		schemaItem: false,
		schemaPropertyKey: null, // holds a UUID of the property to edit
		schemaList: [],
		viewMode: 'cards',
		filters: [], // List of query
		pagination: {
			page: 1,
			limit: 20,
		},
	}),
	getters: {
		getViewMode: (state) => state.viewMode,
	},
	actions: {
		/**
		 * Set the view mode (cards or table).
		 *
		 * @param {string} mode - The view mode
		 * @spec exclude store setter (local view-mode state)
		 */
		setViewMode(mode) {
			this.viewMode = mode
		},
		/**
		 * Set the active schema item.
		 *
		 * @param {object|null} schemaItem - The schema item to set
		 * @spec exclude store setter (wraps Schema entity construction)
		 */
		setSchemaItem(schemaItem) {
			this.schemaItem = schemaItem && new Schema(schemaItem)
		},
		/**
		 * Set the schema list, normalizing empty-properties arrays to objects
		 * and preserving each row's local showProperties toggle.
		 *
		 * @param {Array} schemas - Array of schema objects
		 * @spec exclude store setter (local list state + presentation normalization)
		 */
		setSchemaList(schemas) {
			this.schemaList = schemas.map(schema => {
				const existing = this.schemaList.find(item => item.id === schema.id) || {}
				// Convert properties array to object if needed (backend sometimes returns array when empty)
				const normalizedProperties = Array.isArray(schema.properties) ? {} : (schema.properties || {})
				return {
					...schema,
					properties: normalizedProperties,
					// keep previously toggled value if available, otherwise default false
					showProperties: typeof existing.showProperties === 'boolean' ? existing.showProperties : false,
				}
			})
		},
		/**
		 * Set pagination details
		 * @param {number} page - The current page number for pagination
		 * @param {number} limit - The number of items to display per page
		 * @spec exclude store setter (local pagination state)
		 */
		setPagination(page, limit = 14) {
			this.pagination = { page, limit }
		},
		/**
		 * Set query filters for schema list
		 * @param {object} filters - The filter criteria to apply to the schema list
		 * @spec exclude store setter (local filter state)
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
		},
		/**
		 * Refresh the schema list from the API.
		 *
		 * @param {string|null} search - Optional search term
		 * @return {Promise} Promise with response and data
		 * @spec exclude API passthrough to GET /api/schemas (list)
		 */
		/* istanbul ignore next */ // ignore this for Jest until moved into a service
		async refreshSchemaList(search = null) {
			if (search === null && inFlightSchemaRefresh) {
				return inFlightSchemaRefresh
			}
			let endpoint = '/index.php/apps/openregister/api/schemas'
			if (search !== null && search !== '') {
				endpoint = endpoint + '?_search=' + encodeURIComponent(search)
			}
			const work = (async () => {
				const response = await fetch(endpoint, { method: 'GET' })
				const data = (await response.json()).results
				this.setSchemaList(data)
				return { response, data }
			})()
			if (search === null) {
				inFlightSchemaRefresh = work.finally(() => { inFlightSchemaRefresh = null })
				return inFlightSchemaRefresh
			}
			return work
		},
		/**
		 * Get a single schema by id.
		 *
		 * @param {number|string} id - Schema id
		 * @param {object} options - { setItem } whether to set the active item
		 * @return {Promise} Promise with schema data
		 * @spec exclude API passthrough to GET /api/schemas/{id}
		 */
		async getSchema(id, options = { setItem: false }) {
			const endpoint = `/index.php/apps/openregister/api/schemas/${id}`
			try {
				const response = await fetch(endpoint, {
					method: 'GET',
				})
				const data = await response.json()
				// Convert properties array to object if needed (backend sometimes returns array when empty)
				if (data && Array.isArray(data.properties)) {
					data.properties = {}
				}
				options.setItem && this.setSchemaItem(data)
				return data
			} catch (err) {
				console.error(err)
				throw err
			}
		},
		/**
		 * Get schema statistics.
		 *
		 * @param {number|string} id - Schema id
		 * @return {Promise} Promise with stats data
		 * @spec exclude API passthrough to GET /api/schemas/{id}/stats
		 */
		async getSchemaStats(id) {
			const endpoint = `/index.php/apps/openregister/api/schemas/${id}/stats`
			try {
				const response = await fetch(endpoint, {
					method: 'GET',
				})
				const data = await response.json()
				return data
			} catch (err) {
				console.error('Error in getSchemaStats:', err)
				throw err
			}
		},
		/**
		 * Delete a schema.
		 *
		 * Goes through nc-vue's shared schema API contract. When objects still use the
		 * schema the server refuses, and that surfaces as `SchemaHasObjectsError`
		 * carrying `.objectCount` — callers show it and re-invoke with
		 * `deleteObjects: true` to cascade. It is never cascaded on the user's behalf:
		 * that permanently deletes their data.
		 *
		 * @param {object} schemaItem - The schema to delete
		 * @param {object} [options] - Options.
		 * @param {boolean} [options.deleteObjects] - Also delete the objects (irreversible).
		 * @return {Promise} Promise with response and data
		 * @throws {Error} A `SchemaHasObjectsError` (from nc-vue) when objects remain and
		 *   no cascade was asked for; it carries `.objectCount`.
		 * @spec exclude API passthrough to DELETE /api/schemas/{id}
		 */
		async deleteSchema(schemaItem, options = {}) {
			if (!schemaItem.id) {
				throw new Error('No schema item to delete')
			}

			// NOTE: deliberately NOT wrapped in a try/catch that rebuilds the error. The
			// typed refusals ARE the contract — flattening them into `new Error(...)`
			// would strip `.objectCount` and leave the caller unable to offer the
			// cascade, which is exactly the dead end this refactor removes.
			const responseData = await apiDeleteSchema(schemaItem.id, {
				deleteObjects: options.deleteObjects === true,
			})

			await this.refreshSchemaList()
			this.setSchemaItem(null)

			return { response: { ok: true }, data: responseData }
		},
		/**
		 * Create or save a schema from store.
		 *
		 * Goes through nc-vue's shared schema API contract rather than a hand-rolled
		 * fetch, so this editor and OpenBuild's cannot drift on what the server's
		 * refusals mean. The raw fetch here threw away the response body entirely
		 * (`HTTP error! status: 409`), which is why a breaking change surfaced as an
		 * unexplained failure and could not be saved from this app at all.
		 *
		 * A breaking change raises `SchemaBreakingChangeError` carrying the
		 * `changes[]` the server objected to — callers show those and re-invoke with
		 * `acknowledgeBreaking: true`. It is never acknowledged on the user's behalf.
		 *
		 * @param {object} schemaItem - The schema to save
		 * @param {object} [options] - Options.
		 * @param {boolean} [options.acknowledgeBreaking] - Accept a breaking change.
		 * @return {Promise} Promise with response and data
		 * @throws {Error} A `SchemaBreakingChangeError` (from nc-vue) when the change is
		 *   breaking and unacknowledged; it carries `.changes`.
		 * @spec exclude API passthrough to POST/PUT /api/schemas
		 */
		async saveSchema(schemaItem, options = {}) {
			if (!schemaItem) {
				throw new Error('No schema item to save')
			}

			// Clean the schema data before sending
			const cleanedSchema = this.cleanSchemaForSave(schemaItem)

			const responseData = await apiSaveSchema(cleanedSchema, {
				id: schemaItem?.id,
				acknowledgeBreaking: options.acknowledgeBreaking === true,
			})

			if (!responseData || typeof responseData !== 'object') {
				throw new Error('Invalid response data')
			}

			const data = new Schema(responseData)

			this.setSchemaItem(data)
			this.refreshSchemaList()

			return { response: { ok: true }, data }

		},
		/**
		 * Clean schema data for saving - remove read-only fields and fix structure.
		 *
		 * @param {object} schemaItem - The schema to clean
		 * @return {object} The cleaned schema payload
		 * @spec exclude pure request-payload shaping helper (no client state)
		 */
		cleanSchemaForSave(schemaItem) {
			const cleaned = { ...schemaItem }

			// Remove read-only/calculated fields that should not be sent to the server
			delete cleaned.updated
			delete cleaned.created
			delete cleaned.stats
			delete cleaned.archive
			delete cleaned.version // Backend determines version

			// New schemas have id: '' — omit it entirely so the backend unambiguously
			// treats the request as a create rather than an update with an empty id.
			if (!cleaned.id) {
				delete cleaned.id
			}

			// Keep configuration object intact - backend should handle it
			// Ensure configuration object exists with default values if not present
			if (!cleaned.configuration) {
				cleaned.configuration = {
					objectNameField: '',
					objectDescriptionField: '',
				}
			}

			// Convert required array to individual property required fields
			if (cleaned.required && Array.isArray(cleaned.required) && cleaned.properties) {
				// Set required: true on properties that are in the required array
				cleaned.required.forEach(propertyName => {
					if (cleaned.properties[propertyName]) {
						cleaned.properties[propertyName].required = true
					}
				})

				// Remove the top-level required array since we don't follow JSON Schema standard
				delete cleaned.required
			}

			return cleaned
		},
		/**
		 * Upload a schema from store.
		 *
		 * @param {object} schema - The schema to upload
		 * @return {Promise} Promise with response and data
		 * @spec exclude API passthrough to POST/PUT /api/schemas/upload
		 */
		async uploadSchema(schema) {
			if (!schema) {
				throw new Error('No schema item to upload')
			}

			const isNewSchema = !this.schemaItem
			const endpoint = isNewSchema
				? '/index.php/apps/openregister/api/schemas/upload'
				: `/index.php/apps/openregister/api/schemas/upload/${this.schemaItem.id}`
			const method = isNewSchema ? 'POST' : 'PUT'

			const response = await fetch(
				endpoint,
				{
					method,
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(schema),
				},
			)

			if (!response.ok) {
				throw new Error(`HTTP error! status: ${response.status}`)
			}

			const responseData = await response.json()

			if (!responseData || typeof responseData !== 'object') {
				throw new Error('Invalid response data')
			}

			const data = new Schema(responseData)

			this.setSchemaItem(data)
			this.refreshSchemaList()

			return { response, data }

		},
		/**
		 * Download a schema as a JSON file (triggers a browser download).
		 *
		 * @param {Schema} schema - The schema to download
		 * @return {Promise} Promise with response
		 * @spec exclude API passthrough to GET /api/schemas/{id}/download + browser-download side effect
		 */
		async downloadSchema(schema) {
			if (!schema) {
				throw new Error('No schema item to download')
			}
			if (!(schema instanceof Schema)) {
				throw new Error('Invalid schema item to download')
			}
			if (!schema?.id) {
				throw new Error('No schema item ID to download')
			}

			const response = await fetch(
				`/index.php/apps/openregister/api/schemas/${schema.id}/download`,
				{
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
					},
				},
			)

			if (!response.ok) {
				console.error(response)
				throw new Error(response.statusText)
			}

			const data = await response.json()

			// Convert JSON to a prettified string
			const jsonString = JSON.stringify(data, null, 2)

			// Create a Blob from the JSON string
			const blob = new Blob([jsonString], { type: 'application/json' })

			// Create a URL for the Blob
			const url = URL.createObjectURL(blob)

			// Create a temporary anchor element
			const a = document.createElement('a')
			a.href = url
			a.download = `${schema.title}.json`

			// Temporarily add the anchor to the DOM and trigger the download
			document.body.appendChild(a)
			a.click()

			// Clean up
			document.body.removeChild(a)
			URL.revokeObjectURL(url)

			return { response }
		},

		// Schema exploration methods
		/**
		 * Explore schema properties to discover new properties in objects
		 *
		 * @param {number} schemaId The schema ID to explore
		 * @return {Promise<object>} Exploration results
		 * @spec exclude API passthrough to GET /api/schemas/{id}/explore
		 */
		async exploreSchemaProperties(schemaId) {
			const endpoint = `/index.php/apps/openregister/api/schemas/${schemaId}/explore`

			const response = await fetch(endpoint, {
				method: 'GET',
				headers: {
					'Content-Type': 'application/json',
				},
			})

			if (!response.ok) {
				throw new Error(`HTTP error! status: ${response.status}`)
			}

			const data = await response.json()

			if (data.error) {
				throw new Error(data.error)
			}

			return data
		},

		/**
		 * Update schema properties based on exploration results
		 *
		 * @param {number} schemaId The schema ID to update
		 * @param {object} propertyUpdates Object containing properties to add/update
		 * @return {Promise<object>} Update results
		 * @spec exclude API passthrough to POST /api/schemas/{id}/update-from-exploration
		 */
		async updateSchemaFromExploration(schemaId, propertyUpdates) {
			const endpoint = `/index.php/apps/openregister/api/schemas/${schemaId}/update-from-exploration`

			const response = await fetch(endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify({
					properties: propertyUpdates,
				}),
			})

			if (!response.ok) {
				throw new Error(`HTTP error! status: ${response.status}`)
			}

			const data = await response.json()

			if (data.error) {
				throw new Error(data.error)
			}

			// Refresh schema store data
			await this.refreshSchemaList()

			return data
		},

		/**
		 * Get object count for a schema
		 * @param {number} schemaId The schema ID to get object count for
		 * @return {Promise<number>} The number of objects in the schema
		 * @spec exclude API passthrough to GET /api/objects/count with stats-endpoint fallback
		 */
		async getObjectCount(schemaId) {
			try {
				// Convert schemaId to string for comparison
				const schemaIdStr = String(schemaId)

				// First check if we already have stats for this schema
				const existingSchema = this.schemas.find(s => String(s.id) === schemaIdStr)
				if (existingSchema?.stats?.objects?.total !== undefined) {
					return existingSchema.stats.objects.total
				}

				// Try using the objects API to count objects for this schema
				try {
					const countResponse = await fetch(`/index.php/apps/openregister/api/objects/count?schema=${schemaId}`)
					if (countResponse.ok) {
						const countData = await countResponse.json()
						const count = countData.count || countData.total || 0
						return count
					}
				} catch (countError) {
					// Objects count API failed; fall back to the stats endpoint below.
				}

				// Fallback to stats endpoint
				const statsResponse = await fetch(`/index.php/apps/openregister/api/schemas/${schemaId}/stats`)

				if (statsResponse.ok) {
					const stats = await statsResponse.json()
					// The stats endpoint returns objectCount and objects_count
					const count = stats.objectCount || stats.objects_count || 0
					return count
				} else {
					return 0
				}
			} catch (error) {
				return 0
			}
		},

		// schema properties
		setSchemaPropertyKey(schemaPropertyKey) {
			this.schemaPropertyKey = schemaPropertyKey
		},
	},
})
