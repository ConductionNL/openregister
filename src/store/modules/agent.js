/* eslint-disable no-console */
import { createCrudStore } from '@conduction/nextcloud-vue'
import { Agent } from '../../entities/index.js'

export const useAgentStore = createCrudStore('agent', {
	endpoint: 'agents',
	entity: Agent,
	features: { loading: true, viewMode: true },
	parseListResponse(json) {
		// API sometimes returns array directly instead of { results: [] }
		return Array.isArray(json) ? json : (json.results || [])
	},
	extend: {
		actions: {
			/**
			 * Get agent statistics
			 * @return {Promise} Promise with statistics data
			 */
			async getStats() {
				const response = await fetch(this._options.baseApiUrl + '/stats', {
					method: 'GET',
				})
				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}
				return await response.json()
			},
		},
	},
})
