<script setup>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import formatBytes from '../../services/formatBytes.js'
import {
	deletedStore,
	navigationStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('openregister', 'Soft Deleted Items') }}
				</h1>
				<p>
					{{
						t(
							'openregister',
							'Manage and restore soft deleted items from your registers',
						)
					}}
				</p>
			</div>

			<!-- Actions Bar -->
			<ListActionsBar
				:summary="
					t('openregister', 'Showing {showing} of {total} deleted items', {
						showing: paginatedItems.length,
						total: deletedStore.deletedPagination.total,
					})
				"
				:selectedCount="selectedItems.length"
				:actions="[refreshAction(refreshItems)]">
				<NcActions
					:forceName="true"
					:disabled="selectedItems.length === 0"
					:title="
						selectedItems.length === 0
							? 'Select one or more objects to use mass actions'
							: `Mass actions (${selectedItems.length} selected)`
					"
					:menuName="`Mass Actions (${selectedItems.length})`">
					<template #icon>
						<FormatListChecks :size="20" />
					</template>
					<NcActionButton
						:disabled="selectedItems.length === 0"
						closeAfterClick
						@click="bulkRestore">
						<template #icon>
							<Restore :size="20" />
						</template>
						Restore
					</NcActionButton>
					<NcActionButton
						:disabled="selectedItems.length === 0"
						closeAfterClick
						@click="bulkDelete">
						<template #icon>
							<Delete :size="20" />
						</template>
						Purge
					</NcActionButton>
				</NcActions>
			</ListActionsBar>

			<!-- Items Table -->
			<NcEmptyContent
				v-if="deletedStore.deletedLoading || !filteredItems.length"
				:name="emptyContentName"
				:description="emptyContentDescription">
				<template #icon>
					<NcLoadingIcon v-if="deletedStore.deletedLoading" />
					<DeleteEmpty v-else />
				</template>
			</NcEmptyContent>

			<CnDataTable
				v-else
				class="itemsTable"
				:columns="columns"
				:rows="paginatedItems"
				selectable
				:selectedIds="selectedItems"
				:selectAllLabel="t('openregister', 'Select All')"
				@select="selectedItems = $event">
				<template #column-title="{ row: item }">
					<div class="titleContent">
						<strong>{{ getItemTitle(item) }}</strong>
						<span
							v-if="getItemDescription(item)"
							class="textDescription textEllipsis"
							>{{ getItemDescription(item) }}</span
						>
					</div>
				</template>
				<template #column-register="{ row: item }">
					{{ getRegisterName(item['@self']?.register) }}
				</template>
				<template #column-schema="{ row: item }">
					{{ getSchemaName(item['@self']?.schema) }}
				</template>
				<template #column-deleted="{ row: item }">
					{{
						item['@self']?.deleted?.deleted
							? formatPurgeDate(item['@self'].deleted.deleted)
							: t('openregister', 'Unknown')
					}}
				</template>
				<template #column-deletedBy="{ row: item }">
					{{ item['@self']?.deleted?.deletedBy || t('openregister', 'Unknown') }}
				</template>
				<template #column-destroyableFrom="{ row: item }">
					{{
						destroyableFrom(item)
							? formatPurgeDate(destroyableFrom(item))
							: t('openregister', 'No purge date set')
					}}
				</template>
				<template #column-daysLeft="{ row: item }">
					{{ daysLeftLabel(item) }}
				</template>
				<template #actions-header>
					{{ t('openregister', 'Actions') }}
				</template>
				<template #row-actions="{ row: item }">
					<ActionMenu :actions="itemActions(item)" />
				</template>
			</CnDataTable>

			<!-- Pagination -->
			<PaginationComponent
				:currentPage="currentPage"
				:totalPages="totalPages"
				:totalItems="deletedStore.deletedPagination.total"
				:currentPageSize="deletedStore.deletedPagination.limit || 20"
				:minItemsToShow="10"
				@pageChanged="onPageChanged"
				@pageSizeChanged="onPageSizeChanged" />
		</div>
	</NcAppContent>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import {
	NcActionButton,
	NcActions,
	NcAppContent,
	NcEmptyContent,
	NcLoadingIcon,
} from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DeleteEmpty from 'vue-material-design-icons/DeleteEmpty.vue'
import FormatListChecks from 'vue-material-design-icons/FormatListChecks.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import PaginationComponent from '../../components/PaginationComponent.vue'
import ActionMenu from '../../components/shared/ActionMenu.vue'
import ListActionsBar from '../../components/shared/ListActionsBar.vue'
import { ensureRegisterAndSchemaLists } from '../../composables/registerSchemaLists.js'
import eventBus from '../../eventBus.js'
import { refreshAction } from '../../services/menuActions.js'
import { storePaging } from '../storePaging.js'

