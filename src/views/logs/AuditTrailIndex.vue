<script setup>
import { translate as t } from '@nextcloud/l10n'
import formatBytes from '../../services/formatBytes.js'
import { auditTrailStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openregister', 'Audit Trails')"
			:description="
				t(
					'openregister',
					'View and analyze system audit trails with advanced filtering capabilities',
				)
			"
			:showTitle="true"
			:objects="paginatedAuditTrails"
			:columns="tableColumns"
			:pagination="paginationData"
			:loading="auditTrailStore.auditTrailLoading"
			viewMode="table"
			:availableViewModes="['table']"
			:showViewToggle="false"
			:selectable="true"
			:selectedIds="selectedAuditTrails"
			:showAdd="false"
			:showFormDialog="false"
			:showViewAction="false"
			:showEditAction="false"
			:showCopyAction="false"
			:showDeleteAction="false"
			:showMassImport="false"
			:showMassExport="false"
			:showMassCopy="false"
			showMassDelete
			:nameFormatter="auditTrailName"
			rowKey="id"
			:rowClass="getRowClass"
			:refreshing="isRefreshing"
			@refresh="handleRefresh"
			@pageChanged="onPageChanged"
			@pageSizeChanged="onPageSizeChanged"
			@select="onSelect"
			@massDelete="handleMassDelete">
			<!-- Export covers the current filter set rather than the row selection,
			     so it stays a custom entry instead of the built-in mass export. -->
			<template #action-items>
				<NcActionButton closeAfterClick @click="exportAuditTrails">
					<template #icon>
						<Download :size="20" />
					</template>
					{{ t('openregister', 'Export') }}
				</NcActionButton>
			</template>

			<template #column-action="{ row }">
				<CnStatusBadge
					:label="
						row.action
							? row.action.toUpperCase()
							: t('openregister', 'NO ACTION')
					"
					:colorMap="actionColorMap"
					solid>
					<template #icon>
						<Plus v-if="row.action === 'create'" :size="16" />
						<Pencil v-else-if="row.action === 'update'" :size="16" />
						<Delete v-else-if="row.action === 'delete'" :size="16" />
						<Eye v-else-if="row.action === 'read'" :size="16" />
					</template>
				</CnStatusBadge>
			</template>

			<template #column-created="{ row }">
				<NcDateTime
					:timestamp="new Date(row.created)"
					:ignoreSeconds="false" />
			</template>

			<template #column-object="{ row }">
				{{ row.object || '-' }}
			</template>

			<template #column-register="{ row }">
				{{ row.register || '-' }}
			</template>

			<template #column-userName="{ row }">
				{{ row.userName || row.user || '-' }}
			</template>

			<template #column-schema="{ row }">
				{{ row.schema || '-' }}
			</template>

			<template #column-size="{ row }">
				{{ formatBytes(row.size) }}
			</template>

			<template #row-actions="{ row }">
				<NcActions>
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton closeAfterClick @click="viewDetails(row)">
						<template #icon>
							<Eye :size="20" />
						</template>
						{{ t('openregister', 'View Details') }}
					</NcActionButton>
					<NcActionButton
						v-if="hasChanges(row)"
						closeAfterClick
						@click="viewChanges(row)">
						<template #icon>
							<CompareHorizontal :size="20" />
						</template>
						{{ t('openregister', 'View Changes') }}
					</NcActionButton>
					<NcActionButton closeAfterClick @click="copyData(row)">
						<template #icon>
							<Check
								v-if="copyStates[row.id]"
								:size="20"
								class="copySuccessIcon" />
							<ContentCopy v-else :size="20" />
						</template>
						{{
							copyStates[row.id]
								? t('openregister', 'Copied!')
								: t('openregister', 'Copy Data')
						}}
					</NcActionButton>
					<NcActionButton closeAfterClick @click="deleteAuditTrail(row)">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('openregister', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</template>

			<template #empty>
				<NcEmptyContent
					:name="t('openregister', 'No audit trail entries found')"
					:description="
						t(
							'openregister',
							'There are no audit trail entries matching your current filters.',
						)
					">
					<template #icon>
						<TextBoxOutline />
					</template>
				</NcEmptyContent>
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { CnIndexPage, CnStatusBadge } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
/**
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
 */
import {
	NcActionButton,
	NcActions,
	NcAppContent,
	NcDateTime,
	NcEmptyContent,
} from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import CompareHorizontal from 'vue-material-design-icons/CompareHorizontal.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import eventBus from '../../eventBus.js'

export default {
	name: 'AuditTrailIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcActions,
		NcActionButton,
		NcDateTime,
		CnIndexPage,
		CnStatusBadge,
		TextBoxOutline,
		Download,
		Delete,
		Eye,
		Plus,
		Pencil,
		CompareHorizontal,
		ContentCopy,
		Check,
		DotsHorizontal,
	},

	data() {
		return {
			copyStates: {}, // Track copy state for each audit trail
			selectedAuditTrails: [],
			isRefreshing: false,
			// Keys are matched case-insensitively against the badge label, so
			// namespaced actions (file.renamed, referential_integrity.*) fall
			// through to the neutral 'default' variant.
			actionColorMap: {
				create: 'success',
				update: 'warning',
				delete: 'error',
				read: 'info',
			},
		}
	},

	computed: {
		/**
		 * Column definitions for the audit trail table.
		 *
		 * @spec exclude UI plumbing — static table column list for display.
		 * @return {Array<object>}
		 */
		tableColumns() {
			return [
				{
					key: 'action',
					label: t('openregister', 'Action'),
					width: '100px',
				},
				{
					key: 'created',
					label: t('openregister', 'Timestamp'),
					width: '180px',
				},
				{
					key: 'object',
					label: t('openregister', 'Object ID'),
					class: 'cn-table-col--constrained',
				},
				{
					key: 'register',
					label: t('openregister', 'Register ID'),
					class: 'cn-table-col--constrained',
				},
				{
					key: 'userName',
					label: t('openregister', 'User'),
					class: 'cn-table-col--constrained',
				},
				{
					key: 'schema',
					label: t('openregister', 'Schema ID'),
					class: 'cn-table-col--constrained',
				},
				{ key: 'size', label: t('openregister', 'Size'), width: '100px' },
			]
		},

		/**
		 * Pagination state for CnIndexPage. The API paginates server-side, so the
		 * store values are passed straight through without slicing.
		 *
		 * @spec exclude UI plumbing — derived pagination view state.
		 * @return {object}
		 */
		paginationData() {
			const pagination = auditTrailStore.auditTrailPagination
			return {
				page: pagination.page || 1,
				pages: pagination.pages || 1,
				total: pagination.total || 0,
				limit: pagination.limit || 50,
			}
		},

		/**
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		paginatedAuditTrails() {
			// Ensure we always return a clean array
			try {
				return Array.isArray(auditTrailStore.auditTrailList)
					? auditTrailStore.auditTrailList
					: []
			} catch (error) {
				console.error('Error accessing auditTrailList:', error)
				return []
			}
		},
	},

	watch: {
		paginatedAuditTrails: {
			/**
			 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
			 */
			handler() {
				this.$nextTick(() => {
					this.updateCounts()
				})
			},

			deep: false,
		},
	},

	/**
	 * Lifecycle hook: load audit trails and subscribe to sidebar events on mount.
	 *
	 * @spec exclude UI plumbing — view-mount data fetch and event wiring
	 * @return {void}
	 */
	mounted() {
		// Initialize with safe defaults
		try {
			this.loadAuditTrails()
		} catch (error) {
			console.error('Error in mounted loadAuditTrails:', error)
		}

		// Listen for filter changes from sidebar
		eventBus.on('audit-trail-filters-changed', this.handleFiltersChanged)
		eventBus.on('audit-trail-export', this.handleExport)
		eventBus.on('audit-trail-refresh', this.refreshAuditTrails)

		// Emit counts to sidebar with delay to ensure store is ready
		this.$nextTick(() => {
			this.updateCounts()
		})
	},

	/**
	 * Lifecycle hook: unsubscribe from sidebar events before teardown.
	 *
	 * @spec exclude UI plumbing — event-listener teardown
	 * @return {void}
	 */
	beforeUnmount() {
		eventBus.off('audit-trail-filters-changed')
		eventBus.off('audit-trail-export')
		eventBus.off('audit-trail-refresh')
	},

	methods: {
		/**
		 * Load audit trails from API
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async loadAuditTrails() {
			try {
				await auditTrailStore.refreshAuditTrailList()
			} catch (error) {
				console.error('Error loading audit trails:', error)
				showError(t('openregister', 'Error loading audit trails'))
			}
		},

		/**
		 * Handle filter changes from sidebar
		 *
		 * @param {object} filters - Filter object from sidebar
		 * @return {void}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		handleFiltersChanged(filters) {
			auditTrailStore.setAuditTrailFilters(filters)
			// Refresh with new filters
			this.loadAuditTrails()
		},

		/**
		 * Handle export request from sidebar
		 *
		 * @param {object} options - Export options from sidebar
		 * @return {void}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		handleExport(options) {
			this.exportFilteredAuditTrails(options)
		},

		/**
		 * Reload the audit trail list from the store, driving the refresh spinner.
		 *
		 * @spec exclude UI plumbing — refresh button delegates to the store.
		 * @return {Promise<void>}
		 */
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await this.loadAuditTrails()
			} finally {
				this.isRefreshing = false
			}
		},

		/**
		 * Compute the CSS row class carrying the side accent. Keyed on the badge
		 * variant rather than the action name so the accent always matches the
		 * Action badge, and so dotted actions (file.renamed,
		 * referential_integrity.*) can't produce a two-token class name.
		 *
		 * @spec exclude UI plumbing — derived row-styling helper.
		 * @param {object} auditTrail - audit trail row
		 * @return {string}
		 */
		getRowClass(auditTrail) {
			const variant =
				this.actionColorMap[String(auditTrail.action || '').toLowerCase()]
			return variant ? `auditTrailRow--${variant}` : ''
		},

		/**
		 * Track the selected audit trail ids for mass actions.
		 *
		 * @spec exclude UI plumbing — row-selection state mutation.
		 * @param {Array} ids - selected audit trail ids
		 * @return {void}
		 */
		onSelect(ids) {
			this.selectedAuditTrails = ids
		},

		/**
		 * Label an audit trail in the mass-delete dialog. Entries have no title
		 * field, so the id is the only stable identifier to show.
		 *
		 * @spec exclude UI plumbing — display formatter for the mass-action dialog.
		 * @param {object} auditTrail - audit trail row
		 * @return {string}
		 */
		auditTrailName(auditTrail) {
			return t('openregister', 'Audit trail #{id}', { id: auditTrail.id })
		},

		/**
		 * View detailed information for an audit trail entry
		 *
		 * @param {object} auditTrail - Audit trail entry to view
		 * @return {void}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		viewDetails(auditTrail) {
			// Set the audit trail item in the store
			auditTrailStore.setAuditTrailItem(auditTrail)
			// Open the details modal
			navigationStore.setDialog('auditTrailDetails')
		},

		/**
		 * View changes information for an audit trail entry
		 *
		 * @param {object} auditTrail - Audit trail entry with changes
		 * @return {void}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		viewChanges(auditTrail) {
			// Set the audit trail item and open the specialized changes modal
			auditTrailStore.setAuditTrailItem(auditTrail)
			navigationStore.setDialog('auditTrailChanges')
		},

		/**
		 * Copy audit trail data to clipboard
		 *
		 * @param {object} auditTrail - Audit trail entry to copy
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async copyData(auditTrail) {
			try {
				const data = JSON.stringify(auditTrail, null, 2)
				await navigator.clipboard.writeText(data)

				// Set successful copy state
				this.copyStates[auditTrail.id] = true

				// Show success notification with enhanced styling
				showSuccess(
					t('openregister', 'Audit trail data copied to clipboard'),
				)

				// Reset copy state after 2 seconds
				setTimeout(() => {
					this.copyStates[auditTrail.id] = false
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
					this.copyStates[auditTrail.id] = true

					showSuccess(
						t('openregister', 'Audit trail data copied to clipboard'),
					)

					// Reset copy state after 2 seconds
					setTimeout(() => {
						this.copyStates[auditTrail.id] = false
					}, 2000)
				} catch (fallbackError) {
					console.error('Fallback copy failed:', fallbackError)
					showError(t('openregister', 'Failed to copy data to clipboard'))
				}
			}
		},

		/**
		 * Export audit trails with current filters
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		exportAuditTrails() {
			this.exportFilteredAuditTrails({ format: 'csv', includeChanges: true })
		},

		/**
		 * Export filtered audit trails with specified options
		 *
		 * @param {object} options - Export options
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async exportFilteredAuditTrails(options) {
			try {
				// Build query parameters
				const params = new URLSearchParams()
				params.append('format', options.format || 'csv')
				params.append('includeChanges', options.includeChanges || false)
				params.append('includeMetadata', options.includeMetadata || false)

				// Add current filters. The state field is auditTrailFilters — reading
				// `filters` silently dropped every active filter, including the
				// dateFrom/dateTo range the compliance export requires.
				if (auditTrailStore.auditTrailFilters) {
					Object.entries(auditTrailStore.auditTrailFilters).forEach(
						([key, value]) => {
							if (
								value !== null
								&& value !== undefined
								&& value !== ''
							) {
								params.append(key, value)
							}
						},
					)
				}

				// Make the API request
				const response = await fetch(
					`/index.php/apps/openregister/api/audit-trails/export?${params.toString()}`,
				)
				const result = await response.json()

				if (result.success && result.data) {
					// Create and trigger download
					const blob = new Blob([result.data.content], {
						type: result.data.contentType,
					})
					const url = window.URL.createObjectURL(blob)
					const a = document.createElement('a')
					a.href = url
					a.download = result.data.filename
					document.body.appendChild(a)
					a.click()
					window.URL.revokeObjectURL(url)
					document.body.removeChild(a)

					showSuccess(t('openregister', 'Export completed successfully'))
				} else {
					throw new Error(result.error || 'Export failed')
				}
			} catch (error) {
				console.error('Error exporting audit trails:', error)
				showError(
					t('openregister', 'Export failed: {error}', {
						error: error.message,
					}),
				)
			}
		},

		/**
		 * Delete a single audit trail using the new modal
		 *
		 * @param {object} auditTrail - Audit trail to delete
		 * @return {void}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		deleteAuditTrail(auditTrail) {
			// Set the audit trail item in the store
			auditTrailStore.setAuditTrailItem(auditTrail)
			// Open the delete modal
			navigationStore.setDialog('deleteAuditTrail')
		},

		/**
		 * Refresh audit trails list
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async refreshAuditTrails() {
			await this.loadAuditTrails()
		},

		/**
		 * Update counts for sidebar
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		updateCounts() {
			try {
				const count = Array.isArray(auditTrailStore.auditTrailList)
					? auditTrailStore.auditTrailList.length
					: 0
				eventBus.emit('audit-trail-filtered-count', count)
			} catch (error) {
				console.error('Error updating counts:', error)
				eventBus.emit('audit-trail-filtered-count', 0)
			}
		},

		/**
		 * Handle page change from pagination component
		 *
		 * @param {number} page - The page number to change to
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
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
		 *
		 * @param {number} pageSize - The new page size
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
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
		 *
		 * @param {object} auditTrail - The audit trail item
		 * @return {boolean} Whether the audit trail has changes
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
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

		/**
		 * Delete the audit trails confirmed in the mass-delete dialog. The dialog
		 * lets the user drop individual rows, so the emitted ids win over the
		 * current selection.
		 *
		 * @param {Array} ids - Audit trail ids confirmed for deletion
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
		 */
		async handleMassDelete(ids) {
			try {
				// Make the API request to delete selected audit trails
				const response = await fetch(
					'/index.php/apps/openregister/api/audit-trails/bulk-delete',
					{
						method: 'DELETE',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({ ids }),
					},
				)

				const result = await response.json()

				if (!result.success) {
					throw new Error(result.error || 'Deletion failed')
				}

				this.$refs.indexPage.setMassDeleteResult({ success: true })
				// Clear selection
				this.selectedAuditTrails = []
				// Refresh the list
				await this.loadAuditTrails()
			} catch (error) {
				console.error('Error deleting audit trails:', error)
				this.$refs.indexPage.setMassDeleteResult({
					success: false,
					error: error.message,
				})
			}
		},
	},
}
</script>

<style scoped>
/* Row accent, keyed on the CnStatusBadge variant that getRowClass resolves from
   actionColorMap — so the accent colour is always the Action badge's colour.

   Drawn with an inset box-shadow, never border-left: a border adds layout width
   and shifts the row's cell content sideways, while box-shadow paints inside the
   box.

   Skipped on a selected row so the library's .cn-table-row--selected accent
   wins — scoping adds a [data-v-*] attribute, which would otherwise outweigh
   the library's single-class rule. */
:deep(.auditTrailRow--success:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-success);
}

:deep(.auditTrailRow--warning:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-warning);
}

:deep(.auditTrailRow--error:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-error);
}

:deep(.auditTrailRow--info:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-info);
}

.copySuccessIcon {
	color: var(--color-success) !important;
}

:deep(.copySuccessIcon) {
	animation: copySuccess 0.3s ease-in-out;
}

@keyframes copySuccess {
	0% {
		transform: scale(1);
	}
	50% {
		transform: scale(1.2);
	}
	100% {
		transform: scale(1);
	}
}

@media (prefers-reduced-motion: reduce) {
	:deep(.copySuccessIcon) {
		animation: none;
	}
}
</style>
