import { defineStore } from 'pinia'
import { AuditTrail, ObjectEntity } from '../../entities/index.js'
import { useRegisterStore } from '../../store/modules/register.js'
import { useSchemaStore } from '../../store/modules/schema.js'

export const useObjectStore = defineStore('object', {
	state: () => ({
		objectItem: false,
		objectList: [],
		auditTrailItem: false,
		auditTrails: [],
		relationItem: false,
		relations: [],
		fileItem: false, // Single file item
		files: [], // List of files
		filters: {}, // List of query paramters
		pagination: {
			page: 1,
			limit: 20
		},
		selectedObjects: [],
		metadata: {
			objectId: {
				label: 'ID',
				key: 'id',
				description: 'Unique identifier of the object',
				enabled: true  // Enabled by default
			},
			uri: {
				label: 'URI',
				key: 'uri',
				description: 'Uniform resource identifier',
				enabled: false
			},
			version: {
				label: 'Version',
				key: 'version',
				description: 'Version number of the object',
				enabled: false
			},
			register: {
				label: 'Register',
				key: 'register',
				description: 'Register the object belongs to',
				enabled: false
			},
			schema: {
				label: 'Schema',
				key: 'schema',
				description: 'Schema the object follows',
				enabled: false
			},
			files: {
				label: 'Files',
				key: 'files',
				description: 'Attached files count',
				enabled: true  // Enabled by default
			},
			relations: {
				label: 'Relations',
				key: 'relations',
				description: 'Related objects count',
				enabled: false
			},
			locked: {
				label: 'Locked',
				key: 'locked',
				description: 'Lock status of the object',
				enabled: false
			},
			owner: {
				label: 'Owner',
				key: 'owner',
				description: 'Owner of the object',
				enabled: false
			},
			application: {
				label: 'Application',
				key: 'application',
				description: 'Application that created the object',
				enabled: false
			},
			folder: {
				label: 'Folder',
				key: 'folder',
				description: 'Storage folder location',
				enabled: false
			},
			files: {
				label: 'File',
				key: 'files',
				description: 'The files attached to the object',
				enabled: false
			},
			created: {
				label: 'Created',
				key: 'created',
				description: 'Creation date and time',
				enabled: true  // Enabled by default
			},
			updated: {
				label: 'Updated',
				key: 'updated',
				description: 'Last update date and time',
				enabled: true  // Enabled by default
			}
		},
		properties: {}, // Will be populated based on schema
		columnFilters: {},  // Will contain both metadata and property filters
		loading: false
	}),
	actions: {
		// Helper method to build endpoint path
		_buildObjectPath({ register, schema, objectId = '' }) {
			return `/index.php/apps/openregister/api/objects/${register}/${schema}${objectId ? '/' + objectId : ''}`
		},
		async setObjectItem(objectItem) {
			this.objectItem = objectItem && new ObjectEntity(objectItem)
			console.info('Active object item set to ' + objectItem?.['@self']?.id)

			// If we have a valid object item, fetch related data
			if (objectItem?.['@self']?.id) {
				try {
					// Fetch audit trails
					const auditTrailsEndpoint = `/index.php/apps/openregister/api/objects/${objectItem['@self'].register}/${objectItem['@self'].schema}/${objectItem['@self'].id}/audit-trails`
					const auditTrailsResponse = await fetch(auditTrailsEndpoint)
					const auditTrailsData = await auditTrailsResponse.json()
					this.setAuditTrails(auditTrailsData)

					// Fetch relations (used by)
					const relationsEndpoint = `/index.php/apps/openregister/api/objects/${objectItem['@self'].register}/${objectItem['@self'].schema}/${objectItem['@self'].id}/relations`
					const relationsResponse = await fetch(relationsEndpoint)
					const relationsData = await relationsResponse.json()
					this.setRelations(relationsData)

					// Fetch files
					const filesEndpoint = `/index.php/apps/openregister/api/objects/${objectItem['@self'].register}/${objectItem['@self'].schema}/${objectItem['@self'].id}/files`
					const filesResponse = await fetch(filesEndpoint)
					const filesData = await filesResponse.json()
					this.setFiles(filesData)
				} catch (error) {
					console.error('Error fetching related data:', error)
				}
			} else {
				// Clear related data when no object is selected
				this.setAuditTrails([])
				this.setRelations([])
				this.setFiles([])
			}
		},
		setObjectList(objectList) {
			this.objectList = {
				...objectList,
				results: objectList.results.map(
					(objectItem) => new ObjectEntity(objectItem),
				),
			}

			console.info('Object list set to ' + objectList.length + ' items')
		},
		setAuditTrailItem(auditTrailItem) {
			this.auditTrailItem = auditTrailItem && new AuditTrail(auditTrailItem)
		},
		setAuditTrails(auditTrails) {
			this.auditTrails = auditTrails
		},
		setRelationItem(relationItem) {
			this.relationItem = relationItem && new ObjectEntity(relationItem)
		},
		setRelations(relations) {
			this.relations = relations.map(
				(relation) => new ObjectEntity(relation),
			)
		},
		setFileItem(fileItem) {
			this.fileItem = fileItem
			console.info('File item set to', fileItem) // Logging the file item
		},
		setFiles(files) {
			this.files = files
			console.info('Files set to', files) // Logging the files
		},
		/**
		 * Set pagination details
		 *
		 * @param {number} page
		 * @param {number} [limit=14]
		 * @return {void}
		 */
		setPagination(page, limit = 14) {
			this.pagination = { page, limit }
			console.info('Pagination set to', { page, limit }) // Logging the pagination
		},
		/**
		 * Set query filters for object list
		 *
		 * @param {Object} filters
		 * @return {void}
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
			console.info('Query filters set to', this.filters) // Logging the filters
		},
		async refreshObjectList(options = {}) {
			const registerStore = useRegisterStore()
			const schemaStore = useSchemaStore()
			
			const register = options.register || registerStore.registerItem?.id
			const schema = options.schema || schemaStore.schemaItem?.id
			
			if (!register || !schema) {
				throw new Error('Register and schema are required')
			}

			let endpoint = this._buildObjectPath({
				register,
				schema
			})
			
			const params = []

			// Handle filters as an object
			Object.entries(this.filters).forEach(([key, value]) => {
				if (value !== undefined && value !== '') {
					params.push(`${key}=${encodeURIComponent(value)}`)
				}
			})
			
			if (options.limit || this.pagination.limit) params.push('_limit=' + (options.limit || this.pagination.limit))
			if (options.page || this.pagination.page) params.push('_page=' + (options.page || this.pagination.page))

			if (params.length > 0) {
				endpoint += '?' + params.join('&')
			}

			try {
				const response = await fetch(endpoint)
				const data = await response.json()
				this.setObjectList(data)
				return { response, data }
			} catch (err) {
				console.error(err)
				throw err
			}
		},
		async getObject({ register, schema, objectId }) {
			if (!register || !schema || !objectId) {
				throw new Error('Register, schema and objectId are required')
			}

			const endpoint = this._buildObjectPath({ register, schema, objectId })

			try {
				const response = await fetch(endpoint)
				const data = await response.json()
				this.setObjectItem(data)
				this.getAuditTrails({ register, schema, objectId })
				this.getRelations({ register, schema, objectId })
				return data
			} catch (err) {
				console.error(err)
				throw err
			}
		},
		async saveObject(objectItem, { register, schema }) {
			if (!objectItem || !register || !schema) {
				throw new Error('Object item, register and schema are required')
			}

			const isNewObject = !objectItem['@self'].id
			const endpoint = this._buildObjectPath({
				register,
				schema,
				objectId: isNewObject ? '' : objectItem['@self'].id
			})

			objectItem['@self'].updated = new Date().toISOString()

			try {
				const response = await fetch(endpoint, {
					method: isNewObject ? 'POST' : 'PUT',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify(objectItem)
				})

				const data = new ObjectEntity(await response.json())
				this.setObjectItem(data)
				await this.refreshObjectList({ register, schema })
				return { response, data }
			} catch (error) {
				console.error('Error saving object:', error)
				throw error
			}
		},
		// mass delete objects
		async massDeleteObject(objectIds) {
			if (!objectIds.length) {
				throw new Error('No object ids to delete')
			}

			console.info('Deleting objects...')

			const result = {
				successfulIds: [],
				failedIds: [],
			}

			await Promise.all(objectIds.map(async (objectId) => {
				const endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}${objectId ? '/' + objectId : ''}`

				try {
					const response = await fetch(endpoint, {
						method: 'DELETE',
					})

					if (response.ok) {
						result.successfulIds.push(objectId)
					} else {
						result.failedIds.push(objectId)
					}
				} catch (error) {
					console.error('Error deleting object:', error)
					result.failedIds.push(objectId)
				}
			}))

			this.refreshObjectList()

			return result
		},
		// AUDIT TRAILS
		async getAuditTrails(id, options = {}) {
			if (!id) {
				throw new Error('No object id to get audit trails for')
			}

			let endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/audit-trails}`
			const params = []

			if (options.search && options.search !== '') {
				params.push('_search=' + options.search)
			}
			if (options.limit && options.limit !== '') {
				params.push('_limit=' + options.limit)
			}
			if (options.page && options.page !== '') {
				params.push('_page=' + options.page)
			}

			if (params.length > 0) {
				endpoint += '?' + params.join('&')
			}

			const response = await fetch(endpoint, {
				method: 'GET',
			})

			const responseData = await response.json()
			const data = {
				...responseData,
				results: responseData.results.map((auditTrail) => new AuditTrail(auditTrail)),
			}

			this.setAuditTrails(data)

			return { response, data }
		},
		// RELATIONS
		async getRelations(id, options = {}) {
			if (!id) {
				throw new Error('No object id to get relations for')
			}

			let endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/relations`
			const params = []

			if (options.search && options.search !== '') {
				params.push('_search=' + options.search)
			}
			if (options.limit && options.limit !== '') {
				params.push('_limit=' + options.limit)
			}
			if (options.page && options.page !== '') {
				params.push('_page=' + options.page)
			}

			if (params.length > 0) {
				endpoint += '?' + params.join('&')
			}

			const response = await fetch(endpoint, {
				method: 'GET',
			})

			const responseData = await response.json()
			const data = responseData.map((relation) => new ObjectEntity(relation))

			this.setRelations(data)

			return { response, data }
		},
		// FILES
		/**
		 * Get files for an object
		 *
		 * @param {number} id Object ID
		 * @param options Pagination options
		 * @return {Promise} Promise that resolves with the object's files
		 */
		async getFiles(id, options = {}) {
			if (!id) {
				throw new Error('No object id to get files for')
			}

			let endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/files`
			const params = []

			if (options.search && options.search !== '') {
				params.push('_search=' + options.search)
			}
			if (options.limit && options.limit !== '') {
				params.push('_limit=' + options.limit)
			}
			if (options.page && options.page !== '') {
				params.push('_page=' + options.page)
			}

			if (params.length > 0) {
				endpoint += '?' + params.join('&')
			}

			try {
				const response = await fetch(endpoint, {
					method: 'GET',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				this.setFiles(data || [])

				return { response, data }
			} catch (error) {
				console.error('Error getting files:', error)
				throw new Error(`Failed to get files: ${error.message}`)
			}
		},
		// mappings
		async getMappings() {
			const endpoint = '/index.php/apps/openregister/api/objects/mappings'

			const response = await fetch(endpoint, {
				method: 'GET',
			})

			const data = (await response.json()).results

			return { response, data }
		},
		/**
		 * Lock an object
		 *
		 * @param {number} id Object ID
		 * @param {string|null} process Optional process identifier
		 * @param {number|null} duration Lock duration in seconds
		 * @return {Promise} Promise that resolves when the object is locked
		 */
		async lockObject(id, process = null, duration = null) {
			const endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/lock`

			try {
				const response = await fetch(endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						process,
						duration,
					}),
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				this.setObjectItem(data)
				this.refreshObjectList()

				return { response, data }
			} catch (error) {
				console.error('Error locking object:', error)
				throw new Error(`Failed to lock object: ${error.message}`)
			}
		},
		/**
		 * Unlock an object
		 *
		 * @param {number} id Object ID
		 * @return {Promise} Promise that resolves when the object is unlocked
		 */
		async unlockObject(id) {
			const endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/${id}/unlock`

			try {
				const response = await fetch(endpoint, {
					method: 'POST',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				this.setObjectItem(data)
				this.refreshObjectList()

				return { response, data }
			} catch (error) {
				console.error('Error unlocking object:', error)
				throw new Error(`Failed to unlock object: ${error.message}`)
			}
		},
		/**
		 * Revert an object to a previous state
		 *
		 * @param {number} id Object ID
		 * @param {object} options Revert options
		 * @param {string} [options.datetime] ISO datetime string
		 * @param {string} [options.auditTrailId] Audit trail ID
		 * @param {string} [options.version] Semantic version
		 * @param {boolean} [options.overwriteVersion] Whether to overwrite version
		 * @return {Promise} Promise that resolves when the object is reverted
		 */
		async revertObject(id, options) {
			const endpoint = `/index.php/apps/openregister/api/objects/${register}/${schema}/${objectId}/${id}/revert`

			try {
				const response = await fetch(endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(options),
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				this.setObjectItem(data)
				this.refreshObjectList()

				return { response, data }
			} catch (error) {
				console.error('Error reverting object:', error)
				throw new Error(`Failed to revert object: ${error.message}`)
			}
		},
		setSelectedObjects(objects) {
			this.selectedObjects = objects
		},
		toggleSelectAllObjects() {
			if (this.isAllSelected) {
				// Clear selection
				this.selectedObjects = []
			} else {
				// Select all current objects
				this.selectedObjects = this.objectList.results.map(result => result['@self'].id)
			}
		},
		updateColumnFilter(id, enabled) {
			console.log('Updating column filter:', id, enabled)
			console.log('Current columnFilters:', this.columnFilters)
			
			if (id.startsWith('meta_')) {
				const metaId = id.replace('meta_', '')
				if (this.metadata[metaId]) {
					this.metadata[metaId].enabled = enabled
					this.columnFilters[id] = enabled
					console.log('Updated metadata filter:', metaId, enabled)
				}
			} else if (id.startsWith('prop_')) {
				const propId = id.replace('prop_', '')
				if (this.properties[propId]) {
					this.properties[propId].enabled = enabled
					this.columnFilters[id] = enabled
					console.log('Updated property filter:', propId, enabled)
				}
			}
			
			console.log('Updated columnFilters:', this.columnFilters)
			// Force a refresh of the table
			this.objectList = { ...this.objectList }
		},
		// Initialize properties based on schema
		initializeProperties(schema) {
			if (!schema?.properties) return

			console.log('Initializing properties from schema:', schema.properties)

			// Reset properties
			this.properties = {}

			// Create property entries similar to metadata structure
			Object.entries(schema.properties).forEach(([propertyName, property]) => {
				this.properties[propertyName] = {
					// Capitalize first letter and replace underscores/hyphens with spaces
					label: propertyName
						.split(/[-_]/)
						.map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
						.join(' '),
					key: propertyName,
					description: property.description || '',
					enabled: false,
					type: property.type
				}
			})

			console.log('Properties initialized:', this.properties)

			// Reinitialize column filters to include new properties
			this.initializeColumnFilters()
		},
		// Update to handle both metadata and properties
		initializeColumnFilters() {
			this.columnFilters = {
				...Object.entries(this.metadata).reduce((acc, [id, meta]) => {
					acc[`meta_${id}`] = meta.enabled
					return acc
				}, {}),
				...Object.entries(this.properties).reduce((acc, [id, prop]) => {
					acc[`prop_${id}`] = prop.enabled
					return acc
				}, {})
			}
			console.log('Initialized column filters:', this.columnFilters)
		},
	},
	getters: {
		isAllSelected() {
			if (!this.objectList?.results?.length) return false
			const currentIds = this.objectList.results.map(result => result['@self'].id)
			return currentIds.every(id => this.selectedObjects.includes(id))
		},
		// Add getter for enabled metadata columns
		enabledMetadata() {
			return Object.entries(this.metadata)
				.filter(([id]) => this.columnFilters[`meta_${id}`])
				.map(([id, meta]) => ({
					id: `meta_${id}`,
					...meta
				}))
		},
		// Add getter for enabled property columns
		enabledProperties() {
			return Object.entries(this.properties)
				.filter(([id]) => this.columnFilters[`prop_${id}`])
				.map(([id, prop]) => ({
					id: `prop_${id}`,
					...prop
				}))
		},
		// Separate getter for ID/UUID metadata
		enabledIdentifierMetadata() {
			return Object.entries(this.metadata)
				.filter(([id]) => 
					(id === 'objectId' || id === 'uuid') && 
					this.columnFilters[`meta_${id}`]
				)
				.map(([id, meta]) => ({
					id: `meta_${id}`,
					...meta
				}))
		},
		// Separate getter for other metadata
		enabledOtherMetadata() {
			return Object.entries(this.metadata)
				.filter(([id]) => 
					id !== 'objectId' && 
					id !== 'uuid' && 
					this.columnFilters[`meta_${id}`]
				)
				.map(([id, meta]) => ({
					id: `meta_${id}`,
					...meta
				}))
		},
		// Combined enabled columns in the desired order
		enabledColumns() {
			return [
				...this.enabledIdentifierMetadata,  // ID/UUID first
				...this.enabledProperties,          // Then properties
				...this.enabledOtherMetadata        // Then other metadata
			]
		}
	}
})
