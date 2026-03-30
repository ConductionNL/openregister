<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { deletedStore, registerStore, schemaStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openregister', 'Soft Deleted Items')"
			:description="t('openregister', 'Manage and restore soft deleted items from your registers')"
			:show-title="true"
			:show-add="false"
			:show-view-toggle="false"
			:show-form-dialog="false"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-delete-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			:show-mass-delete="false"
			view-mode="table"
			:objects="deletedStore.deletedList"
			:columns="tableColumns"
			:pagination="paginationData"
			:selectable="true"
			:selected-ids="selectedItems"
			:actions="customActions"
			:refreshing="isRefreshing"
			:loading="deletedStore.deletedLoading"
			row-key="id"
			:empty-text="t('openregister', 'No deleted items found')"
			:name-formatter="formatDeletedItemName"
			@refresh="handleRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@select="selectedItems = $event">
			<!-- Custom mass actions -->
			<template #mass-actions="{ count }">
				<NcActionButton
					:disabled="count === 0"
					close-after-click
					@click="bulkRestore">
					<template #icon>
						<Restore :size="20" />
					</template>
					{{ t('openregister', 'Restore selected') }}
				</NcActionButton>
				<NcActionButton
					:disabled="count === 0"
					close-after-click
					@click="bulkDelete">
					<template #icon>
						<Delete :size="20" />
					</template>
					{{ t('openregister', 'Purge selected') }}
				</NcActionButton>
			</template>

			<!-- Title column -->
			<template #column-title="{ row }">
				<div class="titleContent">
					<strong>{{ getItemTitle(row) }}</strong>
					<span v-if="getItemDescription(row)" class="textDescription textEllipsis">{{ getItemDescription(row) }}</span>
				</div>
			</template>

			<!-- Register column -->
			<template #column-register="{ row }">
				{{ getRegisterName(row['@self']?.register) }}
			</template>

			<!-- Schema column -->
			<template #column-schema="{ row }">
				{{ getSchemaName(row['@self']?.schema) }}
			</template>

			<!-- Deleted Date column -->
			<template #column-deletedDate="{ row }">
				<span v-if="row['@self']?.deleted?.deleted">{{ formatPurgeDate(row['@self'].deleted.deleted) }}</span>
				<span v-else>{{ t('openregister', 'Unknown') }}</span>
			</template>

			<!-- Deleted By column -->
			<template #column-deletedBy="{ row }">
				{{ row['@self']?.deleted?.deletedBy || t('openregister', 'Unknown') }}
			</template>

			<!-- Purge Date column -->
			<template #column-purgeDate="{ row }">
				<span v-if="row['@self']?.deleted?.purgeDate">{{ formatPurgeDate(row['@self'].deleted.purgeDate) }}</span>
				<span v-else>{{ t('openregister', 'No purge date set') }}</span>
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActionButton } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'

