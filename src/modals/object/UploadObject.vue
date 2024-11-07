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
				:disabled="!registers.value?.id || !schemas.value?.id || loading || !verifyJsonValidity(objectItem.object)"
				type="primary"
				@click="editObject()">
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
				<NcTextField :disabled="loading"
					label="Url"
					:value.sync="object.url" />

				<NcTextArea :disabled="loading"
					label="Object"
					placeholder="{ &quot;key&quot;: &quot;value&quot; }"
					:value.sync="object.json"
					:error="!verifyJsonValidity(object.json)"
					:helper-text="!verifyJsonValidity(object.json) ? 'This is not valid JSON (optional)' : ''" />
			</div>
		</div>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcTextField,
	NcTextArea,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Upload from 'vue-material-design-icons/Upload.vue'

export default {
	name: 'UploadObject',
	components: {
		NcDialog,
		NcTextField,
		NcTextArea,
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
			object: {
				json: '{}',
				url: '',
			},
			schemasLoading: false,
			schemas: {},
			registersLoading: false,
			registers: {},
			mappingsLoading: false,
			mappings: {
				// TODO: remove test data
				options: [
					{ label: 'test mapping 1', id: 1 },
					{ label: 'test mapping 2', id: 2 },
					{ label: 'test mapping 3', id: 3 },
				],
			},
			success: false,
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
						options: data.map((schema) => ({
							id: schema.id,
							label: schema.title,
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

			objectStore.uploadObject(this.object).then(({ response }) => {
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
	},
}
</script>
