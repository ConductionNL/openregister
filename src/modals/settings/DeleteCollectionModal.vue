<template>
	<NcModal
		v-if="show"
		name="Delete SOLR Collection"
		:can-close="!deleting"
		@close="$emit('close')">
		<div class="delete-collection-modal">
			<!-- Loading State -->
			<div v-if="deleting" class="loading-section">
				<NcLoadingIcon :size="40" />
				<h3>Deleting SOLR Collection...</h3>
				<p>Please wait while we permanently delete the SOLR collection. This may take a few moments.</p>
			</div>

			<!-- Confirmation State -->
			<div v-else-if="!completed" class="confirmation-section">
				<div class="warning-header">
					<span class="warning-icon">⚠️</span>
					<h3>Delete SOLR Collection</h3>
				</div>

				<div class="warning-content">
					<div class="danger-alert">
						<h4>🚨 DANGER: This action cannot be undone!</h4>
						<p>
							You are about to permanently delete the entire SOLR collection and all its data.
							This will completely remove all indexed search data from SOLR.
						</p>
					</div>

					<div class="consequences-section">
						<h4>📋 What will happen:</h4>
						<ul class="consequences-list">
							<li>✅ The SOLR collection will be permanently deleted</li>
							<li>✅ All indexed documents will be removed</li>
							<li>✅ Search functionality will be unavailable until rebuilt</li>
							<li>✅ No data in OpenRegister database will be affected</li>
						</ul>
					</div>

					<div class="next-steps-section">
						<h4>🔧 Recommended follow-up actions:</h4>
						<ol class="next-steps-list">
							<li>
								<strong>Run SOLR Setup</strong> - Create a new clean collection with proper configuration
							</li>
							<li>
								<strong>Run Warmup Index</strong> - Rebuild the search index from your OpenRegister data
							</li>
							<li>
								<strong>Verify Search</strong> - Test that search functionality is working correctly
							</li>
						</ol>
					</div>

					<div class="use-cases-section">
						<h4>💡 When to use this action:</h4>
						<ul class="use-cases-list">
							<li>SOLR collection is corrupted (e.g., "IndexWriter is closed" errors)</li>
							<li>Schema conflicts that cannot be resolved</li>
							<li>Index contains invalid or inconsistent data</li>
							<li>Starting fresh with a clean SOLR setup</li>
						</ul>
					</div>

					<div class="confirmation-input">
						<label for="confirmationText">
							<strong>Type "DELETE COLLECTION" to confirm:</strong>
						</label>
						<input
							id="confirmationText"
							v-model="confirmationText"
							type="text"
							placeholder="DELETE COLLECTION"
							class="confirmation-field"
							:class="{ 'valid': isConfirmationValid }"
							@keyup.enter="isConfirmationValid && handleConfirm()">
					</div>
				</div>
			</div>

			<!-- Results State -->
			<div v-else class="results-section">
				<div class="results-header" :class="results.success ? 'success' : 'error'">
					<span class="result-icon">{{ results.success ? '✅' : '❌' }}</span>
					<h3>{{ results.success ? 'Collection Deleted Successfully' : 'Deletion Failed' }}</h3>
				</div>

				<div class="results-content">
					<p class="result-message">
						{{ results.message }}
					</p>

					<div v-if="results.success" class="success-details">
						<div v-if="results.collection" class="detail-item">
							<strong>Deleted Collection:</strong> {{ results.collection }}
						</div>
						<div v-if="results.tenant_id" class="detail-item">
							<strong>Tenant ID:</strong> {{ results.tenant_id }}
						</div>
						<div v-if="results.response_time_ms" class="detail-item">
							<strong>Response Time:</strong> {{ results.response_time_ms }}ms
						</div>

						<div v-if="results.next_steps" class="next-steps-reminder">
							<h4>🚀 Next Steps:</h4>
							<ol class="next-steps-list">
								<li v-for="step in results.next_steps" :key="step">
									{{ step }}
								</li>
							</ol>
						</div>
					</div>

					<div v-else class="error-details">
						<div v-if="results.error_code" class="detail-item">
							<strong>Error Code:</strong> {{ results.error_code }}
						</div>
						<div v-if="results.collection" class="detail-item">
							<strong>Target Collection:</strong> {{ results.collection }}
						</div>
						<div v-if="results.solr_error" class="detail-item">
							<strong>SOLR Error:</strong>
							<pre class="error-details-pre">{{ JSON.stringify(results.solr_error, null, 2) }}</pre>
						</div>
					</div>
				</div>
			</div>

			<!-- Action Buttons -->
			<div class="modal-actions">
				<NcButton
					v-if="!deleting && !completed"
					@click="$emit('close')">
					Cancel
				</NcButton>
				<NcButton
					v-if="!deleting && !completed"
					type="error"
					:disabled="!isConfirmationValid"
					@click="handleConfirm">
					<template #icon>
						<Delete :size="20" />
					</template>
					Delete Collection
				</NcButton>
				<NcButton
					v-if="completed"
					@click="$emit('close')">
					Close
				</NcButton>
				<NcButton
					v-if="completed && !results.success"
					type="primary"
					@click="handleRetry">
					<template #icon>
						<Refresh :size="20" />
					</template>
					Retry
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'DeleteCollectionModal',

	components: {
		NcModal,
		NcButton,
		NcLoadingIcon,
		Delete,
		Refresh,
	},

	props: {
		show: {
			type: Boolean,
			required: true,
		},
	},

	emits: ['close', 'deleted'],

	data() {
		return {
			deleting: false,
			completed: false,
			results: null,
			confirmationText: '',
		}
	},

	computed: {
		isConfirmationValid() {
			return this.confirmationText.trim().toUpperCase() === 'DELETE COLLECTION'
		},
	},

	watch: {
		show(newValue) {
			if (newValue) {
				// Reset state when modal is opened
				this.deleting = false
				this.completed = false
				this.results = null
				this.confirmationText = ''
			}
		},
	},

	methods: {
		async handleConfirm() {
			if (!this.isConfirmationValid) {
				return
			}

			this.deleting = true

			try {
				const url = generateUrl('/apps/openregister/api/solr/collection/delete')
				console.log('Deleting SOLR collection via:', url)

				const response = await axios.delete(url)
				console.log('Delete collection response:', response.data)

				this.results = response.data
				this.completed = true

				if (response.data.success) {
					showSuccess('SOLR collection deleted successfully')
					this.$emit('deleted', response.data)
				} else {
					showError('Failed to delete SOLR collection: ' + response.data.message)
				}
			} catch (error) {
				console.error('Delete collection failed:', error)

				this.results = {
					success: false,
					message: error.response?.data?.message || error.message || 'Unknown error occurred',
					error_code: error.response?.data?.error_code || 'NETWORK_ERROR',
					solr_error: error.response?.data?.solr_error || null,
				}
				this.completed = true

				showError('Failed to delete SOLR collection: ' + this.results.message)
			} finally {
				this.deleting = false
			}
		},

		handleRetry() {
			// Reset to confirmation state for retry
			this.completed = false
			this.results = null
			this.confirmationText = ''
		},
	},
}
</script>

