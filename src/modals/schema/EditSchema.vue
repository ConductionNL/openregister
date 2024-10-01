<script setup>
import { schemaStore, navigationStore, sourceStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'editSchema'"
		name="Schema"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>Schema successfully updated</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="!success" class="formContainer">
			<NcTextField :disabled="loading"
				label="Title *"
				:value.sync="schemaItem.title" />
			<NcTextField :disabled="loading"
				label="Version"
				:value.sync="schemaItem.version" />
			<NcTextArea :disabled="loading"
				label="Description"
				:value.sync="schemaItem.description" />
			<NcTextArea :disabled="loading"
				label="Summary"
				:value.sync="schemaItem.summary" />
			<NcSelect v-bind="sources"
				v-model="sources.value"
				input-label="Source"
				:loading="sourcesLoading"
				:disabled="loading" />
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton v-if="!success"
				:disabled="loading || !schemaItem.title"
				type="primary"
				@click="editSchema()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline v-if="!loading && schemaStore.schemaItem?.id" :size="20" />
					<Plus v-if="!loading && !schemaStore.schemaItem?.id" :size="20" />
				</template>
				{{ schemaStore.schemaItem?.id ? 'Save' : 'Create' }}
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
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'EditSchema',
	components: {
		NcDialog,
		NcTextField,
		NcTextArea,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		// Icons
		ContentSaveOutline,
		Cancel,
		Plus,
	},
	data() {
		return {
			schemaItem: {
				title: '',
				version: '',
				description: '',
				summary: '',
				source: '',
				created: '',
				updated: '',
			},
			sourcesLoading: false,
			sources: {},
			success: false,
			loading: false,
			error: false,
			hasUpdated: false,
		}
	},
	mounted() {
		this.initializeSchemaItem()
	},
	updated() {
		if (navigationStore.modal === 'editSchema' && !this.hasUpdated) {
			this.initializeSchemaItem()
			this.initializeSources()
			this.hasUpdated = true
		}
	},
	methods: {
		initializeSchemaItem() {
			if (schemaStore.schemaItem?.id) {
				this.schemaItem = {
					...schemaStore.schemaItem,
					title: schemaStore.schemaItem.title || '',
					version: schemaStore.schemaItem.version || '',
					description: schemaStore.schemaItem.description || '',
					summary: schemaStore.schemaItem.summary || '',
					source: schemaStore.schemaItem.source || '',
				}
			}
		},
		initializeSources() {
			this.sourcesLoading = true

			sourceStore.refreshSourceList()
				.then(() => {
					const activeSource = schemaStore.schemaItem?.id
						? sourceStore.sourceList.find((source) => source.id.toString() === schemaStore.schemaItem.source)
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
			this.success = null
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.schemaItem = {
				title: '',
				version: '',
				description: '',
				summary: '',
				source: '',
				created: '',
				updated: '',
			}
		},
		async editSchema() {
			this.loading = true

			schemaStore.saveSchema({
				...this.schemaItem,
				source: this.sources?.value?.id || '',
			}).then(({ response }) => {
				this.success = response.ok
				this.error = false
				response.ok && setTimeout(this.closeModal, 2000)
			}).catch((error) => {
				this.success = false
				this.error = error.message || 'An error occurred while saving the schema'
			}).finally(() => {
				this.loading = false
			})
		},
	},
}
</script>
