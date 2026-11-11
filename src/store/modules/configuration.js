import { defineStore } from 'pinia'
import { ConfigurationEntity } from '../../entities/index.js'
import {
	answerOrError,
	getJson,
	importAnswer,
	itemTarget,
	loggedRequest,
	objectAnswer,
	okJson,
	sendJson,
	withoutReadOnly,
} from '../../services/listPage.js'
import { listStateActions, listStateFields } from './listState.js'

/**
 * The URL of one configuration.
 *
 * @param {object} configurationItem The configuration.
 * @return {string} The URL.
 * @spec exclude Client-side URL of the configurations API passthrough.
 */
function configurationUrl(configurationItem) {
	return `/index.php/apps/openregister/api/configurations/${configurationItem.id}`
}

/**
 * The form an import sends: the file, and whether to import its objects.
 *
 * @param {File} file The file.
 * @param {boolean} includeObjects Whether to import the objects too.
 * @return {FormData} The form.
 * @spec exclude Client-side form of POST /api/configurations/import.
 */
function importForm(file, includeObjects) {
	const formData = new FormData()
	formData.append('file', file)
	formData.append('includeObjects', includeObjects ? '1' : '0')
	return formData
}

/**
 * The discover URL of a source, with the search term when there is one.
 *
 * @param {string} source The source.
 * @param {string} search The search term.
 * @return {string} The URL.
 * @spec exclude Client-side URL of GET /api/configurations/discover.
 */
function discoverUrl(source, search) {
	const params = new URLSearchParams()
	params.append('source', source)
	if (search) params.append('_search', search)
	return `/index.php/apps/openregister/api/configurations/discover?${params}`
}

