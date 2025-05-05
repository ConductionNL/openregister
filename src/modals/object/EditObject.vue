<script setup>
import { objectStore, schemaStore, registerStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<div>
		<NcDialog
			:name="dialogTitle"
			size="large"
			:can-close="false">
			<div class="dialog-content">
				<NcNoteCard v-if="success" type="success" class="note-card">
					<p>Object successfully {{ isNewObject ? 'created' : 'modified' }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="error" type="error" class="note-card">
					<p>{{ error }}</p>
				</NcNoteCard>

				<div v-if="!success">
					<!-- Register and Schema Info with card style -->
					<div class="detail-grid">
						<div class="detail-item" :class="{ 'empty-value': !currentRegister?.title }">
							<span class="detail-label">Register:</span>
							<span class="detail-value">{{ currentRegister?.title || 'Not selected' }}</span>
						</div>
						<div class="detail-item" :class="{ 'empty-value': !currentSchema?.title }">
							<span class="detail-label">Schema:</span>
							<span class="detail-value">{{ currentSchema?.title || 'Not selected' }}</span>
						</div>
					</div>

					<!-- Upload Files Button (only for existing objects) -->
					<NcButton v-if="!isNewObject"
						type="secondary"
						class="upload-files-btn"
						@click="openUploadFilesModal">
						<template #icon>
							<Plus :size="20" />
						</template>
						Upload Files
					</NcButton>

					<!-- Edit Tabs -->
					<div class="tabContainer">
						<BTabs v-model="activeTab" content-class="mt-3" justified>
							<BTab title="Form Editor" active>
								<div v-if="currentSchema" class="form-editor">
									<div v-for="(prop, key) in schemaProperties" :key="key" class="form-field">
										<template v-if="prop.type === 'string'">
											<NcTextField
												:label="prop.title || key"
												:model-value="getFieldValue(key)"
												:placeholder="prop.description"
												:helper-text="prop.description"
												:required="prop.required"
												@update:model-value="value => setFieldValue(key, value)" />
										</template>
										<template v-else-if="prop.type === 'boolean'">
											<NcCheckboxRadioSwitch
												:label="prop.title || key"
												:model-value="getFieldValue(key)"
												:helper-text="prop.description"
												type="switch"
												@update:model-value="value => setFieldValue(key, value)" />
										</template>
										<template v-else-if="prop.type === 'number' || prop.type === 'integer'">
											<NcTextField
												:label="prop.title || key"
												:model-value="getFieldValue(key)"
												:placeholder="prop.description"
												:helper-text="prop.description"
												:required="prop.required"
												type="number"
												:min="prop.minimum"
												:max="prop.maximum"
												:step="prop.type === 'integer' ? '1' : 'any'"
												@update:model-value="value => setFieldValue(key, value)" />
										</template>
									</div>
								</div>
								<NcEmptyContent v-else>
									Please select a schema to edit the object
								</NcEmptyContent>
							</BTab>

							<BTab title="JSON Editor">
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
				</div>
			</div>

			<template #actions>
				<NcButton @click="closeModal">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ success ? 'Close' : 'Cancel' }}
				</NcButton>

				<NcButton
					v-if="success === null"
					:disabled="loading || (activeTab === 1 && !isValidJson(jsonData))"
					type="primary"
					@click="saveObject">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<ContentSaveOutline v-else-if="!isNewObject" :size="20" />
						<Plus v-else :size="20" />
					</template>
					{{ isNewObject ? 'Add' : 'Save' }}
				</NcButton>
			</template>
		</NcDialog>
		<!-- Add the UploadFiles modal for file uploads -->
		<UploadFiles />
	</div>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcTextField,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'

import CodeMirror from 'vue-codemirror6'
import { getTheme } from '../../services/getTheme.js'
import { json, jsonParseLinter } from '@codemirror/lang-json'
import UploadFiles from '../file/UploadFiles.vue'

// Icons
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

/**
 * EditObject Modal
 * Handles editing of objects and provides access to file upload modal
 * @category Modals
 * @package
 * @author Your Name
 * @copyright 2024 Your Company
 * @license AGPL-3.0
 * @version 1.0.0
 * @link https://your-app-link.example.com
 */

