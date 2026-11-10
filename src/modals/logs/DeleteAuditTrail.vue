<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { auditTrailStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<ConfirmDialogFrame
		v-if="navigationStore.dialog === 'deleteAuditTrail'"
		:name="t('openregister', 'Delete Audit Trail')"
		:success="success"
		:error="error"
		:loading="loading"
		:showConfirm="success === null"
		:successText="t('openregister', 'Audit trail successfully deleted')"
		:closeLabel="
			success === null
				? t('openregister', 'Cancel')
				: t('openregister', 'Close')
		"
		:confirmLabel="t('openregister', 'Delete')"
		@cancel="closeDialog"
		@confirm="deleteAuditTrail()">
		<p v-if="success === null">
			{{
				t(
					'openregister',
					'Do you want to permanently delete this audit trail entry? This action cannot be undone.',
				)
			}}
		</p>

		<div
			v-if="success === null && auditTrailStore.auditTrailItem"
			class="audit-trail-info">
			<p>
				<strong>{{ t('openregister', 'ID:') }}</strong>
				{{ auditTrailStore.auditTrailItem.id }}
			</p>
			<p>
				<strong>{{ t('openregister', 'Action:') }}</strong>
				<span
					class="action-badge"
					:class="`action-${auditTrailStore.auditTrailItem.action}`">
					{{ auditTrailStore.auditTrailItem.action?.toUpperCase() }}
				</span>
			</p>
			<p>
				<strong>{{ t('openregister', 'Object:') }}</strong>
				{{ auditTrailStore.auditTrailItem.object }}
			</p>
			<p>
				<strong>{{ t('openregister', 'Created:') }}</strong>
				{{ formatDate(auditTrailStore.auditTrailItem.created) }}
			</p>
		</div>
	</ConfirmDialogFrame>
</template>

<script>
import ConfirmDialogFrame from '../shared/ConfirmDialogFrame.vue'
import { confirmDialogState } from '../shared/confirmDialogState.js'
/**
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
 */

export default {
	name: 'DeleteAuditTrail',
	components: {
		ConfirmDialogFrame,
	},

	mixins: [confirmDialogState(null)],

	methods: {
		/**
		 * Delete the audit trail entry
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async deleteAuditTrail() {
			this.loading = true

			try {
				const response = await fetch(
					`/index.php/apps/openregister/api/audit-trails/${auditTrailStore.auditTrailItem.id}`,
					{
						method: 'DELETE',
					},
				)

				const result = await response.json()

				if (result.success) {
					this.success = true
					this.error = false
					// Refresh the audit trail list
					await auditTrailStore.refreshAuditTrailList()
					// Auto-close after 2 seconds
					this.closeModalTimeout = setTimeout(this.closeDialog, 2000)
				} else {
					throw new Error(result.error || 'Deletion failed')
				}
			} catch (error) {
				this.success = false
				this.error =
					error.message
					|| t(
						'openregister',
						'An error occurred while deleting the audit trail',
					)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Format date for display
		 *
		 * @param {string} dateString - Date string to format
		 * @return {string} Formatted date
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		formatDate(dateString) {
			if (!dateString) return '-'
			try {
				return new Date(dateString).toLocaleString()
			} catch {
				return dateString
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

.audit-trail-info {
	background: var(--color-background-hover);
	padding: 16px;
	border-radius: var(--border-radius);
	margin: 16px 0;
}

.audit-trail-info p {
	margin: 8px 0;
}

@include action-badge;

@include action-badge-colours;
</style>
