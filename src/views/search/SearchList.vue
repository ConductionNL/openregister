<script setup>
import { navigationStore, objectStore, schemaStore, registerStore } from '../../store/store.js'
import { NcCheckboxRadioSwitch, NcActions, NcActionButton, NcCounterBubble } from '@nextcloud/vue'
</script>

<template>
	<div class="searchList">
		<div class="searchListTable">
			<VueDraggable v-model="objectStore.enabledColumns"
				target=".sortTarget"
				animation="150"
				draggable="> *:not(.staticColumn)">
				<table class="table">
					<thead>
						<tr class="table-row sort-target">
							<th class="static-column">
								<NcCheckboxRadioSwitch
									:checked="objectStore.isAllSelected"
									type="checkbox"
									class="cursor-pointer"
									@update:checked="objectStore.toggleSelectAllObjects" />
							</th>
							<th v-for="column in objectStore.enabledColumns"
								:key="column.id">
								<span class="stickyHeader columnTitle" :title="column.description">
									{{ column.label }}
								</span>
							</th>
							<th class="staticColumn columnTitle">
								Actions
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="result in objectStore.objectList.results"
							:key="result['@self'].uuid"
							class="table-row">
							<td class="static-column">
								<NcCheckboxRadioSwitch
									:checked="objectStore.selectedObjects.includes(result['@self'].id)"
									type="checkbox"
									class="cursor-pointer"
									@update:checked="handleSelectObject(result['@self'].id)" />
							</td>
							<td v-for="column in objectStore.enabledColumns"
								:key="column.id">
								<template v-if="column.id.startsWith('meta_')">
									<span v-if="column.id === 'meta_files'">
										<NcCounterBubble :count="result['@self'].files ? result['@self'].files.length : 0" />
									</span>
									<span v-else-if="column.id === 'meta_created' || column.id === 'meta_updated'">
										{{ getValidISOstring(result['@self'][column.key]) ? new Date(result['@self'][column.key]).toLocaleString() : 'N/A' }}
									</span>
									<span v-else-if="column.id === 'meta_register'">
										<span>{{ registerStore.registerList.find(reg => reg.id === parseInt(result['@self'].register))?.title }}</span>
									</span>
									<span v-else-if="column.id === 'meta_schema'">
										<span>{{ schemaStore.schemaList.find(schema => schema.id === parseInt(result['@self'].schema))?.title }}</span>
									</span>
									<span v-else>
										{{ result['@self'][column.key] }}
									</span>
								</template>
								<template v-else>
									<span>{{ result[column.key] ?? 'N/A' }}</span>
								</template>
							</td>
							<td class="staticColumn">
								<NcActions class="actionsButton">
									<NcActionButton @click="navigationStore.setModal('viewObject'); objectStore.setObjectItem(result)">
										<template #icon>
											<Eye :size="20" />
										</template>
										View
									</NcActionButton>
									<NcActionButton @click="navigationStore.setModal('editObject'); objectStore.setObjectItem(result)">
										<template #icon>
											<Pencil :size="20" />
										</template>
										Edit
									</NcActionButton>
									<NcActionButton @click="deleteObject(result)">
										<template #icon>
											<Delete :size="20" />
										</template>
										Delete
									</NcActionButton>
								</NcActions>
							</td>
						</tr>
					</tbody>
				</table>
			</VueDraggable>
		</div>

		<div class="paginationContainer">
			<BPagination
				v-model="objectStore.pagination.page"
				:total-rows="objectStore.objectList.total"
				:per-page="objectStore.pagination.limit"
				:first-number="true"
				:last-number="true"
				@change="onPageChange" />
		</div>

		<MassDeleteObject v-if="navigationStore.dialog === 'massDeleteObject'"
			:selected-objects="objectStore.selectedObjects"
			@close-modal="() => navigationStore.setDialog(null)"
			@success="onMassDeleteSuccess" />
	</div>
</template>

<script>
import { BPagination } from 'bootstrap-vue'
import { VueDraggable } from 'vue-draggable-plus'
import getValidISOstring from '../../services/getValidISOstring.js'

