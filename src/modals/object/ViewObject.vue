/**
 * @file ViewObject.vue
 * @module Modals/Object
 * @author Your Name
 * @copyright 2024 Your Organization
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

<script setup>
import { objectStore, navigationStore, registerStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'viewObject'"
		:name="'View Object (' + objectStore.objectItem.title + ')'"
		size="large"
		:can-close="false">
		<div class="formContainer">
			<!-- Metadata Display -->
			<div class="detail-item id-item" :class="{ 'empty-value': !objectStore.objectItem['@self'].id }">
				<span class="detail-label">ID:</span>
				<span class="detail-value">{{ objectStore.objectItem.id }}</span>
				<NcButton @click="copyToClipboard(objectStore.objectItem.id)">
					<template #icon>
						<Check v-if="isCopied" :size="20" />
						<ContentCopy v-else :size="20" />
					</template>
					{{ isCopied ? 'Copied' : 'Copy' }}
				</NcButton>
			</div>
			<div class="detail-grid">
				<div class="detail-item" :class="{ 'empty-value': !objectStore.objectItem['@self'].register }">
					<span class="detail-label">Register:</span>
					<span class="detail-value">{{ registerTitle }}</span>
				</div>
				<div class="detail-item" :class="{ 'empty-value': !objectStore.objectItem['@self'].schema }">
					<span class="detail-label">Schema:</span>
					<span class="detail-value">{{ schemaTitle }}</span>
				</div>
				<div class="detail-item" :class="{ 'empty-value': !objectStore.objectItem['@self'].version }">
					<span class="detail-label">Version:</span>
					<span class="detail-value">{{ objectStore.objectItem['@self'].version || 'Not set' }}</span>
				</div>
				<div class="detail-item">
					<span class="detail-label">Created:</span>
					<span class="detail-value">{{ new Date(objectStore.objectItem['@self'].created).toLocaleString() }}</span>
				</div>
				<div class="detail-item">
					<span class="detail-label">Updated:</span>
					<span class="detail-value">{{ new Date(objectStore.objectItem['@self'].updated).toLocaleString() }}</span>
				</div>
				<div class="detail-item" :class="{ 'empty-value': !objectStore.objectItem['@self'].owner }">
					<span class="detail-label">Owner:</span>
					<span class="detail-value">{{ objectStore.objectItem['@self'].owner || 'Not set' }}</span>
				</div>
				<div class="detail-item" :class="{ 'empty-value': !objectStore.objectItem['@self'].application }">
					<span class="detail-label">Application:</span>
					<span class="detail-value">{{ objectStore.objectItem['@self'].application || 'Not set' }}</span>
				</div>
				<div class="detail-item" :class="{ 'empty-value': !objectStore.objectItem['@self'].organisation }">
					<span class="detail-label">Organisation:</span>
					<span class="detail-value">{{ objectStore.objectItem['@self'].organisation || 'Not set' }}</span>
				</div>
			</div>

			<!-- Display Object -->
			<div v-if="objectStore.objectItem">
				<div class="tabContainer">
					<BTabs content-class="mt-3" justified>
						<BTab title="Properties" active @click="activeTab = 'Properties'">
							<div class="search-list-table">
								<table class="table">
									<thead>
										<tr class="table-row">
											<th>Property</th>
											<th>Value</th>
										</tr>
									</thead>
									<tbody>
										<tr
											v-for="([key, value]) in objectProperties"
											:key="key"
											class="table-row">
											<td class="prop-cell">
												{{ key }}
											</td>
											<td class="value-cell">
												<pre
													v-if="typeof value === 'object' && value !== null"
													class="json-value">{{ formatValue(value) }}</pre>
												<span v-else-if="isValidDate(value)">{{ new Date(value).toLocaleString() }}</span>
												<span v-else>{{ value }}</span>
											</td>
										</tr>
									</tbody>
								</table>
							</div>
						</BTab>
						<BTab title="Data" @click="activeTab = 'Data'">
							<div class="json-editor">
								<label>Object (JSON)</label>
								<div :class="`codeMirrorContainer ${getTheme()}`">
									<CodeMirror
										v-model="editorContent"
										:basic="true"
										:linter="jsonParseLinter()"
										:lang="json()"
										:readonly="true"
										:dark="getTheme() === 'dark'"
										:tab-size="2"
										style="height: 400px" />
								</div>
							</div>
						</BTab>
						<BTab title="Uses" @click="activeTab = 'Uses'">
							<div v-if="objectStore.uses.results.length > 0" class="search-list-table">
								<table class="table">
									<thead>
										<tr class="table-row">
											<th>ID</th>
											<th>URI</th>
											<th>Schema</th>
											<th>Register</th>
											<th>Actions</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="use in objectStore.uses.results"
											:key="use['@self'].id"
											class="table-row">
											<td>{{ use['@self'].id }}</td>
											<td>{{ use['@self'].uri }}</td>
											<td>{{ use['@self'].schema }}</td>
											<td>{{ use['@self'].register }}</td>
											<td>
												<NcButton @click="objectStore.setObjectItem(use); navigationStore.setModal('viewObject')">
													<template #icon>
														<Eye :size="20" />
													</template>
													View Object
												</NcButton>
											</td>
										</tr>
									</tbody>
								</table>
							</div>
							<NcNoteCard v-else type="info">
								<p>No uses found for this object</p>
							</NcNoteCard>
						</BTab>
						<BTab title="Used by" @click="activeTab = 'Used by'">
							<div v-if="objectStore.used.results.length > 0" class="search-list-table">
								<table class="table">
									<thead>
										<tr class="table-row">
											<th>ID</th>
											<th>URI</th>
											<th>Schema</th>
											<th>Register</th>
											<th>Actions</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="usedBy in objectStore.used.results"
											:key="usedBy['@self'].id"
											class="table-row">
											<td>{{ usedBy['@self'].id }}</td>
											<td>{{ usedBy['@self'].uri }}</td>
											<td>{{ usedBy['@self'].schema }}</td>
											<td>{{ usedBy['@self'].register }}</td>
											<td>
												<NcButton @click="objectStore.setObjectItem(usedBy); navigationStore.setModal('viewObject')">
													<template #icon>
														<Eye :size="20" />
													</template>
													View Object
												</NcButton>
											</td>
										</tr>
									</tbody>
								</table>
							</div>
							<NcNoteCard v-else type="info">
								<p>No objects are using this object</p>
							</NcNoteCard>
						</BTab>
						<BTab title="Contracts" @click="activeTab = 'Contracts'">
							<div v-if="objectStore.contracts.length > 0" class="search-list-table">
								<table class="table">
									<thead>
										<tr class="table-row">
											<th>ID</th>
											<th>URI</th>
											<th>Schema</th>
											<th>Register</th>
											<th>Actions</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="contract in objectStore.contracts"
											:key="contract['@self'].id"
											class="table-row">
											<td>{{ contract['@self'].id }}</td>
											<td>{{ contract['@self'].uri }}</td>
											<td>{{ contract['@self'].schema }}</td>
											<td>{{ contract['@self'].register }}</td>
											<td>
												<NcButton @click="objectStore.setObjectItem(contract); navigationStore.setModal('viewObject')">
													<template #icon>
														<Eye :size="20" />
													</template>
													View Object
												</NcButton>
											</td>
										</tr>
									</tbody>
								</table>
							</div>
							<NcNoteCard v-else type="info">
								<p>No contracts found for this object</p>
							</NcNoteCard>
						</BTab>
						<BTab title="Files" @click="activeTab = 'Files'">
							<div v-if="objectStore.files.results?.length > 0" class="search-list-table">
								<table class="table">
									<thead>
										<tr class="table-row">
											<th />
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
												<NcCheckboxRadioSwitch
													:checked="selectedAttachments.includes(attachment.id)"
													@update:checked="toggleSelection(attachment)" />
											</td>
											<td class="table-row-title">
												<!-- Show lock icon if file is not shared -->
												<LockOutline v-if="!attachment.accessUrl && !attachment.downloadUrl"
													v-tooltip="'Not shared'"
													class="notSharedIcon"
													:size="20" />
												<!-- Show published icon if file is shared -->
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
							</div>
							<NcNoteCard v-else type="info">
								<p>No files have been attached to this object</p>
							</NcNoteCard>
						</BTab>
						<BTab title="Audit Trails" @click="activeTab = 'Audit Trails'">
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
							</div>
							<NcNoteCard v-else type="info">
								<p>No audit trails found for this object</p>
							</NcNoteCard>
						</BTab>
					</BTabs>
				</div>
			</div>
		</div>

		<template #actions>
			<NcActions
				v-if="objectStore.files?.results?.length > 0 && activeTab === 'Files'"
				:primary="true"
				:disabled="true"
				:menu-name="loading ? 'Laden...' : 'Acties'"
				class="checkboxListActionButton"
				:inline="0"
				title="Acties die je kan uitvoeren op deze publicatie">
				<template #icon>
					<span>
						<DotsHorizontal v-if="!loading" :size="20" />
						<NcLoadingIcon v-if="loading" :size="20" appearance="dark" />
					</span>
				</template>
				<NcActionButton @click="selectAllAttachments('published')">
					<template #icon>
						<SelectAllIcon v-if="!allPublishedSelected" :size="20" />
						<SelectRemove v-else :size="20" />
					</template>
					{{ !allPublishedSelected ? "Selecteer" : "Deselecteer" }} alle gepubliceerde bijlagen
				</NcActionButton>
				<NcActionButton @click="selectAllAttachments('unpublished')">
					<template #icon>
						<SelectAllIcon v-if="!allUnpublishedSelected" :size="20" />
						<SelectRemove v-else :size="20" />
					</template>
					{{ !allUnpublishedSelected ? "Selecteer" : "Deselecteer" }} alle ongepubliceerde bijlagen
				</NcActionButton>
			</NcActions>
			<NcButton @click="navigationStore.setModal('editObject'); objectStore.setObjectItem(objectStore.objectItem)">
				<template #icon>
					<Pencil :size="20" />
				</template>
				Edit Object
			</NcButton>
			<NcButton @click="navigationStore.setModal('uploadFiles'); objectStore.setObjectItem(objectStore.objectItem)">
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

<script>
import {
	NcDialog,
	NcButton,
	NcActions,
	NcActionButton,
	NcNoteCard,
	NcCounterBubble,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import { json, jsonParseLinter } from '@codemirror/lang-json'
import CodeMirror from 'vue-codemirror6'
import { BTabs, BTab } from 'bootstrap-vue'
import { getTheme } from '../../services/getTheme.js'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Check from 'vue-material-design-icons/Check.vue'
import SelectAllIcon from 'vue-material-design-icons/SelectAll.vue'
import SelectRemove from 'vue-material-design-icons/SelectRemove.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
export default {
	name: 'ViewObject',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcCounterBubble,
		NcActions,
		NcActionButton,
		NcCheckboxRadioSwitch,
		CodeMirror,
		BTabs,
		BTab,
		Cancel,
		FileOutline,
		OpenInNew,
		Eye,
		Pencil,
		Upload,
		LockOutline,
		ContentCopy,
		Check,
		SelectAllIcon,
		SelectRemove,
		DotsHorizontal,
		NcLoadingIcon,
	},
	data() {
		return {
			closeModalTimeout: null,
			activeAttachment: null,
			registerTitle: '',
			schemaTitle: '',
			isUpdated: false,
			isCopied: false,
			activeTab: 'Properties',
			selectedAttachments: [],
			publishLoading: [],
			depublishLoading: [],
			fileIdsLoading: [],
		}
	},
	computed: {
		objectProperties() {
			// Return array of [key, value] pairs, excluding '@self'
			if (!objectStore?.objectItem) return []
			return Object.entries(objectStore.objectItem).filter(([key]) => key !== '@self')
		},
		editorContent() {
			return JSON.stringify(objectStore.objectItem, null, 2)
		},
		selectedPublishedCount() {
			return this.selectedAttachments.filter((a) => {
				const found = objectStore.files.results
					?.find(item => item.id === a)
				if (!found) return false

				return !!found.published
			}).length
		},
		selectedUnpublishedCount() {
			return this.selectedAttachments.filter((a) => {
				const found = objectStore.files.results
					?.find(item => item.id === a)
				if (!found) return false
				return found.published === null
			}).length
		},
		allPublishedSelected() {
			const published = objectStore.files.results
				?.filter(item => !!item.published)
				.map(item => item.id) || []

			if (!published.length) {
				return false
			}
			return published.every(pubId => this.selectedAttachments.includes(pubId))
		},
		allUnpublishedSelected() {
			const unpublished = objectStore.files.results
				?.filter(item => !item.published)
				.map(item => item.id) || []

			if (!unpublished.length) {
				return false
			}
			return unpublished.every(unpubId => this.selectedAttachments.includes(unpubId))
		},
		loading() {
			return this.publishLoading.length > 0 || this.depublishLoading.length > 0 || this.fileIdsLoading.length > 0
		},
	},
	updated() {
		if (!this.isUpdated && navigationStore.modal === 'viewObject') {
			this.isUpdated = true
			this.loadTitles()
			this.loadProperties()
		}
	},
	methods: {
		async loadTitles() {
			const register = await registerStore.getRegister(objectStore.objectItem['@self'].register)
			const schema = await schemaStore.getSchema(objectStore.objectItem['@self'].schema)

			this.registerTitle = register?.title || 'Not set'
			this.schemaTitle = schema?.title || 'Not set'
		},
		closeModal() {
			navigationStore.setModal(null)
			this.isUpdated = false
			this.registerTitle = ''
			this.schemaTitle = ''
		},
		/**
		 * Open a file in the Nextcloud Files app
		 * @param {object} file - The file object to open
		 */
		openFile(file) {
			const dirPath = file.path.substring(0, file.path.lastIndexOf('/'))
			const cleanPath = dirPath.replace(/^\/admin\/files\//, '/')
			const filesAppUrl = `/index.php/apps/files/files/${file.id}?dir=${encodeURIComponent(cleanPath)}&openfile=true`
			window.open(filesAppUrl, '_blank')
		},
		/**
		 * Format file size for display
		 * @param {number} bytes - The file size in bytes
		 * @return {string} The formatted file size
		 */
		formatFileSize(bytes) {
			const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']
			if (bytes === 0) return 'n/a'
			const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)))
			if (i === 0 && sizes[i] === 'Bytes') return '< 1 KB'
			if (i === 0) return bytes + ' ' + sizes[i]
			return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i]
		},
		isValidDate(value) {
			if (!value) return false
			const date = new Date(value)
			return date instanceof Date && !isNaN(date)
		},
		formatValue(val) {
			return JSON.stringify(val, null, 2)
		},
		toggleSelection(attachment) {
			const numericId = Number(attachment.id)
			if (this.selectedAttachments.includes(numericId)) {
				this.selectedAttachments = this.selectedAttachments.filter(itemId => itemId !== numericId)
			} else {
				this.selectedAttachments.push(numericId)
			}
		},
		selectAllAttachments(mode) {
			if (mode === 'published') {
				const publishedIds = objectStore.files.results
					?.filter(item => item.published)
					.map(item => Number(item.id)) || []

				const allSelected = publishedIds.length > 0 && publishedIds.every(id => this.selectedAttachments.includes(id))

				if (!allSelected) {
					this.selectedAttachments = Array.from(new Set([...this.selectedAttachments, ...publishedIds]))
				} else {
					this.selectedAttachments = this.selectedAttachments.filter(id => !publishedIds.includes(id))
				}
			} else if (mode === 'unpublished') {
				const unpublishedIds = objectStore.files.results
					?.filter(item => !item.published)
					.map(item => Number(item.id)) || []

				const allSelected = unpublishedIds.length > 0 && unpublishedIds.every(id => this.selectedAttachments.includes(id))

				if (!allSelected) {
					this.selectedAttachments = Array.from(new Set([...this.selectedAttachments, ...unpublishedIds]))
				} else {
					this.selectedAttachments = this.selectedAttachments.filter(id => !unpublishedIds.includes(id))
				}
			}
		},
		getTheme,
		async copyToClipboard(text) {
			try {
				await navigator.clipboard.writeText(text)
				this.isCopied = true
				setTimeout(() => { this.isCopied = false }, 2000)
			} catch (err) {
				console.error('Failed to copy text:', err)
			}
		},
	},
}
</script>

