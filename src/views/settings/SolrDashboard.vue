<template>
	<div>
		<NcSettingsSection name="SOLR Search Management"
			description="Monitor and manage SOLR search performance and operations">
			<div v-if="!loadingStats" class="solr-section">
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
							@click="warmupIndex">
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
									<span class="metric-value" :class="connectionStatusClass">{{ solrStats.overview.connection_status }}</span>
									<span class="metric-label">Status ({{ solrStats.overview.response_time_ms }}ms)</span>
								</div>
							</div>
							<div class="solr-overview-card">
								<h4>📊 Documents</h4>
								<div class="solr-metric">
									<span class="metric-value">{{ formatNumber(solrStats.overview.total_documents) }}</span>
									<span class="metric-label">Total Indexed</span>
								</div>
							</div>
							<div class="solr-overview-card">
								<h4>💾 Index Size</h4>
								<div class="solr-metric">
									<span class="metric-value">{{ solrStats.overview.index_size }}</span>
									<span class="metric-label">Storage Used</span>
								</div>
							</div>
							<div class="solr-overview-card" :class="performanceClass">
								<h4>⚡ Performance</h4>
								<div class="solr-metric">
									<span class="metric-value performance-metric">{{ solrStats.performance.operations_per_sec }}/sec</span>
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
	</div>
</template>

<script>
import { NcSettingsSection, NcButton, NcLoadingIcon, NcDialog } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Fire from 'vue-material-design-icons/Fire.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Wrench from 'vue-material-design-icons/Wrench.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'SolrDashboard',
	
	components: {
		NcSettingsSection,
		NcButton,
		NcLoadingIcon,
		NcDialog,
		Refresh,
		Fire,
		Check,
		Wrench,
		Delete,
	},

	data() {
		return {
			loading: false,
			loadingStats: false,
			warmingUp: false,
			operating: false, // tracks current operation: 'commit', 'optimize', 'clear'
			showClearDialog: false,
			
			solrStats: {
				overview: {
					available: false,
					connection_status: 'unknown',
					response_time_ms: 0,
					total_documents: 0,
					index_size: '0 B',
					last_commit: null,
				},
				cores: {
					active_core: 'unknown',
					core_status: 'inactive',
					tenant_id: 'unknown',
					endpoint_url: 'N/A',
				},
				performance: {
					total_searches: 0,
					total_indexes: 0,
					total_deletes: 0,
					avg_search_time_ms: 0,
					avg_index_time_ms: 0,
					operations_per_sec: 0,
					error_rate: 0,
				},
				health: {
					status: 'unknown',
					uptime: 'N/A',
					memory_usage: { used: 'N/A', max: 'N/A', percentage: 0 },
					disk_usage: { used: 'N/A', available: 'N/A', percentage: 0 },
					warnings: [],
					last_optimization: null,
				},
				operations: {
					recent_activity: [],
					queue_status: { pending_operations: 0, processing: false, last_processed: null },
					commit_frequency: { auto_commit: false, commit_within: 0, last_commit: null },
					optimization_needed: false,
				},
				generated_at: null,
			}
		}
	},

	computed: {
		/**
		 * Get CSS class for connection status
		 *
		 * @return {string} CSS class name
		 */
		connectionStatusClass() {
			switch (this.solrStats.overview.connection_status) {
				case 'healthy':
					return 'status-healthy'
				case 'error':
				case 'critical':
					return 'status-error'
				case 'warning':
					return 'status-warning'
				default:
					return 'status-unknown'
			}
		},

		/**
		 * Get CSS class for performance metrics
		 *
		 * @return {string} CSS class name
		 */
		performanceClass() {
			const opsPerSec = this.solrStats.performance.operations_per_sec
			if (opsPerSec > 100) return 'performance-excellent'
			if (opsPerSec > 50) return 'performance-good'
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

			try {
				const response = await fetch('/index.php/apps/openregister/api/solr/dashboard/stats')
				const data = await response.json()

				if (data.error) {
					console.error('Failed to load SOLR stats:', data.error)
					return
				}

				this.solrStats = { ...this.solrStats, ...data }

			} catch (error) {
				console.error('Failed to load SOLR stats:', error)
			} finally {
				this.loadingStats = false
			}
		},

		/**
		 * Warmup SOLR index
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async warmupIndex() {
			this.warmingUp = true

			try {
				const response = await fetch('/index.php/apps/openregister/api/settings/solr/warmup', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				const result = await response.json()

				if (result.error) {
					console.error('Failed to warmup index:', result.error)
					return
				}

				// Refresh stats after warmup
				await this.loadSolrStats()

			} catch (error) {
				console.error('Failed to warmup index:', error)
			} finally {
				this.warmingUp = false
			}
		},

		/**
		 * Commit SOLR index
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async commitIndex() {
			await this.performOperation('commit')
		},

		/**
		 * Optimize SOLR index
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async optimizeIndex() {
			await this.performOperation('optimize')
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
			if (!this.operating) {
				this.showClearDialog = false
			}
		},

		/**
		 * Clear SOLR index
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async performClearIndex() {
			await this.performOperation('clear')
			this.hideClearIndexDialog()
		},

		/**
		 * Perform SOLR management operation
		 *
		 * @param {string} operation Operation to perform
		 * @async
		 * @return {Promise<void>}
		 */
		async performOperation(operation) {
			this.operating = operation

			try {
				const response = await fetch('/index.php/apps/openregister/api/solr/manage/' + operation, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				const result = await response.json()

				if (result.error) {
					console.error('Failed to perform operation:', result.error)
					return
				}

				// Refresh stats after operation
				await this.loadSolrStats()

			} catch (error) {
				console.error('Failed to perform operation:', error)
			} finally {
				this.operating = false
			}
		},

		/**
		 * Get CSS class for core status
		 *
		 * @param {string} status Core status
		 * @return {string} CSS class name
		 */
		getCoreStatusClass(status) {
			switch (status) {
				case 'active':
					return 'status-active'
				case 'inactive':
					return 'status-inactive'
				default:
					return 'status-unknown'
			}
		},

		/**
		 * Get CSS class for error rate
		 *
		 * @param {number} errorRate Error rate percentage
		 * @return {string} CSS class name
		 */
		getErrorRateClass(errorRate) {
			if (errorRate > 10) return 'error-rate-critical'
			if (errorRate > 5) return 'error-rate-warning'
			return 'error-rate-good'
		},

		/**
		 * Get CSS class for health status
		 *
		 * @param {string} status Health status
		 * @return {string} CSS class name
		 */
		getHealthStatusClass(status) {
			switch (status) {
				case 'healthy':
					return 'health-healthy'
				case 'warning':
					return 'health-warning'
				case 'critical':
					return 'health-critical'
				default:
					return 'health-unknown'
			}
		},

		/**
		 * Get icon for activity type
		 *
		 * @param {string} type Activity type
		 * @return {string} Icon character
		 */
		getActivityIcon(type) {
			switch (type) {
				case 'search':
					return '🔍'
				case 'index':
					return '📄'
				case 'delete':
					return '🗑️'
				default:
					return '🔄'
			}
		},

		/**
		 * Format number with thousands separators
		 *
		 * @param {number} number Number to format
		 * @return {string} Formatted number
		 */
		formatNumber(number) {
			return new Intl.NumberFormat().format(number || 0)
		},

		/**
		 * Format date for display
		 *
		 * @param {string|null} dateString ISO date string
		 * @return {string} Formatted date
		 */
		formatDate(dateString) {
			if (!dateString) return 'Never'
			
			try {
				const date = new Date(dateString)
				const now = new Date()
				const diffMs = now - date
				const diffMins = Math.floor(diffMs / 60000)
				const diffHours = Math.floor(diffMins / 60)
				const diffDays = Math.floor(diffHours / 24)

				if (diffMins < 1) return 'Just now'
				if (diffMins < 60) return diffMins + 'm ago'
				if (diffHours < 24) return diffHours + 'h ago'
				if (diffDays < 7) return diffDays + 'd ago'
				
				return date.toLocaleDateString()
			} catch (e) {
				return 'Invalid date'
			}
		},
	},
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
	margin-bottom: 24px;
}

