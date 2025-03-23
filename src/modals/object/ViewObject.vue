/**
 * @file ViewObject.vue
 * @module Modals/Object
 * @author Your Name
 * @copyright 2024 Your Organization
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
import { ref, onMounted, computed } from 'vue'
import {
	NcDialog,
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcEmptyContent,
	NcCounterBubble,
} from '@nextcloud/vue'
import { json } from '@codemirror/lang-json'
import CodeMirror from 'vue-codemirror6'
import { BTabs, BTab } from 'bootstrap-vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import TimelineQuestionOutline from 'vue-material-design-icons/TimelineQuestionOutline.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import ExclamationThick from 'vue-material-design-icons/ExclamationThick.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Upload from 'vue-material-design-icons/Upload.vue'

// Initialize refs
const objectItem = ref(null)
const schemasLoading = ref(false)
const schemas = ref({
	multiple: false,
	closeOnSelect: true,
	options: [],
	value: null,
})
const registersLoading = ref(false)
const registers = ref({})
const success = ref(null)
const loading = ref(false)
const error = ref(false)
const closeModalTimeout = ref(null)
const activeAttachment = ref(null)
const fileLoading = ref(false)
const relationsLoading = ref(false)
const auditTrailLoading = ref(false)

// Computed properties
const hasObjectItem = computed(() => {
	return objectStore.objectItem !== null && 
		   objectStore.objectItem !== undefined && 
		   objectStore.objectItem['@self'] !== undefined
})
const currentObjectItem = computed(() => objectStore.objectItem)
const currentObjectId = computed(() => currentObjectItem.value?.['@self']?.id)

// New computed property for object properties
const objectProperties = computed(() => {
    if (!currentObjectItem.value) return {}
    const { ['@self']: _, ...properties } = currentObjectItem.value
    return properties
})

// New computed property for self properties
const selfProperties = computed(() => {
    if (!currentObjectItem.value?.['@self']) return {}
    return currentObjectItem.value['@self']
})

// Pagination
const pagination = ref({
	files: {
		limit: 200,
		currentPage: 1,
		totalPages: 1,
	},
	auditTrails: {
		limit: 200,
		currentPage: 1,
		totalPages: 1,
	},
	relations: {
		limit: 200,
		currentPage: 1,
		totalPages: 1,
	},
})

// Methods
const closeModal = () => {
	navigationStore.setModal(false)
	clearTimeout(closeModalTimeout.value)
	success.value = null
	loading.value = false
	error.value = false
	objectItem.value = null
}

const getFiles = async () => {
	if (!currentObjectId.value) return
	fileLoading.value = true
	try {
		await objectStore.getFiles(currentObjectId.value, {
			limit: pagination.value.files.limit,
			page: pagination.value.files.currentPage,
		})
	} finally {
		fileLoading.value = false
	}
}

const getAuditTrails = async () => {
	if (!currentObjectId.value) return
	auditTrailLoading.value = true
	try {
		await objectStore.getAuditTrails(currentObjectId.value, {
			limit: pagination.value.auditTrails.limit,
			page: pagination.value.auditTrails.currentPage,
		})
	} finally {
		auditTrailLoading.value = false
	}
}

const getRelations = async () => {
	if (!currentObjectId.value) return
	relationsLoading.value = true
	try {
		await objectStore.getRelations(currentObjectId.value, {
			limit: pagination.value.relations.limit,
			page: pagination.value.relations.currentPage,
		})
	} finally {
		relationsLoading.value = false
	}
}

const openFile = (file) => {
	// Extract the directory path without the filename
	const dirPath = file.path.substring(0, file.path.lastIndexOf('/'))

	// Remove the '/admin/files/' prefix if it exists
	const cleanPath = dirPath.replace(/^\/admin\/files\//, '/')

	// Construct the proper Nextcloud Files app URL with file ID and openfile parameter
	const filesAppUrl = `/index.php/apps/files/files/${file.id}?dir=${encodeURIComponent(cleanPath)}&openfile=true`

	// Open URL in new tab
	window.open(filesAppUrl, '_blank')
}

const formatFileSize = (bytes) => {
	const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']
	if (bytes === 0) return 'n/a'
	const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)))
	if (i === 0 && sizes[i] === 'Bytes') return '< 1 KB'
	if (i === 0) return bytes + ' ' + sizes[i]
	return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i]
}

// Lifecycle hooks
onMounted(() => {
	if (hasObjectItem.value && currentObjectId.value) {
		objectItem.value = currentObjectItem.value
		getFiles()
		getAuditTrails()
		getRelations()
	}
})
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'viewObject' && hasObjectItem"
		:name="'View Object (' + currentObjectItem['@self']?.uuid + ')'"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>Object successfully loaded</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="!success" class="formContainer">
			<!-- Metadata Display -->
			<div v-if="currentObjectItem" class="metadata-grid">
				<div class="metadata-item">
					<span class="label">Version:</span>
					<span class="value">{{ selfProperties.version }}</span>
				</div>
				<div class="metadata-item">
					<span class="label">Created:</span>
					<span class="value">{{ new Date(selfProperties.created).toLocaleString() }}</span>
				</div>
				<div class="metadata-item">
					<span class="label">Updated:</span>
					<span class="value">{{ new Date(selfProperties.updated).toLocaleString() }}</span>
				</div>
				<div class="metadata-item">
					<span class="label">Owner:</span>
					<span class="value">{{ selfProperties.owner || 'Not set' }}</span>
				</div>
				<div class="metadata-item">
					<span class="label">Application:</span>
					<span class="value">{{ selfProperties.application || 'Not set' }}</span>
				</div>
				<div class="metadata-item">
					<span class="label">Organisation:</span>
					<span class="value">{{ selfProperties.organisation || 'Not set' }}</span>
				</div>
			</div>

			<!-- Display Object -->
			<div v-if="currentObjectItem">
				<div class="tabContainer">
					<BTabs content-class="mt-3" justified>
						<BTab title="Properties" active>
							<div class="search-list-table">
								<table class="table">
									<thead>
										<tr class="table-row">
											<th>Property</th>
											<th>Value</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="(value, key) in objectProperties" 
											:key="key"
											class="table-row">
											<td>{{ key }}</td>
											<td>{{ typeof value === 'object' ? JSON.stringify(value) : value }}</td>
										</tr>
									</tbody>
								</table>
							</div>
						</BTab>
						<BTab title="Data">
							<div class="json-editor">
								<label>Object (JSON)</label>
								<div class="codeMirrorContainer">
									<CodeMirror 
										:value="JSON.stringify(currentObjectItem, null, 2)"
										:basic="true"
										placeholder="{ &quot;key&quot;: &quot;value&quot; }"
										:dark="false"
										:lang="json()"
										:tab-size="2" />
								</div>
							</div>
						</BTab>
						<BTab title="Uses">
							<div v-if="currentObjectItem.relations && Object.keys(currentObjectItem.relations).length > 0" class="search-list-table">
								<table class="table">
									<thead>
										<tr class="table-row">
											<th>Relation</th>
											<th>Value</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="(relation, key) in currentObjectItem.relations"
											:key="key"
											class="table-row">
											<td>{{ key }}</td>
											<td>{{ relation }}</td>
										</tr>
									</tbody>
								</table>
							</div>
							<NcEmptyContent v-else>
								No relations found
							</NcEmptyContent>
						</BTab>
						<BTab title="Used by">
							<div v-if="objectStore.relations.length" class="search-list-table">
								<table class="table">
									<thead>
										<tr class="table-row">
											<th>ID</th>
											<th>URI</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="relation in objectStore.relations"
											:key="relation.id"
											class="table-row">
											<td>{{ relation.id }}</td>
											<td>{{ relation.uri }}</td>
										</tr>
									</tbody>
								</table>
								<div v-if="!relationsLoading && objectStore.relations.total > pagination.relations.limit" class="pagination">
									<NcButton @click="pagination.relations.currentPage--" :disabled="pagination.relations.currentPage === 1">
										Previous
									</NcButton>
									<span>Page {{ pagination.relations.currentPage }}</span>
									<NcButton @click="pagination.relations.currentPage++" :disabled="pagination.relations.currentPage >= Math.ceil(objectStore.relations.total / pagination.relations.limit)">
										Next
									</NcButton>
								</div>
							</div>
							<NcEmptyContent v-else>
								No relations found
							</NcEmptyContent>
						</BTab>
						<BTab title="Files">
							<div v-if="objectStore.files.results?.length > 0" class="search-list-table">
								<table class="table">
									<thead>
										<tr class="table-row">
											<th>Name</th>
											<th>Size</th>
											<th>Type</th>
											<th>Labels</th>
											<th>Actions</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="(attachment, i) in objectStore.files.results"
											:key="`${attachment}${i}`"
											:class="{ 'active': activeAttachment === attachment.id }"
											class="table-row"
											@click="() => {
												if (activeAttachment === attachment.id) activeAttachment = null
												else activeAttachment = attachment.id
											}">
											<td>
												<ExclamationThick v-if="!attachment.accessUrl || !attachment.downloadUrl" class="warningIcon" :size="20" />
												<FileOutline v-else class="publishedIcon" :size="20" />
												{{ attachment.name ?? attachment?.title }}
											</td>
											<td>{{ formatFileSize(attachment?.size) }}</td>
											<td>{{ attachment?.type || 'No type' }}</td>
											<td>
												<div class="fileLabelsContainer">
													<NcCounterBubble v-for="label of attachment.labels" :key="label">
														{{ label }}
													</NcCounterBubble>
												</div>
											</td>
											<td>
												<NcButton @click="openFile(attachment)">
													<template #icon>
														<OpenInNew :size="20" />
													</template>
													View file
												</NcButton>
											</td>
										</tr>
									</tbody>
								</table>
								<div v-if="!fileLoading && objectStore.files.total > pagination.files.limit" class="pagination">
									<NcButton @click="pagination.files.currentPage--" :disabled="pagination.files.currentPage === 1">
										Previous
									</NcButton>
									<span>Page {{ pagination.files.currentPage }}</span>
									<NcButton @click="pagination.files.currentPage++" :disabled="pagination.files.currentPage >= Math.ceil(objectStore.files.total / pagination.files.limit)">
										Next
									</NcButton>
								</div>
							</div>
							<NcEmptyContent v-else>
								No attachments added yet
							</NcEmptyContent>
						</BTab>
						<BTab title="Audit Trails">
							<div v-if="objectStore.auditTrails.results?.length" class="search-list-table">
								<table class="table">
									<thead>
										<tr class="table-row">
											<th>Date</th>
											<th>User</th>
											<th>Action</th>
											<th>Changes</th>
											<th>Actions</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="(auditTrail, key) in objectStore.auditTrails.results"
											:key="key"
											class="table-row">
											<td>{{ new Date(auditTrail.created).toLocaleString() }}</td>
											<td>{{ auditTrail.userName }}</td>
											<td>{{ auditTrail.action }}</td>
											<td>{{ Object.keys(auditTrail.changed).length }}</td>
											<td>
												<NcButton @click="objectStore.setAuditTrailItem(auditTrail); navigationStore.setModal('viewObjectAuditTrail')">
													<template #icon>
														<Eye :size="20" />
													</template>
													View details
												</NcButton>
											</td>
										</tr>
									</tbody>
								</table>
								<div v-if="!auditTrailLoading && objectStore.auditTrails.total > pagination.auditTrails.limit" class="pagination">
									<NcButton @click="pagination.auditTrails.currentPage--" :disabled="pagination.auditTrails.currentPage === 1">
										Previous
									</NcButton>
									<span>Page {{ pagination.auditTrails.currentPage }}</span>
									<NcButton @click="pagination.auditTrails.currentPage++" :disabled="pagination.auditTrails.currentPage >= Math.ceil(objectStore.auditTrails.total / pagination.auditTrails.limit)">
										Next
									</NcButton>
								</div>
							</div>
							<NcEmptyContent v-else>
								No audit trails found
							</NcEmptyContent>
						</BTab>
					</BTabs>
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton @click="navigationStore.setModal('editObject'); objectStore.setObjectItem(currentObjectItem)">
				<template #icon>
					<Pencil :size="20" />
				</template>
				Edit Object
			</NcButton>
			<NcButton @click="navigationStore.setModal('addFile'); objectStore.setObjectItem(currentObjectItem)">
				<template #icon>
					<Upload :size="20" />
				</template>
				Add File
			</NcButton>
			<NcButton type="primary" @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Close
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.json-editor {
    position: relative;
	margin-bottom: 2.5rem;
}

.json-editor label {
	display: block;
	margin-bottom: 0.5rem;
	font-weight: bold;
}

/* CodeMirror */
.codeMirrorContainer {
	margin-block-start: 6px;
	border: 1px dotted silver;
}

