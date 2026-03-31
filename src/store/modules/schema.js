/* eslint-disable no-console */
import { createCrudStore } from '@conduction/nextcloud-vue'
import { Schema } from '../../entities/index.js'

export const useSchemaStore = createCrudStore('schema', {
	endpoint: 'schemas',
	entity: Schema,
	features: { viewMode: true },
	extend: {
		state: () => ({
			schemaPropertyKey: null,
		}),
		actions: {
			setSchemaPropertyKey(schemaPropertyKey) {
				this.schemaPropertyKey = schemaPropertyKey
			},
			// Override setList — preserves showProperties toggle and normalizes properties
			setList(schemas) {
				this.list = schemas.map(schema => {
					const existing = this.list.find(item => item.id === schema.id) || {}
					const normalizedProperties = Array.isArray(schema.properties) ? {} : (schema.properties || {})
					return {
						...schema,
						properties: normalizedProperties,
						showProperties: typeof existing.showProperties === 'boolean' ? existing.showProperties : false,
					}
				})
				console.log('Schema list set to ' + schemas.length + ' items')
			},
			// Override getOne — normalizes properties array to object
			async getOne(id, options = { setItem: false }) {
				const response = await fetch(`${this._options.baseApiUrl}/${id}`, { method: 'GET' })
				const data = await response.json()
				if (data && Array.isArray(data.properties)) {
					data.properties = {}
				}
				if (options.setItem) this.setItem(data)
				return data
			},
			// Override cleanForSave — handles configuration defaults and required array conversion
			cleanForSave(schemaItem) {
				const cleaned = { ...schemaItem }
				delete cleaned.updated
				delete cleaned.created
				delete cleaned.stats
				delete cleaned.archive
				delete cleaned.version
				if (!cleaned.configuration) {
					cleaned.configuration = { objectNameField: '', objectDescriptionField: '' }
				}
				if (cleaned.required && Array.isArray(cleaned.required) && cleaned.properties) {
					cleaned.required.forEach(propertyName => {
						if (cleaned.properties[propertyName]) {
							cleaned.properties[propertyName].required = true
						}
					})
					delete cleaned.required
				}
				return cleaned
			},
			async getSchemaStats(id) {
				console.log('getSchemaStats called with ID:', id)
				const response = await fetch(`${this._options.baseApiUrl}/${id}/stats`, { method: 'GET' })
				return await response.json()
			},
			async publishSchema(schemaId, date = null) {
				if (!schemaId) throw new Error('No schema ID provided')
				console.log('Publishing schema...')
				let endpoint = `${this._options.baseApiUrl}/${schemaId}/publish`
				if (date) endpoint += `?date=${encodeURIComponent(date)}`
				try {
					const response = await fetch(endpoint, { method: 'POST' })
					if (!response.ok) {
						const errorData = await response.json().catch(() => ({}))
						throw new Error(errorData.error || `HTTP error! status: ${response.status}`)
					}
					const responseData = await response.json()
					await this.refreshList()
					if (this.item && this.item.id === schemaId) {
						this.setItem(responseData)
					}
					return { response, data: responseData }
				} catch (error) {
					console.error('Error publishing schema:', error)
					throw new Error(`Failed to publish schema: ${error.message}`)
				}
			},
			async depublishSchema(schemaId, date = null) {
				if (!schemaId) throw new Error('No schema ID provided')
				console.log('Depublishing schema...')
				let endpoint = `${this._options.baseApiUrl}/${schemaId}/depublish`
				if (date) endpoint += `?date=${encodeURIComponent(date)}`
				try {
					const response = await fetch(endpoint, { method: 'POST' })
					if (!response.ok) {
						const errorData = await response.json().catch(() => ({}))
						throw new Error(errorData.error || `HTTP error! status: ${response.status}`)
					}
					const responseData = await response.json()
					await this.refreshList()
					if (this.item && this.item.id === schemaId) {
						this.setItem(responseData)
					}
					return { response, data: responseData }
				} catch (error) {
					console.error('Error depublishing schema:', error)
					throw new Error(`Failed to depublish schema: ${error.message}`)
				}
			},
			async uploadSchema(schema) {
				if (!schema) throw new Error('No schema item to upload')
				console.log('Uploading schema...')
				const isNew = !this.item
				const endpoint = isNew
					? this._options.baseApiUrl + '/upload'
					: `${this._options.baseApiUrl}/upload/${this.item.id}`
				const method = isNew ? 'POST' : 'PUT'
				const response = await fetch(endpoint, {
					method,
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify(schema),
				})
				if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
				const responseData = await response.json()
				if (!responseData || typeof responseData !== 'object') throw new Error('Invalid response data')
				const data = new Schema(responseData)
				this.setItem(data)
				this.refreshList()
				return { response, data }
			},
			async downloadSchema(schema) {
				if (!schema) throw new Error('No schema item to download')
				if (!(schema instanceof Schema)) throw new Error('Invalid schema item to download')
				if (!schema?.id) throw new Error('No schema item ID to download')
				console.log('Downloading schema...')
				const response = await fetch(`${this._options.baseApiUrl}/${schema.id}/download`, {
					method: 'GET',
					headers: { 'Content-Type': 'application/json' },
				})
				if (!response.ok) throw new Error(response.statusText)
				const data = await response.json()
				const jsonString = JSON.stringify(data, null, 2)
				const blob = new Blob([jsonString], { type: 'application/json' })
				const url = URL.createObjectURL(blob)
				const a = document.createElement('a')
				a.href = url
				a.download = `${schema.title}.json`
				document.body.appendChild(a)
				a.click()
				document.body.removeChild(a)
				URL.revokeObjectURL(url)
				return { response }
			},
			async exploreSchemaProperties(schemaId) {
				console.log('Exploring schema properties for schema ID:', schemaId)
				const response = await fetch(`${this._options.baseApiUrl}/${schemaId}/explore`, {
					method: 'GET',
					headers: { 'Content-Type': 'application/json' },
				})
				if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
				const data = await response.json()
				if (data.error) throw new Error(data.error)
				console.log('Schema exploration completed:', data)
				return data
			},
			async updateSchemaFromExploration(schemaId, propertyUpdates) {
				console.log('Updating schema from exploration for schema ID:', schemaId)
				const response = await fetch(`${this._options.baseApiUrl}/${schemaId}/update-from-exploration`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ properties: propertyUpdates }),
				})
				if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
				const data = await response.json()
				if (data.error) throw new Error(data.error)
				console.log('Schema updated from exploration:', data)
				await this.refreshList()
				return data
			},
			async getObjectCount(schemaId) {
				try {
					const schemaIdStr = String(schemaId)
					const existingSchema = this.list.find(s => String(s.id) === schemaIdStr)
					if (existingSchema?.stats?.objects?.total !== undefined) {
						return existingSchema.stats.objects.total
					}
					try {
						const countResponse = await fetch(`/index.php/apps/openregister/api/objects/count?schema=${schemaId}`)
						if (countResponse.ok) {
							const countData = await countResponse.json()
							return countData.count || countData.total || 0
						}
					} catch (countError) {
						console.warn('Objects count API failed, falling back to stats:', countError)
					}
					const statsResponse = await fetch(`${this._options.baseApiUrl}/${schemaId}/stats`)
					if (statsResponse.ok) {
						const stats = await statsResponse.json()
						return stats.objectCount || stats.objects_count || 0
					}
					return 0
				} catch (error) {
					console.warn('Could not fetch object count:', error)
					return 0
				}
			},
		},
	},
})
