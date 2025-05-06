/* eslint-disable no-console */
import { defineStore } from 'pinia'
import { Schema } from '../../entities/index.js'

export const useSchemaStore = defineStore('schema', {
	state: () => ({
		schemaItem: false,
		schemaPropertyKey: null, // holds a UUID of the property to edit
		schemaList: [],
		filters: [], // List of query
		pagination: {
			page: 1,
			limit: 20,
		},
	}),
	actions: {
		setSchemaItem(schemaItem) {
			this.schemaItem = schemaItem && new Schema(schemaItem)
			console.log('Active schema item set to ' + (schemaItem?.title || 'null'))
		},
		setSchemaList(schemas) {
			// Ensure showProperties is reactive and default false for each schema
			this.schemaList = schemas.map(schema => ({
				...schema,
				showProperties: typeof schema.showProperties === 'boolean' ? schema.showProperties : false,
			}))
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
			// Always include _extend[]=@self.stats to get statistics
			let endpoint = '/index.php/apps/openregister/api/schemas?_extend[]=@self.stats'
			if (search !== null && search !== '') {
				endpoint = endpoint + '&_search=' + encodeURIComponent(search)
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
			// Always include _extend[]=@self.stats to get statistics
			const endpoint = `/index.php/apps/openregister/api/schemas/${id}?_extend[]=@self.stats`
			try {
				const response = await fetch(endpoint, {
					method: 'GET',
				})
				const data = await response.json()
				options.setItem && this.setSchemaItem(data)
				return data
			} catch (err) {
				console.error(err)
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

			schemaItem.updated = new Date().toISOString()
			delete schemaItem.version

			const response = await fetch(
				endpoint,
				{
					method,
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(schemaItem),
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
		// schema properties
		setSchemaPropertyKey(schemaPropertyKey) {
			this.schemaPropertyKey = schemaPropertyKey
		},
	},
})
