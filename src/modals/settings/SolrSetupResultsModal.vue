<template>
	<NcDialog
		v-if="show"
		name="SOLR Setup Results"
		:can-close="!setting"
		@closing="$emit('close')"
		size="large">
		<div class="dialog-content">
			<!-- Loading State -->
			<div v-if="setting" class="setup-loading">
				<div class="loading-spinner">
					<NcLoadingIcon :size="40" />
				</div>
				<h4>Setting up SOLR...</h4>
				<p class="loading-description">
					Please wait while we configure your SOLR server with the necessary collections, schemas, and configurations.
				</p>
			</div>

			<!-- Results State -->
			<div v-else-if="results" class="setup-results">
				<!-- Overall Status -->
				<div class="results-header">
					<div class="status-icon" :class="results.success ? 'success' : 'error'">
						{{ results.success ? '✅' : '❌' }}
					</div>
					<h4 :class="results.success ? 'success-text' : 'error-text'">
						{{ results.success ? 'SOLR Setup Completed Successfully!' : 'SOLR Setup Failed' }}
					</h4>
					<p class="results-message">{{ results.message }}</p>
				</div>

				<!-- Setup Steps -->
				<div v-if="results.steps" class="setup-steps">
					<h5>Setup Steps</h5>
					<div class="steps-list">
						<div
							v-for="(step, index) in results.steps"
							:key="index"
							class="step-item"
							:class="getStepStatus(step)">
							<div class="step-header">
								<span class="step-icon">{{ getStepIcon(step) }}</span>
								<h6>{{ step.step || `Step ${index + 1}` }}</h6>
								<span class="step-status">{{ getStepStatusText(step) }}</span>
							</div>
							<p v-if="step.message" class="step-message">{{ step.message }}</p>
							<div v-if="step.details" class="step-details">
								<div v-for="(value, key) in step.details" :key="key" class="detail-item">
									<span class="detail-label">{{ formatDetailLabel(key) }}:</span>
									<span class="detail-value">{{ formatDetailValue(value) }}</span>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Configuration Summary -->
				<div v-if="results.configuration" class="config-summary">
					<h5>Configuration Applied</h5>
					<div class="config-grid">
						<div v-for="(value, key) in results.configuration" :key="key" class="config-item">
							<span class="config-label">{{ formatDetailLabel(key) }}:</span>
							<span class="config-value">{{ formatDetailValue(value) }}</span>
						</div>
					</div>
				</div>

				<!-- ConfigSet Propagation Error (Special Handling) -->
				<div v-if="!results.success && isConfigSetPropagationError" class="propagation-error">
					<h5>⏱️ ConfigSet Propagation Delay</h5>
					<div class="propagation-content">
						<div class="propagation-explanation">
							<p><strong>What happened:</strong> The SOLR configSet was created successfully, but it's still propagating across the distributed SOLR cluster nodes. This is normal in production SOLR environments.</p>
						</div>
						
						<div class="propagation-instructions">
							<h6>🔄 Next Steps:</h6>
							<ol>
								<li><strong>Wait 2-5 minutes</strong> for the configSet to fully propagate to all SOLR nodes</li>
								<li>Click the <strong>"Setup Again"</strong> button below to retry</li>
								<li>The setup should succeed on the next attempt</li>
							</ol>
						</div>

						<div class="propagation-technical">
							<details>
								<summary>Technical Details</summary>
								<p>In SolrCloud environments, configSets are distributed via ZooKeeper coordination. Large clusters or high network latency can cause propagation delays of several minutes. This is expected behavior and not an error with your configuration.</p>
								<div v-if="results.error_details" class="raw-error">
									<pre>{{ JSON.stringify(results.error_details, null, 2) }}</pre>
								</div>
							</details>
						</div>
					</div>
				</div>

				<!-- Standard Error Details -->
				<div v-else-if="!results.success && results.error_details" class="error-details">
					<h5>Error Details</h5>
					<div class="error-content">
						<div v-if="results.primary_error" class="primary-error">
							<strong>Primary Error:</strong> {{ results.primary_error }}
						</div>
						<div v-if="results.error_context" class="error-context">
							<strong>Context:</strong> {{ results.error_context }}
						</div>
						<div v-if="results.troubleshooting_tips" class="troubleshooting">
							<strong>Troubleshooting Tips:</strong>
							<ul>
								<li v-for="(tip, index) in results.troubleshooting_tips" :key="index">
									{{ tip }}
								</li>
							</ul>
						</div>
						<div v-if="results.error_details" class="raw-error">
							<details>
								<summary>Raw Error Details</summary>
								<pre>{{ JSON.stringify(results.error_details, null, 2) }}</pre>
							</details>
						</div>
					</div>
				</div>
			</div>

			<!-- Default State -->
			<div v-else class="setup-placeholder">
				<p>Click "Setup SOLR" to initialize your SOLR configuration.</p>
			</div>
		</div>

		<template #actions>
			<NcButton
				:disabled="setting"
				@click="$emit('close')">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Close
			</NcButton>
			<NcButton
				v-if="!setting"
				type="secondary"
				@click="$emit('retry')">
				<template #icon>
					<Refresh :size="20" />
				</template>
				Setup Again
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

