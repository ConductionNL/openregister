<script setup>
import { schemaStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'uploadSchema'"
		name="Upload Schema"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>Schema successfully uploaded</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="!success" class="formContainer">
			<NcTextField :disabled="loading"
				label="Url"
				:value.sync="schema.url"
				style="margin-top: 12px;" />

			<div :class="`codeMirrorContainer ${getTheme()}`">
				<p>Schema</p>
				<CodeMirror v-model="schema.json"
					:basic="true"
					:dark="getTheme() === 'dark'"
					:lang="json()"
					:linter="jsonParseLinter()"
					placeholder="Enter your schema here..." />
			</div>
			<NcButton class="prettifyButton" @click="prettifyJson">
				<template #icon>
					<AutoFix :size="20" />
				</template>
				Prettify
			</NcButton>
		</div>

		<template #actions>
			<div class="buttonContainer">
				<NcButton @click="closeModal">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{ success ? 'Close' : 'Cancel' }}
				</NcButton>
				<NcButton v-if="!success"
					:disabled="loading || !schema || !validateJson(schema.json)"
					type="primary"
					@click="uploadSchema()">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<Upload :size="20" />
					</template>
					Upload
				</NcButton>
			</div>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcTextField,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import { getTheme } from '../../services/getTheme.js'
import { json, jsonParseLinter } from '@codemirror/lang-json'
import CodeMirror from 'vue-codemirror6'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import AutoFix from 'vue-material-design-icons/AutoFix.vue'

export default {
	name: 'UploadSchema',
	components: {
		NcDialog,
		NcTextField,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		CodeMirror,
		// Icons
		Cancel,
		Upload,
	},
	data() {
		return {
			schema: {
				json: '{}',
				url: '',
			},
			success: false,
			loading: false,
			error: false,
			hasUpdated: false,
			closeModalTimeout: null,
		}
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.schema = {
				json: '{}',
				url: '',
			}
		},
		prettifyJson() {
			this.schema.json = JSON.stringify(JSON.parse(this.schema.json), null, 2)
		},
		async uploadSchema() {
			this.loading = true

			const newSchema = {
				...this.schema,
				json: JSON.stringify(JSON.parse(this.schema.json)), // create a clean json string
			}

			schemaStore.uploadSchema(newSchema).then(({ response }) => {
				this.success = response.ok
				this.error = false
				response.ok && (this.closeModalTimeout = setTimeout(this.closeModal, 2000))
			}).catch((error) => {
				this.success = false
				this.error = error.message || 'An error occurred while uploading the schema'
			}).finally(() => {
				this.loading = false
			})
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
