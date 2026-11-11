// The OpenRegister schema API contract lives in nc-vue, shared with OpenBuild's
// editor, so the two cannot drift on what a 409 means (breaking change / schema
// still has objects). See @conduction/nextcloud-vue src/utils/schemaApi.js.
// Aliased: this store's own actions are also called saveSchema/deleteSchema, and an
// unaliased call inside them would read like recursion.
import {
	deleteSchema as apiDeleteSchema,
	saveSchema as apiSaveSchema,
} from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { Schema } from '../../entities/index.js'
import { getJson, objectAnswer, okJson, sendJson } from '../../services/listPage.js'
import { listStateActions, listStateFields } from './listState.js'

// Module-scoped single-flight for refreshSchemaList; same rationale as the
// register store — AppInitializationService and every search/dashboard
// sidebar mount calls refreshSchemaList in parallel on boot. Coalescing
// here keeps the SearchSideBar's schemaLoading flag from racing past the
// e2e budget. CRUD-driven callers that pass a custom `search` bypass the
// cache.
let inFlightSchemaRefresh = null

/**
 * The object count the loaded schema list already holds for a schema, if any.
 *
 * @param {Array<object>} schemas The loaded schemas.
 * @param {string|number} schemaId The schema.
 * @return {number|undefined} The count, or undefined when the list has none.
 * @spec exclude Client-side read of cached schema statistics.
 */
function knownObjectCount(schemas, schemaId) {
	const schemaIdStr = String(schemaId)
	const existingSchema = schemas.find((s) => String(s.id) === schemaIdStr)
	return existingSchema?.stats?.objects?.total
}

/**
 * A schema's object count from the objects count API, or null when that API
 * fails or refuses.
 *
 * @param {string|number} schemaId The schema.
 * @return {Promise<number|null>} The count, or null.
 * @spec exclude Thin API passthrough — GET /api/objects/count.
 */
async function countFromObjectsApi(schemaId) {
	try {
		const countResponse = await fetch(
			`/index.php/apps/openregister/api/objects/count?schema=${schemaId}`,
		)
		if (!countResponse.ok) {
			return null
		}
		const countData = await countResponse.json()
		return countData.count || countData.total || 0
	} catch {
		return null
	}
}

/**
 * A schema's object count from its stats endpoint; 0 when it refuses.
 *
 * @param {string|number} schemaId The schema.
 * @return {Promise<number>} The count.
 * @spec exclude Thin API passthrough — GET /api/schemas/{id}/stats.
 */
async function countFromSchemaStats(schemaId) {
	const statsResponse = await fetch(
		`/index.php/apps/openregister/api/schemas/${schemaId}/stats`,
	)
	if (!statsResponse.ok) {
		return 0
	}
	// The stats endpoint returns objectCount and objects_count
	const stats = await statsResponse.json()
	return stats.objectCount || stats.objects_count || 0
}

/**
 * A copy of a schema without the fields the server sets or only the draft
 * routes write; a new schema's empty id is left out so the save is a create.
 *
 * @param {object} schemaItem The schema.
 * @return {object} The copy.
 * @spec exclude Client-side payload sanitiser — strips read-only fields before save. No standalone backend contract.
 */
function withoutServerSchemaFields(schemaItem) {
	const cleaned = { ...schemaItem }
	delete cleaned.updated
	delete cleaned.created
	delete cleaned.stats
	delete cleaned.archive
	delete cleaned.version // Backend determines version
	delete cleaned.draft // Only the draft routes write it
	if (!cleaned.id) {
		delete cleaned.id
	}
	return cleaned
}

/**
 * Turn a schema's top-level `required` list into `required: true` on each
 * listed property, and drop the list.
 *
 * @param {object} cleaned The schema copy to change.
 * @return {void}
 * @spec exclude Client-side payload sanitiser — strips read-only fields before save. No standalone backend contract.
 */
function moveRequiredToProperties(cleaned) {
	if (!Array.isArray(cleaned.required) || !cleaned.properties) {
		return
	}
	cleaned.required.forEach((propertyName) => {
		if (cleaned.properties[propertyName]) {
			cleaned.properties[propertyName].required = true
		}
	})
	delete cleaned.required
}

