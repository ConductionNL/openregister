<script setup>
import { auditTrailStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'auditTrailDetails'"
		:name="t('openregister', 'Audit Trail Details')"
		size="large"
		:can-close="true"
		@close="closeDialog">
		<div v-if="auditTrailStore.auditTrailItem" class="audit-trail-details">
			<!-- Header Information -->
			<div class="details-section">
				<h3>{{ t('openregister', 'Basic Information') }}</h3>
				<div class="details-grid">
					<div class="detail-item">
						<label>{{ t('openregister', 'ID') }}</label>
						<span>{{ auditTrailStore.auditTrailItem.id }}</span>
					</div>
					<div class="detail-item">
						<label>{{ t('openregister', 'Action') }}</label>
						<span class="action-badge" :class="`action-${auditTrailStore.auditTrailItem.action}`">
							<Plus v-if="auditTrailStore.auditTrailItem.action === 'create'" :size="16" />
							<Pencil v-else-if="auditTrailStore.auditTrailItem.action === 'update'" :size="16" />
							<Delete v-else-if="auditTrailStore.auditTrailItem.action === 'delete'" :size="16" />
							<Eye v-else-if="auditTrailStore.auditTrailItem.action === 'read'" :size="16" />
							{{ auditTrailStore.auditTrailItem.action?.toUpperCase() }}
						</span>
					</div>
					<div class="detail-item">
						<label>{{ t('openregister', 'Created') }}</label>
						<span>{{ formatDate(auditTrailStore.auditTrailItem.created) }}</span>
					</div>
					<div class="detail-item">
						<label>{{ t('openregister', 'Object ID') }}</label>
						<span>{{ auditTrailStore.auditTrailItem.object || '-' }}</span>
					</div>
					<div class="detail-item">
						<label>{{ t('openregister', 'Register ID') }}</label>
						<span>{{ auditTrailStore.auditTrailItem.register || '-' }}</span>
					</div>
					<div class="detail-item">
						<label>{{ t('openregister', 'Schema ID') }}</label>
						<span>{{ auditTrailStore.auditTrailItem.schema || '-' }}</span>
					</div>
					<div class="detail-item">
						<label>{{ t('openregister', 'User') }}</label>
						<span>{{ auditTrailStore.auditTrailItem.userName || auditTrailStore.auditTrailItem.user || '-' }}</span>
					</div>
					<div class="detail-item">
						<label>{{ t('openregister', 'Size') }}</label>
						<span>{{ auditTrailStore.auditTrailItem.size || '-' }}</span>
					</div>
				</div>
			</div>

			<!-- Changes Information -->
			<div v-if="hasChanges" class="details-section">
				<h3>{{ t('openregister', 'Changes') }}</h3>
				<div class="changes-container">
					<pre>{{ formatChanges(auditTrailStore.auditTrailItem.changed) }}</pre>
				</div>
			</div>

			<!-- Request Data -->
			<div v-if="auditTrailStore.auditTrailItem.request" class="details-section">
				<h3>{{ t('openregister', 'Request Data') }}</h3>
				<div class="request-container">
					<pre>{{ formatJson(auditTrailStore.auditTrailItem.request) }}</pre>
				</div>
			</div>

			<!-- Additional Fields -->
			<div class="details-section">
				<h3>{{ t('openregister', 'Additional Information') }}</h3>
				<div class="additional-fields">
					<div v-for="[key, value] in additionalFields" :key="key" class="detail-item">
						<label>{{ formatFieldName(key) }}</label>
						<span>{{ formatFieldValue(value) }}</span>
					</div>
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton @click="copyFullData">
				<template #icon>
					<ContentCopy :size="20" />
				</template>
				{{ t('openregister', 'Copy Full Data') }}
			</NcButton>
			<NcButton v-if="hasChanges" @click="copyChanges">
				<template #icon>
					<CompareHorizontal :size="20" />
				</template>
				{{ t('openregister', 'Copy Changes') }}
			</NcButton>
			<NcButton @click="closeDialog">
				<template #icon>
					<Close :size="20" />
				</template>
				{{ t('openregister', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
} from '@nextcloud/vue'

import Close from 'vue-material-design-icons/Close.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import CompareHorizontal from 'vue-material-design-icons/CompareHorizontal.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Eye from 'vue-material-design-icons/Eye.vue'

export default {
	name: 'AuditTrailDetails',
	components: {
		NcDialog,
		NcButton,
		// Icons
		Close,
		ContentCopy,
		CompareHorizontal,
		Plus,
		Pencil,
		Delete,
		Eye,
	},
	computed: {
		/**
		 * Check if audit trail has changes data
		 * @return {boolean} True if has changes
		 */
		hasChanges() {
			const changed = auditTrailStore.auditTrailItem?.changed
			if (!changed) return false
			
			if (Array.isArray(changed)) {
				return changed.length > 0
			}
			
			if (typeof changed === 'object') {
				return Object.keys(changed).length > 0
			}
			
			return !!changed
		},

		/**
		 * Get additional fields that aren't in the main display
		 * @return {Array} Array of key-value pairs
		 */
		additionalFields() {
			if (!auditTrailStore.auditTrailItem) return []
			
			const mainFields = [
				'id', 'action', 'created', 'object', 'register', 
				'schema', 'user', 'userName', 'size', 'changed', 'request'
			]
			
			return Object.entries(auditTrailStore.auditTrailItem)
				.filter(([key]) => !mainFields.includes(key))
				.filter(([, value]) => value !== null && value !== undefined && value !== '')
		},
	},
	methods: {
		/**
		 * Close the dialog
		 * @return {void}
		 */
		closeDialog() {
			navigationStore.setDialog(false)
		},

		/**
		 * Format date for display
		 * @param {string} dateString - Date string to format
		 * @return {string} Formatted date
		 */
		formatDate(dateString) {
			if (!dateString) return '-'
			try {
				return new Date(dateString).toLocaleString()
			} catch (error) {
				return dateString
			}
		},

		/**
		 * Format changes data for display
		 * @param {*} changes - Changes data
		 * @return {string} Formatted changes
		 */
		formatChanges(changes) {
			if (!changes) return ''
			
			try {
				if (typeof changes === 'string') {
					// Try to parse if it's a JSON string
					try {
						const parsed = JSON.parse(changes)
						return JSON.stringify(parsed, null, 2)
					} catch {
						return changes
					}
				}
				
				return JSON.stringify(changes, null, 2)
			} catch (error) {
				return String(changes)
			}
		},

		/**
		 * Format JSON data for display
		 * @param {*} data - Data to format
		 * @return {string} Formatted JSON
		 */
		formatJson(data) {
			if (!data) return ''
			
			try {
				if (typeof data === 'string') {
					// Try to parse if it's a JSON string
					try {
						const parsed = JSON.parse(data)
						return JSON.stringify(parsed, null, 2)
					} catch {
						return data
					}
				}
				
				return JSON.stringify(data, null, 2)
			} catch (error) {
				return String(data)
			}
		},

		/**
		 * Format field name for display
		 * @param {string} fieldName - Field name to format
		 * @return {string} Formatted field name
		 */
		formatFieldName(fieldName) {
			return fieldName
				.replace(/([A-Z])/g, ' $1')
				.replace(/^./, str => str.toUpperCase())
				.trim()
		},

		/**
		 * Format field value for display
		 * @param {*} value - Value to format
		 * @return {string} Formatted value
		 */
		formatFieldValue(value) {
			if (value === null || value === undefined) return '-'
			
			if (typeof value === 'object') {
				try {
					return JSON.stringify(value, null, 2)
				} catch {
					return String(value)
				}
			}
			
			return String(value)
		},

		/**
		 * Copy full audit trail data to clipboard
		 * @return {Promise<void>}
		 */
		async copyFullData() {
			try {
				const data = JSON.stringify(auditTrailStore.auditTrailItem, null, 2)
				await navigator.clipboard.writeText(data)
				OC.Notification.showSuccess(this.t('openregister', 'Full data copied to clipboard'))
			} catch (error) {
				console.error('Error copying to clipboard:', error)
				OC.Notification.showError(this.t('openregister', 'Failed to copy data'))
			}
		},

		/**
		 * Copy changes data to clipboard
		 * @return {Promise<void>}
		 */
		async copyChanges() {
			try {
				const changes = this.formatChanges(auditTrailStore.auditTrailItem.changed)
				await navigator.clipboard.writeText(changes)
				OC.Notification.showSuccess(this.t('openregister', 'Changes copied to clipboard'))
			} catch (error) {
				console.error('Error copying to clipboard:', error)
				OC.Notification.showError(this.t('openregister', 'Failed to copy changes'))
			}
		},
	},
}
</script>

<style scoped>
.audit-trail-details {
	padding: 16px 0;
}

.details-section {
	margin-bottom: 24px;
}

.details-section h3 {
	margin: 0 0 16px 0;
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}

.details-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 16px;
}

.detail-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.detail-item label {
	font-size: 0.9rem;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.detail-item span {
	padding: 8px 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	font-family: var(--font-face);
	word-break: break-all;
}

.action-badge {
	display: inline-flex !important;
	align-items: center;
	gap: 4px;
	padding: 4px 8px !important;
	border-radius: 12px !important;
	font-size: 0.75rem;
	font-weight: 600;
	color: white;
	background: var(--color-text-maxcontrast);
	width: fit-content;
}

.action-badge.action-create {
	background: var(--color-success) !important;
}

.action-badge.action-update {
	background: var(--color-warning) !important;
}

.action-badge.action-delete {
	background: var(--color-error) !important;
}

.action-badge.action-read {
	background: var(--color-info) !important;
}

.changes-container,
.request-container {
	background: var(--color-background-darker);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 16px;
	max-height: 400px;
	overflow-y: auto;
}

.changes-container pre,
.request-container pre {
	margin: 0;
	font-family: 'Courier New', monospace;
	font-size: 0.85rem;
	white-space: pre-wrap;
	word-break: break-word;
	color: var(--color-main-text);
}

.additional-fields {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.additional-fields .detail-item {
	flex-direction: row;
	align-items: center;
	gap: 16px;
}

.additional-fields .detail-item label {
	min-width: 150px;
	flex-shrink: 0;
}

.additional-fields .detail-item span {
	flex: 1;
	max-height: 100px;
	overflow-y: auto;
}
</style> 