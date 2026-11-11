<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { auditTrailStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.dialog === 'auditTrailDetails'"
		:name="t('openregister', 'Audit Trail Details')"
		size="large"
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
						<AuditActionBadge
							:action="auditTrailStore.auditTrailItem.action" />
					</div>
					<div class="detail-item">
						<label>{{ t('openregister', 'Created') }}</label>
						<span>{{
							formatDate(auditTrailStore.auditTrailItem.created)
						}}</span>
					</div>
					<div class="detail-item">
						<label>{{ t('openregister', 'Object ID') }}</label>
						<span>{{
							auditTrailStore.auditTrailItem.object || '-'
						}}</span>
					</div>
					<div class="detail-item">
						<label>{{ t('openregister', 'Register ID') }}</label>
						<span>{{
							auditTrailStore.auditTrailItem.register || '-'
						}}</span>
					</div>
					<div class="detail-item">
						<label>{{ t('openregister', 'Schema ID') }}</label>
						<span>{{
							auditTrailStore.auditTrailItem.schema || '-'
						}}</span>
					</div>
					<div class="detail-item">
						<label>{{ t('openregister', 'User') }}</label>
						<span>{{
							auditTrailStore.auditTrailItem.userName
							|| auditTrailStore.auditTrailItem.user
							|| '-'
						}}</span>
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
					<pre>{{
						formatChanges(auditTrailStore.auditTrailItem.changed)
					}}</pre>
				</div>
			</div>

			<!-- Request Data -->
			<div
				v-if="auditTrailStore.auditTrailItem.request"
				class="details-section">
				<h3>{{ t('openregister', 'Request Data') }}</h3>
				<div class="request-container">
					<pre>{{
						formatJson(auditTrailStore.auditTrailItem.request)
					}}</pre>
				</div>
			</div>

			<!-- Additional Fields -->
			<div class="details-section">
				<h3>{{ t('openregister', 'Additional Information') }}</h3>
				<div class="additional-fields">
					<div
						v-for="[key, value] in additionalFields"
						:key="key"
						class="detail-item">
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
/**
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
 */
import { NcButton, NcDialog } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import CompareHorizontal from 'vue-material-design-icons/CompareHorizontal.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import AuditActionBadge from './AuditActionBadge.vue'
import auditTrailModal from '../../services/auditTrailModal.js'
import { printableValue } from './auditValues.js'

export default {
	name: 'AuditTrailDetails',
	components: {
		AuditActionBadge,
		NcDialog,
		NcButton,
		// Icons
		Close,
		ContentCopy,
		CompareHorizontal,
	},

	mixins: [auditTrailModal],

	computed: {
		/**
		 * Get additional fields that aren't in the main display
		 *
		 * @return {Array} Array of key-value pairs
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		additionalFields() {
			if (!auditTrailStore.auditTrailItem) return []

			const mainFields = mainFieldNames()

			return Object.entries(auditTrailStore.auditTrailItem)
				.filter(([key]) => !mainFields.includes(key))
				.filter(
					([, value]) =>
						value !== null && value !== undefined && value !== '',
				)
		},
	},

	methods: {
		/**
		 * Format JSON data for display
		 *
		 * @param {*} data - Data to format
		 * @return {string} Formatted JSON
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
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
			} catch {
				return String(data)
			}
		},

		/**
		 * Format field name for display
		 *
		 * @param {string} fieldName - Field name to format
		 * @return {string} Formatted field name
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		formatFieldName(fieldName) {
			return fieldName
				.replace(/([A-Z])/g, ' $1')
				.replace(/^./, (str) => str.toUpperCase())
				.trim()
		},

		/**
		 * Format field value for display
		 *
		 * @param {*} value - Value to format
		 * @return {string} Formatted value
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		formatFieldValue(value) {
			if (value === null || value === undefined) return '-'

			return printableValue(value)
		},

		/**
		 * Copy full audit trail data to clipboard
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async copyFullData() {
			try {
				const data = JSON.stringify(auditTrailStore.auditTrailItem, null, 2)
				await navigator.clipboard.writeText(data)
				OC.Notification.showSuccess(
					this.t('openregister', 'Full data copied to clipboard'),
				)
			} catch (error) {
				console.error('Error copying to clipboard:', error)
				OC.Notification.showError(
					this.t('openregister', 'Failed to copy data'),
				)
			}
		},
	},
}

/**
 * The fields of an audit trail entry the modal shows on their own, rather
 * than among the additional fields.
 *
 * @return {Array<string>} The field names
 */
function mainFieldNames() {
	return [
		'id',
		'action',
		'created',
		'object',
		'register',
		'schema',
		'user',
		'userName',
		'size',
		'changed',
		'request',
	]
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

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

@include detail-item;

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
	overflow-wrap: break-word;
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
