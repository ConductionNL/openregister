/* eslint-disable no-console */
import { defineStore } from 'pinia'
import { AuditTrail, ObjectEntity } from '../../entities/index.js'

export const useObjectStore = defineStore('object', {
	state: () => ({
		objectItem: false,
		objectList: [],
		auditTrailItem: false,
		auditTrails: [],
	}),
	actions: {
		setObjectItem(objectItem) {
			this.objectItem = objectItem && new ObjectEntity(objectItem)
			console.log('Active object item set to ' + objectItem)
		},
		setObjectList(objectList) {
			this.objectList = objectList.map(
				(objectItem) => new ObjectEntity(objectItem),
			)
			console.log('Object list set to ' + objectList.length + ' items')
		},
		setAuditTrailItem(auditTrailItem) {
			this.auditTrailItem = auditTrailItem && new AuditTrail(auditTrailItem)
		},
		setAuditTrails(auditTrails) {
			this.auditTrails = auditTrails.map(
				(auditTrail) => new AuditTrail(auditTrail),
			)
		},
		/* istanbul ignore next */ // ignore this for Jest until moved into a service
		async refreshObjectList(search = null) {
			// @todo this might belong in a service?
			let endpoint = '/index.php/apps/openregister/api/objects'
			if (search !== null && search !== '') {
				endpoint = endpoint + '?_search=' + search
			}
			return fetch(endpoint, {
				method: 'GET',
			})
				.then(
					(response) => {
						response.json().then(
							(data) => {
								this.setObjectList(data.results)
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

			console.log('Deleting object...')

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

			console.log('Saving object...')

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

			return { response, data }
		},
		// AUDIT TRAILS
		async getAuditTrails(id) {
			if (!id) {
				throw new Error('No object id to get audit trails for')
			}

			const endpoint = `/index.php/apps/openregister/api/audit-trails/${id}`

			const response = await fetch(endpoint, {
				method: 'GET',
			})

			const responseData = await response.json()
			const data = responseData.map((auditTrail) => new AuditTrail(auditTrail))

			this.setAuditTrails(data)

			return { response, data }
		},
	},
})
