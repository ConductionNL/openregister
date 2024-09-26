<script setup>
import { registerStore, schemaStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'editRegister'"
		name="Register"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>Register succesvol aangepast</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="!success" class="formContainer">
			<NcTextField :disabled="loading"
				label="Title *"
				:value.sync="registerItem.title" />
			<NcTextArea :disabled="loading"
				label="Description"
				:value.sync="registerItem.description" />
			<NcTextField :disabled="loading"
				label="Source"
				:value.sync="registerItem.source" />
			<NcTextField :disabled="loading"
				label="Table Prefix"
				:value.sync="registerItem.tablePrefix" />
			<NcSelect v-bind="schemas"
				v-model="schemas.value"
				input-label="Schemas"
				:loading="schemasLoading"
				:disabled="loading" />
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Sluiten' : 'Annuleer' }}
			</NcButton>
			<NcButton v-if="!success"
				:disabled="loading || !registerItem.title"
				type="primary"
				@click="editRegister()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline v-if="!loading && registerStore.registerItem?.id" :size="20" />
					<Plus v-if="!loading && !registerStore.registerItem?.id" :size="20" />
				</template>
				{{ registerStore.registerItem?.id ? 'Opslaan' : 'Aanmaken' }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcTextField,
	NcTextArea,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'EditRegister',
	components: {
		NcDialog,
		NcTextField,
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
			registerItem: {
				title: '',
				description: '',
				schemas: [],
				source: '',
				tablePrefix: '',
				created: '',
				updated: '',
			},
			schemasLoading: false,
			schemas: {},
			success: false,
			loading: false,
			error: false,
			hasUpdated: false,
		}
	},
	mounted() {
		this.initializeRegisterItem()
	},
	updated() {
		if (navigationStore.modal === 'editRegister' && !this.hasUpdated) {
			this.initializeRegisterItem()
			this.initializeSchemas()
			this.hasUpdated = true
		}
	},
	methods: {
		initializeRegisterItem() {
			if (registerStore.registerItem?.id) {
				this.registerItem = {
					...registerStore.registerItem,
					title: registerStore.registerItem.title || '',
					description: registerStore.registerItem.description || '',
					schemas: registerStore.registerItem.schemas || [],
					source: registerStore.registerItem.source || '',
					tablePrefix: registerStore.registerItem.tablePrefix || '',
				}
			}
		},
		initializeSchemas() {
			this.schemasLoading = true

			schemaStore.refreshSchemaList()
				.then(() => {
					const activeSchemas = registerStore.registerItem?.id
						? schemaStore.schemaList.filter((schema) => {
							return registerStore.registerItem.schemas
								.map(String)
								.includes(schema.id.toString())
						})
						: null

					this.schemas = {
						multiple: true,
						closeOnSelect: false,
						options: schemaStore.schemaList.map((schema) => ({
							id: schema.id,
							label: schema.title,
						})),
						value: activeSchemas
							? activeSchemas.map((schema) => ({
								id: schema.id,
								label: schema.title,
							}))
							: null,
					}

					this.schemasLoading = false
				})
		},
		closeModal() {
			navigationStore.setModal(false)
			this.success = null
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.registerItem = {
				title: '',
				description: '',
				schemas: [],
				source: '',
				tablePrefix: '',
				created: '',
				updated: '',
			}
		},
		async editRegister() {
			this.loading = true

			registerStore.saveRegister({
				...this.registerItem,
				schemas: this.schemas?.value?.map((schema) => schema.id) || [],
			}).then(({ response }) => {
				this.success = response.ok
				this.error = false
				response.ok && setTimeout(this.closeModal, 2000)
			}).catch((error) => {
				this.success = false
				this.error = error.message || 'An error occurred while saving the register'
			}).finally(() => {
				this.loading = false
			})
		},
	},
}
</script>
