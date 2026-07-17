/**
 * OpenRegister Settings Store
 *
 * Centralized state management for all settings sections using Pinia.
 * This store handles data fetching, state management, and API calls for:
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
import { translate as t } from '@nextcloud/l10n'

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

		// Cache states
		clearingCache: false,
		warmingUpCache: false,
		showClearCacheConfirmation: false,
		clearCacheType: 'all',
		clearingAppStoreCache: false,
		warmupInterval: 3600,
		warmupLastRun: null,
		loadingWarmupInterval: false,
		savingWarmupInterval: false,

		// Mass validation states
		massValidating: false,
		showMassValidateConfirmation: false,
		massValidateResults: null,

		// Clear logs states
		clearingAuditTrails: false,
		clearingSearchTrails: false,
		clearingBlobObjects: false,
		showClearAuditTrailsConfirmation: false,
		showClearSearchTrailsConfirmation: false,
		showClearBlobObjectsConfirmation: false,

		// Settings data
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
				totalBlobObjects: 0,
				totalMagicObjects: 0,
				totalSize: 0,
				totalBlobSize: 0,
				totalMagicSize: 0,
				totalAuditTrails: 0,
				totalSearchTrails: 0,
				totalConfigurations: 0,
				totalDataAccessProfiles: 0,
				totalOrganisations: 0,
				totalRegisters: 0,
				totalSchemas: 0,
				totalSources: 0,
				totalWebhookLogs: 0,
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

		// Dialog states
		showRebaseConfirmation: false,
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
		 * @spec exclude parallel fan-out wrapper over settings-load passthroughs
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
					this.loadRbacSettings(),
					this.loadMultitenancySettings(),
					this.loadRetentionSettings(),
					this.loadVersionInfo(),
					this.loadAvailableOptions(),
				])

			} catch (error) {
				console.error('Failed to load settings:', error)
				showError(t('openregister', 'Failed to load settings: {error}', { error: error.message }))
			} finally {
				this.loading = false
				this.loadingInProgress = false
			}
		},

		/**
		 * Mass validate objects with advanced configuration
		 * @param {object} options - The options for the mass validate operation
		 * @spec exclude API passthrough to POST /api/settings/mass-validate
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
					showSuccess(t('openregister', 'Mass validation completed successfully'))
				} else {
					showError(t('openregister', 'Mass validation failed: {error}', { error: response.data.message }))
				}

				return response.data
			} catch (error) {
				console.error('Mass validation failed:', error)
				const errorMessage = error.response?.data?.message || error.message
				showError(t('openregister', 'Mass validation failed: {error}', { error: errorMessage }))

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
		 * @spec exclude API passthrough to POST /api/settings/mass-validate/memory-prediction
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
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		showMassValidateDialog() {
			this.showMassValidateConfirmation = true
		},

		/**
		 * Hide mass validate confirmation dialog
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		hideMassValidateDialog() {
			this.showMassValidateConfirmation = false
			this.massValidateResults = null
		},

		/**
		 * Confirm mass validate operation
		 * @param {object} options - The options for the mass validate operation
		 * @spec exclude dialog-confirm wrapper over massValidate (API passthrough)
		 */
		async confirmMassValidate(options = {}) {
			this.hideMassValidateDialog()
			return this.massValidate(options)
		},

		/**
		 * Load RBAC settings
		 * @spec exclude API passthrough to GET /api/settings/rbac
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
		 * @spec exclude API passthrough to PUT /api/settings/rbac
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

				showSuccess(t('openregister', 'RBAC settings updated successfully'))
				return response.data
			} catch (error) {
				console.error('Failed to update RBAC settings:', error)
				showError(t('openregister', 'Failed to update RBAC settings: {error}', { error: error.message }))
				throw error
			} finally {
				this.saving = false
			}
		},

		/**
		 * Load Multitenancy settings
		 * @spec exclude API passthrough to GET /api/settings/multitenancy
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
		 * @spec exclude API passthrough to PUT /api/settings/multitenancy
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

				showSuccess(t('openregister', 'Multitenancy settings updated successfully'))
				return response.data
			} catch (error) {
				console.error('Failed to update Multitenancy settings:', error)
				showError(t('openregister', 'Failed to update Multitenancy settings: {error}', { error: error.message }))
				throw error
			} finally {
				this.saving = false
			}
		},

		/**
		 * Load Retention settings
		 * @spec exclude API passthrough to GET /api/settings/retention
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
		 * @spec exclude API passthrough to PUT /api/settings/retention
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

				showSuccess(t('openregister', 'Retention settings updated successfully'))
				return response.data
			} catch (error) {
				console.error('Failed to update Retention settings:', error)
				showError(t('openregister', 'Failed to update Retention settings: {error}', { error: error.message }))
				throw error
			} finally {
				this.saving = false
			}
		},

		/**
		 * Get LLM settings
		 * @spec exclude API passthrough to GET /api/settings/llm
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
		 * @spec exclude API passthrough to PATCH /api/settings/llm
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

				showSuccess(t('openregister', 'LLM settings saved successfully'))
				return response.data
			} catch (error) {
				console.error('Failed to save LLM settings:', error)
				showError(t('openregister', 'Failed to save LLM settings: {error}', { error: error.message }))
				throw error
			}
		},

		/**
		 * Patch LLM settings (partial update)
		 * @param {object} partialData - Partial LLM data to update
		 * @spec exclude API passthrough to PATCH /api/settings/llm (partial)
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
					showSuccess(t('openregister', 'LLM settings updated successfully'))
				}

				return response.data
			} catch (error) {
				console.error('Failed to update LLM settings:', error)
				showError(t('openregister', 'Failed to update LLM settings: {error}', { error: error.message }))
				throw error
			}
		},

		/**
		 * Get vector statistics
		 * @return {Promise<object>} Vector statistics including counts by type
		 * @spec exclude API passthrough to GET /api/vectors/stats (stat loader)
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
		 * @spec exclude API passthrough to POST /api/settings/llm/test
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
		 * @spec exclude API passthrough to GET /api/settings/llm/usage
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
		 * @spec exclude API passthrough to GET /api/settings/files
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
		 * @spec exclude API passthrough to PUT /api/settings/files
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

				showSuccess(t('openregister', 'File settings saved successfully'))
				return response.data
			} catch (error) {
				console.error('Failed to save file settings:', error)
				showError(t('openregister', 'Failed to save file settings: {error}', { error: error.message }))
				throw error
			}
		},

		/**
		 * Get file extraction statistics
		 * @spec exclude API passthrough to GET /api/files/stats (stat loader)
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
		 * @spec exclude API passthrough to POST /api/files/discover
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
				showError(t('openregister', 'Failed to discover files: {error}', { error: error.message }))
				throw error
			}
		},

		/**
		 * Trigger file extraction for pending or failed files
		 * @param {string} type - 'pending' or 'failed'
		 * @spec exclude API passthrough to POST /api/files/extract|retry-failed
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
				showError(t('openregister', 'Failed to start processing {type} files: {error}', { type, error: error.message }))
				throw error
			}
		},

		/**
		 * Test Dolphin API connection
		 * @param {object} connectionData - API endpoint and key
		 * @spec exclude API passthrough to POST /api/settings/files/test-dolphin
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
		 * Test Presidio API connection
		 * @param {object} connectionData - API endpoint
		 * @spec exclude API passthrough to POST /api/settings/files/test-presidio
		 */
		async testPresidioConnection(connectionData) {
			try {
				const response = await axios.post(
					generateUrl('/apps/openregister/api/settings/files/test-presidio'),
					connectionData,
				)
				return response.data
			} catch (error) {
				console.error('Failed to test Presidio connection:', error)
				return {
					success: false,
					error: error.response?.data?.error || error.message,
				}
			}
		},

		/**
		 * Test OpenAnonymiser API connection
		 * @param {object} connectionData - API endpoint
		 * @spec exclude API passthrough to POST /api/settings/files/test-openanonymiser
		 */
		async testOpenAnonymiserConnection(connectionData) {
			try {
				const response = await axios.post(
					generateUrl('/apps/openregister/api/settings/files/test-openanonymiser'),
					connectionData,
				)
				return response.data
			} catch (error) {
				console.error('Failed to test OpenAnonymiser connection:', error)
				return {
					success: false,
					error: error.response?.data?.error || error.message,
				}
			}
		},

		/**
		 * Get the resolved anonymisation backend state (single source of truth).
		 * @spec exclude API passthrough to GET /api/admin/anonymisation/backend-state
		 */
		async getAnonymisationBackendState() {
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/admin/anonymisation/backend-state'),
				)
				return response.data
			} catch (error) {
				console.error('Failed to load anonymisation backend state:', error)
				return null
			}
		},

		/**
		 * Probe a single anonymisation backend, bypassing the cache.
		 * @param {string} method - One of regex/presidio/openanonymiser/llm/hybrid
		 * @spec exclude API passthrough to POST /api/admin/anonymisation/test-connection
		 */
		async testAnonymisationBackend(method) {
			try {
				const response = await axios.post(
					generateUrl('/apps/openregister/api/admin/anonymisation/test-connection'),
					{ method },
				)
				return response.data
			} catch (error) {
				console.error('Failed to probe anonymisation backend:', error)
				return {
					reachable: false,
					latencyMs: null,
					error: error.response?.data?.error || error.message,
					probedAt: null,
				}
			}
		},

		/**
		 * Load version information
		 * @spec exclude API passthrough to GET /api/settings/version
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
		 * @spec exclude no-op placeholder (options loaded by sibling settings actions)
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
		 * @spec exclude API passthrough to GET /api/settings/statistics (stat loader)
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
				showError(t('openregister', 'Failed to load statistics: {error}', { error: error.message }))
			} finally {
				this.loadingStats = false
			}
		},

		/**
		 * Load cache statistics
		 * @spec exclude API passthrough to GET /api/settings/cache (stat loader)
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
				showError(t('openregister', 'Failed to load cache statistics: {error}', { error: error.message }))
			} finally {
				this.loadingCacheStats = false
			}
		},

		/**
		 * Get chat and agent statistics
		 * @return {Promise<object>} Chat statistics including agents, conversations, and messages
		 * @spec exclude API passthrough to GET /api/chat/stats
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
		 * @spec exclude API passthrough to DELETE /api/settings/cache
		 */
		async clearSpecificCache(type) {
			this.clearingCache = type
			try {
				const response = await axios.delete(generateUrl('/apps/openregister/api/settings/cache'), {
					data: { type },
				})

				if (response.data.success !== false) {
					showSuccess(t('openregister', '{type} cache cleared successfully', { type }))
					// Reload cache stats to reflect changes
					await this.loadCacheStats()
				} else {
					showError(t('openregister', 'Failed to clear {type} cache: {error}', { type, error: response.data.message || 'Unknown error' }))
				}

				return response.data
			} catch (error) {
				console.error(`Failed to clear ${type} cache:`, error)
				showError(t('openregister', 'Failed to clear {type} cache: {error}', { type, error: error.message }))
				throw error
			} finally {
				this.clearingCache = false
			}
		},

		/**
		 * Clear all caches
		 * @spec exclude convenience wrapper over clearSpecificCache (API passthrough)
		 */
		async clearAllCaches() {
			return this.clearSpecificCache('all')
		},

		/**
		 * Warmup names cache
		 * @spec exclude API passthrough to POST /api/settings/cache/warmup-names
		 */
		async warmupNamesCache() {
			this.warmingUpCache = true
			try {
				const response = await axios.post(generateUrl('/apps/openregister/api/settings/cache/warmup-names'))

				if (response.data.success) {
					const loadedCount = response.data.loaded_names || 0
					const executionTime = response.data.execution_time || '0ms'
					const oldCacheSize = response.data.old_cache?.distributed_name_cache_size || 0
					const newCacheSize = response.data.new_cache?.distributed_name_cache_size || 0

					let cacheMessage = ''
					if (newCacheSize > oldCacheSize) {
						cacheMessage = t('openregister', 'Cache grew from {old} to {new} entries.', { old: oldCacheSize, new: newCacheSize })
					} else if (newCacheSize < oldCacheSize) {
						cacheMessage = t('openregister', 'Cache shrunk from {old} to {new} entries.', { old: oldCacheSize, new: newCacheSize })
					} else {
						cacheMessage = t('openregister', 'Cache stayed the same at {size} entries.', { size: newCacheSize })
					}

					showSuccess(t('openregister', 'Names cache warmed up successfully: {count} names loaded in {time}. {message}', { count: loadedCount, time: executionTime, message: cacheMessage }))
				} else {
					showError(t('openregister', 'Failed to warmup names cache: {error}', { error: response.data.error || 'Unknown error' }))
				}

				// Reload cache stats to reflect changes.
				await this.loadCacheStats()

				return response.data
			} catch (error) {
				console.error('Failed to warmup names cache:', error)
				showError(t('openregister', 'Failed to warmup names cache: {error}', { error: error.message }))
				throw error
			} finally {
				this.warmingUpCache = false
			}
		},

		/**
		 * Load cache warmup interval setting
		 * @spec exclude API passthrough to GET /api/settings/cache/warmup-interval
		 */
		async loadWarmupInterval() {
			this.loadingWarmupInterval = true
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/cache/warmup-interval'))
				if (response.data) {
					this.warmupInterval = response.data.interval ?? 3600
					this.warmupLastRun = response.data.last_run ?? null
				}
				return response.data
			} catch (error) {
				console.error('Failed to load warmup interval:', error)
			} finally {
				this.loadingWarmupInterval = false
			}
		},

		/**
		 * Save cache warmup interval setting
		 * @param {number} interval - The interval in seconds (0 = disabled)
		 * @spec exclude API passthrough to PUT /api/settings/cache/warmup-interval
		 */
		async saveWarmupInterval(interval) {
			this.savingWarmupInterval = true
			try {
				const response = await axios.put(
					generateUrl('/apps/openregister/api/settings/cache/warmup-interval'),
					{ interval },
				)

				if (response.data.success) {
					this.warmupInterval = response.data.interval
					showSuccess(response.data.message)
				} else {
					showError(t('openregister', 'Failed to save warmup interval: {error}', { error: response.data.error || 'Unknown error' }))
				}

				return response.data
			} catch (error) {
				console.error('Failed to save warmup interval:', error)
				showError(t('openregister', 'Failed to save warmup interval: {error}', { error: error.message }))
				throw error
			} finally {
				this.savingWarmupInterval = false
			}
		},

		/**
		 * Rebase all objects and logs
		 * @spec exclude API passthrough to POST /api/settings/rebase
		 */
		async rebase() {
			this.rebasing = true
			try {
				const response = await axios.post(generateUrl('/apps/openregister/api/settings/rebase'))

				if (response.data.success !== false) {
					showSuccess(t('openregister', 'Rebase operation completed successfully'))
					// Reload statistics to reflect changes
					await this.loadStats()
				} else {
					showError(t('openregister', 'Rebase operation failed: {error}', { error: response.data.message || 'Unknown error' }))
				}

				return response.data
			} catch (error) {
				console.error('Rebase operation failed:', error)
				showError(t('openregister', 'Rebase operation failed: {error}', { error: error.message }))
				throw error
			} finally {
				this.rebasing = false
			}
		},

		/**
		 * Save general settings (legacy method for backwards compatibility)
		 * @param {object} data - The data to save
		 * @spec exclude dispatcher over update*Settings passthroughs + legacy PUT /api/settings
		 */
		async saveSettings(data) {
			this.saving = true
			try {
				// Route to appropriate specific save method based on data content
				if (data.rbac) {
					return await this.updateRbacSettings(data.rbac)
				} else if (data.multitenancy) {
					return await this.updateMultitenancySettings(data.multitenancy)
				} else if (data.retention) {
					return await this.updateRetentionSettings(data.retention)
				} else {
					// Fallback to legacy endpoint
					const response = await axios.put(generateUrl('/apps/openregister/api/settings'), data)
					showSuccess(t('openregister', 'Settings updated successfully'))
					return response.data
				}
			} catch (error) {
				console.error('Failed to save settings:', error)
				showError(t('openregister', 'Failed to save settings: {error}', { error: error.message }))
				throw error
			} finally {
				this.saving = false
			}
		},

		/**
		 * Show rebase confirmation dialog
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		showRebaseDialog() {
			this.showRebaseConfirmation = true
		},

		/**
		 * Hide rebase confirmation dialog
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		hideRebaseDialog() {
			this.showRebaseConfirmation = false
		},

		/**
		 * Confirm and execute rebase
		 * @spec exclude dialog-confirm wrapper over rebase (API passthrough)
		 */
		async confirmRebase() {
			this.hideRebaseDialog()
			await this.rebase()
		},

		/**
		 * Clear cache of specified type
		 * @param {string} type - The type of cache to clear
		 * @spec exclude API passthrough to DELETE /api/settings/cache
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
				showError(t('openregister', 'Failed to clear cache: {error}', { error: error.message }))
			} finally {
				this.clearingCache = false
			}
		},

		/**
		 * Show clear audit trails confirmation dialog
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		showClearAuditTrailsDialog() {
			this.showClearAuditTrailsConfirmation = true
		},

		/**
		 * Hide clear audit trails confirmation dialog
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		hideClearAuditTrailsDialog() {
			this.showClearAuditTrailsConfirmation = false
		},

		/**
		 * Clear all audit trails
		 * @spec exclude API passthrough to DELETE /api/audit-trails/clear-all
		 */
		async clearAllAuditTrails() {
			this.clearingAuditTrails = true

			try {
				const response = await axios.delete(generateUrl('/apps/openregister/api/audit-trails/clear-all'))

				if (response.data.success) {
					showSuccess(t('openregister', 'Successfully cleared {count} audit trails', { count: response.data.deleted || 0 }))
					this.hideClearAuditTrailsDialog()
				} else {
					showError(t('openregister', 'Failed to clear audit trails: {error}', { error: response.data.error || 'Unknown error' }))
				}
			} catch (error) {
				console.error('Failed to clear audit trails:', error)
				showError(t('openregister', 'Failed to clear audit trails: {error}', { error: error.message }))
			} finally {
				this.clearingAuditTrails = false
			}
		},

		/**
		 * Show clear search trails confirmation dialog
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		showClearSearchTrailsDialog() {
			this.showClearSearchTrailsConfirmation = true
		},

		/**
		 * Hide clear search trails confirmation dialog
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		hideClearSearchTrailsDialog() {
			this.showClearSearchTrailsConfirmation = false
		},

		/**
		 * Clear all search trails
		 * @spec exclude API passthrough to DELETE /api/search-trails/clear-all
		 */
		async clearAllSearchTrails() {
			this.clearingSearchTrails = true

			try {
				const response = await axios.delete(generateUrl('/apps/openregister/api/search-trails/clear-all'))

				if (response.data.success) {
					showSuccess(t('openregister', 'Successfully cleared {count} search trails', { count: response.data.deleted || 0 }))
					this.hideClearSearchTrailsDialog()
				} else {
					showError(t('openregister', 'Failed to clear search trails: {error}', { error: response.data.error || 'Unknown error' }))
				}
			} catch (error) {
				console.error('Failed to clear search trails:', error)
				showError(t('openregister', 'Failed to clear search trails: {error}', { error: error.message }))
			} finally {
				this.clearingSearchTrails = false
			}
		},

		/**
		 * Show clear blob objects confirmation dialog
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		showClearBlobObjectsDialog() {
			this.showClearBlobObjectsConfirmation = true
		},

		/**
		 * Hide clear blob objects confirmation dialog
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		hideClearBlobObjectsDialog() {
			this.showClearBlobObjectsConfirmation = false
		},

		/**
		 * Clear all blob storage objects
		 * @spec exclude API passthrough to DELETE /api/objects/clear-blob
		 */
		async clearAllBlobObjects() {
			this.clearingBlobObjects = true

			try {
				const response = await axios.delete(generateUrl('/apps/openregister/api/objects/clear-blob'))

				if (response.data.success) {
					showSuccess(t('openregister', 'Successfully cleared {count} blob storage objects', { count: response.data.deleted || 0 }))
					this.hideClearBlobObjectsDialog()
				} else {
					showError(t('openregister', 'Failed to clear blob objects: {error}', { error: response.data.error || 'Unknown error' }))
				}
			} catch (error) {
				console.error('Failed to clear blob objects:', error)
				showError(t('openregister', 'Failed to clear blob objects: {error}', { error: error.message }))
			} finally {
				this.clearingBlobObjects = false
			}
		},

		/**
		 * Show clear cache confirmation dialog
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		showClearCacheDialog() {
			this.showClearCacheConfirmation = true
		},

		/**
		 * Hide clear cache confirmation dialog
		 * @spec exclude store setter (local dialog-visibility toggle)
		 */
		hideClearCacheDialog() {
			this.showClearCacheConfirmation = false
		},

		/**
		 * Perform cache clearing with current type selection
		 * @spec exclude dialog-confirm wrapper over clearCache (API passthrough)
		 */
		async performClearCache() {
			await this.clearCache(this.clearCacheType)
			this.hideClearCacheDialog()
		},

		// ========================================
		// App Store Cache Actions
		// ========================================

		/**
		 * Invalidate Nextcloud app store cache
		 * Forces Nextcloud to fetch fresh app data from apps.nextcloud.com
		 * by setting the cache timestamp to 0 (expired)
		 * @param {string} type - Type of cache to invalidate: 'apps', 'categories', 'discover', or 'all'
		 * @return {Promise<object>} The API response
		 * @spec exclude API passthrough to DELETE /api/settings/cache/appstore
		 */
		async clearAppStoreCache(type = 'all') {
			this.clearingAppStoreCache = true

			try {
				const response = await axios.delete(generateUrl('/apps/openregister/api/settings/cache/appstore'), {
					data: { type },
				})

				if (response.data.success) {
					const invalidated = response.data.invalidated?.join(', ') || 'cache'
					showSuccess(t('openregister', 'App store cache invalidated: {invalidated}', { invalidated }))
				} else {
					showError(t('openregister', 'Failed to invalidate app store cache: {error}', { error: response.data.error || 'Unknown error' }))
				}

				return response.data
			} catch (error) {
				console.error('Failed to invalidate app store cache:', error)
				showError(t('openregister', 'Failed to invalidate app store cache: {error}', { error: error.message }))
				throw error
			} finally {
				this.clearingAppStoreCache = false
			}
		},
	},
})
