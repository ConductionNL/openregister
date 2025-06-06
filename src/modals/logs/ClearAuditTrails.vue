<script setup>
import { auditTrailStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'clearAuditTrails'"
		:name="t('openregister', 'Clear Filtered Audit Trails')"
		size="normal"
		:can-close="false">
		<p v-if="success === null">
			{{ t('openregister', 'Do you want to permanently delete all filtered audit trail entries? This action cannot be undone.') }}
		</p>

		<div v-if="success === null" class="clear-info">
			<p><strong>{{ t('openregister', 'Entries to be deleted:') }}</strong> {{ filteredCount }}</p>
			<div v-if="hasActiveFilters" class="active-filters">
				<p><strong>{{ t('openregister', 'Active filters:') }}</strong></p>
				<ul class="filters-list">
					<li v-for="(value, key) in displayFilters" :key="key" class="filter-item">
						<span class="filter-key">{{ formatFilterKey(key) }}:</span>
						<span class="filter-value">{{ formatFilterValue(value) }}</span>
					</li>
				</ul>
			</div>
			<div v-else class="no-filters-warning">
				<NcNoteCard type="warning">
					<p>{{ t('openregister', 'No filters are currently active. This will delete ALL audit trail entries!') }}</p>
				</NcNoteCard>
			</div>
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>{{ successMessage }}</p>
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
				@click="clearAuditTrails()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<DeleteOutline v-if="!loading" :size="20" />
				</template>
				{{ t('openregister', 'Clear Entries') }}
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
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'

export default {
	name: 'ClearAuditTrails',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		DeleteOutline,
		Cancel,
	},
	data() {
		return {
			success: null,
			loading: false,
			error: false,
			successMessage: '',
			closeModalTimeout: null,
			filteredCount: 0,
		}
	},
	computed: {
		hasActiveFilters() {
			return auditTrailStore.filters && Object.keys(auditTrailStore.filters).some(key =>
				auditTrailStore.filters[key] !== null
				&& auditTrailStore.filters[key] !== undefined
				&& auditTrailStore.filters[key] !== '',
			)
		},
		displayFilters() {
			if (!auditTrailStore.filters) return {}
			return Object.fromEntries(
				Object.entries(auditTrailStore.filters).filter(([key, value]) =>
					value !== null && value !== undefined && value !== '',
				),
			)
		},
	},
	watch: {
		'navigationStore.dialog'(newVal) {
			if (newVal === 'clearAuditTrails') {
				// Update filtered count when dialog opens
				this.filteredCount = auditTrailStore.auditTrailCount
			}
		},
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
			this.successMessage = ''
		},

		/**
		 * Clear the filtered audit trails
		 * @return {Promise<void>}
		 */
		async clearAuditTrails() {
			this.loading = true

			try {
				// Build query parameters for deletion
				const params = new URLSearchParams()

				// Add current filters to determine which logs to delete
				if (auditTrailStore.filters) {
					Object.entries(auditTrailStore.filters).forEach(([key, value]) => {
						if (value !== null && value !== undefined && value !== '') {
							params.append(key, value)
						}
					})
				}

				// Make the API request
				const response = await fetch(`/index.php/apps/openregister/api/audit-trails?${params.toString()}`, {
					method: 'DELETE',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				const result = await response.json()

				if (result.success) {
					this.success = true
					this.error = false
					this.successMessage = result.message || this.t('openregister', '{count} audit trails cleared successfully', { count: this.filteredCount })

					// Refresh the audit trail list
					await auditTrailStore.refreshAuditTrailList()

					// Auto-close after 3 seconds
					this.closeModalTimeout = setTimeout(this.closeDialog, 3000)
				} else {
					throw new Error(result.error || 'Deletion failed')
				}
			} catch (error) {
				console.error('Error clearing audit trails:', error)
				this.success = false
				this.error = error.message || this.t('openregister', 'An error occurred while clearing audit trails')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Format filter key for display
		 * @param {string} key - Filter key
		 * @return {string} Formatted key
		 */
		formatFilterKey(key) {
			const keyMap = {
				action: this.t('openregister', 'Action'),
				register: this.t('openregister', 'Register'),
				schema: this.t('openregister', 'Schema'),
				user: this.t('openregister', 'User'),
				dateFrom: this.t('openregister', 'From Date'),
				dateTo: this.t('openregister', 'To Date'),
				object: this.t('openregister', 'Object ID'),
				onlyWithChanges: this.t('openregister', 'Only With Changes'),
			}
			return keyMap[key] || key
		},

		/**
		 * Format filter value for display
		 * @param {any} value - Filter value
		 * @return {string} Formatted value
		 */
		formatFilterValue(value) {
			if (typeof value === 'boolean') {
				return value ? this.t('openregister', 'Yes') : this.t('openregister', 'No')
			}
			if (Array.isArray(value)) {
				return value.join(', ')
			}
			return String(value)
		},
	},
}
</script>

<style scoped>
.clear-info {
	background: var(--color-background-hover);
	padding: 16px;
	border-radius: var(--border-radius);
	margin: 16px 0;
}

.clear-info p {
	margin: 8px 0;
}

.active-filters {
	margin-top: 16px;
}

.filters-list {
	list-style: none;
	padding: 0;
	margin: 8px 0;
}

.filter-item {
	display: flex;
	justify-content: space-between;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.filter-item:last-child {
	border-bottom: none;
}

.filter-key {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.filter-value {
	color: var(--color-main-text);
	font-family: monospace;
}

.no-filters-warning {
	margin-top: 16px;
}
</style>
