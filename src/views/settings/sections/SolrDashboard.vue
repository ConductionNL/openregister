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
import { SolrWarmupModal, ClearIndexModal } from '../../modals/settings'

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
							<p><strong>Error Handling:</strong> {{ warmupConfig.collectErrors ? 'Collect errors' : 'Stop on first error' }}</p>
						</div>
					</div>

					<div v-if="warmupResults.stats" class="results-stats">
						<h5>Warmup Statistics</h5>
						
						<!-- Performance Overview -->
						<div class="stats-section">
							<h6>Performance Overview</h6>
							<div class="stats-grid">
								<div v-if="warmupResults.stats.totalProcessed !== undefined" class="stat-item">
									<span class="stat-label">Objects Processed:</span>
									<span class="stat-value">{{ warmupResults.stats.totalProcessed.toLocaleString() }}</span>
								</div>
								<div v-if="warmupResults.stats.totalIndexed !== undefined" class="stat-item">
									<span class="stat-label">Objects Indexed:</span>
									<span class="stat-value">{{ warmupResults.stats.totalIndexed.toLocaleString() }}</span>
								</div>
								<div v-if="warmupResults.stats.totalErrors !== undefined" class="stat-item">
									<span class="stat-label">Errors:</span>
									<span class="stat-value" :class="{ 'error': warmupResults.stats.totalErrors > 0 }">{{ warmupResults.stats.totalErrors }}</span>
								</div>
								<div v-if="warmupResults.stats.totalObjectsFound !== undefined" class="stat-item">
									<span class="stat-label">Objects Found:</span>
									<span class="stat-value">{{ warmupResults.stats.totalObjectsFound.toLocaleString() }}</span>
								</div>
								<div v-if="warmupResults.stats.batchesProcessed !== undefined" class="stat-item">
									<span class="stat-label">Batches Processed:</span>
									<span class="stat-value">{{ warmupResults.stats.batchesProcessed }}</span>
								</div>
								<div v-if="warmupResults.stats.duration !== undefined" class="stat-item">
									<span class="stat-label">Duration:</span>
									<span class="stat-value">{{ formatDuration(warmupResults.stats.duration) }}</span>
								</div>
								<div v-if="warmupResults.stats.objectsPerSecond !== undefined" class="stat-item">
									<span class="stat-label">Objects/Second:</span>
									<span class="stat-value">{{ warmupResults.stats.objectsPerSecond.toFixed(1) }}</span>
								</div>
								<div v-if="warmupResults.stats.successRate !== undefined" class="stat-item">
									<span class="stat-label">Success Rate:</span>
									<span class="stat-value" :class="getSuccessRateClass(warmupResults.stats.successRate)">{{ warmupResults.stats.successRate.toFixed(1) }}%</span>
								</div>
							</div>
						</div>

						<!-- Schema Processing -->
						<div v-if="warmupResults.stats.schemasProcessed !== undefined || warmupResults.stats.fieldsCreated !== undefined" class="stats-section">
							<h6>Schema Processing</h6>
							<div class="stats-grid">
								<div v-if="warmupResults.stats.schemasProcessed !== undefined" class="stat-item">
									<span class="stat-label">Schemas Processed:</span>
									<span class="stat-value">{{ warmupResults.stats.schemasProcessed }}</span>
								</div>
								<div v-if="warmupResults.stats.fieldsCreated !== undefined" class="stat-item">
									<span class="stat-label">Fields Created:</span>
									<span class="stat-value">{{ warmupResults.stats.fieldsCreated }}</span>
								</div>
							</div>
						</div>

						<!-- Operations Status -->
						<div v-if="warmupResults.stats.operations" class="stats-section">
							<h6>Operations Status</h6>
							<div class="operations-grid">
								<div v-for="(status, operation) in warmupResults.stats.operations" :key="operation" class="operation-item">
									<span class="operation-label">{{ formatOperationName(operation) }}:</span>
									<span class="operation-status" :class="getOperationStatusClass(status)">
										{{ status === true ? '✓ Success' : status === false ? '✗ Failed' : status }}
									</span>
								</div>
							</div>
						</div>
					</div>

					<div v-if="warmupResults.errors && warmupResults.errors.length > 0" class="results-errors">
						<h5>Errors Encountered</h5>
						<div class="error-list">
							<div v-for="(error, index) in warmupResults.errors" :key="index" class="error-item">
								<strong>Error {{ index + 1 }}:</strong> {{ error }}
							</div>
						</div>
					</div>
				</div>

				<!-- Configuration Form -->
				<div v-else class="warmup-form">
					<div class="form-section">
						<h4>Execution Mode</h4>
						<div class="radio-group">
							<NcCheckboxRadioSwitch
								:checked.sync="warmupConfig.mode"
								name="warmup_mode"
								value="serial"
								type="radio">
								Serial Mode (Safer, slower)
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								:checked.sync="warmupConfig.mode"
								name="warmup_mode"
								value="parallel"
								type="radio">
								Parallel Mode (Faster, more resource intensive)
							</NcCheckboxRadioSwitch>
						</div>
						<p class="form-description">
							Serial mode processes objects one by one, while parallel mode processes multiple objects simultaneously for faster completion.
						</p>
					</div>

					<div class="form-section">
						<h4>Processing Limits</h4>
						
						<!-- Object Count Prediction -->
						<div class="object-prediction">
							<div class="prediction-header">
								<h5>📊 Object Count Prediction</h5>
								<div v-if="objectStats.loading" class="loading-indicator">
									<NcLoadingIcon :size="16" />
									<span>Loading object count...</span>
								</div>
							</div>
							<div v-if="!objectStats.loading && objectStats.totalObjects > 0" class="prediction-content">
								<div class="prediction-stats">
									<div class="stat-item">
										<span class="stat-label">Total Objects in Database:</span>
										<span class="stat-value">{{ objectStats.totalObjects.toLocaleString() }}</span>
									</div>
									<div class="stat-item">
										<span class="stat-label">Objects to Process:</span>
										<span class="stat-value">
											{{ warmupConfig.maxObjects === 0 ? objectStats.totalObjects.toLocaleString() : Math.min(warmupConfig.maxObjects, objectStats.totalObjects).toLocaleString() }}
											<span v-if="warmupConfig.maxObjects > 0 && warmupConfig.maxObjects < objectStats.totalObjects" class="limited-indicator">
												(limited by max objects setting)
											</span>
										</span>
									</div>
									<div class="stat-item">
										<span class="stat-label">Estimated Batches:</span>
										<span class="stat-value">
											{{ Math.ceil((warmupConfig.maxObjects === 0 ? objectStats.totalObjects : Math.min(warmupConfig.maxObjects, objectStats.totalObjects)) / warmupConfig.batchSize) }}
										</span>
									</div>
									<div class="stat-item">
										<span class="stat-label">Estimated Duration:</span>
										<span class="stat-value">
											{{ estimateWarmupDuration() }}
										</span>
									</div>
								</div>
							</div>
							<div v-else-if="!objectStats.loading" class="prediction-error">
								<span class="error-icon">⚠️</span>
								<span>Unable to load object count. Warmup will process all available objects.</span>
							</div>
						</div>

						<div class="form-row">
							<label class="form-label">
								<strong>Max Objects (0 = all)</strong>
								<p class="form-description">Maximum number of objects to process. Set to 0 to process all objects.</p>
							</label>
							<div class="form-input">
								<input
									v-model.number="warmupConfig.maxObjects"
									type="number"
									:disabled="warmingUp"
									placeholder="0"
									min="0"
									class="warmup-input-field">
							</div>
						</div>

						<div class="form-row">
							<label class="form-label">
								<strong>Batch Size</strong>
								<p class="form-description">Number of objects to process in each batch (1-5000).</p>
							</label>
							<div class="form-input">
								<input
									v-model.number="warmupConfig.batchSize"
									type="number"
									:disabled="warmingUp"
									placeholder="1000"
									min="1"
									max="5000"
									class="warmup-input-field">
							</div>
						</div>
					</div>

					<div class="form-section">
						<h4>Error Handling</h4>
						<NcCheckboxRadioSwitch
							v-model="warmupConfig.collectErrors"
							:disabled="warmingUp"
							type="switch">
							Continue on errors (collect all errors)
						</NcCheckboxRadioSwitch>
						<p class="form-description">
							<strong>When enabled:</strong> Warmup continues processing even if errors occur, collecting all errors for review at the end.<br>
							<strong>When disabled:</strong> Warmup stops immediately when the first error is encountered.
						</p>
					</div>
				</div>

			</div>

			<template #actions>
				<NcButton
					:disabled="warmingUp"
					@click="hideWarmupDialog">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ warmingUp ? 'Close' : (warmupCompleted ? 'Close' : 'Cancel') }}
				</NcButton>

				<NcButton
					v-if="!warmingUp && !warmupCompleted"
					type="primary"
					@click="performWarmup">
					<template #icon>
						<Fire :size="20" />
					</template>
					Start Warmup
				</NcButton>

				<NcButton
					v-if="warmupCompleted"
					type="secondary"
					@click="resetWarmupDialog">
					<template #icon>
						<Refresh :size="20" />
					</template>
					Run Again
				</NcButton>
			</template>
		</NcDialog>
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
import { SolrWarmupModal, ClearIndexModal } from '../../modals/settings'

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
				totalObjects: 0,
				loading: false
			},
			
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
			this.solrError = false
			this.solrErrorMessage = ''

			try {
				const response = await fetch('/index.php/apps/openregister/api/solr/dashboard/stats')
				
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}
				
				const data = await response.json()

				if (data.error) {
					this.solrError = true
					this.solrErrorMessage = data.error
					console.error('Failed to load SOLR stats:', data.error)
					return
				}

				// Successfully loaded stats - clear any previous errors
				this.solrError = false
				this.solrErrorMessage = ''
				this.solrStats = { ...this.solrStats, ...data }

			} catch (error) {
				this.solrError = true
				this.solrErrorMessage = error.message || 'SOLR not available'
				console.error('Failed to load SOLR stats:', error)
			} finally {
				this.loadingStats = false
			}
		},

		/**
		 * Retry SOLR connection
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
			if (this.objectStats.totalObjects === 0) {
				return 'Unknown'
			}

			const totalObjects = this.warmupConfig.maxObjects === 0 
				? this.objectStats.totalObjects 
				: Math.min(this.warmupConfig.maxObjects, this.objectStats.totalObjects)
			
			const batches = Math.ceil(totalObjects / this.warmupConfig.batchSize)
			
			// Rough estimates based on mode and batch size
			// Serial: ~2-5 seconds per batch, Parallel: ~1-2 seconds per batch
			const secondsPerBatch = this.warmupConfig.mode === 'serial' ? 3 : 1.5
			const totalSeconds = batches * secondsPerBatch
			
			if (totalSeconds < 60) {
				return `~${Math.ceil(totalSeconds)} seconds`
			} else if (totalSeconds < 3600) {
				const minutes = Math.ceil(totalSeconds / 60)
				return `~${minutes} minute${minutes !== 1 ? 's' : ''}`
			} else {
				const hours = Math.floor(totalSeconds / 3600)
				const minutes = Math.ceil((totalSeconds % 3600) / 60)
				return `~${hours}h ${minutes}m`
			}
		},

		/**
		 * Format duration in seconds to human readable format
		 *
		 * @param {number} seconds Duration in seconds
		 * @return {string} Formatted duration
		 */
		formatDuration(seconds) {
			if (seconds < 1) {
				return `${(seconds * 1000).toFixed(0)}ms`
			} else if (seconds < 60) {
				return `${seconds.toFixed(2)}s`
			} else {
				const minutes = Math.floor(seconds / 60)
				const remainingSeconds = seconds % 60
				return `${minutes}m ${remainingSeconds.toFixed(1)}s`
			}
		},

		/**
		 * Get CSS class for success rate
		 *
		 * @param {number} successRate Success rate percentage
		 * @return {string} CSS class
		 */
		getSuccessRateClass(successRate) {
			if (successRate >= 95) return 'success'
			if (successRate >= 80) return 'warning'
			return 'error'
		},

		/**
		 * Format operation name for display
		 *
		 * @param {string} operation Operation key
		 * @return {string} Formatted operation name
		 */
		formatOperationName(operation) {
			const names = {
				'connection_test': 'Connection Test',
				'schema_mirroring': 'Schema Mirroring',
				'schemas_processed': 'Schemas Processed',
				'fields_created': 'Fields Created',
				'conflicts_resolved': 'Conflicts Resolved',
				'error_collection_mode': 'Error Collection Mode',
				'object_indexing': 'Object Indexing',
				'objects_indexed': 'Objects Indexed',
				'indexing_errors': 'Indexing Errors',
				'warmup_query_0': 'Warmup Query 1',
				'warmup_query_1': 'Warmup Query 2',
				'warmup_query_2': 'Warmup Query 3',
				'commit': 'Commit Operation'
			}
			return names[operation] || operation.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
		},

		/**
		 * Get CSS class for operation status
		 *
		 * @param {boolean|string|number} status Operation status
		 * @return {string} CSS class
		 */
		getOperationStatusClass(status) {
			if (status === true) return 'success'
			if (status === false) return 'error'
			if (typeof status === 'number' && status > 0) return 'success'
			return 'neutral'
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

				const result = await response.json()

				if (result.error) {
					console.error('Failed to warmup SOLR index:', result.error)
					// Show error details if available
					if (result.errors && Array.isArray(result.errors)) {
						console.error('Collected errors:', result.errors)
					}
					return
				}

				console.log('SOLR warmup completed successfully:', result)

				// Store results and mark as completed
				this.warmupResults = result
				this.warmupCompleted = true

				// Show success details
				if (result.stats) {
					console.log('Warmup statistics:', result.stats)
				}

				// Refresh stats after warmup
				await this.loadSolrStats()

			} catch (error) {
				console.error('Failed to warmup SOLR index:', error)
			} finally {
				this.warmingUp = false
			}
		},

		/**
		 * Warmup SOLR index (legacy method for backward compatibility)
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async warmupIndex() {
			// Use default configuration for direct calls
			this.warmupConfig = {
				mode: 'serial',
				maxObjects: 0,
				batchSize: 1000,
				collectErrors: false,
			}
			await this.performWarmup()
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

/* Error State */
.error-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 60px 20px;
	text-align: center;
	max-width: 600px;
	margin: 0 auto;
}