/**
 * Refuse a schema that cannot be downloaded: missing, not a Schema, or
 * without an id.
 *
 * @param {unknown} schema The schema to download
 * @return {void}
 * @throws {Error} Why it cannot be downloaded
 */
function assertDownloadable(schema) {
	if (!schema) {
		throw new Error('No schema item to download')
	}
	if (!(schema instanceof Schema)) {
		throw new Error('Invalid schema item to download')
	}
	if (!schema?.id) {
		throw new Error('No schema item ID to download')
	}
}

/**
 * Ask the server for a schema's download; a failed answer is logged and
 * thrown as its status text.
 *
 * @param {Schema} schema The schema
 * @return {Promise<Response>} The successful response
 */
async function fetchSchemaDownload(schema) {
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
	return response
}

/**
 * Let the browser save data as a prettified JSON file.
 *
 * @param {object} data The data to save
 * @param {string} filename The file name to offer
 * @return {void}
 */
function saveAsJsonFile(data, filename) {
	// Convert JSON to a prettified string
	const jsonString = JSON.stringify(data, null, 2)

	// Create a Blob from the JSON string
	const blob = new Blob([jsonString], { type: 'application/json' })

	// Create a URL for the Blob
	const url = URL.createObjectURL(blob)

	// Create a temporary anchor element
	const a = document.createElement('a')
	a.href = url
	a.download = filename

	// Temporarily add the anchor to the DOM and trigger the download
	document.body.appendChild(a)
	a.click()

	// Clean up
	document.body.removeChild(a)
	URL.revokeObjectURL(url)
}

