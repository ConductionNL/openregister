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
		/**
		 * Refresh the configuration list from the API
		 *
		 * @param {string|null} search - Optional search term
		 * @param {boolean} soft - If true, don't show loading state (default: false)
		 * @return {Promise} Promise with response and data
		 */
		/* istanbul ignore next */ // ignore this for Jest until moved into a service
		async refreshConfigurationList(search = null, soft = false) {
			console.log('ConfigurationStore: Starting refreshConfigurationList (soft=' + soft + ')')
			// Note: ConfigurationStore doesn't have a loading state, but we log for consistency

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

			// Clean the data before sending - remove read-only fields
			const cleanedData = this.cleanConfigurationForSave(configurationItem)

			try {
				const response = await fetch(
					endpoint,
					{
						method,
						headers: {
							'Content-Type': 'application/json',
						},
						body: JSON.stringify(cleanedData),
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
		// Clean configuration data for saving - remove read-only fields
		cleanConfigurationForSave(configurationItem) {
			const cleaned = { ...configurationItem }

			// Remove read-only/calculated fields that should not be sent to the server
			delete cleaned.id
			delete cleaned.uuid
			delete cleaned.created
			delete cleaned.updated

			return cleaned
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
		async importConfiguration(file, includeObjects = false) {
			if (!file) {
				throw new Error('No file to import')
			}

			console.log('Importing configuration...')

			const endpoint = '/index.php/apps/openregister/api/configurations/import'
			const formData = new FormData()
			formData.append('file', file)
			formData.append('includeObjects', includeObjects ? '1' : '0')

			try {
				const response = await fetch(
					endpoint,
					{
						method: 'POST',
						body: formData,
					},
				)

				const responseData = await response.json()

				if (!response.ok) {
					// If we have an error message in the response, use that
					if (responseData && responseData.error) {
						throw new Error(responseData.error)
					}
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				if (!responseData || typeof responseData !== 'object') {
					throw new Error('Invalid response data')
				}

				await this.refreshConfigurationList()

				return { response, responseData }
			} catch (error) {
				console.error('Error importing configuration:', error)
				throw error // Pass through the original error message
			}
		},
		async discoverConfigurations(source, query = '') {
			console.log(`ConfigurationStore: Discovering configurations on ${source}`)
			const endpoint = `/index.php/apps/openregister/api/configurations/discover/${source}`
			const params = new URLSearchParams()
			if (query) params.append('query', query)

			try {
				const response = await fetch(`${endpoint}?${params}`, {
					method: 'GET',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				return data.items || []
			} catch (error) {
				console.error('Error discovering configurations:', error)
				throw error
			}
		},
		async getBranches(source, params) {
			console.log(`ConfigurationStore: Fetching branches from ${source}`)
			const endpoint = `/index.php/apps/openregister/api/configurations/${source}/branches`
			const query = new URLSearchParams(params)

			try {
				const response = await fetch(`${endpoint}?${query}`, {
					method: 'GET',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				return data.branches || []
			} catch (error) {
				console.error('Error fetching branches:', error)
				throw error
			}
		},
		async getConfigurationFiles(source, params) {
			console.log(`ConfigurationStore: Fetching configuration files from ${source}`)
			const endpoint = `/index.php/apps/openregister/api/configurations/${source}/files`
			const query = new URLSearchParams(params)

			try {
				const response = await fetch(`${endpoint}?${query}`, {
					method: 'GET',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				return data.files || []
			} catch (error) {
				console.error('Error fetching configuration files:', error)
				throw error
			}
		},
		async importFromGitHub(params) {
			console.log('ConfigurationStore: Importing from GitHub')
			const endpoint = '/index.php/apps/openregister/api/configurations/import/github'

			try {
				const response = await fetch(endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(params),
				})

				if (!response.ok) {
					const errorData = await response.json()
					throw new Error(errorData.error || `HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				await this.refreshConfigurationList()
				return data
			} catch (error) {
				console.error('Error importing from GitHub:', error)
				throw error
			}
		},
		async importFromGitLab(params) {
			console.log('ConfigurationStore: Importing from GitLab')
			const endpoint = '/index.php/apps/openregister/api/configurations/import/gitlab'

			try {
				const response = await fetch(endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(params),
				})

				if (!response.ok) {
					const errorData = await response.json()
					throw new Error(errorData.error || `HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				await this.refreshConfigurationList()
				return data
			} catch (error) {
				console.error('Error importing from GitLab:', error)
				throw error
			}
		},
		async importFromUrl(params) {
			console.log('ConfigurationStore: Importing from URL')
			const endpoint = '/index.php/apps/openregister/api/configurations/import/url'

			try {
				const response = await fetch(endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(params),
				})

				if (!response.ok) {
					const errorData = await response.json()
					throw new Error(errorData.error || `HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				await this.refreshConfigurationList()
				return data
			} catch (error) {
				console.error('Error importing from URL:', error)
				throw error
			}
		},
	},
})
