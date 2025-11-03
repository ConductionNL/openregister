/* eslint-disable no-console */
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
		setViewMode(mode) {
			this.viewMode = mode
			console.log('View mode set to:', mode)
		},
		setApplicationItem(applicationItem) {
			try {
				this.loading = true
				this.error = null
				this.applicationItem = applicationItem ? new Application(applicationItem) : null
				console.log('Active application item set to ' + (applicationItem?.name || 'null'))
			} catch (error) {
				console.error('Error setting application item:', error)
				this.error = error.message
			} finally {
				this.loading = false
			}
		},
		setApplicationList(applicationList) {
			this.applicationList = applicationList.map(
				(applicationItem) => new Application(applicationItem),
			)
			console.log('Application list set to ' + applicationList.length + ' items')
		},
		/**
		 * Set pagination details
		 * @param {number} page - The current page number for pagination
		 * @param {number} limit - The number of items to display per page
		 */
		setPagination(page, limit = 20) {
			this.pagination = { page, limit }
			console.info('Pagination set to', { page, limit })
		},
		/**
		 * Set query filters for application list
		 * @param {object} filters - The filter criteria to apply to the application list
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
			console.info('Query filters set to', this.filters)
		},
		/* istanbul ignore next */
		async refreshApplicationList(search = null) {
			console.log('ApplicationStore: Starting refreshApplicationList')
			this.loading = true
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
				console.log('ApplicationStore: refreshApplicationList completed, got', data.length, 'applications')

				return { response, data }
			} catch (error) {
				console.error('Error fetching applications:', error)
				this.error = error.message
				throw error
			} finally {
				this.loading = false
			}
		},
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
		async deleteApplication(applicationItem) {
			if (!applicationItem.id) {
				throw new Error('No application to delete')
			}

			console.log('Deleting application...')
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
				throw new Error(`Failed to delete application: ${error.message}`)
			} finally {
				this.loading = false
			}
		},
		async saveApplication(applicationItem) {
			if (!applicationItem) {
				throw new Error('No application to save')
			}

			console.log('Saving application...')
			this.loading = true

			const isNewApplication = !applicationItem.id
			const endpoint = isNewApplication
				? '/index.php/apps/openregister/api/applications'
				: `/index.php/apps/openregister/api/applications/${applicationItem.id}`
			const method = isNewApplication ? 'POST' : 'PUT'

			// Clean the data before sending - remove read-only fields
			const cleanedData = this.cleanApplicationForSave(applicationItem)

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
				const data = new Application(responseData)

				this.setApplicationItem(data)
				await this.refreshApplicationList()

				return { response, data }
			} catch (error) {
				console.error('Error saving application:', error)
				this.error = error.message
				throw new Error(`Failed to save application: ${error.message}`)
			} finally {
				this.loading = false
			}
		},
		// Clean application data for saving - remove read-only fields
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
				cleaned.active = cleaned.active === '' ? true : Boolean(cleaned.active)
			}

			return cleaned
		},
		/**
		 * Load and cache Nextcloud groups for application access control
		 * This should be called on the applications index page to preload groups
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
						console.log('Loaded', this.nextcloudGroups.length, 'Nextcloud groups into application store')
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
