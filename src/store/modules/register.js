import { defineStore } from 'pinia'
import { Register } from '../../entities/index.js'
import {
	getJson,
	importAnswer,
	itemTarget,
	loggedRequest,
	objectAnswer,
	sendJson,
	withoutReadOnly,
} from '../../services/listPage.js'
import { listStateActions, listStateFields } from './listState.js'

/**
 * The schema a CSV import goes into: the selected schema, for a .csv file only.
 *
 * @param {File} file The file to import.
 * @return {Promise<string|number|null>} The schema id, or null.
 */
async function csvImportSchemaId(file) {
	const fileExtension = file.name.split('.').pop().toLowerCase()
	const { useSchemaStore } = await import('./schema.js')
	const schemaStore = useSchemaStore()
	return fileExtension === 'csv' && schemaStore.schemaItem
		? schemaStore.schemaItem.id
		: null
}

/**
 * The endpoint and form an import posts.
 *
 * @param {File} file The file to import.
 * @param {{registerId: string|number, schemaId: string|number|null}} target The register, and the schema for a CSV file.
 * @return {{endpoint: string, formData: FormData}} The request.
 */
function importRequest(file, target) {
	let endpoint = `/index.php/apps/openregister/api/registers/${target.registerId}/import`
	const formData = new FormData()
	formData.append('file', file)
	if (target.schemaId) {
		endpoint += `?schema=${target.schemaId}`
		formData.append('schema', target.schemaId)
	}
	return { endpoint, formData }
}

/**
 * Post an import and read its answer.
 *
 * @param {{endpoint: string, formData: FormData}} request The request.
 * @return {Promise<{response: Response, responseData: object}>} The answer.
 * @throws {Error} The answer's own error, the HTTP status, or a note that the body is not an object.
 */
async function postImport(request) {
	// Create controller for potential timeout handling
	const controller = new AbortController()
	const timeoutId = setTimeout(
		() => {
			// Import taking longer than expected (5 minutes); no action taken.
		},
		5 * 60 * 1000,
	) // 5 minutes warning

	const response = await fetch(request.endpoint, {
		method: 'POST',
		body: request.formData,
		signal: controller.signal,
	})

	clearTimeout(timeoutId)
	const responseData = await importAnswer(response)

	return { response, responseData }
}

/**
 * Send one heartbeat request, which keeps the session alive during an import.
 *
 * @return {Promise<void>}
 * @throws {Error} When the answer is not OK.
 * @spec openspec/specs/frontend-store-client-state/spec.md
 */
async function pingHeartbeat() {
	const response = await fetch('/index.php/apps/openregister/api/heartbeat', {
		method: 'GET',
		headers: {
			'X-Requested-With': 'XMLHttpRequest',
			'Cache-Control': 'no-cache',
		},
		// Add timeout to prevent hanging requests
		signal: AbortSignal.timeout(10000), // 10 second timeout
	})
	if (!response.ok) {
		throw new Error(`HTTP ${response.status}: ${response.statusText}`)
	}
}

/**
 * Record a successful heartbeat: after failures it is healthy again, and the
 * listener hears so.
 *
 * @param {object} state The heartbeat's `{ count, failures, healthy, notify }`.
 * @return {void}
 * @spec openspec/specs/frontend-store-client-state/spec.md
 */
function heartbeatRecovered(state) {
	if (state.failures === 0) {
		return
	}
	state.failures = 0
	state.healthy = true
	if (state.notify) {
		state.notify({ healthy: true, failures: 0, count: state.count })
	}
}

/**
 * Record a failed heartbeat: three failures in a row make it unhealthy, and
 * the listener hears when health changes.
 *
 * @param {object} state The heartbeat's `{ count, failures, healthy, notify }`.
 * @param {Error} error Why it failed.
 * @return {void}
 * @spec openspec/specs/frontend-store-client-state/spec.md
 */
