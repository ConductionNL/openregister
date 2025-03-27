<script setup>
import { schemaStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="schemaStore.schemaItem?.id && !createAnother ? 'Edit Schema' : 'Add Schema'"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>Schema successfully {{ schemaStore.schemaItem?.id && !createAnother ? 'updated' : 'created' }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="createAnother || !success" class="formContainer modalSpacing">
			<NcTextField :disabled="loading"
				label="Title *"
				:value.sync="schemaItem.title"
				style="margin-top: 12px;" />
			<NcTextArea :disabled="loading"
				label="Description"
				:value.sync="schemaItem.description"
				resize="none" />
			<NcTextArea :disabled="loading"
				label="Summary"
				:value.sync="schemaItem.summary"
				resize="none" />
			<NcCheckboxRadioSwitch
				v-if="!schemaStore.schemaItem?.id"
				:disabled="loading"
				:checked.sync="createAnother">
				Create another
			</NcCheckboxRadioSwitch>
		</div>

		<template #actions>
			<div class="buttonContainer">
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
			</div>
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
				version: '0.0.0',
				description: '',
				summary: '',
			},
			createAnother: false,
			success: false,
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},
	mounted() {
		this.initializeSchemaItem()
	},
	methods: {
		initializeSchemaItem() {
			if (schemaStore.schemaItem?.id) {
				this.schemaItem = {
					...schemaStore.schemaItem,
					title: schemaStore.schemaItem.title || '',
					description: schemaStore.schemaItem.description || '',
					summary: schemaStore.schemaItem.summary || '',
				}
			}
		},
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
		},
		async editSchema() {
			this.loading = true

			schemaStore.saveSchema({
				...this.schemaItem,
			}).then(({ response }) => {

				if (this.createAnother) {
					// since saveSchema populates the schema item, we need to clear it
					schemaStore.setSchemaItem(null)

					// clear the form after 0.5s
					setTimeout(() => {
						this.schemaItem = {
							title: '',
							version: '0.0.0',
							description: '',
							summary: '',
						}
					}, 500)

					this.success = response.ok
					this.error = false

					// clear the success message after 2s
					setTimeout(() => {
						this.success = null
					}, 2000)
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
