/**
 * @file DownloadObject.vue
 * @module Modals/Object
 * @author Your Name
 * @copyright 2024 Your Organization
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
import { getTheme } from '../../services/getTheme.js'
import { ref, onMounted } from 'vue'
import {
	NcDialog,
	NcButton,
	NcNoteCard,
} from '@nextcloud/vue'
import { json, jsonParseLinter } from '@codemirror/lang-json'
import CodeMirror from 'vue-codemirror6'

import Cancel from 'vue-material-design-icons/Cancel.vue'

// Initialize refs
const success = ref(null)
const loading = ref(false)
const error = ref(false)
const closeModalTimeout = ref(null)

// Methods
const closeModal = () => {
	navigationStore.setModal(false)
	clearTimeout(closeModalTimeout.value)
	success.value = null
	loading.value = false
	error.value = false
}

const downloadObject = async () => {
	loading.value = true

	try {
		const response = await objectStore.downloadObject(objectStore.objectItem)
		success.value = response.ok
		error.value = false
		if (response.ok) {
			closeModalTimeout.value = setTimeout(closeModal, 2000)
		}
	} catch (error) {
		success.value = false
		error.value = error.message || 'An error occurred while downloading the object'
	} finally {
		loading.value = false
	}
}

// Lifecycle hooks
onMounted(() => {
	if (objectStore.objectItem?.id) {
		downloadObject()
	}
})
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'downloadObject'"
		name="Download Object"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>Object successfully downloaded</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
		</template>

		<div v-if="!success" class="formContainer">
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
