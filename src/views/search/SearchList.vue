<script setup>
import { navigationStore, objectStore, schemaStore, searchStore } from '../../store/store.js'
import { EventBus } from '../../eventBus.js'
</script>

<template>
	<div class="search-list">
		<div class="search-list-header">
			<NcLoadingIcon v-if="searchStore.searchObjectsLoading && !!searchStore.searchObjectsResult?.results?.length"
				:size="24"
				class="loadingIcon"
				appearance="dark"
				name="Objects loading" />

			<NcButton :disabled="selectedObjects.length === 0" type="error" @click="() => massDeleteObjectModal = true">
				<template #icon>
					<Delete :size="20" />
				</template>
				Delete {{ selectedObjects.length }} {{ selectedObjects.length > 1 ? 'objects' : 'object' }}
			</NcButton>
		</div>

		<div class="search-list-table">
			<VueDraggable v-model="activeHeaders"
				target=".sort-target"
				animation="150"
				draggable="> *:not(.static-column)">
				<table class="table">
					<thead>
						<tr class="table-row sort-target">
							<th class="static-column">
								<input v-model="selectAllObjects"
									type="checkbox"
									class="cursor-pointer"
									@change="toggleSelectAllObjects()">
							</th>
							<template v-for="header in activeHeaders">
								<th v-if="header.enabled" :key="header.id">
									<span class="sticky-header">
										{{ header.label }}
									</span>
								</th>
							</template>
							<th class="static-column">
								Actions
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(result) in searchStore.searchObjectsResult.results" :key="result.uuid" class="table-row">
							<td class="static-column">
								<input v-model="selectedObjects"
									:value="result.id"
									type="checkbox"
									class="cursor-pointer"
									@change="onSelectObject(result.id)">
							</td>
							<template v-for="header in activeHeaders">
								<td v-if="header.enabled" :key="header.id">
									<span v-if="header.id === 'files'">
										<NcCounterBubble :count="result.files ? result.files.length : 0" />
									</span>
									<span v-else-if="header.id === 'schemaProperties'">
										<NcCounterBubble :count="schemaProperties.length" />
									</span>
									<span v-else-if="header.id === 'created' || header.id === 'updated'">
										{{ getValidISOstring(result[header.key]) ? new Date(result[header.key]).toLocaleString() : 'N/A' }}
									</span>
									<span v-else>
										{{ result[header.key] }}
									</span>
								</td>
							</template>
							<td class="static-column">
								<NcActions>
									<NcActionButton @click="navigationStore.setSelected('objects'); objectStore.setObjectItem(result)">
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
								</NcActions>
							</td>
						</tr>
					</tbody>
				</table>
			</VueDraggable>
		</div>

		<div class="pagination-container">
			<BPagination
				v-model="searchStore.searchObjects_pagination"
				:total-rows="searchStore.searchObjectsResult.total"
				:per-page="searchStore.searchObjects_limit"
				:first-number="true"
				:last-number="true" />
		</div>

		<MassDeleteObject v-if="massDeleteObjectModal"
			:selected-objects="selectedObjects"
			@close-modal="() => massDeleteObjectModal = false"
			@success="onMassDeleteSuccess" />
	</div>
</template>

