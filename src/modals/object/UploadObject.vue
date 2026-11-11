<script setup>
import { translate as t } from '@nextcloud/l10n'
import {
	navigationStore,
	objectStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'
</script>

<template>
	<NcDialog name="Upload Object" size="normal" noClose>
		<NcNoteCard v-if="success" type="success">
			<p>Object successfully uploaded</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton
				v-if="registers?.value?.id && !schemas?.value?.id"
				:disabled="loading"
				@click="registers.value = null">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				Back to Register
			</NcButton>
			<NcButton
				v-if="registers.value?.id && schemas.value?.id"
				:disabled="loading"
				@click="schemas.value = null">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				Back to Schema
			</NcButton>
			<CloseCancelButton :label="success ? 'Close' : 'Cancel'" @click="closeModal" />
			<NcButton
				v-if="success === null"
				:disabled="
					!registers.value?.id
					|| !schemas.value?.id
					|| loading
					|| !validateJson(object)
				"
				variant="primary"
				@click="uploadObject()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Upload v-if="!loading" :size="20" />
				</template>
				Upload
			</NcButton>
		</template>

		<div v-if="!success" class="formContainer">
			<div v-if="registers?.value?.id && success === null">
				<b>Register:</b> {{ registers.value.label }}
				<NcButton @click="registers.value = null"> Edit Register </NcButton>
			</div>
			<div v-if="schemas.value?.id && success === null">
				<b>Schema:</b> {{ schemas.value.label }}
				<NcButton @click="schemas.value = null"> Edit Schema </NcButton>
			</div>

			<!-- STAGE 1 -->
			<div v-if="!registers?.value?.id">
				<NcSelect
					v-bind="registers"
					v-model="registers.value"
					:inputLabel="t('openregister', 'Register')"
					:loading="registersLoading"
					:disabled="loading" />
			</div>

			<!-- STAGE 2 -->
			<div v-if="registers?.value?.id && !schemas?.value?.id">
				<NcSelect
					v-bind="schemas"
					v-model="schemas.value"
					:inputLabel="t('openregister', 'Schemas')"
					:loading="schemasLoading"
					:disabled="loading" />
			</div>

			<!-- STAGE 3 -->
			<div v-if="registers.value?.id && schemas.value?.id">
				<NcSelect
					v-bind="mappings"
					v-model="mappings.value"
					:inputLabel="t('openregister', 'Mappings')"
					:loading="mappingsLoading"
					:disabled="loading || !mappings.options?.length" />

				<div :class="`codeMirrorContainer ${getTheme()}`">
					<p>{{ t('openregister', 'Object') }}</p>
					<CodeMirror
						v-model="object"
						:basic="true"
						:dark="getTheme() === 'dark'"
						:lang="json()"
						:linter="jsonParseLinter()"
						:placeholder="
							t('openregister', 'Enter your object here...')
						" />

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
import { json, jsonParseLinter } from '@codemirror/lang-json'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import CodeMirror from 'vue-codemirror6'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import AutoFix from 'vue-material-design-icons/AutoFix.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'
import { isValidJson } from '../../composables/jsonText.js'
import { getTheme } from '../../services/getTheme.js'

/**
 * Register the object type an upload goes to, and name it.
 *
 * @param {{register: number|string, schema: number|string}} newObject The upload
 * @return {string} The object type
 */
function registerUploadType(newObject) {
	const type = `${newObject.register}-${newObject.schema}`
	objectStore.registerObjectType(type, newObject.schema, newObject.register)
	return type
}

export default {
	name: 'UploadObject',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		// Icons
		CloseCancelButton,
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

	/**
	 * @spec exclude Vue lifecycle hook initializing modal data
	 */
	mounted() {
		this.initializeMappings()
		this.initializeSchemas()
		this.initializeRegisters()
	},

	methods: {
		/**
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		initializeMappings() {
			this.mappingsLoading = true

			objectStore
				.getMappings()
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

		/**
		 * @spec exclude form-state loader populating schema select options
		 */
		initializeSchemas() {
			this.schemasLoading = true

			schemaStore
				.refreshSchemaList()
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

		/**
		 * @spec exclude form-state loader populating register select options
		 */
		initializeRegisters() {
			this.registersLoading = true

			registerStore
				.refreshRegisterList()
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

		/**
		 * @spec exclude modal close + form-state reset handler
		 */
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

		/**
		 * @spec exclude modal submit handler delegating to objectStore.saveObject
		 */
		async uploadObject() {
			this.loading = true

			const newObject = this.uploadPayload()
			const type = registerUploadType(newObject)
			objectStore
				.saveObject(type, newObject)
				.then((data) => this.showUploaded(data))
				.catch((error) => this.showUploadFailure(error))
				.finally(() => {
					this.loading = false
				})
		},

		/**
		 * The object to upload, with its register, schema and mapping.
		 *
		 * @return {object} The upload
		 * @throws {SyntaxError} When the object is not JSON
		 */
		uploadPayload() {
			return {
				object: JSON.parse(this.object) || '',
				register: this.registers.value.id || '',
				schema: this.schemas.value.id || '',
				mapping: this.mappings?.value?.id || null,
				schemas: '',
			}
		},

		/**
		 * Say whether the upload saved an object; close after two seconds when
		 * it did.
		 *
		 * @param {object|null} data The saved object
		 * @return {void}
		 */
		showUploaded(data) {
			this.success = !!data
			this.error = false
			if (data) {
				setTimeout(this.closeModal, 2000)
			}
		},

		/**
		 * Say why the upload failed.
		 *
		 * @param {Error} error What went wrong
		 * @return {void}
		 */
		showUploadFailure(error) {
			this.success = false
			this.error =
				error.message || 'An error occurred while uploading the object'
		},

		/**
		 * @spec exclude JSON formatting UI helper
		 */
		prettifyJson() {
			this.object = JSON.stringify(JSON.parse(this.object), null, 2)
		},

		validateJson: isValidJson,
	},
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

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

@include code-mirror-frame;

@include code-mirror-container-deep-cm-content;

@include code-mirror-editor;

@include code-mirror-theme;
</style>
