<script setup>
import { schemaStore, navigationStore } from '../../store/store.js'
import formatBytes from '../../services/formatBytes.js'
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
						<div v-for="schema in paginatedSchemas" :key="schema.id" class="card">
							<div class="cardHeader">
								<h2>
									<FileTreeOutline :size="20" />
									{{ schema.title }}
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
									<NcActionButton close-after-click @click="schemaStore.downloadSchema(schema)">
										<template #icon>
											<Download :size="20" />
										</template>
										Download
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
									<NcActionButton close-after-click @click="schemaStore.setSchemaItem(schema); navigationStore.setSelected('schemaDetails')">
										<template #icon>
											<InformationOutline :size="20" />
										</template>
										View Details
									</NcActionButton>
								</NcActions>
							</div>
							<!-- Toggle between stats and properties -->
							<div v-if="!schema.showProperties">
								<table class="statisticsTable schemaStats">
									<thead>
										<tr>
											<th>{{ t('openregister', 'Type') }}</th>
											<th>{{ t('openregister', 'Total') }}</th>
											<th>{{ t('openregister', 'Size') }}</th>
										</tr>
									</thead>
									<tbody>
										<tr>
											<td>{{ t('openregister', 'Registers') }}</td>
											<td>{{ schema.stats?.registers ?? 0 }}</td>
											<td>-</td>
										</tr>
										<tr>
											<td>{{ t('openregister', 'Properties') }}</td>
											<td>{{ Object.keys(schema.properties).length }}</td>
											<td>-</td>
										</tr>
										<tr>
											<td>{{ t('openregister', 'Objects') }}</td>
											<td>{{ schema.stats?.objects?.total || 0 }}</td>
											<td>{{ formatBytes(schema.stats?.objects?.size || 0) }}</td>
										</tr>
										<tr class="subRow">
											<td class="indented">
												{{ t('openregister', 'Invalid') }}
											</td>
											<td>{{ schema.stats?.objects?.invalid || 0 }}</td>
											<td>-</td>
										</tr>
										<tr class="subRow">
											<td class="indented">
												{{ t('openregister', 'Deleted') }}
											</td>
											<td>{{ schema.stats?.objects?.deleted || 0 }}</td>
											<td>-</td>
										</tr>
										<tr class="subRow">
											<td class="indented">
												{{ t('openregister', 'Locked') }}
											</td>
											<td>{{ schema.stats?.objects?.locked || 0 }}</td>
											<td>-</td>
										</tr>
										<tr class="subRow">
											<td class="indented">
												{{ t('openregister', 'Published') }}
											</td>
											<td>{{ schema.stats?.objects?.published || 0 }}</td>
											<td>-</td>
										</tr>
										<tr>
											<td>{{ t('openregister', 'Logs') }}</td>
											<td>{{ schema.stats?.logs?.total || 0 }}</td>
											<td>{{ formatBytes(schema.stats?.logs?.size || 0) }}</td>
										</tr>
										<tr>
											<td>{{ t('openregister', 'Files') }}</td>
											<td>{{ schema.stats?.files?.total || 0 }}</td>
											<td>{{ formatBytes(schema.stats?.files?.size || 0) }}</td>
										</tr>
									</tbody>
								</table>
							</div>
							<div v-else>
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
											<td>{{ key }}</td>
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

							<!-- Toggle button -->
							<NcButton @click="schema.showProperties = !schema.showProperties">
								<template #icon>
									<TableIcon v-if="schema.showProperties" :size="20" />
									<ListIcon v-else :size="20" />
								</template>
								{{ schema.showProperties ? 'Show Stats' : 'Show Properties' }}
							</NcButton>
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
									<th>{{ t('openregister', 'Objects (Total/Size)') }}</th>
									<th>{{ t('openregister', 'Logs (Total/Size)') }}</th>
									<th>{{ t('openregister', 'Files (Total/Size)') }}</th>
									<th>{{ t('openregister', 'Registers') }}</th>
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
									:class="{ viewTableRowSelected: selectedSchemas.includes(schema.id) }">
									<td class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="selectedSchemas.includes(schema.id)"
											@update:checked="(checked) => toggleSchemaSelection(schema.id, checked)" />
									</td>
									<td class="tableColumnTitle">
										<div class="titleContent">
											<strong>{{ schema.title }}</strong>
											<span v-if="schema.description" class="textDescription textEllipsis">{{ schema.description }}</span>
										</div>
									</td>
									<td>{{ schema.stats?.objects?.total || 0 }}/{{ formatBytes(schema.stats?.objects?.size || 0) }}</td>
									<td>{{ schema.stats?.logs?.total || 0 }}/{{ formatBytes(schema.stats?.logs?.size || 0) }}</td>
									<td>{{ schema.stats?.files?.total || 0 }}/{{ formatBytes(schema.stats?.files?.size || 0) }}</td>
									<td>{{ schema.stats?.registers|| 0 }}</td>
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
											<NcActionButton close-after-click @click="schemaStore.downloadSchema(schema)">
												<template #icon>
													<Download :size="20" />
												</template>
												Download
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
											<NcActionButton close-after-click @click="schemaStore.setSchemaItem(schema); navigationStore.setSelected('schemaDetails')">
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
import TableIcon from 'vue-material-design-icons/Table.vue'
import ListIcon from 'vue-material-design-icons/FormatListBulleted.vue'
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
		TableIcon,
		ListIcon,
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
/* No component-specific table styles needed - all styles are now generic in main.css */
</style>
