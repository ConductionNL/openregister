<script setup>
import { deletedStore, registerStore, schemaStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="container">
			<!-- Header -->
			<div class="header">
				<h1>{{ t('openregister', 'Soft Deleted Items') }}</h1>
				<p>{{ t('openregister', 'Manage and restore soft deleted items from your registers') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="actions-bar">
				<div class="deleted-items-info">
					<span class="total-count">
						{{ t('openregister', '{count} deleted items', { count: filteredItems.length }) }}
					</span>
					<span v-if="selectedItems.length > 0" class="selection-indicator">
						({{ t('openregister', '{count} selected', { count: selectedItems.length }) }})
					</span>
				</div>
				<div class="actions">
					<NcButton
						v-if="selectedItems.length > 0"
						type="primary"
						@click="bulkRestore">
						<template #icon>
							<Restore :size="20" />
						</template>
						{{ t('openregister', 'Restore ({count})', { count: selectedItems.length }) }}
					</NcButton>
					<NcButton
						v-if="selectedItems.length > 0"
						type="error"
						@click="bulkDelete">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('openregister', 'Delete ({count})', { count: selectedItems.length }) }}
					</NcButton>
					<NcButton @click="refreshItems">
						<template #icon>
							<Refresh :size="20" />
						</template>
						{{ t('openregister', 'Refresh') }}
					</NcButton>
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

			<div v-else class="table-container">
				<table class="items-table">
					<thead>
						<tr>
							<th class="checkbox-column">
								<NcCheckboxRadioSwitch
									:checked="allSelected"
									:indeterminate="someSelected"
									@update:checked="toggleSelectAll" />
							</th>
							<th>{{ t('openregister', 'Title') }}</th>
							<th>{{ t('openregister', 'Register') }}</th>
							<th>{{ t('openregister', 'Schema') }}</th>
							<th>{{ t('openregister', 'Deleted Date') }}</th>
							<th>{{ t('openregister', 'Deleted By') }}</th>
							<th>{{ t('openregister', 'Purge Date') }}</th>
							<th>{{ t('openregister', 'Actions') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="item in paginatedItems"
							:key="item.id"
							class="item-row"
							:class="{ selected: selectedItems.includes(item.id) }">
							<td class="checkbox-column">
								<NcCheckboxRadioSwitch
									:checked="selectedItems.includes(item.id)"
									@update:checked="(checked) => toggleItemSelection(item.id, checked)" />
							</td>
							<td class="title-column">
								<div class="title-content">
									<strong>{{ getItemTitle(item) }}</strong>
									<span v-if="getItemDescription(item)" class="description">{{ getItemDescription(item) }}</span>
								</div>
							</td>
							<td>{{ getRegisterName(item['@self']?.register) }}</td>
							<td>{{ getSchemaName(item['@self']?.schema) }}</td>
							<td>
								<span v-if="item['@self']?.deleted?.deleted">{{ formatPurgeDate(item['@self'].deleted.deleted) }}</span>
								<span v-else>{{ t('openregister', 'Unknown') }}</span>
							</td>
							<td>{{ item['@self']?.deleted?.deletedBy || t('openregister', 'Unknown') }}</td>
							<td>
								<span v-if="item['@self']?.deleted?.purgeDate">{{ formatPurgeDate(item['@self'].deleted.purgeDate) }}</span>
								<span v-else>{{ t('openregister', 'No purge date set') }}</span>
							</td>
							<td class="actions-column">
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
			<div v-if="totalPages > 1" class="pagination">
				<NcButton
					:disabled="currentPage === 1"
					@click="changePage(1)">
					{{ t('openregister', 'First') }}
				</NcButton>
				<NcButton
					:disabled="currentPage === 1"
					@click="changePage(currentPage - 1)">
					{{ t('openregister', 'Previous') }}
				</NcButton>
				<span class="page-info">
					{{ t('openregister', 'Page {current} of {total}', { current: currentPage, total: totalPages }) }}
				</span>
				<NcButton
					:disabled="currentPage === totalPages"
					@click="changePage(currentPage + 1)">
					{{ t('openregister', 'Next') }}
				</NcButton>
				<NcButton
					:disabled="currentPage === totalPages"
					@click="changePage(totalPages)">
					{{ t('openregister', 'Last') }}
				</NcButton>
			</div>
		</div>

		<!-- Deletion Dialogs -->
		<PermanentlyDeleteObject />
		<PermanentlyDeleteMultiple />
	</NcAppContent>
</template>

<script>
import {
	NcAppContent,
	NcEmptyContent,
	NcButton,
	NcLoadingIcon,
	NcCheckboxRadioSwitch,
	NcActions,
	NcActionButton,
} from '@nextcloud/vue'
import DeleteEmpty from 'vue-material-design-icons/DeleteEmpty.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

// Import deletion dialogs
import PermanentlyDeleteObject from '../../modals/deleted/PermanentlyDeleteObject.vue'
import PermanentlyDeleteMultiple from '../../modals/deleted/PermanentlyDeleteMultiple.vue'

export default {
	name: 'DeletedIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcActions,
		NcActionButton,
		DeleteEmpty,
		Restore,
		Delete,
		Refresh,
		// Deletion dialogs
		PermanentlyDeleteObject,
		PermanentlyDeleteMultiple,
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
		this.$root.$on('deleted-object-permanently-deleted', this.handleObjectDeleted)
		this.$root.$on('deleted-objects-permanently-deleted', this.handleObjectsDeleted)
	},
	beforeDestroy() {
		this.$root.$off('deleted-filters-changed')
		this.$root.$off('deleted-bulk-restore')
		this.$root.$off('deleted-bulk-delete')
		this.$root.$off('deleted-export-filtered')
		this.$root.$off('deleted-object-permanently-deleted')
		this.$root.$off('deleted-objects-permanently-deleted')
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
		 * Restore selected items
		 * @return {Promise<void>}
		 */
		async bulkRestore() {
			if (this.selectedItems.length === 0) return

			try {
				await deletedStore.restoreMultiple(this.selectedItems)
				this.selectedItems = []
				// Refresh the list
				await this.loadItems()
			} catch (error) {
				console.error('Error restoring items:', error)
			}
		},
		/**
		 * Permanently delete selected items using dialog
		 * @return {void}
		 */
		bulkDelete() {
			if (this.selectedItems.length === 0) return

			// Get selected objects data
			const selectedObjects = this.paginatedItems.filter(item => this.selectedItems.includes(item.id))

			// Set transfer data and open dialog
			navigationStore.setTransferData(selectedObjects)
			navigationStore.setDialog('permanentlyDeleteMultiple')
		},
		/**
		 * Restore individual item
		 * @param {object} item - Item to restore
		 * @return {Promise<void>}
		 */
		async restoreItem(item) {
			try {
				await deletedStore.restoreDeleted(item.id)
				// Refresh the list
				await this.loadItems()
			} catch (error) {
				console.error('Error restoring item:', error)
			}
		},
		/**
		 * Permanently delete individual item using dialog
		 * @param {object} item - Item to delete
		 * @return {void}
		 */
		permanentlyDelete(item) {
			// Set transfer data and open dialog
			navigationStore.setTransferData(item)
			navigationStore.setDialog('permanentlyDeleteObject')
		},
		/**
		 * Handle single object deletion event
		 * @param {string} objectId - ID of deleted object
		 * @return {Promise<void>}
		 */
		async handleObjectDeleted(objectId) {
			// Remove from selection if it was selected
			const index = this.selectedItems.indexOf(objectId)
			if (index > -1) {
				this.selectedItems.splice(index, 1)
			}

			// Refresh the list
			await this.loadItems()
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
		 * Export filtered items with specified options
		 * @param {object} options - Export options
		 * @return {void}
		 */
		exportFilteredItems(options) {
			// TODO: Implement export functionality for deleted items
		},
		/**
		 * Change page
		 * @param {number} page - The page number to change to
		 * @return {Promise<void>}
		 */
		async changePage(page) {
			try {
				await deletedStore.fetchDeleted({ page })
				// Clear selection when page changes
				this.selectedItems = []
			} catch (error) {
				// Handle error silently
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
	padding-left: 24px;
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

.deleted-items-info {
	display: flex;
	align-items: center;
	gap: 10px;
}

.total-count {
	font-weight: 500;
	color: var(--color-main-text);
}

.selection-indicator {
	font-size: 0.9rem;
	color: var(--color-primary);
}

.actions {
	display: flex;
	align-items: center;
	gap: 15px;
}

.table-container {
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 2px 4px var(--color-box-shadow);
}

.items-table {
	width: 100%;
	border-collapse: collapse;
}

.items-table th,
.items-table td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.items-table th {
	background: var(--color-background-hover);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.checkbox-column {
	width: 50px;
	text-align: center;
}

.title-column {
	min-width: 120px;
	max-width: 200px;
	word-wrap: break-word;
	overflow: hidden;
}

.title-content {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.description {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Register and Schema column width limits */
.items-table td:nth-child(3), /* Register column */
.items-table td:nth-child(4) { /* Schema column */
	max-width: 150px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.items-table th:nth-child(3), /* Register header */
.items-table th:nth-child(4) { /* Schema header */
	max-width: 150px;
}

.actions-column {
	width: 120px;
	text-align: center;
	min-width: 120px;
}

.item-row:hover {
	background: var(--color-background-hover);
}

.item-row.selected {
	background: var(--color-primary-light);
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

/* Responsive table adjustments */
@media (max-width: 1200px) {
	.title-column {
		min-width: 150px;
		max-width: 200px;
	}
}
</style>
