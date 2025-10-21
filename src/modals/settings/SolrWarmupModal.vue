<template>
	<NcDialog
		v-if="show"
		name="SOLR Index Warmup"
		:can-close="!warmingUp"
		size="large"
		@closing="$emit('close')">
		<div class="dialog-content">
			<p class="warmup-description">
				Configure warmup parameters for SOLR index initialization. This process will mirror schemas and index objects into SOLR for enhanced search performance.
			</p>

			<!-- Loading State -->
			<div v-if="warmingUp" class="warmup-loading">
				<div class="loading-spinner">
					<NcLoadingIcon :size="40" />
				</div>
				<h4>Warming up SOLR Index...</h4>
				<p class="loading-description">
					Please wait while the SOLR index is being warmed up. This process may take several minutes depending on the amount of data.
				</p>
				<div class="loading-details">
					<p><strong>Mode:</strong> {{ getModeDisplayName(config.mode) }}</p>
					<p><strong>Max Objects:</strong> {{ config.maxObjects === 0 ? 'All' : config.maxObjects }}</p>
					<p><strong>Batch Size:</strong> {{ config.batchSize }}</p>
				</div>
			</div>

			<!-- Results State -->
			<div v-else-if="completed && results" class="warmup-results">
				<!-- Overall Status -->
				<div class="results-header">
					<div class="status-icon" :class="results.success ? 'success' : 'error'">
						{{ results.success ? '✅' : '❌' }}
					</div>
					<h4 :class="results.success ? 'success-text' : 'error-text'">
						{{ results.success ? 'Warmup Completed Successfully!' : 'Warmup Failed' }}
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
				<div v-if="results.success" class="performance-summary">
					<h5>Performance Summary</h5>
					<div class="summary-grid">
						<div class="summary-item">
							<span class="summary-label">Execution Time:</span>
							<span class="summary-value">{{ formatExecutionTime(results.execution_time_ms) }}</span>
						</div>
						<div v-if="results.objects_indexed !== undefined" class="summary-item">
							<span class="summary-label">Objects Indexed:</span>
							<span class="summary-value">{{ results.objects_indexed.toLocaleString() }}</span>
						</div>
						<div v-if="results.batches_processed !== undefined" class="summary-item">
							<span class="summary-label">Batches Processed:</span>
							<span class="summary-value">{{ results.batches_processed }}</span>
						</div>
						<div v-if="results.indexing_errors !== undefined" class="summary-item">
							<span class="summary-label">Indexing Errors:</span>
							<span class="summary-value" :class="{ 'error': results.indexing_errors > 0 }">{{ results.indexing_errors }}</span>
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

				<!-- Operations Status -->
				<div v-if="results.operations" class="operations-status">
					<h5>Operation Results</h5>
					<div class="operations-grid">
						<div
							v-for="(status, operation) in results.operations"
							:key="operation"
							class="operation-item"
							:class="getOperationStatus(status)">
							<div class="operation-header">
								<span class="operation-icon">{{ getOperationIcon(status) }}</span>
								<h6>{{ formatOperationName(operation) }}</h6>
								<span class="operation-status">{{ getOperationStatusText(status) }}</span>
							</div>
							<div v-if="getOperationDetails(operation, status)" class="operation-details">
								{{ getOperationDetails(operation, status) }}
							</div>
						</div>
					</div>
				</div>

				<!-- Configuration Used -->
				<div class="config-used">
					<h5>Configuration Used</h5>
					<div class="config-grid">
						<div class="config-item">
							<span class="config-label">Mode:</span>
							<span class="config-value">{{ getModeDisplayName(config.mode) }}</span>
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

				<!-- Error Details (if any) -->
				<div v-if="!results.success && results.error_details" class="error-details">
					<h5>Error Details</h5>
					<div class="error-content">
						<pre>{{ JSON.stringify(results.error_details, null, 2) }}</pre>
					</div>
				</div>
			</div>

			<!-- Configuration Form -->
			<div v-else class="warmup-form">
				<div class="form-section">
					<h4>Execution Mode</h4>
					<div class="radio-group">
						<NcCheckboxRadioSwitch
							:checked.sync="localConfig.mode"
							name="warmup_mode"
							value="serial"
							type="radio">
							Serial Mode (Safer, slower)
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:checked.sync="localConfig.mode"
							name="warmup_mode"
							value="parallel"
							type="radio">
							Parallel Mode (Faster, more resource intensive)
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:checked.sync="localConfig.mode"
							name="warmup_mode"
							value="hyper"
							type="radio">
							Hyper Mode (Fastest, optimized for large datasets)
						</NcCheckboxRadioSwitch>
					</div>
					<p class="form-description">
						<strong>Serial:</strong> Processes objects sequentially (safest).<br>
						<strong>Parallel:</strong> Processes objects in chunks with simulated parallelism.<br>
						<strong>Hyper:</strong> Optimized processing with better performance monitoring (recommended for large datasets).
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
						<div v-if="!objectStats.loading && effectiveTotalObjects > 0" class="prediction-content">
							<div class="prediction-stats">
								<div class="stat-item">
									<span class="stat-label">Total Objects in Database:</span>
									<span class="stat-value">{{ objectStats.totalObjects.toLocaleString() }}</span>
								</div>
								<div class="stat-item">
									<span class="stat-label">Objects to Process:</span>
									<span class="stat-value">
										{{ localConfig.maxObjects === 0 ? effectiveTotalObjects.toLocaleString() : Math.min(localConfig.maxObjects, effectiveTotalObjects).toLocaleString() }}
										<span v-if="localConfig.maxObjects > 0 && localConfig.maxObjects < effectiveTotalObjects" class="limited-indicator">
											(limited by max objects setting)
										</span>
									</span>
								</div>
								<div class="stat-item">
									<span class="stat-label">Estimated Batches:</span>
									<span class="stat-value">
										{{ Math.ceil((localConfig.maxObjects === 0 ? effectiveTotalObjects : Math.min(localConfig.maxObjects, effectiveTotalObjects)) / localConfig.batchSize) }}
									</span>
								</div>
								<div class="stat-item">
									<span class="stat-label">Estimated Duration:</span>
									<span class="stat-value">
										{{ estimateWarmupDuration() }}
									</span>
								</div>
								<div class="stat-item">
									<span class="stat-label">Memory Prediction:</span>
									<span class="stat-value" :class="{ 'warning': !memoryPrediction.prediction_safe }">
										{{ formatMemoryPrediction() }}
									</span>
								</div>
							</div>
						</div>
						<div v-else-if="!objectStats.loading" class="prediction-error">
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
								v-model.number="localConfig.maxObjects"
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
								v-model.number="localConfig.batchSize"
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
					<h4>Schema Selection</h4>
					<div class="form-row">
						<label class="form-label">
							<strong>Select Schemas to Warm Up</strong>
							<p class="form-description">Choose which schemas to include in the warmup process. Leave empty to process all schemas.</p>
						</label>
						<div class="form-input">
							<NcSelect
								v-model="localConfig.selectedSchemas"
								:options="availableSchemas"
								:disabled="warmingUp"
								:loading="schemasLoading"
								multiple
								label="label"
								track-by="id"
								placeholder="Select schemas (empty = all schemas)"
								input-label="Select schemas to warm up"
								:label-outside="true"
								class="schema-select">
								<template #option="option">
									<div v-if="option" class="schema-option">
										<span class="schema-name">{{ option.label || 'Unknown' }}</span>
										<span class="schema-objects">({{ option.objectCount || 0 }} objects)</span>
									</div>
								</template>
							</NcSelect>
						</div>
					</div>
					<div v-if="localConfig.selectedSchemas && localConfig.selectedSchemas.length > 0" class="selected-schemas-summary">
						<p><strong>Selected:</strong> {{ localConfig.selectedSchemas.length }} schema(s)</p>
						<div class="selected-schema-list">
							<span
								v-for="schema in selectedSchemasDetails"
								:key="schema.id"
								class="selected-schema-tag">
								{{ schema.label }} ({{ schema.objectCount || 0 }})
							</span>
						</div>
					</div>
				</div>

				<div class="form-section">
					<h4>Error Handling</h4>
					<NcCheckboxRadioSwitch
						v-model="localConfig.collectErrors"
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
			<div class="modal-actions">
				<NcButton
					:disabled="warmingUp"
					@click="$emit('close')">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ warmingUp ? 'Close' : (completed ? 'Close' : 'Cancel') }}
				</NcButton>

				<NcButton
					v-if="!warmingUp && !completed"
					type="primary"
					@click="startWarmup">
					<template #icon>
						<Fire :size="20" />
					</template>
					Start Warmup
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
import { NcDialog, NcButton, NcLoadingIcon, NcCheckboxRadioSwitch, NcSelect } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Fire from 'vue-material-design-icons/Fire.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

