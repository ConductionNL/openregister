<template>
	<NcDialog
		v-if="show"
		name="Mass Validate Objects"
		:can-close="!massValidating"
		size="large"
		@closing="$emit('close')">
		<div class="dialog-content">
			<p class="validate-description">
				Configure mass validation parameters for object processing. This operation will re-save objects in the system to trigger business logic validation and processing according to current rules and schemas.
			</p>

			<!-- Loading State -->
			<div v-if="massValidating" class="validate-loading">
				<div class="loading-spinner">
					<NcLoadingIcon :size="40" />
				</div>
				<h4>Mass Validating Objects...</h4>
				<p class="loading-description">
					Please wait while objects are being processed. This may take several minutes depending on the amount of data and configuration.
				</p>
				<div class="loading-details">
					<p><strong>Mode:</strong> {{ config.mode === 'serial' ? 'Serial' : 'Parallel' }}</p>
					<p><strong>Max Objects:</strong> {{ config.maxObjects === 0 ? 'All' : config.maxObjects }}</p>
					<p><strong>Batch Size:</strong> {{ config.batchSize }}</p>
					<p><strong>Error Handling:</strong> {{ config.collectErrors ? 'Collect all errors' : 'Stop on first error' }}</p>
				</div>
			</div>

			<!-- Results State -->
			<div v-else-if="completed && results" class="validate-results">
				<!-- Overall Status -->
				<div class="results-header">
					<div class="status-icon" :class="results.success ? 'success' : 'error'">
						{{ results.success ? '✅' : '❌' }}
					</div>
					<h4 :class="results.success ? 'success-text' : 'error-text'">
						{{ results.success ? 'Mass Validation Completed Successfully!' : 'Mass Validation Failed' }}
					</h4>
					<p class="results-message">
						{{ results.message }}
					</p>
				</div>

				<!-- Error Details (prominent display) -->
				<div v-if="!results.success && results.error" class="error-banner">
					<div class="error-header">
						<span class="error-icon">⚠️</span>
						<h5>Error Details</h5>
					</div>
					<div class="error-content">
						<div class="error-message">
							{{ results.error }}
						</div>
						<details v-if="results.error_details || hasDetailedError" class="error-details-toggle">
							<summary>Show Technical Details</summary>
							<pre class="error-details-content">{{ formatErrorDetails() }}</pre>
						</details>
					</div>
				</div>

				<!-- Performance Summary -->
				<div v-if="results.success || results.stats" class="performance-summary">
					<h5>Performance Summary</h5>
					<div class="summary-grid">
						<div class="summary-item">
							<span class="summary-label">Execution Time:</span>
							<span class="summary-value">{{ formatExecutionTime(results.execution_time_ms || (results.stats?.duration_seconds * 1000)) }}</span>
						</div>
						<div v-if="results.stats?.processed_objects !== undefined" class="summary-item">
							<span class="summary-label">Objects Processed:</span>
							<span class="summary-value">{{ results.stats.processed_objects.toLocaleString() }}</span>
						</div>
						<div v-if="results.stats?.successful_saves !== undefined" class="summary-item">
							<span class="summary-label">Successful Saves:</span>
							<span class="summary-value">{{ results.stats.successful_saves.toLocaleString() }}</span>
						</div>
						<div v-if="results.stats?.failed_saves !== undefined" class="summary-item">
							<span class="summary-label">Failed Saves:</span>
							<span class="summary-value" :class="{ 'error': results.stats.failed_saves > 0 }">{{ results.stats.failed_saves }}</span>
						</div>
						<div v-if="results.batches_processed !== undefined" class="summary-item">
							<span class="summary-label">Batches Processed:</span>
							<span class="summary-value">{{ results.batches_processed }}</span>
						</div>
						<div v-if="results.memory_usage" class="summary-item">
							<span class="summary-label">Memory Used:</span>
							<span class="summary-value">{{ results.memory_usage.formatted.actual_used }}</span>
						</div>
						<div v-if="results.memory_usage && results.memory_usage.formatted.peak_percentage" class="summary-item">
							<span class="summary-label">Peak Memory:</span>
							<span class="summary-value" :class="getMemoryUsageClass(results.memory_usage.peak_percentage)">
								{{ results.memory_usage.formatted.peak_usage }} ({{ results.memory_usage.formatted.peak_percentage }})
							</span>
						</div>
					</div>
				</div>

				<!-- Success Rate Display -->
				<div v-if="results.stats" class="success-rate-display">
					<h5>Success Rate</h5>
					<div class="success-rate-container">
						<div class="success-rate-bar">
							<div class="success-rate-fill"
								:style="{ width: getSuccessRate() + '%' }"
								:class="getSuccessRateClass()" />
						</div>
						<div class="success-rate-text">
							{{ getSuccessRate().toFixed(1) }}% Success Rate
							({{ results.stats.successful_saves }} / {{ results.stats.total_objects }} objects)
						</div>
					</div>
				</div>

				<!-- Error Collection Results -->
				<div v-if="results.errors && results.errors.length > 0" class="error-collection">
					<h5>Collected Errors ({{ results.errors.length }})</h5>
					<div class="error-list-container">
						<div v-for="(error, index) in results.errors.slice(0, 10)" :key="index" class="error-item">
							<div class="error-item-header">
								<span class="error-index">#{{ index + 1 }}</span>
								<span v-if="error.object_id" class="error-object-id">Object ID: {{ error.object_id }}</span>
							</div>
							<div class="error-item-message">
								{{ error.error || error.message }}
							</div>
						</div>
						<div v-if="results.errors.length > 10" class="error-overflow">
							... and {{ results.errors.length - 10 }} more errors
						</div>
					</div>
				</div>

				<!-- Configuration Used -->
				<div class="config-used">
					<h5>Configuration Used</h5>
					<div class="config-grid">
						<div class="config-item">
							<span class="config-label">Mode:</span>
							<span class="config-value">{{ config.mode === 'serial' ? 'Serial' : 'Parallel' }}</span>
						</div>
						<div class="config-item">
							<span class="config-label">Max Objects:</span>
							<span class="config-value">{{ config.maxObjects === 0 ? 'All' : config.maxObjects }}</span>
						</div>
						<div class="config-item">
							<span class="config-label">Batch Size:</span>
							<span class="config-value">{{ config.batchSize }}</span>
						</div>
						<div class="config-item">
							<span class="config-label">Error Handling:</span>
							<span class="config-value">{{ config.collectErrors ? 'Collect errors' : 'Stop on first error' }}</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Configuration Form -->
			<div v-else class="validate-form">
				<div class="form-section">
					<h4>⚠️ Important Information</h4>
					<div class="warning-box">
						<ul>
							<li><strong>Processing:</strong> All objects will be re-saved to trigger validation and business logic</li>
							<li><strong>Duration:</strong> This may take several minutes depending on the number of objects and configuration</li>
							<li><strong>Impact:</strong> Objects will be updated with current processing rules</li>
							<li><strong>Safety:</strong> This operation is safe and will not delete data</li>
						</ul>
					</div>
				</div>

				<div class="form-section">
					<h4>Execution Mode</h4>
					<div class="radio-group">
						<NcCheckboxRadioSwitch
							:checked.sync="localConfig.mode"
							name="validate_mode"
							value="serial"
							type="radio">
							Serial Mode (Safer, slower)
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:checked.sync="localConfig.mode"
							name="validate_mode"
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
										{{ localConfig.maxObjects === 0 ? objectStats.totalObjects.toLocaleString() : Math.min(localConfig.maxObjects, objectStats.totalObjects).toLocaleString() }}
										<span v-if="localConfig.maxObjects > 0 && localConfig.maxObjects < objectStats.totalObjects" class="limited-indicator">
											(limited by max objects setting)
										</span>
									</span>
								</div>
								<div class="stat-item">
									<span class="stat-label">Estimated Batches:</span>
									<span class="stat-value">
										{{ Math.ceil((localConfig.maxObjects === 0 ? objectStats.totalObjects : Math.min(localConfig.maxObjects, objectStats.totalObjects)) / localConfig.batchSize) }}
									</span>
								</div>
								<div class="stat-item">
									<span class="stat-label">Estimated Duration:</span>
									<span class="stat-value">
										{{ estimateValidationDuration() }}
									</span>
								</div>
								<div class="stat-item">
									<span class="stat-label">Memory Prediction:</span>
									<span v-if="memoryPredictionLoading" class="stat-value loading">
										<NcLoadingIcon :size="16" />
										Loading...
									</span>
									<span v-else class="stat-value" :class="{ 'warning': !memoryPrediction.prediction_safe }">
										{{ formatMemoryPrediction() }}
									</span>
								</div>
							</div>
						</div>
						<div v-else-if="!objectStats.loading" class="prediction-error">
							<span>Unable to load object count. Validation will process all available objects.</span>
						</div>
					</div>

					<div class="form-row">
						<label class="form-label">
							<strong>Max Objects (0 = all)</strong>
							<p class="form-description">Maximum number of objects to process. Set to 0 to process all objects.</p>
						</label>
						<div class="form-input">
							<input
								v-model.number="localConfig.maxObjects"
								type="number"
								:disabled="massValidating"
								placeholder="0"
								min="0"
								class="validate-input-field">
						</div>
					</div>

					<div class="form-row">
						<label class="form-label">
							<strong>Batch Size</strong>
							<p class="form-description">Number of objects to process in each batch (1-5000).</p>
						</label>
						<div class="form-input">
							<input
								v-model.number="localConfig.batchSize"
								type="number"
								:disabled="massValidating"
								placeholder="1000"
								min="1"
								max="5000"
								class="validate-input-field">
						</div>
					</div>
				</div>

				<div class="form-section">
					<h4>Error Handling</h4>
					<NcCheckboxRadioSwitch
						v-model="localConfig.collectErrors"
						:disabled="massValidating"
						type="switch">
						Continue on errors (collect all errors)
					</NcCheckboxRadioSwitch>
					<p class="form-description">
						<strong>When enabled:</strong> Validation continues processing even if errors occur, collecting all errors for review at the end.<br>
						<strong>When disabled:</strong> Validation stops immediately when the first error is encountered.
					</p>
				</div>
			</div>
		</div>

		<template #actions>
			<div class="modal-actions">
				<NcButton
					:disabled="massValidating"
					@click="$emit('close')">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ massValidating ? 'Close' : (completed ? 'Close' : 'Cancel') }}
				</NcButton>

				<NcButton
					v-if="!massValidating && !completed"
					type="primary"
					@click="startMassValidate">
					<template #icon>
						<CheckCircle :size="20" />
					</template>
					Start Mass Validation
				</NcButton>

				<NcButton
					v-if="completed"
					type="secondary"
					@click="resetModal">
					<template #icon>
						<Refresh :size="20" />
					</template>
					Run Again
				</NcButton>
			</div>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'MassValidateModal',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		CheckCircle,
		Refresh,
		Cancel,
	},

	props: {
		show: {
			type: Boolean,
			default: false,
		},
		objectStats: {
			type: Object,
			default: () => ({
				loading: false,
				totalObjects: 0,
			}),
		},
		massValidating: {
			type: Boolean,
			default: false,
		},
		completed: {
			type: Boolean,
			default: false,
		},
		results: {
			type: Object,
			default: null,
		},
		config: {
			type: Object,
			default: () => ({
				mode: 'serial',
				maxObjects: 0,
				batchSize: 1000,
				collectErrors: false,
			}),
		},
		memoryPrediction: {
			type: Object,
			default: () => ({
				prediction_safe: true,
				formatted: {
					total_predicted: 'Unknown',
					available: 'Unknown',
				},
			}),
		},
		memoryPredictionLoading: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'start-validate', 'retry', 'reset'],

	data() {
		return {
			localConfig: { ...this.config },
		}
	},

	watch: {
		config: {
			handler(newConfig) {
				this.localConfig = { ...newConfig }
			},
			deep: true,
		},
		localConfig: {
			handler(newConfig) {
				this.$emit('config-changed', newConfig)
			},
			deep: true,
		},
	},

	methods: {
		startMassValidate() {
			this.$emit('start-validate', this.localConfig)
		},

		retryValidation() {
			this.$emit('retry')
		},

		resetModal() {
			this.$emit('reset')
		},

		/**
		 * Estimate validation duration based on configuration
		 *
		 * @return {string} Estimated duration in human-readable format
		 */
		estimateValidationDuration() {
			if (this.objectStats.totalObjects === 0) {
				return 'Unknown'
			}

			const totalObjects = this.localConfig.maxObjects === 0
				? this.objectStats.totalObjects
				: Math.min(this.localConfig.maxObjects, this.objectStats.totalObjects)

			const batches = Math.ceil(totalObjects / this.localConfig.batchSize)

			// Rough estimates based on mode and batch size
			// Serial: ~3-6 seconds per batch, Parallel: ~1.5-3 seconds per batch
			const secondsPerBatch = this.localConfig.mode === 'serial' ? 4 : 2
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
		 * Format execution time from milliseconds to human readable format
		 *
		 * @param {number} milliseconds Execution time in milliseconds
		 * @return {string} Formatted execution time
		 */
		formatExecutionTime(milliseconds) {
			if (!milliseconds) return 'Unknown'

			if (milliseconds < 1000) {
				return `${milliseconds.toFixed(0)}ms`
			} else if (milliseconds < 60000) {
				return `${(milliseconds / 1000).toFixed(2)}s`
			} else {
				const minutes = Math.floor(milliseconds / 60000)
				const seconds = (milliseconds % 60000) / 1000
				return `${minutes}m ${seconds.toFixed(1)}s`
			}
		},

		/**
		 * Format memory prediction for display
		 *
		 * @return {string} Formatted memory prediction
		 */
		formatMemoryPrediction() {
			if (!this.memoryPrediction || this.memoryPrediction.error) {
				return 'Unable to predict'
			}

			const prediction = this.memoryPrediction.formatted
			if (!prediction) {
				return 'Unknown'
			}

			return `${prediction.total_predicted} / ${prediction.available} available`
		},

		/**
		 * Get CSS class for memory usage display
		 *
		 * @param {number} percentage Memory usage percentage
		 * @return {string} CSS class
		 */
		getMemoryUsageClass(percentage) {
			const numPercentage = parseFloat(percentage)
			if (numPercentage >= 90) return 'error'
			if (numPercentage >= 75) return 'warning'
			return ''
		},

		/**
		 * Check if there are detailed error information available
		 *
		 * @return {boolean} True if detailed error info is available
		 */
		hasDetailedError() {
			return !!(this.results?.error_details
					 || (this.results?.error && this.results.error.length > 100))
		},

		/**
		 * Format error details for display
		 *
		 * @return {string} Formatted error details
		 */
		formatErrorDetails() {
			if (this.results?.error_details) {
				return typeof this.results.error_details === 'string'
					? this.results.error_details
					: JSON.stringify(this.results.error_details, null, 2)
			}

			return this.results?.error || 'No detailed error information available'
		},

		getSuccessRate() {
			if (!this.results || !this.results.stats) return 0
			const { successfulSaves, totalObjects } = this.results.stats
			if (totalObjects === 0) return 100
			return (successfulSaves / totalObjects) * 100
		},

		getSuccessRateClass() {
			const rate = this.getSuccessRate()
			if (rate >= 95) return 'success'
			if (rate >= 80) return 'warning'
			return 'error'
		},
	},
}
</script>