export default {
	name: 'SolrSetupResultsModal',

	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		Cancel,
		Refresh,
	},

	props: {
		show: {
			type: Boolean,
			default: false,
		},
		setting: {
			type: Boolean,
			default: false,
		},
		results: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'retry'],

	computed: {
		isConfigSetPropagationError() {
			if (!this.results || this.results.success) {
				return false
			}

			// Check for configSet propagation error patterns in the message or error details
			const errorMessage = this.results.message || ''
			const errorDetails = this.results.error_details || {}
			const exceptionMessage = errorDetails.exception_message || ''

			const propagationPatterns = [
				'ConfigSet propagation timeout',
				'Underlying core creation failed while creating collection',
				'configset does not exist',
				'Config does not exist',
				'Could not find configSet',
				'configSet not found'
			]

			return propagationPatterns.some(pattern => 
				errorMessage.includes(pattern) || exceptionMessage.includes(pattern)
			)
		}
	},

	methods: {
		getStepStatus(step) {
			if (step.success === true) return 'success'
			if (step.success === false) return 'error'
			return 'pending'
		},

		getStepIcon(step) {
			if (step.success === true) return '✅'
			if (step.success === false) return '❌'
			return '⏳'
		},

		getStepStatusText(step) {
			if (step.success === true) return 'Completed'
			if (step.success === false) return 'Failed'
			return 'Pending'
		},

		formatDetailLabel(key) {
			return key.replace(/_/g, ' ').replace(/([A-Z])/g, ' $1').replace(/^\w/, c => c.toUpperCase())
		},

		formatDetailValue(value) {
			if (typeof value === 'boolean') {
				return value ? 'Yes' : 'No'
			}
			if (typeof value === 'object' && value !== null) {
				return JSON.stringify(value)
			}
			return String(value)
		},
	},
}
</script>

<style scoped>
.dialog-content {
	padding: 0 20px;
}

/* Loading State */
.setup-loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	padding: 2rem;
}

.loading-spinner {
	margin-bottom: 1rem;
}

.setup-loading h4 {
	color: var(--color-primary);
	margin: 0 0 1rem 0;
}

.loading-description {
	color: var(--color-text-light);
	margin: 0;
	line-height: 1.5;
}

