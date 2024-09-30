/* eslint-disable no-console */
import { defineStore } from 'pinia'
import { Schema } from '../../entities/index.js'

export const useSchemaStore = defineStore('schema', {
	state: () => ({
		schemaItem: false,
		schemaList: [],
	}),
	actions: {
		setSchemaItem(schemaItem) {
			this.schemaItem = schemaItem && new Schema(schemaItem)
			console.log('Active schema item set to ' + schemaItem)
		},
		setSchemaList(schemaList) {
			this.schemaList = schemaList.map(
				(schemaItem) => new Schema(schemaItem),
			)
			console.log('Schema list set to ' + schemaList.length + ' items')
		},
		/* istanbul ignore next */ // ignore this for Jest until moved into a service
		async refreshSchemaList(search = null) {
			// @todo this might belong in a service?
			let endpoint = '/index.php/apps/openregister/api/schemas'
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
								this.setSchemaList(data.results)
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
		// Function to get a single schema
		async getSchema(id) {
			const endpoint = `/index.php/apps/openregister/api/schemas/${id}`
			try {
				const response = await fetch(endpoint, {
					method: 'GET',
				})
				const data = await response.json()
				this.setSchemaItem(data)
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

			try {
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
			} catch (error) {
				console.error('Error saving schema:', error)
				throw new Error(`Failed to save schema: ${error.message}`)
			}
		},
	},
})