<style scoped>
/* Dialog content styles (consistent with SolrWarmupModal.vue) */
.dialog-content {
	padding: 0 20px;
}

.validate-description {
	color: var(--color-text-light);
	line-height: 1.5;
	margin: 0 0 1.5rem 0;
}

.validate-form {
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
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	line-height: 1.4;
	margin: 0;
}

.form-input {
	display: flex;
	align-items: center;
}

.validate-input-field {
	width: 100%;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-text);
	font-size: 14px;
}

.validate-input-field:focus {
	outline: none;
	border-color: var(--color-primary);
	box-shadow: 0 0 0 2px rgba(var(--color-primary-rgb), 0.2);
}

.warning-box {
	background: rgba(var(--color-warning), 0.1);
	border: 1px solid var(--color-warning);
	border-radius: 8px;
	padding: 16px;
}

.warning-box ul {
	margin: 0;
	padding-left: 20px;
	color: var(--color-text-light);
}

.warning-box li {
	margin: 8px 0;
	line-height: 1.4;
}

/* Object prediction styles */
.object-prediction {
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 1rem;
	margin-bottom: 1rem;
}

.prediction-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 0.5rem;
}

.prediction-header h5 {
	margin: 0;
	color: var(--color-text);
	font-size: 0.95rem;
}

