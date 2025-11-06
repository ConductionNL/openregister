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
			<p v-if="hasSelectedRegisters && hasSelectedSchemas">
				{{ t('openregister', 'Search and browse objects in this schema') }}
			</p>
		</div>

		<!-- Actions Bar -->
		<div v-if="hasSelectedRegisters && hasSelectedSchemas" class="viewActionsBar">
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
					<span v-if="objectStore.objectList?._source" class="sourceIndicator" :class="'source-' + objectStore.objectList._source">
						{{ getSourceLabel(objectStore.objectList._source) }}
					</span>
				</div>
				<div class="viewActions">
					<!-- Mass Actions Dropdown -->
					<NcActions
						:force-name="true"
						:disabled="objectStore.selectedObjects.length === 0"
						:title="objectStore.selectedObjects.length === 0 ? 'Select one or more objects to use mass actions' : `Mass actions (${objectStore.selectedObjects.length} selected)`"
						:menu-name="`Mass Actions (${objectStore.selectedObjects.length})`">
						<template #icon>
							<FormatListChecks :size="20" />
						</template>
						<NcActionButton
							:disabled="objectStore.selectedObjects.length === 0"
							close-after-click
							@click="migrateObjects">
							<template #icon>
								<DatabaseExport :size="20" />
							</template>
							Migrate
						</NcActionButton>
						<NcActionButton
							:disabled="objectStore.selectedObjects.length === 0"
							close-after-click
							@click="bulkCopyObjects">
							<template #icon>
								<ContentCopy :size="20" />
							</template>
							Copy
						</NcActionButton>
						<NcActionButton
							:disabled="objectStore.selectedObjects.length === 0"
							close-after-click
							@click="bulkDeleteObjects">
							<template #icon>
								<Delete :size="20" />
							</template>
							Delete
						</NcActionButton>
						<NcActionButton
							:disabled="objectStore.selectedObjects.length === 0"
							close-after-click
							@click="bulkPublishObjects">
							<template #icon>
								<Publish :size="20" />
							</template>
							Publish
						</NcActionButton>
						<NcActionButton
							:disabled="objectStore.selectedObjects.length === 0"
							close-after-click
							@click="bulkDepublishObjects">
							<template #icon>
								<PublishOff :size="20" />
							</template>
							Depublish
						</NcActionButton>
						<NcActionButton
							:disabled="objectStore.selectedObjects.length === 0"
							close-after-click
							@click="bulkValidateObjects">
							<template #icon>
								<CheckCircle :size="20" />
							</template>
							Validate
						</NcActionButton>
					</NcActions>

					<!-- Regular Actions -->
					<NcActions
						:force-name="true"
						:inline="2"
						menu-name="Actions">
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
		<div v-else-if="objectStore.objectList?.results?.length && hasSelectedRegisters && hasSelectedSchemas" class="searchList">
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
									class="viewTableRow table-row-selectable"
									:class="{ 'table-row-selected': objectStore.selectedObjects.includes(result['@self'].id) }"
									@click="handleRowClick(result['@self'].id, $event)">
									<td class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="objectStore.selectedObjects.includes(result['@self'].id)"
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
											<span v-else-if="column.id === 'meta_name'">
												<span>{{ result['@self'].name || 'N/A' }}</span>
											</span>
											<span v-else-if="column.id === 'meta_description'">
												<span>{{ result['@self'].description || 'N/A' }}</span>
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
													<Pencil :size="20" />
												</template>
												Edit
											</NcActionButton>
											<NcActionButton close-after-click @click="mergeObject(result)">
												<template #icon>
													<Merge :size="20" />
												</template>
												Merge
											</NcActionButton>
											<NcActionButton close-after-click @click="copyObject(result)">
												<template #icon>
													<ContentCopy :size="20" />
												</template>
												Copy
											</NcActionButton>
											<NcActionButton
												v-if="shouldShowPublishAction(result)"
												:disabled="publishingObjects.includes(result['@self'].id)"
												close-after-click
												@click="publishObject(result)">
												<template #icon>
													<NcLoadingIcon v-if="publishingObjects.includes(result['@self'].id)" :size="20" />
													<Publish v-else :size="20" />
												</template>
												Publish
											</NcActionButton>
											<NcActionButton
												v-if="shouldShowDepublishAction(result)"
												:disabled="depublishingObjects.includes(result['@self'].id)"
												close-after-click
												@click="depublishObject(result)">
												<template #icon>
													<NcLoadingIcon v-if="depublishingObjects.includes(result['@self'].id)" :size="20" />
													<PublishOff v-else :size="20" />
												</template>
												Depublish
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

