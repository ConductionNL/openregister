/* eslint-disable no-console */
import { defineStore } from 'pinia'
import { Organisation } from '../../entities/index.js'

export const useOrganisationStore = defineStore('organisation', {
	state: () => ({
		organisationItem: false,
		organisationList: [],
		activeOrganisation: null,
		userStats: {
			total: 0,
			active: null,
			list: [],
		},
		viewMode: 'cards',
		filters: [], // List of query
		pagination: {
			page: 1,
			limit: 20,
		},
	}),
	getters: {
		getViewMode: (state) => state.viewMode,
		getActiveOrganisation: (state) => state.activeOrganisation,
		getUserOrganisations: (state) => state.userStats.list,
	},
	actions: {
		setViewMode(mode) {
			this.viewMode = mode
			console.log('View mode set to:', mode)
		},
		setOrganisationItem(organisationItem) {
			this.organisationItem = organisationItem && new Organisation(organisationItem)
			console.log('Active organisation item set to ' + (organisationItem?.name || 'null'))
		},
		setOrganisationList(organisations) {
			this.organisationList = organisations.map(organisation => new Organisation(organisation))
			console.log('Organisation list set to ' + organisations.length + ' items')
		},
		setActiveOrganisation(activeOrganisation) {
			this.activeOrganisation = activeOrganisation && new Organisation(activeOrganisation)
			console.log('Active organisation set to ' + (activeOrganisation?.name || 'null'))
		},
		setUserStats(stats) {
			this.userStats = {
				total: stats.total || 0,
				active: stats.active ? new Organisation(stats.active) : null,
				list: (stats.list || []).map(org => new Organisation(org)),
			}
			console.log('User organisation stats set:', this.userStats)
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
		 * Set query filters for organisation list
		 * @param {object} filters - The filter criteria to apply to the organisation list
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
			console.info('Query filters set to', this.filters)
		},
		/* istanbul ignore next */ // ignore this for Jest until moved into a service
		async refreshOrganisationList() {
			const endpoint = '/index.php/apps/openregister/api/organisations'
			const response = await fetch(endpoint, {
				method: 'GET',
			})

			const data = await response.json()
			this.setUserStats(data)

			return { response, data }
		},
		// Get active organisation
		async getActiveOrganisation() {
			const endpoint = '/index.php/apps/openregister/api/organisations/active'
			try {
				const response = await fetch(endpoint, {
					method: 'GET',
				})
				const data = await response.json()

				if (data.activeOrganisation) {
					this.setActiveOrganisation(data.activeOrganisation)
				}

				return data.activeOrganisation
			} catch (err) {
				console.error(err)
				throw err
			}
		},
		// Function to get a single organisation
		async getOrganisation(uuid, options = { setItem: false }) {
			const endpoint = `/index.php/apps/openregister/api/organisations/${uuid}`
			try {
				const response = await fetch(endpoint, {
					method: 'GET',
				})
				const data = await response.json()

				if (data.organisation) {
					options.setItem && this.setOrganisationItem(data.organisation)
					return data.organisation
				}
				return data
			} catch (err) {
				console.error(err)
				throw err
			}
		},
		// Set active organisation
		async setActiveOrganisationById(uuid) {
			const endpoint = `/index.php/apps/openregister/api/organisations/${uuid}/set-active`
			try {
				const response = await fetch(endpoint, {
					method: 'POST',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				if (data.activeOrganisation) {
					this.setActiveOrganisation(data.activeOrganisation)
				}

				// Refresh organisation list to update stats
				await this.refreshOrganisationList()

				return { response, data }
			} catch (error) {
				console.error('Error setting active organisation:', error)
				throw new Error(`Failed to set active organisation: ${error.message}`)
			}
		},
		// Join an organisation
		async joinOrganisation(uuid) {
			const endpoint = `/index.php/apps/openregister/api/organisations/${uuid}/join`
			try {
				const response = await fetch(endpoint, {
					method: 'POST',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				// Refresh organisation list to show new membership
				await this.refreshOrganisationList()

				return { response, data }
			} catch (error) {
				console.error('Error joining organisation:', error)
				throw new Error(`Failed to join organisation: ${error.message}`)
			}
		},
		// Leave an organisation
		async leaveOrganisation(uuid) {
			const endpoint = `/index.php/apps/openregister/api/organisations/${uuid}/leave`
			try {
				const response = await fetch(endpoint, {
					method: 'POST',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				// Refresh organisation list and active organisation
				await this.refreshOrganisationList()
				await this.getActiveOrganisation()

				return { response, data }
			} catch (error) {
				console.error('Error leaving organisation:', error)
				throw new Error(`Failed to leave organisation: ${error.message}`)
			}
		},
		// Delete an organisation (owner only)
		async deleteOrganisation(organisationItem) {
			if (!organisationItem.uuid) {
				throw new Error('No organisation UUID to delete')
			}

			console.log('Deleting organisation...')

			// Note: Delete endpoint would need to be implemented in backend
			const endpoint = `/index.php/apps/openregister/api/organisations/${organisationItem.uuid}`

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

				await this.refreshOrganisationList()
				await this.getActiveOrganisation()
				this.setOrganisationItem(null)

				return { response, data: responseData }
			} catch (error) {
				console.error('Error deleting organisation:', error)
				throw new Error(`Failed to delete organisation: ${error.message}`)
			}
		},
		// Create or save an organisation from store
		async saveOrganisation(organisationItem) {
			if (!organisationItem) {
				throw new Error('No organisation item to save')
			}

			console.log('Saving organisation...')

			const isNewOrganisation = !organisationItem?.uuid
			const endpoint = isNewOrganisation
				? '/index.php/apps/openregister/api/organisations'
				: `/index.php/apps/openregister/api/organisations/${organisationItem.uuid}`
			const method = isNewOrganisation ? 'POST' : 'PUT'

			// Clean the organisation data before sending
			const cleanedOrganisation = this.cleanOrganisationForSave(organisationItem)

			const response = await fetch(
				endpoint,
				{
					method,
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(cleanedOrganisation),
				},
			)

			if (!response.ok) {
				throw new Error(`HTTP error! status: ${response.status}`)
			}

			const responseData = await response.json()

			if (!responseData || typeof responseData !== 'object') {
				throw new Error('Invalid response data')
			}

			// Handle both create and update response formats
			const organisationData = responseData.organisation || responseData

			const data = new Organisation(organisationData)

			this.setOrganisationItem(data)
			this.refreshOrganisationList()

			// If this is the user's first/only organisation, set it as active
			if (isNewOrganisation) {
				await this.getActiveOrganisation()
			}

			return { response, data }
		},
		// Clean organisation data for saving - remove read-only fields
		cleanOrganisationForSave(organisationItem) {
			const cleaned = { ...organisationItem }

			// Remove read-only/calculated fields that should not be sent to the server
			delete cleaned.id
			delete cleaned.uuid
			delete cleaned.users
			delete cleaned.userCount
			delete cleaned.created
			delete cleaned.updated

			return cleaned
		},
		// Search organisations by name
		async searchOrganisations(query = '') {
			if (!query.trim()) {
				return []
			}

			const endpoint = `/index.php/apps/openregister/api/organisations/search?query=${encodeURIComponent(query.trim())}`

			try {
				const response = await fetch(endpoint, {
					method: 'GET',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				return data.organisations || []
			} catch (error) {
				console.error('Error searching organisations:', error)
				throw new Error(`Failed to search organisations: ${error.message}`)
			}
		},
		// Clear organisation cache
		async clearCache() {
			const endpoint = '/index.php/apps/openregister/api/organisations/clear-cache'

			try {
				const response = await fetch(endpoint, {
					method: 'POST',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				// Refresh data after clearing cache
				await this.refreshOrganisationList()
				await this.getActiveOrganisation()

				return { response, data }
			} catch (error) {
				console.error('Error clearing cache:', error)
				throw new Error(`Failed to clear cache: ${error.message}`)
			}
		},
	},
})