<script>
import { NcActions, NcActionButton, NcCounterBubble, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { BPagination } from 'bootstrap-vue'
import { VueDraggable } from 'vue-draggable-plus'
import getValidISOstring from '../../services/getValidISOstring.js'
import _ from 'lodash'

import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

import MassDeleteObject from '../../modals/object/MassDeleteObject.vue'

export default {
	name: 'SearchList',
	components: {
		NcActions,
		NcActionButton,
	},
	data() {
		return {
			headers: [
				{
					id: 'objectId',
					label: 'ObjectID',
					key: 'uuid',
					enabled: true,
				},
				{
					id: 'created',
					label: 'Created',
					key: 'created',
					enabled: true,
				},
				{
					id: 'updated',
					label: 'Updated',
					key: 'updated',
					enabled: true,
				},
				{
					id: 'files',
					label: 'Amount of files',
					key: 'files',
					enabled: true,
				},
				{
					id: 'schemaProperties',
					label: 'Schema properties',
					key: null,
					enabled: true,
				},
			],
			/**
			 * To ensure complete compatibility between the toggle and the drag function,
			 * We need a working headers array which gets updated when a header gets toggled.
			 *
			 * This array is a copy of the headers array but with the disabled headers filtered out.
			 */
			activeHeaders: [],
			// select boxes
			selectAllObjects: false,
			selectedObjects: [],
			// modal state
			massDeleteObjectModal: false,
		}
	},
	computed: {
		objectsLoading: () => searchStore.searchObjectsLoading,
		selectedSchema() {
			return schemaStore.schemaList.find((schema) => schema.id.toString() === searchStore.searchObjectsResult?.results?.[0]?.schema?.toString())
		},
		schemaProperties() {
			return Object.values(this.selectedSchema.properties) || []
		},
	},
	watch: {
		headers: {
			handler() {
				this.setActiveHeaders()
			},
			deep: true,
		},
		objectsLoading: {
			handler(newVal) {
				// if loading finished, run setSelectAllObjects
				newVal === false && this.setSelectAllObjects()
			},
			deep: true,
		},
	},
	created() {
		EventBus.$on('object-search-set-column-filter', (payload) => {
			this.headers.find((header) => header.id === payload.id).enabled = payload.enabled
		})
	},
	beforeDestroy() {
		// Clean up the event listener
		EventBus.$off('object-search-set-column-filter')
	},
	mounted() {
		this.setActiveHeaders()
	},
	methods: {
		setActiveHeaders() {
			this.activeHeaders = _.cloneDeep(this.headers.filter((header) => header.enabled))
		},
		/**
		 * This function sets the selectAllObjects state to true if all object ids from the searchObjectsResult are in the selectedObjects array.
		 *
		 * This is used to ensure that the selectAllObjects state is always in sync with the selectedObjects array.
		 */
		setSelectAllObjects() {
			const allObjectIds = searchStore.searchObjectsResult?.results.map(result => result.id) || []
			this.selectAllObjects = allObjectIds.every(id => this.selectedObjects.includes(id))
		},
		onSelectObject(id) {
			this.setSelectAllObjects()
		},
		openLink(link, type = '') {
			window.open(link, type)
		},
		toggleSelectAllObjects() {
			if (this.selectAllObjects) {
				// add all ids from searchObjectsResult to selectedObjects
				this.selectedObjects = [
					...this.selectedObjects,
					...searchStore.searchObjectsResult.results.map((result) => result.id).filter((id) => !this.selectedObjects.includes(id)),
				]
			} else {
				// remove all ids from searchObjectsResult in selectedObjects
				const allObjectIds = searchStore.searchObjectsResult?.results.map(result => result.id) || []
				this.selectedObjects = this.selectedObjects.filter((id) => !allObjectIds.includes(id))
			}
		},
		onMassDeleteSuccess() {
			const unwatch = this.$watch(
				() => searchStore.searchObjectsLoading,
				(newVal) => {
					if (newVal === false) {
						this.selectedObjects = []
						this.setSelectAllObjects()
						unwatch() // Remove the watcher once we're done
					}
				},
			)
			searchStore.reDoSearch()
		},
	},
}
</script>

<style scoped>
.search-list-header {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-inline-end: 10px;
}

.search-list-table {
    overflow-x: auto;
}

.table {
	width: 100%;
	border-collapse: collapse;
}

.table-row {
    color: var(--color-main-text);
    border-bottom: 1px solid var(--color-border);
}

.table-row > td {
    height: 55px;
    padding: 0 10px;
}
.table-row > th {
    padding: 0 10px;
}
.table-row > th > .sticky-header {
    position: sticky;
    left: 0;
}

.sort-target > th {
    cursor: move;
}

.cursor-pointer {
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
</style>