<style>
.modal-wrapper--large > .modal-container[data-v-d5334a44] {
	width: 1000px;
}
</style>

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
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.codeMirrorContainer :deep(.cm-editor) {
	height: 100%;
}

.codeMirrorContainer :deep(.cm-scroller) {
	overflow: auto;
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
	background-color: var(--color-background-hover);
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

.table-row-title {
	display: flex;
	align-items: center;
	gap: 10px;
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

.detail-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); /* Responsive columns */
	gap: 16px;
	margin-bottom: 20px; /* Remove auto, use 0 for left/right */
	padding: 0 20px; /* Add horizontal padding to match modal */
	width: 100%;
	box-sizing: border-box;
}

.detail-item {
	display: flex;
	flex-direction: column;
	padding: 12px;  /* Slightly increased padding */
	background-color: var(--color-background-hover);
	border-radius: 4px;
	border-left: 3px solid var(--color-primary);
}

.id-item {
	flex-direction: row;
	gap: 10px;
	align-items: center;
	justify-content: space-between;
	margin: 20px 20px 10px;
}

.search-list-table {
	overflow-x: auto;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	box-shadow: 0 2px 6px rgba(0,0,0,.08);
}

.table-row > th {
	padding: 10px;
	background: var(--color-primary-light);
}

.table tbody tr:nth-child(odd) {
	background: var(--color-background-light);
}

.table tbody tr:hover {
	background: var(--color-background-hover);
}

.prop-cell   {
	width: 30%;
	font-weight: 600;
	border-left: 3px solid var(--color-primary);
}
.value-cell  {
	width: 70%;
	word-break: break-word;
	border-radius: 4px;
}

.json-value {
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: 4px;
	padding: 6px 8px;
	margin: 6px;
	white-space: pre-wrap;
	font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
	font-size: .875rem;
	line-height: 1.35;
}

.detail-item.empty-value {
	border-left-color: var(--color-warning);
}

.detail-label {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.detail-value {
	word-break: break-word;
}

/* Remove the old section container and metadata styles */
.section-container,
.metadata-grid,
.metadata-item,
.label,
.value {
	display: none;
}

/* CodeMirror */
.codeMirrorContainer {
	margin-block-start: 6px;
}

.codeMirrorContainer :deep(.cm-content) {
	border-radius: 0 !important;
	border: none !important;
}
.codeMirrorContainer :deep(.cm-editor) {
	outline: none !important;
}
.codeMirrorContainer.light > .vue-codemirror {
	border: 1px dotted silver;
}
.codeMirrorContainer.dark > .vue-codemirror {
	border: 1px dotted grey;
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
</style>
