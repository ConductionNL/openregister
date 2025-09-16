<template>
	<div>
		<NcSettingsSection name="SOLR Search Management"
			description="Monitor and manage SOLR search performance and operations">
			<div v-if="!loadingStats && !solrError" class="solr-section">
				<!-- Action Buttons -->
				<div class="section-header-inline">
					<span />
					<div class="button-group">
						<NcButton
							type="secondary"
							:disabled="loading || loadingStats"
							@click="loadSolrStats">
							<template #icon>
								<NcLoadingIcon v-if="loadingStats" :size="20" />
								<Refresh v-else :size="20" />
							</template>
							Refresh
						</NcButton>
						<NcButton
							type="primary"
							:disabled="loading || warmingUp"
							@click="showWarmupConfigDialog">
							<template #icon>
								<NcLoadingIcon v-if="warmingUp" :size="20" />
								<Fire v-else :size="20" />
							</template>
							Warmup Index
						</NcButton>
						<NcButton
							type="secondary"
							:disabled="loading || operating"
							@click="commitIndex">
							<template #icon>
								<NcLoadingIcon v-if="operating === 'commit'" :size="20" />
								<Check v-else :size="20" />
							</template>
							Commit
						</NcButton>
						<NcButton
							type="secondary"
							:disabled="loading || operating"
							@click="optimizeIndex">
							<template #icon>
								<NcLoadingIcon v-if="operating === 'optimize'" :size="20" />
								<Wrench v-else :size="20" />
							</template>
							Optimize
						</NcButton>
						<NcButton
							type="error"
							:disabled="loading || operating"
							@click="showClearIndexDialog">
							<template #icon>
								<NcLoadingIcon v-if="operating === 'clear'" :size="20" />
								<Delete v-else :size="20" />
							</template>
							Clear Index
						</NcButton>
					</div>
				</div>

				<div class="solr-content">
					<!-- SOLR Overview -->
					<div class="solr-overview">
						<div class="solr-overview-cards">
							<div class="solr-overview-card" :class="connectionStatusClass">
								<h4>🔗 Connection</h4>
								<div class="solr-metric">
									<span class="metric-value" :class="connectionStatusClass">{{ solrStats.overview && solrStats.overview.connection_status || 'Unknown' }}</span>
									<span class="metric-label">Status ({{ solrStats.overview && solrStats.overview.response_time_ms || 0 }}ms)</span>
								</div>
							</div>
							<div class="solr-overview-card">
								<h4>📊 Documents</h4>
								<div class="solr-metric">
									<span class="metric-value">{{ formatNumber(solrStats.overview && solrStats.overview.total_documents || 0) }}</span>
									<span class="metric-label">Total Indexed</span>
								</div>
							</div>
							<div class="solr-overview-card">
								<h4>💾 Index Size</h4>
								<div class="solr-metric">
									<span class="metric-value">{{ solrStats.overview && solrStats.overview.index_size || 'Unknown' }}</span>
									<span class="metric-label">Storage Used</span>
								</div>
							</div>
							<div class="solr-overview-card" :class="performanceClass">
								<h4>⚡ Performance</h4>
								<div class="solr-metric">
									<span class="metric-value performance-metric">{{ solrStats.performance && solrStats.performance.operations_per_sec || 0 }}/sec</span>
									<span class="metric-label">Operations</span>
								</div>
							</div>
						</div>
					</div>

					<!-- SOLR Core Information -->
					<div class="solr-cores">
						<h4>🏗️ Core Information</h4>
						<div class="solr-cores-grid">
							<div class="solr-core-card">
								<h5>Active Core</h5>
								<div class="core-info">
									<div class="core-detail">
										<span class="detail-label">Name:</span>
										<span class="detail-value">{{ solrStats.cores.active_core }}</span>
									</div>
									<div class="core-detail">
										<span class="detail-label">Status:</span>
										<span class="detail-value" :class="getCoreStatusClass(solrStats.cores.core_status)">{{ solrStats.cores.core_status }}</span>
									</div>
									<div class="core-detail">
										<span class="detail-label">Tenant:</span>
										<span class="detail-value">{{ solrStats.cores.tenant_id }}</span>
									</div>
									<div class="core-detail">
										<span class="detail-label">Endpoint:</span>
										<span class="detail-value endpoint-url">{{ solrStats.cores.endpoint_url }}</span>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Performance Metrics -->
					<div class="solr-performance">
						<h4>📈 Performance Metrics</h4>
						<div class="performance-grid">
							<div class="performance-card">
								<h5>Search Operations</h5>
								<div class="performance-stats">
									<div class="performance-stat">
										<span class="stat-label">Total Searches:</span>
										<span class="stat-value">{{ formatNumber(solrStats.performance.total_searches) }}</span>
									</div>
									<div class="performance-stat">
										<span class="stat-label">Avg Time:</span>
										<span class="stat-value">{{ solrStats.performance.avg_search_time_ms }}ms</span>
									</div>
								</div>
							</div>

							<div class="performance-card">
								<h5>Index Operations</h5>
								<div class="performance-stats">
									<div class="performance-stat">
										<span class="stat-label">Total Indexes:</span>
										<span class="stat-value">{{ formatNumber(solrStats.performance.total_indexes) }}</span>
									</div>
									<div class="performance-stat">
										<span class="stat-label">Avg Time:</span>
										<span class="stat-value">{{ solrStats.performance.avg_index_time_ms }}ms</span>
									</div>
								</div>
							</div>

							<div class="performance-card">
								<h5>Error Rate</h5>
								<div class="performance-stats">
									<div class="performance-stat">
										<span class="stat-label">Error Rate:</span>
										<span class="stat-value" :class="getErrorRateClass(solrStats.performance.error_rate)">{{ solrStats.performance.error_rate }}%</span>
									</div>
									<div class="performance-stat">
										<span class="stat-label">Total Deletes:</span>
										<span class="stat-value">{{ formatNumber(solrStats.performance.total_deletes) }}</span>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Health Status -->
					<div class="solr-health">
						<h4>🏥 Health & Resources</h4>
						<div class="health-grid">
							<div class="health-card" :class="getHealthStatusClass(solrStats.health.status)">
								<h5>Overall Status</h5>
								<div class="health-status">
									<span class="status-indicator" :class="getHealthStatusClass(solrStats.health.status)"></span>
									<span class="status-text">{{ (solrStats.health.status || 'unknown').toUpperCase() }}</span>
								</div>
								<div class="health-details">
									<div class="health-detail">
										<span class="detail-label">Uptime:</span>
										<span class="detail-value">{{ solrStats.health.uptime }}</span>
									</div>
									<div class="health-detail">
										<span class="detail-label">Last Optimization:</span>
										<span class="detail-value">{{ formatDate(solrStats.health.last_optimization) }}</span>
									</div>
								</div>
							</div>

							<div class="health-card">
								<h5>Memory Usage</h5>
								<div class="resource-usage">
									<div class="usage-bar">
										<div class="usage-fill" :style="{ width: (solrStats.health.memory_usage?.percentage || 0) + '%' }"></div>
									</div>
									<div class="usage-details">
										<span>{{ solrStats.health.memory_usage?.used || 'N/A' }} / {{ solrStats.health.memory_usage?.max || 'N/A' }}</span>
										<span class="usage-percentage">{{ solrStats.health.memory_usage?.percentage || 0 }}%</span>
									</div>
								</div>
							</div>

							<div class="health-card">
								<h5>Disk Usage</h5>
								<div class="resource-usage">
									<div class="usage-bar">
										<div class="usage-fill disk-usage" :style="{ width: (solrStats.health.disk_usage?.percentage || 0) + '%' }"></div>
									</div>
									<div class="usage-details">
										<span>{{ solrStats.health.disk_usage?.used || 'N/A' }} / {{ (solrStats.health.disk_usage?.used || 'N/A') + ' + ' + (solrStats.health.disk_usage?.available || 'N/A') }}</span>
										<span class="usage-percentage">{{ solrStats.health.disk_usage?.percentage || 0 }}%</span>
									</div>
								</div>
							</div>
						</div>

						<!-- Health Warnings -->
						<div v-if="solrStats.health.warnings && solrStats.health.warnings.length > 0" class="health-warnings">
							<h6>⚠️ Warnings</h6>
							<ul class="warning-list">
								<li v-for="warning in solrStats.health.warnings" :key="warning" class="warning-item">
									{{ warning }}
								</li>
							</ul>
						</div>
					</div>

					<!-- Operations Status -->
					<div class="solr-operations">
						<h4>🔄 Recent Operations</h4>
						<div class="operations-grid">
							<div class="operations-card">
								<h5>Recent Activity</h5>
								<div class="activity-list">
									<div v-for="(activity, index) in (solrStats.operations.recent_activity || [])" :key="index" class="activity-item">
										<span class="activity-icon">{{ getActivityIcon(activity.type) }}</span>
										<div class="activity-content">
											<span class="activity-type">{{ (activity.type || '').charAt(0).toUpperCase() + (activity.type || '').slice(1) }}</span>
											<span class="activity-count">{{ activity.count }} operations</span>
											<span class="activity-time">{{ formatDate(activity.timestamp) }}</span>
										</div>
										<span class="activity-status" :class="activity.status">{{ activity.status }}</span>
									</div>
								</div>
							</div>

							<div class="operations-card">
								<h5>Queue Status</h5>
								<div class="queue-info">
									<div class="queue-stat">
										<span class="stat-label">Pending:</span>
										<span class="stat-value">{{ solrStats.operations.queue_status.pending_operations }}</span>
									</div>
									<div class="queue-stat">
										<span class="stat-label">Processing:</span>
										<span class="stat-value" :class="solrStats.operations.queue_status.processing ? 'processing' : 'idle'">
											{{ solrStats.operations.queue_status.processing ? 'Active' : 'Idle' }}
										</span>
									</div>
									<div class="queue-stat">
										<span class="stat-label">Last Processed:</span>
										<span class="stat-value">{{ formatDate(solrStats.operations.queue_status.last_processed) }}</span>
									</div>
								</div>
							</div>

							<div class="operations-card">
								<h5>Commit Settings</h5>
								<div class="commit-info">
									<div class="commit-stat">
										<span class="stat-label">Auto Commit:</span>
										<span class="stat-value" :class="solrStats.operations.commit_frequency.auto_commit ? 'enabled' : 'disabled'">
											{{ solrStats.operations.commit_frequency.auto_commit ? 'Enabled' : 'Disabled' }}
										</span>
									</div>
									<div class="commit-stat">
										<span class="stat-label">Commit Within:</span>
										<span class="stat-value">{{ solrStats.operations.commit_frequency.commit_within }}ms</span>
									</div>
									<div class="commit-stat">
										<span class="stat-label">Last Commit:</span>
										<span class="stat-value">{{ formatDate(solrStats.operations.commit_frequency.last_commit) }}</span>
									</div>
								</div>
							</div>
						</div>

						<div v-if="solrStats.operations.optimization_needed" class="optimization-notice">
							<div class="notice-content">
								<span class="notice-icon">⚡</span>
								<div class="notice-text">
									<strong>Optimization Recommended</strong>
									<p>Your index would benefit from optimization to improve search performance.</p>
								</div>
								<NcButton
									type="primary"
									:disabled="operating"
									@click="optimizeIndex">
									<template #icon>
										<NcLoadingIcon v-if="operating === 'optimize'" :size="20" />
										<Wrench v-else :size="20" />
									</template>
									Optimize Now
								</NcButton>
							</div>
						</div>
					</div>
				</div>
			</div>
			
			<!-- Error State -->
			<div v-else-if="solrError" class="error-container">
				<div class="error-icon">⚠️</div>
				<h3>SOLR Connection Error</h3>
				<p class="error-message">{{ solrErrorMessage }}</p>
				<p class="error-description">
					SOLR search engine is currently unavailable. This could be due to:
				</p>
				<ul class="error-reasons">
					<li>SOLR service is not running</li>
					<li>Network connectivity issues</li>
					<li>SOLR configuration problems</li>
					<li>Authentication or permission issues</li>
				</ul>
				<div class="error-actions">
					<NcButton
						type="primary"
						:disabled="loadingStats"
						@click="retryConnection">
						<template #icon>
							<NcLoadingIcon v-if="loadingStats" :size="20" />
							<Refresh v-else :size="20" />
						</template>
						Retry Connection
					</NcButton>
				</div>
			</div>

			<!-- Loading State -->
			<div v-else class="loading-container">
				<NcLoadingIcon :size="64" />
				<p>Loading SOLR statistics...</p>
			</div>
		</NcSettingsSection>

		<!-- Clear Index Confirmation Dialog -->
		<NcDialog v-if="showClearDialog"
			name="Clear SOLR Index"
			message="This will permanently delete all documents from the SOLR index. This action cannot be undone."
			:can-close="!operating"
			@closing="hideClearIndexDialog">
			<template #actions>
				<NcButton
					:disabled="operating"
					@click="hideClearIndexDialog">
					Cancel
				</NcButton>
				<NcButton
					type="error"
					:disabled="operating"
					@click="performClearIndex">
					<template #icon>
						<NcLoadingIcon v-if="operating === 'clear'" :size="20" />
						<Delete v-else :size="20" />
					</template>
					Clear Index
				</NcButton>
			</template>
		</NcDialog>

		<!-- SOLR Warmup Configuration Modal -->
		<SolrWarmupModal
			:show="showWarmupDialog"
			:warming-up="warmingUp"
			:completed="warmupCompleted"
			:results="warmupResults"
			:config="warmupConfig"
			:object-stats="objectStats"
			@close="hideWarmupDialog"
			@start-warmup="performWarmup"
			@reset="resetWarmupDialog"
			@config-changed="updateWarmupConfig" />


	</div>
