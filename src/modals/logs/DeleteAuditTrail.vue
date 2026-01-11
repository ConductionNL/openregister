<script setup>
import { auditTrailStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'deleteAuditTrail'"
		:name="t('openregister', 'Delete Audit Trail')"
		size="normal"
		:can-close="false">
		<p v-if="success === null">
			{{ t('openregister', 'Do you want to permanently delete this audit trail entry? This action cannot be undone.') }}
		</p>

		<div v-if="success === null && auditTrailStore.auditTrailItem" class="audit-trail-info">
			<p><strong>{{ t('openregister', 'ID:') }}</strong> {{ auditTrailStore.auditTrailItem.id }}</p>
			<p>
				<strong>{{ t('openregister', 'Action:') }}</strong>
				<span class="action-badge" :class="`action-${auditTrailStore.auditTrailItem.action}`">
					{{ auditTrailStore.auditTrailItem.action?.toUpperCase() }}
				</span>
			</p>
			<p><strong>{{ t('openregister', 'Object:') }}</strong> {{ auditTrailStore.auditTrailItem.object }}</p>
			<p><strong>{{ t('openregister', 'Created:') }}</strong> {{ formatDate(auditTrailStore.auditTrailItem.created) }}</p>
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>{{ t('openregister', 'Audit trail successfully deleted') }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success === null ? t('openregister', 'Cancel') : t('openregister', 'Close') }}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="loading"
				type="error"
				@click="deleteAuditTrail()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<TrashCanOutline v-if="!loading" :size="20" />
				</template>
				{{ t('openregister', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'DeleteAuditTrail',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		TrashCanOutline,
		Cancel,
	},
	data() {
		return {
			success: null,
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},
	methods: {
		/**
		 * Close the dialog and reset state
		 * @return {void}
		 */
		closeDialog() {
			navigationStore.setDialog(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = false
		},

		/**
		 * Delete the audit trail entry
		 * @return {Promise<void>}
		 */
		async deleteAuditTrail() {
			this.loading = true

			try {
				const response = await fetch(`/index.php/apps/openregister/api/audit-trails/${auditTrailStore.auditTrailItem.id}`, {
					method: 'DELETE',
				})

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
				this.error = error.message || t('openregister', 'An error occurred while deleting the audit trail')
			} finally {
				this.loading = false
			}
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
	},
}
</script>

<style scoped>
.audit-trail-info {
	background: var(--color-background-hover);
	padding: 16px;
	border-radius: var(--border-radius);
	margin: 16px 0;
}

.audit-trail-info p {
	margin: 8px 0;
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
</style>
