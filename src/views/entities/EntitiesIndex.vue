<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<FilterToggleHeader
				:title="t('openregister', 'Entities')"
				:description="
					t(
						'openregister',
						'Manage and view detected entities from files and objects',
					)
				"
				:sidebarOpen="sidebarOpen"
				@toggle="toggleSidebar" />

			<!-- Actions Bar -->
			<ListActionsBar
				:summary="
					entitiesList.length
						? t(
								'openregister',
								'Showing {showing} of {total} entities',
								{
									showing: paginatedEntities.length,
									total: totalEntities,
								},
							)
						: ''
				"
				:selectedCount="selectedEntities.length"
				:actions="[refreshAction(refreshEntities)]">
				<ViewModeSwitch
					v-model="viewMode"
					cardsTitle="See entities as cards"
					tableTitle="See entities as a table" />
			</ListActionsBar>

			<!-- Loading State -->
			<NcLoadingIcon v-if="loading" :size="64" />

			<!-- Empty State -->
			<NcEmptyContent
				v-else-if="!entitiesList.length"
				:name="t('openregister', 'No entities found')"
				:description="
					t('openregister', 'No entities have been detected yet')
				">
				<template #icon>
					<AccountOutline :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Content -->
			<div v-else>
				<!-- Cards View -->
				<template v-if="viewMode === 'cards'">
					<div class="cardGrid">
						<div
							v-for="entity in paginatedEntities"
							:key="entity.id"
							class="card">
							<div class="cardHeader">
								<h2>
									<AccountOutline :size="20" />
									<!-- The activator is a real <button>; its ::after overlay makes the
										whole card clickable with the mouse without turning the card into
										a role="button" (which would hide the heading, the stats table and
										the actions menu from assistive technology). -->
									<button
										type="button"
										class="cardActivator"
										@click="viewEntity(entity)">
										{{ entity.value }}
									</button>
									<span class="badge badge-type">{{
										entity.type
									}}</span>
								</h2>
								<ActionMenu
									class="cardActions"
									primary
									:actions="entityActions(entity)" />
							</div>

							<!-- Entity Info -->
							<table class="statisticsTable entityStats">
								<tbody>
									<tr>
										<th scope="row">
											<strong>{{
												t('openregister', 'Category')
											}}</strong>
										</th>
										<td>
											<span class="badge badge-category">{{
												entity.category
											}}</span>
										</td>
									</tr>
									<tr>
										<th scope="row">
											<strong>{{
												t('openregister', 'Detected At')
											}}</strong>
										</th>
										<td>{{ formatDate(entity.detectedAt) }}</td>
									</tr>
									<tr>
										<th scope="row">
											<strong>{{
												t('openregister', 'Relations')
											}}</strong>
										</th>
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
											:modelValue="allSelected"
											:indeterminate="someSelected"
											:aria-label="
												t(
													'openregister',
													'Select all entities',
												)
											"
											@update:modelValue="toggleSelectAll" />
									</th>
									<th scope="col">
										{{ t('openregister', 'Value') }}
									</th>
									<th scope="col">
										{{ t('openregister', 'Type') }}
									</th>
									<th scope="col">
										{{ t('openregister', 'Category') }}
									</th>
									<th scope="col">
										{{ t('openregister', 'Detected At') }}
									</th>
									<th scope="col">
										{{ t('openregister', 'Relations') }}
									</th>
									<th scope="col" class="tableColumnActions">
										{{ t('openregister', 'Actions') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr
									v-for="entity in paginatedEntities"
									:key="entity.id"
									class="viewTableRow"
									:class="{
										viewTableRowSelected:
											selectedEntities.includes(entity.id),
									}">
									<td class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:modelValue="
												selectedEntities.includes(entity.id)
											"
											:aria-label="
												t(
													'openregister',
													'Select entity {value}',
													{ value: entity.value },
												)
											"
											@update:modelValue="
												(checked) =>
													toggleEntitySelection(
														entity.id,
														checked,
													)
											" />
									</td>
									<td class="tableColumnTitle">
										<div class="entity-value-cell">
											<AccountOutline
												:size="20"
												class="entity-icon" />
											<strong>{{ entity.value }}</strong>
										</div>
									</td>
									<td>
										<span class="badge badge-type">{{
											entity.type
										}}</span>
									</td>
									<td>
										<span class="badge badge-category">{{
											entity.category
										}}</span>
									</td>
									<td>{{ formatDate(entity.detectedAt) }}</td>
									<td>{{ entity.relationCount || 0 }}</td>
									<td class="tableColumnActions">
										<ActionMenu
											:actions="entityActions(entity)" />
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
				:currentPage="currentPage"
				:totalPages="totalPages"
				:totalItems="totalEntities"
				:currentPageSize="limit"
				:minItemsToShow="10"
				@pageChanged="onPageChanged"
				@pageSizeChanged="onPageSizeChanged" />
		</div>
	</NcAppContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppContent,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
	NcLoadingIcon,
} from '@nextcloud/vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import PaginationComponent from '../../components/PaginationComponent.vue'
import ActionMenu from '../../components/shared/ActionMenu.vue'
import FilterToggleHeader from '../../components/shared/FilterToggleHeader.vue'
import ListActionsBar from '../../components/shared/ListActionsBar.vue'
import ViewModeSwitch from '../../components/shared/ViewModeSwitch.vue'
import eventBus from '../../eventBus.js'
import { displayDateTime } from '../../services/displayDateTime.js'
import { refreshAction } from '../../services/menuActions.js'
import { navigationStore } from '../../store/store.js'

