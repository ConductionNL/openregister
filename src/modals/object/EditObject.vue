<script setup>
import { objectStore, schemaStore, registerStore, navigationStore } from '../../store/store.js'
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
				:disabled="!registers.value?.id || !schemas.value?.id || loading || !verifyJsonValidity(objectItem.object)"
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
				<NcTextArea :disabled="loading"
					label="Object"
					placeholder="{ &quot;key&quot;: &quot;value&quot; }"
					:value.sync="objectItem.object"
					:error="!verifyJsonValidity(objectItem.object)"
					:helper-text="!verifyJsonValidity(objectItem.object) ? 'This is not valid JSON (optional)' : ''" />
			</div>
		</div>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcTextArea,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'

export default {
	name: 'EditObject',
	components: {
		NcDialog,
		NcTextArea,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		ContentSaveOutline,
		Cancel,
		Plus,
	},
	data() {
		return {
			objectItem: {
				schemas: '',
				register: '',
				object: '',
			},
			schemasLoading: false,
			schemasData: [],
			schemas: {
				multiple: false,
				closeOnSelect: true,
				options: [],
				value: null,
			},
			registersLoading: false,
			registers: {},
			success: null,
			loading: false,
			error: false,
			hasUpdated: false,
			closeModalTimeout: null,
		}
	},
	watch: {
		'registers.value': {
			handler(newVal) {
				if (newVal) {
					if (!newVal.id) return

					const currentRegister = registerStore.registerList.find((register) => register.id === newVal.id)
					const filteredSchemas = this.schemasData.filter((schema) => currentRegister.schemas.includes(schema.id))

					this.schemas.options = filteredSchemas.map((schema) => ({
						id: schema.id,
						label: schema.title,
					}))
				}
			},
			deep: true,
		},
	},
	mounted() {
		this.initializeObjectItem()
	},
	updated() {
		if (navigationStore.modal === 'editObject' && !this.hasUpdated) {
			this.initializeObjectItem()
			this.fetchSchemas()
			this.initializeRegisters()
			this.hasUpdated = true
		}
	},
	methods: {
		initializeObjectItem() {
			if (objectStore.objectItem?.id) {
				this.objectItem = {
					...objectStore.objectItem,
					schemas: objectStore.objectItem.schemas || '',
					register: objectStore.objectItem.register || '',
					object: JSON.stringify(objectStore.objectItem.object) || '',
				}
			}
		},
		fetchSchemas() {
			this.schemasLoading = true

			schemaStore.refreshSchemaList()
				.then(() => {
					this.schemasData = schemaStore.schemaList

					this.schemas.value = objectStore.objectItem?.id
						? this.schemasData.find((schema) => schema.id.toString() === objectStore.objectItem.schema.toString())
						: null
				})
				.finally(() => {
					this.schemasLoading = false
				})
		},
		initializeRegisters() {
			this.registersLoading = true

			registerStore.refreshRegisterList()
				.then(() => {
					const activeRegister = objectStore.objectItem?.id
						? registerStore.registerList.find((register) => register.id.toString() === objectStore.objectItem.register)
						: null

					this.registers = {
						multiple: false,
						closeOnSelect: true,
						options: registerStore.registerList.map((register) => ({
							id: register.id,
							label: register.title,
						})),
						value: activeRegister
							? {
								id: activeRegister.id,
								label: activeRegister.title,
							}
							: null,
					}

					this.registersLoading = false
				})
		},
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.objectItem = {
				schemas: '',
				register: '',
				object: '',
			}
		},
		async editObject() {
			this.loading = true

			objectStore.saveObject({
				...this.objectItem,
				object: JSON.parse(this.objectItem.object),
				schema: this.schemas?.value?.id || '',
				register: this.registers?.value?.id || '',
			}).then(({ response }) => {
				this.success = response.ok
				this.error = false
				response.ok && (this.closeModalTimeout = setTimeout(this.closeModal, 2000))
			}).catch((error) => {
				this.success = false
				this.error = error.message || 'An error occurred while saving the object'
			}).finally(() => {
				this.loading = false
			})
		},
		verifyJsonValidity(jsonInput) {
			if (jsonInput === '') return true
			try {
				JSON.parse(jsonInput)
				return true
			} catch (e) {
				return false
			}
		},
	},
}
</script>