import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import Merge from 'vue-material-design-icons/Merge.vue'
import DatabaseExport from 'vue-material-design-icons/DatabaseExport.vue'
import FormatListChecks from 'vue-material-design-icons/FormatListChecks.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'

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
		Pencil,
		Delete,
		Plus,
		Refresh,
		PaginationComponent,
		FileTreeOutline,
		Merge,
		DatabaseExport,
		FormatListChecks,
		Publish,
		PublishOff,
		CheckCircle,
		ContentCopy,
	},
	data() {
		return {
			publishingObjects: [],
			depublishingObjects: [],
		}
	},
	computed: {
		// Check if registers are selected from URL query params (multi-select)
		selectedRegisterIds() {
			const registerParam = this.$route.query.register
			if (!registerParam) return []
			return Array.isArray(registerParam) 
				? registerParam.map(r => parseInt(r))
				: registerParam.split(',').map(r => parseInt(r.trim()))
		},

		// Check if schemas are selected from URL query params (multi-select)
		selectedSchemaIds() {
			const schemaParam = this.$route.query.schema
			if (!schemaParam) return []
			return Array.isArray(schemaParam)
				? schemaParam.map(s => parseInt(s))
				: schemaParam.split(',').map(s => parseInt(s.trim()))
		},

		hasSelectedRegisters() {
			return this.selectedRegisterIds.length > 0
		},

		hasSelectedSchemas() {
			return this.selectedSchemaIds.length > 0
		},

		pageTitle() {
			if (!this.hasSelectedRegisters) {
				return 'No register selected'
			}

			// Get register names
			const registerNames = this.selectedRegisterIds
				.map(id => {
					const reg = registerStore.registerList.find(r => r.id === id)
					return reg ? (reg.label || reg.title) : null
				})
				.filter(Boolean)
			
			if (registerNames.length === 0) {
				return 'No register selected'
			}

			const registerTitle = registerNames.length === 1 
				? registerNames[0].charAt(0).toUpperCase() + registerNames[0].slice(1)
				: `${registerNames.length} Registers (${registerNames.join(', ')})`

			if (!this.hasSelectedSchemas) {
				return `${registerTitle} / No schema selected`
			}

			// Get schema names
			const schemaNames = this.selectedSchemaIds
				.map(id => {
					const schema = schemaStore.schemaList.find(s => s.id === id)
					return schema ? (schema.label || schema.title) : null
				})
				.filter(Boolean)

			const schemaTitle = schemaNames.length === 1
				? schemaNames[0].charAt(0).toUpperCase() + schemaNames[0].slice(1)
				: `${schemaNames.length} Schemas (${schemaNames.join(', ')})`

			return `${registerTitle} / ${schemaTitle}`
		},

		showNoRegisterWarning() {
			return !this.hasSelectedRegisters
		},
		showNoSchemaWarning() {
			return this.hasSelectedRegisters && !this.hasSelectedSchemas
		},
		showNoObjectsMessage() {
			return this.hasSelectedRegisters
				&& this.hasSelectedSchemas
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
		handleRowClick(id, event) {
			// Don't select if clicking on the checkbox, actions button, or inside actions menu
			if (event.target.closest('.tableColumnCheckbox')
				|| event.target.closest('.tableColumnActions')
				|| event.target.closest('.actionsButton')) {
				return
			}

			// Toggle selection on row click
			this.handleSelectObject(id)
		},
		bulkDeleteObjects() {
			if (objectStore.selectedObjects.length === 0) return

			// Prepare selected objects data for deletion - pass the full object
			const selectedObjectsData = objectStore.objectList.results
				.filter(obj => objectStore.selectedObjects.includes(obj['@self'].id))
				.map(obj => ({
					...obj, // Include the full object data
					id: obj['@self'].id, // Ensure id is available at root level
				}))

			// Store selected objects in the object store for the deletion modal
			objectStore.selectedObjects = selectedObjectsData

			// Set the dialog to mass delete
			navigationStore.setDialog('massDeleteObject')
		},
		migrateObjects() {
			if (objectStore.selectedObjects.length === 0) return

			// Prepare selected objects data for migration - pass the full object
			const selectedObjectsData = objectStore.objectList.results
				.filter(obj => objectStore.selectedObjects.includes(obj['@self'].id))
				.map(obj => ({
					...obj, // Include the full object data
					id: obj['@self'].id, // Ensure id is available at root level
				}))

			// Store selected objects in the object store for the migration modal
			objectStore.selectedObjects = selectedObjectsData

			// Open the migration modal
			navigationStore.setModal('migrationObject')
		},
		addObject() {
			// Clear any existing object
			objectStore.setObjectItem(null)
			
			// Check if registers and schemas are selected
			if (this.selectedRegisterIds.length === 0 || this.selectedSchemaIds.length === 0) {
				showError(this.t('openregister', 'Please select at least one register and schema first'))
				return
			}
			
			// Get the selected registers and schemas
			const selectedRegisters = this.selectedRegisterIds
				.map(id => registerStore.registerList.find(r => r.id === id))
				.filter(Boolean)
			
			const selectedSchemas = this.selectedSchemaIds
				.map(id => schemaStore.schemaList.find(s => s.id === id))
				.filter(Boolean)
			
			// If only one register and one schema, use them directly
			if (selectedRegisters.length === 1 && selectedSchemas.length === 1) {
				registerStore.setRegisterItem(selectedRegisters[0])
				schemaStore.setSchemaItem(selectedSchemas[0])
				
				console.log('Opening add object modal with single register/schema:', {
					register: selectedRegisters[0]?.title,
					schema: selectedSchemas[0]?.title,
					schemaProperties: selectedSchemas[0]?.properties,
				})
				
				navigationStore.setModal('viewObject')
				return
			}
			
			// If multiple registers or schemas, store them for selection in the modal
			objectStore.availableRegistersForNewObject = selectedRegisters
			objectStore.availableSchemasForNewObject = selectedSchemas
			
			console.log('Opening add object modal with multiple options:', {
				registers: selectedRegisters.map(r => r.title),
				schemas: selectedSchemas.map(s => s.title),
			})
			
			navigationStore.setModal('viewObject')
		},
		refreshObjects() {
			// Refresh the object list
			objectStore.refreshObjectList()
			// Clear selection after refresh
			objectStore.selectedObjects = []
		},
		mergeObject(sourceObject) {
			// Set the source object for merging and open the merge modal
			objectStore.setObjectItem(sourceObject)
			navigationStore.setModal('mergeObject')
		},
		copyObject(sourceObject) {
			// Set the source object for copying and open the copy dialog
			objectStore.setObjectItem(sourceObject)
			navigationStore.setDialog('copyObject')
		},
		getValidISOstring,
		formatBytes,
		getSourceLabel(source) {
			const sourceLabels = {
				index: '🔍 SOLR Index',
				database: '💾 Database',
				auto: '🤖 Auto',
			}
			return sourceLabels[source] || source
		},
		/**
		 * Publish a single object
		 * @param {object} result - The object to publish
		 */
		async publishObject(result) {
			const objectId = result['@self'].id

			if (this.publishingObjects.includes(objectId)) {
				return // Already publishing
			}

			try {
				this.publishingObjects.push(objectId)

				const publishedDate = new Date().toISOString()

				await objectStore.publishObject({
					register: result['@self'].register,
					schema: result['@self'].schema,
					objectId,
					publishedDate,
				})

			} catch (error) {
				console.error('Failed to publish object:', error)
			} finally {
				this.publishingObjects = this.publishingObjects.filter(id => id !== objectId)
			}
		},
		/**
		 * Depublish a single object
		 * @param {object} result - The object to depublish
		 */
		async depublishObject(result) {
			const objectId = result['@self'].id

			if (this.depublishingObjects.includes(objectId)) {
				return // Already depublishing
			}

			try {
				this.depublishingObjects.push(objectId)

				const depublishedDate = new Date().toISOString()

				await objectStore.depublishObject({
					register: result['@self'].register,
					schema: result['@self'].schema,
					objectId,
					depublishedDate,
				})

			} catch (error) {
				console.error('Failed to depublish object:', error)
			} finally {
				this.depublishingObjects = this.depublishingObjects.filter(id => id !== objectId)
			}
		},
		/**
		 * Open bulk publish modal
		 */
		bulkPublishObjects() {
			if (objectStore.selectedObjects.length === 0) return

			// Prepare selected objects data for publishing - pass the full object
			const selectedObjectsData = objectStore.objectList.results
				.filter(obj => objectStore.selectedObjects.includes(obj['@self'].id))
				.map(obj => ({
					...obj, // Include the full object data
					id: obj['@self'].id, // Ensure id is available at root level
				}))

			// Store selected objects in the object store for the publish modal
			objectStore.selectedObjects = selectedObjectsData

			// Open the mass publish modal
			navigationStore.setDialog('massPublishObjects')
		},
		/**
		 * Open bulk depublish modal
		 */
		bulkDepublishObjects() {
			if (objectStore.selectedObjects.length === 0) return

			// Prepare selected objects data for depublishing - pass the full object
			const selectedObjectsData = objectStore.objectList.results
				.filter(obj => objectStore.selectedObjects.includes(obj['@self'].id))
				.map(obj => ({
					...obj, // Include the full object data
					id: obj['@self'].id, // Ensure id is available at root level
				}))

			// Store selected objects in the object store for the depublish modal
			objectStore.selectedObjects = selectedObjectsData

			// Open the mass depublish modal
			navigationStore.setDialog('massDepublishObjects')
		},
		/**
		 * Open bulk validate modal
		 */
		bulkValidateObjects() {
			if (objectStore.selectedObjects.length === 0) return

			// Prepare selected objects data for validation - pass the full object
			const selectedObjectsData = objectStore.objectList.results
				.filter(obj => objectStore.selectedObjects.includes(obj['@self'].id))
				.map(obj => ({
					...obj, // Include the full object data
					id: obj['@self'].id, // Ensure id is available at root level
				}))

			// Store selected objects in the object store for the validate modal
			objectStore.selectedObjects = selectedObjectsData

			// Open the mass validate modal
			navigationStore.setDialog('massValidateObjects')
		},
		bulkCopyObjects() {
			if (objectStore.selectedObjects.length === 0) return

			// Prepare selected objects data for copying - pass the full object
			const selectedObjectsData = objectStore.objectList.results
				.filter(obj => objectStore.selectedObjects.includes(obj['@self'].id))
				.map(obj => ({
					...obj, // Include the full object data
					id: obj['@self'].id, // Ensure id is available at root level
				}))

			// Store selected objects in the object store for the copy modal
			objectStore.selectedObjects = selectedObjectsData

			// Open the mass copy modal
			navigationStore.setDialog('massCopyObjects')
		},
		/**
		 * Check if an object should show the publish action
		 * Show publish if: no published date OR has depublished date
		 * @param {object} result - The object to check
		 * @return {boolean} True if publish action should be shown
		 */
		shouldShowPublishAction(result) {
			const published = result['@self'].published
			const depublished = result['@self'].depublished

			// Show publish if not published OR if depublished
			return !published || depublished
		},
		/**
		 * Check if an object should show the depublish action
		 * Show depublish if: has published date AND no depublished date
		 * @param {object} result - The object to check
		 * @return {boolean} True if depublish action should be shown
		 */
		shouldShowDepublishAction(result) {
			const published = result['@self'].published
			const depublished = result['@self'].depublished

			// Show depublish if published AND not depublished
			return published && !depublished
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
/* Add styles for note cards */
:deep(.notecard) {
    margin-left: 15px;
    margin-right: 15px;
}

/* Fix checkbox layout in table */
.tableColumnCheckbox {
	padding: 8px !important;
}

.tableColumnCheckbox :deep(.checkbox-radio-switch) {
	margin: 0;
	display: flex;
	align-items: center;
	justify-content: center;
}

.tableColumnCheckbox :deep(.checkbox-radio-switch__content) {
	margin: 0;
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

/* Row selection styling */
.table-row-selectable {
	cursor: pointer;
}

.table-row-selectable:hover {
	background-color: var(--color-background-hover);
}

.table-row-selected {
	background-color: var(--color-primary-light) !important;
}

/* Source indicator styling */
.sourceIndicator {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 500;
	margin-left: 8px;
}

.source-index {
	background-color: var(--color-success-light);
	color: var(--color-success-dark);
}

.source-database {
	background-color: var(--color-warning-light);
	color: var(--color-warning-dark);
}

.source-auto {
	background-color: var(--color-info-light);
	color: var(--color-info-dark);
}
</style>
