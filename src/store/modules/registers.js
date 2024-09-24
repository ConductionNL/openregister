/* eslint-disable no-console */
import { defineStore } from 'pinia'
import { Register } from '../../entities/index.js'

export const useRegisterStore = defineStore(
	'register', {
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
				let endpoint = '/index.php/apps/openconnector/api/registers'
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
				const endpoint = `/index.php/apps/openconnector/api/registers/${id}`
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
			deleteRegister() {
				if (!this.registerItem || !this.registerItem.id) {
					throw new Error('No register item to delete')
				}

				console.log('Deleting register...')

				const endpoint = `/index.php/apps/openconnector/api/registers/${this.registerItem.id}`

				return fetch(endpoint, {
					method: 'DELETE',
				})
					.then((response) => {
						this.refreshRegisterList()
					})
					.catch((err) => {
						console.error('Error deleting register:', err)
						throw err
					})
			},
			// Create or save a register from store
			saveRegister() {
				if (!this.registerItem) {
					throw new Error('No register item to save')
				}

				console.log('Saving register...')

				const isNewRegister = !this.registerItem.id
				const endpoint = isNewRegister
					? '/index.php/apps/openconnector/api/registers'
					: `/index.php/apps/openconnector/api/registers/${this.registerItem.id}`
				const method = isNewRegister ? 'POST' : 'PUT'

				// Create a copy of the register item and remove empty properties
				const registerToSave = { ...this.registerItem }
				Object.keys(registerToSave).forEach(key => {
					if (registerToSave[key] === '' || (Array.isArray(registerToSave[key]) && registerToSave[key].length === 0)) {
						delete registerToSave[key]
					}
				})

				return fetch(
					endpoint,
					{
						method,
						headers: {
							'Content-Type': 'application/json',
						},
						body: JSON.stringify(registerToSave),
					},
				)
					.then((response) => response.json())
					.then((data) => {
						this.setRegisterItem(data)
						console.log('Register saved')
						// Refresh the register list
						return this.refreshRegisterList()
					})
					.catch((err) => {
						console.error('Error saving register:', err)
						throw err
					})
			},
		},
	},
)
