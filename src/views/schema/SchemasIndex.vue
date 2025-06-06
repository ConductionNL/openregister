<script setup>
import { schemaStore, navigationStore } from '../../store/store.js'
import formatBytes from '../../services/formatBytes.js'
</script>

<template>
	<NcAppContent>
		<span class="pageHeaderContainer">
			<h2 class="pageHeader">
				Schemas
			</h2>

			<div class="headerActionsContainer">
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
					:inline="1"
					:primary="true"
					menu-name="Schema actions">
					<NcActionButton @click="schemaStore.setSchemaItem(null); navigationStore.setModal('editSchema')">
						<template #icon>
							<PlusCircleOutline :size="20" />
						</template>
						Add Schema
					</NcActionButton>
					<NcActionButton @click="schemaStore.refreshSchemaList()">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Refresh
					</NcActionButton>
				</NcActions>
			</div>
		</span>

		<div class="dashboardContent">
			<div v-if="!schemaStore.schemaList.length" class="empty">
				<NcEmptyContent title="No schemas found" icon="icon-folder" />
			</div>
			<div v-else class="registers">
				<template v-if="schemaStore.viewMode === 'cards'">
					<div v-for="schema in schemaStore.schemaList" :key="schema.id" class="registerCard">
						<div class="registerHeader">
							<h2>
								<FileTreeOutline :size="20" />
								{{ schema.title }}
							</h2>
							<NcActions :primary="true" menu-name="Actions">
								<template #icon>
									<DotsHorizontal :size="20" />
								</template>
								<NcActionButton @click="schemaStore.setSchemaItem(schema); navigationStore.setModal('editSchema')">
									<template #icon>
										<Pencil :size="20" />
									</template>
									Edit
								</NcActionButton>
								<NcActionButton @click="schemaStore.setSchemaPropertyKey(null); schemaStore.setSchemaItem(schema); navigationStore.setModal('editSchemaProperty')">
									<template #icon>
										<PlusCircleOutline :size="20" />
									</template>
									Add Property
								</NcActionButton>
								<NcActionButton @click="schemaStore.downloadSchema(schema)">
									<template #icon>
										<Download :size="20" />
									</template>
									Download
								</NcActionButton>
								<NcActionButton
									v-tooltip="schema.stats?.objects?.total > 0 ? 'Cannot delete: objects are still attached' : ''"
									:disabled="schema.stats?.objects?.total > 0"
									@click="schemaStore.setSchemaItem(schema); navigationStore.setDialog('deleteSchema')">
									<template #icon>
										<TrashCanOutline :size="20" />
									</template>
									Delete
								</NcActionButton>
								<NcActionButton @click="schemaStore.setSchemaItem(schema); navigationStore.setSelected('schemaDetails')">
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
												<NcActionButton :aria-label="'Edit ' + key"
													@click="schemaStore.setSchemaPropertyKey(key); schemaStore.setSchemaItem(schema); navigationStore.setModal('editSchemaProperty')">
													<template #icon>
														<Pencil :size="16" />
													</template>
													Edit
												</NcActionButton>
												<NcActionButton :aria-label="'Delete ' + key"
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
				</template>
				<template v-else>
					<table class="statisticsTable registerStats tableOfContents">
						<thead>
							<tr>
								<th>Title</th>
								<th>Objects (Total/Size)</th>
								<th>Logs (Total/Size)</th>
								<th>Files (Total/Size)</th>
								<th>Registers</th>
								<th>Created</th>
								<th>Updated</th>
								<th />
							</tr>
						</thead>
						<tbody>
							<tr v-for="schema in schemaStore.schemaList" :key="schema.id">
								<td>{{ schema.title }}</td>
								<td>{{ schema.stats?.objects?.total || 0 }}/{{ formatBytes(schema.stats?.objects?.size || 0) }}</td>
								<td>{{ schema.stats?.logs?.total || 0 }}/{{ formatBytes(schema.stats?.logs?.size || 0) }}</td>
								<td>{{ schema.stats?.files?.total || 0 }}/{{ formatBytes(schema.stats?.files?.size || 0) }}</td>
								<td>{{ schema.stats?.registers|| 0 }}</td>
								<td>{{ schema.created ? new Date(schema.created).toLocaleDateString({day: '2-digit', month: '2-digit', year: 'numeric'}) + ', ' + new Date(schema.created).toLocaleTimeString({hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '-' }}</td>
								<td>{{ schema.updated ? new Date(schema.updated).toLocaleDateString({day: '2-digit', month: '2-digit', year: 'numeric'}) + ', ' + new Date(schema.updated).toLocaleTimeString({hour: '2-digit', minute: '2-digit', second: '2-digit'}) : '-' }}</td>
								<td>
									<NcActions :primary="false">
										<template #icon>
											<DotsHorizontal :size="20" />
										</template>
										<NcActionButton @click="schemaStore.setSchemaItem(schema); navigationStore.setModal('editSchema')">
											<template #icon>
												<Pencil :size="20" />
											</template>
											Edit
										</NcActionButton>
										<NcActionButton @click="schemaStore.setSchemaPropertyKey(null); schemaStore.setSchemaItem(schema); navigationStore.setModal('editSchemaProperty')">
											<template #icon>
												<PlusCircleOutline :size="20" />
											</template>
											Add Property
										</NcActionButton>
										<NcActionButton @click="schemaStore.downloadSchema(schema)">
											<template #icon>
												<Download :size="20" />
											</template>
											Download
										</NcActionButton>
										<NcActionButton
											v-tooltip="schema.stats?.objects?.total > 0 ? 'Cannot delete: objects are still attached' : ''"
											:disabled="schema.stats?.objects?.total > 0"
											@click="schemaStore.setSchemaItem(schema); navigationStore.setDialog('deleteSchema')">
											<template #icon>
												<TrashCanOutline :size="20" />
											</template>
											Delete
										</NcActionButton>
										<NcActionButton @click="schemaStore.setSchemaItem(schema); navigationStore.setSelected('schemaDetails')">
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
				</template>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcButton, NcActions, NcActionButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import PlusCircleOutline from 'vue-material-design-icons/PlusCircleOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import TableIcon from 'vue-material-design-icons/Table.vue'
import ListIcon from 'vue-material-design-icons/FormatListBulleted.vue'

export default {
	name: 'SchemasIndex',
	components: {
		NcCheckboxRadioSwitch,
		NcAppContent,
		NcEmptyContent,
		NcButton,
		NcActions,
		NcActionButton,
		FileTreeOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		PlusCircleOutline,
		Download,
		Refresh,
		InformationOutline,
		TableIcon,
		ListIcon,
	},
	computed: {
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
}
</script>
<style scoped lang="scss">
.pageHeaderContainer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 0;
}

.pageHeader {
	font-size: 30px;
	font-weight: 600;
	margin-left: 50px;
}

.dashboardContent {
	margin-inline: auto;
	max-width: 1200px;
	padding-block: 20px;
	padding-inline: 20px;
}

.registers {
	display: grid;
	gap: 1.5rem;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
}

.registerCard {
	background: var(--color-main-background);
	border-radius: 8px;
	padding-block: 20px;
	padding-inline: 20px;
	box-shadow: 0 2px 8px var(--color-box-shadow);
	min-height: 200px;
	transition: transform 0.2s ease-in-out;
	border: 1px solid var(--color-border);
}

.registerHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-block-end: 12px;
	padding-block-end: 8px;
	border-block-end: 1px solid var(--color-border);

	h2 {
		display: flex;
		align-items: center;
		gap: 8px;
		margin: 0;
		font-size: 1.2em;
		color: var(--color-main-text);
	}
}

.schemaToggle {
	background: none;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 4px 8px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	transition: all 0.2s ease;
	margin-bottom: 4px;
}

.schemaProperties {
	border-block-start: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
	padding: 8px;
}

.statisticsTable {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;

	th, td {
		padding: 8px 12px;
		text-align: start;
		border-block-end: 1px solid var(--color-border);
	}

	th {
		background-color: var(--color-background-darker);
		color: var(--color-text-maxcontrast);
		font-weight: normal;
	}

	tr:last-child td {
		border-block-end: none;
	}

	.subRow td {
		color: var(--color-text-maxcontrast);
	}

	.indented {
		padding-inline-start: 24px;
	}
}

.statisticsTable.schemaStats {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;
	margin-bottom: 8px;
}

.statisticsTable.schemaStats th, .statisticsTable.schemaStats td {
	padding: 8px 12px;
	text-align: start;
	border-block-end: 1px solid var(--color-border);
}

.statisticsTable.schemaStats th {
	background-color: var(--color-background-darker);
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.statisticsTable.schemaStats tr:last-child td {
	border-block-end: none;
}
.headerActionsContainer {
    display: flex;
    align-items: end;
    gap: 1em;
    padding-block-start: 0.5em;
    padding-inline-end: 0.9em;

    .viewModeSwitchContainer {
        display: flex;
        align-items: center;

        span {
            max-height: 34px;
        }
    }
}
.tableOfContents {
	thead {
		th {
			background-color: var(--color-primary-light);
			font-weight: 600;
		}
	}
	tbody {
		tr:nth-child(odd) {
			background-color: transparent;
		}
		tr:nth-child(even) {
			background-color: var(--color-border);
		}
	}
}
</style>
