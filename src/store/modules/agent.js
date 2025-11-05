/**
 * Agent Store Module
 *
 * @category Store
 * @package  openregister
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2
 * @version  1.0.0
 * @link     https://www.openregister.nl
 */

/* eslint-disable no-console */
import { defineStore } from 'pinia'
import { Agent } from '../../entities/index.js'

export const useAgentStore = defineStore('agent', {
	state: () => ({
		agentItem: null,
		agentList: [],
		loading: false,
		error: null,
		viewMode: 'cards',
		filters: [],
		pagination: {
			page: 1,
			limit: 20,
		},
	}),
	getters: {
		getAgentItem: (state) => state.agentItem,
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
		getViewMode: (state) => state.viewMode,
	},
	actions: {
		/**
		 * Set the view mode (cards or table)
		 *
		 * @param {string} mode - The view mode
		 */
		setViewMode(mode) {
			this.viewMode = mode
			console.log('View mode set to:', mode)
		},
		/**
		 * Set the current agent item
		 *
		 * @param {object|null} agentItem - The agent item to set
		 */
		setAgentItem(agentItem) {
			try {
				this.loading = true
				this.error = null
				this.agentItem = agentItem ? new Agent(agentItem) : null
				console.log('Active agent item set to ' + (agentItem?.name || 'null'))
			} catch (error) {
				console.error('Error setting agent item:', error)
				this.error = error.message
			} finally {
				this.loading = false
			}
		},
		/**
		 * Set the agent list
		 *
		 * @param {array} agentList - Array of agent objects
		 */
		setAgentList(agentList) {
			this.agentList = agentList.map(
				(agentItem) => new Agent(agentItem),
			)
			console.log('Agent list set to ' + agentList.length + ' items')
		},
		/**
		 * Set pagination details
		 *
		 * @param {number} page - The current page number for pagination
		 * @param {number} limit - The number of items to display per page
		 */
		setPagination(page, limit = 20) {
			this.pagination = { page, limit }
			console.info('Pagination set to', { page, limit })
		},
		/**
		 * Set query filters for agent list
		 *
		 * @param {object} filters - The filter criteria to apply to the agent list
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
			console.info('Query filters set to', this.filters)
		},
		/**
		 * Refresh the agent list from the API
		 *
		 * @param {string|null} search - Optional search term
		 * @param {boolean} soft - If true, don't show loading state (default: false)
		 * @returns {Promise} Promise with response and data
		 */
		/* istanbul ignore next */
		async refreshAgentList(search = null, soft = false) {
			console.log('AgentStore: Starting refreshAgentList (soft=' + soft + ')')
			
			// Only set loading state for hard reloads
			if (!soft) {
				this.loading = true
			}
			this.error = null
			
			try {
				let endpoint = '/index.php/apps/openregister/api/agents'
				if (search !== null && search !== '') {
					endpoint = endpoint + '?_search=' + encodeURIComponent(search)
				}
				
				const response = await fetch(endpoint, {
					method: 'GET',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = (await response.json()).results

				this.setAgentList(data)
				console.log('AgentStore: refreshAgentList completed, got', data.length, 'agents')

				return { response, data }
			} catch (error) {
				console.error('Error fetching agents:', error)
				this.error = error.message
				throw error
			} finally {
				if (!soft) {
					this.loading = false
				}
			}
		},
		/**
		 * Get a single agent by ID
		 *
		 * @param {number} id - Agent ID
		 * @returns {Promise} Promise with agent data
		 */
		async getAgent(id) {
			const endpoint = `/index.php/apps/openregister/api/agents/${id}`
			try {
				this.loading = true
				const response = await fetch(endpoint, {
					method: 'GET',
				})
				
				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}
				
				const data = await response.json()
				this.setAgentItem(data)
				return data
			} catch (err) {
				console.error(err)
				this.error = err.message
				throw err
			} finally {
				this.loading = false
			}
		},
		/**
		 * Delete an agent
		 *
		 * @param {object} agentItem - The agent to delete
		 * @returns {Promise} Promise with response
		 */
		async deleteAgent(agentItem) {
			if (!agentItem.id) {
				throw new Error('No agent to delete')
			}

			console.log('Deleting agent...')
			this.loading = true

			const endpoint = `/index.php/apps/openregister/api/agents/${agentItem.id}`

			try {
				const response = await fetch(endpoint, {
					method: 'DELETE',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				await this.refreshAgentList()
				this.setAgentItem(null)

				return { response }
			} catch (error) {
				console.error('Error deleting agent:', error)
				this.error = error.message
				throw new Error(`Failed to delete agent: ${error.message}`)
			} finally {
				this.loading = false
			}
		},
		/**
		 * Save (create or update) an agent
		 *
		 * @param {object} agentItem - The agent to save
		 * @returns {Promise} Promise with response and data
		 */
		async saveAgent(agentItem) {
			if (!agentItem) {
				throw new Error('No agent to save')
			}

			console.log('Saving agent...')
			this.loading = true

			const isNewAgent = !agentItem.id
			const endpoint = isNewAgent
				? '/index.php/apps/openregister/api/agents'
				: `/index.php/apps/openregister/api/agents/${agentItem.id}`
			const method = isNewAgent ? 'POST' : 'PUT'

			try {
				const response = await fetch(
					endpoint,
					{
						method,
						headers: {
							'Content-Type': 'application/json',
						},
						body: JSON.stringify(agentItem),
					},
				)

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const responseData = await response.json()
				const data = new Agent(responseData)

				this.setAgentItem(data)
				await this.refreshAgentList()

				return { response, data }
			} catch (error) {
				console.error('Error saving agent:', error)
				this.error = error.message
				throw new Error(`Failed to save agent: ${error.message}`)
			} finally {
				this.loading = false
			}
		},
		/**
		 * Get agent statistics
		 *
		 * @returns {Promise} Promise with statistics data
		 */
		async getStats() {
			const endpoint = '/index.php/apps/openregister/api/agents/stats'
			try {
				const response = await fetch(endpoint, {
					method: 'GET',
				})
				
				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}
				
				return await response.json()
			} catch (err) {
				console.error('Error fetching agent stats:', err)
				throw err
			}
		},
	},
})


