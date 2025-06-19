<script setup>
import { auditTrailStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="container">
			<!-- Header -->
			<div class="header">
				<h1>{{ t('openregister', 'Audit Trails') }}</h1>
				<p>{{ t('openregister', 'View and analyze system audit trails with advanced filtering capabilities') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="actions-bar">
				<div class="audit-trail-info">
					<span class="total-count">
						{{ t('openregister', '{count} audit trail entries', { count: auditTrailStore.auditTrailCount }) }}
					</span>
					<span v-if="hasActiveFilters" class="filter-indicator">
						({{ t('openregister', 'Filtered') }})
					</span>
				</div>
				<div class="actions">
					<NcButton @click="exportAuditTrails">
						<template #icon>
							<Download :size="20" />
						</template>
						{{ t('openregister', 'Export') }}
					</NcButton>
					<NcButton @click="clearAuditTrails">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('openregister', 'Clear Filtered') }}
					</NcButton>
					<NcButton @click="refreshAuditTrails">
						<template #icon>
							<Refresh :size="20" />
						</template>
						{{ t('openregister', 'Refresh') }}
					</NcButton>
				</div>
			</div>

			<!-- Audit Trails Table -->
			<div v-if="auditTrailStore.isLoading" class="loading">
				<NcLoadingIcon :size="64" />
				<p>{{ t('openregister', 'Loading audit trails...') }}</p>
			</div>

			<NcEmptyContent v-else-if="!auditTrailStore.auditTrailList.length"
				:name="t('openregister', 'No audit trail entries found')"
				:description="t('openregister', 'There are no audit trail entries matching your current filters.')">
				<template #icon>
					<TextBoxOutline />
				</template>
			</NcEmptyContent>

			<div v-else class="table-container">
				<table class="audit-trails-table">
					<thead>
						<tr>
							<th class="action-column">
								{{ t('openregister', 'Action') }}
							</th>
							<th class="timestamp-column">
								{{ t('openregister', 'Timestamp') }}
							</th>
							<th class="object-column">
								{{ t('openregister', 'Object ID') }}
							</th>
							<th class="register-column">
								{{ t('openregister', 'Register ID') }}
							</th>
							<th class="user-column">
								{{ t('openregister', 'User') }}
							</th>
							<th class="schema-column">
								{{ t('openregister', 'Schema ID') }}
							</th>
							<th class="size-column">
								{{ t('openregister', 'Size') }}
							</th>
							<th class="actions-column">
								{{ t('openregister', 'Actions') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="auditTrail in paginatedAuditTrails"
							:key="auditTrail.id"
							class="audit-trail-row"
							:class="`action-${auditTrail.action}`">
							<td class="action-column">
								<span class="action-badge" :class="`action-${auditTrail.action}`">
									<Plus v-if="auditTrail.action === 'create'" :size="16" />
									<Pencil v-else-if="auditTrail.action === 'update'" :size="16" />
									<Delete v-else-if="auditTrail.action === 'delete'" :size="16" />
									<Eye v-else-if="auditTrail.action === 'read'" :size="16" />
									{{ auditTrail.action ? auditTrail.action.toUpperCase() : 'NO ACTION' }}
								</span>
							</td>
							<td class="timestamp-column">
								<NcDateTime :timestamp="new Date(auditTrail.created)" :ignore-seconds="false" />
							</td>
							<td class="object-column">
								{{ auditTrail.object || '-' }}
							</td>
							<td class="register-column">
								{{ auditTrail.register || '-' }}
							</td>
							<td class="user-column">
								{{ auditTrail.userName || auditTrail.user || '-' }}
							</td>
							<td class="schema-column">
								{{ auditTrail.schema || '-' }}
							</td>
							<td class="size-column">
								{{ auditTrail.size || '-' }}
							</td>
							<td class="actions-column">
								<NcActions>
									<NcActionButton close-after-click @click="viewDetails(auditTrail)">
										<template #icon>
											<Eye :size="20" />
										</template>
										{{ t('openregister', 'View Details') }}
									</NcActionButton>
									<NcActionButton v-if="auditTrail.changed && (Array.isArray(auditTrail.changed) ? auditTrail.changed.length > 0 : Object.keys(auditTrail.changed).length > 0)" close-after-click @click="viewChanges(auditTrail)">
										<template #icon>
											<CompareHorizontal :size="20" />
										</template>
										{{ t('openregister', 'View Changes') }}
									</NcActionButton>
									<NcActionButton close-after-click @click="copyData(auditTrail)">
										<template #icon>
											<Check v-if="copyStates[auditTrail.id]" :size="20" class="copy-success-icon" />
											<ContentCopy v-else :size="20" />
										</template>
										{{ copyStates[auditTrail.id] ? t('openregister', 'Copied!') : t('openregister', 'Copy Data') }}
									</NcActionButton>
									<NcActionButton close-after-click class="delete-action" @click="deleteAuditTrail(auditTrail)">
										<template #icon>
											<Delete :size="20" />
										</template>
										{{ t('openregister', 'Delete') }}
									</NcActionButton>
								</NcActions>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Pagination -->
			<div v-if="auditTrailStore.pagination.pages > 1" class="pagination">
				<NcButton
					:disabled="auditTrailStore.pagination.page === 1"
					@click="goToPage(1)">
					{{ t('openregister', 'First') }}
				</NcButton>
				<NcButton
					:disabled="auditTrailStore.pagination.page === 1"
					@click="goToPage(auditTrailStore.pagination.page - 1)">
					{{ t('openregister', 'Previous') }}
				</NcButton>
				<span class="page-info">
					{{ t('openregister', 'Page {current} of {total}', {
						current: auditTrailStore.pagination.page,
						total: auditTrailStore.pagination.pages
					}) }}
				</span>
				<NcButton
					:disabled="auditTrailStore.pagination.page === auditTrailStore.pagination.pages"
					@click="goToPage(auditTrailStore.pagination.page + 1)">
					{{ t('openregister', 'Next') }}
				</NcButton>
				<NcButton
					:disabled="auditTrailStore.pagination.page === auditTrailStore.pagination.pages"
					@click="goToPage(auditTrailStore.pagination.pages)">
					{{ t('openregister', 'Last') }}
				</NcButton>
			</div>
		</div>

		<!-- Import the new modals -->
		<DeleteAuditTrail />
		<AuditTrailDetails />
		<AuditTrailChanges />
		<ClearAuditTrails />
	</NcAppContent>
</template>

<script>
import {
	NcAppContent,
	NcEmptyContent,
	NcButton,
	NcLoadingIcon,
	NcActions,
	NcActionButton,
	NcDateTime,
} from '@nextcloud/vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import CompareHorizontal from 'vue-material-design-icons/CompareHorizontal.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Check from 'vue-material-design-icons/Check.vue'

// Import the new modals
import DeleteAuditTrail from '../../modals/logs/DeleteAuditTrail.vue'
import AuditTrailDetails from '../../modals/logs/AuditTrailDetails.vue'
import AuditTrailChanges from '../../modals/logs/AuditTrailChanges.vue'
import ClearAuditTrails from '../../modals/logs/ClearAuditTrails.vue'

export default {
	name: 'AuditTrailIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcButton,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcDateTime,
		TextBoxOutline,
		Download,
		Delete,
		Refresh,
		Eye,
		Plus,
		Pencil,
		CompareHorizontal,
		ContentCopy,
		Check,
		// Modal components
		DeleteAuditTrail,
		AuditTrailDetails,
		AuditTrailChanges,
		ClearAuditTrails,
	},
	data() {
		return {
			itemsPerPage: 50,
			copyStates: {}, // Track copy state for each audit trail
		}
	},
	computed: {
		hasActiveFilters() {
			return Object.keys(auditTrailStore.filters || {}).some(key =>
				auditTrailStore.filters[key] !== null
				&& auditTrailStore.filters[key] !== undefined
				&& auditTrailStore.filters[key] !== '',
			)
		},
		paginatedAuditTrails() {
			// The store handles pagination, so we return the full list
			return auditTrailStore.auditTrailList
		},
	},
	watch: {
		'auditTrailStore.auditTrailList'() {
			this.updateCounts()
		},
	},
	mounted() {
		this.loadAuditTrails()

		// Listen for filter changes from sidebar
		this.$root.$on('audit-trail-filters-changed', this.handleFiltersChanged)
		this.$root.$on('audit-trail-export', this.handleExport)
		this.$root.$on('audit-trail-clear-filtered', this.clearAuditTrails)
		this.$root.$on('audit-trail-refresh', this.refreshAuditTrails)

		// Emit counts to sidebar
		this.updateCounts()
	},
	beforeDestroy() {
		this.$root.$off('audit-trail-filters-changed')
		this.$root.$off('audit-trail-export')
		this.$root.$off('audit-trail-clear-filtered')
		this.$root.$off('audit-trail-refresh')
	},
	methods: {
		/**
		 * Load audit trails from API
		 * @return {Promise<void>}
		 */
		async loadAuditTrails() {
			try {
				await auditTrailStore.refreshAuditTrailList()
			} catch (error) {
				console.error('Error loading audit trails:', error)
				OC.Notification.showError(this.t('openregister', 'Error loading audit trails'))
			}
		},
		/**
		 * Handle filter changes from sidebar
		 * @param {object} filters - Filter object from sidebar
		 * @return {void}
		 */
		handleFiltersChanged(filters) {
			auditTrailStore.setFilters(filters)
			// Refresh with new filters
			this.loadAuditTrails()
		},
		/**
		 * Handle export request from sidebar
		 * @param {object} options - Export options from sidebar
		 * @return {void}
		 */
		handleExport(options) {
			this.exportFilteredAuditTrails(options)
		},
		/**
		 * Go to specific page
		 * @param {number} page - Page number
		 * @return {Promise<void>}
		 */
		async goToPage(page) {
			try {
				await auditTrailStore.refreshAuditTrailList({ page })
			} catch (error) {
				console.error('Error loading page:', error)
			}
		},
		/**
		 * View detailed information for an audit trail entry
		 * @param {object} auditTrail - Audit trail entry to view
		 * @return {void}
		 */
		viewDetails(auditTrail) {
			// Set the audit trail item in the store
			auditTrailStore.setAuditTrailItem(auditTrail)
			// Open the details modal
			navigationStore.setDialog('auditTrailDetails')
		},
		/**
		 * View changes information for an audit trail entry
		 * @param {object} auditTrail - Audit trail entry with changes
		 * @return {void}
		 */
		viewChanges(auditTrail) {
			// Set the audit trail item and open the specialized changes modal
			auditTrailStore.setAuditTrailItem(auditTrail)
			navigationStore.setDialog('auditTrailChanges')
		},
		/**
		 * Copy audit trail data to clipboard
		 * @param {object} auditTrail - Audit trail entry to copy
		 * @return {Promise<void>}
		 */
		async copyData(auditTrail) {
			try {
				const data = JSON.stringify(auditTrail, null, 2)
				await navigator.clipboard.writeText(data)

				// Set successful copy state
				this.$set(this.copyStates, auditTrail.id, true)

				// Show success notification with enhanced styling
				OC.Notification.showSuccess(this.t('openregister', 'Audit trail data copied to clipboard'))

				// Reset copy state after 2 seconds
				setTimeout(() => {
					this.$set(this.copyStates, auditTrail.id, false)
				}, 2000)

			} catch (error) {
				console.error('Error copying to clipboard:', error)
				// Fallback for older browsers or when clipboard API is not available
				try {
					const textArea = document.createElement('textarea')
					textArea.value = JSON.stringify(auditTrail, null, 2)
					document.body.appendChild(textArea)
					textArea.select()
					document.execCommand('copy')
					document.body.removeChild(textArea)

					// Set successful copy state for fallback method too
					this.$set(this.copyStates, auditTrail.id, true)

					OC.Notification.showSuccess(this.t('openregister', 'Audit trail data copied to clipboard'))

					// Reset copy state after 2 seconds
					setTimeout(() => {
						this.$set(this.copyStates, auditTrail.id, false)
					}, 2000)

				} catch (fallbackError) {
					console.error('Fallback copy failed:', fallbackError)
					OC.Notification.showError(this.t('openregister', 'Failed to copy data to clipboard'))
				}
			}
		},
		/**
		 * Export audit trails with current filters
		 * @return {void}
		 */
		exportAuditTrails() {
			this.exportFilteredAuditTrails({ format: 'csv', includeChanges: true })
		},
		/**
		 * Export filtered audit trails with specified options
		 * @param {object} options - Export options
		 * @return {Promise<void>}
		 */
		async exportFilteredAuditTrails(options) {
			try {
				// Build query parameters
				const params = new URLSearchParams()
				params.append('format', options.format || 'csv')
				params.append('includeChanges', options.includeChanges || false)
				params.append('includeMetadata', options.includeMetadata || false)

				// Add current filters
				if (auditTrailStore.filters) {
					Object.entries(auditTrailStore.filters).forEach(([key, value]) => {
						if (value !== null && value !== undefined && value !== '') {
							params.append(key, value)
						}
					})
				}

				// Make the API request
				const response = await fetch(`/index.php/apps/openregister/api/audit-trails/export?${params.toString()}`)
				const result = await response.json()

				if (result.success && result.data) {
					// Create and trigger download
					const blob = new Blob([result.data.content], { type: result.data.contentType })
					const url = window.URL.createObjectURL(blob)
					const a = document.createElement('a')
					a.href = url
					a.download = result.data.filename
					document.body.appendChild(a)
					a.click()
					window.URL.revokeObjectURL(url)
					document.body.removeChild(a)

					OC.Notification.showSuccess(this.t('openregister', 'Export completed successfully'))
				} else {
					throw new Error(result.error || 'Export failed')
				}
			} catch (error) {
				console.error('Error exporting audit trails:', error)
				OC.Notification.showError(this.t('openregister', 'Export failed: {error}', { error: error.message }))
			}
		},
		/**
		 * Clear filtered audit trails
		 * @return {Promise<void>}
		 */
		async clearAuditTrails() {
			if (!confirm(this.t('openregister', 'Are you sure you want to clear the filtered audit trails? This action cannot be undone.'))) {
				return
			}

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
					OC.Notification.showSuccess(result.message || this.t('openregister', 'Audit trails cleared successfully'))
					// Refresh the list
					await this.loadAuditTrails()
				} else {
					throw new Error(result.error || 'Deletion failed')
				}
			} catch (error) {
				console.error('Error clearing audit trails:', error)
				OC.Notification.showError(this.t('openregister', 'Error clearing audit trails: {error}', { error: error.message }))
			}
		},
		/**
		 * Delete a single audit trail using the new modal
		 * @param {object} auditTrail - Audit trail to delete
		 * @return {void}
		 */
		deleteAuditTrail(auditTrail) {
			// Set the audit trail item in the store
			auditTrailStore.setAuditTrailItem(auditTrail)
			// Open the delete modal
			navigationStore.setDialog('deleteAuditTrail')
		},
		/**
		 * Refresh audit trails list
		 * @return {Promise<void>}
		 */
		async refreshAuditTrails() {
			await this.loadAuditTrails()
		},
		/**
		 * Update counts for sidebar
		 * @return {void}
		 */
		updateCounts() {
			this.$root.$emit('audit-trail-filtered-count', auditTrailStore.auditTrailCount)
		},
	},
}
</script>

