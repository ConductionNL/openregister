import { defineStore } from 'pinia'
import { AuditTrail, ObjectEntity } from '../../entities/index.js'

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
		activeRegister: null,
		activeSchema: null,
		pagination: {
			page: 1,
			limit: 20
		},
		selectedObjects: [],
		columnFilters: {
			objectId: true,
			created: true,
			updated: true,
			files: true,
		},
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
		setActiveRegister(register) {
			this.activeRegister = register
			console.info('Active register set to', register) // Logging the active register
		},
		setActiveSchema(schema) {
			this.activeSchema = schema
			console.info('Active schema set to', schema) // Logging the active schema
		},
		setPagination(page, limit = 14) {
			this.pagination = { page, limit }
			console.info('Pagination set to', { page, limit }) // Logging the pagination
		},
		async refreshObjectList(options = {}) {
			const register = options.register || this.activeRegister?.id
			const schema = options.schema || this.activeSchema?.id
			
			if (!register || !schema) {
				throw new Error('Register and schema are required')
			}

			let endpoint = this._buildObjectPath({
				register,
				schema
			})
			
			const params = []
			if (options.search) params.push('_search=' + options.search)
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
			this.columnFilters[id] = enabled
		},
	},
	getters: {
		isAllSelected() {
			if (!this.objectList?.results?.length) return false
			const currentIds = this.objectList.results.map(result => result['@self'].id)
			return currentIds.every(id => this.selectedObjects.includes(id))
		}
	}
})
