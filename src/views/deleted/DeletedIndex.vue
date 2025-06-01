<script setup>
// No setup script needed for this component
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
				<div class="selection-info">
					<span v-if="selectedItems.length > 0" class="selected-count">
						{{ t('openregister', '{count} items selected', { count: selectedItems.length }) }}
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
						{{ t('openregister', 'Restore Selected') }}
					</NcButton>
					<NcButton
						v-if="selectedItems.length > 0"
						type="error"
						@click="bulkDelete">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('openregister', 'Permanently Delete Selected') }}
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
			<div v-if="loading" class="loading">
				<NcLoadingIcon :size="64" />
				<p>{{ t('openregister', 'Loading deleted items...') }}</p>
			</div>

			<NcEmptyContent v-else-if="!filteredItems.length"
				:name="t('openregister', 'No deleted items found')"
				:description="t('openregister', 'There are no deleted items matching your current filters.')">
				<template #icon>
					<DeleteEmpty />
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
							<th>{{ t('openregister', 'Type') }}</th>
							<th>{{ t('openregister', 'Register') }}</th>
							<th>{{ t('openregister', 'Deleted Date') }}</th>
							<th>{{ t('openregister', 'Deleted By') }}</th>
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
									<strong>{{ item.title }}</strong>
									<span v-if="item.description" class="description">{{ item.description }}</span>
								</div>
							</td>
							<td>
								<span class="type-badge">{{ item.type }}</span>
							</td>
							<td>{{ item.register }}</td>
							<td>
								<NcDateTime :timestamp="new Date(item.deletedAt)" :ignore-seconds="true" />
							</td>
							<td>{{ item.deletedBy }}</td>
							<td class="actions-column">
								<NcActions>
									<NcActionButton @click="restoreItem(item)">
										<template #icon>
											<Restore :size="20" />
										</template>
										{{ t('openregister', 'Restore') }}
									</NcActionButton>
									<NcActionButton @click="viewDetails(item)">
										<template #icon>
											<Eye :size="20" />
										</template>
										{{ t('openregister', 'View Details') }}
									</NcActionButton>
									<NcActionButton @click="permanentlyDelete(item)">
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
					@click="currentPage = 1">
					{{ t('openregister', 'First') }}
				</NcButton>
				<NcButton
					:disabled="currentPage === 1"
					@click="currentPage--">
					{{ t('openregister', 'Previous') }}
				</NcButton>
				<span class="page-info">
					{{ t('openregister', 'Page {current} of {total}', { current: currentPage, total: totalPages }) }}
				</span>
				<NcButton
					:disabled="currentPage === totalPages"
					@click="currentPage++">
					{{ t('openregister', 'Next') }}
				</NcButton>
				<NcButton
					:disabled="currentPage === totalPages"
					@click="currentPage = totalPages">
					{{ t('openregister', 'Last') }}
				</NcButton>
			</div>
		</div>
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
	NcDateTime,
} from '@nextcloud/vue'
import DeleteEmpty from 'vue-material-design-icons/DeleteEmpty.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Eye from 'vue-material-design-icons/Eye.vue'

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
		NcDateTime,
		DeleteEmpty,
		Restore,
		Delete,
		Refresh,
		Eye,
	},
	data() {
		return {
			loading: false,
			currentPage: 1,
			itemsPerPage: 25,
			selectedItems: [],
			filters: {},
			// Mock data - replace with actual API calls
			items: [
				{
					id: '1',
					title: 'Sample Object',
					description: 'A sample deleted object for testing',
					type: 'Object',
					register: 'Sample Register',
					deletedAt: new Date(Date.now() - 86400000).toISOString(), // 1 day ago
					deletedBy: 'admin',
				},
				{
					id: '2',
					title: 'Test Schema',
					description: 'Test schema that was removed',
					type: 'Schema',
					register: 'Test Register',
					deletedAt: new Date(Date.now() - 172800000).toISOString(), // 2 days ago
					deletedBy: 'user1',
				},
				{
					id: '3',
					title: 'Legacy Configuration',
					description: null,
					type: 'Configuration',
					register: 'Legacy Register',
					deletedAt: new Date(Date.now() - 259200000).toISOString(), // 3 days ago
					deletedBy: 'admin',
				},
				{
					id: '4',
					title: 'Old Data Source',
					description: 'Deprecated data source connection',
					type: 'Source',
					register: 'Legacy Register',
					deletedAt: new Date(Date.now() - 604800000).toISOString(), // 1 week ago
					deletedBy: 'user2',
				},
				{
					id: '5',
					title: 'Archived Template',
					description: 'Template that is no longer needed',
					type: 'Template',
					register: 'Archive Register',
					deletedAt: new Date(Date.now() - 1209600000).toISOString(), // 2 weeks ago
					deletedBy: 'admin',
				},
			],
		}
	},
	computed: {
		filteredItems() {
			// Apply filters from sidebar
			let filtered = [...this.items]

			if (this.filters.register) {
				filtered = filtered.filter(item => item.register === this.filters.register)
			}
			if (this.filters.deletedBy) {
				filtered = filtered.filter(item => item.deletedBy === this.filters.deletedBy)
			}
			if (this.filters.dateFrom) {
				filtered = filtered.filter(item => new Date(item.deletedAt) >= new Date(this.filters.dateFrom))
			}
			if (this.filters.dateTo) {
				filtered = filtered.filter(item => new Date(item.deletedAt) <= new Date(this.filters.dateTo))
			}

			return filtered
		},
		paginatedItems() {
			const start = (this.currentPage - 1) * this.itemsPerPage
			return this.filteredItems.slice(start, start + this.itemsPerPage)
		},
		totalPages() {
			return Math.ceil(this.filteredItems.length / this.itemsPerPage)
		},
		allSelected() {
			return this.paginatedItems.length > 0 && this.paginatedItems.every(item => this.selectedItems.includes(item.id))
		},
		someSelected() {
			return this.selectedItems.length > 0 && !this.allSelected
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
	mounted() {
		this.loadItems()

		// Listen for filter changes from sidebar
		this.$root.$on('deleted-filters-changed', this.handleFiltersChanged)
		this.$root.$on('deleted-bulk-restore', this.bulkRestore)
		this.$root.$on('deleted-bulk-delete', this.bulkDelete)
		this.$root.$on('deleted-export-filtered', this.exportFiltered)

		// Emit counts to sidebar
		this.updateCounts()
	},
	beforeDestroy() {
		this.$root.$off('deleted-filters-changed')
		this.$root.$off('deleted-bulk-restore')
		this.$root.$off('deleted-bulk-delete')
		this.$root.$off('deleted-export-filtered')
	},
	methods: {
		/**
		 * Load deleted items from API
		 * @return {Promise<void>}
		 */
		async loadItems() {
			this.loading = true
			try {
				// TODO: Replace with actual API call
				// const response = await fetch('/api/deleted-items')
				// this.items = await response.json()

				// Mock delay
				await new Promise(resolve => setTimeout(resolve, 500))
			} catch (error) {
				console.error('Error loading deleted items:', error)
			} finally {
				this.loading = false
			}
		},
		/**
		 * Handle filter changes from sidebar
		 * @param {object} filters - Filter object from sidebar
		 * @return {void}
		 */
		handleFiltersChanged(filters) {
			this.filters = filters
			this.currentPage = 1 // Reset to first page when filters change
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
				// TODO: Replace with actual API call
				// await fetch('/api/deleted-items/restore', {
				//     method: 'POST',
				//     body: JSON.stringify({ ids: this.selectedItems })
				// })

				// Remove from items array (mock)
				this.items = this.items.filter(item => !this.selectedItems.includes(item.id))
				this.selectedItems = []

				OC.Notification.showSuccess(this.t('openregister', 'Items restored successfully'))
			} catch (error) {
				console.error('Error restoring items:', error)
				OC.Notification.showError(this.t('openregister', 'Error restoring items'))
			}
		},
		/**
		 * Permanently delete selected items
		 * @return {Promise<void>}
		 */
		async bulkDelete() {
			if (this.selectedItems.length === 0) return

			if (!confirm(this.t('openregister', 'Are you sure you want to permanently delete the selected items? This action cannot be undone.'))) {
				return
			}

			try {
				// TODO: Replace with actual API call
				// await fetch('/api/deleted-items/permanent-delete', {
				//     method: 'DELETE',
				//     body: JSON.stringify({ ids: this.selectedItems })
				// })

				// Remove from items array (mock)
				this.items = this.items.filter(item => !this.selectedItems.includes(item.id))
				this.selectedItems = []

				OC.Notification.showSuccess(this.t('openregister', 'Items permanently deleted'))
			} catch (error) {
				console.error('Error deleting items:', error)
				OC.Notification.showError(this.t('openregister', 'Error deleting items'))
			}
		},
		/**
		 * Restore individual item
		 * @param {object} item - Item to restore
		 * @return {Promise<void>}
		 */
		async restoreItem(item) {
			try {
				// TODO: Replace with actual API call
				// await fetch(`/api/deleted-items/${item.id}/restore`, { method: 'POST' })

				// Remove from items array (mock)
				const index = this.items.findIndex(i => i.id === item.id)
				if (index > -1) {
					this.items.splice(index, 1)
				}

				OC.Notification.showSuccess(this.t('openregister', 'Item restored successfully'))
			} catch (error) {
				console.error('Error restoring item:', error)
				OC.Notification.showError(this.t('openregister', 'Error restoring item'))
			}
		},
		/**
		 * Permanently delete individual item
		 * @param {object} item - Item to delete
		 * @return {Promise<void>}
		 */
		async permanentlyDelete(item) {
			if (!confirm(this.t('openregister', 'Are you sure you want to permanently delete "{title}"? This action cannot be undone.', { title: item.title }))) {
				return
			}

			try {
				// TODO: Replace with actual API call
				// await fetch(`/api/deleted-items/${item.id}`, { method: 'DELETE' })

				// Remove from items array (mock)
				const index = this.items.findIndex(i => i.id === item.id)
				if (index > -1) {
					this.items.splice(index, 1)
				}

				OC.Notification.showSuccess(this.t('openregister', 'Item permanently deleted'))
			} catch (error) {
				console.error('Error deleting item:', error)
				OC.Notification.showError(this.t('openregister', 'Error deleting item'))
			}
		},
		/**
		 * View item details (could open a modal)
		 * @param {object} item - Item to view
		 * @return {void}
		 */
		viewDetails(item) {
			// TODO: Implement details modal or navigation
			// console.log('View details for item:', item)
		},
		/**
		 * Export filtered items with specified options
		 * @param {object} options - Export options
		 * @return {void}
		 */
		exportFilteredItems(options) {
			// TODO: Implement export functionality
			// console.log('Export filtered items:', this.filteredItems)
			OC.Notification.showSuccess(this.t('openregister', 'Export started'))
		},
		/**
		 * Refresh items list
		 * @return {Promise<void>}
		 */
		async refreshItems() {
			await this.loadItems()
		},
		/**
		 * Update counts for sidebar
		 * @return {void}
		 */
		updateCounts() {
			this.$root.$emit('deleted-selection-count', this.selectedItems.length)
			this.$root.$emit('deleted-filtered-count', this.filteredItems.length)
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

.selection-info .selected-count {
	font-weight: 500;
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
	min-width: 250px;
}

.title-content {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.description {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.actions-column {
	width: 120px;
	text-align: center;
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

.type-badge {
	display: inline-block;
	padding: 4px 8px;
	border-radius: 12px;
	background: var(--color-primary-light);
	color: var(--color-primary);
	font-size: 0.8em;
	font-weight: 500;
	white-space: nowrap;
}
</style>