<style scoped>
.container {
	padding: 20px;
	max-width: 100%;
}

.header {
	margin-bottom: 30px;
}

.header h1 {
	margin: 0 0 10px 0;
	font-size: 2rem;
	font-weight: 300;
}

.header p {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.actions-bar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
	padding: 10px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.audit-trail-info {
	display: flex;
	align-items: center;
	gap: 10px;
}

.total-count {
	font-weight: 500;
	color: var(--color-main-text);
}

.filter-indicator {
	font-size: 0.9em;
	color: var(--color-primary);
}

.actions {
	display: flex;
	gap: 10px;
}

.loading {
	text-align: center;
	padding: 50px;
}

.loading p {
	margin-top: 20px;
	color: var(--color-text-maxcontrast);
}

.table-container {
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 2px 4px var(--color-box-shadow);
}

.audit-trails-table {
	width: 100%;
	border-collapse: collapse;
}

.audit-trails-table th,
.audit-trails-table td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.audit-trails-table th {
	background: var(--color-background-hover);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.action-column {
	width: 100px;
}

.timestamp-column {
	width: 180px;
}

.object-column {
	width: 150px;
}

.register-column {
	width: 150px;
}

.user-column {
	width: 120px;
}

.schema-column {
	width: 150px;
}

.size-column {
	width: 100px;
}

.actions-column {
	width: 100px;
	text-align: center;
}

.audit-trail-row:hover {
	background: var(--color-background-hover);
}

.audit-trail-row.action-create {
	border-left: 4px solid var(--color-info);
}

.audit-trail-row.action-update {
	border-left: 4px solid var(--color-warning);
}

.audit-trail-row.action-delete {
	border-left: 4px solid var(--color-error);
}

.audit-trail-row.action-read {
	border-left: 4px solid var(--color-text-maxcontrast);
}

.pagination {
	display: flex;
	justify-content: center;
	align-items: center;
	gap: 20px;
	margin-top: 30px;
	padding: 20px;
}

.page-info {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

/* Log level chip styling */
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
	color: white;
}

.action-badge.action-update {
	background: var(--color-warning);
	color: white;
}

.action-badge.action-delete {
	background: var(--color-error);
	color: white;
}

.action-badge.action-read {
	background: var(--color-info);
	color: white;
}

/* Add some spacing between select inputs */
:deep(.v-select) {
	margin-bottom: 8px;
}

/* Delete action styling */
:deep(.delete-action) {
	color: var(--color-error) !important;
}

:deep(.delete-action:hover) {
	background-color: var(--color-error) !important;
	color: var(--color-main-background) !important;
}

/* Copy success feedback styling */
.copy-success-icon {
	color: var(--color-success) !important;
}

:deep(.copy-success-icon) {
	animation: copySuccess 0.3s ease-in-out;
}

@keyframes copySuccess {
	0% { transform: scale(1); }
	50% { transform: scale(1.2); }
	100% { transform: scale(1); }
}
</style>
