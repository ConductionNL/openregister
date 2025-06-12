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
		<div class="formContainer viewObjectDialog">
			<!-- Metadata Display -->
			<div class="detail-grid">
				<div class="detail-item id-card" :class="{ 'empty-value': !objectStore.objectItem.id }">
					<div class="id-card-header">
						<span class="detail-label">ID:</span>
						<NcButton class="copy-button" @click="copyToClipboard(objectStore.objectItem.id)">
							<template #icon>
								<Check v-if="isCopied" :size="20" />
								<ContentCopy v-else :size="20" />
							</template>
							{{ isCopied ? 'Copied' : 'Copy' }}
						</NcButton>
					</div>
					<span class="detail-value">{{ objectStore.objectItem.id }}</span>
				</div>
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
				<div class="detail-item" :class="{ 'empty-value': !objectStore.objectItem['@self'].published }">
					<span class="detail-label">Published:</span>
					<span class="detail-value">{{ objectStore.objectItem['@self'].published ? new Date(objectStore.objectItem['@self'].published).toLocaleString() : 'Not published' }}</span>
				</div>
				<div class="detail-item" :class="{ 'empty-value': !objectStore.objectItem['@self'].depublished }">
					<span class="detail-label">Depublished:</span>
					<span class="detail-value">{{ objectStore.objectItem['@self'].depublished ? new Date(objectStore.objectItem['@self'].depublished).toLocaleString() : 'Not depublished' }}</span>
				</div>
				<div class="detail-item" :class="{ 'empty-value': objectStore.objectItem['@self'].validation === null }">
					<span class="detail-label">Validation:</span>
					<span class="detail-value">{{ objectStore.objectItem['@self'].validation !== null ? (objectStore.objectItem['@self'].validation ? 'Valid' : 'Invalid') : 'Not validated' }}</span>
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
					<BTabs v-model="activeTab" content-class="mt-3" justified>
						<BTab title="Properties" active>
							<div class="viewTableContainer">
								<table class="viewTable">
									<thead>
										<tr class="viewTableRow">
											<th class="tableColumnConstrained">Property</th>
											<th class="tableColumnExpanded">Value</th>
										</tr>
									</thead>
									<tbody>
										<tr
											v-for="([key, value]) in objectProperties"
											:key="key"
											class="viewTableRow">
											<td class="tableColumnConstrained prop-cell">
												{{ key }}
											</td>
											<td class="tableColumnExpanded value-cell">
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
						<BTab title="Data">
							<div class="json-editor">
								<label>Object (JSON)</label>
								<div :class="`codeMirrorContainer ${getTheme()}`">
									<CodeMirror
										:model-value="editorContent"
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
						<BTab title="Edit">
							<div class="tabContainer">
								<BTabs v-model="editorTab" content-class="mt-3" justified>
									<BTab title="Form Editor" active>
										<div v-if="currentSchema && currentSchema.properties" class="form-editor">
											<NcNoteCard v-if="success" type="success" class="note-card">
												<p>Object successfully modified</p>
											</NcNoteCard>
											<div v-for="(value, key) in currentSchema.properties"
												:key="key"
												class="form-field">
												<div v-if="value && value.type === 'string'" class="field-label-row">
													<NcTextField
														v-model="formData[key] "
														:label="objectStore.enabledColumns.find(c => c.key === key)?.label || key"
														:placeholder="key"
														:helper-text="objectStore.enabledColumns.find(c => c.key === key)?.description || key" />
													<NcButton
														v-if="(key === 'id' || key === 'uri') && formData[key]"
														class="copy-button"
														size="small"
														@click="copyToClipboard(formData[key])">
														<template #icon>
															<ContentCopy :size="16" />
														</template>
													</NcButton>
												</div>

												<NcCheckboxRadioSwitch v-else-if="value && value.type === 'boolean'"
													v-model="formData[key]"
													:label="objectStore.enabledColumns.find(c => c.key === key)?.label || key"
													type="switch" />
												<NcTextField v-else-if="value && value.type === 'number'"
													v-model.number="formData[key]"
													:label="objectStore.enabledColumns.find(c => c.key === key)?.label || key"
													type="number" />

												<template v-else-if="value && value.type === 'array'">
													<label class="field-label">
														{{ objectStore.enabledColumns.find(c => c.key === key)?.label || key }}
													</label>
													<ul class="array-editor">
														<li v-for="(item, i) in formData[key] || []" :key="i">
															<NcTextField v-model="formData[key][i]"
																class="array-item-input" />
															<NcButton size="small"
																@click="removeArrayItem(key, i)">
																<template #icon>
																	<Delete :size="16" />
																</template>
															</NcButton>
														</li>
													</ul>
													<NcButton size="small"
														@click="addArrayItem(key)">
														<template #icon>
															<Plus :size="16" />
														</template>
														Add element
													</NcButton>
												</template>

												<template v-else-if="value && value.type === 'object' && value !== null">
													<label class="field-label">
														{{ objectStore.enabledColumns.find(c => c.key === key)?.label || key }}
													</label>
													<CodeMirror
														:model-value="objectEditors[key]"
														:basic="true"
														:dark="getTheme() === 'dark'"
														:lang="json()"
														:tab-size="2"
														@update:model-value="val => updateObjectField(key, val)" />
												</template>

												<NcTextField v-else
													v-model="formData[key]"
													:label="objectStore.enabledColumns.find(c => c.key === key)?.label || key"
													:placeholder="key"
													:helper-text="objectStore.enabledColumns.find(c => c.key === key)?.description || key" />
											</div>
										</div>
										<NcEmptyContent v-else>
											<template v-if="!currentSchema">
												Please select a schema to edit the object
											</template>
											<template v-else>
												This schema has no properties defined for editing
											</template>
										</NcEmptyContent>
									</BTab>
									<BTab title="JSON Editor">
										<NcNoteCard v-if="success" type="success" class="note-card">
											<p>Object successfully modified</p>
										</NcNoteCard>
										<div class="json-editor">
											<div :class="`codeMirrorContainer ${getTheme()}`">
												<CodeMirror
													v-model="jsonData"
													:basic="true"
													placeholder="{ &quot;key&quot;: &quot;value&quot; }"
													:dark="getTheme() === 'dark'"
													:linter="jsonParseLinter()"
													:lang="json()"
													:extensions="[json()]"
													:tab-size="2"
													style="height: 400px" />
												<NcButton
													class="format-json-button"
													type="secondary"
													size="small"
													@click="formatJSON">
													Format JSON
												</NcButton>
											</div>
											<span v-if="!isValidJson(jsonData)" class="error-message">
												Invalid JSON format
											</span>
										</div>
									</BTab>
								</BTabs>
							</div>
						</Btab>
						<BTab title="Uses">
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
						<BTab title="Used by">
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
						<BTab title="Contracts">
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
						<BTab title="Files">
							<div v-if="paginatedFiles.length > 0" class="viewTableContainer">
								<table class="viewTable">
									<thead>
										<tr class="viewTableRow">
											<th class="tableColumnCheckbox">
												<NcCheckboxRadioSwitch
													:checked="allFilesSelected"
													:indeterminate="someFilesSelected"
													@update:checked="toggleSelectAllFiles" />
											</th>
											<th class="tableColumnExpanded">Name</th>
											<th class="tableColumnConstrained">Size</th>
											<th class="tableColumnConstrained">Type</th>
											<th class="tableColumnConstrained">Labels</th>
											<th class="tableColumnActions">
												<NcActions v-if="selectedAttachments.length > 0" force-menu>
													<template #icon>
														<DotsHorizontal :size="20" />
													</template>
													<NcActionButton close-after-click @click="publishSelectedFiles">
														<template #icon>
															<FileOutline :size="20" />
														</template>
														Publish {{ selectedAttachments.length }} file{{ selectedAttachments.length > 1 ? 's' : '' }}
													</NcActionButton>
													<NcActionButton close-after-click @click="depublishSelectedFiles">
														<template #icon>
															<LockOutline :size="20" />
														</template>
														Depublish {{ selectedAttachments.length }} file{{ selectedAttachments.length > 1 ? 's' : '' }}
													</NcActionButton>
													<NcActionButton close-after-click type="error" @click="deleteSelectedFiles">
														<template #icon>
															<Delete :size="20" />
														</template>
														Delete {{ selectedAttachments.length }} file{{ selectedAttachments.length > 1 ? 's' : '' }}
													</NcActionButton>
												</NcActions>
												<span v-else>Actions</span>
											</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="(attachment, i) in paginatedFiles"
											:key="`${attachment.id}${i}`"
											:class="{ 'active': activeAttachment === attachment.id }"
											class="viewTableRow"
											@click="() => {
												if (activeAttachment === attachment.id) activeAttachment = null
												else activeAttachment = attachment.id
											}">
											<td class="tableColumnCheckbox">
												<NcCheckboxRadioSwitch
													:checked="selectedAttachments.includes(attachment.id)"
													@update:checked="(checked) => toggleFileSelection(attachment.id, checked)" />
											</td>
											<td class="tableColumnExpanded table-row-title">
												<!-- Show lock icon if file is not shared -->
												<LockOutline v-if="!attachment.accessUrl && !attachment.downloadUrl"
													v-tooltip="'Not shared'"
													class="notSharedIcon"
													:size="20" />
												<!-- Show published icon if file is shared -->
												<FileOutline v-else class="publishedIcon" :size="20" />
												{{ attachment.name ?? attachment?.title }}
											</td>
											<td class="tableColumnConstrained">{{ formatFileSize(attachment?.size) }}</td>
											<td class="tableColumnConstrained">{{ attachment?.type || 'No type' }}</td>
											<td class="tableColumnConstrained">
												<div class="fileLabelsContainer">
													<NcCounterBubble v-for="label of attachment.labels" :key="label">
														{{ label }}
													</NcCounterBubble>
												</div>
											</td>
											<td class="tableColumnActions">
												<NcActions>
													<NcActionButton close-after-click @click="openFile(attachment)">
														<template #icon>
															<OpenInNew :size="20" />
														</template>
														View
													</NcActionButton>
													<NcActionButton close-after-click @click="editFileLabels(attachment)">
														<template #icon>
															<Tag :size="20" />
														</template>
														Labels
													</NcActionButton>
													<NcActionButton 
														v-if="!attachment.accessUrl && !attachment.downloadUrl"
														close-after-click 
														@click="publishFile(attachment)">
														<template #icon>
															<FileOutline :size="20" />
														</template>
														Publish
													</NcActionButton>
													<NcActionButton 
														v-else
														close-after-click 
														@click="depublishFile(attachment)">
														<template #icon>
															<LockOutline :size="20" />
														</template>
														Depublish
													</NcActionButton>
													<NcActionButton 
														close-after-click 
														type="error"
														@click="deleteFile(attachment)">
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
							</div>
							<NcNoteCard v-else type="info">
								<p>No files have been attached to this object</p>
							</NcNoteCard>

							<!-- Files Pagination -->
							<PaginationComponent
								v-if="objectStore.files?.results?.length > filesPerPage"
								:current-page="filesCurrentPage"
								:total-pages="filesTotalPages"
								:total-items="objectStore.files?.results?.length || 0"
								:current-page-size="filesPerPage"
								:min-items-to-show="5"
								@page-changed="onFilesPageChanged"
								@page-size-changed="onFilesPageSizeChanged" />
						</BTab>

					</BTabs>
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton v-if="activeTab !== 2" @click="activeTab = 2">
				<template #icon>
					<Pencil :size="20" />
				</template>
				Edit Object
			</NcButton>
			<NcButton v-if="activeTab === 2" @click="saveObject">
				<template #icon>
					<ContentSave :size="20" />
				</template>
				Save
			</NcButton>
			<NcButton @click="navigationStore.setModal('uploadFiles'); objectStore.setObjectItem(objectStore.objectItem)">
				<template #icon>
					<Upload :size="20" />
				</template>
				Add File
			</NcButton>
			<NcButton @click="viewAuditTrails">
				<template #icon>
					<TextBoxOutline :size="20" />
				</template>
				Audit Trails
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
	NcTextField,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
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
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'