export default {
	name: 'DeletedIndex',
	components: {
		ActionMenu,
		CnDataTable,
		ListActionsBar,
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		DeleteEmpty,
		Restore,
		Delete,
		FormatListChecks,
		PaginationComponent,
	},

	mixins: [
		storePaging({
			fetch: (options) => deletedStore.fetchDeleted(options),
			limit: () => deletedStore.deletedPagination.limit,
			selection: 'selectedItems',
			logPageError: false,
		}),
	],

	data() {
		return {
			selectedItems: [],
		}
	},

	computed: {
		/**
		 * The trash table columns; the row menu adds the "Actions" column.
		 *
		 * @spec exclude list-view table layout
		 * @return {Array<object>} The CnDataTable columns
		 */
		columns() {
			return [
				{ key: 'title', label: t('openregister', 'Title'), cellClass: 'cn-cell--wrap' },
				{ key: 'register', label: t('openregister', 'Register'), class: 'tableColumnConstrained' },
				{ key: 'schema', label: t('openregister', 'Schema'), class: 'tableColumnConstrained' },
				{ key: 'deleted', label: t('openregister', 'Deleted Date') },
				{ key: 'deletedBy', label: t('openregister', 'Deleted By') },
				{ key: 'destroyableFrom', label: t('openregister', 'Destroyable from') },
				{ key: 'daysLeft', label: t('openregister', 'Days left'), class: 'tableColumnConstrained' },
			]
		},

		/**
		 * @spec exclude list-view store passthrough for filtered deleted items (computed; archivering-vernietiging contract)
		 */
		filteredItems() {
			// Items are already filtered by the store based on sidebar filters
			return deletedStore.deletedList || []
		},

		/**
		 * @spec exclude list-view store passthrough for paginated deleted items (computed)
		 */
		paginatedItems() {
			// Items are already paginated by the store
			return this.filteredItems
		},

		/**
		 * @spec exclude list-view pagination total-pages helper (computed)
		 */
		totalPages() {
			return deletedStore.deletedPagination.pages || 1
		},

		/**
		 * @spec exclude list-view pagination current-page helper (computed)
		 */
		currentPage() {
			return deletedStore.deletedPagination.page || 1
		},



		/**
		 * @spec exclude list-view empty-state title text helper (computed)
		 */
		emptyContentName() {
			if (deletedStore.deletedLoading) {
				return t('openregister', 'Loading deleted items...')
			} else if (!this.filteredItems.length) {
				return t('openregister', 'No deleted items found')
			}
			return ''
		},

		/**
		 * @spec exclude list-view empty-state description text helper (computed)
		 */
		emptyContentDescription() {
			if (deletedStore.deletedLoading) {
				return t(
					'openregister',
					'Please wait while we fetch your deleted items.',
				)
			} else if (!this.filteredItems.length) {
				return t(
					'openregister',
					'There are no deleted items matching your current filters.',
				)
			}
			return ''
		},
	},

	watch: {
		/**
		 * @spec exclude list-view watcher; re-emits counts when the selection changes
		 */
		selectedItems() {
			this.updateCounts()
		},

		/**
		 * @spec exclude list-view watcher re-emitting counts when filtered items change
		 */
		filteredItems() {
			this.updateCounts()
		},
	},

	/**
	 * @spec exclude list-view lifecycle; loads data and registers sidebar/modal event listeners on mount
	 */
	async mounted() {
		// Load initial data
		await this.loadItems()

		// Update counts
		this.updateCounts()

		// Listen for filter changes from sidebar
		eventBus.on('deleted-filters-changed', this.handleFiltersChanged)
		eventBus.on('deleted-bulk-restore', this.bulkRestore)
		eventBus.on('deleted-bulk-delete', this.bulkDelete)
		// Pre-existing typo, surfaced by the move off `$root.$on`: the method is
		// `exportFilteredItems`. `this.exportFiltered` is undefined, so this
		// channel had been subscribed to nothing since it was written. (The
		// target is itself a documented stub, so no behaviour changes — but the
		// wiring now says what it means instead of silently pointing at
		// nothing.)
		eventBus.on('deleted-export-filtered', this.exportFilteredItems)

		// Listen for deletion events from modals
		eventBus.on('deleted-objects-permanently-deleted', this.handleObjectsDeleted)
		eventBus.on('deleted-objects-restored', this.handleObjectsRestored)
	},

	/**
	 * @spec exclude list-view lifecycle; tears down sidebar/modal event listeners on destroy
	 */
	beforeUnmount() {
		eventBus.off('deleted-filters-changed')
		eventBus.off('deleted-bulk-restore')
		eventBus.off('deleted-bulk-delete')
		eventBus.off('deleted-export-filtered')
		eventBus.off('deleted-objects-permanently-deleted')
		eventBus.off('deleted-objects-restored')
	},

	methods: {
		refreshAction,

		/**
		 * The row menu of a deleted item.
		 *
		 * @spec exclude Client-side menu composition.
		 * @param {object} item The item
		 * @return {Array<object>} The ActionMenu entries
		 */
		itemActions(item) {
			return [
				{
					label: t('openregister', 'Restore'),
					icon: Restore,
					run: () => this.restoreItem(item),
				},
				{
					label: t('openregister', 'Permanently Delete'),
					icon: Delete,
					run: () => this.permanentlyDelete(item),
				},
			]
		},

		/**
		 * Load deleted items from store
		 *
		 * @spec exclude list-view store fetch plumbing (archivering-vernietiging contract)
		 * @return {Promise<void>}
		 */
		async loadItems() {
			try {
				await deletedStore.fetchDeleted()

				// Load register and schema data if not already loaded
				await ensureRegisterAndSchemaLists()
			} catch (error) {
				console.error('Error loading deleted items:', error)
			}
		},

		/**
		 * Handle filter changes from sidebar
		 *
		 * @spec exclude list-view filter-change handler; converts and re-fetches via store
		 * @param {object} filters - Filter object from sidebar
		 * @return {void}
		 */
		async handleFiltersChanged(filters) {
			// Convert sidebar filters to API filters using @self.deleted notation
			const apiFilters = this.convertFiltersToApiFormat(filters)

			deletedStore.setDeletedFilters(apiFilters)

			// Reset pagination and fetch new data
			try {
				await deletedStore.fetchDeleted({
					page: 1,
					filters: apiFilters,
				})

				// Clear selection when filters change
				this.selectedItems = []
			} catch (error) {
				console.error('Error applying filters:', error)
			}
		},

		/**
		 * Convert sidebar filters to API format using @self.deleted notation
		 *
		 * @spec exclude list-view filter-shape mapping helper for display/query only
		 * @param {object} filters - Sidebar filters
		 * @return {object} API filters
		 */
		convertFiltersToApiFormat(filters) {
			const apiFilters = {}

			if (filters.register) {
				apiFilters['@self.register'] = filters.register
			}
			if (filters.schema) {
				apiFilters['@self.schema'] = filters.schema
			}
			if (filters.deletedBy) {
				apiFilters['@self.deleted.deletedBy'] = filters.deletedBy
			}
			if (filters.dateFrom) {
				apiFilters['@self.deleted.deleted'] = { gte: filters.dateFrom }
			}
			if (filters.dateTo) {
				if (apiFilters['@self.deleted.deleted']) {
					apiFilters['@self.deleted.deleted'].lte = filters.dateTo
				} else {
					apiFilters['@self.deleted.deleted'] = { lte: filters.dateTo }
				}
			}

			return apiFilters
		},

		/**
		 * Get item title from object data
		 *
		 * @spec exclude list-view title-resolution display helper
		 * @param {object} item - The deleted item
		 * @return {string} The item title
		 */
		getItemTitle(item) {
			// Try various title fields, fallback to ID without "Object" prefix
			return (
				item.title
				|| item.fileName
				|| item.name
				|| item.object?.title
				|| item.object?.name
				|| item.id
			)
		},

		/**
		 * Get item description from object data
		 *
		 * @param {object} item - The deleted item
		 * @return {string} The item description
		 */
		getItemDescription(item) {
			return (
				item.description
				|| item.object?.description
				|| item.object?.summary
				|| null
			)
		},

		/**
		 * Get register name by ID
		 *
		 * @spec exclude list-view id-to-name lookup display helper
		 * @param {string|number} registerId - The register ID
		 * @return {string} The register name
		 */
		getRegisterName(registerId) {
			if (!registerId) return t('openregister', 'Unknown Register')

			const register = registerStore.registerList.find(
				(r) => r.id === parseInt(registerId),
			)
			return register?.title || `Register ${registerId}`
		},

		/**
		 * Get schema name by ID
		 *
		 * @spec exclude list-view id-to-name lookup display helper
		 * @param {string|number} schemaId - The schema ID
		 * @return {string} The schema name
		 */
		getSchemaName(schemaId) {
			if (!schemaId) return t('openregister', 'Unknown Schema')

			const schema = schemaStore.schemaList.find(
				(s) => s.id === parseInt(schemaId),
			)
			return schema?.title || `Schema ${schemaId}`
		},



		/**
		 * Restore selected items using dialog
		 *
		 * @spec exclude list-view bulk-action dialog-open plumbing (archivering-vernietiging contract)
		 * @return {void}
		 */
		bulkRestore() {
			if (this.selectedItems.length === 0) return

			// Get selected objects data
			const selectedObjects = this.paginatedItems.filter((item) =>
				this.selectedItems.includes(item.id),
			)

			// Set data in deletedStore and open dialog
			deletedStore.setSelectedForBulkAction(selectedObjects)
			navigationStore.setDialog('restoreMultiple')
		},

		/**
		 * Permanently delete selected items using dialog
		 *
		 * @spec exclude list-view bulk-action dialog-open plumbing (archivering-vernietiging contract)
		 * @return {void}
		 */
		bulkDelete() {
			if (this.selectedItems.length === 0) return

			// Get selected objects data
			const selectedObjects = this.paginatedItems.filter((item) =>
				this.selectedItems.includes(item.id),
			)

			// Set data in deletedStore and open dialog
			deletedStore.setSelectedForBulkAction(selectedObjects)
			navigationStore.setDialog('permanentlyDeleteMultiple')
		},

		/**
		 * Restore individual item using dialog
		 *
		 * @spec exclude list-view row-action dialog-open plumbing (archivering-vernietiging contract)
		 * @param {object} item - Item to restore
		 * @return {void}
		 */
		restoreItem(item) {
			// Set transfer data as array with single item and open dialog
			deletedStore.setSelectedForBulkAction([item])
			navigationStore.setDialog('restoreMultiple')
		},

		/**
		 * Permanently delete individual item using dialog
		 *
		 * @spec exclude list-view row-action dialog-open plumbing (archivering-vernietiging contract)
		 * @param {object} item - Item to delete
		 * @return {void}
		 */
		permanentlyDelete(item) {
			// Set transfer data as array with single item and open dialog
			deletedStore.setSelectedForBulkAction([item])
			navigationStore.setDialog('permanentlyDeleteMultiple')
		},

		/**
		 * Handle multiple objects deletion event
		 *
		 * @spec exclude list-view event handler; clears selection and reloads after modal delete
		 * @param {Array<string>} objectIds - IDs of deleted objects
		 * @return {Promise<void>}
		 */
		async handleObjectsDeleted(objectIds) {
			await this.deselectAndReload(objectIds)
		},

		/**
		 * Handle multiple objects restoration event
		 *
		 * @spec exclude list-view event handler; clears selection and reloads after modal restore
		 * @param {Array<string>} objectIds - IDs of restored objects
		 * @return {Promise<void>}
		 */
		async handleObjectsRestored(objectIds) {
			await this.deselectAndReload(objectIds)
		},

		/**
		 * Drop these objects from the selection, then reload the list.
		 *
		 * @spec exclude list-view event handler; clears selection and reloads after a bulk modal action
		 * @param {Array<string>} objectIds - IDs of the objects the modal handled
		 * @return {Promise<void>}
		 */
		async deselectAndReload(objectIds) {
			// Remove from selection if they were selected
			objectIds.forEach((id) => {
				const index = this.selectedItems.indexOf(id)
				if (index > -1) {
					this.selectedItems.splice(index, 1)
				}
			})

			// Refresh the list
			await this.loadItems()
		},

		/**
		 * Export filtered items with specified options
		 *
		 * @spec exclude list-view export stub (not yet implemented)
		 * @param {object} options - Export options
		 * @param {object} _options
		 * @return {void}
		 */
		exportFilteredItems(_options) {
			// TODO: Implement export functionality for deleted items
		},

		/**
		 * Refresh items list
		 *
		 * @spec exclude list-view manual refresh plumbing
		 * @return {Promise<void>}
		 */
		async refreshItems() {
			await this.loadItems()
			this.selectedItems = []
		},

		/**
		 * Update counts for sidebar
		 *
		 * @spec exclude list-view count-emit plumbing for the sidebar
		 * @return {void}
		 */
		updateCounts() {
			eventBus.emit('deleted-selection-count', this.selectedItems.length)
			eventBus.emit('deleted-filtered-count', this.filteredItems.length)
		},



		/**
		 * Format purge date in ISO format yyyy:mm:dd hh:mm
		 *
		 * @spec exclude list-view date-formatting display helper
		 * @param {string} timestamp - The purge date timestamp
		 * @return {string} Formatted purge date
		 */
		formatPurgeDate(timestamp) {
			const date = new Date(timestamp)
			const year = date.getFullYear()
			const month = String(date.getMonth() + 1).padStart(2, '0')
			const day = String(date.getDate()).padStart(2, '0')
			const hours = String(date.getHours()).padStart(2, '0')
			const minutes = String(date.getMinutes()).padStart(2, '0')

			return `${year}:${month}:${day} ${hours}:${minutes}`
		},

		/**
		 * The date this object becomes destroyable, from the published window.
		 *
		 * Falls back to the raw purge date so a row deleted before the window
		 * was published still shows the only date it has.
		 *
		 * @spec openspec/specs/deletion-audit-trail/spec.md
		 * @param {object} item - The trash row.
		 * @return {string|null} The ISO date, or null when the row carries none.
		 */
		destroyableFrom(item) {
			return (
				item?.deletionWindow?.destroyableFrom
				|| item?.['@self']?.deleted?.destroyableFrom
				|| item?.['@self']?.deleted?.purgeDate
				|| null
			)
		},

		/**
		 * How long the caseworker has, in words.
		 *
		 * A window that only exists as a date makes every reader do the
		 * subtraction, and the answer they most need is the one they are
		 * worst at computing under pressure.
		 *
		 * @spec openspec/specs/deletion-audit-trail/spec.md
		 * @param {object} item - The trash row.
		 * @return {string} The label to render.
		 */
		daysLeftLabel(item) {
			const days = item?.deletionWindow?.daysRemaining
			if (days === undefined || days === null) {
				return t('openregister', 'Unknown')
			}

			if (days === 0) {
				return t('openregister', 'Destroyable now')
			}

			return n('openregister', '%n day left', '%n days left', days)
		},

		formatBytes,
	},
}
</script>

<style scoped>
/* Actions button styling */
.actionsButton > div > button {
	margin-top: 0px !important;
	margin-right: 0px !important;
	padding-right: 0px !important;
}
</style>
