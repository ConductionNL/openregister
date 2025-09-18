<template>
	<NcDialog
		v-if="show"
		name="SOLR Connection Test Results"
		:can-close="!testing"
		@closing="$emit('close')"
		size="large">
		<div class="dialog-content">
			<!-- Loading State -->
			<div v-if="testing" class="test-loading">
				<div class="loading-spinner">
					<NcLoadingIcon :size="40" />
				</div>
				<h4>Testing SOLR Connection...</h4>
				<p class="loading-description">
					Please wait while we test the connection to your SOLR server and validate the configuration.
				</p>
			</div>

			<!-- Results State -->
			<div v-else-if="results" class="test-results">
				<!-- Overall Status -->
				<div class="results-header">
					<div class="status-icon" :class="results.success ? 'success' : 'error'">
						{{ results.success ? '✅' : '❌' }}
					</div>
					<h4 :class="results.success ? 'success-text' : 'error-text'">
						{{ results.success ? 'Connection Test Successful!' : 'Connection Test Failed' }}
					</h4>
					<p class="results-message">{{ results.message }}</p>
				</div>

				<!-- Component Details -->
				<div v-if="results.components" class="component-results">
					<h5>Component Test Results</h5>
					<div class="component-grid">
						<div
							v-for="(component, name) in results.components"
							:key="name"
							class="component-item"
							:class="component.success ? 'success' : 'error'">
							<div class="component-header">
								<span class="component-icon">{{ component.success ? '✅' : '❌' }}</span>
								<h6>{{ formatComponentName(name) }}</h6>
							</div>
							<p class="component-message">{{ component.message }}</p>
							<div v-if="component.details" class="component-details">
								<div v-for="(value, key) in component.details" :key="key" class="detail-item">
									<span class="detail-label">{{ formatDetailLabel(key) }}:</span>
									<span class="detail-value">{{ formatDetailValue(value) }}</span>
								</div>
							</div>
						</div>
					</div>
				</div>

				<!-- Error Details -->
				<div v-if="!results.success && results.details" class="error-details">
					<h5>Error Details</h5>
					<div class="error-content">
						<pre>{{ JSON.stringify(results.details, null, 2) }}</pre>
					</div>
				</div>
			</div>

			<!-- Default State -->
			<div v-else class="test-placeholder">
				<p>Click "Test Connection" to validate your SOLR configuration.</p>
			</div>
		</div>

		<template #actions>
			<NcButton
				:disabled="testing"
				@click="$emit('close')">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Close
			</NcButton>
			<NcButton
				v-if="!testing"
				type="secondary"
				@click="$emit('retry')">
				<template #icon>
					<Refresh :size="20" />
				</template>
				Test Again
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

export default {
	name: 'SolrTestResultsModal',

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
		testing: {
			type: Boolean,
			default: false,
		},
		results: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'retry'],

	methods: {
		formatComponentName(name) {
			return name.charAt(0).toUpperCase() + name.slice(1).replace(/([A-Z])/g, ' $1')
		},

		formatDetailLabel(key) {
			return key.replace(/_/g, ' ').replace(/([A-Z])/g, ' $1').replace(/^\w/, c => c.toUpperCase())
		},

		formatDetailValue(value) {
			if (typeof value === 'object') {
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
.test-loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	padding: 2rem;
}

.loading-spinner {
	margin-bottom: 1rem;
}

.test-loading h4 {
	color: var(--color-primary);
	margin: 0 0 1rem 0;
}

.loading-description {
	color: var(--color-text-light);
	margin: 0;
	line-height: 1.5;
}

/* Results State */
.test-results {
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

/* Component Results */
.component-results {
	margin-bottom: 2rem;
}

.component-results h5 {
	margin: 0 0 1rem 0;
	color: var(--color-text);
	font-size: 1.1rem;
}

.component-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
	gap: 1rem;
}

.component-item {
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.component-item.success {
	border-color: var(--color-success);
	background-color: rgba(var(--color-success-rgb), 0.1);
}

.component-item.error {
	border-color: var(--color-error);
	background-color: rgba(var(--color-error-rgb), 0.1);
}

.component-header {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 0.75rem;
}

.component-icon {
	font-size: 1.2rem;
}

.component-header h6 {
	margin: 0;
	color: var(--color-text);
	font-size: 1rem;
}

.component-message {
	color: var(--color-text-light);
	margin: 0 0 0.75rem 0;
	font-size: 0.9rem;
}

.component-details {
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

/* Placeholder State */
.test-placeholder {
	text-align: center;
	padding: 2rem;
}

.test-placeholder p {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

/* Responsive Design */
@media (max-width: 768px) {
	.component-grid {
		grid-template-columns: 1fr;
	}
	
	.detail-item {
		flex-direction: column;
		align-items: flex-start;
		gap: 0.25rem;
	}
	
	.detail-value {
		text-align: left;
	}
}
</style>