.button-group {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.solr-content {
	display: flex;
	flex-direction: column;
	gap: 32px;
}

/* Overview Cards */
.solr-overview-cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 16px;
	margin-bottom: 24px;
}

.solr-overview-card {
	background: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	text-align: center;
	transition: border-color 0.2s ease;
}

.solr-overview-card.status-healthy {
	border-color: var(--color-success);
}

.solr-overview-card.status-warning {
	border-color: var(--color-warning);
}

.solr-overview-card.status-error {
	border-color: var(--color-error);
}

.solr-overview-card.performance-excellent {
	border-color: var(--color-success);
}

.solr-overview-card.performance-good {
	border-color: #2196F3;
}

.solr-overview-card.performance-average {
	border-color: var(--color-warning);
}

.solr-overview-card.performance-low {
	border-color: var(--color-error);
}

.solr-overview-card h4 {
	margin: 0 0 12px 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.solr-metric {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.metric-value {
	font-size: 24px;
	font-weight: bold;
	color: var(--color-text-light);
}

.metric-value.status-healthy {
	color: var(--color-success);
}

.metric-value.status-error {
	color: var(--color-error);
}

.metric-value.status-warning {
	color: var(--color-warning);
}

.metric-value.performance-metric {
	color: var(--color-primary);
}

.metric-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

/* Core Information */
.solr-cores h4 {
	margin: 0 0 16px 0;
	color: var(--color-text-light);
}

.solr-cores-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
	gap: 16px;
}

.solr-core-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 16px;
}

