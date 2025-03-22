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
			uuid: {
				label: 'UUID',
				key: 'uuid',
				description: 'Universal unique identifier',
				enabled: false
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
		columnFilters: {},  // This will now be populated from metadata
		loading: false
	}),
	actions: {
		// Helper method to build endpoint path
		_buildObjectPath({ register, schema, objectId = '' }) {
			return `/index.php/apps/openregister/api/objects/${register}/${schema}${objectId ? '/' + objectId : ''}`
		},
		async setObjectItem(objectItem) {
			this.objectItem = objectItem && new ObjectEntity(objectItem)
			console.info('Active object item set to ' + objectItem)
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
			console.log('Updating column filter:', id, enabled) // Debug log
			if (this.metadata[id]) {
				this.metadata[id].enabled = enabled
				this.columnFilters[id] = enabled
				// Force a refresh of the table
				this.objectList = { ...this.objectList }
			}
		},
		// Initialize columnFilters from metadata enabled states
		initializeColumnFilters() {
			this.columnFilters = Object.entries(this.metadata).reduce((acc, [id, meta]) => {
				acc[id] = meta.enabled
				return acc
			}, {})
			console.log('Initialized column filters:', this.columnFilters) // Debug log
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
				.filter(([id]) => this.columnFilters[id])
				.map(([id, meta]) => ({
					id,
					...meta
				}))
		}
	}
})
