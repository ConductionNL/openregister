<script setup>
import { searchTrailStore, navigationStore } from '../../store/store.js'
import formatBytes from '../../services/formatBytes.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('openregister', 'Search Trails') }}
				</h1>
				<p>{{ t('openregister', 'View and analyze search trail logs with advanced filtering and analytics capabilities') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<!-- Display pagination info: showing current page items out of total items -->
					<span class="viewTotalCount">
						{{ t('openregister', 'Showing {showing} of {total} search trail entries', { showing: paginatedSearchTrails.length, total: searchTrailStore.searchTrailPagination.total || 0 }) }}
					</span>
					<span v-if="hasActiveFilters" class="viewIndicator">
						({{ t('openregister', 'Filtered') }})
					</span>
					<span v-if="selectedSearchTrails.length > 0" class="viewIndicator">
						({{ t('openregister', '{count} selected', { count: selectedSearchTrails.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<NcActions
						:force-name="true"
						:inline="selectedSearchTrails.length > 0 ? 3 : 2"
						menu-name="Actions">
						<NcActionButton
							v-if="selectedSearchTrails.length > 0"
							type="error"
							close-after-click
							@click="bulkDeleteSearchTrails">
							<template #icon>
								<Delete :size="20" />
							</template>
							{{ t('openregister', 'Delete ({count})', { count: selectedSearchTrails.length }) }}
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="cleanupSearchTrails">
							<template #icon>
								<Broom :size="20" />
							</template>
							{{ t('openregister', 'Cleanup Old Trails') }}
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="refreshSearchTrails">
							<template #icon>
								<Refresh :size="20" />
							</template>
							{{ t('openregister', 'Refresh') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Search Trails Table -->
			<div v-if="searchTrailStore.searchTrailLoading" class="viewLoading">
				<NcLoadingIcon :size="64" />
				<p>{{ t('openregister', 'Loading search trails...') }}</p>
			</div>

			<NcEmptyContent v-else-if="!searchTrailStore.searchTrailList.length"
				:name="t('openregister', 'No search trail entries found')"
				:description="t('openregister', 'There are no search trail entries matching your current filters.')">
				<template #icon>
					<MagnifyPlus />
				</template>
			</NcEmptyContent>

			<div v-else class="viewTableContainer">
				<table class="viewTable searchTrailsTable">
					<thead>
						<tr>
							<th class="tableColumnCheckbox">
								<NcCheckboxRadioSwitch
									:checked="allSelected"
									:indeterminate="someSelected"
									@update:checked="toggleSelectAll" />
							</th>
							<th class="searchTermColumn">
								{{ t('openregister', 'Search Term') }}
							</th>
							<th class="timestampColumn">
								{{ t('openregister', 'Timestamp') }}
							</th>
							<th class="tableColumnConstrained">
								{{ t('openregister', 'Register') }}
							</th>
							<th class="tableColumnConstrained">
								{{ t('openregister', 'Schema') }}
							</th>
							<th class="tableColumnConstrained">
								{{ t('openregister', 'User') }}
							</th>
							<th class="tableColumnConstrained">
								{{ t('openregister', 'Results') }}
							</th>
							<th class="tableColumnConstrained">
								{{ t('openregister', 'Execution Time') }}
							</th>
							<th class="tableColumnActions">
								{{ t('openregister', 'Actions') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="searchTrail in paginatedSearchTrails"
							:key="searchTrail.id"
							class="viewTableRow searchTrailRow"
							:class="{ 'success': searchTrail.totalResults > 0, 'failed': searchTrail.totalResults === 0 }">
							<td class="tableColumnCheckbox">
								<NcCheckboxRadioSwitch
									:checked="selectedSearchTrails.includes(searchTrail.id)"
									@update:checked="(checked) => toggleSearchTrailSelection(searchTrail.id, checked)" />
							</td>
							<td class="searchTermColumn">
								<span class="searchTermText">{{ searchTrail.searchTerm || '-' }}</span>
								<span v-if="searchTrail.totalResults > 0" class="searchResultsBadge">
									{{ searchTrail.totalResults }} {{ t('openregister', 'results') }}
								</span>
							</td>
							<td class="timestampColumn">
								<NcDateTime :timestamp="new Date(searchTrail.created)" :ignore-seconds="false" />
							</td>
							<td class="tableColumnConstrained">
								{{ searchTrail.registerName || searchTrail.register || '-' }}
							</td>
							<td class="tableColumnConstrained">
								{{ searchTrail.schemaName || searchTrail.schema || '-' }}
							</td>
							<td class="tableColumnConstrained">
								{{ searchTrail.userName || searchTrail.user || '-' }}
							</td>
							<td class="tableColumnConstrained">
								<span :class="{ 'success-text': searchTrail.totalResults > 0, 'error-text': !searchTrail.totalResults }">
									{{ searchTrail.totalResults || 0 }}
								</span>
							</td>
							<td class="tableColumnConstrained">
								<span class="executionTime">{{ formatExecutionTime(searchTrail.responseTime) }}</span>
							</td>
							<td class="tableColumnActions">
								<NcActions>
									<NcActionButton close-after-click @click="viewDetails(searchTrail)">
										<template #icon>
											<Eye :size="20" />
										</template>
										{{ t('openregister', 'View Details') }}
									</NcActionButton>
									<NcActionButton v-if="hasParameters(searchTrail)" close-after-click @click="viewParameters(searchTrail)">
										<template #icon>
											<Cog :size="20" />
										</template>
										{{ t('openregister', 'View Parameters') }}
									</NcActionButton>
									<NcActionButton v-if="searchTrail.searchTerm" close-after-click @click="rerunSearch(searchTrail)">
										<template #icon>
											<Refresh :size="20" />
										</template>
										{{ t('openregister', 'Rerun Search') }}
									</NcActionButton>
									<NcActionButton close-after-click class="deleteAction" @click="deleteSearchTrail(searchTrail)">
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
				:current-page="searchTrailStore.searchTrailPagination.page || 1"
				:total-pages="searchTrailStore.searchTrailPagination.pages || 1"
				:total-items="searchTrailStore.searchTrailPagination.total || 0"
				:current-page-size="searchTrailStore.searchTrailPagination.limit || 50"
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
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Broom from 'vue-material-design-icons/Broom.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Cog from 'vue-material-design-icons/Cog.vue'

import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'SearchTrailIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcDateTime,
		NcCheckboxRadioSwitch,
		MagnifyPlus,
		Delete,
		Broom,
		Refresh,
		Eye,
		Cog,
		PaginationComponent,
	},
	data() {
		return {
			itemsPerPage: 50,
			selectedSearchTrails: [],
		}
	},
	computed: {
		hasActiveFilters() {
			return Object.keys(searchTrailStore.searchTrailFilters || {}).some(key =>
				searchTrailStore.searchTrailFilters[key] !== null
				&& searchTrailStore.searchTrailFilters[key] !== undefined
				&& searchTrailStore.searchTrailFilters[key] !== '',
			)
		},
		paginatedSearchTrails() {
			// Ensure we always return a clean array
			try {
				return Array.isArray(searchTrailStore.searchTrailList) ? searchTrailStore.searchTrailList : []
			} catch (error) {
				console.error('Error accessing searchTrailList:', error)
				return []
			}
		},
		allSelected() {
			return this.paginatedSearchTrails.length > 0 && this.paginatedSearchTrails.every(searchTrail => this.selectedSearchTrails.includes(searchTrail.id))
		},
		someSelected() {
			return this.selectedSearchTrails.length > 0 && !this.allSelected
		},
	},
	watch: {
		paginatedSearchTrails: {
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
			this.loadSearchTrails()
		} catch (error) {
			console.error('Error in mounted loadSearchTrails:', error)
		}

		// Listen for filter changes from sidebar
		this.$root.$on('search-trail-filters-changed', this.handleFiltersChanged)
		this.$root.$on('search-trail-refresh', this.refreshSearchTrails)

		// Emit counts to sidebar with delay to ensure store is ready
		this.$nextTick(() => {
			this.updateCounts()
		})
	},
	beforeDestroy() {
		this.$root.$off('search-trail-filters-changed')
		this.$root.$off('search-trail-refresh')
	},
	methods: {
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
		 * Handle filter changes from sidebar
		 * @param {object} filters - Filter object from sidebar
		 * @return {void}
		 */
		handleFiltersChanged(filters) {
			searchTrailStore.setSearchTrailFilters(filters)
			// Refresh with new filters
			this.loadSearchTrails()
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
			// Set the search trail item and open the specialized parameters modal
			searchTrailStore.setSearchTrailItem(searchTrail)
			navigationStore.setDialog('searchTrailParameters')
		},
		/**
		 * Rerun a search based on the search trail parameters
		 * @param {object} searchTrail - Search trail entry to rerun
		 * @return {void}
		 */
		rerunSearch(searchTrail) {
			// Navigate to the search page with the same parameters
			const searchParams = {
				q: searchTrail.searchTerm,
				register: searchTrail.register,
				schema: searchTrail.schema,
			}

			// Add any additional parameters from the search trail
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

			// Navigate to search page with parameters
			this.$router.push({
				name: 'search',
				query: searchParams,
			})

			// Show notification
			OC.Notification.showTemporary(
				this.t('openregister', 'Rerunning search: {searchTerm}', { searchTerm: searchTrail.searchTerm }),
				{ type: 'info' },
			)
		},
		/**
		 * Delete a single search trail using the new modal
		 * @param {object} searchTrail - Search trail to delete
		 * @return {void}
		 */
		deleteSearchTrail(searchTrail) {
			// Set the search trail item in the store
			searchTrailStore.setSearchTrailItem(searchTrail)
			// Open the delete modal
			navigationStore.setDialog('deleteSearchTrail')
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
					// Refresh the list
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
		 * Refresh search trails list
		 * @return {Promise<void>}
		 */
		async refreshSearchTrails() {
			await this.loadSearchTrails()
		},
		/**
		 * Update counts for sidebar
		 * @return {void}
		 */
		updateCounts() {
			try {
				const count = Array.isArray(searchTrailStore.searchTrailList) ? searchTrailStore.searchTrailList.length : 0
				this.$root.$emit('search-trail-filtered-count', count)
			} catch (error) {
				console.error('Error updating counts:', error)
				this.$root.$emit('search-trail-filtered-count', 0)
			}
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
				// Clear selection when page changes
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
				// Clear selection when page size changes
				this.selectedSearchTrails = []
			} catch (error) {
				console.error('Error changing page size:', error)
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
		formatBytes,
		toggleSelectAll(checked) {
			if (checked) {
				this.selectedSearchTrails = this.paginatedSearchTrails.map(searchTrail => searchTrail.id)
			} else {
				this.selectedSearchTrails = []
			}
		},
		toggleSearchTrailSelection(id, checked) {
			if (checked) {
				this.selectedSearchTrails.push(id)
			} else {
				this.selectedSearchTrails = this.selectedSearchTrails.filter(i => i !== id)
			}
		},
		/**
		 * Delete selected search trails using bulk operation
		 * @return {Promise<void>}
		 */
		async bulkDeleteSearchTrails() {
			if (this.selectedSearchTrails.length === 0) return

			if (!confirm(this.t('openregister', 'Are you sure you want to delete the selected search trails? This action cannot be undone.'))) {
				return
			}

			try {
				// Make the API request to delete selected search trails
				const response = await fetch('/index.php/apps/openregister/api/search-trails/bulk-delete', {
					method: 'DELETE',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ ids: this.selectedSearchTrails }),
				})

				const result = await response.json()

				if (result.success) {
					OC.Notification.showTemporary(result.message || this.t('openregister', 'Selected search trails deleted successfully'), { type: 'success' })
					// Clear selection
					this.selectedSearchTrails = []
					// Refresh the list
					await this.loadSearchTrails()
				} else {
					throw new Error(result.error || 'Deletion failed')
				}
			} catch (error) {
				console.error('Error deleting search trails:', error)
				OC.Notification.showTemporary(this.t('openregister', 'Error deleting search trails: {error}', { error: error.message }), { type: 'error' })
			}
		},
	},
}
</script>

<style scoped>
/* Specific column widths for search trail table */
.searchTermColumn {
	width: 200px;
}

.timestampColumn {
	width: 180px;
}

/* Success/failed row styling */
.viewTableRow.success {
	border-left: 4px solid var(--color-success);
}

.viewTableRow.failed {
	border-left: 4px solid var(--color-error);
}

/* Search term styling */
.searchTermColumn {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

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
