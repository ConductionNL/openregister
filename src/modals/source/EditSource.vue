<script setup>
import { sourceStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'editSource'"
		:name="sourceStore.sourceItem?.id ? 'Edit Source' : 'Add Source'"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>Source successfully updated</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="!success" class="formContainer">
			<NcTextField :disabled="loading"
				label="Title *"
				:value.sync="sourceItem.title" />
			<NcTextArea :disabled="loading"
				label="Description"
				:value.sync="sourceItem.description" />
			<NcTextField :disabled="loading"
				label="Database URL"
				:value.sync="sourceItem.databaseUrl" />
			<NcSelect v-bind="typeOptions"
				v-model="typeOptions.value"
				input-label="Type"
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
				:disabled="loading || !sourceItem.title"
				type="primary"
				@click="editSource()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline v-if="!loading && sourceStore.sourceItem?.id" :size="20" />
					<Plus v-if="!loading && !sourceStore.sourceItem?.id" :size="20" />
				</template>
				{{ sourceStore.sourceItem?.id ? 'Save' : 'Create' }}
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
	name: 'EditSource',
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
			sourceItem: {
				title: '',
				description: '',
				databaseUrl: '',
			},
			typeOptions: {
				clearable: false,
				options: [
					{ label: 'Internal', id: 'internal' },
					{ label: 'MongoDB', id: 'mongodb' },
				],
				value: { label: 'Internal', id: 'internal' },
			},
			success: false,
			loading: false,
			error: false,
			hasUpdated: false,
			closeModalTimeout: null,
		}
	},
	mounted() {
		this.initializeSourceItem()
	},
	updated() {
		if (navigationStore.modal === 'editSource' && !this.hasUpdated) {
			this.initializeSourceItem()
			this.hasUpdated = true
		}
	},
	methods: {
		initializeSourceItem() {
			if (sourceStore.sourceItem?.id) {
				this.sourceItem = {
					...sourceStore.sourceItem,
				}

				// set typeOptions to the sourceItem type
				this.typeOptions.value = this.typeOptions.options.find(option => option.id === this.sourceItem.type)
			}
		},
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.sourceItem = {
				id: '',
				title: '',
				description: '',
				databaseUrl: '',
			}
			// reset typeOptions to the internal option
			this.typeOptions.value = this.typeOptions.options.find(option => option.id === 'internal')
		},
		async editSource() {
			this.loading = true

			sourceStore.saveSource({
				...this.sourceItem,
				type: this.typeOptions.value.id,
			}).then(({ response }) => {
				this.success = response.ok
				this.error = false
				response.ok && (this.closeModalTimeout = setTimeout(this.closeModal, 2000))
			}).catch((error) => {
				this.success = false
				this.error = error.message || 'An error occurred while saving the source'
			}).finally(() => {
				this.loading = false
			})
		},
	},
}
</script>
