/* eslint-disable no-console */
import { defineStore } from 'pinia'
import { Register } from '../../entities/index.js'

export const useRegisterStore = defineStore('register', {
	state: () => ({
		registerItem: false,
		registerList: [],
	}),
	actions: {
		setRegisterItem(registerItem) {
			this.registerItem = registerItem && new Register(registerItem)
			console.log('Active register item set to ' + registerItem)
		},
		setRegisterList(registerList) {
			this.registerList = registerList.map(
				(registerItem) => new Register(registerItem),
			)
			console.log('Register list set to ' + registerList.length + ' items')
		},
		/* istanbul ignore next */ // ignore this for Jest until moved into a service
		async refreshRegisterList(search = null) {
			// @todo this might belong in a service?
			let endpoint = '/index.php/apps/openregister/api/registers'
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
								this.setRegisterList(data.results)
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
				await this.refreshRegisterList()

				return { response, data }
			} catch (error) {
				console.error('Error saving register:', error)
				throw new Error(`Failed to save register: ${error.message}`)
			}
		},
	},
})
