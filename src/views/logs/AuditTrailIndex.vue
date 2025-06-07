<script setup>
import { auditTrailStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1>{{ t('openregister', 'Audit Trails') }}</h1>
				<p>{{ t('openregister', 'View and analyze system audit trails with advanced filtering capabilities') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<!-- Display pagination info: showing current page items out of total items -->
					<span class="viewTotalCount">
						{{ t('openregister', 'Showing {showing} of {total} audit trail entries', { showing: paginatedAuditTrails.length, total: auditTrailStore.pagination.total || 0 }) }}
					</span>
					<span v-if="hasActiveFilters" class="viewIndicator">
						({{ t('openregister', 'Filtered') }})
					</span>
				</div>
				<div class="viewActions">
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
			<div v-if="auditTrailStore.isLoading" class="viewLoading">
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

			<div v-else class="viewTableContainer">
				<table class="viewTable auditTrailsTable">
					<thead>
						<tr>
							<th class="actionColumn">
								{{ t('openregister', 'Action') }}
							</th>
							<th class="timestampColumn">
								{{ t('openregister', 'Timestamp') }}
							</th>
							<th class="tableColumnConstrained">
								{{ t('openregister', 'Object ID') }}
							</th>
							<th class="tableColumnConstrained">
								{{ t('openregister', 'Register ID') }}
							</th>
							<th class="tableColumnConstrained">
								{{ t('openregister', 'User') }}
							</th>
							<th class="tableColumnConstrained">
								{{ t('openregister', 'Schema ID') }}
							</th>
							<th class="sizeColumn">
								{{ t('openregister', 'Size') }}
							</th>
							<th class="tableColumnActions">
								{{ t('openregister', 'Actions') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="auditTrail in paginatedAuditTrails"
							:key="auditTrail.id"
							class="viewTableRow auditTrailRow"
							:class="`action-${auditTrail.action}`">
							<td class="actionColumn">
								<span class="actionBadge" :class="`action-${auditTrail.action}`">
									<Plus v-if="auditTrail.action === 'create'" :size="16" />
									<Pencil v-else-if="auditTrail.action === 'update'" :size="16" />
									<Delete v-else-if="auditTrail.action === 'delete'" :size="16" />
									<Eye v-else-if="auditTrail.action === 'read'" :size="16" />
									{{ auditTrail.action ? auditTrail.action.toUpperCase() : 'NO ACTION' }}
								</span>
							</td>
							<td class="timestampColumn">
								<NcDateTime :timestamp="new Date(auditTrail.created)" :ignore-seconds="false" />
							</td>
							<td class="tableColumnConstrained">
								{{ auditTrail.object || '-' }}
							</td>
							<td class="tableColumnConstrained">
								{{ auditTrail.register || '-' }}
							</td>
							<td class="tableColumnConstrained">
								{{ auditTrail.userName || auditTrail.user || '-' }}
							</td>
							<td class="tableColumnConstrained">
								{{ auditTrail.schema || '-' }}
							</td>
							<td class="sizeColumn">
								{{ auditTrail.size || '-' }}
							</td>
							<td class="tableColumnActions">
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
											<Check v-if="copyStates[auditTrail.id]" :size="20" class="copySuccessIcon" />
											<ContentCopy v-else :size="20" />
										</template>
										{{ copyStates[auditTrail.id] ? t('openregister', 'Copied!') : t('openregister', 'Copy Data') }}
									</NcActionButton>
									<NcActionButton close-after-click class="deleteAction" @click="deleteAuditTrail(auditTrail)">
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
			<PaginationComponent
				:current-page="auditTrailStore.pagination.page || 1"
				:total-pages="auditTrailStore.pagination.pages || 1"
				:total-items="auditTrailStore.pagination.total || 0"
				:current-page-size="auditTrailStore.pagination.limit || 50"
				:min-items-to-show="10"
				@page-changed="onPageChanged"
				@page-size-changed="onPageSizeChanged" />
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
import PaginationComponent from '../../components/PaginationComponent.vue'

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
		PaginationComponent,
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
		/**
		 * Handle page change from pagination component
		 * @param {number} page - The page number to change to
		 * @return {Promise<void>}
		 */
		async onPageChanged(page) {
			try {
				await auditTrailStore.refreshAuditTrailList({ page })
			} catch (error) {
				console.error('Error loading page:', error)
			}
		},
		/**
		 * Handle page size change from pagination component
		 * @param {number} pageSize - The new page size
		 * @return {Promise<void>}
		 */
		async onPageSizeChanged(pageSize) {
			try {
				await auditTrailStore.refreshAuditTrailList({
					page: 1,
					limit: pageSize,
				})
			} catch (error) {
				console.error('Error changing page size:', error)
			}
		},
	},
}
</script>

<style scoped>
/* Specific column widths for audit trail table */
.actionColumn {
	width: 100px;
}

.timestampColumn {
	width: 180px;
}

.sizeColumn {
	width: 100px;
}

/* Action-specific row styling */
.viewTableRow.action-create {
	border-left: 4px solid var(--color-info);
}

.viewTableRow.action-update {
	border-left: 4px solid var(--color-warning);
}

.viewTableRow.action-delete {
	border-left: 4px solid var(--color-error);
}

.viewTableRow.action-read {
	border-left: 4px solid var(--color-text-maxcontrast);
}

/* Action badge styling */
.actionBadge {
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

.actionBadge.action-create {
	background: var(--color-success);
	color: white;
}

.actionBadge.action-update {
	background: var(--color-warning);
	color: white;
}

.actionBadge.action-delete {
	background: var(--color-error);
	color: white;
}

.actionBadge.action-read {
	background: var(--color-info);
	color: white;
}

/* Component-specific styling */
:deep(.v-select) {
	margin-bottom: 8px;
}

:deep(.deleteAction) {
	color: var(--color-error) !important;
}

:deep(.deleteAction:hover) {
	background-color: var(--color-error) !important;
	color: var(--color-main-background) !important;
}

.copySuccessIcon {
	color: var(--color-success) !important;
}

:deep(.copySuccessIcon) {
	animation: copySuccess 0.3s ease-in-out;
}

@keyframes copySuccess {
	0% { transform: scale(1); }
	50% { transform: scale(1.2); }
	100% { transform: scale(1); }
}
</style>