.codeMirrorContainer :deep(.cm-content) {
	border-radius: 0 !important;
	border: none !important;
}

.codeMirrorContainer :deep(.cm-editor) {
	outline: none !important;
}

/* value text color */
/* string */
.codeMirrorContainer.light :deep(.ͼe) {
	color: #448c27;
}
.codeMirrorContainer.dark :deep(.ͼe) {
	color: #88c379;
}

/* boolean */
.codeMirrorContainer.light :deep(.ͼc) {
	color: #221199;
}
.codeMirrorContainer.dark :deep(.ͼc) {
	color: #8d64f7;
}

/* null */
.codeMirrorContainer.light :deep(.ͼb) {
	color: #770088;
}
.codeMirrorContainer.dark :deep(.ͼb) {
	color: #be55cd;
}

/* number */
.codeMirrorContainer.light :deep(.ͼd) {
	color: #d19a66;
}
.codeMirrorContainer.dark :deep(.ͼd) {
	color: #9d6c3a;
}

/* text cursor */
.codeMirrorContainer :deep(.cm-content) * {
	cursor: text !important;
}

/* selection color */
.codeMirrorContainer.light :deep(.cm-line)::selection,
.codeMirrorContainer.light :deep(.cm-line) ::selection {
	background-color: #d7eaff !important;
    color: black;
}
.codeMirrorContainer.dark :deep(.cm-line)::selection,
.codeMirrorContainer.dark :deep(.cm-line) ::selection {
	background-color: #8fb3e6 !important;
    color: black;
}

