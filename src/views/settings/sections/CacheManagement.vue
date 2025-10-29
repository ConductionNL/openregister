<template>
	<SettingsSection 
		name="Cache Management"
		description="Monitor and manage API caching for optimal performance"
		:loading="loadingCache"
		loading-message="Loading cache statistics...">
		<template #actions>
			<NcButton
				type="secondary"
				:disabled="loading || clearingCache || loadingCache"
				@click="loadCacheStats">
				<template #icon>
					<NcLoadingIcon v-if="loadingCache" :size="20" />
					<Refresh v-else :size="20" />
				</template>
				Refresh
			</NcButton>
			<NcButton
				type="error"
				:disabled="loading || clearingCache || loadingCache || cacheStats.unavailable"
				@click="showClearCacheDialog">
				<template #icon>
					<NcLoadingIcon v-if="clearingCache" :size="20" />
					<Delete v-else :size="20" />
				</template>
				Clear Cache
			</NcButton>
		</template>

				<!-- Cache Unavailable Message -->
				<div v-if="cacheStats.unavailable" class="cache-unavailable">
					<div class="unavailable-message">
						<h4>⚠️ Cache Statistics Unavailable</h4>
						<p>Cache monitoring is not available. This can happen when:</p>
						<ul>
							<li>Cache systems are not properly configured</li>
							<li>Statistics collection is disabled for performance reasons</li>
							<li>Cache backends don't support statistics</li>
						</ul>
						<div v-if="cacheStats.errorMessage" class="error-details">
							<strong>Technical Details:</strong> {{ cacheStats.errorMessage }}
						</div>
						<p class="performance-note">
							<strong>Note:</strong> This is normal behavior as storing cache metadata in database tables would cause performance issues.
							Cache systems are working but detailed statistics are not collected.
						</p>
					</div>
				</div>

				<div v-else class="cache-content">
					<!-- Cache Overview -->
					<div class="cache-overview">
						<div class="cache-overview-cards">
							<div class="cache-overview-card">
								<h4>📈 Hit Rate</h4>
								<div class="cache-metric">
									<span class="metric-value" :class="hitRateClass">{{ cacheStats.overview.overallHitRate.toFixed(1) }}%</span>
									<span class="metric-label">Overall Success</span>
								</div>
							</div>
							<div class="cache-overview-card">
								<h4>💾 Total Size</h4>
								<div class="cache-metric">
									<span class="metric-value">{{ formatBytes(cacheStats.overview.totalCacheSize) }}</span>
									<span class="metric-label">Memory Used</span>
								</div>
							</div>
							<div class="cache-overview-card">
								<h4>🗃️ Entries</h4>
								<div class="cache-metric">
									<span class="metric-value">{{ cacheStats.overview.totalCacheEntries.toLocaleString() }}</span>
									<span class="metric-label">Cache Items</span>
								</div>
							</div>
							<div class="cache-overview-card">
								<h4>⚡ Performance</h4>
								<div class="cache-metric">
									<span class="metric-value performance-gain">{{ cacheStats.performance.performanceGain.toFixed(0) }}x</span>
									<span class="metric-label">Speed Boost</span>
								</div>
							</div>
						</div>
					</div>

					<!-- Cache Services Details -->
					<div class="cache-services">
						<h4>🔧 Cache Services</h4>
						<div class="cache-services-grid">
							<!-- Object Cache -->
							<div class="cache-service-card">
								<h5>Object Cache</h5>
								<div class="service-stats">
									<div class="service-stat">
										<span class="stat-label">Entries:</span>
										<span class="stat-value">{{ (cacheStats.services.object.entries || 0).toLocaleString() }}</span>
									</div>
									<div class="service-stat">
										<span class="stat-label">Hit Rate:</span>
										<span class="stat-value" :class="getHitRateClass(getServiceHitRate(cacheStats.services.object))">
											{{ getServiceHitRate(cacheStats.services.object).toFixed(1) }}%
										</span>
									</div>
									<div class="service-stat">
										<span class="stat-label">Memory:</span>
										<span class="stat-value">{{ formatBytes(cacheStats.services.object.memoryUsage || 0) }}</span>
									</div>
								</div>
							</div>

							<!-- Schema Cache -->
							<div class="cache-service-card">
								<h5>Schema Cache</h5>
								<div class="service-stats">
									<div class="service-stat">
										<span class="stat-label">Entries:</span>
										<span class="stat-value">{{ (cacheStats.services.schema.entries || 0).toLocaleString() }}</span>
									</div>
									<div class="service-stat">
										<span class="stat-label">Hit Rate:</span>
										<span class="stat-value" :class="getHitRateClass(getServiceHitRate(cacheStats.services.schema))">
											{{ getServiceHitRate(cacheStats.services.schema).toFixed(1) }}%
										</span>
									</div>
									<div class="service-stat">
										<span class="stat-label">Memory:</span>
										<span class="stat-value">{{ formatBytes(cacheStats.services.schema.memoryUsage || 0) }}</span>
									</div>
								</div>
							</div>

							<!-- Facet Cache -->
							<div class="cache-service-card">
								<h5>Facet Cache</h5>
								<div class="service-stats">
									<div class="service-stat">
										<span class="stat-label">Entries:</span>
										<span class="stat-value">{{ (cacheStats.services.facet.entries || 0).toLocaleString() }}</span>
									</div>
									<div class="service-stat">
										<span class="stat-label">Hit Rate:</span>
										<span class="stat-value" :class="getHitRateClass(getServiceHitRate(cacheStats.services.facet))">
											{{ getServiceHitRate(cacheStats.services.facet).toFixed(1) }}%
										</span>
									</div>
									<div class="service-stat">
										<span class="stat-label">Memory:</span>
										<span class="stat-value">{{ formatBytes(cacheStats.services.facet.memoryUsage || 0) }}</span>
									</div>
								</div>
							</div>

							<!-- Distributed Cache -->
							<div class="cache-service-card">
								<h5>Distributed Cache</h5>
								<div class="service-stats">
									<div class="service-stat">
										<span class="stat-label">Backend:</span>
										<span class="stat-value">{{ getDistributedCacheBackend() }}</span>
									</div>
									<div class="service-stat">
										<span class="stat-label">Status:</span>
										<span class="stat-value" :class="cacheStats.distributed.available ? 'status-enabled' : 'status-disabled'">
											{{ cacheStats.distributed.available ? 'Available' : 'Unavailable' }}
										</span>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Performance Metrics -->
					<div class="cache-performance">
						<h4>📊 Performance Metrics</h4>
						<div class="performance-table-container">
							<table class="performance-table">
								<thead>
									<tr>
										<th class="performance-table-header">
											Metric
										</th>
										<th class="performance-table-header">
											Current
										</th>
										<th class="performance-table-header">
											Target
										</th>
										<th class="performance-table-header">
											Status
										</th>
									</tr>
								</thead>
								<tbody>
									<tr class="performance-table-row">
										<td class="performance-table-label">
											Average Hit Time
										</td>
										<td class="performance-table-value">
											{{ cacheStats.performance.averageHitTime }}ms
										</td>
										<td class="performance-table-value">
											< 5ms
										</td>
										<td class="performance-table-value" :class="cacheStats.performance.averageHitTime < 5 ? 'status-enabled' : 'status-warning'">
											{{ cacheStats.performance.averageHitTime < 5 ? '✓ Good' : '⚠ Slow' }}
										</td>
									</tr>
									<tr class="performance-table-row">
										<td class="performance-table-label">
											Average Miss Time
										</td>
										<td class="performance-table-value">
											{{ cacheStats.performance.averageMissTime }}ms
										</td>
										<td class="performance-table-value">
											< 500ms
										</td>
										<td class="performance-table-value" :class="cacheStats.performance.averageMissTime < 500 ? 'status-enabled' : 'status-error'">
											{{ cacheStats.performance.averageMissTime < 500 ? '✓ Good' : '❌ Slow' }}
										</td>
									</tr>
									<tr class="performance-table-row">
										<td class="performance-table-label">
											Overall Hit Rate
										</td>
										<td class="performance-table-value">
											{{ cacheStats.overview.overallHitRate.toFixed(1) }}%
										</td>
										<td class="performance-table-value">
											≥ {{ cacheStats.performance.optimalHitRate }}%
										</td>
										<td class="performance-table-value" :class="getHitRateClass(cacheStats.overview.overallHitRate)">
											{{ getHitRateText(cacheStats.overview.overallHitRate) }}
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>

					<div class="cache-footer">
						<p class="cache-updated">
							Last updated: {{ formatDate(cacheStats.lastUpdated) }}
						</p>
					</div>
				</div>
			</div>
	</SettingsSection>

	<!-- Clear Cache Confirmation Dialog -->
		<NcDialog
			v-if="showClearCacheConfirmation"
			name="Clear Cache"
			:can-close="!clearingCache"
			@closing="hideClearCacheDialog">
			<div class="clear-cache-dialog">
				<div class="clear-cache-options">
					<h3>🗑️ Clear Cache</h3>
					<p class="warning-text">
						Select the type of cache to clear. This action cannot be undone and may temporarily impact performance.
					</p>

					<div class="cache-type-selection">
						<h4>Cache Type:</h4>
						<NcCheckboxRadioSwitch
							:checked.sync="clearCacheType"
							name="cache_type"
							value="all"
							type="radio">
							Clear All Cache (Recommended)
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:checked.sync="clearCacheType"
							name="cache_type"
							value="object"
							type="radio">
							Object Cache Only
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:checked.sync="clearCacheType"
							name="cache_type"
							value="schema"
							type="radio">
							Schema Cache Only
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:checked.sync="clearCacheType"
							name="cache_type"
							value="facet"
							type="radio">
							Facet Cache Only
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:checked.sync="clearCacheType"
							name="cache_type"
							value="distributed"
							type="radio">
							Distributed Cache Only
						</NcCheckboxRadioSwitch>
					</div>
				</div>
				<div class="dialog-actions">
					<NcButton
						:disabled="clearingCache"
						@click="hideClearCacheDialog">
						Cancel
					</NcButton>
					<NcButton
						type="error"
						:disabled="clearingCache"
						@click="performClearCache">
						<template #icon>
							<NcLoadingIcon v-if="clearingCache" :size="20" />
							<Delete v-else :size="20" />
						</template>
						{{ clearingCache ? 'Clearing...' : 'Clear Cache' }}
					</NcButton>
				</div>
			</div>
		</NcDialog>
	</div>
