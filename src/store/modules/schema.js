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
		deleteSchema() {
			if (!this.schemaItem || !this.schemaItem.id) {
				throw new Error('No schema item to delete')
			}

			console.log('Deleting schema...')

			const endpoint = `/index.php/apps/openregister/api/schemas/${this.schemaItem.id}`

			return fetch(endpoint, {
				method: 'DELETE',
			})
				.then((response) => {
					this.refreshSchemaList()
				})
				.catch((err) => {
					console.error('Error deleting schema:', err)
					throw err
				})
		},
		// Create or save a schema from store
		saveSchema() {
			if (!this.schemaItem) {
				throw new Error('No schema item to save')
			}

			console.log('Saving schema...')

			const isNewSchema = !this.schemaItem.id
			const endpoint = isNewSchema
				? '/index.php/apps/openregister/api/schemas'
				: `/index.php/apps/openregister/api/schemas/${this.schemaItem.id}`
			const method = isNewSchema ? 'POST' : 'PUT'

			// Create a copy of the schema item and remove empty properties
			const schemaToSave = { ...this.schemaItem }
			Object.keys(schemaToSave).forEach(key => {
				if (schemaToSave[key] === '' || (Array.isArray(schemaToSave[key]) && schemaToSave[key].length === 0)) {
					delete schemaToSave[key]
				}
			})

			return fetch(
				endpoint,
				{
					method,
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(schemaToSave),
				},
			)
				.then((response) => response.json())
				.then((data) => {
					this.setSchemaItem(data)
					console.log('Schema saved')
					// Refresh the schema list
					return this.refreshSchemaList()
				})
				.catch((err) => {
					console.error('Error saving schema:', err)
					throw err
				})
		},
	},
})
