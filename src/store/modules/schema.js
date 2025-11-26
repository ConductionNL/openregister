/* eslint-disable no-console */
import { defineStore } from 'pinia'
import { Schema } from '../../entities/index.js'

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
		setViewMode(mode) {
			this.viewMode = mode
			console.log('View mode set to:', mode)
		},
		setSchemaItem(schemaItem) {
			this.schemaItem = schemaItem && new Schema(schemaItem)
			console.log('Active schema item set to ' + (schemaItem?.title || 'null'))
		},
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
		console.log('Schema list set to ' + schemas.length + ' items')
	},
		/**
		 * Set pagination details
		 * @param {number} page - The current page number for pagination
		 * @param {number} limit - The number of items to display per page
		 */
		setPagination(page, limit = 14) {
			this.pagination = { page, limit }
			console.info('Pagination set to', { page, limit }) // Logging the pagination
		},
		/**
		 * Set query filters for schema list
		 * @param {object} filters - The filter criteria to apply to the schema list
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
			console.info('Query filters set to', this.filters) // Logging the filters
		},
		/* istanbul ignore next */ // ignore this for Jest until moved into a service
		async refreshSchemaList(search = null) {
			let endpoint = '/index.php/apps/openregister/api/schemas'
			if (search !== null && search !== '') {
				endpoint = endpoint + '?_search=' + encodeURIComponent(search)
			}
			const response = await fetch(endpoint, {
				method: 'GET',
			})

			const data = (await response.json()).results

			this.setSchemaList(data)

			return { response, data }
		},
	// Function to get a single schema
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
		// New function to get schema statistics
		async getSchemaStats(id) {
			console.log('getSchemaStats called with ID:', id)
			const endpoint = `/index.php/apps/openregister/api/schemas/${id}/stats`
			console.log('Making request to:', endpoint)
			try {
				const response = await fetch(endpoint, {
					method: 'GET',
				})
				console.log('Response status:', response.status)
				const data = await response.json()
				console.log('Response data:', data)
				return data
			} catch (err) {
				console.error('Error in getSchemaStats:', err)
				throw err
			}
		},
		// Delete a schema
		async deleteSchema(schemaItem) {
			if (!schemaItem.id) {
				throw new Error('No schema item to delete')
			}

			console.log('Deleting schema...')

			const endpoint = `/index.php/apps/openregister/api/schemas/${schemaItem.id}`

			try {
				const response = await fetch(endpoint, {
					method: 'DELETE',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const responseData = await response.json()

				if (!responseData || typeof responseData !== 'object') {
					throw new Error('Invalid response data')
				}

				await this.refreshSchemaList()
				this.setSchemaItem(null)

				return { response, data: responseData }
			} catch (error) {
				console.error('Error deleting schema:', error)
				throw new Error(`Failed to delete schema: ${error.message}`)
			}
		},
		// Create or save a schema from store
		async saveSchema(schemaItem) {
			if (!schemaItem) {
				throw new Error('No schema item to save')
			}

			console.log('Saving schema...')

			const isNewSchema = !schemaItem?.id
			const endpoint = isNewSchema
				? '/index.php/apps/openregister/api/schemas'
				: `/index.php/apps/openregister/api/schemas/${schemaItem.id}`
			const method = isNewSchema ? 'POST' : 'PUT'

			// Clean the schema data before sending
			const cleanedSchema = this.cleanSchemaForSave(schemaItem)

			const response = await fetch(
				endpoint,
				{
					method,
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(cleanedSchema),
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
		// Clean schema data for saving - remove read-only fields and fix structure
		cleanSchemaForSave(schemaItem) {
			const cleaned = { ...schemaItem }

			// Remove read-only/calculated fields that should not be sent to the server
			delete cleaned.updated
			delete cleaned.created
			delete cleaned.stats
			delete cleaned.archive
			delete cleaned.version // Backend determines version

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
		// Create or save a schema from store
		async uploadSchema(schema) {
			if (!schema) {
				throw new Error('No schema item to upload')
			}

			console.log('Uploading schema...')

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

			console.log('Downloading schema...')

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
		 */
		async exploreSchemaProperties(schemaId) {
			console.log('Exploring schema properties for schema ID:', schemaId)

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

			console.log('Schema exploration completed:', data)
			return data
		},

		/**
		 * Update schema properties based on exploration results
		 *
		 * @param {number} schemaId The schema ID to update
		 * @param {object} propertyUpdates Object containing properties to add/update
		 * @return {Promise<object>} Update results
		 */
		async updateSchemaFromExploration(schemaId, propertyUpdates) {
			console.log('Updating schema from exploration for schema ID:', schemaId)

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

			console.log('Schema updated from exploration:', data)

			// Refresh schema store data
			await this.refreshSchemaList()

			return data
		},

		/**
		 * Get object count for a schema
		 * @param {number} schemaId The schema ID to get object count for
		 * @return {Promise<number>} The number of objects in the schema
		 */
		async getObjectCount(schemaId) {
			try {
				// Convert schemaId to string for comparison
				const schemaIdStr = String(schemaId)

				// First check if we already have stats for this schema
				const existingSchema = this.schemas.find(s => String(s.id) === schemaIdStr)
				if (existingSchema?.stats?.objects?.total !== undefined) {
					console.log('Using cached stats for schema:', schemaId, existingSchema.stats.objects.total)
					return existingSchema.stats.objects.total
				}

				console.log('Fetching object count for schema:', schemaId)

				// Try using the objects API to count objects for this schema
				try {
					const countResponse = await fetch(`/index.php/apps/openregister/api/objects/count?schema=${schemaId}`)
					if (countResponse.ok) {
						const countData = await countResponse.json()
						console.log('Count response data:', countData)
						const count = countData.count || countData.total || 0
						console.log('Extracted object count from objects API:', count)
						return count
					}
				} catch (countError) {
					console.warn('Objects count API failed, falling back to stats:', countError)
				}

				// Fallback to stats endpoint
				const statsResponse = await fetch(`/index.php/apps/openregister/api/schemas/${schemaId}/stats`)
				console.log('Stats response status:', statsResponse.status)

				if (statsResponse.ok) {
					const stats = await statsResponse.json()
					console.log('Stats response data:', stats)
					// The stats endpoint returns objectCount and objects_count
					const count = stats.objectCount || stats.objects_count || 0
					console.log('Extracted object count:', count)
					return count
				} else {
					console.warn('Stats API returned error:', statsResponse.status, statsResponse.statusText)
					// Try to get response text for debugging
					try {
						const errorText = await statsResponse.text()
						console.warn('Error response body:', errorText)
					} catch (e) {
						// Ignore error reading response
					}
					return 0
				}
			} catch (error) {
				console.warn('Could not fetch object count:', error)
				return 0
			}
		},

		// schema properties
		setSchemaPropertyKey(schemaPropertyKey) {
			this.schemaPropertyKey = schemaPropertyKey
		},
	},
})
