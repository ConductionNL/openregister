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
		/**
		 * @param configurationItem
		 * @spec exclude Client state mutator — wraps the active configuration in an entity. No backend contract.
		 */
		setConfigurationItem(configurationItem) {
			this.configurationItem = configurationItem
				? new ConfigurationEntity(configurationItem)
				: null
		},
		/**
		 * @param configurationList
		 * @spec exclude Client state mutator — maps the configuration list to entities. No backend contract.
		 */
		setConfigurationList(configurationList) {
			this.configurationList = configurationList.map(
				(configurationItem) => new ConfigurationEntity(configurationItem),
			)
		},
		/**
		 * Set pagination details
		 *
		 * @param {number} page - The current page number for pagination
		 * @param {number} limit - The number of items to display per page
		 *
		 * @spec exclude Pure client UI-state setter — list pagination cursor. No backend contract.
		 */
		setPagination(page, limit = 14) {
			this.pagination = { page, limit }
		},
		/**
		 * Set query filters for configuration list
		 *
		 * @param {object} filters - The filter criteria to apply to the configuration list
		 *
		 * @spec exclude Pure client UI-state setter — list filter criteria. No backend contract.
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
		},
		/**
		 * Refresh the configuration list from the API
		 *
		 * @param {string|null} search - Optional search term
		 * @param {boolean} _soft - If true, don't show loading state (default: false)
		 * @return {Promise} Promise with response and data
		 *
		 * @spec exclude Thin API passthrough — GET /api/configurations list; observable contract owned by data-import-export.
		 */
		/* istanbul ignore next */ // ignore this for Jest until moved into a service
		async refreshConfigurationList(search = null, _soft = false) {
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
		/**
		 * @param id
		 * @spec exclude Thin API passthrough — GET /api/configurations/{id}; observable contract owned by data-import-export.
		 */
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
		/**
		 * @param configurationItem
		 * @spec exclude Thin API passthrough — DELETE /api/configurations/{id}; observable contract owned by data-import-export.
		 */
		async deleteConfiguration(configurationItem) {
			if (!configurationItem.id) {
				throw new Error('No configuration item to delete')
			}

			const endpoint = `/index.php/apps/openregister/api/configurations/${configurationItem.id}`

			try {
				const response = await fetch(endpoint, {
					method: 'DELETE',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				this.refreshConfigurationList()
				this.setConfigurationItem(null)

				return { response }
			} catch (error) {
				console.error('Error deleting configuration:', error)
				throw new Error(`Failed to delete configuration: ${error.message}`)
			}
		},
		/**
		 * @param configurationItem
		 * @spec exclude Thin API passthrough — POST/PUT /api/configurations; observable contract owned by data-import-export.
		 */
		async saveConfiguration(configurationItem) {
			if (!configurationItem) {
				throw new Error('No configuration item to save')
			}

			const isNewConfiguration = !configurationItem.id
			const endpoint = isNewConfiguration
				? '/index.php/apps/openregister/api/configurations'
				: `/index.php/apps/openregister/api/configurations/${configurationItem.id}`
			const method = isNewConfiguration ? 'POST' : 'PUT'

			// Clean the data before sending - remove read-only fields
			const cleanedData = this.cleanConfigurationForSave(configurationItem)

			try {
				const response = await fetch(endpoint, {
					method,
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(cleanedData),
				})

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
		/**
		 * @param configurationItem
		 * @spec exclude Client-side payload sanitiser — strips read-only fields before save. No standalone backend contract.
		 */
		cleanConfigurationForSave(configurationItem) {
			const cleaned = { ...configurationItem }

			// Remove read-only/calculated fields that should not be sent to the server
			delete cleaned.id
			delete cleaned.uuid
			delete cleaned.created
			delete cleaned.updated

			return cleaned
		},
		/**
		 * @param configuration
		 * @spec exclude Thin API passthrough — POST/PUT /api/configurations/upload; observable contract owned by data-import-export.
		 */
		async uploadConfiguration(configuration) {
			if (!configuration) {
				throw new Error('No configuration item to upload')
			}

			const isNewConfiguration = !this.configurationItem
			const endpoint = isNewConfiguration
				? '/index.php/apps/openregister/api/configurations/upload'
				: `/index.php/apps/openregister/api/configurations/upload/${this.configurationItem.id}`
			const method = isNewConfiguration ? 'POST' : 'PUT'

			try {
				const response = await fetch(endpoint, {
					method,
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(configuration),
				})

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
		/**
		 * @param file
		 * @param includeObjects
		 * @spec exclude Thin API passthrough — POST /api/configurations/import; observable contract owned by data-import-export.
		 */
		async importConfiguration(file, includeObjects = false) {
			if (!file) {
				throw new Error('No file to import')
			}

			const endpoint = '/index.php/apps/openregister/api/configurations/import'
			const formData = new FormData()
			formData.append('file', file)
			formData.append('includeObjects', includeObjects ? '1' : '0')

			try {
				const response = await fetch(endpoint, {
					method: 'POST',
					body: formData,
				})

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
		/**
		 * @param source
		 * @param search
		 * @spec exclude Thin API passthrough — GET /api/configurations/discover; observable contract owned by data-import-export.
		 */
		async discoverConfigurations(source, search = '') {
			const endpoint =
				'/index.php/apps/openregister/api/configurations/discover'
			const params = new URLSearchParams()
			params.append('source', source)
			if (search) params.append('_search', search)

			try {
				const response = await fetch(`${endpoint}?${params}`, {
					method: 'GET',
				})

				// Parse the JSON response first to extract error messages
				const data = await response.json()

				if (!response.ok) {
					// If backend returns an error message, use it
					const errorMessage =
						data.error || `HTTP error! status: ${response.status}`
					throw new Error(errorMessage)
				}

				return data.results || []
			} catch (error) {
				console.error('Error discovering configurations:', error)
				// Re-throw with the error message (which now contains backend's user-friendly message)
				throw error
			}
		},
		/**
		 * @param source
		 * @param params
		 * @spec exclude Thin API passthrough — GET /api/configurations/{source}/branches; observable contract owned by data-import-export.
		 */
		async getBranches(source, params) {
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
		/**
		 * @param source
		 * @param params
		 * @spec exclude Thin API passthrough — GET /api/configurations/{source}/files; observable contract owned by data-import-export.
		 */
		async getConfigurationFiles(source, params) {
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
		/**
		 * @param params
		 * @spec exclude Thin API passthrough — POST /api/configurations/import/github; observable contract owned by data-import-export.
		 */
		async importFromGitHub(params) {
			const endpoint =
				'/index.php/apps/openregister/api/configurations/import/github'

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
					throw new Error(
						errorData.error || `HTTP error! status: ${response.status}`,
					)
				}

				const data = await response.json()
				await this.refreshConfigurationList()
				return data
			} catch (error) {
				console.error('Error importing from GitHub:', error)
				throw error
			}
		},
		/**
		 * @param params
		 * @spec exclude Thin API passthrough — POST /api/configurations/import/gitlab; observable contract owned by data-import-export.
		 */
		async importFromGitLab(params) {
			const endpoint =
				'/index.php/apps/openregister/api/configurations/import/gitlab'

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
					throw new Error(
						errorData.error || `HTTP error! status: ${response.status}`,
					)
				}

				const data = await response.json()
				await this.refreshConfigurationList()
				return data
			} catch (error) {
				console.error('Error importing from GitLab:', error)
				throw error
			}
		},
		/**
		 * @param params
		 * @spec exclude Thin API passthrough — POST /api/configurations/import/url; observable contract owned by data-import-export.
		 */
		async importFromUrl(params) {
			const endpoint =
				'/index.php/apps/openregister/api/configurations/import/url'

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
					throw new Error(
						errorData.error || `HTTP error! status: ${response.status}`,
					)
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