.solr-core-card h5 {
	margin: 0 0 12px 0;
	color: var(--color-text-light);
}

.core-info {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.core-detail {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.detail-label {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.detail-value {
	color: var(--color-text-light);
}

.detail-value.status-active {
	color: var(--color-success);
}

.detail-value.status-inactive {
	color: var(--color-error);
}

.endpoint-url {
	font-family: monospace;
	font-size: 12px;
}

/* Performance Grid */
.solr-performance h4 {
	margin: 0 0 16px 0;
	color: var(--color-text-light);
}

.performance-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 16px;
}

.performance-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 16px;
}

.performance-card h5 {
	margin: 0 0 12px 0;
	color: var(--color-text-light);
}

.performance-stats {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.performance-stat {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.stat-label {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.stat-value {
	color: var(--color-text-light);
}

.stat-value.error-rate-good {
	color: var(--color-success);
}

.stat-value.error-rate-warning {
	color: var(--color-warning);
}

.stat-value.error-rate-critical {
	color: var(--color-error);
}

/* Health Grid */
.solr-health h4 {
	margin: 0 0 16px 0;
	color: var(--color-text-light);
}

.health-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 16px;
	margin-bottom: 16px;
}

.health-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 16px;
}

.health-card.health-healthy {
	border-color: var(--color-success);
}

.health-card.health-warning {
	border-color: var(--color-warning);
}

.health-card.health-critical {
	border-color: var(--color-error);
}

.health-card h5 {
	margin: 0 0 12px 0;
	color: var(--color-text-light);
}

.health-status {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
}

.status-indicator {
	width: 12px;
	height: 12px;
	border-radius: 50%;
	background: var(--color-text-maxcontrast);
}

.status-indicator.health-healthy {
	background: var(--color-success);
}

.status-indicator.health-warning {
	background: var(--color-warning);
}

.status-indicator.health-critical {
	background: var(--color-error);
}

.status-text {
	font-weight: 500;
	color: var(--color-text-light);
}

.health-details {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.health-detail {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

/* Resource Usage */
.resource-usage {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.usage-bar {
	height: 8px;
	background: var(--color-background-dark);
	border-radius: 4px;
	overflow: hidden;
}

.usage-fill {
	height: 100%;
	background: var(--color-primary);
	transition: width 0.3s ease;
}

.usage-fill.disk-usage {
	background: var(--color-warning);
}

.usage-details {
	display: flex;
	justify-content: space-between;
	align-items: center;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.usage-percentage {
	font-weight: 500;
}

/* Health Warnings */
.health-warnings {
	margin-top: 16px;
	padding: 16px;
	background: rgba(var(--color-warning), 0.1);
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius);
}

.health-warnings h6 {
	margin: 0 0 8px 0;
	color: var(--color-warning);
}

.warning-list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.warning-item {
	margin: 4px 0;
	color: var(--color-text-light);
}

/* Operations */
.solr-operations h4 {
	margin: 0 0 16px 0;
	color: var(--color-text-light);
}

.operations-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
	gap: 16px;
	margin-bottom: 16px;
}

.operations-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 16px;
}

.operations-card h5 {
	margin: 0 0 12px 0;
	color: var(--color-text-light);
}

/* Activity List */
.activity-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.activity-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.activity-icon {
	font-size: 16px;
}

.activity-content {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.activity-type {
	font-weight: 500;
	color: var(--color-text-light);
}

.activity-count {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.activity-time {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.activity-status {
	font-size: 12px;
	padding: 2px 6px;
	border-radius: var(--border-radius);
	background: var(--color-success);
	color: white;
}

.activity-status.error {
	background: var(--color-error);
}

/* Queue Info */
.queue-info, .commit-info {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.queue-stat, .commit-stat {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.stat-value.processing {
	color: var(--color-warning);
}

.stat-value.idle {
	color: var(--color-text-maxcontrast);
}

.stat-value.enabled {
	color: var(--color-success);
}

.stat-value.disabled {
	color: var(--color-text-maxcontrast);
}

/* Optimization Notice */
.optimization-notice {
	padding: 16px;
	background: rgba(var(--color-warning), 0.1);
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius);
	margin-top: 16px;
}

.notice-content {
	display: flex;
	align-items: center;
	gap: 16px;
}

.notice-icon {
	font-size: 24px;
}

.notice-text {
	flex: 1;
}

.notice-text strong {
	color: var(--color-text-light);
	display: block;
	margin-bottom: 4px;
}

.notice-text p {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

/* Loading State */
.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 60px 20px;
	text-align: center;
}

.loading-container p {
	margin: 16px 0 0 0;
	color: var(--color-text-maxcontrast);
}
</style>
