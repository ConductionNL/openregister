<script setup>
import { navigationStore, objectStore, registerStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ pageTitle }}
				</h1>
				<p v-if="registerStore.registerItem && schemaStore.schemaItem">
					{{ t('openregister', 'Search and browse objects in this schema') }}
				</p>
			</div>

			<!-- Actions Bar -->
			<div v-if="registerStore.registerItem && schemaStore.schemaItem" class="viewActionsBar">
				<div class="viewInfo">
					<span v-if="objectStore.objectList?.results?.length" class="viewTotalCount">
						{{ t('openregister', 'Showing {showing} of {total} objects', {
							showing: objectStore.objectList.results.length,
							total: objectStore.objectList.total
						}) }}
					</span>
					<span v-if="objectStore.selectedObjects.length > 0" class="viewIndicator">
						({{ t('openregister', '{count} selected', { count: objectStore.selectedObjects.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<NcActions
						:force-name="true"
						:inline="objectStore.selectedObjects.length > 0 ? 3 : 2"
						menu-name="Actions">
						<NcActionButton
							v-if="objectStore.selectedObjects.length > 0"
							close-after-click
							@click="bulkDeleteObjects">
							<template #icon>
								<Delete :size="20" />
							</template>
							Delete ({{ objectStore.selectedObjects.length }})
						</NcActionButton>
						<NcActionButton
							:primary="true"
							close-after-click
							@click="addObject">
							<template #icon>
								<Plus :size="20" />
							</template>
							Add Object
						</NcActionButton>
						<NcActionButton
							close-after-click
							@click="refreshObjects">
							<template #icon>
								<Refresh :size="20" />
							</template>
							Refresh
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<!-- Warning when no register is selected -->
			<NcEmptyContent v-if="showNoRegisterWarning || showNoSchemaWarning || objectStore.loading || showNoObjectsMessage"
				:name="emptyContentName"
				:description="emptyContentDescription">
				<template #icon>
					<NcLoadingIcon v-if="objectStore.loading" :size="64" />
					<FileTreeOutline v-else :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Search List Content -->
			<div v-else-if="objectStore.objectList?.results?.length && registerStore.registerItem && schemaStore.schemaItem" class="searchList">
				<div class="viewTableContainer">
					<VueDraggable v-model="objectStore.enabledColumns"
						target=".sort-target"
						animation="150"
						draggable="> *:not(.staticColumn)">
						<table class="viewTable">
							<thead>
								<tr class="viewTableRow sort-target">
									<th class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="objectStore.isAllSelected"
											type="checkbox"
											class="cursor-pointer"
											@update:checked="objectStore.toggleSelectAllObjects" />
									</th>
									<th v-for="(column, index) in objectStore.enabledColumns"
										:key="`header-${column.id || column.key || `col-${index}`}`">
										<span class="stickyHeader columnTitle" :title="column.description">
											{{ column.label }}
										</span>
									</th>
									<th class="tableColumnActions columnTitle">
										{{ t('openregister', 'Actions') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="result in objectStore.objectList.results"
									:key="result['@self'].id || result.id"
									class="viewTableRow">
									<td class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="objectStore.selectedObjects.includes(result['@self'].id)"
											type="checkbox"
											class="cursor-pointer"
											@update:checked="handleSelectObject(result['@self'].id)" />
									</td>
									<td v-for="(column, index) in objectStore.enabledColumns"
										:key="`cell-${result['@self'].id}-${column.id || column.key || `col-${index}`}`">
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
												{{ result['@self'][column.key] || 'N/A' }}
											</span>
										</template>
										<template v-else>
											<span>{{ result[column.key] ?? 'N/A' }}</span>
										</template>
									</td>
									<td class="tableColumnActions">
										<NcActions class="actionsButton">
											<NcActionButton close-after-click @click="navigationStore.setModal('viewObject'); objectStore.setObjectItem(result)">
												<template #icon>
													<Eye :size="20" />
												</template>
												View
											</NcActionButton>
											<NcActionButton close-after-click @click="navigationStore.setModal('editObject'); objectStore.setObjectItem(result)">
												<template #icon>
													<Pencil :size="20" />
												</template>
												Edit
											</NcActionButton>
											<NcActionButton close-after-click @click="deleteObject(result)">
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
					<div class="empty-space" />

					<PaginationComponent
						:current-page="objectStore.pagination.page || 1"
						:total-pages="Math.ceil((objectStore.objectList.total || 0) / (objectStore.pagination.limit || 20))"
						:total-items="objectStore.objectList.total || 0"
						:current-page-size="objectStore.pagination.limit || 20"
						:min-items-to-show="10"
						@page-changed="onPageChanged"
						@page-size-changed="onPageSizeChanged" />
				</div>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcLoadingIcon, NcEmptyContent, NcCheckboxRadioSwitch, NcActions, NcActionButton, NcCounterBubble } from '@nextcloud/vue'
import { VueDraggable } from 'vue-draggable-plus'
import getValidISOstring from '../../services/getValidISOstring.js'
import formatBytes from '../../services/formatBytes.js'

import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'

import PaginationComponent from '../../components/PaginationComponent.vue'

export default {
	name: 'SearchIndex',
	components: {
		NcAppContent,
		NcLoadingIcon,
		NcEmptyContent,
		NcCheckboxRadioSwitch,
		NcActions,
		NcActionButton,
		NcCounterBubble,
		VueDraggable,
		Eye,
		Pencil,
		Delete,
		Plus,
		Refresh,
		PaginationComponent,
		FileTreeOutline,
	},
	data() {
		return {
		}
	},
	computed: {
		pageTitle() {
			if (!registerStore.registerItem) {
				return 'No register selected'
			}

			const registerName = (registerStore.registerItem.label || registerStore.registerItem.title).charAt(0).toUpperCase()
		+ (registerStore.registerItem.label || registerStore.registerItem.title).slice(1)

			if (!schemaStore.schemaItem) {
				return `${registerName} / No schema selected`
			}

			const schemaName = (schemaStore.schemaItem.label || schemaStore.schemaItem.title).charAt(0).toUpperCase()
		+ (schemaStore.schemaItem.label || schemaStore.schemaItem.title).slice(1)
			return `${registerName} / ${schemaName}`
		},

		showNoRegisterWarning() {
			return !registerStore.registerItem
		},
		showNoSchemaWarning() {
			return registerStore.registerItem && !schemaStore.schemaItem
		},
		showNoObjectsMessage() {
			return registerStore.registerItem
				&& schemaStore.schemaItem
				&& !objectStore.loading
				&& !objectStore.objectList?.results?.length
		},
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
		emptyContentName() {
			if (this.showNoRegisterWarning) {
				return 'No register selected'
			} else if (this.showNoSchemaWarning) {
				return 'No schema selected'
			} else if (this.loading) {
				return 'Loading objects...'
			} else if (this.showNoObjectsMessage) {
				return 'No objects found'
			}
			return ''
		},
		emptyContentDescription() {
			if (this.showNoRegisterWarning) {
				return 'Please select a register in the sidebar to view objects'
			} else if (this.showNoSchemaWarning) {
				return 'Please select a schema in the sidebar to view objects'
			} else if (this.loading) {
				return 'Please wait while we fetch your objects.'
			} else if (this.showNoObjectsMessage) {
				return 'There are no objects that match this filter'
			}
			return ''
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
						title: result['@self'].title || result.name || result.title || result['@self'].id,
					},
				})
			} catch (error) {
				console.error('Failed to delete object:', error)
			}
		},
		// default limit to store pagination limit, if this is undefined the limit will be set to 14 underwater
		onPageChange(page, limit = objectStore.pagination.limit) {
			// ensure limit is a number (a custom limit is a string)
			// and handle NaN values (NaN is not a value that can be replaced by the default value in a function)
			limit = Number(limit)
			isNaN(limit) && (limit = undefined) // setPagination handles default values.

			objectStore.setPagination(page, limit)
			objectStore.refreshObjectList()
		},
		onPageChanged(page) {
			this.onPageChange(page)
		},
		onPageSizeChanged(pageSize) {
			this.onPageChange(1, pageSize)
		},
		handleSelectObject(id) {
			if (objectStore.selectedObjects.includes(id)) {
				objectStore.selectedObjects = objectStore.selectedObjects.filter(obj => obj !== id)
			} else {
				objectStore.selectedObjects.push(id)
			}
		},
		bulkDeleteObjects() {
			if (objectStore.selectedObjects.length === 0) return

			// Set the dialog to mass delete
			navigationStore.setDialog('massDeleteObject')
		},
		addObject() {
			// Clear any existing object and open the add object modal
			objectStore.setObjectItem(null)
			navigationStore.setModal('editObject')
		},
		refreshObjects() {
			// Refresh the object list
			objectStore.refreshObjectList()
			// Clear selection after refresh
			objectStore.selectedObjects = []
		},
		getValidISOstring,
		formatBytes,
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
/* Add styles for note cards */
:deep(.notecard) {
    margin-left: 15px;
    margin-right: 15px;
}

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

.sortTarget > th {
    cursor: move;
}

.cursorPointer {
    cursor: pointer !important;
}

input[type="checkbox"] {
    box-shadow: none !important;
}

.paginationContainer {
    display: flex;
    justify-content: center;
    margin-block-start: 1rem;
    margin-inline: 0.5rem;
}

.columnTitle {
    font-weight: bold;
}

.stickyHeader {
    position: sticky;
    left: 0;
}

/* So that the actions menu is not overlapped by the sidebar button when it is closed */
.sidebar-closed {
	margin-right: 45px;
}
</style>
