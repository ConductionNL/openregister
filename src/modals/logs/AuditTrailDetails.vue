<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'auditTrailDetails'"
		:name="t('openregister', 'Audit Trail Details')"
		size="large"
		:can-close="true"
		@update:open="closeDialog">
		<div v-if="objectStore.auditTrailItem" class="audit-trail-details">
			<!-- Basic Information -->
			<div class="details-section">
				<h3>{{ t('openregister', 'Basic Information') }}</h3>
				<CnDetailGrid :items="basicInfoItems">
					<template #item-1>
						<CnStatusBadge
							:label="objectStore.auditTrailItem.action ? objectStore.auditTrailItem.action.toUpperCase() : 'NO ACTION'"
							:color-map="actionColorMap"
							solid>
							<template #icon>
								<Plus v-if="objectStore.auditTrailItem.action === 'create'" :size="16" />
								<Pencil v-else-if="objectStore.auditTrailItem.action === 'update'" :size="16" />
								<Delete v-else-if="objectStore.auditTrailItem.action === 'delete'" :size="16" />
								<Eye v-else-if="objectStore.auditTrailItem.action === 'read'" :size="16" />
							</template>
						</CnStatusBadge>
					</template>
				</CnDetailGrid>
			</div>

			<!-- Changes Information -->
			<div v-if="hasChanges" class="details-section">
				<h3>{{ t('openregister', 'Changes') }}</h3>
				<CnJsonViewer
					:value="formatChanges(objectStore.auditTrailItem.changed)"
					:read-only="true" />
			</div>

			<!-- Request Data -->
			<div v-if="objectStore.auditTrailItem.request" class="details-section">
				<h3>{{ t('openregister', 'Request Data') }}</h3>
				<CnJsonViewer
					:value="formatJson(objectStore.auditTrailItem.request)"
					:read-only="true"
					height="100px" />
			</div>

			<!-- Additional Fields -->
			<div v-if="additionalFieldItems.length" class="details-section">
				<h3>{{ t('openregister', 'Additional Information') }}</h3>
				<CnDetailGrid
					layout="horizontal"
					:items="additionalFieldItems" />
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
import { CnStatusBadge, CnJsonViewer, CnDetailGrid } from '@conduction/nextcloud-vue'

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
		CnStatusBadge,
		CnJsonViewer,
		CnDetailGrid,
		// Icons
		Close,
		ContentCopy,
		CompareHorizontal,
		Plus,
		Pencil,
		Delete,
		Eye,
	},
	data() {
		return {
			actionColorMap: { create: 'success', update: 'warning', delete: 'error', read: 'info' },
		}
	},
	computed: {
		/**
		 * Check if audit trail has changes data
		 * @return {boolean} True if has changes
		 */
		hasChanges() {
			const changed = objectStore.auditTrailItem?.changed
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
		 * Basic information items for the detail grid
		 * @return {Array<{ label: string, value: string }>}
		 */
		basicInfoItems() {
			const item = objectStore.auditTrailItem
			if (!item) return []

			return [
				{ label: this.t('openregister', 'ID'), value: item.id },
				{ label: this.t('openregister', 'Action') },
				{ label: this.t('openregister', 'Created'), value: this.formatDate(item.created) },
				{ label: this.t('openregister', 'Object ID'), value: item.object || '-' },
				{ label: this.t('openregister', 'Register ID'), value: item.register || '-' },
				{ label: this.t('openregister', 'Schema ID'), value: item.schema || '-' },
				{ label: this.t('openregister', 'User'), value: item.userName || item.user || '-' },
				{ label: this.t('openregister', 'Size'), value: item.size || '-' },
			]
		},

		/**
		 * Additional fields formatted as items for the horizontal detail grid
		 * @return {Array<{ label: string, value: string }>}
		 */
		additionalFieldItems() {
			if (!objectStore.auditTrailItem) return []

			const mainFields = [
				'id', 'action', 'created', 'object', 'register',
				'schema', 'user', 'userName', 'size', 'changed', 'request',
			]

			return Object.entries(objectStore.auditTrailItem)
				.filter(([key]) => !mainFields.includes(key))
				.filter(([, value]) => value !== null && value !== undefined && value !== '')
				.map(([key, value]) => ({
					label: this.formatFieldName(key),
					value: this.formatFieldValue(value),
				}))
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
				const data = JSON.stringify(objectStore.auditTrailItem, null, 2)
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
				const changes = this.formatChanges(objectStore.auditTrailItem.changed)
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

</style>
