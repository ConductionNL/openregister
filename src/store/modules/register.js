/* eslint-disable no-console */
import { createCrudStore } from '@conduction/nextcloud-vue'
import { Register } from '../../entities/index.js'

export const useRegisterStore = createCrudStore('register', {
	endpoint: 'registers',
	entity: Register,
	features: { loading: true, viewMode: true },
	extend: {
		state: () => ({
			activeTab: 'stats-tab',
		}),
		getters: {
			getActiveTab: (state) => state.activeTab,
		},
		actions: {
			setActiveTab(tab) {
				this.activeTab = tab
				console.log('Active tab set to:', tab)
			},
			// Override refreshList to add custom query params
			async refreshList(search = null) {
				console.log('RegisterStore: Starting refreshList')
				let endpoint = this._options.baseApiUrl + '?_extend[]=schemas&_extend[]=@self.stats'
				if (search !== null && search !== '') {
					endpoint += '&_search=' + encodeURIComponent(search)
				}
				const response = await fetch(endpoint, { method: 'GET' })
				const data = (await response.json()).results
				this.setList(data)
				console.log('RegisterStore: refreshList completed, got', data.length, 'registers')
				return { response, data }
			},
			async getRegisterStats(id) {
				const response = await fetch(`${this._options.baseApiUrl}/${id}/stats`, { method: 'GET' })
				return await response.json()
			},
			async publishRegister(registerId, date = null) {
				if (!registerId) throw new Error('No register ID provided')
				console.log('Publishing register...')
				let endpoint = `${this._options.baseApiUrl}/${registerId}/publish`
				if (date) endpoint += `?date=${encodeURIComponent(date)}`
				try {
					const response = await fetch(endpoint, { method: 'POST' })
					if (!response.ok) {
						const errorData = await response.json().catch(() => ({}))
						throw new Error(errorData.error || `HTTP error! status: ${response.status}`)
					}
					const responseData = await response.json()
					await this.refreshList()
					if (this.item && this.item.id === registerId) {
						this.setItem(responseData)
					}
					return { response, data: responseData }
				} catch (error) {
					console.error('Error publishing register:', error)
					throw new Error(`Failed to publish register: ${error.message}`)
				}
			},
			async depublishRegister(registerId, date = null) {
				if (!registerId) throw new Error('No register ID provided')
				console.log('Depublishing register...')
				let endpoint = `${this._options.baseApiUrl}/${registerId}/depublish`
				if (date) endpoint += `?date=${encodeURIComponent(date)}`
				try {
					const response = await fetch(endpoint, { method: 'POST' })
					if (!response.ok) {
						const errorData = await response.json().catch(() => ({}))
						throw new Error(errorData.error || `HTTP error! status: ${response.status}`)
					}
					const responseData = await response.json()
					await this.refreshList()
					if (this.item && this.item.id === registerId) {
						this.setItem(responseData)
					}
					return { response, data: responseData }
				} catch (error) {
					console.error('Error depublishing register:', error)
					throw new Error(`Failed to depublish register: ${error.message}`)
				}
			},
			async uploadRegister(register) {
				if (!register) throw new Error('No register item to upload')
				console.log('Uploading register...')
				const isNew = !this.item
				const endpoint = isNew
					? this._options.baseApiUrl + '/upload'
					: `${this._options.baseApiUrl}/upload/${this.item.id}`
				const method = isNew ? 'POST' : 'PUT'
				const response = await fetch(endpoint, {
					method,
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify(register),
				})
				if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`)
				const responseData = await response.json()
				if (!responseData || typeof responseData !== 'object') throw new Error('Invalid response data')
				const data = new Register(responseData)
				this.setItem(data)
				this.refreshList()
				return { response, data }
			},
			startImportHeartbeat(intervalMs = 15000, onStatusChange = null) {
				console.log('RegisterStore: Starting import heartbeat every', intervalMs, 'ms')
				let heartbeatCount = 0
				let failureCount = 0
				let isHealthy = true
				const heartbeatInterval = setInterval(async () => {
					try {
						heartbeatCount++
						const startTime = Date.now()
						const response = await fetch('/index.php/apps/openregister/api/heartbeat', {
							method: 'GET',
							headers: { 'X-Requested-With': 'XMLHttpRequest', 'Cache-Control': 'no-cache' },
							signal: AbortSignal.timeout(10000),
						})
						if (!response.ok) throw new Error(`HTTP ${response.status}: ${response.statusText}`)
						const duration = Date.now() - startTime
						console.log(`RegisterStore: Heartbeat #${heartbeatCount} successful (${duration}ms)`)
						if (failureCount > 0) {
							failureCount = 0
							isHealthy = true
							if (onStatusChange) onStatusChange({ healthy: true, failures: 0, count: heartbeatCount })
						}
					} catch (error) {
						failureCount++
						const wasHealthy = isHealthy
						isHealthy = failureCount < 3
						console.error(`RegisterStore: Heartbeat #${heartbeatCount} failed (failure ${failureCount}):`, error.message)
						if (onStatusChange && (!wasHealthy !== !isHealthy)) {
							onStatusChange({ healthy: isHealthy, failures: failureCount, count: heartbeatCount, error: error.message })
						}
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
				if (!file) throw new Error('No file to import')
				console.log('RegisterStore: Starting import...')
				const registerId = this.item?.id
				if (!registerId) throw new Error('No register selected for import')

				const fileExtension = file.name.split('.').pop().toLowerCase()
				const { useSchemaStore } = await import('./schema.js')
				const schemaStore = useSchemaStore()
				const schemaId = (fileExtension === 'csv' && schemaStore.item) ? schemaStore.item.id : null

				let endpoint = `${this._options.baseApiUrl}/${registerId}/import`
				if (schemaId) endpoint += `?schema=${schemaId}`

				const formData = new FormData()
				formData.append('file', file)
				if (schemaId) formData.append('schema', schemaId)

				const heartbeat = this.startImportHeartbeat(15000, heartbeatCallback)
				try {
					console.log('RegisterStore: Sending import request to:', endpoint)
					const controller = new AbortController()
					const timeoutId = setTimeout(() => {
						console.warn('RegisterStore: Import taking longer than expected (5 minutes)')
					}, 5 * 60 * 1000)
					const response = await fetch(endpoint, { method: 'POST', body: formData, signal: controller.signal })
					clearTimeout(timeoutId)
					const responseData = await response.json()
					if (!response.ok) {
						if (responseData && responseData.error) throw new Error(responseData.error)
						throw new Error(`HTTP error! status: ${response.status}`)
					}
					if (!responseData || typeof responseData !== 'object') throw new Error('Invalid response data')
					console.log('RegisterStore: Import successful, starting register refresh in background...')
					this.refreshList().catch(error => {
						console.error('RegisterStore: Error refreshing register list:', error)
					})
					return { response, responseData }
				} catch (error) {
					console.error('RegisterStore: Error importing register:', error)
					throw error
				} finally {
					heartbeat.stop()
				}
			},
			clearItem() {
				this.item = null
				this.error = null
			},
		},
	},
})