import Restore from 'vue-material-design-icons/Restore.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'DeletedIndex',
	components: {
		NcAppContent,
		NcActionButton,
		CnIndexPage,
		Restore,
		Delete,
	},
	data() {
		return {
			selectedItems: [],
			isRefreshing: false,
		}
	},
	computed: {
		tableColumns() {
			return [
				{ key: 'title', label: t('openregister', 'Title') },
				{ key: 'register', label: t('openregister', 'Register') },
				{ key: 'schema', label: t('openregister', 'Schema') },
				{ key: 'deletedDate', label: t('openregister', 'Deleted Date') },
				{ key: 'deletedBy', label: t('openregister', 'Deleted By') },
				{ key: 'purgeDate', label: t('openregister', 'Purge Date') },
			]
		},
		paginationData() {
			const p = deletedStore.deletedPagination
			return {
				page: p.page || 1,
				pages: p.pages || 1,
				total: p.total || 0,
				limit: p.limit || 20,
			}
		},
		customActions() {
			return [
				{
					label: t('openregister', 'Restore'),
					icon: Restore,
					handler: (row) => this.restoreItem(row),
				},
				{
					label: t('openregister', 'Permanently Delete'),
					icon: Delete,
					handler: (row) => this.permanentlyDelete(row),
				},
			]
		},
	},
	watch: {
		selectedItems() {
			this.updateCounts()
		},
		'deletedStore.deletedList'() {
			this.updateCounts()
		},
	},
	async mounted() {
		await this.loadItems()
		this.updateCounts()

		this.$root.$on('deleted-filters-changed', this.handleFiltersChanged)
		this.$root.$on('deleted-bulk-restore', this.bulkRestore)
		this.$root.$on('deleted-bulk-delete', this.bulkDelete)
		this.$root.$on('deleted-export-filtered', this.exportFiltered)

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
		formatDeletedItemName(item) {
			return this.getItemTitle(item)
		},
		async loadItems() {
			try {
				await deletedStore.fetchDeleted()

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
		async handleFiltersChanged(filters) {
			const apiFilters = this.convertFiltersToApiFormat(filters)

			deletedStore.setDeletedFilters(apiFilters)

			try {
				await deletedStore.fetchDeleted({
					page: 1,
					filters: apiFilters,
				})

				this.selectedItems = []
			} catch (error) {
				console.error('Error applying filters:', error)
			}
		},
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
		getItemTitle(item) {
			return item.title || item.fileName || item.name || item.object?.title || item.object?.name || item.id
		},
		getItemDescription(item) {
			return item.description || item.object?.description || item.object?.summary || null
		},
		getRegisterName(registerId) {
			if (!registerId) return t('openregister', 'Unknown Register')

			const register = registerStore.registerList.find(r => r.id === parseInt(registerId))
			return register?.title || `Register ${registerId}`
		},
		getSchemaName(schemaId) {
			if (!schemaId) return t('openregister', 'Unknown Schema')

			const schema = schemaStore.schemaList.find(s => s.id === parseInt(schemaId))
			return schema?.title || `Schema ${schemaId}`
		},
		bulkRestore() {
			if (this.selectedItems.length === 0) return

			const selectedObjects = deletedStore.deletedList.filter(item => this.selectedItems.includes(item.id))

			deletedStore.setSelectedForBulkAction(selectedObjects)
			navigationStore.setDialog('restoreMultiple')
		},
		bulkDelete() {
			if (this.selectedItems.length === 0) return

			const selectedObjects = deletedStore.deletedList.filter(item => this.selectedItems.includes(item.id))

			deletedStore.setSelectedForBulkAction(selectedObjects)
			navigationStore.setDialog('permanentlyDeleteMultiple')
		},
		restoreItem(item) {
			deletedStore.setSelectedForBulkAction([item])
			navigationStore.setDialog('restoreMultiple')
		},
		permanentlyDelete(item) {
			deletedStore.setSelectedForBulkAction([item])
			navigationStore.setDialog('permanentlyDeleteMultiple')
		},
		async handleObjectsDeleted(objectIds) {
			objectIds.forEach(id => {
				const index = this.selectedItems.indexOf(id)
				if (index > -1) {
					this.selectedItems.splice(index, 1)
				}
			})

			await this.loadItems()
		},
		async handleObjectsRestored(objectIds) {
			objectIds.forEach(id => {
				const index = this.selectedItems.indexOf(id)
				if (index > -1) {
					this.selectedItems.splice(index, 1)
				}
			})

			await this.loadItems()
		},
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await this.loadItems()
				this.selectedItems = []
			} finally {
				this.isRefreshing = false
			}
		},
		async onPageChanged(page) {
			try {
				await deletedStore.fetchDeleted({
					page,
					limit: deletedStore.deletedPagination.limit,
				})
				this.selectedItems = []
			} catch (error) {
				// Handle error silently
			}
		},
		async onPageSizeChanged(pageSize) {
			try {
				await deletedStore.fetchDeleted({
					page: 1,
					limit: pageSize,
				})
				this.selectedItems = []
			} catch (error) {
				console.error('Error changing page size:', error)
			}
		},
		updateCounts() {
			this.$root.$emit('deleted-selection-count', this.selectedItems.length)
			this.$root.$emit('deleted-filtered-count', deletedStore.deletedList.length)
		},
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