/* string */
.codeMirrorContainer.light :deep(.cm-line .ͼe)::selection {
    color: #2d770f;
}
.codeMirrorContainer.dark :deep(.cm-line .ͼe)::selection {
    color: #104e0c;
}

/* boolean */
.codeMirrorContainer.light :deep(.cm-line .ͼc)::selection {
	color: #221199;
}
.codeMirrorContainer.dark :deep(.cm-line .ͼc)::selection {
	color: #4026af;
}

/* null */
.codeMirrorContainer.light :deep(.cm-line .ͼb)::selection {
	color: #770088;
}
.codeMirrorContainer.dark :deep(.cm-line .ͼb)::selection {
	color: #770088;
}

/* number */
.codeMirrorContainer.light :deep(.cm-line .ͼd)::selection {
	color: #8c5c2c;
}
.codeMirrorContainer.dark :deep(.cm-line .ͼd)::selection {
	color: #623907;
}

.fileLabelsContainer {
	display: inline-flex;
	gap: 3px;
}

.warningIcon {
	color: var(--color-warning);
}

.publishedIcon {
	color: var(--color-success);
}

/* Table styles */
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
    background-color: var(--color-background-dark);
    font-weight: bold;
    text-align: left;
}

.table-row:hover {
    background-color: var(--color-background-hover);
}

.table-row.active {
    background-color: var(--color-primary-light);
}

.pagination {
	display: flex;
	justify-content: center;
	align-items: center;
	gap: 1rem;
	margin-top: 1rem;
}

.object-info {
	margin-bottom: 1rem;
}

.object-info > div {
	margin-bottom: 0.5rem;
}

.metadata-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 1rem;
	margin-bottom: 2rem;
	padding: 1rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.metadata-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.5rem;
}

.label {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.value {
	color: var(--color-main-text);
	word-break: break-all;
}
</style>