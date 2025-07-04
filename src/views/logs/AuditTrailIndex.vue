<script setup>
import { auditTrailStore, navigationStore } from '../../store/store.js'
import formatBytes from '../../services/formatBytes.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('openregister', 'Audit Trails') }}
				</h1>
				<p>{{ t('openregister', 'View and analyze system audit trails with advanced filtering capabilities') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<!-- Display pagination info: showing current page items out of total items -->
					<span class="viewTotalCount">
						{{ t('openregister', 'Showing {showing} of {total} audit trail entries', { showing: paginatedAuditTrails.length, total: auditTrailStore.auditTrailPagination.total || 0 }) }}
					</span>
					<span v-if="hasActiveFilters" class="viewIndicator">
						({{ t('openregister', 'Filtered') }})
					</span>
					<span v-if="selectedAuditTrails.length > 0" class="viewIndicator">
						({{ t('openregister', '{count} selected', { count: selectedAuditTrails.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<NcActions
						:force-name="true"
						:inline="selectedAuditTrails.length > 0 ? 3 : 2"
						menu-name="Actions">
						<NcActionButton
							v-if="selectedAuditTrails.length > 0"
							type="error"
							close-after-click
							@click="bulkDeleteAuditTrails">
							<template #icon>
								<Delete :size="20" />
							</template>
							{{ t('openregister', 'Delete ({count})', { count: selectedAuditTrails.length }) }}
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="exportAuditTrails">
							<template #icon>
								<Download :size="20" />
							</template>
							{{ t('openregister', 'Export') }}
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="refreshAuditTrails">
							<template #icon>
								<Refresh :size="20" />
							</template>
							{{ t('openregister', 'Refresh') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Audit Trails Table -->
			<div v-if="auditTrailStore.auditTrailLoading" class="viewLoading">
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
							<th class="tableColumnCheckbox">
								<NcCheckboxRadioSwitch
									:checked="allSelected"
									:indeterminate="someSelected"
									@update:checked="toggleSelectAll" />
							</th>
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
							<td class="tableColumnCheckbox">
								<NcCheckboxRadioSwitch
									:checked="selectedAuditTrails.includes(auditTrail.id)"
									@update:checked="(checked) => toggleAuditTrailSelection(auditTrail.id, checked)" />
							</td>
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
								{{ formatBytes(auditTrail.size) }}
							</td>
							<td class="tableColumnActions">
								<NcActions>
									<NcActionButton close-after-click @click="viewDetails(auditTrail)">
										<template #icon>
											<Eye :size="20" />
										</template>
										{{ t('openregister', 'View Details') }}
									</NcActionButton>
									<NcActionButton v-if="hasChanges(auditTrail)" close-after-click @click="viewChanges(auditTrail)">
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
				:current-page="auditTrailStore.auditTrailPagination.page || 1"
				:total-pages="auditTrailStore.auditTrailPagination.pages || 1"
				:total-items="auditTrailStore.auditTrailPagination.total || 0"
				:current-page-size="auditTrailStore.auditTrailPagination.limit || 50"
				:min-items-to-show="10"
				@page-changed="onPageChanged"
				@page-size-changed="onPageSizeChanged" />
		</div>
	</NcAppContent>
</template>

<script>
import {
	NcAppContent,
	NcEmptyContent,
	NcLoadingIcon,
	NcActions,
	NcActionButton,
	NcDateTime,
	NcCheckboxRadioSwitch,
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

import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'AuditTrailIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcDateTime,
		NcCheckboxRadioSwitch,
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
		PaginationComponent,
	},
	data() {
		return {
			itemsPerPage: 50,
			copyStates: {}, // Track copy state for each audit trail
			selectedAuditTrails: [],
		}
	},
	computed: {
		hasActiveFilters() {
			return Object.keys(auditTrailStore.auditTrailFilters || {}).some(key =>
				auditTrailStore.auditTrailFilters[key] !== null
				&& auditTrailStore.auditTrailFilters[key] !== undefined
				&& auditTrailStore.auditTrailFilters[key] !== '',
			)
		},
		paginatedAuditTrails() {
			// Ensure we always return a clean array
			try {
				return Array.isArray(auditTrailStore.auditTrailList) ? auditTrailStore.auditTrailList : []
			} catch (error) {
				console.error('Error accessing auditTrailList:', error)
				return []
			}
		},
		allSelected() {
			return this.paginatedAuditTrails.length > 0 && this.paginatedAuditTrails.every(auditTrail => this.selectedAuditTrails.includes(auditTrail.id))
		},
		someSelected() {
			return this.selectedAuditTrails.length > 0 && !this.allSelected
		},
	},
	watch: {
		paginatedAuditTrails: {
			handler() {
				this.$nextTick(() => {
					this.updateCounts()
				})
			},
			deep: false,
		},
	},
	mounted() {
		// Initialize with safe defaults
		try {
			this.loadAuditTrails()
		} catch (error) {
			console.error('Error in mounted loadAuditTrails:', error)
		}

		// Listen for filter changes from sidebar
		this.$root.$on('audit-trail-filters-changed', this.handleFiltersChanged)
		this.$root.$on('audit-trail-export', this.handleExport)
		this.$root.$on('audit-trail-refresh', this.refreshAuditTrails)

		// Emit counts to sidebar with delay to ensure store is ready
		this.$nextTick(() => {
			this.updateCounts()
		})
	},
	beforeDestroy() {
		this.$root.$off('audit-trail-filters-changed')
		this.$root.$off('audit-trail-export')
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
			auditTrailStore.setAuditTrailFilters(filters)
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
			try {
				const count = Array.isArray(auditTrailStore.auditTrailList) ? auditTrailStore.auditTrailList.length : 0
				this.$root.$emit('audit-trail-filtered-count', count)
			} catch (error) {
				console.error('Error updating counts:', error)
				this.$root.$emit('audit-trail-filtered-count', 0)
			}
		},
		/**
		 * Handle page change from pagination component
		 * @param {number} page - The page number to change to
		 * @return {Promise<void>}
		 */
		async onPageChanged(page) {
			try {
				await auditTrailStore.fetchAuditTrails({
					page,
					limit: auditTrailStore.auditTrailPagination.limit,
				})
				// Clear selection when page changes
				this.selectedAuditTrails = []
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
				await auditTrailStore.fetchAuditTrails({
					page: 1,
					limit: pageSize,
				})
				// Clear selection when page size changes
				this.selectedAuditTrails = []
			} catch (error) {
				console.error('Error changing page size:', error)
			}
		},
		/**
		 * Check if audit trail has changes
		 * @param {object} auditTrail - The audit trail item
		 * @return {boolean} Whether the audit trail has changes
		 */
		hasChanges(auditTrail) {
			try {
				if (!auditTrail || !auditTrail.changed) return false

				if (Array.isArray(auditTrail.changed)) {
					return auditTrail.changed.length > 0
				}

				if (typeof auditTrail.changed === 'object') {
					return Object.keys(auditTrail.changed).length > 0
				}

				return false
			} catch (error) {
				console.error('Error checking changes:', error)
				return false
			}
		},
		formatBytes,
		toggleSelectAll(checked) {
			if (checked) {
				this.selectedAuditTrails = this.paginatedAuditTrails.map(auditTrail => auditTrail.id)
			} else {
				this.selectedAuditTrails = []
			}
		},
		toggleAuditTrailSelection(id, checked) {
			if (checked) {
				this.selectedAuditTrails.push(id)
			} else {
				this.selectedAuditTrails = this.selectedAuditTrails.filter(i => i !== id)
			}
		},
		/**
		 * Delete selected audit trails using bulk operation
		 * @return {Promise<void>}
		 */
		async bulkDeleteAuditTrails() {
			if (this.selectedAuditTrails.length === 0) return

			if (!confirm(this.t('openregister', 'Are you sure you want to delete the selected audit trails? This action cannot be undone.'))) {
				return
			}

			try {
				// Make the API request to delete selected audit trails
				const response = await fetch('/index.php/apps/openregister/api/audit-trails/bulk-delete', {
					method: 'DELETE',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ ids: this.selectedAuditTrails }),
				})

				const result = await response.json()

				if (result.success) {
					OC.Notification.showSuccess(result.message || this.t('openregister', 'Selected audit trails deleted successfully'))
					// Clear selection
					this.selectedAuditTrails = []
					// Refresh the list
					await this.loadAuditTrails()
				} else {
					throw new Error(result.error || 'Deletion failed')
				}
			} catch (error) {
				console.error('Error deleting audit trails:', error)
				OC.Notification.showError(this.t('openregister', 'Error deleting audit trails: {error}', { error: error.message }))
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