</template>

<script>
import { NcSettingsSection, NcButton, NcLoadingIcon, NcDialog, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Fire from 'vue-material-design-icons/Fire.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Wrench from 'vue-material-design-icons/Wrench.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { SolrWarmupModal, ClearIndexModal } from '../../../modals/settings'

export default {
	name: 'SolrDashboard',
	
	components: {
		NcSettingsSection,
		NcButton,
		NcLoadingIcon,
		NcDialog,
		NcCheckboxRadioSwitch,
		Refresh,
		Fire,
		Check,
		Wrench,
		Delete,
		Cancel,
		SolrWarmupModal,
		ClearIndexModal,
	},

	data() {
		return {
			loading: false,
			loadingStats: false,
			solrError: false,
			solrErrorMessage: '',
			warmingUp: false,
			operating: false, // tracks current operation: 'commit', 'optimize', 'clear'
			showClearDialog: false,
			showWarmupDialog: false,
			warmupConfig: {
				mode: 'serial',
				maxObjects: 0,
				batchSize: 1000,
				collectErrors: false,
			},
			warmupResults: null,
			warmupCompleted: false,
			objectStats: {
				loading: false,
				totalObjects: 0,
			},
			solrStats: {
				available: false,
				overview: {
					connection_status: 'Unknown',
					response_time_ms: 0,
					total_documents: 0,
					index_size: 'Unknown',
				},
				performance: {
					operations_per_sec: 0,
					total_searches: 0,
					avg_search_time_ms: 0,
					total_indexes: 0,
					avg_index_time_ms: 0,
					error_rate: 0,
					total_deletes: 0,
				},
				cores: {
					active_core: 'Unknown',
					core_status: 'Unknown',
					tenant_id: 'Unknown',
					endpoint_url: 'Unknown',
				},
				health: {
					status: 'unknown',
					uptime: 'Unknown',
					last_optimization: null,
					memory_usage: {
						used: 'N/A',
						max: 'N/A',
						percentage: 0,
					},
					disk_usage: {
						used: 'N/A',
						available: 'N/A',
						percentage: 0,
					},
					warnings: [],
				},
				operations: {
					recent_activity: [],
					queue_status: {
						pending_operations: 0,
						processing: false,
						last_processed: null,
					},
					commit_frequency: {
						auto_commit: false,
						commit_within: 0,
						last_commit: null,
					},
					optimization_needed: false,
				},
			},
		}
	},

	computed: {
		/**
		 * Get CSS class for connection status
		 */
		connectionStatusClass() {
			if (!this.solrStats || !this.solrStats.available) return 'status-error'
			if (this.solrStats.overview && this.solrStats.overview.connection_status === 'Connected') return 'status-success'
			return 'status-warning'
		},

		/**
		 * Get CSS class for performance rating
		 */
		performanceClass() {
			if (!this.solrStats || !this.solrStats.performance) return 'performance-low'
			const opsPerSec = this.solrStats.performance.operations_per_sec || 0
			if (opsPerSec > 50) return 'performance-excellent'
			if (opsPerSec > 20) return 'performance-good'
			if (opsPerSec > 10) return 'performance-average'
			return 'performance-low'
		},
	},

	async mounted() {
		await this.loadSolrStats()
		// Auto-refresh every 30 seconds
		this.refreshInterval = setInterval(() => {
			if (!this.loading && !this.loadingStats && !this.operating && !this.warmingUp) {
				this.loadSolrStats()
			}
		}, 30000)
	},

	beforeDestroy() {
		if (this.refreshInterval) {
			clearInterval(this.refreshInterval)
		}
	},

	methods: {
		/**
		 * Load SOLR statistics from the API
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async loadSolrStats() {
			this.loadingStats = true
			this.solrError = false
			this.solrErrorMessage = ''

			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/solr/dashboard/stats'))
				
				if (response.data && response.data.available) {
					this.solrStats = response.data
				} else {
					this.solrError = true
					this.solrErrorMessage = response.data?.error || 'SOLR not available'
				}
			} catch (error) {
				console.error('Failed to load SOLR stats:', error)
				this.solrError = true
				this.solrErrorMessage = error.message || 'Failed to load SOLR statistics'
			} finally {
				this.loadingStats = false
			}
		},

		/**
		 * Format numbers for display
		 */
		formatNumber(num) {
			if (typeof num !== 'number') return num
			return num.toLocaleString()
		},

		/**
		 * Format dates for display
		 */
		formatDate(dateStr) {
			if (!dateStr) return 'Never'
			return new Date(dateStr).toLocaleString()
		},

		/**
		 * Get activity icon based on type
		 */
		getActivityIcon(type) {
			const icons = {
				'search': '🔍',
				'index': '📝',
				'delete': '🗑️',
				'commit': '💾',
				'optimize': '⚡'
			}
			return icons[type] || '📊'
		},

		/**
		 * Get CSS class for core status
		 */
		getCoreStatusClass(status) {
			if (status === 'Active') return 'status-success'
			if (status === 'Inactive') return 'status-warning'
			return 'status-error'
		},

		/**
		 * Get CSS class for health status
		 */
		getHealthStatusClass(status) {
			if (status === 'healthy') return 'status-success'
			if (status === 'warning') return 'status-warning'
			return 'status-error'
		},

		/**
		 * Get CSS class for error rate
		 */
		getErrorRateClass(rate) {
			if (rate < 1) return 'rate-good'
			if (rate < 5) return 'rate-warning'
			return 'rate-error'
		},

		/**
		 * Retry connection to SOLR
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async retryConnection() {
			await this.loadSolrStats()
		},

		/**
		 * Show warmup configuration dialog
		 *
		 * @return {void}
		 */
		showWarmupConfigDialog() {
			this.showWarmupDialog = true
			this.loadObjectStats()
		},

		/**
		 * Load object statistics for warmup prediction
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async loadObjectStats() {
			this.objectStats.loading = true
			
			try {
				const response = await fetch('/index.php/apps/openregister/api/settings/stats')
				
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}
				
				const data = await response.json()
				
				if (data && data.totals) {
					this.objectStats.totalObjects = data.totals.totalObjects || 0
				} else {
					console.warn('No totals data found in response:', data)
					this.objectStats.totalObjects = 0
				}
			} catch (error) {
				console.error('Failed to load object stats:', error)
				this.objectStats.totalObjects = 0
			} finally {
				this.objectStats.loading = false
			}
		},

		/**
		 * Hide warmup configuration dialog
		 *
		 * @return {void}
		 */
		hideWarmupDialog() {
			this.showWarmupDialog = false
			this.resetWarmupDialog()
		},

		/**
		 * Reset warmup dialog state
		 *
		 * @return {void}
		 */
		resetWarmupDialog() {
			this.warmupCompleted = false
			this.warmupResults = null
			this.warmingUp = false
		},

		/**
		 * Update warmup configuration when changed in modal
		 *
		 * @param {object} newConfig Updated configuration
		 */
		updateWarmupConfig(newConfig) {
			this.warmupConfig = { ...newConfig }
		},

		/**
		 * Estimate warmup duration based on configuration
		 *
		 * @return {string} Estimated duration in human-readable format
		 */
		estimateWarmupDuration() {
			const objectsToProcess = this.warmupConfig.maxObjects === 0 
				? this.objectStats.totalObjects 
				: Math.min(this.warmupConfig.maxObjects, this.objectStats.totalObjects)
			
			const batchCount = Math.ceil(objectsToProcess / this.warmupConfig.batchSize)
			const estimatedSeconds = batchCount * 2 // rough estimate of 2 seconds per batch
			
			if (estimatedSeconds < 60) return `~${estimatedSeconds} seconds`
			if (estimatedSeconds < 3600) return `~${Math.ceil(estimatedSeconds / 60)} minutes`
			return `~${Math.ceil(estimatedSeconds / 3600)} hours`
		},

		/**
		 * Perform SOLR warmup with configured parameters
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async performWarmup() {
			this.warmingUp = true

			try {
				const response = await fetch('/index.php/apps/openregister/api/settings/solr/warmup', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						mode: this.warmupConfig.mode,
						maxObjects: this.warmupConfig.maxObjects,
						batchSize: this.warmupConfig.batchSize,
						collectErrors: this.warmupConfig.collectErrors,
					}),
				})

				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}

				const result = await response.json()
				
				if (result.success) {
					this.warmupCompleted = true
					this.warmupResults = result
					console.log('Warmup completed successfully:', result)

					// Log detailed stats if available
					if (result.stats) {
						console.log('Warmup statistics:', result.stats)
					}

					// Refresh stats after warmup
					await this.loadSolrStats()

				} else {
					throw new Error(result.error || 'Warmup failed')
				}
			} catch (error) {
				console.error('Failed to warmup SOLR index:', error)
				this.warmupResults = {
					success: false,
					error: error.message,
					message: 'Failed to complete warmup process'
				}
				this.warmupCompleted = true
			} finally {
				this.warmingUp = false
			}
		},

		/**
		 * Show clear index confirmation dialog
		 */
		showClearIndexDialog() {
			this.showClearDialog = true
		},

		/**
		 * Hide clear index confirmation dialog
		 */
		hideClearIndexDialog() {
			this.showClearDialog = false
		},

		/**
		 * Perform clear index operation
		 */
		async performClearIndex() {
			await this.performSolrOperation('clear', 'Clear Index')
		},

		/**
		 * Commit SOLR index
		 */
		async commitIndex() {
			await this.performSolrOperation('commit', 'Commit')
		},

		/**
		 * Optimize SOLR index
		 */
		async optimizeIndex() {
			await this.performSolrOperation('optimize', 'Optimize')
		},

		/**
		 * Perform a SOLR operation (commit, optimize, clear)
		 *
		 * @param {string} operation The operation to perform
		 * @param {string} operationName Display name for the operation
		 */
		async performSolrOperation(operation, operationName) {
			this.operating = operation
			
			try {
				const response = await fetch(`/index.php/apps/openregister/api/settings/solr/${operation}`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}

				const result = await response.json()
				
				if (result.success) {
					console.log(`${operationName} completed successfully:`, result)
					
					// Hide dialog for clear operation
					if (operation === 'clear') {
						this.hideClearIndexDialog()
					}

					// Refresh stats after operation
					await this.loadSolrStats()

				} else {
					console.error('Failed to perform operation:', result.error)
					return
				}

			} catch (error) {
				console.error('Failed to perform operation:', error)
			} finally {
				this.operating = false
			}
		},
	}
}
</script>

<style scoped>
.solr-section {
	margin-top: 20px;
}

.section-header-inline {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1rem;
}

.section-header-inline h3 {
	margin: 0;
}

.solr-overview {
	margin-bottom: 2rem;
}

.solr-overview-cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 1rem;
	margin-bottom: 1.5rem;
}

.solr-overview-card {
	background: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 1rem;
	text-align: center;
}

.solr-overview-card.status-success {
	border-color: var(--color-success);
}

.solr-overview-card.status-warning {
	border-color: var(--color-warning);
}

.solr-overview-card.status-error {
	border-color: var(--color-error);
}

.solr-metric {
	margin-top: 0.5rem;
}

.metric-value {
	display: block;
	font-size: 1.5rem;
	font-weight: bold;
	margin-bottom: 0.25rem;
}

.metric-value.status-success {
	color: var(--color-success);
}

.metric-value.status-warning {
	color: var(--color-warning);
}

.metric-value.status-error {
	color: var(--color-error);
}

.metric-label {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

/* Error states */
.error-message {
	color: var(--color-error);
	margin-top: 1rem;
}

.retry-button {
	margin-top: 1rem;
}

/* Loading states */
.loading-container {
	display: flex;
	justify-content: center;
	align-items: center;
	padding: 2rem;
}

.loading-message {
	margin-left: 1rem;
	color: var(--color-text-maxcontrast);
}

/* Clear Index Modal */
.clear-modal-actions {
	display: flex;
	gap: 0.5rem;
	justify-content: flex-end;
	margin-top: 1rem;
}
</style>
