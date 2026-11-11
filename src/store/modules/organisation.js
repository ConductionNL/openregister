import { defineStore } from 'pinia'
import { Organisation } from '../../entities/index.js'
import { loadNextcloudGroupsInto } from '../../services/groupSearch.js'
import { listStateActions } from './listState.js'

const ORGANISATIONS = '/index.php/apps/openregister/api/organisations'

/**
 * Whether an organisation is the one a uuid or id names.
 *
 * @param {object} organisation The organisation.
 * @param {string|number} organisationId Its uuid or id.
 * @return {boolean} True when either matches.
 */
function isOrganisation(organisation, organisationId) {
	return organisation.uuid === organisationId || organisation.id === organisationId
}

/**
 * Save an organisation and read the answer.
 *
 * @param {string|number} organisationId The organisation's uuid, else its id.
 * @param {object} body The fields to save.
 * @return {Promise<{response: Response, data: object}>} The answer.
 * @throws {Error} The answer's message, else the HTTP status.
 */
function putOrganisation(organisationId, body) {
	return fetchJsonOrExplain(
		`${ORGANISATIONS}/${organisationId}`,
		jsonRequest('PUT', body),
	)
}

/**
 * A request that sends a body as JSON.
 *
 * @param {string} method The HTTP method.
 * @param {object} body The body.
 * @return {object} The fetch options.
 */
function jsonRequest(method, body) {
	return {
		method,
		headers: {
			'Content-Type': 'application/json',
		},
		body: JSON.stringify(body),
	}
}

/**
 * Send a request and read its JSON answer, whatever its status.
 *
 * @param {string} endpoint The URL.
 * @param {object} init The fetch options.
 * @return {Promise<{response: Response, data: object}>} The answer.
 */
async function readJson(endpoint, init) {
	const response = await fetch(endpoint, init)
	return { response, data: await response.json() }
}

/**
 * The search query of an organisation search: the trimmed text, when any.
 *
 * @param {string} query The text typed.
 * @return {URLSearchParams} The query parameters.
 */
function organisationQuery(query) {
	const params = new URLSearchParams()
	if (query.trim()) {
		params.append('query', query.trim())
	}
	return params
}

/**
 * The id an organisation is updated under: its uuid, else its id.
 *
 * @param {object} organisationData The organisation.
 * @return {string|number} The uuid or id.
 * @throws {Error} When it has neither.
 */
function organisationIdOf(organisationData) {
	if (!organisationData.id && !organisationData.uuid) {
		throw new Error('Organisation UUID is required for updates')
	}

	// API expects UUID, not ID
	return organisationData.uuid || organisationData.id
}

/**
 * Send a request and read its JSON answer; a refusal throws its status.
 *
 * @param {string} endpoint The URL.
 * @param {object} init The fetch options.
 * @return {Promise<{response: Response, data: object}>} The answer.
 * @throws {Error} The HTTP status.
 */
async function fetchJson(endpoint, init) {
	const response = await fetch(endpoint, init)
	if (!response.ok) {
		throw new Error(`HTTP error! status: ${response.status}`)
	}
	return { response, data: await response.json() }
}

/**
 * Send a request and read its JSON answer; a refusal throws the answer's
 * message, else its status.
 *
 * @param {string} endpoint The URL.
 * @param {object} init The fetch options.
 * @return {Promise<{response: Response, data: object}>} The answer.
 * @throws {Error} The answer's message, else the HTTP status.
 */
async function fetchJsonOrExplain(endpoint, init) {
	const response = await fetch(endpoint, init)
	if (!response.ok) {
		const errorData = await response.json()
		throw new Error(
			errorData.message || `HTTP error! status: ${response.status}`,
		)
	}
	return { response, data: await response.json() }
}

/**
 * How each organisation action names a failure: what it was doing, for the
 * log, and what it failed to do, for the thrown error.
 */
const FAILURES = {
	setActiveOrganisationById: ['setting active organisation', 'set active organisation'],
	joinOrganisation: ['joining organisation', 'join organisation'],
	leaveOrganisation: ['leaving organisation', 'leave organisation'],
	createOrganisation: ['creating organisation', 'create organisation'],
	updateOrganisation: ['updating organisation', 'update organisation'],
	searchOrganisations: ['searching organisations', 'search organisations'],
	clearCache: ['clearing cache', 'clear cache'],
}

/**
 * Log an action's failure and wrap it in the error the action throws.
 *
 * @param {string} action The action's name.
 * @param {Error} error The failure.
 * @return {Error} The error to throw, caused by the failure.
 */
function failureOf(action, error) {
	const [doing, verb] = FAILURES[action]
	console.error(`Error ${doing}:`, error)
	return new Error(`Failed to ${verb}: ${error.message}`, { cause: error })
}

/**
 * The fields an organisation carries that the server calculates or owns,
 * and that a save must not send.
 */
