<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { searchTrailStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<CnIndexPage
			ref="indexPage"
			:title="t('openregister', 'Search Trails')"
			:description="t('openregister', 'View and analyze search trail logs with advanced filtering and analytics capabilities')"
			:show-title="true"
			:show-add="false"
			:show-view-toggle="false"
			:show-form-dialog="false"
			:show-edit-action="false"
			:show-copy-action="false"
			:show-mass-import="false"
			:show-mass-export="false"
			:show-mass-copy="false"
			view-mode="table"
			:objects="searchTrailStore.searchTrailList"
			:columns="tableColumns"
			:pagination="paginationData"
			:selectable="true"
			:selected-ids="selectedSearchTrails"
			:actions="customActions"
			:row-class="getRowClass"
			:refreshing="isRefreshing"
			row-key="id"
			:empty-text="t('openregister', 'No search trail entries found')"
			:name-formatter="formatSearchTrailName"
			@delete="onDelete"
			@mass-delete="onMassDelete"
			@refresh="handleRefresh"
			@page-changed="onPageChanged"
			@page-size-changed="onPageSizeChanged"
			@select="selectedSearchTrails = $event">
			<!-- Custom action items in actions bar -->
			<template #action-items>
				<NcActionButton
					close-after-click
					@click="cleanupSearchTrails">
					<template #icon>
						<Broom :size="20" />
					</template>
					{{ t('openregister', 'Cleanup Old Trails') }}
				</NcActionButton>
			</template>

			<!-- Search Term column: term text + results badge -->
			<template #column-searchTerm="{ row }">
				<span class="searchTermText">{{ row.searchTerm || '-' }}</span>
				<span v-if="row.totalResults > 0" class="searchResultsBadge">
					{{ row.totalResults }} {{ t('openregister', 'results') }}
				</span>
			</template>

			<!-- Timestamp column -->
			<template #column-created="{ row }">
				<NcDateTime :timestamp="new Date(row.created)" :ignore-seconds="false" />
			</template>

			<!-- Register column -->
			<template #column-register="{ row }">
				{{ row.registerName || row.register || '-' }}
			</template>

			<!-- Schema column -->
			<template #column-schema="{ row }">
				{{ row.schemaName || row.schema || '-' }}
			</template>

			<!-- User column -->
			<template #column-user="{ row }">
				{{ row.userName || row.user || '-' }}
			</template>

			<!-- Results column with color coding -->
			<template #column-totalResults="{ row }">
				<span :class="{ 'success-text': row.totalResults > 0, 'error-text': !row.totalResults }">
					{{ row.totalResults || 0 }}
				</span>
			</template>

			<!-- Execution Time column -->
			<template #column-responseTime="{ row }">
				<span class="executionTime">{{ formatExecutionTime(row.responseTime) }}</span>
			</template>
		</CnIndexPage>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcActionButton, NcDateTime } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'

import Eye from 'vue-material-design-icons/Eye.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Broom from 'vue-material-design-icons/Broom.vue'

