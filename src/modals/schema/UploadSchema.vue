<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.modal === 'uploadSchema'"
		name="Upload Schema"
		size="normal"
		noClose>
		<NcNoteCard v-if="success" type="success">
			<p>Schema successfully uploaded</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="!success" class="formContainer">
			<NcTextField
				v-model="schema.url"
				:disabled="loading"
				:label="t('openregister', 'Url')" />

			<div :class="`codeMirrorContainer ${getTheme()}`">
				<p>{{ t('openregister', 'Schema') }}</p>
				<CodeMirror
					v-model="schema.json"
					:basic="true"
					:dark="getTheme() === 'dark'"
					:lang="json()"
					:linter="jsonParseLinter()"
					:placeholder="t('openregister', 'Enter your schema here...')" />
			</div>
			<NcButton class="prettifyButton" @click="prettifyJson">
				<template #icon>
					<AutoFix :size="20" />
				</template>
				{{ t('openregister', 'Prettify') }}
			</NcButton>
		</div>

		<template #actions>
			<CloseCancelButton :label="success ? 'Close' : 'Cancel'" @click="closeModal" />
			<NcButton
				v-if="!success"
				:disabled="loading || !schema || !validateJson(schema.json)"
				variant="primary"
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
import { json, jsonParseLinter } from '@codemirror/lang-json'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import CodeMirror from 'vue-codemirror6'
import AutoFix from 'vue-material-design-icons/AutoFix.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'
import { isValidJson } from '../../composables/jsonText.js'
import { getTheme } from '../../services/getTheme.js'

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
		CloseCancelButton,
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
		/**
		 * @spec exclude Modal close plumbing — resets upload form state and closes the modal.
		 */
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

		/**
		 * @spec exclude UI helper — pretty-prints the JSON in the editor field.
		 */
		prettifyJson() {
			this.schema.json = JSON.stringify(JSON.parse(this.schema.json), null, 2)
		},

		/**
		 * @spec exclude Modal action plumbing — delegates schema upload to schemaStore.uploadSchema.
		 */
		async uploadSchema() {
			this.loading = true

			const newSchema = {
				...this.schema,
				json: JSON.stringify(JSON.parse(this.schema.json)), // create a clean json string
			}
			schemaStore
				.uploadSchema(newSchema)
				.then(({ response }) => {
					this.success = response.ok
					this.error = false
					if (response.ok) {
						this.closeModalTimeout = setTimeout(this.closeModal, 2000)
					}
				})
				.catch((error) => {
					this.success = false
					this.error =
						error.message
						|| 'An error occurred while uploading the schema'
				})
				.finally(() => {
					this.loading = false
				})
		},

		validateJson: isValidJson,
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

@include code-mirror-frame;

@include code-mirror-container-deep-cm-content;

@include code-mirror-editor;

@include code-mirror-theme;
</style>