function heartbeatFailed(state, error) {
	state.failures++
	const wasHealthy = state.healthy
	state.healthy = state.failures < 3
	console.error(
		`RegisterStore: Heartbeat #${state.count} failed (failure ${state.failures}):`,
		error.message,
	)
	if (!wasHealthy !== !state.healthy) {
		notifyHealthChange(state, error)
	}
}

/**
 * Tell the heartbeat's listener, if any, that the health flipped.
 *
 * @param {object} state The heartbeat state.
 * @param {Error} error The failure that flipped it.
 * @return {void}
 * @spec exclude Client-side heartbeat bookkeeping.
 */
function notifyHealthChange(state, error) {
	if (state.notify) {
		state.notify({
			healthy: state.healthy,
			failures: state.failures,
			count: state.count,
			error: error.message,
		})
	}
}

/**
 * One heartbeat: count it, send it, and record how it went.
 *
 * @param {object} state The heartbeat's `{ count, failures, healthy, notify }`.
 * @return {Promise<void>}
 * @spec openspec/specs/frontend-store-client-state/spec.md
 */
async function beat(state) {
	try {
		state.count++
		await pingHeartbeat()
		heartbeatRecovered(state)
	} catch (error) {
		heartbeatFailed(state, error)
	}
}

/**
 * The register list URL, with schemas and stats, narrowed by a search when
 * one is given.
 *
 * @param {string|null} search The search text.
 * @return {string} The URL.
 * @spec exclude Client-side URL of the registers API passthrough.
 */
function registerListUrl(search) {
	const endpoint =
		'/index.php/apps/openregister/api/registers?_extend[]=schemas&_extend[]=@self.stats'
	if (search !== null && search !== '') {
		return endpoint + '&_search=' + encodeURIComponent(search)
	}
	return endpoint
}

/**
 * The URL of one register, or of the list for a new one.
 *
 * @param {object} registerItem The register.
 * @return {string} The URL.
 * @spec exclude Client-side URL of the registers API passthrough.
 */
function registerUrl(registerItem) {
	return registerItem.id
		? `/index.php/apps/openregister/api/registers/${registerItem.id}`
		: '/index.php/apps/openregister/api/registers'
}

/**
 * The register an import goes into: the active one.
 *
 * @param {object} store The register store.
 * @param {File} file The file to import.
 * @return {string|number} The register id.
 * @throws {Error} When there is no file or no active register.
 * @spec exclude Client-side precondition of POST /api/registers/{id}/import.
 */
function importTargetId(store, file) {
	if (!file) {
		throw new Error('No file to import')
	}
	const registerId = store.registerItem?.id
	if (!registerId) {
		throw new Error('No register selected for import')
	}
	return registerId
}

/**
 * Post an import, then refresh the register list in the background, so the
 * import completes without waiting for the list.
 *
 * @param {object} store The register store.
 * @param {{endpoint: string, formData: FormData}} request The import request.
 * @return {Promise<object>} The import answer.
 * @spec exclude Thin API passthrough — POST /api/registers/{id}/import.
 */
function importAndRefresh(store, request) {
	return loggedRequest({
		log: (error) =>
			console.error('RegisterStore: Error importing register:', error),
		run: async () => {
			const result = await postImport(request)
			store.refreshRegisterList().catch((error) => {
				console.error(
					'RegisterStore: Error refreshing register list:',
					error,
				)
			})
			return result
		},
	})
}

// Module-scoped single-flight token for refreshRegisterList. The endpoint is
// expensive (~5-16s with _extend=schemas + @self.stats on dev envs with 100+
// registers), and every sidebar mount + AppInitializationService both call
// refreshRegisterList on app boot. Without coalescing, callers issue 2-4
// identical parallel fetches that race the SearchSideBar's `:loading` /
// `:disabled` flags past the e2e tests' 30s budget (see
// tests/e2e/spec-coverage/saved-search-views.spec.ts). Holding the in-flight
// promise here lets every caller await the same fetch.
let inFlightRefresh = null