export default {
	name: 'SearchTrailIndex',
	components: {
		NcAppContent,
		NcActionButton,
		NcDateTime,
		CnIndexPage,
		Broom,
	},
	data() {
		return {
			selectedSearchTrails: [],
			isRefreshing: false,
		}
	},
	computed: {
		tableColumns() {
			return [
				{ key: 'searchTerm', label: t('openregister', 'Search Term') },
				{ key: 'created', label: t('openregister', 'Timestamp'), sortable: true },
				{ key: 'register', label: t('openregister', 'Register') },
				{ key: 'schema', label: t('openregister', 'Schema') },
				{ key: 'user', label: t('openregister', 'User') },
				{ key: 'totalResults', label: t('openregister', 'Results') },
				{ key: 'responseTime', label: t('openregister', 'Execution Time') },
			]
		},
		paginationData() {
			const p = searchTrailStore.searchTrailPagination
			return {
				page: p.page || 1,
				pages: p.pages || 1,
				total: p.total || 0,
				limit: p.limit || 50,
			}
		},
		customActions() {
			return [
				{
					label: t('openregister', 'View Details'),
					icon: Eye,
					handler: (row) => this.viewDetails(row),
				},
				{
					label: t('openregister', 'View Parameters'),
					icon: Cog,
					handler: (row) => this.viewParameters(row),
					disabled: (row) => !this.hasParameters(row),
				},
				{
					label: t('openregister', 'Rerun Search'),
					icon: Refresh,
					handler: (row) => this.rerunSearch(row),
					disabled: (row) => !row.searchTerm,
				},
			]
		},
	},
	mounted() {
		this.loadSearchTrails()
	},
	methods: {
		formatSearchTrailName(item) {
			return t('openregister', 'Search Trail #{id}', { id: item.id })
		},
		getRowClass(row) {
			return row.totalResults > 0 ? 'success' : 'failed'
		},
		/**
		 * Load search trails from API
		 * @return {Promise<void>}
		 */
		async loadSearchTrails() {
			try {
				await searchTrailStore.refreshSearchTrailList()
			} catch (error) {
				console.error('Error loading search trails:', error)
				OC.Notification.showTemporary(this.t('openregister', 'Error loading search trails'), { type: 'error' })
			}
		},
		/**
		 * Handle refresh
		 * @return {Promise<void>}
		 */
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await this.loadSearchTrails()
			} finally {
				this.isRefreshing = false
			}
		},
		/**
		 * View detailed information for a search trail entry
		 * @param {object} searchTrail - Search trail entry to view
		 * @return {void}
		 */
		viewDetails(searchTrail) {
			// Create a formatted details message
			const details = []
			details.push(`Search Term: ${searchTrail.searchTerm || 'N/A'}`)
			details.push(`Timestamp: ${new Date(searchTrail.created).toLocaleString()}`)
			details.push(`Register: ${searchTrail.registerName || searchTrail.register || 'N/A'}`)
			details.push(`Schema: ${searchTrail.schemaName || searchTrail.schema || 'N/A'}`)
			details.push(`User: ${searchTrail.userName || searchTrail.user || 'N/A'}`)
			details.push(`Results: ${searchTrail.totalResults || 0}`)
			details.push(`Execution Time: ${this.formatExecutionTime(searchTrail.responseTime)}`)

			if (searchTrail.parameters && typeof searchTrail.parameters === 'object') {
				details.push(`Parameters: ${JSON.stringify(searchTrail.parameters, null, 2)}`)
			}

			// Show details in a dialog
			OC.dialogs.info(
				details.join('\n'),
				this.t('openregister', 'Search Trail Details'),
				null,
				true,
			)
		},
		/**
		 * View parameters information for a search trail entry
		 * @param {object} searchTrail - Search trail entry with parameters
		 * @return {void}
		 */
		viewParameters(searchTrail) {
			searchTrailStore.setSearchTrailItem(searchTrail)
			navigationStore.setDialog('searchTrailParameters')
		},
		/**
		 * Rerun a search based on the search trail parameters
		 * @param {object} searchTrail - Search trail entry to rerun
		 * @return {void}
		 */
		rerunSearch(searchTrail) {
			const searchParams = {
				q: searchTrail.searchTerm,
				register: searchTrail.register,
				schema: searchTrail.schema,
			}

			if (searchTrail.parameters) {
				try {
					const parsedParams = typeof searchTrail.parameters === 'string'
						? JSON.parse(searchTrail.parameters)
						: searchTrail.parameters

					Object.assign(searchParams, parsedParams)
				} catch (error) {
					console.error('Error parsing search parameters:', error)
				}
			}

			this.$router.push({
				name: 'search',
				query: searchParams,
			})

			OC.Notification.showTemporary(
				this.t('openregister', 'Rerunning search: {searchTerm}', { searchTerm: searchTrail.searchTerm }),
				{ type: 'info' },
			)
		},
		/**
		 * Delete a single search trail
		 * @param {string} id - Search trail ID
		 * @return {void}
		 */
		onDelete(id) {
			const searchTrail = searchTrailStore.searchTrailList.find(s => s.id === id)
			if (!searchTrail) return
			searchTrailStore.setSearchTrailItem(searchTrail)
			navigationStore.setDialog('deleteSearchTrail')
		},
		/**
		 * Delete selected search trails using bulk operation
		 * @param {Array} ids - Array of search trail IDs
		 * @return {Promise<void>}
		 */
		async onMassDelete(ids) {
			try {
				await searchTrailStore.deleteMultipleSearchTrails(ids)
				this.$refs.indexPage.setMassDeleteResult({ success: true })
				this.selectedSearchTrails = []
				await this.loadSearchTrails()
			} catch (error) {
				console.error('Error deleting search trails:', error)
				this.$refs.indexPage.setMassDeleteResult({ error: error.message })
			}
		},
		/**
		 * Clean up old search trails
		 * @return {Promise<void>}
		 */
		async cleanupSearchTrails() {
			if (!confirm(this.t('openregister', 'Are you sure you want to cleanup old search trails? This will delete entries older than 30 days.'))) {
				return
			}

			try {
				const result = await searchTrailStore.cleanupSearchTrails(30)

				if (result.success) {
					OC.Notification.showTemporary(this.t('openregister', 'Cleanup completed successfully. Deleted {count} entries.', { count: result.deletedCount || 0 }), { type: 'success' })
					await this.loadSearchTrails()
				} else {
					throw new Error(result.message || 'Cleanup failed')
				}
			} catch (error) {
				console.error('Error during cleanup:', error)
				OC.Notification.showTemporary(this.t('openregister', 'Cleanup failed: {error}', { error: error.message }), { type: 'error' })
			}
		},
		/**
		 * Check if search trail has parameters
		 * @param {object} searchTrail - The search trail item
		 * @return {boolean} Whether the search trail has parameters
		 */
		hasParameters(searchTrail) {
			try {
				if (!searchTrail || !searchTrail.parameters) return false

				if (typeof searchTrail.parameters === 'string') {
					const parsed = JSON.parse(searchTrail.parameters)
					return Object.keys(parsed).length > 0
				}

				if (typeof searchTrail.parameters === 'object') {
					return Object.keys(searchTrail.parameters).length > 0
				}

				return false
			} catch (error) {
				console.error('Error checking parameters:', error)
				return false
			}
		},
		/**
		 * Format execution time for display
		 * @param {number} executionTime - Execution time in milliseconds
		 * @return {string} Formatted execution time
		 */
		formatExecutionTime(executionTime) {
			if (!executionTime) return '-'

			if (executionTime < 1000) {
				return `${executionTime}ms`
			}

			return `${(executionTime / 1000).toFixed(2)}s`
		},
		/**
		 * Handle page change from pagination component
		 * @param {number} page - The page number to change to
		 * @return {Promise<void>}
		 */
		async onPageChanged(page) {
			try {
				await searchTrailStore.fetchSearchTrails({
					page,
					limit: searchTrailStore.searchTrailPagination.limit,
				})
				this.selectedSearchTrails = []
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
				await searchTrailStore.fetchSearchTrails({
					page: 1,
					limit: pageSize,
				})
				this.selectedSearchTrails = []
			} catch (error) {
				console.error('Error changing page size:', error)
			}
		},
	},
}
</script>

<style scoped>
/* Success/failed row styling — only when not selected */
:deep(.success:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-success);
}

:deep(.failed:not(.cn-table-row--selected)) {
	box-shadow: inset 3px 0 0 0 var(--color-error);
}

/* Search term styling */
.searchTermText {
	font-weight: 500;
	color: var(--color-main-text);
}

.searchResultsBadge {
	display: inline-block;
	padding: 2px 6px;
	border-radius: 10px;
	font-size: 0.75rem;
	font-weight: 500;
	color: var(--color-primary-text);
	background: var(--color-primary-light);
}

/* Execution time styling */
.executionTime {
	font-family: 'Courier New', monospace;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

/* Success/error text styling */
.success-text {
	color: var(--color-success);
	font-weight: 500;
}

.error-text {
	color: var(--color-error);
	font-weight: 500;
}
</style>
