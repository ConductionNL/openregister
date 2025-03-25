/**
 * @file EditObject.vue
 * @module Modals/Object/Edit
 * @description Component for editing objects with both JSON and form-based interfaces
 * @requires @nextcloud/vue
 * @requires vue-codemirror6
 */

<script setup>
import { objectStore, schemaStore, registerStore, navigationStore } from '../../store/store.js'
import { ref, computed, watch, onMounted } from 'vue'
import { getTheme } from '../../services/getTheme.js'
import { json, jsonParseLinter } from '@codemirror/lang-json'
import CodeMirror from 'vue-codemirror6'
import {
	NcButton,
	NcDialog,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
} from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'

// State
const success = ref(null)
const loading = ref(false)
const error = ref(null)
const activeTab = ref(0) // Using number instead of string for BTabs
const closeModalTimeout = ref(null)
const formData = ref({})
const jsonData = ref('')

// Computed properties
const currentRegister = computed(() => registerStore.registerItem)
const currentSchema = computed(() => schemaStore.schemaItem)
const schemaProperties = computed(() => currentSchema.value?.properties || {})
const isNewObject = computed(() => !objectStore.objectItem || !objectStore.objectItem?.['@self']?.id)
const dialogTitle = computed(() => isNewObject.value ? 'Add Object' : 'Edit Object')

// Methods
const initializeData = () => {
	// Initialize with empty data for new objects
	if (!objectStore.objectItem) {
		const initialData = {
			'@self': {
				id: '',
				uuid: '',
				uri: '',
				register: currentRegister.value?.id || '',
				schema: currentSchema.value?.id || '',
				relations: '',
				files: '',
				folder: '',
				updated: '',
				created: '',
				locked: null,
				owner: ''
			}
		}
		formData.value = initialData
		jsonData.value = JSON.stringify(initialData, null, 2)
		return
	}

	// For existing objects, use their data
	const initialData = { ...objectStore.objectItem }
	formData.value = initialData
	jsonData.value = JSON.stringify(initialData, null, 2)
}

const updateFormFromJson = () => {
	try {
		const parsed = JSON.parse(jsonData.value)
		formData.value = parsed
	} catch (e) {
		error.value = 'Invalid JSON format'
	}
}

const updateJsonFromForm = () => {
	try {
		jsonData.value = JSON.stringify(formData.value, null, 2)
	} catch (e) {
		console.error('Error updating JSON:', e)
	}
}

const isValidJson = (str) => {
	try {
		JSON.parse(str)
		return true
	} catch (e) {
		return false
	}
}

const formatJSON = () => {
	try {
		if (jsonData.value) {
			const parsed = JSON.parse(jsonData.value)
			jsonData.value = JSON.stringify(parsed, null, 2)
		}
	} catch (e) {
		// Keep invalid JSON as-is
	}
}

const closeModal = () => {
	navigationStore.setModal(false)
	clearTimeout(closeModalTimeout.value)
	success.value = null
	loading.value = false
	error.value = null
	formData.value = {}
	jsonData.value = ''
}

const saveObject = async () => {
	if (!currentRegister.value || !currentSchema.value) {
		error.value = 'Register and schema are required'
		return
	}

	loading.value = true
	error.value = null

	try {
		const dataToSave = activeTab.value === 1 ? JSON.parse(jsonData.value) : formData.value
		
		const { response } = await objectStore.saveObject(dataToSave, {
			register: currentRegister.value.id,
			schema: currentSchema.value.id
		})

		success.value = response.ok
		if (response.ok) {
			closeModalTimeout.value = setTimeout(closeModal, 2000)
		}
	} catch (e) {
		error.value = e.message || 'Failed to save object'
		success.value = false
	} finally {
		loading.value = false
	}
}

// Watch for changes
watch(() => objectStore.objectItem, (newValue) => {
	if (newValue) {
		initializeData()
	}
}, { immediate: true })

watch(() => jsonData.value, (newValue) => {
	if (activeTab.value === 1 && isValidJson(newValue)) {
		updateFormFromJson()
	}
})

watch(() => formData.value, (newValue) => {
	if (activeTab.value === 0) {
		updateJsonFromForm()
	}
}, { deep: true })

// Lifecycle hooks
onMounted(() => {
	initializeData()
})

// Add modelValue handling for form fields
const getFieldValue = (key) => {
	return formData.value[key] || ''
}

const setFieldValue = (key, value) => {
	formData.value[key] = value
}
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'editObject'"
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

				<!-- Edit Tabs -->
				<div class="tabContainer">
					<BTabs content-class="mt-3" justified>
						<BTab title="Form Editor" active>
							<div class="form-editor" v-if="currentSchema">
								<div v-for="(prop, key) in schemaProperties" :key="key" class="form-field">
									<template v-if="prop.type === 'string'">
										<NcTextField
											:label="prop.title || key"
											:model-value="getFieldValue(key)"
											@update:model-value="value => setFieldValue(key, value)"
											:placeholder="prop.description"
											:helper-text="prop.description"
											:required="prop.required"
										/>
									</template>
									<template v-else-if="prop.type === 'boolean'">
										<NcCheckboxRadioSwitch
											:label="prop.title || key"
											:model-value="getFieldValue(key)"
											@update:model-value="value => setFieldValue(key, value)"
											:helper-text="prop.description"
											type="switch"
										/>
									</template>
									<template v-else-if="prop.type === 'number' || prop.type === 'integer'">
										<NcTextField
											:label="prop.title || key"
											:model-value="getFieldValue(key)"
											@update:model-value="value => setFieldValue(key, value)"
											:placeholder="prop.description"
											:helper-text="prop.description"
											:required="prop.required"
											type="number"
											:min="prop.minimum"
											:max="prop.maximum"
											:step="prop.type === 'integer' ? '1' : 'any'"
										/>
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
										:extensions="[json()]"
										:tab-size="2"
										style="height: 400px"
									/>
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
</template>

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
</style>