.error-icon {
	font-size: 4rem;
	margin-bottom: 1rem;
}

.error-container h3 {
	color: var(--color-error);
	margin: 0 0 1rem 0;
	font-size: 1.5rem;
}

.error-message {
	color: var(--color-error);
	font-weight: 500;
	margin: 0 0 1rem 0;
	font-size: 1rem;
}

.error-description {
	color: var(--color-text-light);
	margin: 0 0 1rem 0;
	line-height: 1.5;
}

.error-reasons {
	text-align: left;
	color: var(--color-text-maxcontrast);
	margin: 0 0 2rem 0;
	padding-left: 1.5rem;
}

.error-reasons li {
	margin: 0.5rem 0;
	line-height: 1.4;
}

.error-actions {
	margin-top: 1rem;
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

/* Dialog content styles (consistent with EditObject.vue) */
.dialog-content {
	padding: 0 20px;
}

/* Warmup Dialog Styles */
.warmup-dialog {
	padding: 2rem;
	max-width: 1200px;
	width: 100%;
	max-height: 90vh;
	overflow-y: auto;
}

.warmup-header {
	margin-bottom: 1.5rem;
}

.warmup-header h3 {
	color: var(--color-primary);
	margin-bottom: 1rem;
	font-size: 1.2rem;
}

.warmup-description {
	color: var(--color-text-light);
	line-height: 1.5;
	margin: 0;
}

.warmup-form {
	margin-bottom: 1.5rem;
}

.form-section {
	margin-bottom: 1.5rem;
	padding-bottom: 1rem;
	border-bottom: 1px solid var(--color-border);
}

.form-section:last-child {
	border-bottom: none;
	margin-bottom: 0;
}

.form-section h4 {
	margin: 0 0 1rem 0;
	color: var(--color-text);
	font-size: 1rem;
}

.form-row {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	margin-bottom: 1rem;
}

.radio-group {
	display: flex;
	gap: 1rem;
	margin-bottom: 0.5rem;
}

.radio-group > * {
	flex: 1;
}

.form-label {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.form-label strong {
	color: var(--color-text);
	font-weight: 500;
}

.form-description {
	color: var(--color-text-light);
	font-size: 0.9rem;
	margin: 0;
	line-height: 1.4;
}

.form-input {
	display: flex;
	align-items: center;
	gap: 0.25rem;
}

.warmup-input-field {
	width: 100%;
	padding: 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background: var(--color-background);
	color: var(--color-text);
	font-size: 0.9rem;
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.5rem;
}

/* Warmup Loading State */
.warmup-loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	padding: 2rem 1rem;
}

.loading-spinner {
	margin-bottom: 1.5rem;
	color: var(--color-primary);
}

.warmup-loading h4 {
	margin: 0 0 1rem 0;
	color: var(--color-text);
	font-size: 1.1rem;
}

.loading-description {
	color: var(--color-text-light);
	line-height: 1.5;
	margin: 0 0 1.5rem 0;
	max-width: 400px;
}

.loading-details {
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 1rem;
	text-align: left;
	max-width: 300px;
	width: 100%;
}

.loading-details p {
	margin: 0.5rem 0;
	color: var(--color-text);
	font-size: 0.9rem;
}

.loading-details p:first-child {
	margin-top: 0;
}

.loading-details p:last-child {
	margin-bottom: 0;
}

/* Warmup Results State */
.warmup-results {
	padding: 1.5rem;
}

.results-header {
	text-align: center;
	margin-bottom: 2rem;
}

.success-icon {
	font-size: 3rem;
	margin-bottom: 1rem;
}

.results-header h4 {
	margin: 0 0 1rem 0;
	color: var(--color-success);
	font-size: 1.2rem;
}

.results-description {
	color: var(--color-text-light);
	line-height: 1.5;
	margin: 0;
}

.results-summary,
.results-stats,
.results-errors {
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 1.5rem;
	margin-bottom: 1.5rem;
}

.results-summary:last-child,
.results-stats:last-child,
.results-errors:last-child {
	margin-bottom: 0;
}

.results-summary h5,
.results-stats h5,
.results-errors h5 {
	margin: 0 0 1rem 0;
	color: var(--color-text);
	font-size: 1rem;
	font-weight: 600;
}

.results-details {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.results-details p {
	margin: 0;
	color: var(--color-text);
	font-size: 0.9rem;
}

.stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 1rem;
}

.stat-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.75rem;
	background: var(--color-background);
	border-radius: 6px;
	border: 1px solid var(--color-border);
}