/**
 * Main view for managing entities
 *
 * @package
 * @category View
 * @author Ruben Linde <ruben@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license EUPL-1.2
 * @version 1.0.0
 * @see https://github.com/ConductionNL/openregister
 */
export default {
	name: 'EntitiesIndex',
	components: {
		ViewModeSwitch,
		ActionMenu,
		ListActionsBar,
		FilterToggleHeader,
		NcAppContent,
		NcLoadingIcon,
		NcEmptyContent,
		NcCheckboxRadioSwitch,
		AccountOutline,
		PaginationComponent,
	},

	data() {
		return {
			entitiesList: [],
			loading: false,
			totalEntities: 0,
			limit: 20,
			currentPage: 1,
			searchQuery: '',
			typeFilter: null,
			categoryFilter: null,
			viewMode: 'table',
			selectedEntities: [],
		}
	},

	computed: {
		/**
		 * Whether the entities filter sidebar is currently open (driven by navigationStore).
		 *
		 * @spec exclude UI sidebar open-state proxy, driven by navigationStore
		 * @return {boolean}
		 */
		sidebarOpen() {
			return navigationStore.sidebarState.entities
		},

		/**
		 * Get total number of pages
		 *
		 * @spec exclude list-view pagination total-pages helper (computed)
		 * @return {number} Total pages
		 */
		totalPages() {
			return Math.ceil(this.totalEntities / this.limit)
		},

		/**
		 * Get paginated entities for current page
		 *
		 * @spec exclude list-view client-side pagination slice (computed)
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
		 * @spec exclude list-view select-all checkbox state (computed)
		 * @return {boolean} True if all selected
		 */
		allSelected() {
			return (
				this.entitiesList.length > 0
				&& this.entitiesList.every((entity) =>
					this.selectedEntities.includes(entity.id),
				)
			)
		},

		/**
		 * Check if some entities are selected
		 *
		 * @spec exclude list-view indeterminate-selection checkbox state (computed)
		 * @return {boolean} True if some selected
		 */
		someSelected() {
			return this.selectedEntities.length > 0 && !this.allSelected
		},
	},

	/**
	 * @spec exclude Lifecycle plumbing; loads entity list and wires root event bus listeners from EntitiesSideBar.
	 */
	mounted() {
		this.loadEntities()
		// Listen for filter changes emitted by EntitiesSideBar via the root event bus.
		eventBus.on('entities-search-changed', this.handleSearchUpdate)
		eventBus.on('entities-type-changed', this.handleTypeUpdate)
		eventBus.on('entities-category-changed', this.handleCategoryUpdate)
	},

	/**
	 * @spec exclude Lifecycle teardown; removes root event bus listeners to prevent memory leaks.
	 */
	beforeUnmount() {
		eventBus.off('entities-search-changed', this.handleSearchUpdate)
		eventBus.off('entities-type-changed', this.handleTypeUpdate)
		eventBus.off('entities-category-changed', this.handleCategoryUpdate)
	},

	methods: {
		refreshAction,

		/**
		 * The menu of an entity's card and table row.
		 *
		 * @spec exclude Client-side menu composition.
		 * @param {object} entity The entity
		 * @return {Array<object>} The ActionMenu entries
		 */
		entityActions(entity) {
			return [
				{
					label: 'View Details',
					icon: EyeOutline,
					run: () => this.viewEntity(entity),
				},
			]
		},

		t,

		/**
		 * Toggle the entities filter sidebar via navigationStore.
		 *
		 * @spec openspec/specs/admin-list-views/spec.md
		 * @return {void}
		 */
		toggleSidebar() {
			navigationStore.setSidebarState(
				'entities',
				!navigationStore.sidebarState.entities,
			)
		},

		/**
		 * Handle search query update from EntitiesSideBar event bus emission.
		 *
		 * @spec exclude list-view search-input handler; resets page and reloads (linked-entity-types contract)
		 * @param {string} query - Search query
		 * @return {void}
		 */
		handleSearchUpdate(query) {
			this.searchQuery = query
			this.currentPage = 1
			this.loadEntities()
		},

		/**
		 * Handle type filter update from EntitiesSideBar event bus emission.
		 *
		 * @spec exclude list-view filter-input handler; resets page and reloads
		 * @param {string|null} type - Type filter
		 * @return {void}
		 */
		handleTypeUpdate(type) {
			this.typeFilter = type
			this.currentPage = 1
			this.loadEntities()
		},

		/**
		 * Handle category filter update from EntitiesSideBar event bus emission.
		 *
		 * @spec exclude list-view filter-input handler; resets page and reloads
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
		 * @spec exclude list-view API fetch plumbing (linked-entity-types contract)
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
					this.totalEntities =
						response.data.count || this.entitiesList.length
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
		 * @spec exclude list-view manual refresh plumbing
		 * @return {void}
		 */
		refreshEntities() {
			this.loadEntities()
		},

		/**
		 * Handle page change event
		 *
		 * @spec exclude list-view pagination page-change handler
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
		 * @spec exclude list-view pagination page-size-change handler
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
		 * @spec exclude list-view select-all checkbox plumbing
		 * @param {boolean} checked - Whether to select all
		 * @return {void}
		 */
		toggleSelectAll(checked) {
			if (checked) {
				this.selectedEntities = this.entitiesList.map((entity) => entity.id)
			} else {
				this.selectedEntities = []
			}
		},

		/**
		 * Toggle entity selection
		 *
		 * @spec exclude list-view single-row selection toggle plumbing
		 * @param {number} entityId - Entity ID
		 * @param {boolean} checked - Whether entity is selected
		 * @return {void}
		 */
		toggleEntitySelection(entityId, checked) {
			if (checked) {
				this.selectedEntities.push(entityId)
			} else {
				this.selectedEntities = this.selectedEntities.filter(
					(id) => id !== entityId,
				)
			}
		},

		/**
		 * View entity details
		 *
		 * @spec exclude list-view router-navigation plumbing to the entity detail page
		 * @param {object} entity - Entity object
		 * @return {void}
		 */
		viewEntity(entity) {
			this.$router.push({ name: 'entityDetails', params: { id: entity.id } })
		},

		formatDate: displayDateTime,
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/views.scss' as *;

@include view-container-2;

/* Cards Grid */
.cardGrid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
	gap: 20px;
	margin-bottom: 20px;
}

.card {
	position: relative;
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

/* Real button that carries the card's action. The ::after overlay restores
   whole-card mouse clickability while keyboard users get a genuine button. */
.cardActivator {
	appearance: none;
	background: none;
	border: none;
	padding: 0;
	margin: 0;
	font: inherit;
	color: inherit;
	text-align: left;
	cursor: pointer;
}

.cardActivator::after {
	content: '';
	position: absolute;
	inset: 0;
}

.cardActivator:focus-visible::after {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
	border-radius: var(--border-radius-large);
}

/* Keep the actions menu above the activator overlay so it stays clickable. */
.cardActions {
	position: relative;
	z-index: 1;
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

.entityStats td,
.entityStats th {
	padding: 12px 16px;
	text-align: left;
	font-weight: normal;
}

.entityStats td:first-child,
.entityStats th:first-child {
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
@include badge-rules-2;

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

@media (prefers-reduced-motion: reduce) {
	.card {
		transition: none;
	}
}
</style>