.loading-indicator {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.prediction-content {
	margin-top: 1rem;
}

.prediction-stats {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 0.75rem;
}

.stat-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.5rem;
	background-color: var(--color-main-background);
	border-radius: var(--border-radius-small);
}

.stat-label {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.stat-value {
	color: var(--color-text);
	font-weight: 500;
}

.stat-value.loading {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.limited-indicator {
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
	font-style: italic;
}

.prediction-error {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.75rem;
	background-color: var(--color-warning);
	color: var(--color-text-dark);
	border-radius: var(--border-radius);
	margin-top: 1rem;
}

/* Loading state styles */
.validate-loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	padding: 2rem;
}

.loading-spinner {
	margin-bottom: 1rem;
}

.validate-loading h4 {
	color: var(--color-primary);
	margin: 0 0 1rem 0;
}

.loading-description {
	color: var(--color-text-light);
	margin: 0 0 1.5rem 0;
	line-height: 1.5;
}

.loading-details {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	align-items: center;
}

.loading-details p {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

/* Results state styles (consistent with SolrWarmupModal) */
.validate-results {
	padding: 1rem 0;
}

/* Error Banner Styles */
.error-banner {
	background-color: rgba(var(--color-error-rgb), 0.1);
	border: 1px solid var(--color-error);
	border-radius: var(--border-radius);
	padding: 1rem;
	margin-bottom: 1.5rem;
}

.error-header {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 1rem;
}

.error-icon {
	font-size: 1.2rem;
}

.error-header h5 {
	margin: 0;
	color: var(--color-error);
	font-size: 1.1rem;
}

.error-content {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.error-message {
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 1rem;
	color: var(--color-text);
	font-family: monospace;
	font-size: 0.9rem;
	line-height: 1.4;
	white-space: pre-wrap;
	word-break: break-word;
	max-height: 200px;
	overflow-y: auto;
}

.error-details-toggle {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.error-details-toggle summary {
	padding: 0.75rem;
	cursor: pointer;
	font-weight: 500;
	color: var(--color-text);
	user-select: none;
}

.error-details-toggle summary:hover {
	background-color: var(--color-background-darker);
}

.error-details-content {
	margin: 0;
	padding: 1rem;
	background-color: var(--color-main-background);
	border-top: 1px solid var(--color-border);
	color: var(--color-text-light);
	font-family: monospace;
	font-size: 0.85rem;
	line-height: 1.4;
	white-space: pre-wrap;
	word-break: break-word;
	max-height: 300px;
	overflow-y: auto;
}

.results-header {
	text-align: center;
	margin-bottom: 2rem;
}

.status-icon {
	font-size: 3rem;
	margin-bottom: 1rem;
}

.results-header h4 {
	margin: 0 0 1rem 0;
	font-size: 1.2rem;
}

.success-text {
	color: var(--color-success);
}

.error-text {
	color: var(--color-error);
}

.results-message {
	color: var(--color-text-light);
	line-height: 1.5;
	margin: 0;
}

/* Performance Summary */
.performance-summary {
	margin-bottom: 2rem;
}

.performance-summary h5 {
	margin: 0 0 1rem 0;
	color: var(--color-text);
	font-size: 1.1rem;
}

.summary-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 0.75rem;
}

.summary-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.75rem;
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 0.9rem;
}

.summary-label {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.summary-value {
	color: var(--color-text);
	font-weight: 500;
}

.summary-value.error {
	color: var(--color-error);
}

.summary-value.warning,
.stat-value.warning {
	color: var(--color-warning);
}

/* Success Rate Display */
.success-rate-display {
	margin-bottom: 2rem;
}

.success-rate-display h5 {
	margin: 0 0 1rem 0;
	color: var(--color-text);
	font-size: 1.1rem;
}

.success-rate-container {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.success-rate-bar {
	width: 100%;
	height: 12px;
	background: var(--color-background-dark);
	border-radius: 6px;
	overflow: hidden;
}

.success-rate-fill {
	height: 100%;
	transition: width 0.3s ease;
	border-radius: 6px;
}

.success-rate-fill.success {
	background: linear-gradient(90deg, var(--color-success-light) 0%, var(--color-success) 100%);
}

.success-rate-fill.warning {
	background: linear-gradient(90deg, var(--color-warning-light) 0%, var(--color-warning) 100%);
}

.success-rate-fill.error {
	background: linear-gradient(90deg, var(--color-error-light) 0%, var(--color-error) 100%);
}

.success-rate-text {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

/* Error Collection Results */
.error-collection {
	margin-bottom: 2rem;
}

.error-collection h5 {
	margin: 0 0 1rem 0;
	color: var(--color-error);
	font-size: 1.1rem;
}

.error-list-container {
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 1rem;
	max-height: 300px;
	overflow-y: auto;
}

.error-item {
	padding: 0.75rem;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 0.5rem;
}

.error-item:last-child {
	margin-bottom: 0;
}

.error-item-header {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	margin-bottom: 0.5rem;
}

.error-index {
	background: var(--color-error);
	color: white;
	padding: 2px 6px;
	border-radius: 3px;
	font-size: 0.8rem;
	font-weight: 500;
}

.error-object-id {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	font-family: monospace;
}

.error-item-message {
	color: var(--color-text);
	font-size: 0.9rem;
	line-height: 1.4;
	word-break: break-word;
}

.error-overflow {
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	margin-top: 0.5rem;
}

/* Configuration Used */
.config-used {
	margin-bottom: 2rem;
}

.config-used h5 {
	margin: 0 0 1rem 0;
	color: var(--color-text);
	font-size: 1.1rem;
}

.config-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 0.75rem;
}

.config-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.75rem;
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 0.9rem;
}

.config-label {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.config-value {
	color: var(--color-text);
	font-family: monospace;
}

/* Responsive Design */
@media (max-width: 768px) {
	.summary-grid,
	.config-grid {
		grid-template-columns: 1fr;
	}

	.summary-item,
	.config-item {
		flex-direction: column;
		align-items: flex-start;
		gap: 0.25rem;
	}

	.summary-value,
	.config-value {
		text-align: left;
	}

	.radio-group {
		flex-direction: column;
		gap: 0.5rem;
	}

	.radio-group > * {
		flex: none;
	}
}

.modal-actions {
	display: flex;
	gap: 0.5rem;
	justify-content: flex-end;
}
</style>
