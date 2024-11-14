<script setup>
import { schemaStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'editSchema'"
		:name="schemaStore.schemaItem?.id && !createAnother ? 'Edit Schema' : 'Add Schema'"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>Schema successfully {{ schemaStore.schemaItem?.id && !createAnother ? 'updated' : 'created' }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="createAnother || !success" class="formContainer">
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
			<NcCheckboxRadioSwitch
				v-if="!schemaStore.schemaItem?.id"
				:disabled="loading"
				:checked.sync="createAnother">
				Create another
			</NcCheckboxRadioSwitch>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton v-if="createAnother ||!success"
				:disabled="loading || !schemaItem.title"
				type="primary"
				@click="editSchema()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline v-if="!loading && schemaStore.schemaItem?.id" :size="20" />
					<Plus v-if="!loading && !schemaStore.schemaItem?.id" :size="20" />
				</template>
				{{ schemaStore.schemaItem?.id && !createAnother ? 'Save' : 'Create' }}
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
	NcCheckboxRadioSwitch,
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
		NcCheckboxRadioSwitch,
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
				created: '',
				updated: '',
			},
			createAnother: false,
			success: false,
			loading: false,
			error: false,
			hasUpdated: false,
			closeModalTimeout: null,
		}
	},
	mounted() {
		this.initializeSchemaItem()
	},
	updated() {
		if (navigationStore.modal === 'editSchema' && !this.hasUpdated) {
			this.initializeSchemaItem()
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
				}
			}
		},
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.schemaItem = {
				title: '',
				version: '',
				description: '',
				summary: '',
				created: '',
				updated: '',
			}
		},
		async editSchema() {
			this.loading = true

			schemaStore.saveSchema({
				...this.schemaItem,
			}).then(({ response }) => {
				if (this.createAnother) {
					schemaStore.setSchemaItem(null)
					setTimeout(() => {
						this.initializeSchemaItem()
						this.schemaItem = {
							title: '',
							version: '0.0.1',
							description: '',
							summary: '',
							created: '',
							updated: '',
						}
						this.loading = false
					}, 500)
					setTimeout(() => {
						this.success = null
					}, 2000)
					this.success = response.ok
					this.hasUpdated = false
					this.error = false

				} else {
					this.success = response.ok
					this.error = false
					response.ok && (this.closeModalTimeout = setTimeout(this.closeModal, 2000))
				}

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
