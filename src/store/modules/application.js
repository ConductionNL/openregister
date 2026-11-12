import { defineStore } from 'pinia'
import { Application } from '../../entities/index.js'
import { loadNextcloudGroupsInto } from '../../services/groupSearch.js'
import { okJson, sendJson } from '../../services/listPage.js'
import { itemRequest } from './itemRequest.js'
import { listStateActions } from './listState.js'

/**
 * The applications list URL, with the search term when there is one.
 *
 * @param {string|null} search The search term.
 * @return {string} The URL.
 * @spec exclude Client-side URL of the applications API passthrough.
 */
function applicationsUrl(search) {
	const endpoint = '/index.php/apps/openregister/api/applications'
	return search !== null && search !== ''
		? endpoint + '?_search=' + encodeURIComponent(search)
		: endpoint
}

/**
 * The URL of one application, or of the list for a new one.
 *
 * @param {object} applicationItem The application.
 * @return {string} The URL.
 * @spec exclude Client-side URL of the applications API passthrough.
 */
function applicationUrl(applicationItem) {
	return applicationItem.id
		? `/index.php/apps/openregister/api/applications/${applicationItem.id}`
		: '/index.php/apps/openregister/api/applications'
}

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
		...listStateActions(20),
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
			return itemRequest(this, {
				clearsError: true,
				soft,
				log: (error) => console.error('Error fetching applications:', error),
				run: async () => {
					const response = await fetch(applicationsUrl(search), {
						method: 'GET',
					})
					const data = (await okJson(response)).results
					this.setApplicationList(data)
					return { response, data }
				},
			})
		},

		/**
		 * @param id
		 * @spec exclude Thin API passthrough — GET /api/applications/{id}; observable contract owned by the applications backend capability.
		 */
		async getApplication(id) {
			return itemRequest(this, {
				log: (err) => console.error(err),
				run: async () => {
					const response = await fetch(applicationUrl({ id }), {
						method: 'GET',
					})
					const data = await okJson(response)
					this.setApplicationItem(data)
					return data
				},
			})
		},

		/**
		 * @param applicationItem
		 * @spec exclude Thin API passthrough — DELETE /api/applications/{id}; observable contract owned by the applications backend capability.
		 */
		async deleteApplication(applicationItem) {
			if (!applicationItem.id) {
				throw new Error('No application to delete')
			}
			return itemRequest(this, {
				failure: 'Failed to delete application',
				log: (error) => console.error('Error deleting application:', error),
				run: async () => {
					const response = await fetch(applicationUrl(applicationItem), {
						method: 'DELETE',
					})
					if (!response.ok) {
						throw new Error(`HTTP error! status: ${response.status}`)
					}
					await this.refreshApplicationList()
					this.setApplicationItem(null)
					return { response }
				},
			})
		},

		/**
		 * @param applicationItem
		 * @spec exclude Thin API passthrough — POST/PUT /api/applications; observable contract owned by the applications backend capability.
		 */
		async saveApplication(applicationItem) {
			if (!applicationItem) {
				throw new Error('No application to save')
			}
			return itemRequest(this, {
				failure: 'Failed to save application',
				log: (error) => console.error('Error saving application:', error),
				run: async () => {
					const response = await sendJson(
						applicationUrl(applicationItem),
						{
							method: applicationItem.id ? 'PUT' : 'POST',
							body: this.cleanApplicationForSave(applicationItem),
						},
					)
					const data = new Application(await okJson(response))
					this.setApplicationItem(data)
					await this.refreshApplicationList()
					return { response, data }
				},
			})
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
			return loadNextcloudGroupsInto(this)
		},
	},
})