</template>

<script>
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../../store/settings.js'
import SettingsSection from '../../../components/shared/SettingsSection.vue'
import { NcButton, NcLoadingIcon, NcDialog, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'CacheManagement',

	components: {
		SettingsSection,
		NcButton,
		NcLoadingIcon,
		NcDialog,
		NcCheckboxRadioSwitch,
		Refresh,
		Delete,
	},

	computed: {
		...mapStores(useSettingsStore),

		cacheStats() {
			return this.settingsStore.cacheStats
		},

		loadingCache() {
			return this.settingsStore.loadingCache
		},

		clearingCache() {
			return this.settingsStore.clearingCache
		},

		loading() {
			return this.settingsStore.loading
		},

		showClearCacheConfirmation() {
			return this.settingsStore.showClearCacheConfirmation
		},

		clearCacheType: {
			get() {
				return this.settingsStore.clearCacheType
			},
			set(value) {
				this.settingsStore.clearCacheType = value
			},
		},

		/**
		 * Get CSS class for overall hit rate
		 *
		 * @return {string} CSS class name
		 */
		hitRateClass() {
			return this.getHitRateClass(this.cacheStats.overview?.overallHitRate || 0)
		},
	},

	methods: {
		/**
		 * Load cache statistics
		 */
		loadCacheStats() {
			this.settingsStore.loadCacheStats()
		},

		/**
		 * Show clear cache dialog
		 */
		showClearCacheDialog() {
			this.settingsStore.showClearCacheDialog()
		},

		/**
		 * Hide clear cache dialog
		 */
		hideClearCacheDialog() {
			this.settingsStore.hideClearCacheDialog()
		},

		/**
		 * Perform cache clearing
		 */
		performClearCache() {
			this.settingsStore.performClearCache()
		},

		/**
		 * Format bytes to human readable format
		 *
		 * @param {number} bytes Number of bytes
		 * @return {string} Formatted string
		 */
		formatBytes(bytes) {
			if (bytes === 0) return '0 B'

			const k = 1024
			const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))

			return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
		},

		/**
		 * Get service hit rate
		 *
		 * @param {object} service Service stats object
		 * @return {number} Hit rate percentage
		 */
		getServiceHitRate(service) {
			if (!service || !service.hits || !service.requests) {
				return 0
			}
			return (service.hits / service.requests) * 100
		},

		/**
		 * Get CSS class for hit rate
		 *
		 * @param {number} hitRate Hit rate percentage
		 * @return {string} CSS class name
		 */
		getHitRateClass(hitRate) {
			if (hitRate >= 80) return 'status-enabled'
			if (hitRate >= 60) return 'status-warning'
			return 'status-error'
		},

		/**
		 * Get hit rate text
		 *
		 * @param {number} hitRate Hit rate percentage
		 * @return {string} Status text
		 */
		getHitRateText(hitRate) {
			if (hitRate >= 80) return '✓ Excellent'
			if (hitRate >= 60) return '⚠ Good'
			return '❌ Poor'
		},

		/**
		 * Get distributed cache backend name
		 *
		 * @return {string} Backend name
		 */
		getDistributedCacheBackend() {
			if (!this.cacheStats.distributed) {
				return 'Unknown'
			}
			return this.cacheStats.distributed.backend || 'File'
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
				return date.toLocaleString()
			} catch (e) {
				return 'Invalid date'
			}
		},
	},
}
</script>