import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

import MassDeleteObject from '../../modals/object/MassDeleteObject.vue'

export default {
	name: 'SearchList',
	components: {
		NcActions,
		NcActionButton,
		NcCounterBubble,
		BPagination,
		VueDraggable,
		Eye,
		Pencil,
		Delete,
		MassDeleteObject,
	},
	data() {
		return {
		}
	},
	computed: {
		loading() {
			return objectStore.loading
		},
		selectedSchema() {
			return schemaStore.schemaList.find(
				schema => schema.id.toString() === objectStore.activeSchema?.id?.toString(),
			)
		},
		schemaProperties() {
			return Object.values(this.selectedSchema?.properties || {}) || []
		},
	},
	watch: {
		loading: {
			handler(newVal) {
				newVal === false && objectStore.setSelectAllObjects()
			},
			deep: true,
		},
	},
	mounted() {
		objectStore.initializeColumnFilters()
	},
	methods: {
		openLink(link, type = '') {
			window.open(link, type)
		},
		onMassDeleteSuccess() {
			objectStore.selectedObjects = []
			objectStore.refreshObjectList()
		},
		async deleteObject(result) {
			try {
				navigationStore.setDialog('deleteObject')
				objectStore.setObjectItem({
					'@self': {
						id: result['@self'].id,
						uuid: result['@self'].uuid,
						register: result['@self'].register,
						schema: result['@self'].schema,
						title: result['@self'].title || result.name || result.title || result['@self'].id
					}
				})
			} catch (error) {
				console.error('Failed to delete object:', error)
			}
		},
		onPageChange(page) {
			objectStore.setPagination(page)
			objectStore.refreshObjectList()
		},
		handleSelectObject(id) {
			if (objectStore.selectedObjects.includes(id)) {
				objectStore.selectedObjects = objectStore.selectedObjects.filter(obj => obj !== id)
			} else {
				objectStore.selectedObjects.push(id)
			}
		},
	},
}
</script>

<style>
.actionsButton > div > button {
    margin-top: 0px !important;
    margin-right: 0px !important;
    padding-right: 0px !important;
}
</style>

<style scoped>
.searchListHeader {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding: 1rem;
    margin-bottom: 1rem;
}

.searchListHeader h2 {
    margin: 0;
    font-size: var(--default-font-size);
    font-weight: bold;
}

.searchListTable {
    overflow-x: auto;
}

.table {
	width: 100%;
	border-collapse: collapse;
}

.tableRow {
    color: var(--color-main-text);
    border-bottom: 1px solid var(--color-border);
}

.tableRow > td {
    height: 55px;
    padding: 0 10px;
}
.tableRow > th {
    padding: 0 10px;
}
.tableRow > th > .stickyHeader {
    position: sticky;
    left: 0;
}

.sortTarget > th {
    cursor: move;
}

.cursorPointer {
    cursor: pointer !important;
}

input[type="checkbox"] {
    box-shadow: none !important;
}

.pagination {
    margin-block-start: 1rem;
    display: flex;
}
.pagination :deep(.page-item > .page-link) {
    width: 35px !important;
    height: 35px !important;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--color-primary-element-light-text) !important;
    background-color: var(--color-primary-element-light) !important;
    padding: 0 !important;
    font-size: var(--default-font-size) !important;
    min-height: var(--default-clickable-area) !important;
    margin: 3px !important;
    margin-inline-start: 0 !important;
    border-radius: var(--border-radius-element) !important;
    line-height: 18.75px !important;
    vertical-align: middle !important;
    font-weight: bold !important;
    font-family: var(--font-face) !important;
}
.pagination :deep(.page-item.active > .page-link) {
    color: var(--color-primary-element-text) !important;
    background-color: var(--color-primary-element) !important;
}
.pagination :deep(.page-item.disabled > .page-link) {
    color: var(--color-primary-element-light-text) !important;
    background-color: var(--color-primary-element-light) !important;
    opacity: 0.5 !important;
    cursor: not-allowed !important;
}

.columnTitle {
    font-weight: bold;
}
</style>
