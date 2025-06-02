<script setup>
import { auditTrailStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'auditTrailChanges'"
		:name="t('openregister', 'Audit Trail Changes')"
		size="large"
		:can-close="true"
		@close="closeDialog">
		<div v-if="auditTrailStore.auditTrailItem" class="audit-trail-changes">
			<!-- Header Information -->
			<div class="changes-header">
				<h3>{{ t('openregister', 'Changes for Audit Trail #{id}', { id: auditTrailStore.auditTrailItem.id }) }}</h3>
				<div class="audit-info">
					<span class="action-badge" :class="`action-${auditTrailStore.auditTrailItem.action}`">
						<Plus v-if="auditTrailStore.auditTrailItem.action === 'create'" :size="16" />
						<Pencil v-else-if="auditTrailStore.auditTrailItem.action === 'update'" :size="16" />
						<Delete v-else-if="auditTrailStore.auditTrailItem.action === 'delete'" :size="16" />
						<Eye v-else-if="auditTrailStore.auditTrailItem.action === 'read'" :size="16" />
						{{ auditTrailStore.auditTrailItem.action?.toUpperCase() }}
					</span>
					<span class="timestamp">
						{{ formatDate(auditTrailStore.auditTrailItem.created) }}
					</span>
					<span class="user">
						{{ auditTrailStore.auditTrailItem.userName || auditTrailStore.auditTrailItem.user || 'Unknown User' }}
					</span>
				</div>
			</div>

			<!-- Changes Table -->
			<div v-if="hasChanges" class="changes-section">
				<div v-if="isTableChanges" class="changes-table-container">
					<table class="changes-table">
						<thead>
							<tr>
								<th>{{ t('openregister', 'Field') }}</th>
								<th>{{ t('openregister', 'Old Value') }}</th>
								<th>{{ t('openregister', 'New Value') }}</th>
								<th>{{ t('openregister', 'Change Type') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="(change, field) in changes" :key="field" class="change-row">
								<td class="field-name">
									{{ field }}
								</td>
								<td class="old-value">
									<pre v-if="isObject(change.old)">{{ formatValue(change.old) }}</pre>
									<span v-else>{{ formatValue(change.old) }}</span>
								</td>
								<td class="new-value">
									<pre v-if="isObject(change.new)">{{ formatValue(change.new) }}</pre>
									<span v-else>{{ formatValue(change.new) }}</span>
								</td>
								<td class="change-type">
									<span class="type-badge" :class="getChangeType(change)">
										{{ getChangeTypeLabel(change) }}
									</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Raw changes view for non-standard formats -->
				<div v-else class="raw-changes-container">
					<h4>{{ t('openregister', 'Raw Changes Data') }}</h4>
					<pre>{{ formatChanges(auditTrailStore.auditTrailItem.changed) }}</pre>
				</div>
			</div>

			<!-- No changes message -->
			<div v-else class="no-changes">
				<NcEmptyContent
					:name="t('openregister', 'No changes recorded')"
					:description="t('openregister', 'This audit trail entry does not contain any change information.')">
					<template #icon>
						<InformationOutline />
					</template>
				</NcEmptyContent>
			</div>
		</div>

		<template #actions>
			<NcButton @click="copyChanges">
				<template #icon>
					<ContentCopy :size="20" />
				</template>
				{{ t('openregister', 'Copy Changes') }}
			</NcButton>
			<NcButton @click="viewFullDetails">
				<template #icon>
					<Eye :size="20" />
				</template>
				{{ t('openregister', 'View Full Details') }}
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
	NcEmptyContent,
} from '@nextcloud/vue'

import Close from 'vue-material-design-icons/Close.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'

export default {
	name: 'AuditTrailChanges',
	components: {
		NcDialog,
		NcButton,
		NcEmptyContent,
		// Icons
		Close,
		ContentCopy,
		Eye,
		Plus,
		Pencil,
		Delete,
		InformationOutline,
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
		 * Get processed changes data
		 * @return {object} Processed changes
		 */
		changes() {
			const changed = auditTrailStore.auditTrailItem?.changed
			if (!changed) return {}

			// Try to process as table-style changes
			if (typeof changed === 'object' && !Array.isArray(changed)) {
				// Check if it's in the format { field: { old: value, new: value } }
				const hasStandardFormat = Object.values(changed).every(value =>
					typeof value === 'object'
					&& value !== null
					&& (value.hasOwnProperty('old') || value.hasOwnProperty('new')),
				)

				if (hasStandardFormat) {
					return changed
				}
			}

			return {}
		},

		/**
		 * Check if changes are in table format
		 * @return {boolean} True if table format
		 */
		isTableChanges() {
			return Object.keys(this.changes).length > 0
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
		 * Format value for display
		 * @param {*} value - Value to format
		 * @return {string} Formatted value
		 */
		formatValue(value) {
			if (value === null) return 'null'
			if (value === undefined) return 'undefined'
			if (value === '') return '(empty)'

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
		 * Check if value is an object
		 * @param {*} value - Value to check
		 * @return {boolean} True if object
		 */
		isObject(value) {
			return value !== null && typeof value === 'object'
		},

		/**
		 * Get change type class
		 * @param {object} change - Change object
		 * @return {string} CSS class for change type
		 */
		getChangeType(change) {
			if (!change.hasOwnProperty('old') && change.hasOwnProperty('new')) {
				return 'added'
			}
			if (change.hasOwnProperty('old') && !change.hasOwnProperty('new')) {
				return 'removed'
			}
			if (change.old !== change.new) {
				return 'modified'
			}
			return 'unchanged'
		},

		/**
		 * Get change type label
		 * @param {object} change - Change object
		 * @return {string} Human readable change type
		 */
		getChangeTypeLabel(change) {
			const type = this.getChangeType(change)
			switch (type) {
			case 'added':
				return this.t('openregister', 'Added')
			case 'removed':
				return this.t('openregister', 'Removed')
			case 'modified':
				return this.t('openregister', 'Modified')
			default:
				return this.t('openregister', 'Unchanged')
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

		/**
		 * Switch to full details view
		 * @return {void}
		 */
		viewFullDetails() {
			navigationStore.setDialog('auditTrailDetails')
		},
	},
}
</script>

<style scoped>
.audit-trail-changes {
	padding: 16px 0;
}

.changes-header {
	margin-bottom: 24px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
}

.changes-header h3 {
	margin: 0 0 12px 0;
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.audit-info {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
}

.action-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 0.75rem;
	font-weight: 600;
	color: white;
	background: var(--color-text-maxcontrast);
}

.action-badge.action-create {
	background: var(--color-success);
}

.action-badge.action-update {
	background: var(--color-warning);
}

.action-badge.action-delete {
	background: var(--color-error);
}

.action-badge.action-read {
	background: var(--color-info);
}

.timestamp,
.user {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.changes-section {
	margin-bottom: 24px;
}

.changes-table-container {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	overflow: hidden;
}

.changes-table {
	width: 100%;
	border-collapse: collapse;
}

.changes-table th,
.changes-table td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.changes-table th {
	background: var(--color-background-hover);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.change-row:hover {
	background: var(--color-background-hover);
}

.field-name {
	font-weight: 500;
	min-width: 150px;
}

.old-value,
.new-value {
	max-width: 200px;
	word-break: break-word;
}

.old-value pre,
.new-value pre {
	margin: 0;
	font-family: 'Courier New', monospace;
	font-size: 0.8rem;
	white-space: pre-wrap;
	max-height: 100px;
	overflow-y: auto;
	background: var(--color-background-darker);
	padding: 8px;
	border-radius: 4px;
}

.change-type {
	width: 100px;
}

.type-badge {
	display: inline-flex;
	padding: 2px 6px;
	border-radius: 8px;
	font-size: 0.7rem;
	font-weight: 500;
	color: white;
}

.type-badge.added {
	background: var(--color-success);
}

.type-badge.removed {
	background: var(--color-error);
}

.type-badge.modified {
	background: var(--color-warning);
}

.type-badge.unchanged {
	background: var(--color-text-maxcontrast);
}

.raw-changes-container {
	background: var(--color-background-darker);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 16px;
}

.raw-changes-container h4 {
	margin: 0 0 12px 0;
	font-size: 1rem;
	font-weight: 500;
	color: var(--color-main-text);
}

.raw-changes-container pre {
	margin: 0;
	font-family: 'Courier New', monospace;
	font-size: 0.85rem;
	white-space: pre-wrap;
	word-break: break-word;
	color: var(--color-main-text);
	max-height: 400px;
	overflow-y: auto;
}

.no-changes {
	padding: 40px 20px;
}
</style>