.stat-label {
	color: var(--color-text-light);
	font-size: 0.9rem;
	font-weight: 500;
}

.stat-value {
	color: var(--color-text);
	font-weight: 600;
	font-size: 0.9rem;
}

.stat-value.error {
	color: var(--color-error);
}

.error-list {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.error-item {
	padding: 0.75rem;
	background: var(--color-error-light);
	border: 1px solid var(--color-error);
	border-radius: 6px;
	color: var(--color-error-text);
	font-size: 0.9rem;
	line-height: 1.4;
}

/* Enhanced Statistics Styles */
.stats-section {
	margin-bottom: 1.5rem;
	padding: 1rem;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.stats-section h6 {
	margin: 0 0 1rem 0;
	color: var(--color-text-dark);
	font-size: 0.9rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.operations-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 0.75rem;
}

.operation-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.5rem 0.75rem;
	background: var(--color-background);
	border-radius: 6px;
	border: 1px solid var(--color-border);
}

.operation-label {
	color: var(--color-text-light);
	font-size: 0.85rem;
	font-weight: 500;
}

.operation-status {
	font-size: 0.85rem;
	font-weight: 600;
}

.operation-status.success {
	color: var(--color-success);
}

.operation-status.error {
	color: var(--color-error);
}

.operation-status.neutral {
	color: var(--color-text-lighter);
}

.stat-value.success {
	color: var(--color-success);
	font-weight: 600;
}

.stat-value.warning {
	color: var(--color-warning);
	font-weight: 600;
}

.stat-value.error {
	color: var(--color-error);
	font-weight: 600;
}

/* Object Prediction Styles */
.object-prediction {
	margin-bottom: 1.5rem;
	padding: 1rem;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.prediction-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1rem;
}

.prediction-header h5 {
	margin: 0;
	color: var(--color-text);
	font-size: 1rem;
	font-weight: 600;
}

.loading-indicator {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	color: var(--color-text-light);
	font-size: 0.9rem;
}

.prediction-content {
	/* Content styles are handled by existing .prediction-stats */
}

.prediction-stats {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 0.75rem;
}

.prediction-stats .stat-item {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	padding: 0.75rem;
	background: var(--color-background);
	border: 1px solid var(--color-border);
	border-radius: 6px;
}

.prediction-stats .stat-label {
	color: var(--color-text-light);
	font-size: 0.85rem;
	font-weight: 500;
}

.prediction-stats .stat-value {
	color: var(--color-text);
	font-weight: 600;
	font-size: 0.9rem;
}

.limited-indicator {
	color: var(--color-text-light);
	font-size: 0.8rem;
	font-weight: normal;
	font-style: italic;
}

.prediction-error {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.75rem;
	background: var(--color-warning-light);
	border: 1px solid var(--color-warning);
	border-radius: 6px;
	color: var(--color-warning-text);
	font-size: 0.9rem;
}

.error-icon {
	font-size: 1rem;
}
</style>
