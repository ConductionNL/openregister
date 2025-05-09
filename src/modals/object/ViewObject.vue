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
					<BTabs v-model="activeTab" content-class="mt-3" justified>
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
										<div v-if="currentSchema" class="form-editor">
											<NcNoteCard v-if="success" type="success" class="note-card">
												<p>Object successfully modified</p>
											</NcNoteCard>
											<div v-for="(value, key) in formData" :key="key" class="form-field">
												<div v-if="typeof value === 'string'" class="field-label-row">
													<NcTextField
														v-model="formData[key]"
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
												<NcTextField v-else-if="value === null"
													v-model="formData[key]"
													:label="objectStore.enabledColumns.find(c => c.key === key)?.label || key"
													:placeholder="key"
													:helper-text="objectStore.enabledColumns.find(c => c.key === key)?.description || key" />
												<NcCheckboxRadioSwitch v-else-if="typeof value === 'boolean'"
													v-model="formData[key]"
													:label="objectStore.enabledColumns.find(c => c.key === key)?.label || key"
													type="switch" />
												<NcTextField v-else-if="typeof value === 'number'"
													v-model.number="formData[key]"
													:label="objectStore.enabledColumns.find(c => c.key === key)?.label || key"
													type="number" />

												<template v-else-if="Array.isArray(value)">
													<label class="field-label">
														{{ objectStore.enabledColumns.find(c => c.key === key)?.label || key }}
													</label>
													<ul class="array-editor">
														<li v-for="(item, i) in value" :key="i">
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

												<template v-else-if="typeof value === 'object' && value !== null">
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
											</div>
										</div>
										<NcEmptyContent v-else>
											Please select a schema to edit the object
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

			this.formData = JSON.parse(JSON.stringify(initial.data || {}))

			this.jsonData = JSON.stringify(initial, null, 2)
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
					payload = JSON.parse(this.jsonData)
				} else {
					payload = {
						...objectStore.objectItem,
						data: this.formData,
					}
					payload['@self'] = {
						...payload['@self'],
						updated: new Date().toISOString(),
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

		addArrayItem(key) { this.formData[key].push('') },
		removeArrayItem(key, i) { this.formData[key].splice(i, 1) },
		updateObjectField(key, val) {
			this.objectEditors[key] = val
			try {
				this.formData[key] = JSON.parse(val)
			} catch (e) {
				console.error('Invalid JSON format:', e)
			}
		},
	},
}
</script>

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
