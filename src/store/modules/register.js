import { defineStore } from 'pinia'
import { Register } from '../../entities/index.js'

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
		filters: [], // List of query
		pagination: {
			page: 1,
			limit: 20,
		},
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
		/**
		 * Set pagination details
		 * @param {number} page - The current page number for pagination
		 * @param {number} limit - The number of items to display per page
		 *
		 * @spec exclude Pure client UI-state setter — list pagination cursor. No backend contract.
		 */
		setPagination(page, limit = 14) {
			this.pagination = { page, limit }
		},
		/**
		 * Set query filters for register list
		 * @param {object} filters - The filter criteria to apply to the register list
		 *
		 * @spec exclude Pure client UI-state setter — list filter criteria. No backend contract.
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
		},
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
			let endpoint = '/index.php/apps/openregister/api/registers?_extend[]=schemas&_extend[]=@self.stats'
			if (search !== null && search !== '') {
				endpoint = endpoint + '&_search=' + encodeURIComponent(search)
			}
			const work = (async () => {
				const response = await fetch(endpoint, { method: 'GET' })
				const data = (await response.json()).results
				this.setRegisterList(data)
				return { response, data }
			})()
			if (search === null) {
				inFlightRefresh = work.finally(() => { inFlightRefresh = null })
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
				const response = await fetch(endpoint, {
					method: 'GET',
				})
				const data = await response.json()
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
				const response = await fetch(endpoint, {
					method: 'GET',
				})
				const data = await response.json()
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

			const endpoint = `/index.php/apps/openregister/api/registers/${registerItem.id}`

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

				this.refreshRegisterList()
				this.setRegisterItem(null)

				return { response, data: responseData }
			} catch (error) {
				console.error('Error deleting register:', error)
				throw new Error(`Failed to delete register: ${error.message}`)
			}
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

			const isNewRegister = !registerItem.id
			const endpoint = isNewRegister
				? '/index.php/apps/openregister/api/registers'
				: `/index.php/apps/openregister/api/registers/${registerItem.id}`
			const method = isNewRegister ? 'POST' : 'PUT'

			// Clean the data before sending - remove read-only fields
			const cleanedData = this.cleanRegisterForSave(registerItem)

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

				if (!responseData || typeof responseData !== 'object') {
					throw new Error('Invalid response data')
				}

				const data = new Register(responseData)

				this.setRegisterItem(data)
				this.refreshRegisterList()

				return { response, data }
			} catch (error) {
				console.error('Error saving register:', error)
				throw new Error(`Failed to save register: ${error.message}`)
			}
		},
		// Clean register data for saving - remove read-only fields
		/**
		 * @param registerItem
		 * @spec exclude Client-side payload sanitiser — strips read-only fields before save. No standalone backend contract.
		 */
		cleanRegisterForSave(registerItem) {
			const cleaned = { ...registerItem }

			// Remove read-only/calculated fields that should not be sent to the server
			delete cleaned.id
			delete cleaned.uuid
			delete cleaned.created
			delete cleaned.updated

			return cleaned
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

			const isNewRegister = !this.registerItem
			const endpoint = isNewRegister
				? '/index.php/apps/openregister/api/registers/upload'
				: `/index.php/apps/openregister/api/registers/upload/${this.registerItem.id}`
			const method = isNewRegister ? 'POST' : 'PUT'

			const response = await fetch(
				endpoint,
				{
					method,
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(register),
				},
			)

			if (!response.ok) {
				throw new Error(`HTTP error! status: ${response.status}`)
			}

			const responseData = await response.json()

			if (!responseData || typeof responseData !== 'object') {
				throw new Error('Invalid response data')
			}

			const data = new Register(responseData)

			this.setRegisterItem(data)
			this.refreshRegisterList()

			return { response, data }

		},
		/**
		 * Start a heartbeat mechanism to prevent gateway timeouts during long imports
		 * @param {number} intervalMs - Heartbeat interval in milliseconds (default: 15 seconds)
		 * @param {Function} onStatusChange - Callback for heartbeat status changes
		 * @return {object} - Object with stop() method and status property
		 *
		 * @spec openspec/specs/frontend-store-client-state/spec.md
		 */
		startImportHeartbeat(intervalMs = 15000, onStatusChange = null) {
			let heartbeatCount = 0
			let failureCount = 0
			let isHealthy = true

			const heartbeatInterval = setInterval(async () => {
				try {
					heartbeatCount++

					// Send a lightweight request to keep the session alive
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

					// Reset failure count on success
					if (failureCount > 0) {
						failureCount = 0
						isHealthy = true
						if (onStatusChange) {
							onStatusChange({ healthy: true, failures: 0, count: heartbeatCount })
						}
					}

				} catch (error) {
					failureCount++
					const wasHealthy = isHealthy
					isHealthy = failureCount < 3 // Consider unhealthy after 3 consecutive failures

					console.error(`RegisterStore: Heartbeat #${heartbeatCount} failed (failure ${failureCount}):`, error.message)

					if (onStatusChange && (!wasHealthy !== !isHealthy)) {
						onStatusChange({ healthy: isHealthy, failures: failureCount, count: heartbeatCount, error: error.message })
					}
				}
			}, intervalMs)

			return {
				/**
				 * @spec openspec/specs/frontend-store-client-state/spec.md
				 */
				stop() {
					clearInterval(heartbeatInterval)
				},
				getStatus() {
					return { healthy: isHealthy, failures: failureCount, count: heartbeatCount }
				},
			}
		},

		/**
		 * @param file
		 * @param heartbeatCallback
		 * @spec exclude Thin API passthrough — POST /api/registers/{id}/import; observable contract owned by data-import-export (the heartbeat it spins up is spec'd separately under frontend-client-state-orchestration REQ-001).
		 */
		async importRegister(file, heartbeatCallback = null) {
			if (!file) {
				throw new Error('No file to import')
			}

			const registerId = this.registerItem?.id
			if (!registerId) {
				throw new Error('No register selected for import')
			}

			// Get the schema for CSV files
			const fileExtension = file.name.split('.').pop().toLowerCase()
			const { useSchemaStore } = await import('./schema.js')
			const schemaStore = useSchemaStore()
			const schemaId = (fileExtension === 'csv' && schemaStore.schemaItem) ? schemaStore.schemaItem.id : null

			// Build basic endpoint
			let endpoint = `/index.php/apps/openregister/api/registers/${registerId}/import`
			if (schemaId) {
				endpoint += `?schema=${schemaId}`
			}

			const formData = new FormData()
			formData.append('file', file)
			if (schemaId) {
				formData.append('schema', schemaId)
			}

			// Start heartbeat to prevent gateway timeouts for large imports
			// Use 15-second intervals for better timeout prevention
			const heartbeat = this.startImportHeartbeat(15000, heartbeatCallback) // Every 15 seconds

			try {
				// Create controller for potential timeout handling
				const controller = new AbortController()
				const timeoutId = setTimeout(() => {
					// Import taking longer than expected (5 minutes); no action taken.
				}, 5 * 60 * 1000) // 5 minutes warning

				const response = await fetch(
					endpoint,
					{
						method: 'POST',
						body: formData,
						signal: controller.signal,
					},
				)

				clearTimeout(timeoutId)
				const responseData = await response.json()

				if (!response.ok) {
					// If we have an error message in the response, use that
					if (responseData && responseData.error) {
						throw new Error(responseData.error)
					}
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				if (!responseData || typeof responseData !== 'object') {
					throw new Error('Invalid response data')
				}

				// Start the register refresh in the background without waiting for it to complete
				// This way the import can complete and the loading state can be turned off
				this.refreshRegisterList().catch(error => {
					console.error('RegisterStore: Error refreshing register list:', error)
				})

				return { response, responseData }
			} catch (error) {
				console.error('RegisterStore: Error importing register:', error)
				throw error // Pass through the original error message
			} finally {
				// Always stop the heartbeat when import completes (success or error)
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
