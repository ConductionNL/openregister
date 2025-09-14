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
 *
 * @category Store
 * @package  OpenRegister
 * 
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.nl
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
		
		// SOLR states
		testingConnection: false,
		warmingUpSolr: false,
		settingUpSolr: false,
		showTestDialog: false,
		showSetupDialog: false,
		testResults: null,
		setupResults: null,
		
		// Cache states
		clearingCache: false,
		warmingUpCache: false,
		showClearCacheConfirmation: false,
		clearCacheType: 'all',
		
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
			enableRBAC: false,
			anonymousGroup: 'public',
			defaultNewUserGroup: 'viewer',
			defaultObjectOwner: '',
			adminOverride: true,
		},
		
		multitenancyOptions: {
			enableMultitenancy: false,
			defaultUserTenant: '',
			defaultObjectTenant: '',
		},
		
		retentionOptions: {
			objectArchiveRetention: 31536000000, // 1 year
			objectDeleteRetention: 63072000000,  // 2 years
			searchTrailRetention: 2592000000,    // 1 month
			createLogRetention: 2592000000,      // 1 month
			readLogRetention: 86400000,          // 24 hours
			updateLogRetention: 604800000,       // 1 week
			deleteLogRetention: 2592000000,      // 1 month
		},
		
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
		 */
		hasWarnings: (state) => {
			const warnings = state.stats.warnings
			return Object.values(warnings).some(count => count > 0)
		},
		
		/**
		 * Get retention status information
		 */
		retentionStatusClass: (state) => {
			const hasIssues = state.stats.warnings.auditTrailsWithoutExpiry > 0 ||
							 state.stats.warnings.searchTrailsWithoutExpiry > 0 ||
							 state.stats.warnings.expiredAuditTrails > 0 ||
							 state.stats.warnings.expiredSearchTrails > 0 ||
							 state.stats.warnings.expiredObjects > 0
			
			return hasIssues ? 'warning-status' : 'healthy-status'
		},
		
		retentionStatusTextClass: (state) => {
			const hasIssues = state.stats.warnings.auditTrailsWithoutExpiry > 0 ||
							 state.stats.warnings.searchTrailsWithoutExpiry > 0 ||
							 state.stats.warnings.expiredAuditTrails > 0 ||
							 state.stats.warnings.expiredSearchTrails > 0 ||
							 state.stats.warnings.expiredObjects > 0
			
			return hasIssues ? 'status-warning' : 'status-healthy'
		},
		
		retentionStatusMessage: (state) => {
			const warnings = state.stats.warnings
			const hasIssues = warnings.auditTrailsWithoutExpiry > 0 ||
							 warnings.searchTrailsWithoutExpiry > 0 ||
							 warnings.expiredAuditTrails > 0 ||
							 warnings.expiredSearchTrails > 0 ||
							 warnings.expiredObjects > 0
			
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
					this.solrOptions = { ...this.solrOptions, ...response.data }
				}
			} catch (error) {
				console.error('Failed to load SOLR settings:', error)
				// Don't show error - this is handled by individual components
			}
		},

		/**
		 * Update SOLR settings
		 */
		async updateSolrSettings(solrData) {
			this.saving = true
			try {
				const response = await axios.put(
					generateUrl('/apps/openregister/api/settings/solr'),
					solrData
				)
				
				if (response.data) {
					this.solrOptions = { ...this.solrOptions, ...response.data }
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
		 * Test SOLR connection using currently configured settings
		 */
		async testSolrConnection() {
			this.testingConnection = true
			this.showTestDialog = true
			try {
				const response = await axios.post(
					generateUrl('/apps/openregister/api/settings/solr/test')
				)
				
				this.testResults = response.data
				this.solrConnectionStatus = response.data
				return response.data
			} catch (error) {
				console.error('SOLR connection test failed:', error)
				const errorData = {
					success: false,
					message: 'Connection test failed: ' + error.message,
					details: { error: error.message }
				}
				this.testResults = errorData
				this.solrConnectionStatus = errorData
				return errorData
			} finally {
				this.testingConnection = false
			}
		},

		/**
		 * Setup SOLR
		 */
		async setupSolr() {
			this.settingUpSolr = true
			this.showSetupDialog = true
			try {
				const response = await axios.post(generateUrl('/apps/openregister/api/solr/setup'))
				
				this.setupResults = response.data
				
				if (response.data.success) {
					showSuccess('SOLR setup completed successfully')
				} else {
					showError('SOLR setup failed: ' + response.data.message)
				}
				
				return response.data
			} catch (error) {
				console.error('SOLR setup failed:', error)
				const errorData = {
					success: false,
					message: 'SOLR setup failed: ' + error.message,
					details: { error: error.message }
				}
				this.setupResults = errorData
				showError('SOLR setup failed: ' + error.message)
				throw error
			} finally {
				this.settingUpSolr = false
			}
		},

		/**
		 * Warmup SOLR index
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
					}
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
		 * Load SOLR dashboard statistics
		 */
		async loadSolrDashboardStats() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/solr/dashboard/stats'))
				this.solrDashboardStats = response.data || this.solrDashboardStats
				return response.data
			} catch (error) {
				console.error('Failed to load SOLR dashboard stats:', error)
				// Set unavailable state
				this.solrDashboardStats = {
					...this.solrDashboardStats,
					available: false,
					connection_status: 'unavailable',
					health: 'error',
				}
				throw error
			}
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
		 */
		async updateRbacSettings(rbacData) {
			this.saving = true
			try {
				const response = await axios.put(
					generateUrl('/apps/openregister/api/settings/rbac'),
					rbacData
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
		 */
		async updateMultitenancySettings(multitenancyData) {
			this.saving = true
			try {
				const response = await axios.put(
					generateUrl('/apps/openregister/api/settings/multitenancy'),
					multitenancyData
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
		 */
		async updateRetentionSettings(retentionData) {
			this.saving = true
			try {
				const response = await axios.put(
					generateUrl('/apps/openregister/api/settings/retention'),
					retentionData
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
		 * Clear specific cache type
		 */
		async clearSpecificCache(type) {
			this.clearingCache = type
			try {
				const response = await axios.delete(generateUrl('/apps/openregister/api/settings/cache'), {
					data: { type }
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
		 */
		async clearCache(type = 'all') {
			this.clearingCache = true
			
			try {
				const response = await axios.delete(generateUrl('/apps/openregister/api/settings/cache'), {
					data: { type }
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
		 * Hide test dialog
		 */
		hideTestDialog() {
			this.showTestDialog = false
		},

		/**
		 * Retry test connection
		 */
		retryTest() {
			this.testSolrConnection()
		},

		/**
		 * Hide setup dialog
		 */
		hideSetupDialog() {
			this.showSetupDialog = false
		},

		/**
		 * Retry SOLR setup
		 */
		retrySetup() {
			this.setupSolr()
		},
	},
})
