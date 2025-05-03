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
		</span>

		<div class="dashboardContent">
			<div v-if="!schemaStore.schemaList.length" class="empty">
				<NcEmptyContent title="No schemas found" icon="icon-folder" />
			</div>
			<div v-else class="registers">
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
							<NcActionButton @click="schemaStore.setSchemaItem(schema); navigationStore.setModal('uploadSchema')">
								<template #icon>
									<Upload :size="20" />
								</template>
								Upload
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
						</NcActions>
					</div>
					<!-- Schema Statistics Table -->
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
					<!-- Expandable section for properties -->
					<div>
						<button class="schemaToggle" @click="schema.expanded = !schema.expanded">
							{{ schema.expanded ? 'Hide Properties' : 'Show Properties' }}
						</button>
						<div v-show="schema.expanded" class="schemaProperties">
							<div v-if="Object.keys(schema.properties).length">
								<NcListItem v-for="(property, key) in schema.properties"
									:key="key"
									:name="key"
									:bold="false"
									:force-display-actions="true">
									<template #icon>
										<CircleOutline :size="20" />
									</template>
									<template #subname>
										{{ property.description }}
									</template>
									<template #actions>
										<NcActionButton :aria-label="'Edit ' + key"
											@click="schemaStore.setSchemaPropertyKey(key); schemaStore.setSchemaItem(schema); navigationStore.setModal('editSchemaProperty')">
											<template #icon>
												<Pencil :size="20" />
											</template>
											Edit
										</NcActionButton>
										<NcActionButton :aria-label="'Delete ' + key"
											@click="schemaStore.setSchemaPropertyKey(key); schemaStore.setSchemaItem(schema); navigationStore.setModal('deleteSchemaProperty')">
											<template #icon>
												<TrashCanOutline :size="20" />
											</template>
											Delete
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
							<div v-else class="tabPanel">
								No properties found
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcButton, NcActions, NcActionButton, NcListItem } from '@nextcloud/vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import PlusCircleOutline from 'vue-material-design-icons/PlusCircleOutline.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import CircleOutline from 'vue-material-design-icons/CircleOutline.vue'

export default {
	name: 'SchemasIndex',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcButton,
		NcActions,
		NcActionButton,
		NcListItem,
		FileTreeOutline,
		DotsHorizontal,
		Pencil,
		TrashCanOutline,
		PlusCircleOutline,
		Upload,
		Download,
		Refresh,
		CircleOutline,
	},
}
</script>

<style scoped>
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
	grid-template-columns: 1fr;
	gap: 1.5rem;
}

@media screen and (min-width: 880px) {
	.registers {
		grid-template-columns: repeat(2, 1fr);
	}
}

@media screen and (min-width: 1220px) {
	.registers {
		grid-template-columns: repeat(3, 1fr);
	}
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

</style>
