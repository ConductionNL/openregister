<template>
	<NcAppContent :show-details="sidebarOpen" @update:showDetails="toggleSidebar">
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<div class="viewHeaderTitle">
					<h1 class="viewHeaderTitleIndented">
						{{ t('openregister', 'Entities') }}
					</h1>
					<NcButton
						type="tertiary"
						:aria-label="t('openregister', 'Toggle search sidebar')"
						@click="toggleSidebar">
						<template #icon>
							<FilterVariant :size="20" />
						</template>
						{{ sidebarOpen ? t('openregister', 'Hide Filters') : t('openregister', 'Show Filters') }}
					</NcButton>
				</div>
				<p>
					{{ t('openregister', 'Manage and view detected entities from files and objects') }}
				</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span v-if="entitiesList.length" class="viewTotalCount">
						{{ t('openregister', 'Showing {showing} of {total} entities', {
							showing: entitiesList.length,
							total: totalEntities
						}) }}
					</span>
				</div>
				<div class="viewActions">
					<NcActions
						:force-name="true"
						:inline="1"
						menu-name="Actions">
						<NcActionButton
							close-after-click
							@click="refreshEntities">
							<template #icon>
								<Refresh :size="20" />
							</template>
							{{ t('openregister', 'Refresh') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Entities Table -->
			<div class="tableContainer">
				<NcLoadingIcon v-if="loading" :size="64" />

				<NcEmptyContent
					v-else-if="!entitiesList.length"
					:name="t('openregister', 'No entities found')"
					:description="t('openregister', 'No entities have been detected yet')">
					<template #icon>
						<AccountOutline :size="64" />
					</template>
				</NcEmptyContent>

				<table v-else class="entitiesTable">
					<thead>
						<tr>
							<th class="column-type">
								{{ t('openregister', 'Type') }}
							</th>
							<th class="column-value">
								{{ t('openregister', 'Value') }}
							</th>
							<th class="column-category">
								{{ t('openregister', 'Category') }}
							</th>
							<th class="column-detected">
								{{ t('openregister', 'Detected At') }}
							</th>
							<th class="column-relations">
								{{ t('openregister', 'Relations') }}
							</th>
							<th class="column-actions">
								{{ t('openregister', 'Actions') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="entity in entitiesList" :key="entity.id">
							<td class="column-type">
								<span class="badge badge-type">{{ entity.type }}</span>
							</td>
							<td class="column-value">
								<div class="entity-value-cell">
									<AccountOutline :size="20" class="entity-icon" />
									<span class="entity-value">{{ entity.value }}</span>
								</div>
							</td>
							<td class="column-category">
								<span class="badge badge-category">{{ entity.category }}</span>
							</td>
							<td class="column-detected">
								{{ formatDate(entity.detectedAt) }}
							</td>
							<td class="column-relations">
								{{ entity.relationCount || 0 }}
							</td>
							<td class="column-actions">
								<NcActions>
									<NcActionButton
										close-after-click
										@click="viewEntity(entity)">
										<template #icon>
											<EyeOutline :size="20" />
										</template>
										{{ t('openregister', 'View Details') }}
									</NcActionButton>
								</NcActions>
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Pagination -->
				<div v-if="totalEntities > limit" class="pagination">
					<NcButton
						:disabled="offset === 0"
						@click="previousPage">
						{{ t('openregister', 'Previous') }}
					</NcButton>
					<span class="pagination-info">
						{{ t('openregister', 'Page {current} of {total}', {
							current: currentPage,
							total: totalPages
						}) }}
					</span>
					<NcButton
						:disabled="offset + limit >= totalEntities"
						@click="nextPage">
						{{ t('openregister', 'Next') }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Search Sidebar -->
		<template #details>
			<EntitiesSidebar
				:search.sync="searchQuery"
				:type.sync="typeFilter"
				:category.sync="categoryFilter"
				@update:search="handleSearchUpdate"
				@update:type="handleTypeUpdate"
				@update:category="handleCategoryUpdate" />
		</template>
	</NcAppContent>
</template>

<script>
import { t } from '@nextcloud/l10n'
// TODO: Uncomment when entities API is implemented
// import { generateUrl } from '@nextcloud/router'
// import { showError } from '@nextcloud/dialogs'
// import axios from '@nextcloud/axios'

import {
	NcAppContent,
	NcActions,
	NcActionButton,
	NcButton,
	NcLoadingIcon,
	NcEmptyContent,
} from '@nextcloud/vue'

import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'

import EntitiesSidebar from '../../components/EntitiesSidebar.vue'

/**
 * Main view for managing entities
 */
export default {
	name: 'EntitiesIndex',
	components: {
		NcAppContent,
		NcActions,
		NcActionButton,
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		AccountOutline,
		Refresh,
		FilterVariant,
		EyeOutline,
		EntitiesSidebar,
	},
	data() {
		return {
			entitiesList: [],
			loading: false,
			totalEntities: 0,
			limit: 50,
			offset: 0,
			sidebarOpen: false,
			searchQuery: '',
			typeFilter: null,
			categoryFilter: null,
		}
	},
	computed: {
		/**
		 * Get current page number
		 *
		 * @return {number} Current page
		 */
		currentPage() {
			return Math.floor(this.offset / this.limit) + 1
		},

		/**
		 * Get total number of pages
		 *
		 * @return {number} Total pages
		 */
		totalPages() {
			return Math.ceil(this.totalEntities / this.limit)
		},
	},
	mounted() {
		this.loadEntities()
	},
	methods: {
		t,

		/**
		 * Toggle sidebar visibility
		 *
		 * @return {void}
		 */
		toggleSidebar() {
			this.sidebarOpen = !this.sidebarOpen
		},

		/**
		 * Handle search query update
		 *
		 * @param {string} query - Search query
		 * @return {void}
		 */
		handleSearchUpdate(query) {
			this.searchQuery = query
			this.offset = 0
			this.loadEntities()
		},

		/**
		 * Handle type filter update
		 *
		 * @param {string|null} type - Type filter
		 * @return {void}
		 */
		handleTypeUpdate(type) {
			this.typeFilter = type
			this.offset = 0
			this.loadEntities()
		},

		/**
		 * Handle category filter update
		 *
		 * @param {string|null} category - Category filter
		 * @return {void}
		 */
		handleCategoryUpdate(category) {
			this.categoryFilter = category
			this.offset = 0
			this.loadEntities()
		},

		/**
		 * Load entities from the API
		 *
		 * @return {Promise<void>}
		 */
		async loadEntities() {
			this.loading = true
			try {
				// TODO: Replace with actual entities API endpoint when available
				// For now, show empty state
				this.entitiesList = []
				this.totalEntities = 0

				// Uncomment when API is available:
				// const params = {
				//     limit: this.limit,
				//     offset: this.offset,
				// }
				//
				// if (this.searchQuery) {
				//     params.search = this.searchQuery
				// }
				//
				// if (this.typeFilter) {
				//     params.type = this.typeFilter
				// }
				//
				// if (this.categoryFilter) {
				//     params.category = this.categoryFilter
				// }
				//
				// const response = await axios.get(
				//     generateUrl('/apps/openregister/api/entities'),
				//     { params },
				// )
				//
				// if (response.data.success) {
				//     this.entitiesList = response.data.data
				//     this.totalEntities = response.data.count || this.entitiesList.length
				// }
			} catch (error) {
				// TODO: Uncomment when API is implemented
				// console.error('Failed to load entities:', error)
				// showError(t('openregister', 'Failed to load entities'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Refresh the entities list
		 *
		 * @return {void}
		 */
		refreshEntities() {
			this.loadEntities()
		},

		/**
		 * Go to previous page
		 *
		 * @return {void}
		 */
		previousPage() {
			if (this.offset > 0) {
				this.offset = Math.max(0, this.offset - this.limit)
				this.loadEntities()
			}
		},

		/**
		 * Go to next page
		 *
		 * @return {void}
		 */
		nextPage() {
			if (this.offset + this.limit < this.totalEntities) {
				this.offset += this.limit
				this.loadEntities()
			}
		},

		/**
		 * View entity details
		 *
		 * @param {object} entity - Entity object
		 * @return {void}
		 */
		viewEntity(entity) {
			// TODO: Navigate to entity details page when available
			// console.log('View entity:', entity)
		},

		/**
		 * Format date for display
		 *
		 * @param {string} date - Date string
		 * @return {string} Formatted date
		 */
		formatDate(date) {
			if (!date) return '-'
			return new Date(date).toLocaleString()
		},
	},
}
</script>

<style scoped>
.viewContainer {
	padding: 20px;
	max-width: 100%;
}

.viewHeader {
	margin-bottom: 20px;
}

.viewHeaderTitle {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 8px;
}

.viewHeaderTitleIndented {
	margin: 0;
	font-size: 28px;
	font-weight: 600;
}

.viewHeader p {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.viewActionsBar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.viewInfo {
	display: flex;
	gap: 12px;
	align-items: center;
}

.viewTotalCount {
	font-weight: 600;
}

.viewActions {
	display: flex;
	gap: 8px;
}

.tableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.entitiesTable {
	width: 100%;
	border-collapse: collapse;
}

.entitiesTable thead {
	background: var(--color-background-hover);
	border-bottom: 2px solid var(--color-border);
}

.entitiesTable th {
	padding: 12px 16px;
	text-align: left;
	font-weight: 600;
	white-space: nowrap;
}

.entitiesTable td {
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
}

.entitiesTable tbody tr:hover {
	background: var(--color-background-hover);
}

.entity-value-cell {
	display: flex;
	align-items: center;
	gap: 8px;
}

.entity-icon {
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.entity-value {
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.badge {
	display: inline-block;
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
}

.badge-type {
	background: var(--color-primary-light);
	color: var(--color-primary-element);
}

.badge-category {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.column-type {
	width: 120px;
}

.column-value {
	min-width: 200px;
}

.column-category {
	width: 150px;
}

.column-detected {
	width: 180px;
}

.column-relations {
	width: 100px;
	text-align: center;
}

.column-actions {
	width: 50px;
}

.pagination {
	display: flex;
	justify-content: center;
	align-items: center;
	gap: 16px;
	padding: 20px;
	border-top: 1px solid var(--color-border);
}

.pagination-info {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}
</style>