<style scoped>
/* SettingsSection handles all action button positioning and spacing */

.cache-unavailable {
	background: rgba(var(--color-warning), 0.1);
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius-large);
	padding: 20px;
	margin-bottom: 20px;
}

.unavailable-message h4 {
	color: var(--color-warning);
	margin: 0 0 12px 0;
}

.unavailable-message p {
	margin: 8px 0;
	color: var(--color-text-light);
}

.unavailable-message ul {
	margin: 8px 0 8px 20px;
	color: var(--color-text-maxcontrast);
}

.error-details {
	background: var(--color-background-dark);
	padding: 8px 12px;
	border-radius: var(--border-radius);
	margin: 12px 0;
	font-family: monospace;
	font-size: 12px;
}

.performance-note {
	background: rgba(var(--color-primary), 0.1);
	border-left: 3px solid var(--color-primary);
	padding: 12px;
	margin: 12px 0 0 0;
}

.cache-content {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.cache-overview-cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 16px;
	margin-bottom: 24px;
}

.cache-overview-card {
	background: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	text-align: center;
}

.cache-overview-card h4 {
	margin: 0 0 12px 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.cache-metric {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.metric-value {
	font-size: 24px;
	font-weight: bold;
	color: var(--color-text-light);
}

.metric-value.performance-gain {
	color: var(--color-success);
}

.metric-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.cache-services h4 {
	margin: 0 0 16px 0;
	color: var(--color-text-light);
}

.cache-services-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 16px;
}

.cache-service-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 16px;
}

