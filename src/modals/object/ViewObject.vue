<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
import { ref, onMounted } from 'vue'
import {
	NcDialog,
	NcButton,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import { json, jsonParseLinter } from '@codemirror/lang-json'
import CodeMirror from 'vue-codemirror6'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'

// Initialize refs
const objectItem = ref({
	schemas: '',
	register: '',
	object: '',
})
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
const hasUpdated = ref(false)
const closeModalTimeout = ref(null)

const pagination = ref({
	files: {
		limit: 200,
		currentPage: objectStore.files.page || 1,
		totalPages: objectStore.files.total || 1,
	},
	auditTrails: {
		limit: 200,
		currentPage: objectStore.auditTrails.page || 1,
		totalPages: objectStore.auditTrails.total || 1,
	},
	relations: {
		limit: 200,
		currentPage: objectStore.relations.page || 1,
		totalPages: objectStore.relations.total || 1,
	},
})

// Methods
const getFiles = () => {
	return objectStore.getFiles(objectStore.objectItem.id, {
		limit: pagination.value.files.limit,
		page: pagination.value.files.currentPage,
	})
}

const getAuditTrails = () => {
	return objectStore.getAuditTrails(objectStore.objectItem.id, {
		limit: pagination.value.auditTrails.limit,
		page: pagination.value.auditTrails.currentPage,
	})
}

const getRelations = () => {
	return objectStore.getRelations(objectStore.objectItem.id, {
		limit: pagination.value.relations.limit,
		page: pagination.value.relations.currentPage,
	})
}

const closeModal = () => {
	navigationStore.setModal(false)
	clearTimeout(closeModalTimeout.value)
	success.value = null
	loading.value = false
	error.value = false
	hasUpdated.value = false
	objectItem.value = {
		schemas: '',
		register: '',
		object: '',
	}
}

const editObject = async () => {
	loading.value = true

	try {
		const response = await objectStore.saveObject({
			...objectItem.value,
			object: JSON.parse(objectItem.value.object),
			schema: schemas.value?.value?.id || '',
			register: registers.value?.value?.id || '',
		})
		success.value = response.ok
		error.value = false
		if (response.ok) {
			closeModalTimeout.value = setTimeout(closeModal, 2000)
		}
	} catch (error) {
		success.value = false
		error.value = error.message || 'An error occurred while saving the object'
	} finally {
		loading.value = false
	}
}

const isValidJson = (str) => {
	if (!str) return true
	try {
		JSON.parse(str)
		return true
	} catch (e) {
		return false
	}
}

const formatJsonObject = () => {
	try {
		if (objectItem.value.object) {
			// Format the JSON with proper indentation
			const parsed = JSON.parse(objectItem.value.object)
			objectItem.value.object = JSON.stringify(parsed, null, 2)
		}
	} catch (e) {
		// Keep invalid JSON as-is to allow user to fix it
	}
}

// Lifecycle hooks
onMounted(() => {
	if (objectStore.objectItem?.id) {
		Promise.all([
			getFiles(),
			getAuditTrails(),
			getRelations(),
		])
	}
})
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'editObject'"
		:name="objectStore.objectItem?.id ? 'Edit Object' : 'Add Object'"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>Object successfully modified</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton v-if="registers?.value?.id && !schemas?.value?.id"
				:disabled="loading"
				@click="registers.value = null">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				Back to Register
			</NcButton>
			<NcButton v-if="registers.value?.id && schemas.value?.id"
				:disabled="loading"
				@click="schemas.value = null">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				Back to Schema
			</NcButton>
			<NcButton
				@click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton v-if="success === null"
				:disabled="!registers.value?.id || !schemas.value?.id || loading || !isValidJson(objectItem.object)"
				type="primary"
				@click="editObject()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline v-if="!loading && objectStore.objectItem?.id" :size="20" />
					<Plus v-if="!loading && !objectStore.objectItem?.id" :size="20" />
				</template>
				{{ objectStore.objectItem?.id ? 'Save' : 'Add' }}
			</NcButton>
		</template>

		<div v-if="!success" class="formContainer">
			<div v-if="registers?.value?.id && success === null">
				<b>Register:</b> {{ registers.value.label }}
				<NcButton @click="registers.value = null; schemas.value = null;">
					Edit Register
				</NcButton>
			</div>
			<div v-if="schemas.value?.id && success === null">
				<b>Schema:</b> {{ schemas.value.label }}
				<NcButton @click="schemas.value = null">
					Edit Schema
				</NcButton>
			</div>

			<!-- STAGE 1 -->
			<div v-if="!registers?.value?.id">
				<NcSelect v-bind="registers"
					v-model="registers.value"
					input-label="Register"
					:loading="registersLoading"
					:disabled="loading" />
			</div>

			<!-- STAGE 2 -->
			<div v-if="registers?.value?.id && !schemas?.value?.id">
				<NcSelect v-bind="schemas"
					v-model="schemas.value"
					input-label="Schemas"
					:loading="schemasLoading"
					:disabled="loading" />
			</div>

			<!-- STAGE 3 -->
			<div v-if="registers.value?.id && schemas.value?.id">
				<div class="json-editor">
					<label>Object (JSON)</label>
					<div :class="`codeMirrorContainer ${getTheme()}`">
						<CodeMirror v-model="objectItem.object"
							:basic="true"
							placeholder="{ &quot;key&quot;: &quot;value&quot; }"
							:dark="getTheme() === 'dark'"
							:linter="jsonParseLinter()"
							:lang="json()"
							:tab-size="2" />

						<NcButton class="format-json-button"
							type="secondary"
							size="small"
							@click="formatJsonObject">
							Format JSON
						</NcButton>
					</div>
					<span v-if="!isValidJson(objectItem.object)" class="error-message">
						Invalid JSON format
					</span>
				</div>
			</div>
		</div>
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

.json-editor .error-message {
    position: absolute;
	bottom: 0;
	right: 50%;
    transform: translateY(100%) translateX(50%);

	color: var(--color-error);
	font-size: 0.8rem;
	padding-top: 0.25rem;
	display: block;
}

.json-editor .format-json-button {
	position: absolute;
	bottom: 0;
	right: 0;
    transform: translateY(100%);
}

/* Add styles for the code editor */
.code-editor {
	font-family: monospace;
	width: 100%;
	background-color: var(--color-background-dark);
}

.info-text {
	margin: 1rem 0;
	padding: 0.5rem;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
}

/* CodeMirror */
.codeMirrorContainer {
	margin-block-start: 6px;
}

.prettifyButton {
	margin-block-start: 10px;
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
