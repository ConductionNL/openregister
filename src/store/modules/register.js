/* eslint-disable no-console */
import { defineStore } from 'pinia'
import { Register } from '../../entities/index.js'

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
		setActiveTab(tab) {
			this.activeTab = tab
			console.log('Active tab set to:', tab)
		},
		setViewMode(mode) {
			this.viewMode = mode
			console.log('View mode set to:', mode)
		},
		setRegisterItem(registerItem) {
			try {
				this.loading = true
				this.error = null
				this.registerItem = registerItem ? new Register(registerItem) : null
				console.log('Active register item set to ' + (registerItem?.title || 'null'))
			} catch (error) {
				console.error('Error setting register item:', error)
				this.error = error.message
			} finally {
				this.loading = false
			}
		},
		setRegisterList(registerList) {
			this.registerList = registerList.map(
				(registerItem) => new Register(registerItem),
			)
			console.log('Register list set to ' + registerList.length + ' items')
		},
		/**
		 * Set pagination details
		 * @param {number} page - The current page number for pagination
		 * @param {number} limit - The number of items to display per page
		 */
		setPagination(page, limit = 14) {
			this.pagination = { page, limit }
			console.info('Pagination set to', { page, limit }) // Logging the pagination
		},
		/**
		 * Set query filters for register list
		 * @param {object} filters - The filter criteria to apply to the register list
		 */
		setFilters(filters) {
			this.filters = { ...this.filters, ...filters }
			console.info('Query filters set to', this.filters) // Logging the filters
		},
		/* istanbul ignore next */ // ignore this for Jest until moved into a service
		async refreshRegisterList(search = null) {
			console.log('RegisterStore: Starting refreshRegisterList')
			let endpoint = '/index.php/apps/openregister/api/registers'
			if (search !== null && search !== '') {
				endpoint = endpoint + '?_search=' + encodeURIComponent(search)
			}
			const response = await fetch(endpoint, {
				method: 'GET',
			})

			const data = (await response.json()).results

			this.setRegisterList(data)
			console.log('RegisterStore: refreshRegisterList completed, got', data.length, 'registers')

			return { response, data }
		},
		// New function to get a single register
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
		async deleteRegister(registerItem) {
			if (!registerItem.id) {
				throw new Error('No register item to delete')
			}

			console.log('Deleting register...')

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
		async saveRegister(registerItem) {
			if (!registerItem) {
				throw new Error('No register item to save')
			}

			console.log('Saving register...')

			const isNewRegister = !registerItem.id
			const endpoint = isNewRegister
				? '/index.php/apps/openregister/api/registers'
				: `/index.php/apps/openregister/api/registers/${registerItem.id}`
			const method = isNewRegister ? 'POST' : 'PUT'

			// change updated to current date as a singular iso date string
			registerItem.updated = new Date().toISOString()

			try {
				const response = await fetch(
					endpoint,
					{
						method,
						headers: {
							'Content-Type': 'application/json',
						},
						body: JSON.stringify(registerItem),
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
		// Create or save a register from store
		async uploadRegister(register) {
			if (!register) {
				throw new Error('No register item to upload')
			}

			console.log('Uploading register...')

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
		 */
		startImportHeartbeat(intervalMs = 15000, onStatusChange = null) {
			console.log('RegisterStore: Starting import heartbeat every', intervalMs, 'ms')

			let heartbeatCount = 0
			let failureCount = 0
			let isHealthy = true

			const heartbeatInterval = setInterval(async () => {
				try {
					heartbeatCount++
					const startTime = Date.now()

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

					const duration = Date.now() - startTime
					console.log(`RegisterStore: Heartbeat #${heartbeatCount} successful (${duration}ms)`)

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

					// If too many failures, warn user but don't stop heartbeat
					if (failureCount === 3) {
						console.warn('RegisterStore: Multiple heartbeat failures detected - connection may be unstable')
					}
				}
			}, intervalMs)

			return {
				stop() {
					console.log(`RegisterStore: Stopping import heartbeat after ${heartbeatCount} attempts (${failureCount} failures)`)
					clearInterval(heartbeatInterval)
				},
				getStatus() {
					return { healthy: isHealthy, failures: failureCount, count: heartbeatCount }
				},
			}
		},

		async importRegister(file, heartbeatCallback = null) {
			if (!file) {
				throw new Error('No file to import')
			}

			console.log('RegisterStore: Starting import...')

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
				console.log('RegisterStore: Sending import request to:', endpoint)

				// Create controller for potential timeout handling
				const controller = new AbortController()
				const timeoutId = setTimeout(() => {
					console.warn('RegisterStore: Import taking longer than expected (5 minutes)')
					// Don't abort, but log a warning for debugging
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

				console.log('RegisterStore: Import successful, starting register refresh in background...')
				// Start the register refresh in the background without waiting for it to complete
				// This way the import can complete and the loading state can be turned off
				this.refreshRegisterList().catch(error => {
					console.error('RegisterStore: Error refreshing register list:', error)
				})
				console.log('RegisterStore: Register refresh started in background')

				return { response, responseData }
			} catch (error) {
				console.error('RegisterStore: Error importing register:', error)
				throw error // Pass through the original error message
			} finally {
				// Always stop the heartbeat when import completes (success or error)
				heartbeat.stop()
			}
		},
		clearRegisterItem() {
			this.registerItem = null
			this.error = null
		},
	},
})
