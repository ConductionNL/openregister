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
		nextcloudGroups: [], // Cached Nextcloud groups for organisation access control
		viewMode: 'cards',
		filters: [], // List of query
		pagination: {
			page: 1,
			limit: 20,
		},
	}),
	getters: {
		getViewMode: (state) => state.viewMode,
		activeOrganisationGetter: (state) => state.activeOrganisation,
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
				list: (stats.results || []).map(org => new Organisation(org)),
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

			// Also populate the organisationList for use in dropdowns
			if (data.results) {
				this.setOrganisationList(data.results)
			}

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
		/**
		 * Join an organisation with optional user selection
		 *
		 * @param {string} uuid - Organisation UUID to join
		 * @param {string|null} userId - Optional user ID to join the organisation. If null, current user joins.
		 * @return {Promise<{response: Response, data: object}>} API response
		 */
		async joinOrganisation(uuid, userId = null) {
			const endpoint = `/index.php/apps/openregister/api/organisations/${uuid}/join`
			try {
				// Prepare request body with optional userId
				const requestBody = userId ? { userId } : {}

				const response = await fetch(endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(requestBody),
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

			// Create the organisation item from the organisation
			const uuid = organisationItem.uuid || organisationItem.id

			const response = await fetch(
				`/index.php/apps/openregister/api/organisations/${uuid}`,
				{
					method: 'DELETE',
				},
			)

			this.refreshOrganisationList()

			return { response }
		},
		// Create a new organisation
		async createOrganisation(organisationData) {
			console.log('Creating organisation...', organisationData)

			const endpoint = '/index.php/apps/openregister/api/organisations'

			try {
				const response = await fetch(endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(organisationData),
				})

				if (!response.ok) {
					const errorData = await response.json()
					throw new Error(errorData.message || `HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				const savedOrganisation = data.organisation || data

				// Update local state
				this.setOrganisationItem(savedOrganisation)

				// Refresh the full list to get updated stats
				await this.refreshOrganisationList()

				console.log('Organisation created successfully:', savedOrganisation)
				return { response, data: savedOrganisation }
			} catch (error) {
				console.error('Error creating organisation:', error)
				throw new Error(`Failed to create organisation: ${error.message}`)
			}
		},

		// Update an existing organisation
		async updateOrganisation(organisationData) {
			console.log('Updating organisation...', organisationData)

			if (!organisationData.id && !organisationData.uuid) {
				throw new Error('Organisation UUID is required for updates')
			}

			// API expects UUID, not ID
			const organisationId = organisationData.uuid || organisationData.id
			const endpoint = `/index.php/apps/openregister/api/organisations/${organisationId}`

			// Clean the data before sending - remove read-only fields
			const cleanedData = this.cleanOrganisationForSave(organisationData)

			try {
				const response = await fetch(endpoint, {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(cleanedData),
				})

				if (!response.ok) {
					const errorData = await response.json()
					throw new Error(errorData.message || `HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				const savedOrganisation = data.organisation || data

				// Update existing in list
				const index = this.organisationList.findIndex(org =>
					org.uuid === organisationId || org.id === organisationId,
				)
				if (index !== -1) {
					this.organisationList[index] = new Organisation(savedOrganisation)
				}

				// Update current item if it's the same organisation
				if (this.organisationItem && (this.organisationItem.uuid === organisationId || this.organisationItem.id === organisationId)) {
					this.setOrganisationItem(savedOrganisation)
				}

				// Refresh the full list to get updated stats
				await this.refreshOrganisationList()

				console.log('Organisation updated successfully:', savedOrganisation)
				return { response, data: savedOrganisation }
			} catch (error) {
				console.error('Error updating organisation:', error)
				throw new Error(`Failed to update organisation: ${error.message}`)
			}
		},

		// Create or update an organisation (legacy method for backward compatibility)
		async saveOrganisation(organisationItem) {
			const isNewOrganisation = !organisationItem.uuid && !organisationItem.id

			if (isNewOrganisation) {
				return await this.createOrganisation(organisationItem)
			} else {
				return await this.updateOrganisation(organisationItem)
			}
		},
	// Clean organisation data for saving - remove read-only fields
	cleanOrganisationForSave(organisationItem) {
		const cleaned = { ...organisationItem }

		// Remove read-only/calculated fields that should not be sent to the server
		delete cleaned.id
		delete cleaned.uuid
		delete cleaned.users
		delete cleaned.userCount
		delete cleaned.groupCount
		delete cleaned.usage // Usage is calculated by backend, not set by frontend
		delete cleaned.owner
		delete cleaned.created
		delete cleaned.updated

		// Remove empty slug to avoid database errors
		if (cleaned.slug === '' || cleaned.slug === null) {
			delete cleaned.slug
		}

		// Remove isDefault as it's now managed via config, not database
		delete cleaned.isDefault

		// Ensure boolean fields are actually booleans, not empty strings
		if (cleaned.active !== undefined) {
			cleaned.active = cleaned.active === '' ? true : Boolean(cleaned.active)
		}

		return cleaned
	},
		/**
		 * Search organisations by name with pagination support
		 *
		 * @param {string} query - Search query (empty returns all)
		 * @param {number} limit - Maximum number of results to return
		 * @param {number} offset - Number of results to skip
		 * @return {Promise<Array>} List of organisations
		 */
		async searchOrganisations(query = '', limit = 50, offset = 0) {
			// Build query parameters
			const params = new URLSearchParams()
			if (query.trim()) {
				params.append('query', query.trim())
			}
			params.append('_limit', limit)
			params.append('_offset', offset)

			const endpoint = `/index.php/apps/openregister/api/organisations/search?${params.toString()}`

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
		// Clear cache
		async clearCache() {
			const endpoint = '/index.php/apps/openregister/api/organisations/clear-cache'
			try {
				const response = await fetch(endpoint, {
					method: 'POST',
				})

				const data = await response.json()
				console.log('Organisation cache cleared:', data.message)

				// Refresh organisation data after cache clear
				await this.refreshOrganisationList()

				return { response, data }
			} catch (error) {
				console.error('Error clearing cache:', error)
				throw new Error(`Failed to clear cache: ${error.message}`)
			}
		},
		/**
		 * Load and cache Nextcloud groups for organisation access control
		 * This should be called on the organisations index page to preload groups
		 *
		 * @return {Promise<void>}
		 */
		async loadNextcloudGroups() {
			try {
				// Fetch groups from Nextcloud OCS API (using v1 for compatibility)
				const response = await fetch('/ocs/v1.php/cloud/groups?format=json', {
					headers: {
						'OCS-APIRequest': 'true',
					},
				})

				if (response.ok) {
					const data = await response.json()
					if (data.ocs?.data?.groups) {
						// Transform group IDs into objects with additional info
						this.nextcloudGroups = data.ocs.data.groups.map(groupId => ({
							id: groupId,
							name: groupId,
							userCount: 0, // Could be fetched separately if needed
						}))
						console.log('Loaded', this.nextcloudGroups.length, 'Nextcloud groups into organisation store')
					}
				} else {
					console.warn('Failed to load Nextcloud groups:', response.statusText)
				}
			} catch (error) {
				console.error('Error loading Nextcloud groups:', error)
			}
		},
	},
})