export const useConfigurationStore = defineStore('configuration', {
	state: () => ({
		configurationItem: false,
		configurationList: [],
		...listStateFields(),
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
		...listStateActions(14),
		/**
		 * Refresh the configuration list from the API
		 *
		 * @param {string|null} search - Optional search term
		 * @return {Promise} Promise with response and data
		 *
		 * @spec exclude Thin API passthrough — GET /api/configurations list; observable contract owned by data-import-export.
		 */
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
		/**
		 * @param id
		 * @spec exclude Thin API passthrough — GET /api/configurations/{id}; observable contract owned by data-import-export.
		 */
		async getConfiguration(id) {
			const endpoint = `/index.php/apps/openregister/api/configurations/${id}`
			try {
				const data = await getJson(endpoint)
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
			return loggedRequest({
				failure: 'Failed to delete configuration',
				log: (error) =>
					console.error('Error deleting configuration:', error),
				run: async () => {
					const response = await fetch(
						configurationUrl(configurationItem),
						{
							method: 'DELETE',
						},
					)
					if (!response.ok) {
						throw new Error(`HTTP error! status: ${response.status}`)
					}
					this.refreshConfigurationList()
					this.setConfigurationItem(null)
					return { response }
				},
			})
		},

		/**
		 * @param configurationItem
		 * @spec exclude Thin API passthrough — POST/PUT /api/configurations; observable contract owned by data-import-export.
		 */
		async saveConfiguration(configurationItem) {
			if (!configurationItem) {
				throw new Error('No configuration item to save')
			}
			return this._sendConfiguration({
				...itemTarget(
					'/index.php/apps/openregister/api/configurations',
					configurationItem.id ? configurationItem : null,
				),
				// Clean the data before sending - remove read-only fields
				payload: this.cleanConfigurationForSave(configurationItem),
				action: 'save',
				logText: 'Error saving configuration:',
			})
		},

		// Clean configuration data for saving - remove read-only fields
		/**
		 * @param configurationItem
		 * @spec exclude Client-side payload sanitiser — strips read-only fields before save. No standalone backend contract.
		 */
		cleanConfigurationForSave(configurationItem) {
			return withoutReadOnly(configurationItem)
		},
		/**
		 * @param configuration
		 * @spec exclude Thin API passthrough — POST/PUT /api/configurations/upload; observable contract owned by data-import-export.
		 */
		async uploadConfiguration(configuration) {
			if (!configuration) {
				throw new Error('No configuration item to upload')
			}
			return this._sendConfiguration({
				...itemTarget(
					'/index.php/apps/openregister/api/configurations/upload',
					this.configurationItem,
				),
				payload: configuration,
				action: 'upload',
				logText: 'Error uploading configuration:',
			})
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
			const body = importForm(file, includeObjects)
			return loggedRequest({
				log: (error) =>
					console.error('Error importing configuration:', error),
				run: async () => {
					const response = await fetch(
						'/index.php/apps/openregister/api/configurations/import',
						{ method: 'POST', body },
					)
					const responseData = await importAnswer(response)
					await this.refreshConfigurationList()
					return { response, responseData }
				},
			})
		},

		/**
		 * @param source
		 * @param search
		 * @spec exclude Thin API passthrough — GET /api/configurations/discover; observable contract owned by data-import-export.
		 */
		async discoverConfigurations(source, search = '') {
			const url = discoverUrl(source, search)
			return loggedRequest({
				log: (error) =>
					console.error('Error discovering configurations:', error),
				run: async () => {
					const data = await answerOrError(
						await fetch(url, { method: 'GET' }),
					)
					return data.results || []
				},
			})
		},

		/**
		 * @param source
		 * @param params
		 * @spec exclude Thin API passthrough — GET /api/configurations/{source}/branches; observable contract owned by data-import-export.
		 */
		async getBranches(source, params) {
			return this._fetchSourceList({
				source,
				params,
				path: 'branches',
				logText: 'Error fetching branches:',
			})
		},
		/**
		 * @param source
		 * @param params
		 * @spec exclude Thin API passthrough — GET /api/configurations/{source}/files; observable contract owned by data-import-export.
		 */
		async getConfigurationFiles(source, params) {
			return this._fetchSourceList({
				source,
				params,
				path: 'files',
				logText: 'Error fetching configuration files:',
			})
		},
		/**
		 * @param params
		 * @spec exclude Thin API passthrough — POST /api/configurations/import/github; observable contract owned by data-import-export.
		 */
		async importFromGitHub(params) {
			return this._importFromSource({
				kind: 'github',
				params,
				logText: 'Error importing from GitHub:',
			})
		},
		/**
		 * @param params
		 * @spec exclude Thin API passthrough — POST /api/configurations/import/gitlab; observable contract owned by data-import-export.
		 */
		async importFromGitLab(params) {
			return this._importFromSource({
				kind: 'gitlab',
				params,
				logText: 'Error importing from GitLab:',
			})
		},
		/**
		 * @param params
		 * @spec exclude Thin API passthrough — POST /api/configurations/import/url; observable contract owned by data-import-export.
		 */
		async importFromUrl(params) {
			return this._importFromSource({
				kind: 'url',
				params,
				logText: 'Error importing from URL:',
			})
		},
		/**
		 * Send a configuration as JSON and make the answer the active item.
		 *
		 * @param {{endpoint: string, method: string, payload: object, action: string, logText: string}} request
		 *     The endpoint and method, the configuration to send, the verb used
		 *     in the thrown message and the text logged on failure.
		 * @return {Promise<object>} The response and the configuration entity.
		 * @spec exclude private step shared by saveConfiguration and uploadConfiguration
		 */
		async _sendConfiguration(request) {
			return loggedRequest({
				failure: `Failed to ${request.action} configuration`,
				log: (error) => console.error(request.logText, error),
				run: async () => {
					const response = await sendJson(request.endpoint, {
						method: request.method,
						body: request.payload,
					})
					const data = new ConfigurationEntity(
						await objectAnswer(response),
					)
					this.setConfigurationItem(data)
					this.refreshConfigurationList()
					return { response, data }
				},
			})
		},

		/**
		 * List what a configuration source offers (branches or files).
		 *
		 * @param {{source: string, params: object, path: string, logText: string}} request
		 *     The source, the query parameters, the list endpoint (also the
		 *     answer field) and the text logged on failure.
		 * @return {Promise<Array>} The list.
		 * @spec exclude private step shared by getBranches and getConfigurationFiles
		 */
		async _fetchSourceList(request) {
			const endpoint = `/index.php/apps/openregister/api/configurations/${request.source}/${request.path}`
			const query = new URLSearchParams(request.params)

			try {
				const response = await fetch(`${endpoint}?${query}`, {
					method: 'GET',
				})

				const data = await okJson(response)
				return data[request.path] || []
			} catch (error) {
				console.error(request.logText, error)
				throw error
			}
		},
		/**
		 * Import a configuration from a source, then refresh the list.
		 *
		 * @param {{kind: string, params: object, logText: string}} request The
		 *     import endpoint, the import parameters and the text logged on failure.
		 * @return {Promise<object>} The import answer.
		 * @spec exclude private step shared by importFromGitHub, importFromGitLab and importFromUrl
		 */
		async _importFromSource(request) {
			return loggedRequest({
				log: (error) => console.error(request.logText, error),
				run: async () => {
					const response = await sendJson(
						`/index.php/apps/openregister/api/configurations/import/${request.kind}`,
						{ method: 'POST', body: request.params },
					)
					const data = await answerOrError(response)
					await this.refreshConfigurationList()
					return data
				},
			})
		},
	},
})