export default {
	name: 'EditObject',
	components: {
		NcButton,
		NcDialog,
		NcTextField,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		BTabs,
		BTab,
		CodeMirror,
		UploadFiles,
	},

	data() {
		return {
			activeTab: 0,
			loading: false,
			error: null,
			success: null,
			closeModalTimeout: null,

			formData: {},
			jsonData: '',
		}
	},
	computed: {
		currentRegister() {
			return registerStore.registerItem
		},
		currentSchema() {
			return schemaStore.schemaItem
		},
		schemaProperties() {
			return this.currentSchema?.properties || {}
		},
		isNewObject() {
			return !objectStore.objectItem || !objectStore.objectItem?.['@self']?.id
		},
		dialogTitle() {
			return this.isNewObject ? 'Add Object' : 'Edit Object'
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
				if (this.activeTab === 1 && this.isValidJson(newValue)) {
					this.updateFormFromJson()
				}
			},
		},
		formData: {
			handler(newValue) {
				if (this.activeTab === 0) {
					this.updateJsonFromForm()
				}
			},
			deep: true,
		},
	},

	mounted() {
		this.initializeData()
	},

	methods: {
		initializeData() {
			// Initialize with empty data for new objects
			if (!objectStore.objectItem) {
				const initialData = {
					'@self': {
						id: '',
						uuid: '',
						uri: '',
						register: this.currentRegister?.id || '',
						schema: this.currentSchema?.id || '',
						relations: '',
						files: '',
						folder: '',
						updated: '',
						created: '',
						locked: null,
						owner: '',
					},
				}
				this.formData = initialData
				this.jsonData = JSON.stringify(initialData, null, 2)
				return
			}

			// For existing objects, use their data
			const initialData = { ...objectStore.objectItem }
			this.formData = initialData
			this.jsonData = JSON.stringify(initialData, null, 2)
		},

		async saveObject() {
			if (!this.currentRegister || !this.currentSchema) {
				this.error = 'Register and schema are required'
				return
			}

			this.loading = true
			this.error = null

			try {
				let dataToSave
				if (this.activeTab === 1) {
					if (!this.jsonData.trim()) {
						throw new Error('JSON data cannot be empty')
					}
					try {
						dataToSave = JSON.parse(this.jsonData)
					} catch (e) {
						throw new Error('Invalid JSON format: ' + e.message)
					}
				} else {
					dataToSave = this.formData
				}

				const { response } = await objectStore.saveObject(dataToSave, {
					register: this.currentRegister.id,
					schema: this.currentSchema.id,
				})

				this.success = response.ok
				if (response.ok) {
					this.closeModalTimeout = setTimeout(this.closeModal, 2000)
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
			try {
				this.jsonData = JSON.stringify(this.formData, null, 2)
			} catch (e) {
				console.error('Error updating JSON:', e)
			}
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

		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = null
			this.formData = {}
			this.jsonData = ''
		},

		getFieldValue(key) {
			return this.formData[key] || ''
		},

		setFieldValue(key, value) {
			this.formData[key] = value
		},

		openUploadFilesModal() {
			// Set the navigationStore modal to 'uploadFiles' to show the UploadFiles modal
			navigationStore.setModal('uploadFiles')
		},
	},
}
</script>

<style scoped>
/* Add consistent dialog content spacing */
.dialog-content {
	padding: 0 20px;
}

/* Update note card margins */
:deep(.note-card) {
	margin: 20px 0;
}

/* Update detail grid margins */
.detail-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
	margin: 20px 0;
	max-width: 100%;
}

.detail-item {
	display: flex;
	flex-direction: column;
	padding: 12px;
	background-color: var(--color-background-hover);
	border-radius: 4px;
	border-left: 3px solid var(--color-primary);
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

.edit-tabs {
	margin-top: 20px;
}

.form-editor {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.form-field {
	margin-bottom: 16px;
}

/* JSON Editor styles */
.json-editor {
	position: relative;
	margin-bottom: 2.5rem;
}

.codeMirrorContainer {
	margin-block-start: 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	position: relative;
}

.codeMirrorContainer :deep(.cm-editor) {
	height: 100%;
}

.codeMirrorContainer :deep(.cm-scroller) {
	overflow: auto;
}

.format-json-button {
	position: absolute;
	bottom: 0;
	right: 0;
	transform: translateY(100%);
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

.form-field {
	margin-bottom: 16px;
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
