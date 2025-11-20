<script setup>
import { schemaStore, navigationStore, configurationStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ t('openregister', 'Schemas') }}
				</h1>
				<p>{{ t('openregister', 'Manage your data schemas and their properties') }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span class="viewTotalCount">
						{{ t('openregister', 'Showing {showing} of {total} schemas', { showing: schemaStore.schemaList.length, total: schemaStore.schemaList.length }) }}
					</span>
					<span v-if="selectedSchemas.length > 0" class="viewIndicator">
						({{ t('openregister', '{count} selected', { count: selectedSchemas.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<div class="viewModeSwitchContainer">
						<NcCheckboxRadioSwitch
							v-model="schemaStore.viewMode"
							v-tooltip="'See schemas as cards'"
							:button-variant="true"
							value="cards"
							name="view_mode_radio"
							type="radio"
							button-variant-grouped="horizontal">
							Cards
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-model="schemaStore.viewMode"
							v-tooltip="'See schemas as a table'"
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
						:inline="2"
						menu-name="Actions">
						<NcActionButton
							:primary="true"
							close-after-click
							@click="schemaStore.setSchemaItem(null); navigationStore.setModal('editSchema')">
							<template #icon>
								<Plus :size="20" />
							</template>
							Add Schema
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="schemaStore.refreshSchemaList()">
							<template #icon>
								<Refresh :size="20" />
							</template>
							Refresh
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Empty State -->
			<NcEmptyContent v-if="!schemaStore.schemaList.length"
				:name="t('openregister', 'No schemas found')"
				:description="t('openregister', 'No schemas are available.')">
				<template #icon>
					<FileTreeOutline :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Content -->
			<div v-else>
				<template v-if="schemaStore.viewMode === 'cards'">
					<div class="cardGrid">
						<div v-for="schema in paginatedSchemas"
							:key="schema.id"
							class="card"
							:class="{
								'card--in-use': hasObjects(schema),
								'card--configuration': isManagedByExternalConfig(schema),
								'card--local': isManagedByLocalConfig(schema)
							}">
							<div class="cardHeader">
								<h2>
									<FileTreeOutline :size="20" />
									{{ schema.title }}
									<span v-if="schema.extend" class="statusPill statusPill--alert">
										{{ t('openregister', 'Extended') }}
									</span>
									<span v-if="hasObjects(schema)" class="statusPill statusPill--success">
										{{ t('openregister', 'In use') }}
									</span>
									<span v-if="isManagedByExternalConfig(schema)" class="managedBadge managedBadge--external">
										<CogOutline :size="16" />
										{{ t('openregister', 'Managed') }}
									</span>
									<span v-else-if="isManagedByLocalConfig(schema)" class="managedBadge managedBadge--local">
										<CogOutline :size="16" />
										{{ t('openregister', 'Local') }}
									</span>
								</h2>
								<NcActions :primary="true" menu-name="Actions">
									<template #icon>
										<DotsHorizontal :size="20" />
									</template>
									<NcActionButton
										v-tooltip="isManagedByExternalConfig(schema) ? 'Cannot edit: This schema is managed by external configuration ' + getManagingConfiguration(schema).title : ''"
										close-after-click
										:disabled="isManagedByExternalConfig(schema)"
										@click="schemaStore.setSchemaItem(schema); navigationStore.setModal('editSchema')">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton v-tooltip="schema.stats?.objects?.total > 0 ? 'Cannot delete: objects are still attached' : ''"
										close-after-click
										:disabled="schema.stats?.objects?.total > 0"
										@click="schemaStore.setSchemaItem(schema); navigationStore.setDialog('deleteSchema')">
										<template #icon>
											<TrashCanOutline :size="20" />
										</template>
										Delete
									</NcActionButton>
								</NcActions>
							</div>

							<!-- Schema Description -->
							<div class="schemaDescription"
								:class="{ 'schemaDescription--expanded': isDescriptionExpanded(schema.id), 'schemaDescription--empty': !schema.description }"
								@click="schema.description ? toggleDescriptionExpanded(schema.id) : null">
								{{ schema.description || t('openregister', 'No description found') }}
							</div>

							<!-- Show properties table -->
							<table class="statisticsTable schemaStats">
								<thead>
									<tr>
										<th>{{ t('openregister', 'Name') }}</th>
										<th>{{ t('openregister', 'Type') }}</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="(property, key, index) in getDisplayedProperties(schema)" :key="key">
										<td>{{ key }} <span v-if="isPropertyRequired(schema, key)" class="required-indicator">({{ t('openregister', 'required') }})</span></td>
										<td>{{ property.type }}</td>
									</tr>
									<tr v-if="!Object.keys(schema.properties || {}).length">
										<td colspan="2" class="emptyText">
											{{ t('openregister', 'No properties found') }}
										</td>
									</tr>
								</tbody>
							</table>

							<!-- View More Button -->
							<div v-if="getRemainingPropertiesCount(schema) > 0" class="viewMoreContainer">
								<NcButton
									type="secondary"
									@click="toggleSchemaExpanded(schema.id)">
									<template #icon>
										<ChevronDown v-if="!isSchemaExpanded(schema.id)" :size="20" />
										<ChevronUp v-else :size="20" />
									</template>
									{{ isSchemaExpanded(schema.id)
										? t('openregister', 'Show less')
										: t('openregister', 'View {count} more', { count: getRemainingPropertiesCount(schema) })
									}}
								</NcButton>
							</div>
						</div>
					</div>
				</template>
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
									<th>{{ t('openregister', 'Title') }}</th>
									<th>{{ t('openregister', 'Properties') }}</th>
									<th>{{ t('openregister', 'Created') }}</th>
									<th>{{ t('openregister', 'Updated') }}</th>
									<th class="tableColumnActions">
										{{ t('openregister', 'Actions') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="schema in paginatedSchemas"
									:key="schema.id"
									class="viewTableRow"
									:class="{
										viewTableRowSelected: selectedSchemas.includes(schema.id),
										'viewTableRow--in-use': hasObjects(schema),
										'viewTableRow--configuration': isManagedByExternalConfig(schema),
										'viewTableRow--local': isManagedByLocalConfig(schema)
									}">
									<td class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="selectedSchemas.includes(schema.id)"
											@update:checked="(checked) => toggleSchemaSelection(schema.id, checked)" />
									</td>
									<td class="tableColumnTitle">
										<div class="titleContent">
											<div class="titleWithBadges">
												<strong>{{ schema.title }}</strong>
												<span v-if="schema.extend" class="statusPill statusPill--alert">
													{{ t('openregister', 'Extended') }}
												</span>
												<span v-if="hasObjects(schema)" class="statusPill statusPill--success">
													{{ t('openregister', 'In use') }}
												</span>
												<span v-if="isManagedByExternalConfig(schema)" class="managedBadge managedBadge--external">
													<CogOutline :size="16" />
													{{ t('openregister', 'Managed') }}
												</span>
												<span v-else-if="isManagedByLocalConfig(schema)" class="managedBadge managedBadge--local">
													<CogOutline :size="16" />
													{{ t('openregister', 'Local') }}
												</span>
											</div>
											<span v-if="schema.description" class="textDescription textEllipsis">{{ schema.description }}</span>
										</div>
									</td>
									<td>{{ Object.keys(schema.properties || {}).length }}</td>
									<td>{{ schema.created ? new Date(schema.created).toLocaleDateString({day: '2-digit', month: '2-digit', year: 'numeric'}) + ', ' + new Date(schema.created).toLocaleTimeString({hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '-' }}</td>
									<td>{{ schema.updated ? new Date(schema.updated).toLocaleDateString({day: '2-digit', month: '2-digit', year: 'numeric'}) + ', ' + new Date(schema.updated).toLocaleTimeString({hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '-' }}</td>
									<td class="tableColumnActions">
										<NcActions :primary="false">
											<template #icon>
												<DotsHorizontal :size="20" />
											</template>
											<NcActionButton
												v-tooltip="isManagedByExternalConfig(schema) ? 'Cannot edit: This schema is managed by external configuration ' + getManagingConfiguration(schema).title : ''"
												close-after-click
												:disabled="isManagedByExternalConfig(schema)"
												@click="schemaStore.setSchemaItem(schema); navigationStore.setModal('editSchema')">
												<template #icon>
													<Pencil :size="20" />
												</template>
												Edit
											</NcActionButton>
											<NcActionButton v-tooltip="schema.stats?.objects?.total > 0 ? 'Cannot delete: objects are still attached' : ''"
												close-after-click
												:disabled="schema.stats?.objects?.total > 0"
												@click="schemaStore.setSchemaItem(schema); navigationStore.setDialog('deleteSchema')">
												<template #icon>
													<TrashCanOutline :size="20" />
												</template>
												Delete
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
				v-if="schemaStore.schemaList.length > 0"
				:current-page="schemaStore.pagination.page || 1"
				:total-pages="Math.ceil(schemaStore.schemaList.length / (schemaStore.pagination.limit || 20))"
				:total-items="schemaStore.schemaList.length"
				:current-page-size="schemaStore.pagination.limit || 20"
				:min-items-to-show="10"
				@page-changed="onPageChanged"
				@page-size-changed="onPageSizeChanged" />
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcActions, NcActionButton, NcCheckboxRadioSwitch, NcButton } from '@nextcloud/vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'

import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'SchemasIndex',
	components: {
		NcCheckboxRadioSwitch,
		NcAppContent,
		NcEmptyContent,
		NcActions,
		NcActionButton,
		NcButton,
		FileTreeOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Refresh,
		Plus,
		ChevronDown,
		ChevronUp,
		CogOutline,
		PaginationComponent,
	},
	data() {
		return {
			selectedSchemas: [],
			expandedSchemas: [], // Track which schemas are expanded
			expandedDescriptions: [], // Track which descriptions are expanded
		}
	},
	computed: {
		allSelected() {
			return schemaStore.schemaList.length > 0 && schemaStore.schemaList.every(schema => this.selectedSchemas.includes(schema.id))
		},
		someSelected() {
			return this.selectedSchemas.length > 0 && !this.allSelected
		},
		paginatedSchemas() {
			const start = ((schemaStore.pagination.page || 1) - 1) * (schemaStore.pagination.limit || 20)
			const end = start + (schemaStore.pagination.limit || 20)
			return schemaStore.schemaList.slice(start, end)
		},
		sortedProperties() {
			return (schema) => {
				const properties = schema.properties || {}
				return Object.entries(properties)
					.sort(([keyA, propA], [keyB, propB]) => {
						const orderA = propA.order || 0
						const orderB = propB.order || 0
						if (orderA > 0 && orderB > 0) {
							return orderA - orderB
						}
						if (orderA > 0) return -1
						if (orderB > 0) return 1
						const createdA = propA.created || ''
						const createdB = propB.created || ''
						return createdA.localeCompare(createdB)
					})
					.reduce((acc, [key, value]) => {
						acc[key] = value
						return acc
					}, {})
			}
		},
	},
	async mounted() {
		try {
			// Load schemas and configurations in parallel
			await Promise.all([
				schemaStore.refreshSchemaList(),
				configurationStore.refreshConfigurationList(),
			])
		} catch (error) {
			console.error('Failed to load data:', error)
		}
	},
	methods: {
		/**
		 * Check if a property is required
		 *
		 * @param {object} schema - Schema object
		 * @param {string} key - Property key
		 * @return {boolean} True if property is required
		 */
		isPropertyRequired(schema, key) {
			return schema.required && schema.required.includes(key)
		},

		/**
		 * Check if a schema is expanded
		 *
		 * @param {number} schemaId - Schema ID
		 * @return {boolean} True if schema is expanded
		 */
		isSchemaExpanded(schemaId) {
			return this.expandedSchemas.includes(schemaId)
		},

		/**
		 * Toggle schema expanded state
		 *
		 * @param {number} schemaId - Schema ID
		 * @return {void}
		 */
		toggleSchemaExpanded(schemaId) {
			const index = this.expandedSchemas.indexOf(schemaId)
			if (index > -1) {
				this.expandedSchemas.splice(index, 1)
			} else {
				this.expandedSchemas.push(schemaId)
			}
		},

		/**
		 * Check if a description is expanded
		 *
		 * @param {number} schemaId - Schema ID
		 * @return {boolean} True if description is expanded
		 */
		isDescriptionExpanded(schemaId) {
			return this.expandedDescriptions.includes(schemaId)
		},

		/**
		 * Toggle description expanded state
		 *
		 * @param {number} schemaId - Schema ID
		 * @return {void}
		 */
		toggleDescriptionExpanded(schemaId) {
			const index = this.expandedDescriptions.indexOf(schemaId)
			if (index > -1) {
				this.expandedDescriptions.splice(index, 1)
			} else {
				this.expandedDescriptions.push(schemaId)
			}
		},

		/**
		 * Get displayed properties for a schema (first 5 or all if expanded)
		 *
		 * @param {object} schema - Schema object
		 * @return {object} Properties to display
		 */
		getDisplayedProperties(schema) {
			const sorted = this.sortedProperties(schema)
			const entries = Object.entries(sorted)

			if (this.isSchemaExpanded(schema.id)) {
				return sorted
			}

			// Show only first 5 properties
			return Object.fromEntries(entries.slice(0, 5))
		},

		/**
		 * Get count of remaining properties not displayed
		 *
		 * @param {object} schema - Schema object
		 * @return {number} Count of remaining properties
		 */
		getRemainingPropertiesCount(schema) {
			const total = Object.keys(schema.properties || {}).length
			return Math.max(0, total - 5)
		},
		/**
		 * Check if schema has objects
		 *
		 * @param {object} schema - Schema object
		 * @return {boolean} True if schema has objects
		 */
		hasObjects(schema) {
			return schema.stats?.objects?.total > 0
		},
		/**
		 * Check if schema is part of a configuration
		 *
		 * @param {object} schema - Schema object
		 * @return {boolean} True if schema is part of configuration
		 */
		isInConfiguration(schema) {
			if (!schema || !schema.id) return false

			return configurationStore.configurationList.some(
				config => config.schemas && config.schemas.includes(schema.id),
			)
		},
		/**
		 * Get the configuration that manages this schema
		 *
		 * @param {object} schema - Schema object
		 * @return {object|null} Configuration object or null if not managed
		 */
		getManagingConfiguration(schema) {
			if (!schema || !schema.id) return null

			return configurationStore.configurationList.find(
				config => config.schemas && config.schemas.includes(schema.id),
			) || null
		},
		/**
		 * Check if schema is managed by an external (imported) configuration
		 * External configurations are locked and cannot be edited
		 *
		 * @param {object} schema - Schema object
		 * @return {boolean} True if managed by external configuration
		 */
		isManagedByExternalConfig(schema) {
			const config = this.getManagingConfiguration(schema)
			if (!config) return false

			// External configurations: github, gitlab, url sources, or isLocal === false
			return (config.sourceType && ['github', 'gitlab', 'url'].includes(config.sourceType)) || config.isLocal === false
		},
		/**
		 * Check if schema is managed by a local configuration
		 * Local configurations are editable
		 *
		 * @param {object} schema - Schema object
		 * @return {boolean} True if managed by local configuration
		 */
		isManagedByLocalConfig(schema) {
			const config = this.getManagingConfiguration(schema)
			if (!config) return false

			// Local configurations: sourceType === 'local' or 'manual', or isLocal === true
			return config.sourceType === 'local' || config.sourceType === 'manual' || config.isLocal === true
		},
		toggleSelectAll(checked) {
			if (checked) {
				this.selectedSchemas = schemaStore.schemaList.map(schema => schema.id)
			} else {
				this.selectedSchemas = []
			}
		},

		toggleSchemaSelection(schemaId, checked) {
			if (checked) {
				this.selectedSchemas.push(schemaId)
			} else {
				this.selectedSchemas = this.selectedSchemas.filter(id => id !== schemaId)
			}
		},
		onPageChanged(page) {
			schemaStore.setPagination(page, schemaStore.pagination.limit)
		},
		onPageSizeChanged(pageSize) {
			schemaStore.setPagination(1, pageSize)
		},
	},
}
</script>
<style scoped lang="scss">
.required-indicator {
	color: var(--color-warning-dark);
	font-size: 0.8em;
	margin-left: 4px;
}

/* Status Pills */
.statusPill {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.75em;
	font-weight: 600;
	text-transform: uppercase;
	margin-left: 8px;
	white-space: nowrap;
}

.statusPill--alert {
	background-color: var(--color-warning);
	color: var(--color-main-background);
}

.statusPill--success {
	background-color: var(--color-success);
	color: white;
}

.statusPill--danger {
	background-color: var(--color-error);
	color: white;
}

.statusPill--warning {
	background-color: var(--color-warning);
	color: var(--color-main-background);
}

/* Title with badges layout */
.titleWithBadges {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 4px;
}

/* Card borders based on status */
.card--in-use {
	border: 2px solid var(--color-success);
}

.card--configuration {
	border: 2px solid var(--color-success);
}

.card--local {
	border: 2px solid var(--color-warning);
}

/* Table row borders based on status */
.viewTableRow--in-use {
	border-left: 4px solid var(--color-success);
}

.viewTableRow--configuration {
	border-left: 4px solid var(--color-success);
}

.viewTableRow--local {
	border-left: 4px solid var(--color-warning);
}

/* Adjust card header to accommodate pills */
.cardHeader h2 {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

/* Schema card description */
.schemaDescription {
	padding: 16px;
	margin: 12px 0 12px 0;
	background-color: var(--color-background-hover);
	color: var(--color-text-lighter);
	font-size: 0.95em;
	line-height: 1.5;
	min-height: 80px;
	max-height: 100px;
	overflow: hidden;
	word-wrap: break-word;
	overflow-wrap: break-word;
	word-break: break-word;
	hyphens: auto;
	box-sizing: border-box;
	cursor: pointer;
	transition: max-height 0.3s ease;
	display: -webkit-box;
	-webkit-line-clamp: 4;
	line-clamp: 4;
	-webkit-box-orient: vertical;
}

.schemaDescription:hover {
	background-color: var(--color-background-dark);
}

.schemaDescription--expanded {
	max-height: none !important;
	display: block;
	-webkit-line-clamp: unset;
	line-clamp: unset;
}

.schemaDescription--empty {
	cursor: default;
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.schemaDescription--empty:hover {
	background-color: var(--color-background-hover);
}

/* View more button container */
.viewMoreContainer {
	display: flex;
	justify-content: stretch;
	padding: 0;
}

.viewMoreContainer button {
	width: 100%;
	border-radius: 0 0 8px 8px;
}

/* Empty text styling */
.emptyText {
	text-align: center;
	color: var(--color-text-lighter);
	font-style: italic;
	padding: 16px !important;
}

/* Remove all borders between sections */
.card .schemaStats {
	border-top: none !important;
	margin-top: 0 !important;
}

.card .schemaStats thead {
	border-top: none !important;
}

.card .schemaStats thead tr {
	border-top: none !important;
}

.card .schemaStats thead th {
	border-top: none !important;
}

/* Remove border after card header */
.card .cardHeader {
	border-bottom: none !important;
	margin-bottom: 0 !important;
	padding-bottom: 0 !important;
}

.card .cardHeader h2 {
	margin-bottom: 0;
}

/* Managed by Configuration badge */
.managedBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 0.75rem;
	font-weight: 600;
	margin-left: 8px;
	vertical-align: middle;
}

/* External (managed) badge - green */
.managedBadge--external {
	background: var(--color-success);
	color: white;
}

/* Local configuration badge - orange */
.managedBadge--local {
	background: var(--color-warning);
	color: var(--color-main-background);
}

/* No component-specific table styles needed - all styles are now generic in main.css */
</style>