export default {
	name: 'SolrWarmupModal',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcSelect,
		Cancel,
		Fire,
		Refresh,
	},

	props: {
		show: {
			type: Boolean,
			default: false,
		},
		warmingUp: {
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
				selectedSchemas: [],
			}),
		},
		objectStats: {
			type: Object,
			default: () => ({
				loading: false,
				totalObjects: 0,
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
		availableSchemas: {
			type: Array,
			default: () => [],
		},
		schemasLoading: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'start-warmup', 'reset'],

	data() {
		return {
			localConfig: { ...this.config },
		}
	},

	computed: {
		selectedSchemasDetails() {
			if (!this.localConfig.selectedSchemas || !this.availableSchemas) {
				return []
			}
			// Handle both object and string values in selectedSchemas
			return this.localConfig.selectedSchemas
				.map(schemaIdOrObj => {
					// If it's already an object with id, use it; otherwise treat as string ID
					const schemaId = typeof schemaIdOrObj === 'object' ? schemaIdOrObj.id : schemaIdOrObj
					return this.availableSchemas.find(schema => schema && schema.id === schemaId) || { id: schemaId, label: 'Unknown', objectCount: 0 }
				})
				.filter(schema => schema !== null && schema !== undefined)
		},

		/**
		 * Calculate the total number of objects based on selected schemas
		 * If no schemas are selected, use the total from objectStats
		 */
		effectiveTotalObjects() {
			if (!this.localConfig.selectedSchemas || this.localConfig.selectedSchemas.length === 0) {
				// No schemas selected = all schemas, use the total count
				return this.objectStats.totalObjects
			}

			// Sum up object counts from selected schemas only
			return this.selectedSchemasDetails.reduce((total, schema) => {
				return total + (schema.objectCount || 0)
			}, 0)
		},
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
		startWarmup() {
			this.$emit('start-warmup', this.localConfig)
		},

		resetModal() {
			this.$emit('reset')
		},

		/**
		 * Get display name for mode
		 *
		 * @param {string} mode Mode value
		 * @return {string} Display name
		 */
		getModeDisplayName(mode) {
			const modeNames = {
				serial: 'Serial',
				parallel: 'Parallel',
				hyper: 'Hyper',
			}
			return modeNames[mode] || mode
		},

		/**
		 * Estimate warmup duration based on configuration
		 *
		 * @return {string} Estimated duration in human-readable format
		 */
		estimateWarmupDuration() {
			if (this.effectiveTotalObjects === 0) {
				return 'Unknown'
			}

			const totalObjects = this.localConfig.maxObjects === 0
				? this.effectiveTotalObjects
				: Math.min(this.localConfig.maxObjects, this.effectiveTotalObjects)

			const batches = Math.ceil(totalObjects / this.localConfig.batchSize)

			// Rough estimates based on mode and batch size
			// Serial: ~2-5 seconds per batch, Parallel: ~1-2 seconds per batch, Hyper: ~0.5-1 seconds per batch
			let secondsPerBatch = 3 // Default for serial
			if (this.localConfig.mode === 'parallel') {
				secondsPerBatch = 1.5
			} else if (this.localConfig.mode === 'hyper') {
				secondsPerBatch = 0.8 // Fastest mode
			}
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
		 * Format execution time from milliseconds to human readable format
		 *
		 * @param {number} milliseconds Execution time in milliseconds
		 * @return {string} Formatted execution time
		 */
		formatExecutionTime(milliseconds) {
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
		 * Get operation status class
		 *
		 * @param {boolean|number} status Operation status
		 * @return {string} CSS class
		 */
		getOperationStatus(status) {
			if (status === true || status === 1) return 'success'
			if (status === false || status === 0) return 'error'
			return 'pending'
		},

		/**
		 * Get operation icon
		 *
		 * @param {boolean|number} status Operation status
		 * @return {string} Icon character
		 */
		getOperationIcon(status) {
			if (status === true || status === 1) return '✅'
			if (status === false || status === 0) return '❌'
			return '⏳'
		},

		/**
		 * Get operation status text
		 *
		 * @param {boolean|number} status Operation status
		 * @return {string} Status text
		 */
		getOperationStatusText(status) {
			if (status === true || status === 1) return 'Success'
			if (status === false || status === 0) return 'Failed'
			return 'Pending'
		},

		/**
		 * Format operation name for display
		 *
		 * @param {string} operation Operation key
		 * @return {string} Formatted operation name
		 */
		formatOperationName(operation) {
			const operationNames = {
				connection_test: 'Connection Test',
				schema_mirroring: 'Schema Mirroring',
				schemas_processed: 'Schemas Processed',
				fields_created: 'Fields Created',
				conflicts_resolved: 'Conflicts Resolved',
				error_collection_mode: 'Error Collection Mode',
				object_indexing: 'Object Indexing',
				objects_indexed: 'Objects Indexed',
				indexing_errors: 'Indexing Errors',
				warmup_query_0: 'Warmup Query 1',
				warmup_query_1: 'Warmup Query 2',
				warmup_query_2: 'Warmup Query 3',
				commit: 'Commit Operation',
			}

			return operationNames[operation] || operation.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
		},

		/**
		 * Get operation details for display
		 *
		 * @param {string} operation Operation key
		 * @param {boolean|number} status Operation status/value
		 * @return {string|null} Details text
		 */
		getOperationDetails(operation, status) {
			if (typeof status === 'number' && status > 1) {
				return `Count: ${status.toLocaleString()}`
			}

			const detailsMap = {
				schema_mirroring: status ? 'All schemas successfully mirrored' : 'Schema mirroring was skipped or failed',
				error_collection_mode: status ? 'Errors collected for review' : 'Stop on first error mode',
				object_indexing: status ? 'All objects processed successfully' : 'Object indexing failed or was skipped',
			}

			return detailsMap[operation] || null
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
	},
}
</script>

<style scoped>
/* Dialog content styles (consistent with EditObject.vue) */
.dialog-content {
	padding: 0 20px;
}

.warmup-description {
	color: var(--color-text-light);
	line-height: 1.5;
	margin: 0 0 1.5rem 0;
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
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	line-height: 1.4;
	margin: 0;
}

.form-input {
	display: flex;
	align-items: center;
}

.warmup-input-field {
	width: 100%;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-text);
	font-size: 14px;
}

.warmup-input-field:focus {
	outline: none;
	border-color: var(--color-primary);
	box-shadow: 0 0 0 2px rgba(var(--color-primary-rgb), 0.2);
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
.warmup-loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	padding: 2rem;
}

.loading-spinner {
	margin-bottom: 1rem;
}

.warmup-loading h4 {
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

/* Results state styles (consistent with test/setup dialogs) */
.warmup-results {
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

/* Operations Status */
.operations-status {
	margin-bottom: 2rem;
}

.operations-status h5 {
	margin: 0 0 1rem 0;
	color: var(--color-text);
	font-size: 1.1rem;
}

.operations-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
	gap: 1rem;
}

.operation-item {
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.operation-item.success {
	border-color: var(--color-success);
	background-color: rgba(var(--color-success-rgb), 0.1);
}

.operation-item.error {
	border-color: var(--color-error);
	background-color: rgba(var(--color-error-rgb), 0.1);
}

.operation-item.pending {
	border-color: var(--color-warning);
	background-color: rgba(var(--color-warning-rgb), 0.1);
}

.operation-header {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	margin-bottom: 0.5rem;
}

.operation-icon {
	font-size: 1.2rem;
}

.operation-header h6 {
	margin: 0;
	color: var(--color-text);
	font-size: 1rem;
	flex-grow: 1;
}

.operation-status {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	font-weight: 500;
}

.operation-details {
	color: var(--color-text-light);
	font-size: 0.85rem;
	line-height: 1.4;
	margin-top: 0.25rem;
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

/* Error Details */
.error-details {
	margin-bottom: 1rem;
}

.error-details h5 {
	margin: 0 0 1rem 0;
	color: var(--color-error);
	font-size: 1.1rem;
}

.error-content {
	background-color: var(--color-background-darker);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 1rem;
	overflow-x: auto;
}

.error-content pre {
	margin: 0;
	color: var(--color-text-light);
	font-family: monospace;
	font-size: 0.9rem;
	line-height: 1.4;
	white-space: pre-wrap;
}

/* Responsive Design */
@media (max-width: 768px) {
	.summary-grid,
	.config-grid {
		grid-template-columns: 1fr;
	}

	.operations-grid {
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

	.operation-header {
		flex-wrap: wrap;
		gap: 0.5rem;
	}

	.radio-group {
		flex-direction: column;
		gap: 0.5rem;
	}

	.radio-group > * {
		flex: none;
	}
}

/* Schema Selection Styles */
.schema-select {
	width: 100%;
}

.schema-option {
	display: flex;
	justify-content: space-between;
	align-items: center;
	width: 100%;
}

.schema-name {
	font-weight: 500;
	color: var(--color-text);
}

.schema-objects {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.selected-schemas-summary {
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 1rem;
	margin-top: 1rem;
}

.selected-schemas-summary p {
	margin: 0 0 0.75rem 0;
	color: var(--color-text);
	font-size: 0.9rem;
}

.selected-schema-list {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.selected-schema-tag {
	display: inline-flex;
	align-items: center;
	background-color: var(--color-primary);
	color: var(--color-primary-text);
	padding: 0.25rem 0.5rem;
	border-radius: var(--border-radius-small);
	font-size: 0.8rem;
	font-weight: 500;
}

.modal-actions {
	display: flex;
	gap: 0.5rem;
	justify-content: flex-end;
}
</style>
