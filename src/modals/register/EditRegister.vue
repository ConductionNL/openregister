<script setup>
import { registerStore, schemaStore, sourceStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'editRegister'"
		:name="registerStore.registerItem?.id ? 'Edit Register' : 'Add Register'"
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
				label="Table Prefix"
				:value.sync="registerItem.tablePrefix" />
			<NcSelect v-bind="sources"
				v-model="sources.value"
				input-label="Source"
				:loading="sourcesLoading"
				:disabled="loading" />
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
			schemas: {
				options: [],
				value: [],
				multiple: true,
				closeOnSelect: false,
			},
			sourcesLoading: false,
			sources: {},
			success: false,
			loading: false,
			error: false,
			hasUpdated: false,
			closeModalTimeout: null,
		}
	},
	mounted() {
		this.initializeRegisterItem()
	},
	updated() {
		if (navigationStore.modal === 'editRegister' && !this.hasUpdated) {
			this.initializeRegisterItem()
			this.initializeSchemas()
			this.initializeSources()
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

					this.schemas.options = schemaStore.schemaList.map((schema) => ({
						id: schema.id,
						label: schema.title,
					}))

					this.schemas.value = activeSchemas.map((schema) => ({
						id: schema.id,
						label: schema.title,
					}))

					this.schemasLoading = false
				})
		},
		initializeSources() {
			this.sourcesLoading = true

			sourceStore.refreshSourceList()
				.then(() => {
					const activeSource = registerStore.registerItem?.id
						? sourceStore.sourceList.find((source) => source.id.toString() === registerStore.registerItem.source)
						: null

					this.sources = {
						multiple: false,
						closeOnSelect: true,
						options: sourceStore.sourceList.map((source) => ({
							id: source.id,
							label: source.title,
						})),
						value: activeSource
							? {
								id: activeSource.id,
								label: activeSource.title,
							}
							: null,
					}

					this.sourcesLoading = false
				})
		},
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
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
				source: this.sources?.value?.id || '',
			}).then(({ response }) => {
				this.success = response.ok
				this.error = false
				response.ok && (this.closeModalTimeout = setTimeout(this.closeModal, 2000))
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
