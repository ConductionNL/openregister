<script setup>
import { sourceStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'editSource'"
		name="Source"
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
			<NcTextField :disabled="loading"
				label="Type"
				:value.sync="sourceItem.type" />
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
				type: '',
				created: '',
				updated: '',
			},
			success: false,
			loading: false,
			error: false,
			hasUpdated: false,
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
			}
		},
		closeModal() {
			navigationStore.setModal(false)
			this.success = null
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.sourceItem = {
				id: '',
				title: '',
				description: '',
				databaseUrl: '',
				type: '',
				created: '',
				updated: '',
			}
		},
		async editSource() {
			this.loading = true

			sourceStore.saveSource({
				...this.sourceItem,
				created: !this.sourceItem?.id ? new Date().toISOString() : this.sourceItem.created,
				updated: this.sourceItem?.id ? new Date().toISOString() : null,
			}).then(({ response }) => {
				this.success = response.ok
				this.error = false
				response.ok && setTimeout(this.closeModal, 2000)
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
