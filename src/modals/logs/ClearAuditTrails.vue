<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { auditTrailStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<ConfirmDialogFrame
		v-if="navigationStore.dialog === 'clearAuditTrails'"
		:name="t('openregister', 'Clear Filtered Audit Trails')"
		:success="success"
		:error="error"
		:loading="loading"
		:showConfirm="success === null"
		:successText="successMessage"
		:closeLabel="
			success === null
				? t('openregister', 'Cancel')
				: t('openregister', 'Close')
		"
		:confirmLabel="t('openregister', 'Clear Entries')"
		@cancel="closeDialog"
		@confirm="clearAuditTrails()">
		<p v-if="success === null">
			{{
				t(
					'openregister',
					'Do you want to permanently delete all filtered audit trail entries? This action cannot be undone.',
				)
			}}
		</p>

		<div v-if="success === null" class="clear-info">
			<p>
				<strong>{{ t('openregister', 'Entries to be deleted:') }}</strong>
				{{ filteredCount }}
			</p>
			<div v-if="hasActiveFilters" class="active-filters">
				<p>
					<strong>{{ t('openregister', 'Active filters:') }}</strong>
				</p>
				<ul class="filters-list">
					<li
						v-for="(value, key) in displayFilters"
						:key="key"
						class="filter-item">
						<span class="filter-key">{{ formatFilterKey(key) }}:</span>
						<span class="filter-value">{{
							formatFilterValue(value)
						}}</span>
					</li>
				</ul>
			</div>
			<div v-else class="no-filters-warning">
				<NcNoteCard type="warning">
					<p>
						{{
							t(
								'openregister',
								'No filters are currently active. This will delete ALL audit trail entries!',
							)
						}}
					</p>
				</NcNoteCard>
			</div>
		</div>
		<template #confirmIcon>
			<DeleteOutline :size="20" />
		</template>
	</ConfirmDialogFrame>
</template>

<script>
import { NcNoteCard } from '@nextcloud/vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import ConfirmDialogFrame from '../shared/ConfirmDialogFrame.vue'
import { confirmDialogState } from '../shared/confirmDialogState.js'
import { deletionAnswer } from './deletionAnswer.js'

export default {
	name: 'ClearAuditTrails',
	components: {
		ConfirmDialogFrame,
		NcNoteCard,
		DeleteOutline,
	},

	mixins: [confirmDialogState(null)],

	data() {
		return {
			successMessage: '',
			filteredCount: 0,
		}
	},

	computed: {
		// A <script setup> binding is not on `this`, and a watch path reads `this`.
		navigationStore: () => navigationStore,

		/**
		 * @spec exclude UI state helper — reports whether any audit-trail filter is active.
		 */
		hasActiveFilters() {
			return (
				auditTrailStore.auditTrailFilters
				&& Object.keys(auditTrailStore.auditTrailFilters).some(
					(key) =>
						auditTrailStore.auditTrailFilters[key] !== null
						&& auditTrailStore.auditTrailFilters[key] !== undefined
						&& auditTrailStore.auditTrailFilters[key] !== '',
				)
			)
		},

		/**
		 * @spec exclude UI display helper — returns only the non-empty filters for display.
		 */
		displayFilters() {
			if (!auditTrailStore.auditTrailFilters) return {}
			return Object.fromEntries(
				Object.entries(auditTrailStore.auditTrailFilters).filter(
					([_key, value]) =>
						value !== null && value !== undefined && value !== '',
				),
			)
		},
	},

	watch: {
		'navigationStore.dialog': function (newVal) {
			if (newVal === 'clearAuditTrails') {
				// Update filtered count when dialog opens
				this.filteredCount = auditTrailStore.auditTrailPagination.total
			}
		},
	},

	methods: {
		/**
		 * Close the dialog and reset state
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/retrofit-2026-05-24-audit-trail-immutable/tasks.md#task-3
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
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/retrofit-2026-05-24-audit-trail-immutable/tasks.md#task-3
		 */
		async clearAuditTrails() {
			this.loading = true

			try {
				const result = await this.requestClear()
				await this.showCleared(result)
			} catch (error) {
				this.showClearFailure(error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Say how many entries were cleared, refresh the audit trail list and
		 * close after three seconds.
		 *
		 * @param {object} result The server's answer
		 * @return {Promise<void>}
		 */
		async showCleared(result) {
			this.success = true
			this.error = false
			this.successMessage =
				result.message
				|| this.t(
					'openregister',
					'{count} audit trails cleared successfully',
					{
						count: this.filteredCount,
					},
				)
			await auditTrailStore.refreshAuditTrailList()
			this.closeModalTimeout = setTimeout(this.closeDialog, 3000)
		},

		/**
		 * Log a failed clear and say why it failed.
		 *
		 * @param {Error} error What went wrong
		 * @return {void}
		 */
		showClearFailure(error) {
			console.error('Error clearing audit trails:', error)
			this.success = false
			this.error =
				error.message
				|| this.t(
					'openregister',
					'An error occurred while clearing audit trails',
				)
		},

		/**
		 * Ask the server to delete the audit trails that match the active
		 * filters.
		 *
		 * @return {Promise<object>} The server's answer when it reports success
		 * @throws {Error} When the server reports a failure
		 *
		 * @spec openspec/changes/retrofit-2026-05-24-audit-trail-immutable/tasks.md#task-3
		 */
		async requestClear() {
			const params = new URLSearchParams(Object.entries(this.displayFilters))
			const response = await fetch(
				`/index.php/apps/openregister/api/audit-trails?${params.toString()}`,
				{
					method: 'DELETE',
					headers: { 'Content-Type': 'application/json' },
				},
			)
			return deletionAnswer(response)
		},

		/**
		 * Format filter key for display
		 *
		 * @param {string} key - Filter key
		 * @return {string} Formatted key
		 * @spec exclude UI display helper — translates filter keys to human labels.
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
		 *
		 * @param {any} value - Filter value
		 * @return {string} Formatted value
		 * @spec exclude UI display helper — formats filter values for display.
		 */
		formatFilterValue(value) {
			if (typeof value === 'boolean') {
				return value
					? this.t('openregister', 'Yes')
					: this.t('openregister', 'No')
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
