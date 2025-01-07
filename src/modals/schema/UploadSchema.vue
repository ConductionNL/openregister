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
				:value.sync="schema.url" />

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
.codeMirrorContainer.light :deep(.ͼe) {
	color: #448c27;
}
.codeMirrorContainer.dark :deep(.ͼe) {
	color: #88c379;
}

/* text cursor */
.codeMirrorContainer :deep(.cm-content) * {
	cursor: text !important;
}
</style>