export const useRegisterStore = defineStore('register', {
	state: () => ({
		registerItem: null,
		registerList: [],
		loading: false,
		error: null,
		viewMode: 'cards',
		activeTab: 'stats-tab',
		...listStateFields(),
	}),
	getters: {
		getRegisterItem: (state) => state.registerItem,
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
		getActiveTab: (state) => state.activeTab,
		getViewMode: (state) => state.viewMode,
	},
	actions: {
		/**
		 * @param tab
		 * @spec exclude Pure client UI-state setter — active detail tab. No backend contract.
		 */
		setActiveTab(tab) {
			this.activeTab = tab
		},
		/**
		 * @param mode
		 * @spec exclude Pure client UI-state setter — list/card view-mode toggle. No backend contract.
		 */
		setViewMode(mode) {
			this.viewMode = mode
		},
		/**
		 * @param registerItem
		 * @spec exclude Client state mutator — wraps the active register in an entity. No backend contract.
		 */
		setRegisterItem(registerItem) {
			try {
				this.loading = true
				this.error = null
				this.registerItem = registerItem ? new Register(registerItem) : null
			} catch (error) {
				console.error('Error setting register item:', error)
				this.error = error.message
			} finally {
				this.loading = false
			}
		},
		/**
		 * @param registerList
		 * @spec exclude Client state mutator — maps the register list to entities. No backend contract.
		 */
		setRegisterList(registerList) {
			this.registerList = registerList.map(
				(registerItem) => new Register(registerItem),
			)
		},
		...listStateActions(14),
		/**
		 * @spec exclude Thin API passthrough — GET /api/registers list; observable contract owned by the register lifecycle backend capability.
		 */
		/* istanbul ignore next */ // ignore this for Jest until moved into a service
		async refreshRegisterList(search = null) {
			// Single-flight: callers that pass the same `search` (or none)
			// while a fetch is in flight share the same promise. CRUD-driven
			// callers that pass a custom search bypass the cache.
			if (search === null && inFlightRefresh) {
				return inFlightRefresh
			}
			const endpoint = registerListUrl(search)
			const work = (async () => {
				const response = await fetch(endpoint, { method: 'GET' })
				const data = (await response.json()).results
				this.setRegisterList(data)
				return { response, data }
			})()
			if (search === null) {
				inFlightRefresh = work.finally(() => {
					inFlightRefresh = null
				})
				return inFlightRefresh
			}
			return work
		},
		// New function to get a single register
		/**
		 * @param id
		 * @spec exclude Thin API passthrough — GET /api/registers/{id}; observable contract owned by the register lifecycle backend capability.
		 */
		async getRegister(id) {
			const endpoint = `/index.php/apps/openregister/api/registers/${id}`
			try {
				const data = await getJson(endpoint)
				this.setRegisterItem(data)
				return data
			} catch (err) {
				console.error(err)
				throw err
			}
		},
		// New function to get register statistics
		/**
		 * @param id
		 * @spec exclude Thin API passthrough — GET /api/registers/{id}/stats; observable contract owned by the register lifecycle backend capability.
		 */
		async getRegisterStats(id) {
			const endpoint = `/index.php/apps/openregister/api/registers/${id}/stats`
			try {
				const data = await getJson(endpoint)
				return data
			} catch (err) {
				console.error(err)
				throw err
			}
		},
		// Delete a register
		/**
		 * @param registerItem
		 * @spec exclude Thin API passthrough — DELETE /api/registers/{id}; observable contract owned by the register lifecycle backend capability.
		 */
		async deleteRegister(registerItem) {
			if (!registerItem.id) {
				throw new Error('No register item to delete')
			}
			return loggedRequest({
				failure: 'Failed to delete register',
				log: (error) => console.error('Error deleting register:', error),
				run: async () => {
					const response = await fetch(registerUrl(registerItem), {
						method: 'DELETE',
					})
					const responseData = await objectAnswer(response)
					this.refreshRegisterList()
					this.setRegisterItem(null)
					return { response, data: responseData }
				},
			})
		},

		// Create or save a register from store
		/**
		 * @param registerItem
		 * @spec exclude Thin API passthrough — POST/PUT /api/registers; observable contract owned by the register lifecycle backend capability.
		 */
		async saveRegister(registerItem) {
			if (!registerItem) {
				throw new Error('No register item to save')
			}
			const endpoint = registerUrl(registerItem)
			const method = registerItem.id ? 'PUT' : 'POST'
			// Clean the data before sending - remove read-only fields
			const body = this.cleanRegisterForSave(registerItem)
			return loggedRequest({
				failure: 'Failed to save register',
				log: (error) => console.error('Error saving register:', error),
				run: async () => {
					const response = await sendJson(endpoint, { method, body })
					const data = new Register(await objectAnswer(response))
					this.setRegisterItem(data)
					this.refreshRegisterList()
					return { response, data }
				},
			})
		},

		// Clean register data for saving - remove read-only fields
		/**
		 * @param registerItem
		 * @spec exclude Client-side payload sanitiser — strips read-only fields before save. No standalone backend contract.
		 */
		cleanRegisterForSave(registerItem) {
			return withoutReadOnly(registerItem)
		},
		// Create or save a register from store
		/**
		 * @param register
		 * @spec exclude Thin API passthrough — POST/PUT /api/registers/upload; observable contract owned by the register lifecycle backend capability.
		 */
		async uploadRegister(register) {
			if (!register) {
				throw new Error('No register item to upload')
			}
			const { endpoint, method } = itemTarget(
				'/index.php/apps/openregister/api/registers/upload',
				this.registerItem,
			)
			const response = await sendJson(endpoint, { method, body: register })
			const data = new Register(await objectAnswer(response))
			this.setRegisterItem(data)
			this.refreshRegisterList()
			return { response, data }
		},

		/**
		 * Start a heartbeat mechanism to prevent gateway timeouts during long imports
		 *
		 * @param {number} intervalMs - Heartbeat interval in milliseconds (default: 15 seconds)
		 * @param {Function} onStatusChange - Callback for heartbeat status changes
		 * @return {object} - Object with stop() method and status property
		 *
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		startImportHeartbeat(intervalMs = 15000, onStatusChange = null) {
			const state = {
				count: 0,
				failures: 0,
				healthy: true,
				notify: onStatusChange,
			}
			const heartbeatInterval = setInterval(() => beat(state), intervalMs)

			return {
				/**
				 * @spec openspec/specs/frontend-store-client-state/spec.md
				 */
				stop() {
					clearInterval(heartbeatInterval)
				},
				/**
				 * @spec openspec/specs/frontend-store-client-state/spec.md
				 */
				getStatus() {
					return {
						healthy: state.healthy,
						failures: state.failures,
						count: state.count,
					}
				},
			}
		},

		/**
		 * @param file
		 * @param heartbeatCallback
		 * @spec exclude Thin API passthrough — POST /api/registers/{id}/import; observable contract owned by data-import-export (the heartbeat it spins up is spec'd separately under frontend-client-state-orchestration REQ-001).
		 */
		async importRegister(file, heartbeatCallback = null) {
			const registerId = importTargetId(this, file)
			const schemaId = await csvImportSchemaId(file)
			const request = importRequest(file, { registerId, schemaId })
			// Heartbeats every 15 seconds keep large imports past the gateway timeout.
			const heartbeat = this.startImportHeartbeat(15000, heartbeatCallback)
			try {
				return await importAndRefresh(this, request)
			} finally {
				heartbeat.stop()
			}
		},

		/**
		 * @spec exclude Pure client UI-state mutator — resets the active register and error. No backend contract.
		 */
		clearRegisterItem() {
			this.registerItem = null
			this.error = null
		},
	},
})
