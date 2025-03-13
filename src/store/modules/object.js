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
	}),
	actions: {
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
		},
		setFiles(files) {
			this.files = files
		},
		/* istanbul ignore next */ // ignore this for Jest until moved into a service
		async refreshObjectList(options = {}) {
			// @todo this might belong in a service?
			let endpoint = '/index.php/apps/openregister/api/objects'
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

			return fetch(endpoint, {
				method: 'GET',
			})
				.then(
					(response) => {
						response.json().then(
							(data) => {
								this.setObjectList(data)
							},
						)
					},
				)
				.catch(
					(err) => {
						console.error(err)
					},
				)
		},
		// New function to get a single object
		async getObject(id) {
			const endpoint = `/index.php/apps/openregister/api/objects/${id}`
			try {
				const response = await fetch(endpoint, {
					method: 'GET',
				})
				const data = await response.json()
				this.setObjectItem(data)
				this.getAuditTrails(data.id)
				this.getRelations(data.id)

				return data
			} catch (err) {
				console.error(err)
				throw err
			}
		},
		// Delete an object
		async deleteObject(objectItem) {
			if (!objectItem.id) {
				throw new Error('No object item to delete')
			}

			console.info('Deleting object...')

			const endpoint = `/index.php/apps/openregister/api/objects/${objectItem.id}`

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

				this.refreshObjectList()
				this.setObjectItem(null)

				return { response, data: responseData }
			} catch (error) {
				console.error('Error deleting object:', error)
				throw new Error(`Failed to delete object: ${error.message}`)
			}
		},
		// Create or save an object from store
		async saveObject(objectItem) {
			if (!objectItem) {
				throw new Error('No object item to save')
			}

			console.info('Saving object...')

			const isNewObject = !objectItem.id
			const endpoint = isNewObject
				? '/index.php/apps/openregister/api/objects'
				: `/index.php/apps/openregister/api/objects/${objectItem.id}`
			const method = isNewObject ? 'POST' : 'PUT'

			// change updated to current date as a singular iso date string
			objectItem.updated = new Date().toISOString()

			const response = await fetch(
				endpoint,
				{
					method,
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(objectItem),
				},
			)

			if (!response.ok) {
				throw new Error(`HTTP error! status: ${response.status}`)
			}

			const data = new ObjectEntity(await response.json())

			this.refreshObjectList()
			this.setObjectItem(data)
			this.getAuditTrails(data.id)
			this.getRelations(data.id)

			return { response, data }
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
				const endpoint = `/index.php/apps/openregister/api/objects/${objectId}`

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

			let endpoint = `/index.php/apps/openregister/api/objects/audit-trails/${id}`
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

			let endpoint = `/index.php/apps/openregister/api/objects/relations/${id}`
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

			let endpoint = `/index.php/apps/openregister/api/objects/files/${id}`
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
			const endpoint = `/index.php/apps/openregister/api/objects/${id}/lock`

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
			const endpoint = `/index.php/apps/openregister/api/objects/${id}/unlock`

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
			const endpoint = `/index.php/apps/openregister/api/objects/${id}/revert`

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
	},
})
