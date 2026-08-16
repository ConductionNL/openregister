import { defineStore } from 'pinia'
import { Application } from '../../entities/index.js'

export const useApplicationStore = defineStore('application', {
	state: () => ({
		applicationItem: null,
		applicationList: [],
		nextcloudGroups: [], // Cached Nextcloud groups for application access control
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
		getApplicationItem: (state) => state.applicationItem,
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
		getViewMode: (state) => state.viewMode,
	},
	actions: {
		/**
		 * @param mode
		 * @spec exclude Pure client UI-state setter — list/card view-mode toggle. No backend contract.
		 */
		setViewMode(mode) {
			this.viewMode = mode
		},
		/**
		 * @param applicationItem
		 * @spec exclude Client state mutator — wraps the active application in an entity. No backend contract.
		 */
		setApplicationItem(applicationItem) {
			try {
				this.loading = true
				this.error = null
				this.applicationItem = applicationItem
					? new Application(applicationItem)
					: null
			} catch (error) {
				console.error('Error setting application item:', error)
				this.error = error.message
			} finally {
				this.loading = false
			}
		},
		/**
		 * @param applicationList
		 * @spec exclude Client state mutator — maps the application list to entities. No backend contract.
		 */
		setApplicationList(applicationList) {
			this.applicationList = applicationList.map(
				(applicationItem) => new Application(applicationItem),
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
		setPagination(page, limit = 20) {
			this.pagination = { page, limit }
		},
		/**
		 * Set query filters for application list
		 *
		 * @param {object} filters - The filter criteria to apply to the application list
		 *
		 * @spec exclude Pure client UI-state setter — list filter criteria. No backend contract.
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
		},
		/**
		 * Refresh the application list from the API
		 *
		 * @param {string|null} search - Optional search term
		 * @param {boolean} soft - If true, don't show loading state (default: false)
		 * @return {Promise} Promise with response and data
		 *
		 * @spec exclude Thin API passthrough — GET /api/applications list; observable contract owned by the applications backend capability.
		 */
		/* istanbul ignore next */
		async refreshApplicationList(search = null, soft = false) {
			// Only set loading state for hard reloads
			if (!soft) {
				this.loading = true
			}
			this.error = null

			try {
				let endpoint = '/index.php/apps/openregister/api/applications'
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

				this.setApplicationList(data)

				return { response, data }
			} catch (error) {
				console.error('Error fetching applications:', error)
				this.error = error.message
				throw error
			} finally {
				if (!soft) {
					this.loading = false
				}
			}
		},
		/**
		 * @param id
		 * @spec exclude Thin API passthrough — GET /api/applications/{id}; observable contract owned by the applications backend capability.
		 */
		async getApplication(id) {
			const endpoint = `/index.php/apps/openregister/api/applications/${id}`
			try {
				this.loading = true
				const response = await fetch(endpoint, {
					method: 'GET',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()
				this.setApplicationItem(data)
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
		 * @param applicationItem
		 * @spec exclude Thin API passthrough — DELETE /api/applications/{id}; observable contract owned by the applications backend capability.
		 */
		async deleteApplication(applicationItem) {
			if (!applicationItem.id) {
				throw new Error('No application to delete')
			}

			this.loading = true

			const endpoint = `/index.php/apps/openregister/api/applications/${applicationItem.id}`

			try {
				const response = await fetch(endpoint, {
					method: 'DELETE',
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				await this.refreshApplicationList()
				this.setApplicationItem(null)

				return { response }
			} catch (error) {
				console.error('Error deleting application:', error)
				this.error = error.message
				throw new Error(`Failed to delete application: ${error.message}`, {
					cause: error,
				})
			} finally {
				this.loading = false
			}
		},
		/**
		 * @param applicationItem
		 * @spec exclude Thin API passthrough — POST/PUT /api/applications; observable contract owned by the applications backend capability.
		 */
		async saveApplication(applicationItem) {
			if (!applicationItem) {
				throw new Error('No application to save')
			}

			this.loading = true

			const isNewApplication = !applicationItem.id
			const endpoint = isNewApplication
				? '/index.php/apps/openregister/api/applications'
				: `/index.php/apps/openregister/api/applications/${applicationItem.id}`
			const method = isNewApplication ? 'POST' : 'PUT'

			// Clean the data before sending - remove read-only fields
			const cleanedData = this.cleanApplicationForSave(applicationItem)

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
				const data = new Application(responseData)

				this.setApplicationItem(data)
				await this.refreshApplicationList()

				return { response, data }
			} catch (error) {
				console.error('Error saving application:', error)
				this.error = error.message
				throw new Error(`Failed to save application: ${error.message}`, {
					cause: error,
				})
			} finally {
				this.loading = false
			}
		},
		// Clean application data for saving - remove read-only fields
		/**
		 * @param applicationItem
		 * @spec exclude Client-side payload sanitiser — strips read-only fields before save. No standalone backend contract.
		 */
		cleanApplicationForSave(applicationItem) {
			const cleaned = { ...applicationItem }

			// Remove read-only/calculated fields that should not be sent to the server
			delete cleaned.id
			delete cleaned.uuid
			delete cleaned.usage // Usage is calculated by backend, not set by frontend
			delete cleaned.owner
			delete cleaned.created
			delete cleaned.updated

			// Ensure boolean fields are actually booleans, not empty strings
			if (cleaned.active !== undefined) {
				cleaned.active =
					cleaned.active === '' ? true : Boolean(cleaned.active)
			}

			return cleaned
		},
		/**
		 * Load and cache Nextcloud groups for application access control
		 * This should be called on the applications index page to preload groups
		 *
		 * @return {Promise<void>}
		 *
		 * @spec exclude Thin passthrough to the Nextcloud OCS groups API; observable contract owned by Nextcloud core, not OpenRegister.
		 */
		async loadNextcloudGroups() {
			try {
				// Fetch groups from Nextcloud OCS API (using v1 for compatibility)
				const response = await fetch(
					'/ocs/v1.php/cloud/groups?format=json',
					{
						headers: {
							'OCS-APIRequest': 'true',
						},
					},
				)

				if (response.ok) {
					const data = await response.json()
					if (data.ocs?.data?.groups) {
						// Transform group IDs into objects with additional info
						this.nextcloudGroups = data.ocs.data.groups.map(
							(groupId) => ({
								id: groupId,
								name: groupId,
								userCount: 0, // Could be fetched separately if needed
							}),
						)
					}
				}
			} catch (error) {
				console.error('Error loading Nextcloud groups:', error)
			}
		},
	},
})
