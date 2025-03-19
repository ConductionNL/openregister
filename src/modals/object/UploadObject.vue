<script setup>
import { objectStore, navigationStore, schemaStore, registerStore } from '../../store/store.js'
</script>

<template>
	<NcDialog name="Upload Object"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>Object successfully uploaded</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
		<div class="buttonContainer">
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
				:disabled="!registers.value?.id || !schemas.value?.id || loading || !validateJson(object)"
				type="primary"
				@click="uploadObject()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Upload v-if="!loading" :size="20" />
				</template>
				Upload
			</NcButton>
		</div>
		</template>

		<div v-if="!success" class="formContainer">
			<div v-if="registers?.value?.id && success === null">
				<b>Register:</b> {{ registers.value.label }}
				<NcButton @click="registers.value = null">
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
				<NcSelect v-bind="mappings"
					v-model="mappings.value"
					input-label="Mappings"
					:loading="mappingsLoading"
					:disabled="loading || !mappings.options?.length" />

				<div :class="`codeMirrorContainer ${getTheme()}`">
					<p>Object</p>
					<CodeMirror v-model="object"
						:basic="true"
						:dark="getTheme() === 'dark'"
						:lang="json()"
						:linter="jsonParseLinter()"
						placeholder="Enter your object here..." />

					<NcButton class="prettifyButton" @click="prettifyJson">
						<template #icon>
							<AutoFix :size="20" />
						</template>
						Prettify
					</NcButton>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import { getTheme } from '../../services/getTheme.js'
import { json, jsonParseLinter } from '@codemirror/lang-json'
import CodeMirror from 'vue-codemirror6'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import AutoFix from 'vue-material-design-icons/AutoFix.vue'

export default {
	name: 'UploadObject',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		// Icons
		Cancel,
		Upload,
	},
	data() {
		return {
			object: '{}',
			schemasLoading: false,
			schemas: {},
			registersLoading: false,
			registers: {},
			mappingsLoading: false,
			mappings: {},
			success: null,
			loading: false,
			error: false,
			hasUpdated: false,
		}
	},
	mounted() {
		this.initializeMappings()
		this.initializeSchemas()
		this.initializeRegisters()
	},
	methods: {
		initializeMappings() {
			this.mappingsLoading = true

			objectStore.getMappings()
				.then(({ data }) => {
					this.mappings = {
						multiple: false,
						closeOnSelect: true,
						options: data.map((mapping) => ({
							id: mapping.id,
							label: mapping.name,
						})),
						value: null,
					}
				})
				.finally(() => {
					this.mappingsLoading = false
				})
		},
		initializeSchemas() {
			this.schemasLoading = true

			schemaStore.refreshSchemaList()
				.then(() => {
					this.schemas = {
						multiple: false,
						closeOnSelect: true,
						options: schemaStore.schemaList.map((schema) => ({
							id: schema.id,
							label: schema.title,
						})),
						value: null,
					}
				})
				.finally(() => {
					this.schemasLoading = false
				})
		},
		initializeRegisters() {
			this.registersLoading = true

			registerStore.refreshRegisterList()
				.then(() => {
					this.registers = {
						multiple: false,
						closeOnSelect: true,
						options: registerStore.registerList.map((register) => ({
							id: register.id,
							label: register.title,
						})),
						value: null,
					}
				})
				.finally(() => {
					this.registersLoading = false
				})
		},
		closeModal() {
			navigationStore.setModal(false)
			this.success = null
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.object = {
				json: '{}',
				url: '',
			}
		},
		async uploadObject() {
			this.loading = true

			const newObject = {
				object: JSON.parse(this.object) || '',
				register: this.registers.value.id || '',
				schema: this.schemas.value.id || '',
				mapping: this.mappings?.value?.id || null,
				schemas: '',
			}

			objectStore.saveObject(newObject)
				.then(({ response }) => {
					this.success = response.ok
					this.error = false
					response.ok && setTimeout(this.closeModal, 2000)
				}).catch((error) => {
					this.success = false
					this.error = error.message || 'An error occurred while uploading the object'
				}).finally(() => {
					this.loading = false
				})
		},
		prettifyJson() {
			this.object = JSON.stringify(JSON.parse(this.object), null, 2)
		},
		validateJson(json) {
			try {
				JSON.parse(json)
				return true
			} catch (error) {
				return false
			}
		},
	},
}
</script>

<style scoped>
/*
    The classes to recognize dark and light mode from the :dark attribute on CodeMirror is:
    - dark: cm-editor ͼ1 ͼ3 ͼ4 ͼr
    - light: cm-editor ͼ1 ͼ2 ͼ4 ͼq
    specifically ͼr and ͼq are the ones that change

    String color is .ͼe
    Boolean color is .ͼc
    Null color is .ͼb
    Number color is .ͼd
*/

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
