/**
 * OpenRegister Settings Store
 *
 * Centralized state management for all settings sections using Pinia.
 * This store handles data fetching, state management, and API calls for:
 * - SOLR configuration and dashboard
 * - RBAC settings
 * - Multitenancy configuration
 * - Retention policies
 * - Cache management
 * - System statistics
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'

export const useSettingsStore = defineStore('settings', {
	state: () => ({
		// Loading states
		loading: false,
		loadingInProgress: false,
		saving: false,
		rebasing: false,
		loadingStats: false,
		loadingCacheStats: false,
		loadingVersionInfo: false,

		// Stats data (cached)
		extractionStats: null,
		vectorStats: null,

		// SOLR states
		testingConnection: false,
		warmingUpSolr: false,
		settingUpSolr: false,
		showTestDialog: false,
		showSetupDialog: false,
		showFieldsDialog: false,
		loadingFields: false,
		testResults: null,
		setupResults: null,
		fieldsInfo: null,
		fieldComparison: null,
		creatingFields: false,
		fixingFields: false,
		fieldCreationResult: null,

		// Cache states
		clearingCache: false,
		warmingUpCache: false,
		showClearCacheConfirmation: false,
		clearCacheType: 'all',

		// Mass validation states
		massValidating: false,
		showMassValidateConfirmation: false,
		massValidateResults: null,

		// Clear logs states
		clearingAuditTrails: false,
		clearingSearchTrails: false,
		showClearAuditTrailsConfirmation: false,
		showClearSearchTrailsConfirmation: false,

		// Settings data
		solrOptions: {
			enabled: false,
			host: 'solr',
			port: 8983,
			path: '/solr',
			core: 'openregister',
			scheme: 'http',
			username: '',
			password: '',
			timeout: 30,
			autoCommit: true,
			commitWithin: 1000,
			enableLogging: true,
			zookeeperHosts: 'zookeeper:2181',
			collection: 'openregister',
			useCloud: true,
			tenantId: '',
		},

		rbacOptions: {
			enabled: false,
			anonymousGroup: 'public',
			defaultNewUserGroup: 'viewer',
			defaultObjectOwner: '',
			adminOverride: true,
		},

		multitenancyOptions: {
			enabled: false,
			defaultUserTenant: '',
			defaultObjectTenant: '',
			publishedObjectsBypassMultiTenancy: false,
			adminOverride: true,
		},

		retentionOptions: {
			objectArchiveRetention: 31536000000, // 1 year
			objectDeleteRetention: 63072000000, // 2 years
			searchTrailRetention: 2592000000, // 1 month
			createLogRetention: 2592000000, // 1 month
			readLogRetention: 86400000, // 24 hours
			updateLogRetention: 604800000, // 1 week
			deleteLogRetention: 2592000000, // 1 month
			auditTrailsEnabled: true, // Audit trails enabled by default
			searchTrailsEnabled: true, // Search trails enabled by default
		},

		llmOptions: {
			enabled: false,
			providerId: 'none',
			apiEndpoint: '',
			apiKey: '',
			model: null,
			temperature: 0.7,
			maxTokens: 2000,
			enabledFeatures: [],
		},

		fileOptions: {
			extractionScope: 'objects', // none, all, folders, objects
			extractionMode: 'background', // background, immediate, manual
			maxFileSize: 100,
			batchSize: 10,
			enabledFileTypes: ['txt', 'pdf', 'docx', 'xlsx', 'pptx', 'html', 'md', 'json'],
		},

		loadingLlmSettings: false,
		loadingFileSettings: false,

		versionInfo: {
			appName: 'Open Register',
			appVersion: '0.2.3',
		},

		// Options data
		groupOptions: [],
		userOptions: [],
		tenantOptions: [],
		schemeOptions: [
			{ id: 'http', label: 'HTTP' },
			{ id: 'https', label: 'HTTPS' },
		],

		// Statistics data
		stats: {
			warnings: {
				objectsWithoutOwner: 0,
				objectsWithoutOrganisation: 0,
				auditTrailsWithoutExpiry: 0,
				searchTrailsWithoutExpiry: 0,
				expiredAuditTrails: 0,
				expiredSearchTrails: 0,
				expiredObjects: 0,
			},
			totals: {
				totalObjects: 0,
				totalAuditTrails: 0,
				totalSearchTrails: 0,
				totalConfigurations: 0,
				totalDataAccessProfiles: 0,
				totalOrganisations: 0,
				totalRegisters: 0,
				totalSchemas: 0,
				totalSources: 0,
				deletedObjects: 0,
			},
			lastUpdated: null,
		},

		// Cache statistics
		cacheStats: {
			overview: {
				totalCacheSize: 0,
				totalCacheEntries: 0,
				overallHitRate: 0.0,
				averageResponseTime: 0.0,
				cacheEfficiency: 0.0,
			},
			services: {
				object: { entries: 0, hits: 0, requests: 0, memoryUsage: 0 },
				schema: { entries: 0, hits: 0, requests: 0, memoryUsage: 0 },
				facet: { entries: 0, hits: 0, requests: 0, memoryUsage: 0 },
			},
			distributed: {
				type: 'unknown',
				backend: 'Unknown',
				available: false,
			},
			performance: {
				averageHitTime: 0,
				averageMissTime: 0,
				performanceGain: 0,
				optimalHitRate: 85.0,
				currentTrend: 'unknown',
			},
			names: {
				cache_size: 0,
				hit_rate: 0.0,
				hits: 0,
				misses: 0,
				warmups: 0,
				enabled: false,
			},
			lastUpdated: null,
			unavailable: true,
			errorMessage: 'Loading...',
		},

		// SOLR dashboard data
		solrDashboardStats: {
			available: false,
			connection_status: 'unknown',
			document_count: 0,
			index_size: 0,
			collection: 'openregister',
			tenant_id: '',
			health: 'unknown',
			last_modified: null,
		},

		// Dialog states
		showRebaseConfirmation: false,

		// Connection status
		solrConnectionStatus: null,
	}),

	getters: {
		/**
		 * Check if there are any warning items requiring attention
		 * @param {object} state - The state of the stats
		 */
		hasWarnings: (state) => {
			const warnings = state.stats.warnings
			return Object.values(warnings).some(count => count > 0)
		},

		/**
		 * Get retention status information
		 * @param {object} state - The state of the retention settings
		 */
		retentionStatusClass: (state) => {
			const hasIssues = state.stats.warnings.auditTrailsWithoutExpiry > 0
							 || state.stats.warnings.searchTrailsWithoutExpiry > 0
							 || state.stats.warnings.expiredAuditTrails > 0
							 || state.stats.warnings.expiredSearchTrails > 0
							 || state.stats.warnings.expiredObjects > 0

			return hasIssues ? 'warning-status' : 'healthy-status'
		},

		retentionStatusTextClass: (state) => {
			const hasIssues = state.stats.warnings.auditTrailsWithoutExpiry > 0
							 || state.stats.warnings.searchTrailsWithoutExpiry > 0
							 || state.stats.warnings.expiredAuditTrails > 0
							 || state.stats.warnings.expiredSearchTrails > 0
							 || state.stats.warnings.expiredObjects > 0

			return hasIssues ? 'status-warning' : 'status-healthy'
		},

		retentionStatusMessage: (state) => {
			const warnings = state.stats.warnings
			const hasIssues = warnings.auditTrailsWithoutExpiry > 0
							 || warnings.searchTrailsWithoutExpiry > 0
							 || warnings.expiredAuditTrails > 0
							 || warnings.expiredSearchTrails > 0
							 || warnings.expiredObjects > 0

			if (hasIssues) {
				const issues = []
				if (warnings.auditTrailsWithoutExpiry > 0) issues.push(`${warnings.auditTrailsWithoutExpiry} audit trails without expiry`)
				if (warnings.searchTrailsWithoutExpiry > 0) issues.push(`${warnings.searchTrailsWithoutExpiry} search trails without expiry`)
				if (warnings.expiredAuditTrails > 0) issues.push(`${warnings.expiredAuditTrails} expired audit trails`)
				if (warnings.expiredSearchTrails > 0) issues.push(`${warnings.expiredSearchTrails} expired search trails`)
				if (warnings.expiredObjects > 0) issues.push(`${warnings.expiredObjects} expired objects`)

				return `Issues found: ${issues.join(', ')}`
			}

			return 'All retention policies are properly configured and applied'
		},
	},

	actions: {
		/**
		 * Load all settings data
		 */
		async loadSettings() {
			// Prevent multiple simultaneous calls
			if (this.loading && this.loadingInProgress) {
				return
			}

			this.loading = true
			this.loadingInProgress = true

			try {
				// Load all settings sections in parallel for better performance
				await Promise.allSettled([
					this.loadSolrSettings(),
					this.loadRbacSettings(),
					this.loadMultitenancySettings(),
					this.loadRetentionSettings(),
					this.loadVersionInfo(),
					this.loadAvailableOptions(),
				])

			} catch (error) {
				console.error('Failed to load settings:', error)
				showError('Failed to load settings: ' + error.message)
			} finally {
				this.loading = false
				this.loadingInProgress = false
			}
		},

		/**
		 * Load SOLR settings
		 */
		async loadSolrSettings() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/solr'))
				if (response.data) {
					// Ensure boolean fields are properly converted from API response
					const booleanFields = ['enabled', 'useCloud', 'autoCommit', 'enableLogging']
					const processedData = { ...response.data }

					booleanFields.forEach(field => {
						if (processedData[field] !== undefined) {
							processedData[field] = Boolean(processedData[field])
						}
					})

					// Convert scheme string to object format for NcSelect component
					// API returns "http" or "https", but NcSelect needs {id: "http", label: "HTTP"}
					if (processedData.scheme && typeof processedData.scheme === 'string') {
						const schemeOption = this.schemeOptions.find(opt => opt.id === processedData.scheme)
						if (schemeOption) {
							processedData.scheme = schemeOption
						}
					}

					this.solrOptions = { ...this.solrOptions, ...processedData }
				}
			} catch (error) {
				console.error('Failed to load SOLR settings:', error)
				// Don't show error - this is handled by individual components
			}
		},

		/**
		 * Update SOLR settings
		 * @param {object} solrData - The SOLR settings to save
		 */
		async updateSolrSettings(solrData) {
			this.saving = true
			try {
				// Normalize the data before sending to API
				const normalizedData = { ...solrData }

				// Handle scheme field - NcSelect returns {id: "http", label: "HTTP"} but we need just "http"
				if (normalizedData.scheme && typeof normalizedData.scheme === 'object' && normalizedData.scheme.id) {
					normalizedData.scheme = normalizedData.scheme.id
				}

				const response = await axios.put(
					generateUrl('/apps/openregister/api/settings/solr'),
					normalizedData,
				)

				if (response.data) {
					// Ensure boolean fields are properly converted from API response
					const booleanFields = ['enabled', 'useCloud', 'autoCommit', 'enableLogging']
					const processedData = { ...response.data }

					booleanFields.forEach(field => {
						if (processedData[field] !== undefined) {
							processedData[field] = Boolean(processedData[field])
						}
					})

					this.solrOptions = { ...this.solrOptions, ...processedData }
				}

				showSuccess('SOLR settings updated successfully')
				return response.data
			} catch (error) {
				console.error('Failed to update SOLR settings:', error)
				showError('Failed to update SOLR settings: ' + error.message)
				throw error
			} finally {
				this.saving = false
			}
		},

		/**
		 * Warmup SOLR index
		 * @param {object} options - The options for the warmup operation
		 */
		async warmupSolrIndex(options = {}) {
			this.warmingUpSolr = true
			try {
				const response = await axios.post(
					generateUrl('/apps/openregister/api/settings/solr/warmup'),
					{
						batchSize: options.batchSize || 2000,
						maxObjects: options.maxObjects || 0,
						mode: options.mode || 'serial',
						collectErrors: options.collectErrors || false,
						selectedSchemas: options.selectedSchemas || [],
					},
				)

				if (response.data.success) {
					showSuccess('SOLR index warmup completed successfully')
				} else {
					showError('SOLR warmup failed: ' + response.data.message)
				}

				return response.data
			} catch (error) {
				console.error('SOLR warmup failed:', error)
				showError('SOLR warmup failed: ' + error.message)
				throw error
			} finally {
				this.warmingUpSolr = false
			}
		},

		/**
		 * Mass validate objects with advanced configuration
		 * @param {object} options - The options for the mass validate operation
		 */
		async massValidate(options = {}) {
			this.massValidating = true
			this.massValidateResults = null

			try {
				const response = await axios.post(
					generateUrl('/apps/openregister/api/settings/mass-validate'),
					{
						batchSize: options.batchSize || 1000,
						maxObjects: options.maxObjects || 0,
						mode: options.mode || 'serial',
						collectErrors: options.collectErrors || false,
					},
				)

				this.massValidateResults = response.data

				if (response.data.success) {
					showSuccess('Mass validation completed successfully')
				} else {
					showError('Mass validation failed: ' + response.data.message)
				}

				return response.data
			} catch (error) {
				console.error('Mass validation failed:', error)
				const errorMessage = error.response?.data?.message || error.message
				showError('Mass validation failed: ' + errorMessage)

				this.massValidateResults = {
					success: false,
					message: errorMessage,
					error: errorMessage,
					stats: {
						total_objects: 0,
						processed_objects: 0,
						successful_saves: 0,
						failed_saves: 0,
						duration_seconds: 0,
					},
					errors: [],
				}

				throw error
			} finally {
				this.massValidating = false
			}
		},

		/**
		 * Load memory prediction for mass validation
		 * @param {number} maxObjects - The maximum number of objects to validate
		 */
		async loadMassValidateMemoryPrediction(maxObjects = 0) {
			try {
				const response = await axios.post(
					generateUrl('/apps/openregister/api/settings/mass-validate/memory-prediction'),
					{ maxObjects },
				)

				return response.data
			} catch (error) {
				console.error('Failed to load memory prediction:', error)
				return {
					success: false,
					prediction_safe: true,
					formatted: {
						total_predicted: 'Unknown',
						available: 'Unknown',
					},
				}
			}
		},

		/**
		 * Show mass validate confirmation dialog
		 */
		showMassValidateDialog() {
			this.showMassValidateConfirmation = true
		},

		/**
		 * Hide mass validate confirmation dialog
		 */
		hideMassValidateDialog() {
			this.showMassValidateConfirmation = false
			this.massValidateResults = null
		},

		/**
		 * Confirm mass validate operation
		 * @param {object} options - The options for the mass validate operation
		 */
		async confirmMassValidate(options = {}) {
			this.hideMassValidateDialog()
			return this.massValidate(options)
		},

		/**
		 * Load RBAC settings
		 */
		async loadRbacSettings() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/rbac'))
				if (response.data) {
					this.rbacOptions = { ...this.rbacOptions, ...response.data.rbac }
					if (response.data.availableGroups) {
						this.groupOptions = Object.entries(response.data.availableGroups).map(([id, label]) => ({ id, label }))
					}
					if (response.data.availableUsers) {
						this.userOptions = Object.entries(response.data.availableUsers).map(([id, label]) => ({ id, label }))
					}
				}
			} catch (error) {
				console.error('Failed to load RBAC settings:', error)
			}
		},

		/**
		 * Update RBAC settings
		 * @param {object} rbacData - The RBAC settings to save
		 */
		async updateRbacSettings(rbacData) {
			this.saving = true
			try {
				const response = await axios.put(
					generateUrl('/apps/openregister/api/settings/rbac'),
					rbacData,
				)

				if (response.data) {
					this.rbacOptions = { ...this.rbacOptions, ...response.data.rbac }
				}

				showSuccess('RBAC settings updated successfully')
				return response.data
			} catch (error) {
				console.error('Failed to update RBAC settings:', error)
				showError('Failed to update RBAC settings: ' + error.message)
				throw error
			} finally {
				this.saving = false
			}
		},

		/**
		 * Load Multitenancy settings
		 */
		async loadMultitenancySettings() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/multitenancy'))
				if (response.data) {
					this.multitenancyOptions = { ...this.multitenancyOptions, ...response.data.multitenancy }
					if (response.data.availableTenants) {
						this.tenantOptions = Object.entries(response.data.availableTenants).map(([id, label]) => ({ id, label }))
					}
				}
			} catch (error) {
				console.error('Failed to load Multitenancy settings:', error)
			}
		},

		/**
		 * Update Multitenancy settings
		 * @param {object} multitenancyData - The multitenancy settings to save
		 */
		async updateMultitenancySettings(multitenancyData) {
			this.saving = true
			try {
				const response = await axios.put(
					generateUrl('/apps/openregister/api/settings/multitenancy'),
					multitenancyData,
				)

				if (response.data) {
					this.multitenancyOptions = { ...this.multitenancyOptions, ...response.data.multitenancy }
				}

				showSuccess('Multitenancy settings updated successfully')
				return response.data
			} catch (error) {
				console.error('Failed to update Multitenancy settings:', error)
				showError('Failed to update Multitenancy settings: ' + error.message)
				throw error
			} finally {
				this.saving = false
			}
		},

		/**
		 * Load Retention settings
		 */
		async loadRetentionSettings() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/retention'))
				if (response.data) {
					this.retentionOptions = { ...this.retentionOptions, ...response.data }
				}
			} catch (error) {
				console.error('Failed to load Retention settings:', error)
			}
		},

		/**
		 * Update Retention settings
		 * @param {object} retentionData - The retention settings to save
		 */
		async updateRetentionSettings(retentionData) {
			this.saving = true
			try {
				const response = await axios.put(
					generateUrl('/apps/openregister/api/settings/retention'),
					retentionData,
				)

				if (response.data) {
					this.retentionOptions = { ...this.retentionOptions, ...response.data }
				}

				showSuccess('Retention settings updated successfully')
				return response.data
			} catch (error) {
				console.error('Failed to update Retention settings:', error)
				showError('Failed to update Retention settings: ' + error.message)
				throw error
			} finally {
				this.saving = false
			}
		},

		/**
		 * Get LLM settings
		 */
		async getLlmSettings() {
			try {
				this.loadingLlmSettings = true
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/llm'))
				if (response.data) {
					this.llmOptions = { ...this.llmOptions, ...response.data }
					return this.llmOptions
				}
			} catch (error) {
				console.error('Failed to load LLM settings:', error)
				return this.llmOptions
			} finally {
				this.loadingLlmSettings = false
			}
		},

		/**
		 * Save LLM settings (full update - use patchLlmSettings for partial updates)
		 * @param {object} llmData - The LLM settings to save
		 */
		async saveLlmSettings(llmData) {
			try {
				// Use PATCH instead of PUT for better partial update support
				const response = await axios.patch(
					generateUrl('/apps/openregister/api/settings/llm'),
					llmData,
				)

				if (response.data) {
					this.llmOptions = { ...this.llmOptions, ...response.data }
				}

				showSuccess('LLM settings saved successfully')
				return response.data
			} catch (error) {
				console.error('Failed to save LLM settings:', error)
				showError('Failed to save LLM settings: ' + error.message)
				throw error
			}
		},

		/**
		 * Patch LLM settings (partial update)
		 * @param {object} partialData - Partial LLM data to update
		 */
		async patchLlmSettings(partialData) {
			try {
				const response = await axios.patch(
					generateUrl('/apps/openregister/api/settings/llm'),
					partialData,
				)

				if (response.data && response.data.data) {
					this.llmOptions = { ...this.llmOptions, ...response.data.data }
				}

				// Show success message only if not just toggling enabled
				if (Object.keys(partialData).length > 1 || !Object.prototype.hasOwnProperty.call(partialData, 'enabled')) {
					showSuccess('LLM settings updated successfully')
				}

				return response.data
			} catch (error) {
				console.error('Failed to update LLM settings:', error)
				showError('Failed to update LLM settings: ' + error.message)
				throw error
			}
		},

		/**
		 * Get vector statistics
		 * @return {Promise<object>} Vector statistics including counts by type
		 */
		async getVectorStats() {
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/vectors/stats'),
				)
				const stats = response.data
				this.vectorStats = stats // Cache in state
				return stats
			} catch (error) {
				console.error('Failed to load vector statistics:', error)
				// Return empty stats instead of throwing to prevent UI breakage
				const emptyStats = {
					total_vectors: 0,
					by_type: { object: 0, file: 0 },
					connection_status: 'Error',
				}
				this.vectorStats = emptyStats
				return emptyStats
			}
		},

		/**
		 * Test LLM connection
		 * @param {object} connectionData - The connection data to test
		 */
		async testLlmConnection(connectionData) {
			try {
				const response = await axios.post(
					generateUrl('/apps/openregister/api/settings/llm/test'),
					connectionData,
				)
				return response.data
			} catch (error) {
				console.error('Failed to test LLM connection:', error)
				throw error
			}
		},

		/**
		 * Get LLM usage statistics
		 */
		async getLlmUsageStats() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/llm/usage'))
				return response.data
			} catch (error) {
				console.error('Failed to load LLM usage stats:', error)
				return null
			}
		},

		/**
		 * Get file settings
		 */
		async getFileSettings() {
			try {
				this.loadingFileSettings = true
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/files'))
				if (response.data) {
					this.fileOptions = { ...this.fileOptions, ...response.data }
					return this.fileOptions
				}
			} catch (error) {
				console.error('Failed to load file settings:', error)
				return this.fileOptions
			} finally {
				this.loadingFileSettings = false
			}
		},

		/**
		 * Save file settings
		 * @param {object} fileData - The file settings to save
		 */
		async saveFileSettings(fileData) {
			try {
				const response = await axios.put(
					generateUrl('/apps/openregister/api/settings/files'),
					fileData,
				)

				if (response.data) {
					this.fileOptions = { ...this.fileOptions, ...response.data }
				}

				showSuccess('File settings saved successfully')
				return response.data
			} catch (error) {
				console.error('Failed to save file settings:', error)
				showError('Failed to save file settings: ' + error.message)
				throw error
			}
		},

		/**
		 * Get object text extraction settings
		 */
		async getObjectSettings() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/objects'))
				if (response.data) {
					return response.data
				}
				return {}
			} catch (error) {
				console.error('Failed to load object settings:', error)
				return {}
			}
		},

		/**
		 * Save object text extraction settings
		 * @param {object} objectData - The object settings to save
		 */
		async saveObjectSettings(objectData) {
			try {
				const response = await axios.put(
					generateUrl('/apps/openregister/api/settings/objects'),
					objectData,
				)

				if (response.data) {
					showSuccess('Object settings saved successfully')
				}

				return response.data
			} catch (error) {
				console.error('Failed to save object settings:', error)
				showError('Failed to save object settings: ' + error.message)
				throw error
			}
		},

		/**
		 * Get file extraction statistics
		 */
		async getExtractionStats() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/files/stats'))
				const stats = response.data.data || response.data
				this.extractionStats = stats // Cache in state
				return stats
			} catch (error) {
				console.error('Failed to load extraction stats:', error)
				return null
			}
		},

		/**
		 * Discover files in Nextcloud that aren't tracked yet
		 */
		async discoverFiles() {
			try {
				const response = await axios.post(
					generateUrl('/apps/openregister/api/files/discover'),
					{ limit: 100 },
				)
				return response.data
			} catch (error) {
				console.error('Failed to discover files:', error)
				showError('Failed to discover files: ' + error.message)
				throw error
			}
		},

		/**
		 * Trigger file extraction for pending or failed files
		 * @param {string} type - 'pending' or 'failed'
		 */
		async triggerFileExtraction(type = 'pending') {
			try {
			// Use new core file extraction endpoints
				const endpoint = type === 'failed'
					? '/apps/openregister/api/files/retry-failed'
					: '/apps/openregister/api/files/extract'

				const response = await axios.post(generateUrl(endpoint), { limit: 100 })
				return response.data
			} catch (error) {
				console.error(`Failed to trigger ${type} file extraction:`, error)
				showError(`Failed to start processing ${type} files: ` + error.message)
				throw error
			}
		},

		/**
		 * Test Dolphin API connection
		 * @param {object} connectionData - API endpoint and key
		 */
		async testDolphinConnection(connectionData) {
			try {
				const response = await axios.post(
					generateUrl('/apps/openregister/api/settings/files/test-dolphin'),
					connectionData,
				)
				return response.data
			} catch (error) {
				console.error('Failed to test Dolphin connection:', error)
				return {
					success: false,
					error: error.response?.data?.error || error.message,
				}
			}
		},

		/**
		 * Load version information
		 */
		async loadVersionInfo() {
			try {
				this.loadingVersionInfo = true
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/version'))
				if (response.data) {
					this.versionInfo = { ...this.versionInfo, ...response.data }
				}
			} catch (error) {
				console.error('Failed to load version info:', error)
			} finally {
				this.loadingVersionInfo = false
			}
		},

		/**
		 * Load available options (groups, users, tenants)
		 */
		async loadAvailableOptions() {
			try {
				// These are loaded as part of the individual settings sections
				// This method exists for consistency and future extensibility
			} catch (error) {
				console.error('Failed to load available options:', error)
			}
		},

		/**
		 * Load system statistics
		 */
		async loadStats() {
			this.loadingStats = true
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/statistics'))
				if (response.data) {
					this.stats = { ...this.stats, ...response.data }
					this.stats.lastUpdated = new Date().toISOString()
				}
			} catch (error) {
				console.error('Failed to load statistics:', error)
				showError('Failed to load statistics: ' + error.message)
			} finally {
				this.loadingStats = false
			}
		},

		/**
		 * Load cache statistics
		 */
		async loadCacheStats() {
			this.loadingCacheStats = true
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/cache'))
				if (response.data) {
					this.cacheStats = { ...this.cacheStats, ...response.data }
					this.cacheStats.lastUpdated = new Date().toISOString()
				}
			} catch (error) {
				console.error('Failed to load cache statistics:', error)
				showError('Failed to load cache statistics: ' + error.message)
			} finally {
				this.loadingCacheStats = false
			}
		},

		/**
		 * Get chat and agent statistics
		 * @return {Promise<object>} Chat statistics including agents, conversations, and messages
		 */
		async getChatStats() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/chat/stats'))
				return response.data
			} catch (error) {
				console.error('Failed to load chat statistics:', error)
				// Return empty stats if API not available yet
				return {
					total_agents: 0,
					total_conversations: 0,
					total_messages: 0,
				}
			}
		},

		/**
		 * Clear specific cache type
		 * @param {string} type - The type of cache to clear
		 */
		async clearSpecificCache(type) {
			this.clearingCache = type
			try {
				const response = await axios.delete(generateUrl('/apps/openregister/api/settings/cache'), {
					data: { type },
				})

				if (response.data.success !== false) {
					showSuccess(`${type} cache cleared successfully`)
					// Reload cache stats to reflect changes
					await this.loadCacheStats()
				} else {
					showError(`Failed to clear ${type} cache: ` + (response.data.message || 'Unknown error'))
				}

				return response.data
			} catch (error) {
				console.error(`Failed to clear ${type} cache:`, error)
				showError(`Failed to clear ${type} cache: ` + error.message)
				throw error
			} finally {
				this.clearingCache = false
			}
		},

		/**
		 * Clear all caches
		 */
		async clearAllCaches() {
			return this.clearSpecificCache('all')
		},

		/**
		 * Warmup names cache
		 */
		async warmupNamesCache() {
			this.warmingUpCache = true
			try {
				const response = await axios.post(generateUrl('/apps/openregister/api/settings/cache/warmup-names'))

				if (response.data.success) {
					showSuccess('Names cache warmed up successfully')
				} else {
					showError('Failed to warmup names cache: ' + (response.data.error || 'Unknown error'))
				}

				// Reload cache stats to reflect changes
				await this.loadCacheStats()

				return response.data
			} catch (error) {
				console.error('Failed to warmup names cache:', error)
				showError('Failed to warmup names cache: ' + error.message)
				throw error
			} finally {
				this.warmingUpCache = false
			}
		},

		/**
		 * Rebase all objects and logs
		 */
		async rebase() {
			this.rebasing = true
			try {
				const response = await axios.post(generateUrl('/apps/openregister/api/settings/rebase'))

				if (response.data.success !== false) {
					showSuccess('Rebase operation completed successfully')
					// Reload statistics to reflect changes
					await this.loadStats()
				} else {
					showError('Rebase operation failed: ' + (response.data.message || 'Unknown error'))
				}

				return response.data
			} catch (error) {
				console.error('Rebase operation failed:', error)
				showError('Rebase operation failed: ' + error.message)
				throw error
			} finally {
				this.rebasing = false
			}
		},

		/**
		 * Save general settings (legacy method for backwards compatibility)
		 * @param {object} data - The data to save
		 */
		async saveSettings(data) {
			this.saving = true
			try {
				// Route to appropriate specific save method based on data content
				if (data.solr) {
					return await this.updateSolrSettings(data.solr)
				} else if (data.rbac) {
					return await this.updateRbacSettings(data.rbac)
				} else if (data.multitenancy) {
					return await this.updateMultitenancySettings(data.multitenancy)
				} else if (data.retention) {
					return await this.updateRetentionSettings(data.retention)
				} else {
					// Fallback to legacy endpoint
					const response = await axios.put(generateUrl('/apps/openregister/api/settings'), data)
					showSuccess('Settings updated successfully')
					return response.data
				}
			} catch (error) {
				console.error('Failed to save settings:', error)
				showError('Failed to save settings: ' + error.message)
				throw error
			} finally {
				this.saving = false
			}
		},

		/**
		 * Show rebase confirmation dialog
		 */
		showRebaseDialog() {
			this.showRebaseConfirmation = true
		},

		/**
		 * Hide rebase confirmation dialog
		 */
		hideRebaseDialog() {
			this.showRebaseConfirmation = false
		},

		/**
		 * Confirm and execute rebase
		 */
		async confirmRebase() {
			this.hideRebaseDialog()
			await this.rebase()
		},

		/**
		 * Clear cache of specified type
		 * @param {string} type - The type of cache to clear
		 */
		async clearCache(type = 'all') {
			this.clearingCache = true

			try {
				const response = await axios.delete(generateUrl('/apps/openregister/api/settings/cache'), {
					data: { type },
				})

				if (response.data.success) {
					// Reload cache stats after clearing
					await this.loadCacheStats()
				}
			} catch (error) {
				console.error('Failed to clear cache:', error)
				showError('Failed to clear cache: ' + error.message)
			} finally {
				this.clearingCache = false
			}
		},

		/**
		 * Show clear audit trails confirmation dialog
		 */
		showClearAuditTrailsDialog() {
			this.showClearAuditTrailsConfirmation = true
		},

		/**
		 * Hide clear audit trails confirmation dialog
		 */
		hideClearAuditTrailsDialog() {
			this.showClearAuditTrailsConfirmation = false
		},

		/**
		 * Clear all audit trails
		 */
		async clearAllAuditTrails() {
			this.clearingAuditTrails = true

			try {
				const response = await axios.delete(generateUrl('/apps/openregister/api/audit-trails/clear-all'))

				if (response.data.success) {
					showSuccess(`Successfully cleared ${response.data.deleted || 0} audit trails`)
					this.hideClearAuditTrailsDialog()
				} else {
					showError('Failed to clear audit trails: ' + (response.data.error || 'Unknown error'))
				}
			} catch (error) {
				console.error('Failed to clear audit trails:', error)
				showError('Failed to clear audit trails: ' + error.message)
			} finally {
				this.clearingAuditTrails = false
			}
		},

		/**
		 * Show clear search trails confirmation dialog
		 */
		showClearSearchTrailsDialog() {
			this.showClearSearchTrailsConfirmation = true
		},

		/**
		 * Hide clear search trails confirmation dialog
		 */
		hideClearSearchTrailsDialog() {
			this.showClearSearchTrailsConfirmation = false
		},

		/**
		 * Clear all search trails
		 */
		async clearAllSearchTrails() {
			this.clearingSearchTrails = true

			try {
				const response = await axios.delete(generateUrl('/apps/openregister/api/search-trails/clear-all'))

				if (response.data.success) {
					showSuccess(`Successfully cleared ${response.data.deleted || 0} search trails`)
					this.hideClearSearchTrailsDialog()
				} else {
					showError('Failed to clear search trails: ' + (response.data.error || 'Unknown error'))
				}
			} catch (error) {
				console.error('Failed to clear search trails:', error)
				showError('Failed to clear search trails: ' + error.message)
			} finally {
				this.clearingSearchTrails = false
			}
		},

		/**
		 * Show clear cache confirmation dialog
		 */
		showClearCacheDialog() {
			this.showClearCacheConfirmation = true
		},

		/**
		 * Hide clear cache confirmation dialog
		 */
		hideClearCacheDialog() {
			this.showClearCacheConfirmation = false
		},

		/**
		 * Perform cache clearing with current type selection
		 */
		async performClearCache() {
			await this.clearCache(this.clearCacheType)
			this.hideClearCacheDialog()
		},

		/**
		 * Retry test connection
		 */
		retryTest() {
			this.testSolrConnection()
		},

		/**
		 * Load SOLR field configuration
		 */
		async loadSolrFields() {
			this.loadingFields = true
			this.showFieldsDialog = true
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/solr/fields'))
				this.fieldsInfo = response.data
				this.fieldComparison = response.data.comparison || null
				return response.data
			} catch (error) {
				console.error('Failed to load SOLR fields:', error)
				const errorData = {
					success: false,
					message: 'Failed to load SOLR fields: ' + error.message,
					details: { error: error.message },
				}
				this.fieldsInfo = errorData
				throw error
			} finally {
				this.loadingFields = false
			}
		},

		/**
		 * Hide fields dialog
		 */
		hideFieldsDialog() {
			this.showFieldsDialog = false
			this.fieldsInfo = null
			this.fieldComparison = null
			this.fieldCreationResult = null
		},

		setCreatingFields(creating) {
			this.creatingFields = creating
		},

		setFixingFields(fixing) {
			this.fixingFields = fixing
		},

		setFieldCreationResult(result) {
			this.fieldCreationResult = result
		},

		/**
		 * Create missing SOLR fields
		 * @param {boolean} dryRun - Whether to run the operation in dry run mode
		 */
		async createMissingSolrFields(dryRun = false) {
			this.creatingFields = true
			this.fieldCreationResult = null
			try {
				const payload = {
					dry_run: dryRun,
				}

				const response = await axios.post(generateUrl('/apps/openregister/api/solr/fields/create-missing'), payload)
				this.fieldCreationResult = response.data

				// If successful and not a dry run, reload the fields to show updated state
				if (response.data.success && !dryRun) {
					await this.loadSolrFields()
				}

				return response.data
			} catch (error) {
				console.error('Failed to create missing SOLR fields:', error)
				const result = {
					success: false,
					message: error.response?.data?.message || error.message,
					error: error.response?.data?.error || error.message,
				}
				this.fieldCreationResult = result
				return result
			} finally {
				this.creatingFields = false
			}
		},

		/**
		 * Fix mismatched SOLR field configurations
		 * @param {boolean} dryRun - Whether to run the operation in dry run mode
		 */
		async fixMismatchedSolrFields(dryRun = false) {
			this.fixingFields = true
			this.fieldCreationResult = null
			try {
				const payload = {
					dry_run: dryRun,
				}

				const response = await axios.post(generateUrl('/apps/openregister/api/solr/fields/fix-mismatches'), payload)
				this.fieldCreationResult = response.data

				// If successful and not a dry run, reload the fields to show updated state
				if (response.data.success && !dryRun) {
					await this.loadSolrFields()
				}

				return response.data
			} catch (error) {
				console.error('Failed to fix mismatched SOLR fields:', error)
				this.fieldCreationResult = {
					success: false,
					message: 'Failed to fix mismatched SOLR fields: ' + error.message,
					errors: [error.message],
				}
				throw error
			} finally {
				this.fixingFields = false
			}
		},

		// ========================================
		// SOLR Management Actions
		// ========================================

		/**
		 * Setup SOLR configuration
		 */
		async setupSolr() {
			this.settingUpSolr = true
			this.setupResults = null
			this.showSetupDialog = true

			try {
				const response = await axios.post(generateUrl('/apps/openregister/api/solr/setup'))

				this.setupResults = response.data

				if (response.data.success) {
					showSuccess('SOLR setup completed successfully!')
				} else {
					// Don't show error toast for propagation timeouts - the modal will handle it
					const isConfigSetPropagationError = response.data.error_details?.exception_message?.includes('ConfigSet propagation timeout')
					if (!isConfigSetPropagationError) {
						showError('SOLR setup failed: ' + (response.data.message || 'Unknown error'))
					}
				}

				return response.data
			} catch (error) {
				console.error('Failed to setup SOLR:', error)

				// Handle different error scenarios
				let errorMessage = 'Failed to setup SOLR: ' + error.message
				let setupResults = {
					success: false,
					message: errorMessage,
					timestamp: new Date().toISOString(),
					error_details: {
						primary_error: 'Setup operation failed',
						error_type: 'network_error',
						exception_message: error.message,
					},
				}

				// If we have response data from server, use that instead
				if (error.response?.data) {
					setupResults = error.response.data
					errorMessage = setupResults.message || errorMessage
				}

				this.setupResults = setupResults
				showError(errorMessage)
				throw error
			} finally {
				this.settingUpSolr = false
			}
		},

		/**
		 * Test SOLR connection
		 */
		async testSolrConnection() {
			this.testingConnection = true
			this.testResults = null
			this.showTestDialog = true

			try {
				const response = await axios.post(generateUrl('/apps/openregister/api/settings/solr/test'))
				this.testResults = response.data

				if (response.data.success) {
					showSuccess('SOLR connection test successful!')
				} else {
					showError('SOLR connection test failed: ' + (response.data.message || 'Unknown error'))
				}

				return response.data
			} catch (error) {
				console.error('Failed to test SOLR connection:', error)
				const errorMessage = 'Failed to test SOLR connection: ' + error.message
				this.testResults = {
					success: false,
					message: errorMessage,
					error: error.message,
				}
				showError(errorMessage)
				throw error
			} finally {
				this.testingConnection = false
			}
		},

		/**
		 * Hide setup dialog
		 */
		hideSetupDialog() {
			this.showSetupDialog = false
			this.setupResults = null
		},

		/**
		 * Hide test dialog
		 */
		hideTestDialog() {
			this.showTestDialog = false
			this.testResults = null
		},

		/**
		 * Retry setup
		 */
		retrySetup() {
			this.setupSolr()
		},
	},
})