<style scoped>
.delete-collection-modal {
	padding: 24px;
	max-width: 600px;
	min-height: 400px;
}

.loading-section {
	text-align: center;
	padding: 40px 20px;
}

.loading-section h3 {
	margin: 16px 0 8px 0;
	color: var(--color-main-text);
}

.loading-section p {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.confirmation-section {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.warning-header {
	display: flex;
	align-items: center;
	gap: 12px;
	padding-bottom: 16px;
	border-bottom: 2px solid var(--color-warning);
}

.warning-icon {
	font-size: 32px;
}

.warning-header h3 {
	margin: 0;
	color: var(--color-main-text);
	font-size: 20px;
	font-weight: 600;
}

.warning-content {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.danger-alert {
	background: rgba(var(--color-error), 0.1);
	border: 2px solid var(--color-error);
	border-radius: 8px;
	padding: 16px;
}

.danger-alert h4 {
	margin: 0 0 8px 0;
	color: var(--color-error);
	font-size: 16px;
	font-weight: 600;
}

.danger-alert p {
	margin: 0;
	color: var(--color-text-light);
	line-height: 1.5;
}

.consequences-section,
.next-steps-section,
.use-cases-section {
	background: var(--color-background-hover);
	border-radius: 8px;
	padding: 16px;
	border-left: 4px solid var(--color-primary);
}

.consequences-section h4,
.next-steps-section h4,
.use-cases-section h4 {
	margin: 0 0 12px 0;
	color: var(--color-main-text);
	font-size: 14px;
	font-weight: 600;
}

.consequences-list,
.next-steps-list,
.use-cases-list {
	margin: 0;
	padding-left: 20px;
}

.consequences-list li,
.use-cases-list li {
	margin: 6px 0;
	color: var(--color-text-light);
	font-size: 14px;
	line-height: 1.4;
}

.next-steps-list li {
	margin: 8px 0;
	color: var(--color-text-light);
	font-size: 14px;
	line-height: 1.4;
}

.next-steps-list strong {
	color: var(--color-primary);
}

.confirmation-input {
	background: var(--color-background-dark);
	border-radius: 8px;
	padding: 16px;
	border: 2px solid var(--color-border);
}

.confirmation-input label {
	display: block;
	margin-bottom: 8px;
	color: var(--color-main-text);
	font-size: 14px;
	font-weight: 500;
}

.confirmation-field {
	width: 100%;
	padding: 12px 16px;
	border: 2px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
	font-family: monospace;
	text-transform: uppercase;
	letter-spacing: 1px;
}

.confirmation-field:focus {
	outline: none;
	border-color: var(--color-primary);
}

.confirmation-field.valid {
	border-color: var(--color-success);
	background: rgba(var(--color-success), 0.1);
}

.results-section {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.results-header {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 16px;
	border-radius: 8px;
	border: 2px solid;
}

.results-header.success {
	background: rgba(var(--color-success), 0.1);
	border-color: var(--color-success);
}

.results-header.error {
	background: rgba(var(--color-error), 0.1);
	border-color: var(--color-error);
}

.result-icon {
	font-size: 24px;
}

.results-header h3 {
	margin: 0;
	color: var(--color-main-text);
	font-size: 18px;
	font-weight: 600;
}

.results-content {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.result-message {
	color: var(--color-text-light);
	font-size: 14px;
	line-height: 1.5;
	margin: 0;
}

.success-details,
.error-details {
	background: var(--color-background-hover);
	border-radius: 8px;
	padding: 16px;
}

.detail-item {
	margin: 8px 0;
	font-size: 13px;
	color: var(--color-text-light);
}

.detail-item strong {
	color: var(--color-main-text);
	margin-right: 8px;
}

.error-details-pre {
	background: var(--color-background-dark);
	border-radius: 4px;
	padding: 8px;
	font-size: 11px;
	overflow-x: auto;
	margin-top: 8px;
}

.next-steps-reminder {
	margin-top: 16px;
	padding: 16px;
	background: linear-gradient(135deg, var(--color-primary-light) 0%, var(--color-primary) 100%);
	color: white;
	border-radius: 8px;
}

.next-steps-reminder h4 {
	margin: 0 0 12px 0;
	font-size: 16px;
	font-weight: 600;
}

.next-steps-reminder .next-steps-list {
	margin: 0;
	padding-left: 20px;
}

.next-steps-reminder .next-steps-list li {
	margin: 8px 0;
	font-size: 14px;
	line-height: 1.4;
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

@media (max-width: 768px) {
	.delete-collection-modal {
		padding: 16px;
		max-width: 100%;
	}

	.modal-actions {
		flex-direction: column-reverse;
		gap: 8px;
	}

	.warning-header {
		flex-direction: column;
		text-align: center;
		gap: 8px;
	}
}
</style>