import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import Tag from 'vue-material-design-icons/Tag.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'

import PaginationComponent from '../../components/PaginationComponent.vue'
export default {
	name: 'ViewObject',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcCounterBubble,
		NcTextField,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcActions,
		NcActionButton,
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
		Plus,
		Delete,
		ContentSave,
		TextBoxOutline,
		Tag,
		DotsHorizontal,
		PaginationComponent,
	},
	data() {
		return {
			closeModalTimeout: null,
			activeAttachment: null,
			registerTitle: '',
			schemaTitle: '',
			isUpdated: false,
			isCopied: false,
			error: null,
			success: null,
			formData: {},
			jsonData: '',
			editorTab: 0,
			activeTab: 0,
			objectEditors: {},
			tabOptions: ['Properties', 'Data', 'Uses', 'Used by', 'Contracts', 'Files'],
			selectedAttachments: [],
			publishLoading: [],
			depublishLoading: [],
			fileIdsLoading: [],
			filesCurrentPage: 1,
			filesPerPage: 10,
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
		currentRegister() {
			return registerStore.registerItem
		},
		currentSchema() {
			return schemaStore.schemaItem
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
		filesHasPublished() {
			return objectStore.files.results?.some(item => !!item.published)
		},
		filesHasUnpublished() {
			return objectStore.files.results?.some(item => !item.published)
		},
		paginatedFiles() {
			const files = objectStore.files?.results || []
			const start = (this.filesCurrentPage - 1) * this.filesPerPage
			const end = start + this.filesPerPage
			return files.slice(start, end)
		},
		filesTotalPages() {
			const totalFiles = objectStore.files?.results?.length || 0
			return Math.ceil(totalFiles / this.filesPerPage)
		},
		allFilesSelected() {
			return this.paginatedFiles.length > 0 && this.paginatedFiles.every(file => this.selectedAttachments.includes(file.id))
		},
		someFilesSelected() {
			return this.selectedAttachments.length > 0 && !this.allFilesSelected
		},
	},
	watch: {
		objectStore: {
			handler(newValue) {
				if (newValue) {
					this.initializeData()
				}
			},
			deep: true,
		},
		jsonData: {
			handler(newValue) {
				if (this.editorTab === 1 && this.isValidJson(newValue)) {
					this.updateFormFromJson()
				}
			},
		},
		formData: {
			deep: true,
			immediate: true,
			handler(obj) {
				// Only update JSON if we're not in JSON editor tab to avoid circular updates
				if (this.editorTab === 0) {
					// Create a clean copy of the form data
					const draft = JSON.stringify(obj, null, 2)
					// Only update if the content is different to avoid infinite loops
					if (this.jsonData !== draft) {
						this.jsonData = draft
					}
				}

				// Update object editors for complex fields
				for (const k in obj) {
					if (typeof obj[k] === 'object' && obj[k] !== null) {
						this.objectEditors[k] = JSON.stringify(obj[k], null, 2)
					}
				}
			},
		},
	},
	updated() {
		if (!this.isUpdated && navigationStore.modal === 'viewObject') {
			this.isUpdated = true
			this.loadTitles()
			this.initializeData()
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
		initializeData() {
			if (!objectStore.objectItem) {
				this.formData = {}
				this.jsonData = JSON.stringify({ data: {} }, null, 2)
				return
			}
			const initial = objectStore.objectItem

			const filtered = {}
			for (const key in initial) {
				if (key !== '@self' && key !== 'id') {
					filtered[key] = initial[key]
				}
			}
			this.formData = JSON.parse(JSON.stringify(filtered))
			this.jsonData = JSON.stringify(filtered, null, 2)
		},

		async saveObject() {
			if (!this.currentRegister || !this.currentSchema) {
				this.error = 'Register and schema are required'
				return
			}

			this.loading = true
			this.error = null

			try {
				let payload
				if (this.editorTab === 1) {
					payload = {
						...JSON.parse(this.jsonData),
						'@self': {
							...objectStore.objectItem['@self'],
						},
					}
				} else {
					payload = {
						...this.formData,
						'@self': {
							...objectStore.objectItem['@self'],
						},
					}
				}

				const { response } = await objectStore.saveObject(payload, {
					register: this.currentRegister.id,
					schema: this.currentSchema.id,
				})

				this.success = response.ok
				if (this.success) {
					setTimeout(() => {
						this.success = null
					}, 2000)
				}
			} catch (e) {
				this.error = e.message || 'Failed to save object'
				this.success = false
			} finally {
				this.loading = false
			}
		},
		updateFormFromJson() {
			try {
				const parsed = JSON.parse(this.jsonData)
				this.formData = parsed
			} catch (e) {
				this.error = 'Invalid JSON format'
			}
		},

		updateJsonFromForm() {
			const draft = {
				...objectStore.objectItem,
				data: this.formData,
			}
			this.jsonData = JSON.stringify(draft, null, 2)
		},

		isValidJson(str) {
			if (!str || !str.trim()) {
				return false
			}
			try {
				JSON.parse(str)
				return true
			} catch (e) {
				return false
			}
		},

		formatJSON() {
			try {
				if (this.jsonData) {
					const parsed = JSON.parse(this.jsonData)
					this.jsonData = JSON.stringify(parsed, null, 2)
				}
			} catch (e) {
				// Keep invalid JSON as-is
			}
		},

		setFieldValue(key, value) {
			this.formData[key] = value
		},
		toDisplay(v) { return v === null ? '' : v },
		toPayload(v) { return v === '' ? null : v },

		addArrayItem(key) { 
			if (!this.formData[key] || !Array.isArray(this.formData[key])) {
				this.formData[key] = []
			}
			this.formData[key].push('') 
		},
		removeArrayItem(key, i) { 
			if (this.formData[key] && Array.isArray(this.formData[key])) {
				this.formData[key].splice(i, 1) 
			}
		},
		updateObjectField(key, val) {
			this.objectEditors[key] = val
			try {
				this.formData[key] = JSON.parse(val)
			} catch (e) {
				console.error('Invalid JSON format:', e)
			}
		},
		toggleSelectAllFiles(checked) {
			if (checked) {
				// Add all current page files to selection
				this.paginatedFiles.forEach(file => {
					if (!this.selectedAttachments.includes(file.id)) {
						this.selectedAttachments.push(file.id)
					}
				})
			} else {
				// Remove all current page files from selection
				const currentPageIds = this.paginatedFiles.map(file => file.id)
				this.selectedAttachments = this.selectedAttachments.filter(id => !currentPageIds.includes(id))
			}
		},
		toggleFileSelection(fileId, checked) {
			if (checked) {
				if (!this.selectedAttachments.includes(fileId)) {
					this.selectedAttachments.push(fileId)
				}
			} else {
				this.selectedAttachments = this.selectedAttachments.filter(id => id !== fileId)
			}
		},
		onFilesPageChanged(page) {
			this.filesCurrentPage = page
		},
		onFilesPageSizeChanged(pageSize) {
			this.filesPerPage = pageSize
			this.filesCurrentPage = 1
		},
		viewAuditTrails() {
			// Close the current modal and navigate to audit trails
			this.closeModal()
			navigationStore.setSelected('auditTrails')
		},
		async publishSelectedFiles() {
			if (this.selectedAttachments.length === 0) return

			try {
				this.publishLoading = [...this.selectedAttachments]
				
				// Get the selected files
				const selectedFiles = objectStore.files.results.filter(file => 
					this.selectedAttachments.includes(file.id)
				)

				// Publish each file
				for (const file of selectedFiles) {
					// You'll need to implement the actual publish API call here
					// This is a placeholder for the actual implementation
					console.log('Publishing file:', file.name)
				}

				// Clear selection after successful operation
				this.selectedAttachments = []
				
				// Refresh files list
				// You may need to call a refresh method here
				
			} catch (error) {
				console.error('Error publishing files:', error)
			} finally {
				this.publishLoading = []
			}
		},
		async depublishSelectedFiles() {
			if (this.selectedAttachments.length === 0) return

			try {
				this.depublishLoading = [...this.selectedAttachments]
				
				// Get the selected files
				const selectedFiles = objectStore.files.results.filter(file => 
					this.selectedAttachments.includes(file.id)
				)

				// Depublish each file
				for (const file of selectedFiles) {
					// You'll need to implement the actual depublish API call here
					// This is a placeholder for the actual implementation
					console.log('Depublishing file:', file.name)
				}

				// Clear selection after successful operation
				this.selectedAttachments = []
				
				// Refresh files list
				// You may need to call a refresh method here
				
			} catch (error) {
				console.error('Error depublishing files:', error)
			} finally {
				this.depublishLoading = []
			}
		},
		async deleteSelectedFiles() {
			if (this.selectedAttachments.length === 0) return

			const confirmed = confirm(`Are you sure you want to delete ${this.selectedAttachments.length} file${this.selectedAttachments.length > 1 ? 's' : ''}? This action cannot be undone.`)
			if (!confirmed) return

			try {
				this.fileIdsLoading = [...this.selectedAttachments]
				
				// Get the selected files
				const selectedFiles = objectStore.files.results.filter(file => 
					this.selectedAttachments.includes(file.id)
				)

				// Delete each file
				for (const file of selectedFiles) {
					// You'll need to implement the actual delete API call here
					// This is a placeholder for the actual implementation
					console.log('Deleting file:', file.name)
				}

				// Clear selection after successful operation
				this.selectedAttachments = []
				
				// Refresh files list
				// You may need to call a refresh method here
				
			} catch (error) {
				console.error('Error deleting files:', error)
			} finally {
				this.fileIdsLoading = []
			}
		},
		async publishFile(file) {
			try {
				this.publishLoading.push(file.id)
				
				// You'll need to implement the actual publish API call here
				// This is a placeholder for the actual implementation
				console.log('Publishing single file:', file.name)
				
				// Refresh files list after successful operation
				// You may need to call a refresh method here
				
			} catch (error) {
				console.error('Error publishing file:', error)
			} finally {
				this.publishLoading = this.publishLoading.filter(id => id !== file.id)
			}
		},
		async depublishFile(file) {
			try {
				this.depublishLoading.push(file.id)
				
				// You'll need to implement the actual depublish API call here
				// This is a placeholder for the actual implementation
				console.log('Depublishing single file:', file.name)
				
				// Refresh files list after successful operation
				// You may need to call a refresh method here
				
			} catch (error) {
				console.error('Error depublishing file:', error)
			} finally {
				this.depublishLoading = this.depublishLoading.filter(id => id !== file.id)
			}
		},
		async deleteFile(file) {
			const confirmed = confirm(`Are you sure you want to delete "${file.name}"? This action cannot be undone.`)
			if (!confirmed) return

			try {
				this.fileIdsLoading.push(file.id)
				
				// You'll need to implement the actual delete API call here
				// This is a placeholder for the actual implementation
				console.log('Deleting single file:', file.name)
				
				// Remove from selection if it was selected
				this.selectedAttachments = this.selectedAttachments.filter(id => id !== file.id)
				
				// Refresh files list after successful operation
				// You may need to call a refresh method here
				
			} catch (error) {
				console.error('Error deleting file:', error)
			} finally {
				this.fileIdsLoading = this.fileIdsLoading.filter(id => id !== file.id)
			}
		},
		editFileLabels(file) {
			// You'll need to implement the labels editing functionality
			// This could open a modal or inline editor for file labels
			console.log('Editing labels for file:', file.name)
			// Placeholder for labels editing implementation
		},
	},
}
</script>

<style>
.modal-container:has(.viewObjectDialog) {
	width: 1000px !important;
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

/* Table styles matching AuditTrailIndex */
.viewTableContainer {
	overflow-x: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 20px;
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
	background-color: var(--color-main-background);
}

.viewTableRow {
	border-bottom: 1px solid var(--color-border);
}

.viewTableRow:hover {
	background-color: var(--color-background-hover);
}

.viewTableRow.active {
	background-color: var(--color-primary-light);
}

.viewTableRow th {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-weight: bold;
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.viewTableRow td {
	padding: 12px;
	vertical-align: middle;
}

.tableColumnCheckbox {
	width: 50px;
	text-align: center;
}

.tableColumnConstrained {
	width: 150px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.tableColumnExpanded {
	width: auto;
	min-width: 200px;
}

.tableColumnActions {
	width: 100px;
	text-align: center;
}

.table-row-title {
	display: flex;
	align-items: center;
	gap: 10px;
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

.id-card-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 4px;
}

.id-card .detail-value {
	word-break: break-all;
	margin-top: 4px;
}

.copy-button {
	flex-shrink: 0;
}

.detail-value-with-copy {
	display: flex;
	align-items: center;
	gap: 10px;
	justify-content: space-between;
}

.detail-value-with-copy .detail-value {
	flex: 1;
	word-break: break-all;
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

.format-json-button {
	position: absolute;
	bottom: 0;
	right: 0;
	transform: translateY(100%);
	border-top-left-radius: 0;
	border-top-right-radius: 0;
}

.copy-button {
	margin-top: 5px;
}

.error-message {
	position: absolute;
	bottom: 0;
	right: 50%;
	transform: translateY(100%) translateX(50%);
	color: var(--color-error);
	font-size: 0.8rem;
	padding-top: 0.25rem;
}

/* Dark mode specific styles */
.codeMirrorContainer.dark :deep(.cm-editor) {
	background-color: var(--color-background-darker);
}

.codeMirrorContainer.light :deep(.cm-editor) {
	background-color: var(--color-background-hover);
}

/* Add tab container styles */
.tabContainer {
	margin-top: 20px;
}

/* Style the tabs to match ViewObject */
:deep(.nav-tabs) {
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 15px;
}

:deep(.nav-tabs .nav-link) {
	border: none;
	border-bottom: 2px solid transparent;
	color: var(--color-text-maxcontrast);
	padding: 8px 16px;
}

:deep(.nav-tabs .nav-link.active) {
	color: var(--color-main-text);
	border-bottom: 2px solid var(--color-primary);
	background-color: transparent;
}

:deep(.nav-tabs .nav-link:hover) {
	border-bottom: 2px solid var(--color-border);
}

:deep(.tab-content) {
	padding: 16px;
	background-color: var(--color-main-background);
}

/* Form editor specific styles */
.form-editor {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
}

.field-label-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 6px;
  margin-bottom: 4px;
}

.array-editor {
  list-style: none;
  padding-left: 0;
  margin-bottom: 6px;
}
.array-editor li {
  display: flex;
  align-items: center;
  gap: 4px;
  margin-bottom: 4px;
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
