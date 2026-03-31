/* eslint-disable no-console */
import { createCrudStore } from '@conduction/nextcloud-vue'
import { ConfigurationEntity } from '../../entities/index.js'

export const useConfigurationStore = createCrudStore('configuration', {
	endpoint: 'configurations',
	entity: ConfigurationEntity,
	extend: {
		actions: {
			async uploadConfiguration(configuration) {
				if (!configuration) {
					throw new Error('No configuration item to upload')
				}
				console.log('Uploading configuration...')
				const isNew = !this.item
				const endpoint = isNew
					? this._options.baseApiUrl + '/upload'
					: `${this._options.baseApiUrl}/upload/${this.item.id}`
				const method = isNew ? 'POST' : 'PUT'
				try {
					const response = await fetch(endpoint, {
						method,
						headers: { 'Content-Type': 'application/json' },
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
					this.setItem(data)
					this.refreshList()
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
				const endpoint = this._options.baseApiUrl + '/import'
				const formData = new FormData()
				formData.append('file', file)
				formData.append('includeObjects', includeObjects ? '1' : '0')
				try {
					const response = await fetch(endpoint, { method: 'POST', body: formData })
					const responseData = await response.json()
					if (!response.ok) {
						if (responseData && responseData.error) {
							throw new Error(responseData.error)
						}
						throw new Error(`HTTP error! status: ${response.status}`)
					}
					if (!responseData || typeof responseData !== 'object') {
						throw new Error('Invalid response data')
					}
					await this.refreshList()
					return { response, responseData }
				} catch (error) {
					console.error('Error importing configuration:', error)
					throw error
				}
			},
			async discoverConfigurations(source, search = '') {
				console.log(`ConfigurationStore: Discovering configurations on ${source}`)
				const params = new URLSearchParams()
				params.append('source', source)
				if (search) params.append('_search', search)
				try {
					const response = await fetch(`${this._options.baseApiUrl}/discover?${params}`, { method: 'GET' })
					const data = await response.json()
					if (!response.ok) {
						const errorMessage = data.error || `HTTP error! status: ${response.status}`
						throw new Error(errorMessage)
					}
					return data.results || []
				} catch (error) {
					console.error('Error discovering configurations:', error)
					throw error
				}
			},
			async getBranches(source, params) {
				console.log(`ConfigurationStore: Fetching branches from ${source}`)
				const query = new URLSearchParams(params)
				try {
					const response = await fetch(`${this._options.baseApiUrl}/${source}/branches?${query}`, { method: 'GET' })
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
				const query = new URLSearchParams(params)
				try {
					const response = await fetch(`${this._options.baseApiUrl}/${source}/files?${query}`, { method: 'GET' })
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
				try {
					const response = await fetch(this._options.baseApiUrl + '/import/github', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify(params),
					})
					if (!response.ok) {
						const errorData = await response.json()
						throw new Error(errorData.error || `HTTP error! status: ${response.status}`)
					}
					const data = await response.json()
					await this.refreshList()
					return data
				} catch (error) {
					console.error('Error importing from GitHub:', error)
					throw error
				}
			},
			async importFromGitLab(params) {
				console.log('ConfigurationStore: Importing from GitLab')
				try {
					const response = await fetch(this._options.baseApiUrl + '/import/gitlab', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify(params),
					})
					if (!response.ok) {
						const errorData = await response.json()
						throw new Error(errorData.error || `HTTP error! status: ${response.status}`)
					}
					const data = await response.json()
					await this.refreshList()
					return data
				} catch (error) {
					console.error('Error importing from GitLab:', error)
					throw error
				}
			},
			async importFromUrl(params) {
				console.log('ConfigurationStore: Importing from URL')
				try {
					const response = await fetch(this._options.baseApiUrl + '/import/url', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify(params),
					})
					if (!response.ok) {
						const errorData = await response.json()
						throw new Error(errorData.error || `HTTP error! status: ${response.status}`)
					}
					const data = await response.json()
					await this.refreshList()
					return data
				} catch (error) {
					console.error('Error importing from URL:', error)
					throw error
				}
			},
		},
	},
})