export const useSchemaStore = defineStore('schema', {
	state: () => ({
		schemaItem: false,
		schemaPropertyKey: null, // holds a UUID of the property to edit
		schemaList: [],
		viewMode: 'cards',
		// True while the editor saves to the schema's draft instead of publishing (modelling-schema-draft).
		draftMode: false,
		...listStateFields(),
	}),
	getters: {
		getViewMode: (state) => state.viewMode,
		/**
		 * What the editor opens on: the pending draft in draft mode, else the published schema.
		 *
		 * @param {object} state - Store state
		 * @return {object|false} The schema to edit
		 * @spec openspec/changes/modelling-schema-draft/specs/runtime-schema-api/spec.md#requirement-req-sdraft-001-a-schema-edit-can-be-held-as-a-draft-until-it-is-published
		 */
		editorSchemaItem: (state) => {
			const item = state.schemaItem
			if (!state.draftMode || !item || !item.draft) {
				return item
			}
			return { ...item, ...item.draft, id: item.id, draft: item.draft }
		},
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
		 * Switch the editor between publishing and saving a draft.
		 *
		 * @param {boolean} draftMode - True to save edits as the schema's draft
		 * @spec openspec/changes/modelling-schema-draft/specs/runtime-schema-api/spec.md#requirement-req-sdraft-001-a-schema-edit-can-be-held-as-a-draft-until-it-is-published
		 */
		setDraftMode(draftMode) {
			this.draftMode = draftMode === true
		},
		/**
		 * Save an edit as the schema's draft. Records keep being checked against
		 * the published definition until the draft is published.
		 *
		 * @param {object} schemaItem - The edited schema (must have an id)
		 * @return {Promise<Schema>} The schema with its draft
		 * @spec openspec/changes/modelling-schema-draft/specs/runtime-schema-api/spec.md#requirement-req-sdraft-001-a-schema-edit-can-be-held-as-a-draft-until-it-is-published
		 */
		async saveSchemaDraft(schemaItem) {
			if (!schemaItem?.id) {
				throw new Error('Only a saved schema can hold a draft')
			}
			const body = this.cleanSchemaForSave(schemaItem)
			const { data } = await axios.put(
				generateUrl(
					`/apps/openregister/api/schemas/${schemaItem.id}?draft=true`,
				),
				body,
			)
			this.setSchemaItem(data)
			return this.schemaItem
		},
		/**
		 * Publish the schema's draft through the normal update (version bump, changelog).
		 *
		 * @param {number|string} id - The schema id
		 * @param {object} [options] - Options
		 * @param {boolean} [options.acknowledgeBreaking] - Accept a breaking change
		 * @return {Promise<Schema>} The published schema
		 * @spec openspec/changes/modelling-schema-draft/specs/runtime-schema-api/spec.md#requirement-req-sdraft-001-a-schema-edit-can-be-held-as-a-draft-until-it-is-published
		 */
		async publishSchemaDraft(id, options = {}) {
			const { data } = await axios.post(
				generateUrl(`/apps/openregister/api/schemas/${id}/draft/publish`),
				{ acknowledgeBreaking: options.acknowledgeBreaking === true },
			)
			this.setSchemaItem(data)
			this.refreshSchemaList()
			return this.schemaItem
		},
		/**
		 * Discard the schema's draft; the published definition stays.
		 *
		 * @param {number|string} id - The schema id
		 * @return {Promise<Schema>} The schema without a draft
		 * @spec openspec/changes/modelling-schema-draft/specs/runtime-schema-api/spec.md#requirement-req-sdraft-001-a-schema-edit-can-be-held-as-a-draft-until-it-is-published
		 */
		async discardSchemaDraft(id) {
			const { data } = await axios.delete(
				generateUrl(`/apps/openregister/api/schemas/${id}/draft`),
			)
			this.setSchemaItem(data)
			return this.schemaItem
		},
		/**
		 * Set the schema list, normalizing empty-properties arrays to objects
		 * and preserving each row's local showProperties toggle.
		 *
		 * @param {Array} schemas - Array of schema objects
		 * @spec exclude store setter (local list state + presentation normalization)
		 */
		setSchemaList(schemas) {
			this.schemaList = schemas.map((schema) => {
				const existing =
					this.schemaList.find((item) => item.id === schema.id) || {}
				// Convert properties array to object if needed (backend sometimes returns array when empty)
				const normalizedProperties = Array.isArray(schema.properties)
					? {}
					: schema.properties || {}
				return {
					...schema,
					properties: normalizedProperties,
					// keep previously toggled value if available, otherwise default false
					showProperties:
						typeof existing.showProperties === 'boolean'
							? existing.showProperties
							: false,
				}
			})
		},
		...listStateActions(14),
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
				inFlightSchemaRefresh = work.finally(() => {
					inFlightSchemaRefresh = null
				})
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
				const data = await getJson(endpoint)
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
				const data = await getJson(endpoint)
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
			const cleaned = withoutServerSchemaFields(schemaItem)
			// Keep configuration object intact - backend should handle it
			// Ensure configuration object exists with default values if not present
			if (!cleaned.configuration) {
				cleaned.configuration = {
					objectNameField: '',
					objectDescriptionField: '',
					objectSlugField: '',
					objectSlugSuffix: false,
				}
			}
			moveRequiredToProperties(cleaned)
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

			const response = await sendJson(endpoint, { method, body: schema })

			const responseData = await objectAnswer(response)

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
			assertDownloadable(schema)
			const response = await fetchSchemaDownload(schema)
			saveAsJsonFile(await response.json(), `${schema.title}.json`)

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

			const data = await okJson(response)

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

			const data = await okJson(response)

			if (data.error) {
				throw new Error(data.error)
			}

			// Refresh schema store data
			await this.refreshSchemaList()

			return data
		},

		/**
		 * Get object count for a schema
		 *
		 * @param {number} schemaId The schema ID to get object count for
		 * @return {Promise<number>} The number of objects in the schema
		 * @spec exclude API passthrough to GET /api/objects/count with stats-endpoint fallback
		 */
		async getObjectCount(schemaId) {
			try {
				const known = knownObjectCount(this.schemas, schemaId)
				if (known !== undefined) {
					return known
				}
				return (
					(await countFromObjectsApi(schemaId))
					?? (await countFromSchemaStats(schemaId))
				)
			} catch {
				return 0
			}
		},

		// schema properties
		setSchemaPropertyKey(schemaPropertyKey) {
			this.schemaPropertyKey = schemaPropertyKey
		},
	},
})