/* Results State */
.setup-results {
	padding: 1rem 0;
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

/* Setup Steps */
.setup-steps {
	margin-bottom: 2rem;
}

.setup-steps h5 {
	margin: 0 0 1rem 0;
	color: var(--color-text);
	font-size: 1.1rem;
}

.steps-list {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.step-item {
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.step-item.success {
	border-color: var(--color-success);
	background-color: rgba(var(--color-success-rgb), 0.1);
}

.step-item.error {
	border-color: var(--color-error);
	background-color: rgba(var(--color-error-rgb), 0.1);
}

.step-item.pending {
	border-color: var(--color-warning);
	background-color: rgba(var(--color-warning-rgb), 0.1);
}

.step-header {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	margin-bottom: 0.5rem;
}

.step-icon {
	font-size: 1.2rem;
}

.step-header h6 {
	margin: 0;
	color: var(--color-text);
	font-size: 1rem;
	flex-grow: 1;
}

.step-status {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	font-weight: 500;
}

.step-message {
	color: var(--color-text-light);
	margin: 0 0 0.75rem 0;
	font-size: 0.9rem;
	line-height: 1.4;
}

.step-details {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.detail-item {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	padding: 0.5rem;
	background-color: var(--color-main-background);
	border-radius: var(--border-radius-small);
	font-size: 0.85rem;
}

.detail-label {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
	margin-right: 1rem;
	flex-shrink: 0;
}

.detail-value {
	color: var(--color-text);
	word-break: break-word;
	text-align: right;
}

/* Configuration Summary */
.config-summary {
	margin-bottom: 2rem;
}

.config-summary h5 {
	margin: 0 0 1rem 0;
	color: var(--color-text);
	font-size: 1.1rem;
}

.config-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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

/* ConfigSet Propagation Error */
.propagation-error {
	margin-bottom: 1rem;
}

.propagation-error h5 {
	margin: 0 0 1rem 0;
	color: var(--color-warning);
	font-size: 1.1rem;
}

.propagation-content {
	background-color: rgba(var(--color-warning-rgb), 0.1);
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius);
	padding: 1.5rem;
}

.propagation-explanation {
	margin-bottom: 1.5rem;
	padding: 1rem;
	background-color: var(--color-main-background);
	border-radius: var(--border-radius);
	border-left: 4px solid var(--color-warning);
}

.propagation-explanation p {
	margin: 0;
	color: var(--color-text);
	line-height: 1.5;
}

.propagation-instructions {
	margin-bottom: 1.5rem;
}

.propagation-instructions h6 {
	margin: 0 0 0.75rem 0;
	color: var(--color-text);
	font-size: 1rem;
}

.propagation-instructions ol {
	margin: 0;
	padding-left: 1.5rem;
	color: var(--color-text);
}

.propagation-instructions li {
	margin-bottom: 0.5rem;
	line-height: 1.4;
}

.propagation-technical {
	border-top: 1px solid var(--color-border);
	padding-top: 1rem;
}

.propagation-technical details summary {
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0.5rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	margin-bottom: 0.75rem;
}

.propagation-technical p {
	margin: 0 0 1rem 0;
	color: var(--color-text-light);
	line-height: 1.4;
	font-size: 0.9rem;
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
}

.primary-error,
.error-context {
	margin-bottom: 1rem;
	color: var(--color-text-light);
	line-height: 1.4;
}

.troubleshooting {
	margin-bottom: 1rem;
}

.troubleshooting ul {
	margin: 0.5rem 0 0 1rem;
	padding: 0;
}

.troubleshooting li {
	color: var(--color-text-light);
	margin-bottom: 0.5rem;
	line-height: 1.4;
}

.raw-error details {
	margin-top: 1rem;
}

.raw-error summary {
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	padding: 0.5rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	margin-bottom: 0.5rem;
}

.raw-error pre {
	margin: 0;
	color: var(--color-text-light);
	font-family: monospace;
	font-size: 0.8rem;
	line-height: 1.4;
	white-space: pre-wrap;
	background-color: var(--color-main-background);
	padding: 1rem;
	border-radius: var(--border-radius);
	overflow-x: auto;
}

/* Placeholder State */
.setup-placeholder {
	text-align: center;
	padding: 2rem;
}

.setup-placeholder p {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

/* Responsive Design */
@media (max-width: 768px) {
	.config-grid {
		grid-template-columns: 1fr;
	}
	
	.config-item,
	.detail-item {
		flex-direction: column;
		align-items: flex-start;
		gap: 0.25rem;
	}
	
	.config-value,
	.detail-value {
		text-align: left;
	}
	
	.step-header {
		flex-wrap: wrap;
		gap: 0.5rem;
	}
}
</style>
