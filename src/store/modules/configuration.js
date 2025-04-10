/* eslint-disable no-console */
import { defineStore } from 'pinia'
import { ConfigurationEntity } from '../../entities/index.js'

export const useConfigurationStore = defineStore('configuration', {
	state: () => ({
		configurationItem: false,
		configurationList: [],
		filters: [], // List of query
		pagination: {
			page: 1,
			limit: 20,
		},
	}),
	actions: {
		setConfigurationItem(configurationItem) {
			this.configurationItem = configurationItem ? new ConfigurationEntity(configurationItem) : null
			console.log('Active configuration item set to ' + (configurationItem?.title || 'null'))
		},
		setConfigurationList(configurationList) {
			this.configurationList = configurationList.map(
				(configurationItem) => new ConfigurationEntity(configurationItem),
			)
			console.log('Configuration list set to ' + configurationList.length + ' items')
		},
		/**
		 * Set pagination details
		 * @param {number} page - The current page number for pagination
		 * @param {number} limit - The number of items to display per page
		 */
		setPagination(page, limit = 14) {
			this.pagination = { page, limit }
			console.info('Pagination set to', { page, limit })
		},
		/**
		 * Set query filters for configuration list
		 * @param {object} filters - The filter criteria to apply to the configuration list
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
			console.info('Query filters set to', this.filters)
		},
		/* istanbul ignore next */ // ignore this for Jest until moved into a service
		async refreshConfigurationList(search = null) {
			let endpoint = '/index.php/apps/openregister/api/configurations'
			if (search !== null && search !== '') {
				endpoint = endpoint + '?_search=' + search
			}
			const response = await fetch(endpoint, {
				method: 'GET',
			})

			const data = (await response.json()).results

			this.setConfigurationList(data)

			return { response, data }
		},
		async getConfiguration(id) {
			const endpoint = `/index.php/apps/openregister/api/configurations/${id}`
			try {
				const response = await fetch(endpoint, {
					method: 'GET',
				})
				const data = await response.json()
				this.setConfigurationItem(data)
				return data
			} catch (err) {
				console.error(err)
				throw err
			}
		},
		async deleteConfiguration(configurationItem) {
			if (!configurationItem.id) {
				throw new Error('No configuration item to delete')
			}

			console.log('Deleting configuration...')

			const endpoint = `/index.php/apps/openregister/api/configurations/${configurationItem.id}`

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

				this.refreshConfigurationList()
				this.setConfigurationItem(null)

				return { response, data: responseData }
			} catch (error) {
				console.error('Error deleting configuration:', error)
				throw new Error(`Failed to delete configuration: ${error.message}`)
			}
		},
		async saveConfiguration(configurationItem) {
			if (!configurationItem) {
				throw new Error('No configuration item to save')
			}

			console.log('Saving configuration...')

			const isNewConfiguration = !configurationItem.id
			const endpoint = isNewConfiguration
				? '/index.php/apps/openregister/api/configurations'
				: `/index.php/apps/openregister/api/configurations/${configurationItem.id}`
			const method = isNewConfiguration ? 'POST' : 'PUT'

			// change updated to current date as a singular iso date string
			configurationItem.updated = new Date().toISOString()

			try {
				const response = await fetch(
					endpoint,
					{
						method,
						headers: {
							'Content-Type': 'application/json',
						},
						body: JSON.stringify(configurationItem),
					},
				)

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const responseData = await response.json()

				if (!responseData || typeof responseData !== 'object') {
					throw new Error('Invalid response data')
				}

				const data = new ConfigurationEntity(responseData)

				this.setConfigurationItem(data)
				this.refreshConfigurationList()

				return { response, data }
			} catch (error) {
				console.error('Error saving configuration:', error)
				throw new Error(`Failed to save configuration: ${error.message}`)
			}
		},
		async uploadConfiguration(configuration) {
			if (!configuration) {
				throw new Error('No configuration item to upload')
			}

			console.log('Uploading configuration...')

			const isNewConfiguration = !this.configurationItem
			const endpoint = isNewConfiguration
				? '/index.php/apps/openregister/api/configurations/upload'
				: `/index.php/apps/openregister/api/configurations/upload/${this.configurationItem.id}`
			const method = isNewConfiguration ? 'POST' : 'PUT'

			try {
				const response = await fetch(
					endpoint,
					{
						method,
						headers: {
							'Content-Type': 'application/json',
						},
						body: JSON.stringify(configuration),
					},
				)

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const responseData = await response.json()

				if (!responseData || typeof responseData !== 'object') {
					throw new Error('Invalid response data')
				}

				const data = new ConfigurationEntity(responseData)

				this.setConfigurationItem(data)
				this.refreshConfigurationList()

				return { response, data }
			} catch (error) {
				console.error('Error uploading configuration:', error)
				throw new Error(`Failed to upload configuration: ${error.message}`)
			}
		},
	},
}) 