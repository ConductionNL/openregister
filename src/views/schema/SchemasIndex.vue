<script setup>
import { schemaStore, navigationStore } from '../../store/store.js'
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
								'card--configuration': isInConfiguration(schema)
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
									<span v-if="isInConfiguration(schema)" class="statusPill statusPill--danger">
										{{ t('openregister', 'Configuration') }}
									</span>
								</h2>
								<NcActions :primary="true" menu-name="Actions">
									<template #icon>
										<DotsHorizontal :size="20" />
									</template>
									<NcActionButton close-after-click @click="schemaStore.setSchemaItem(schema); navigationStore.setModal('editSchema')">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton close-after-click @click="schemaStore.setSchemaPropertyKey(null); schemaStore.setSchemaItem(schema); navigationStore.setModal('editSchemaProperty')">
										<template #icon>
											<Plus :size="20" />
										</template>
										Add Property
									</NcActionButton>
									<NcActionButton close-after-click @click="createExtendedSchema(schema)">
										<template #icon>
											<CallSplit :size="20" />
										</template>
										Extend Schema
									</NcActionButton>
									<NcActionButton close-after-click @click="schemaStore.downloadSchema(schema)">
										<template #icon>
											<Download :size="20" />
										</template>
										Download
									</NcActionButton>
									<NcActionButton close-after-click @click="schemaStore.setSchemaItem(schema); navigationStore.setModal('exploreSchema')">
										<template #icon>
											<DatabaseSearch :size="20" />
										</template>
										Analyze Properties
									</NcActionButton>
									<NcActionButton close-after-click @click="schemaStore.setSchemaItem(schema); navigationStore.setModal('validateSchema')">
										<template #icon>
											<CheckCircle :size="20" />
										</template>
										Validate Objects
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
									<NcActionButton v-tooltip="schema.stats?.objects?.total > 0 ? 'Delete all objects in this schema' : 'No objects to delete'"
										close-after-click
										:disabled="schema.stats?.objects?.total === 0"
										@click="schemaStore.setSchemaItem(schema); navigationStore.setModal('deleteSchemaObjects')">
										<template #icon>
											<DeleteSweep :size="20" />
										</template>
										Delete Objects
									</NcActionButton>
									<NcActionButton v-tooltip="schema.stats?.objects?.total > 0 ? 'Publish all objects in this schema' : 'No objects to publish'"
										close-after-click
										:disabled="schema.stats?.objects?.total === 0"
										@click="schemaStore.setSchemaItem(schema); navigationStore.setModal('publishSchemaObjects')">
										<template #icon>
											<CheckCircle :size="20" />
										</template>
										Publish Objects
									</NcActionButton>
									<NcActionButton close-after-click @click="schemaStore.setSchemaItem(schema); $router.push(`/schemas/${schema.id}`)">
										<template #icon>
											<InformationOutline :size="20" />
										</template>
										View Details
									</NcActionButton>
								</NcActions>
							</div>
							<!-- Show properties table -->
							<table class="statisticsTable schemaStats">
								<thead>
									<tr>
										<th>{{ t('openregister', 'Name') }}</th>
										<th>{{ t('openregister', 'Type') }}</th>
										<th>{{ t('openregister', 'Actions') }}</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="(property, key) in sortedProperties(schema)" :key="key">
										<td>{{ key }} <span v-if="isPropertyRequired(schema, key)" class="required-indicator">({{ t('openregister', 'required') }})</span></td>
										<td>{{ property.type }}</td>
										<td>
											<NcActions :primary="false">
												<NcActionButton close-after-click
													:aria-label="'Edit ' + key"
													@click="schemaStore.setSchemaPropertyKey(key); schemaStore.setSchemaItem(schema); navigationStore.setModal('editSchemaProperty')">
													<template #icon>
														<Pencil :size="16" />
													</template>
													Edit
												</NcActionButton>
												<NcActionButton close-after-click
													:aria-label="'Delete ' + key"
													@click="schemaStore.setSchemaPropertyKey(key); schemaStore.setSchemaItem(schema); navigationStore.setModal('deleteSchemaProperty')">
													<template #icon>
														<TrashCanOutline :size="16" />
													</template>
													Delete
												</NcActionButton>
											</NcActions>
										</td>
									</tr>
									<tr v-if="!Object.keys(schema.properties).length">
										<td colspan="3">
											No properties found
										</td>
									</tr>
								</tbody>
							</table>
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
										'viewTableRow--configuration': isInConfiguration(schema)
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
												<span v-if="isInConfiguration(schema)" class="statusPill statusPill--danger">
													{{ t('openregister', 'Configuration') }}
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
											<NcActionButton close-after-click @click="schemaStore.setSchemaItem(schema); navigationStore.setModal('editSchema')">
												<template #icon>
													<Pencil :size="20" />
												</template>
												Edit
											</NcActionButton>
											<NcActionButton close-after-click @click="schemaStore.setSchemaPropertyKey(null); schemaStore.setSchemaItem(schema); navigationStore.setModal('editSchemaProperty')">
												<template #icon>
													<Plus :size="20" />
												</template>
												Add Property
											</NcActionButton>
											<NcActionButton close-after-click @click="createExtendedSchema(schema)">
												<template #icon>
													<CallSplit :size="20" />
												</template>
												Extend Schema
											</NcActionButton>
											<NcActionButton close-after-click @click="schemaStore.downloadSchema(schema)">
												<template #icon>
													<Download :size="20" />
												</template>
												Download
											</NcActionButton>
											<NcActionButton close-after-click @click="schemaStore.setSchemaItem(schema); navigationStore.setModal('exploreSchema')">
												<template #icon>
													<DatabaseSearch :size="20" />
												</template>
												Analyze Properties
											</NcActionButton>
											<NcActionButton close-after-click @click="schemaStore.setSchemaItem(schema); navigationStore.setModal('validateSchema')">
												<template #icon>
													<CheckCircle :size="20" />
												</template>
												Validate Objects
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
											<NcActionButton v-tooltip="schema.stats?.objects?.total > 0 ? 'Delete all objects in this schema' : 'No objects to delete'"
												close-after-click
												:disabled="schema.stats?.objects?.total === 0"
												@click="schemaStore.setSchemaItem(schema); navigationStore.setModal('deleteSchemaObjects')">
												<template #icon>
													<DeleteSweep :size="20" />
												</template>
												Delete Objects
											</NcActionButton>
											<NcActionButton close-after-click @click="schemaStore.setSchemaItem(schema); $router.push(`/schemas/${schema.id}`)">
												<template #icon>
													<InformationOutline :size="20" />
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
import { NcAppContent, NcEmptyContent, NcActions, NcActionButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import DatabaseSearch from 'vue-material-design-icons/DatabaseSearch.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import DeleteSweep from 'vue-material-design-icons/DeleteSweep.vue'
import CallSplit from 'vue-material-design-icons/CallSplit.vue'

import Plus from 'vue-material-design-icons/Plus.vue'

import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'SchemasIndex',
	components: {
		NcCheckboxRadioSwitch,
		NcAppContent,
		NcEmptyContent,
		NcActions,
		NcActionButton,
		FileTreeOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		Download,
		Refresh,
		InformationOutline,
		DatabaseSearch,
		CheckCircle,
		DeleteSweep,
		CallSplit,

		Plus,
		PaginationComponent,
	},
	data() {
		return {
			selectedSchemas: [],
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
	methods: {
		isPropertyRequired(schema, key) {
			return schema.required && schema.required.includes(key)
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
			// Check if schema has configuration references
			// You can customize this logic based on your data structure
			return schema.configurations && schema.configurations.length > 0
		},
		createExtendedSchema(parentSchema) {
			// Create a new schema that extends the parent schema
			const newSchema = {
				title: `Extended ${parentSchema.title}`,
				description: `Schema extending ${parentSchema.title}`,
				extend: parentSchema.id, // Set the parent schema ID
				properties: {}, // Start with empty properties (will inherit from parent)
				required: [],
			}
			// Set the new schema and open the edit modal
			schemaStore.setSchemaItem(newSchema)
			navigationStore.setModal('editSchema')
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
	border: 2px solid var(--color-error);
}

/* Table row borders based on status */
.viewTableRow--in-use {
	border-left: 4px solid var(--color-success);
}

.viewTableRow--configuration {
	border-left: 4px solid var(--color-error);
}

/* Adjust card header to accommodate pills */
.cardHeader h2 {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

/* No component-specific table styles needed - all styles are now generic in main.css */
</style>