.cache-service-card h5 {
	margin: 0 0 12px 0;
	color: var(--color-text-light);
}

.service-stats {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.service-stat {
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

.cache-performance h4 {
	margin: 0 0 16px 0;
	color: var(--color-text-light);
}

.performance-table-container {
	overflow-x: auto;
}

.performance-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 14px;
}

.performance-table-header {
	text-align: left;
	padding: 12px 8px;
	border-bottom: 2px solid var(--color-border);
	background: var(--color-background-hover);
	font-weight: 600;
	color: var(--color-text-light);
}

.performance-table-row {
	border-bottom: 1px solid var(--color-border-dark);
}

.performance-table-row:hover {
	background: var(--color-background-hover);
}

.performance-table-label {
	padding: 12px 8px;
	color: var(--color-text-light);
	font-weight: 500;
}

.performance-table-value {
	padding: 12px 8px;
	color: var(--color-text-maxcontrast);
	text-align: right;
	font-family: monospace;
	font-size: 13px;
}

.cache-footer {
	text-align: center;
	margin-top: 16px;
}

.cache-updated {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	margin: 0;
}

.loading-icon {
	margin: 40px auto;
	display: block;
}

/* Status classes */
.status-enabled {
	color: var(--color-success) !important;
}

.status-warning {
	color: var(--color-warning) !important;
}

.status-error {
	color: var(--color-error) !important;
}

.status-disabled {
	color: var(--color-text-maxcontrast) !important;
}

/* Dialog styles */
.clear-cache-dialog {
	padding: 20px;
}

.clear-cache-options h3 {
	margin: 0 0 16px 0;
	color: var(--color-text-light);
}

.warning-text {
	color: var(--color-text-light);
	margin: 0 0 20px 0;
	line-height: 1.5;
}

.cache-type-selection h4 {
	margin: 0 0 12px 0;
	color: var(--color-text-light);
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 20px;
}

@media (max-width: 768px) {
	.cache-overview-cards {
		grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	}

	.cache-services-grid {
		grid-template-columns: 1fr;
	}

	.section-header-inline {
		position: static;
		flex-direction: column;
		gap: 12px;
		align-items: stretch;
		margin-bottom: 20px;
	}

	.button-group {
		justify-content: center;
	}
}
</style>