const READ_ONLY_FIELDS = [
	'id',
	'uuid',
	'users',
	'userCount',
	'groupCount',
	'usage', // Usage is calculated by backend, not set by frontend
	'owner',
	'created',
	'updated',
]

/**
 * Put a saved organisation into the list and, when it is the current one,
 * into the current item.
 *
 * @param {object} store The organisation store.
 * @param {{organisationId: string|number, savedOrganisation: object}} saved The saved organisation and the uuid or id it was saved under.
 */
function applySavedOrganisation(store, saved) {
	const { organisationId, savedOrganisation } = saved
	const index = store.organisationList.findIndex((org) =>
		isOrganisation(org, organisationId),
	)
	if (index !== -1) {
		store.organisationList[index] = new Organisation(savedOrganisation)
	}
	if (
		store.organisationItem
		&& isOrganisation(store.organisationItem, organisationId)
	) {
		store.setOrganisationItem(savedOrganisation)
	}
}

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
		/**
		 * @param mode
		 * @spec exclude Pure client UI-state setter — list/card view-mode toggle. No backend contract.
		 */
		setViewMode(mode) {
			this.viewMode = mode
		},
		/**
		 * @param organisationItem
		 * @spec exclude Client state mutator — wraps the active organisation in an entity. No backend contract.
		 */
		setOrganisationItem(organisationItem) {
			this.organisationItem =
				organisationItem && new Organisation(organisationItem)
		},
		/**
		 * @param organisations
		 * @spec exclude Client state mutator — maps the organisation list to entities. No backend contract.
		 */
		setOrganisationList(organisations) {
			this.organisationList = organisations.map(
				(organisation) => new Organisation(organisation),
			)
		},
		/**
		 * @param activeOrganisation
		 * @spec exclude Client state mutator — wraps the active organisation in an entity. No backend contract.
		 */
		setActiveOrganisation(activeOrganisation) {
			this.activeOrganisation =
				activeOrganisation && new Organisation(activeOrganisation)
		},
		/**
		 * @param stats
		 * @spec exclude Client state mutator — maps the user-org stats response into entities. No backend contract.
		 */
		setUserStats(stats) {
			this.userStats = {
				total: stats.total || 0,
				active: stats.active ? new Organisation(stats.active) : null,
				list: (stats.results || []).map((org) => new Organisation(org)),
			}
		},
		...listStateActions(14),
		/**
		 * @spec exclude Thin API passthrough — GET /api/organisations list; observable contract owned by tenant-lifecycle.
		 */
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
		/**
		 * @spec exclude Thin API passthrough — GET /api/organisations/active; observable contract owned by tenant-lifecycle.
		 */
		async getActiveOrganisation() {
			try {
				const { data } = await readJson(`${ORGANISATIONS}/active`, {
					method: 'GET',
				})

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
		/**
		 * @param uuid
		 * @param options
		 * @spec exclude Thin API passthrough — GET /api/organisations/{uuid}; observable contract owned by tenant-lifecycle.
		 */
		async getOrganisation(uuid, options = { setItem: false }) {
			try {
				const { data } = await readJson(`${ORGANISATIONS}/${uuid}`, {
					method: 'GET',
				})

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
		/**
		 * @param uuid
		 * @spec exclude Thin API passthrough — POST /api/organisations/{uuid}/set-active; observable contract owned by tenant-lifecycle.
		 */
		async setActiveOrganisationById(uuid) {
			try {
				const { response, data } = await fetchJson(
					`${ORGANISATIONS}/${uuid}/set-active`,
					{ method: 'POST' },
				)

				if (data.activeOrganisation) {
					this.setActiveOrganisation(data.activeOrganisation)
				}

				// Refresh organisation list to update stats
				await this.refreshOrganisationList()

				return { response, data }
			} catch (error) {
				throw failureOf('setActiveOrganisationById', error)
			}
		},
		/**
		 * Join an organisation with optional user selection
		 *
		 * @param {string} uuid - Organisation UUID to join
		 * @param {string|null} userId - Optional user ID to join the organisation. If null, current user joins.
		 * @return {Promise<{response: Response, data: object}>} API response
		 *
		 * @spec exclude Thin API passthrough — POST /api/organisations/{uuid}/join; observable contract owned by tenant-lifecycle.
		 */
		async joinOrganisation(uuid, userId = null) {
			try {
				// Prepare request body with optional userId
				const requestBody = userId ? { userId } : {}

				const { response, data } = await fetchJson(
					`${ORGANISATIONS}/${uuid}/join`,
					jsonRequest('POST', requestBody),
				)

				// Refresh organisation list to show new membership
				await this.refreshOrganisationList()

				return { response, data }
			} catch (error) {
				throw failureOf('joinOrganisation', error)
			}
		},
		// Leave an organisation
		/**
		 * @param uuid
		 * @spec exclude Thin API passthrough — POST /api/organisations/{uuid}/leave; observable contract owned by tenant-lifecycle.
		 */
		async leaveOrganisation(uuid) {
			try {
				const { response, data } = await fetchJson(
					`${ORGANISATIONS}/${uuid}/leave`,
					{ method: 'POST' },
				)

				// Refresh organisation list and active organisation
				await this.refreshOrganisationList()
				await this.getActiveOrganisation()

				return { response, data }
			} catch (error) {
				throw failureOf('leaveOrganisation', error)
			}
		},
		// Delete an organisation (owner only)
		/**
		 * @param organisationItem
		 * @spec exclude Thin API passthrough — DELETE /api/organisations/{uuid}; observable contract owned by tenant-lifecycle.
		 */
		async deleteOrganisation(organisationItem) {
			if (!organisationItem.uuid) {
				throw new Error('No organisation UUID to delete')
			}

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
		/**
		 * @param organisationData
		 * @spec exclude Thin API passthrough — POST /api/organisations; observable contract owned by tenant-lifecycle.
		 */
		async createOrganisation(organisationData) {
			try {
				const { response, data } = await fetchJsonOrExplain(
					ORGANISATIONS,
					jsonRequest('POST', organisationData),
				)
				const savedOrganisation = data.organisation || data

				// Update local state
				this.setOrganisationItem(savedOrganisation)

				// Refresh the full list to get updated stats
				await this.refreshOrganisationList()

				return { response, data: savedOrganisation }
			} catch (error) {
				throw failureOf('createOrganisation', error)
			}
		},

		// Update an existing organisation
		/**
		 * @param organisationData
		 * @spec exclude Thin API passthrough — PUT /api/organisations/{uuid}; observable contract owned by tenant-lifecycle.
		 */
		async updateOrganisation(organisationData) {
			const organisationId = organisationIdOf(organisationData)

			// Clean the data before sending - remove read-only fields
			const cleanedData = this.cleanOrganisationForSave(organisationData)

			try {
				const { response, data } = await putOrganisation(
					organisationId,
					cleanedData,
				)
				const savedOrganisation = data.organisation || data
				applySavedOrganisation(this, { organisationId, savedOrganisation })

				// Refresh the full list to get updated stats
				await this.refreshOrganisationList()

				return { response, data: savedOrganisation }
			} catch (error) {
				throw failureOf('updateOrganisation', error)
			}
		},

		// Create or update an organisation (legacy method for backward compatibility)
		/**
		 * @param organisationItem
		 * @spec exclude Client-side create/update dispatcher — delegates to createOrganisation/updateOrganisation passthroughs. No standalone backend contract.
		 */
		async saveOrganisation(organisationItem) {
			const isNewOrganisation = !organisationItem.uuid && !organisationItem.id

			if (isNewOrganisation) {
				return await this.createOrganisation(organisationItem)
			} else {
				return await this.updateOrganisation(organisationItem)
			}
		},
		// Clean organisation data for saving - remove read-only fields
		/**
		 * @param organisationItem
		 * @spec exclude Client-side payload sanitiser — strips read-only fields before save. No standalone backend contract.
		 */
		cleanOrganisationForSave(organisationItem) {
			const cleaned = { ...organisationItem }

			// Remove read-only/calculated fields that should not be sent to the server
			for (const field of READ_ONLY_FIELDS) {
				delete cleaned[field]
			}

			// Remove empty slug to avoid database errors
			if (cleaned.slug === '' || cleaned.slug === null) {
				delete cleaned.slug
			}

			// Remove isDefault as it's now managed via config, not database
			delete cleaned.isDefault

			// Ensure boolean fields are actually booleans, not empty strings
			if (cleaned.active !== undefined) {
				cleaned.active =
					cleaned.active === '' ? true : Boolean(cleaned.active)
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
		 *
		 * @spec exclude Thin API passthrough — GET /api/organisations/search; observable contract owned by tenant-lifecycle.
		 */
		async searchOrganisations(query = '', limit = 50, offset = 0) {
			// Build query parameters
			const params = organisationQuery(query)
			params.append('_limit', limit)
			params.append('_offset', offset)

			try {
				const { data } = await fetchJson(
					`${ORGANISATIONS}/search?${params.toString()}`,
					{ method: 'GET' },
				)
				return data.organisations || []
			} catch (error) {
				throw failureOf('searchOrganisations', error)
			}
		},
		// Clear cache
		/**
		 * @spec exclude Thin API passthrough — POST /api/organisations/clear-cache; observable contract owned by tenant-lifecycle.
		 */
		async clearCache() {
			try {
				const { response, data } = await readJson(
					`${ORGANISATIONS}/clear-cache`,
					{ method: 'POST' },
				)

				// Refresh organisation data after cache clear
				await this.refreshOrganisationList()

				return { response, data }
			} catch (error) {
				throw failureOf('clearCache', error)
			}
		},
		/**
		 * Load and cache Nextcloud groups for organisation access control
		 * This should be called on the organisations index page to preload groups
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
