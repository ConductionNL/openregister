/* eslint-disable no-console */
import { defineStore } from 'pinia'
import { Register } from '../../entities/index.js'

export const useRegisterStore = defineStore('register', {
	state: () => ({
		registerItem: false,
		registerList: [],
		filters: [], // List of query
		pagination: {
			page: 1,
			limit: 20,
		},
	}),
	actions: {
		setRegisterItem(registerItem) {
			this.registerItem = registerItem ? new Register(registerItem) : null
			console.log('Active register item set to ' + (registerItem?.title || 'null'))
		},
		setRegisterList(registerList) {
			this.registerList = registerList.map(
				(registerItem) => new Register(registerItem),
			)
			console.log('Register list set to ' + registerList.length + ' items')
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
		 * Set query filters for register list
		 * @param {object} filters - The filter criteria to apply to the register list
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
			console.info('Query filters set to', this.filters) // Logging the filters
		},
		/* istanbul ignore next */ // ignore this for Jest until moved into a service
		async refreshRegisterList(search = null) {
			// @todo this might belong in a service?
			let endpoint = '/index.php/apps/openregister/api/registers'
			if (search !== null && search !== '') {
				endpoint = endpoint + '?_search=' + search
			}
			const response = await fetch(endpoint, {
				method: 'GET',
			})

			const data = (await response.json()).results

			this.setRegisterList(data)

			return { response, data }
		},
		// New function to get a single register
		async getRegister(id) {
			const endpoint = `/index.php/apps/openregister/api/registers/${id}`
			try {
				const response = await fetch(endpoint, {
					method: 'GET',
				})
				const data = await response.json()
				this.setRegisterItem(data)
				return data
			} catch (err) {
				console.error(err)
				throw err
			}
		},
		// Delete a register
		async deleteRegister(registerItem) {
			if (!registerItem.id) {
				throw new Error('No register item to delete')
			}

			console.log('Deleting register...')

			const endpoint = `/index.php/apps/openregister/api/registers/${registerItem.id}`

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

				this.refreshRegisterList()
				this.setRegisterItem(null)

				return { response, data: responseData }
			} catch (error) {
				console.error('Error deleting register:', error)
				throw new Error(`Failed to delete register: ${error.message}`)
			}
		},
		// Create or save a register from store
		async saveRegister(registerItem) {
			if (!registerItem) {
				throw new Error('No register item to save')
			}

			console.log('Saving register...')

			const isNewRegister = !registerItem.id
			const endpoint = isNewRegister
				? '/index.php/apps/openregister/api/registers'
				: `/index.php/apps/openregister/api/registers/${registerItem.id}`
			const method = isNewRegister ? 'POST' : 'PUT'

			// change updated to current date as a singular iso date string
			registerItem.updated = new Date().toISOString()

			try {
				const response = await fetch(
					endpoint,
					{
						method,
						headers: {
							'Content-Type': 'application/json',
						},
						body: JSON.stringify(registerItem),
					},
				)

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const responseData = await response.json()

				if (!responseData || typeof responseData !== 'object') {
					throw new Error('Invalid response data')
				}

				const data = new Register(responseData)

				this.setRegisterItem(data)
				this.refreshRegisterList()

				return { response, data }
			} catch (error) {
				console.error('Error saving register:', error)
				throw new Error(`Failed to save register: ${error.message}`)
			}
		},
		// Create or save a register from store
		async uploadRegister(register) {
			if (!register) {
				throw new Error('No register item to upload')
			}

			console.log('Uploading register...')

			const isNewRegister = !this.registerItem
			const endpoint = isNewRegister
				? '/index.php/apps/openregister/api/registers/upload'
				: `/index.php/apps/openregister/api/registers/upload/${this.registerItem.id}`
			const method = isNewRegister ? 'POST' : 'PUT'

			const response = await fetch(
				endpoint,
				{
					method,
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(register),
				},
			)

			if (!response.ok) {
				throw new Error(`HTTP error! status: ${response.status}`)
			}

			const responseData = await response.json()

			if (!responseData || typeof responseData !== 'object') {
				throw new Error('Invalid response data')
			}

			const data = new Register(responseData)

			this.setRegisterItem(data)
			this.refreshRegisterList()

			return { response, data }

		},
		async importRegister(file) {
			if (!file) {
				throw new Error('No file to import')
			}

			console.log('Importing register...')

			const endpoint = '/index.php/apps/openregister/api/registers/import'
			const formData = new FormData()
			formData.append('file', file)

			try {
				const response = await fetch(
					endpoint,
					{
						method: 'POST',
						body: formData,
					},
				)

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const responseData = await response.json()

				if (!responseData || typeof responseData !== 'object') {
					throw new Error('Invalid response data')
				}

				await this.refreshRegisterList()

				return { response, responseData }
			} catch (error) {
				console.error('Error importing register:', error)
				throw new Error(`Failed to import register: ${error.message}`)
			}
		},
	},
})
