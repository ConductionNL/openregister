<script setup>
import { deletedStore, registerStore, schemaStore, navigationStore } from '../../store/store.js'
import formatBytes from '../../services/formatBytes.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('openregister', 'Soft Deleted Items') }}
				</h1>
				<p>{{ t('openregister', 'Manage and restore soft deleted items from your registers') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span class="viewTotalCount">
						{{ t('openregister', 'Showing {showing} of {total} deleted items', { showing: paginatedItems.length, total: deletedStore.deletedPagination.total }) }}
					</span>
					<span v-if="selectedItems.length > 0" class="viewIndicator">
						({{ t('openregister', '{count} selected', { count: selectedItems.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<!-- Mass Actions Dropdown -->
					<NcActions
						:force-name="true"
						:disabled="selectedItems.length === 0"
						:title="selectedItems.length === 0 ? 'Select one or more objects to use mass actions' : `Mass actions (${selectedItems.length} selected)`"
						:menu-name="`Mass Actions (${selectedItems.length})`">
						<template #icon>
							<FormatListChecks :size="20" />
						</template>
						<NcActionButton
							:disabled="selectedItems.length === 0"
							close-after-click
							@click="bulkRestore">
							<template #icon>
								<Restore :size="20" />
							</template>
							Restore
						</NcActionButton>
						<NcActionButton
							:disabled="selectedItems.length === 0"
							close-after-click
							@click="bulkDelete">
							<template #icon>
								<Delete :size="20" />
							</template>
							Purge
						</NcActionButton>
					</NcActions>

					<!-- Regular Actions -->
					<NcActions
						:force-name="true"
						:inline="1"
						menu-name="Actions">
						<NcActionButton
							close-after-click
							@click="refreshItems">
							<template #icon>
								<Refresh :size="20" />
							</template>
							{{ t('openregister', 'Refresh') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Items Table -->
			<NcEmptyContent v-if="deletedStore.deletedLoading || !filteredItems.length"
				:name="emptyContentName"
				:description="emptyContentDescription">
				<template #icon>
					<NcLoadingIcon v-if="deletedStore.deletedLoading" />
					<DeleteEmpty v-else />
				</template>
			</NcEmptyContent>

			<div v-else class="viewTableContainer">
				<table class="viewTable itemsTable">
					<thead>
						<tr>
							<th class="tableColumnCheckbox">
								<NcCheckboxRadioSwitch
									:checked="allSelected"
									:indeterminate="someSelected"
									@update:checked="toggleSelectAll" />
							</th>
							<th>{{ t('openregister', 'Title') }}</th>
							<th class="tableColumnConstrained">
								{{ t('openregister', 'Register') }}
							</th>
							<th class="tableColumnConstrained">
								{{ t('openregister', 'Schema') }}
							</th>
							<th>{{ t('openregister', 'Deleted Date') }}</th>
							<th>{{ t('openregister', 'Deleted By') }}</th>
							<th>{{ t('openregister', 'Purge Date') }}</th>
							<th class="tableColumnActions">
								{{ t('openregister', 'Actions') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="item in paginatedItems"
							:key="item.id"
							class="viewTableRow itemRow table-row-selectable"
							:class="{ 'viewTableRowSelected table-row-selected': selectedItems.includes(item.id) }"
							@click="handleRowClick(item.id, $event)">
							<td class="tableColumnCheckbox">
								<NcCheckboxRadioSwitch
									:checked="selectedItems.includes(item.id)"
									@update:checked="(checked) => toggleItemSelection(item.id, checked)" />
							</td>
							<td class="tableColumnTitle">
								<div class="titleContent">
									<strong>{{ getItemTitle(item) }}</strong>
									<span v-if="getItemDescription(item)" class="textDescription textEllipsis">{{ getItemDescription(item) }}</span>
								</div>
							</td>
							<td class="tableColumnConstrained">
								{{ getRegisterName(item['@self']?.register) }}
							</td>
							<td class="tableColumnConstrained">
								{{ getSchemaName(item['@self']?.schema) }}
							</td>
							<td>
								<span v-if="item['@self']?.deleted?.deleted">{{ formatPurgeDate(item['@self'].deleted.deleted) }}</span>
								<span v-else>{{ t('openregister', 'Unknown') }}</span>
							</td>
							<td>{{ item['@self']?.deleted?.deletedBy || t('openregister', 'Unknown') }}</td>
							<td>
								<span v-if="item['@self']?.deleted?.purgeDate">{{ formatPurgeDate(item['@self'].deleted.purgeDate) }}</span>
								<span v-else>{{ t('openregister', 'No purge date set') }}</span>
							</td>
							<td class="tableColumnActions">
								<NcActions>
									<NcActionButton close-after-click @click="restoreItem(item)">
										<template #icon>
											<Restore :size="20" />
										</template>
										{{ t('openregister', 'Restore') }}
									</NcActionButton>
									<NcActionButton close-after-click @click="permanentlyDelete(item)">
										<template #icon>
											<Delete :size="20" />
										</template>
										{{ t('openregister', 'Permanently Delete') }}
									</NcActionButton>
								</NcActions>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Pagination -->
			<PaginationComponent
				:current-page="currentPage"
				:total-pages="totalPages"
				:total-items="deletedStore.deletedPagination.total"
				:current-page-size="deletedStore.deletedPagination.limit || 20"
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
	NcCheckboxRadioSwitch,
	NcActions,
	NcActionButton,
} from '@nextcloud/vue'
import DeleteEmpty from 'vue-material-design-icons/DeleteEmpty.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import FormatListChecks from 'vue-material-design-icons/FormatListChecks.vue'

import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'DeletedIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcActions,
		NcActionButton,
		DeleteEmpty,
		Restore,
		Delete,
		Refresh,
		FormatListChecks,
		PaginationComponent,
	},
	data() {
		return {
			selectedItems: [],
		}
	},
	computed: {
		filteredItems() {
			// Items are already filtered by the store based on sidebar filters
			return deletedStore.deletedList || []
		},
		paginatedItems() {
			// Items are already paginated by the store
			return this.filteredItems
		},
		totalPages() {
			return deletedStore.deletedPagination.pages || 1
		},
		currentPage() {
			return deletedStore.deletedPagination.page || 1
		},
		allSelected() {
			return this.paginatedItems.length > 0 && this.paginatedItems.every(item => this.selectedItems.includes(item.id))
		},
		someSelected() {
			return this.selectedItems.length > 0 && !this.allSelected
		},
		emptyContentName() {
			if (deletedStore.deletedLoading) {
				return t('openregister', 'Loading deleted items...')
			} else if (!this.filteredItems.length) {
				return t('openregister', 'No deleted items found')
			}
			return ''
		},
		emptyContentDescription() {
			if (deletedStore.deletedLoading) {
				return t('openregister', 'Please wait while we fetch your deleted items.')
			} else if (!this.filteredItems.length) {
				return t('openregister', 'There are no deleted items matching your current filters.')
			}
			return ''
		},
	},
	watch: {
		selectedItems() {
			this.updateCounts()
		},
		filteredItems() {
			this.updateCounts()
		},
	},
	async mounted() {
		// Load initial data
		await this.loadItems()

		// Update counts
		this.updateCounts()

		// Listen for filter changes from sidebar
		this.$root.$on('deleted-filters-changed', this.handleFiltersChanged)
		this.$root.$on('deleted-bulk-restore', this.bulkRestore)
		this.$root.$on('deleted-bulk-delete', this.bulkDelete)
		this.$root.$on('deleted-export-filtered', this.exportFiltered)

		// Listen for deletion events from modals
		this.$root.$on('deleted-objects-permanently-deleted', this.handleObjectsDeleted)
		this.$root.$on('deleted-objects-restored', this.handleObjectsRestored)
	},
	beforeDestroy() {
		this.$root.$off('deleted-filters-changed')
		this.$root.$off('deleted-bulk-restore')
		this.$root.$off('deleted-bulk-delete')
		this.$root.$off('deleted-export-filtered')
		this.$root.$off('deleted-objects-permanently-deleted')
		this.$root.$off('deleted-objects-restored')
	},
	methods: {
		/**
		 * Load deleted items from store
		 * @return {Promise<void>}
		 */
		async loadItems() {
			try {
				await deletedStore.fetchDeleted()

				// Load register and schema data if not already loaded
				if (!registerStore.registerList.length) {
					await registerStore.refreshRegisterList()
				}
				if (!schemaStore.schemaList.length) {
					await schemaStore.refreshSchemaList()
				}
			} catch (error) {
				console.error('Error loading deleted items:', error)
			}
		},
		/**
		 * Handle filter changes from sidebar
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
		 * @param {object} item - The deleted item
		 * @return {string} The item title
		 */
		getItemTitle(item) {
			// Try various title fields, fallback to ID without "Object" prefix
			return item.title || item.fileName || item.name || item.object?.title || item.object?.name || item.id
		},
		/**
		 * Get item description from object data
		 * @param {object} item - The deleted item
		 * @return {string} The item description
		 */
		getItemDescription(item) {
			return item.description || item.object?.description || item.object?.summary || null
		},
		/**
		 * Get register name by ID
		 * @param {string|number} registerId - The register ID
		 * @return {string} The register name
		 */
		getRegisterName(registerId) {
			if (!registerId) return t('openregister', 'Unknown Register')

			const register = registerStore.registerList.find(r => r.id === parseInt(registerId))
			return register?.title || `Register ${registerId}`
		},
		/**
		 * Get schema name by ID
		 * @param {string|number} schemaId - The schema ID
		 * @return {string} The schema name
		 */
		getSchemaName(schemaId) {
			if (!schemaId) return t('openregister', 'Unknown Schema')

			const schema = schemaStore.schemaList.find(s => s.id === parseInt(schemaId))
			return schema?.title || `Schema ${schemaId}`
		},
		/**
		 * Toggle selection for all items on current page
		 * @param {boolean} checked - Whether to select or deselect all
		 * @return {void}
		 */
		toggleSelectAll(checked) {
			if (checked) {
				this.paginatedItems.forEach(item => {
					if (!this.selectedItems.includes(item.id)) {
						this.selectedItems.push(item.id)
					}
				})
			} else {
				this.paginatedItems.forEach(item => {
					const index = this.selectedItems.indexOf(item.id)
					if (index > -1) {
						this.selectedItems.splice(index, 1)
					}
				})
			}
		},
		/**
		 * Toggle selection for individual item
		 * @param {string} itemId - ID of the item to toggle
		 * @param {boolean} checked - Whether to select or deselect
		 * @return {void}
		 */
		toggleItemSelection(itemId, checked) {
			if (checked) {
				if (!this.selectedItems.includes(itemId)) {
					this.selectedItems.push(itemId)
				}
			} else {
				const index = this.selectedItems.indexOf(itemId)
				if (index > -1) {
					this.selectedItems.splice(index, 1)
				}
			}
		},
		/**
		 * Restore selected items using dialog
		 * @return {void}
		 */
		bulkRestore() {
			if (this.selectedItems.length === 0) return

			// Get selected objects data
			const selectedObjects = this.paginatedItems.filter(item => this.selectedItems.includes(item.id))

			// Set data in deletedStore and open dialog
			deletedStore.setSelectedForBulkAction(selectedObjects)
			navigationStore.setDialog('restoreMultiple')
		},
		/**
		 * Permanently delete selected items using dialog
		 * @return {void}
		 */
		bulkDelete() {
			if (this.selectedItems.length === 0) return

			// Get selected objects data
			const selectedObjects = this.paginatedItems.filter(item => this.selectedItems.includes(item.id))

			// Set data in deletedStore and open dialog
			deletedStore.setSelectedForBulkAction(selectedObjects)
			navigationStore.setDialog('permanentlyDeleteMultiple')
		},
		/**
		 * Restore individual item using dialog
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
		 * @param {Array<string>} objectIds - IDs of deleted objects
		 * @return {Promise<void>}
		 */
		async handleObjectsDeleted(objectIds) {
			// Remove from selection if they were selected
			objectIds.forEach(id => {
				const index = this.selectedItems.indexOf(id)
				if (index > -1) {
					this.selectedItems.splice(index, 1)
				}
			})

			// Refresh the list
			await this.loadItems()
		},
		/**
		 * Handle multiple objects restoration event
		 * @param {Array<string>} objectIds - IDs of restored objects
		 * @return {Promise<void>}
		 */
		async handleObjectsRestored(objectIds) {
			// Remove from selection if they were selected
			objectIds.forEach(id => {
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
		 * @param {object} options - Export options
		 * @return {void}
		 */
		exportFilteredItems(options) {
			// TODO: Implement export functionality for deleted items
		},
		/**
		 * Handle page change from pagination component
		 * @param {number} page - The page number to change to
		 * @return {Promise<void>}
		 */
		async onPageChanged(page) {
			try {
				await deletedStore.fetchDeleted({
					page,
					limit: deletedStore.deletedPagination.limit,
				})
				// Clear selection when page changes
				this.selectedItems = []
			} catch (error) {
				// Handle error silently
			}
		},
		/**
		 * Handle page size change from pagination component
		 * @param {number} pageSize - The new page size
		 * @return {Promise<void>}
		 */
		async onPageSizeChanged(pageSize) {
			try {
				await deletedStore.fetchDeleted({
					page: 1,
					limit: pageSize,
				})
				// Clear selection when page size changes
				this.selectedItems = []
			} catch (error) {
				console.error('Error changing page size:', error)
			}
		},
		/**
		 * Refresh items list
		 * @return {Promise<void>}
		 */
		async refreshItems() {
			await this.loadItems()
			this.selectedItems = []
		},
		/**
		 * Update counts for sidebar
		 * @return {void}
		 */
		updateCounts() {
			this.$root.$emit('deleted-selection-count', this.selectedItems.length)
			this.$root.$emit('deleted-filtered-count', this.filteredItems.length)
		},
		/**
		 * Handle row click for selection
		 * @param {string} id - Item ID
		 * @param {Event} event - Click event
		 * @return {void}
		 */
		handleRowClick(id, event) {
			// Don't select if clicking on the checkbox, actions button, or inside actions menu
			if (event.target.closest('.tableColumnCheckbox')
				|| event.target.closest('.tableColumnActions')
				|| event.target.closest('.actionsButton')) {
				return
			}

			// Toggle selection on row click
			this.handleSelectItem(id)
		},
		/**
		 * Handle item selection toggle
		 * @param {string} id - Item ID
		 * @return {void}
		 */
		handleSelectItem(id) {
			if (this.selectedItems.includes(id)) {
				this.selectedItems = this.selectedItems.filter(item => item !== id)
			} else {
				this.selectedItems.push(id)
			}
		},
		/**
		 * Format purge date in ISO format yyyy:mm:dd hh:mm
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
		formatBytes,
	},
}
</script>

<style scoped>
/* Fix checkbox layout in table */
.tableColumnCheckbox {
	padding: 8px !important;
}

.tableColumnCheckbox :deep(.checkbox-radio-switch) {
	margin: 0;
	display: flex;
	align-items: center;
	justify-content: center;
}

.tableColumnCheckbox :deep(.checkbox-radio-switch__content) {
	margin: 0;
}

/* Row selection styling */
.table-row-selectable {
	cursor: pointer;
}

.table-row-selectable:hover {
	background-color: var(--color-background-hover);
}

.table-row-selected {
	background-color: var(--color-primary-light) !important;
}

/* Actions button styling */
.actionsButton > div > button {
    margin-top: 0px !important;
    margin-right: 0px !important;
    padding-right: 0px !important;
}
</style>
