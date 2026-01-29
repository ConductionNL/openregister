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
							showing: paginatedEntities.length,
							total: totalEntities
						}) }}
					</span>
					<span v-if="selectedEntities.length > 0" class="viewIndicator">
						({{ t('openregister', '{count} selected', { count: selectedEntities.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<div class="viewModeSwitchContainer">
						<NcCheckboxRadioSwitch
							v-model="viewMode"
							v-tooltip="'See entities as cards'"
							:button-variant="true"
							value="cards"
							name="view_mode_radio"
							type="radio"
							button-variant-grouped="horizontal">
							Cards
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="viewMode"
							v-tooltip="'See entities as a table'"
							:button-variant="true"
							value="table"
							name="view_mode_radio"
							type="radio"
							button-variant-grouped="horizontal">
							Table
						</NcCheckboxRadioSwitch>
					</div>

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

			<!-- Loading State -->
			<NcLoadingIcon v-if="loading" :size="64" />

			<!-- Empty State -->
			<NcEmptyContent
				v-else-if="!entitiesList.length"
				:name="t('openregister', 'No entities found')"
				:description="t('openregister', 'No entities have been detected yet')">
				<template #icon>
					<AccountOutline :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Content -->
			<div v-else>
				<!-- Cards View -->
				<template v-if="viewMode === 'cards'">
					<div class="cardGrid">
						<div v-for="entity in paginatedEntities"
							:key="entity.id"
							class="card"
							@click="viewEntity(entity)">
							<div class="cardHeader">
								<h2>
									<AccountOutline :size="20" />
									{{ entity.value }}
									<span class="badge badge-type">{{ entity.type }}</span>
								</h2>
								<NcActions :primary="true" menu-name="Actions">
									<template #icon>
										<DotsHorizontal :size="20" />
									</template>
									<NcActionButton
										close-after-click
										@click.stop="viewEntity(entity)">
										<template #icon>
											<EyeOutline :size="20" />
										</template>
										View Details
									</NcActionButton>
								</NcActions>
							</div>

							<!-- Entity Info -->
							<table class="statisticsTable entityStats">
								<tbody>
									<tr>
										<td><strong>{{ t('openregister', 'Category') }}</strong></td>
										<td><span class="badge badge-category">{{ entity.category }}</span></td>
									</tr>
									<tr>
										<td><strong>{{ t('openregister', 'Detected At') }}</strong></td>
										<td>{{ formatDate(entity.detectedAt) }}</td>
									</tr>
									<tr>
										<td><strong>{{ t('openregister', 'Relations') }}</strong></td>
										<td>{{ entity.relationCount || 0 }}</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</template>

				<!-- Table View -->
				<template v-else>
					<div class="viewTableContainer">
						<table class="viewTable">
							<thead>
								<tr>
									<th class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="allSelected"
											:indeterminate="someSelected"
											@update:checked="toggleSelectAll" />
									</th>
									<th>{{ t('openregister', 'Value') }}</th>
									<th>{{ t('openregister', 'Type') }}</th>
									<th>{{ t('openregister', 'Category') }}</th>
									<th>{{ t('openregister', 'Detected At') }}</th>
									<th>{{ t('openregister', 'Relations') }}</th>
									<th class="tableColumnActions">
										{{ t('openregister', 'Actions') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="entity in paginatedEntities"
									:key="entity.id"
									class="viewTableRow"
									:class="{ viewTableRowSelected: selectedEntities.includes(entity.id) }">
									<td class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="selectedEntities.includes(entity.id)"
											@update:checked="(checked) => toggleEntitySelection(entity.id, checked)" />
									</td>
									<td class="tableColumnTitle">
										<div class="entity-value-cell">
											<AccountOutline :size="20" class="entity-icon" />
											<strong>{{ entity.value }}</strong>
										</div>
									</td>
									<td>
										<span class="badge badge-type">{{ entity.type }}</span>
									</td>
									<td>
										<span class="badge badge-category">{{ entity.category }}</span>
									</td>
									<td>{{ formatDate(entity.detectedAt) }}</td>
									<td>{{ entity.relationCount || 0 }}</td>
									<td class="tableColumnActions">
										<NcActions :primary="false">
											<template #icon>
												<DotsHorizontal :size="20" />
											</template>
											<NcActionButton
												close-after-click
												@click="viewEntity(entity)">
												<template #icon>
													<EyeOutline :size="20" />
												</template>
												View Details
											</NcActionButton>
										</NcActions>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</template>
			</div>

			<!-- Pagination -->
			<PaginationComponent
				v-if="entitiesList.length > 0"
				:current-page="currentPage"
				:total-pages="totalPages"
				:total-items="totalEntities"
				:current-page-size="limit"
				:min-items-to-show="10"
				@page-changed="onPageChanged"
				@page-size-changed="onPageSizeChanged" />
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
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'

import {
	NcAppContent,
	NcActions,
	NcActionButton,
	NcButton,
	NcLoadingIcon,
	NcEmptyContent,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'

import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'

import EntitiesSidebar from '../../components/EntitiesSidebar.vue'
import PaginationComponent from '../../components/PaginationComponent.vue'

/**
 * Main view for managing entities
 *
 * @package OpenRegister
 * @category View
 * @author Ruben Linde <ruben@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license EUPL-1.2
 * @version 1.0.0
 * @link https://github.com/ConductionNL/openregister
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
		NcCheckboxRadioSwitch,
		AccountOutline,
		Refresh,
		FilterVariant,
		EyeOutline,
		DotsHorizontal,
		EntitiesSidebar,
		PaginationComponent,
	},
	data() {
		return {
			entitiesList: [],
			loading: false,
			totalEntities: 0,
			limit: 20,
			currentPage: 1,
			sidebarOpen: false,
			searchQuery: '',
			typeFilter: null,
			categoryFilter: null,
			viewMode: 'table',
			selectedEntities: [],
		}
	},
	computed: {
		/**
		 * Get total number of pages
		 *
		 * @return {number} Total pages
		 */
		totalPages() {
			return Math.ceil(this.totalEntities / this.limit)
		},

		/**
		 * Get paginated entities for current page
		 *
		 * @return {Array} Paginated entities
		 */
		paginatedEntities() {
			const start = (this.currentPage - 1) * this.limit
			const end = start + this.limit
			return this.entitiesList.slice(start, end)
		},

		/**
		 * Check if all entities are selected
		 *
		 * @return {boolean} True if all selected
		 */
		allSelected() {
			return this.entitiesList.length > 0 && this.entitiesList.every(entity => this.selectedEntities.includes(entity.id))
		},

		/**
		 * Check if some entities are selected
		 *
		 * @return {boolean} True if some selected
		 */
		someSelected() {
			return this.selectedEntities.length > 0 && !this.allSelected
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
			this.currentPage = 1
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
			this.currentPage = 1
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
			this.currentPage = 1
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
				const params = {
					limit: this.limit,
					offset: (this.currentPage - 1) * this.limit,
				}

				if (this.searchQuery) {
					params.search = this.searchQuery
				}

				if (this.typeFilter) {
					params.type = this.typeFilter
				}

				if (this.categoryFilter) {
					params.category = this.categoryFilter
				}

				const response = await axios.get(
					generateUrl('/apps/openregister/api/entities'),
					{ params },
				)

				if (response.data.success) {
					this.entitiesList = response.data.data
					this.totalEntities = response.data.count || this.entitiesList.length
				}
			} catch (error) {
				console.error('Failed to load entities:', error)
				showError(t('openregister', 'Failed to load entities'))
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
		 * Handle page change event
		 *
		 * @param {number} page - New page number
		 * @return {void}
		 */
		onPageChanged(page) {
			this.currentPage = page
			this.loadEntities()
		},

		/**
		 * Handle page size change event
		 *
		 * @param {number} pageSize - New page size
		 * @return {void}
		 */
		onPageSizeChanged(pageSize) {
			this.limit = pageSize
			this.currentPage = 1
			this.loadEntities()
		},

		/**
		 * Toggle select all entities
		 *
		 * @param {boolean} checked - Whether to select all
		 * @return {void}
		 */
		toggleSelectAll(checked) {
			if (checked) {
				this.selectedEntities = this.entitiesList.map(entity => entity.id)
			} else {
				this.selectedEntities = []
			}
		},

		/**
		 * Toggle entity selection
		 *
		 * @param {number} entityId - Entity ID
		 * @param {boolean} checked - Whether entity is selected
		 * @return {void}
		 */
		toggleEntitySelection(entityId, checked) {
			if (checked) {
				this.selectedEntities.push(entityId)
			} else {
				this.selectedEntities = this.selectedEntities.filter(id => id !== entityId)
			}
		},

		/**
		 * View entity details
		 *
		 * @param {object} entity - Entity object
		 * @return {void}
		 */
		viewEntity(entity) {
			this.$router.push({ name: 'entityDetails', params: { id: entity.id } })
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

<style scoped lang="scss">
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

.viewIndicator {
	color: var(--color-text-maxcontrast);
}

.viewActions {
	display: flex;
	gap: 8px;
}

/* Cards Grid */
.cardGrid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
	gap: 20px;
	margin-bottom: 20px;
}

.card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
	transition: box-shadow 0.2s ease;
	cursor: pointer;
}

.card:hover {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.cardHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 16px;
	border-bottom: 1px solid var(--color-border);
}

.cardHeader h2 {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
	font-size: 16px;
	font-weight: 600;
	flex-wrap: wrap;
}

/* Entity Stats Table */
.entityStats {
	width: 100%;
	border-collapse: collapse;
}

.entityStats tbody tr {
	border-bottom: 1px solid var(--color-border);
}

.entityStats tbody tr:last-child {
	border-bottom: none;
}

.entityStats td {
	padding: 12px 16px;
}

.entityStats td:first-child {
	width: 40%;
	color: var(--color-text-maxcontrast);
}

/* Table View */
.viewTableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	overflow: hidden;
	margin-bottom: 20px;
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

/* Badges */
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

/* Column sizes for table view */
.tableColumnCheckbox {
	width: 50px;
}

.tableColumnTitle {
	min-width: 200px;
}

.tableColumnActions {
	width: 50px;
}
</style>
