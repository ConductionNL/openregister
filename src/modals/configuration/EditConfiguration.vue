<script setup>
import { configurationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'editConfiguration'"
		:title="configurationStore.configurationItem?.id ? 'Edit Configuration' : 'New Configuration'"
		size="large"
		:can-close="false">
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div class="formContainer">
			<NcTextField
				label="Title"
				placeholder="Enter configuration title"
				:value="configurationStore.configurationItem?.title"
				@update:value="updateTitle" />

			<NcTextField
				type="textarea"
				label="Description"
				placeholder="Enter configuration description"
				:value="configurationStore.configurationItem?.description"
				@update:value="updateDescription" />

			<NcTextField
				label="Type"
				placeholder="Enter configuration type"
				:value="configurationStore.configurationItem?.type"
				@update:value="updateType" />
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Cancel
			</NcButton>
			<NcButton
				:disabled="loading || !isValid"
				type="primary"
				@click="saveConfiguration">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSave v-else :size="20" />
				</template>
				Save
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'

export default {
	name: 'EditConfiguration',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		// Icons
		Cancel,
		ContentSave,
	},
	data() {
		return {
			loading: false,
			error: null,
		}
	},
	computed: {
		isValid() {
			const item = configurationStore.configurationItem
			return Boolean(item?.title?.trim()) && Boolean(item?.type?.trim())
		},
	},
	created() {
		// Initialize configurationItem if it doesn't exist
		if (!configurationStore.configurationItem) {
			configurationStore.configurationItem = {
				title: '',
				description: null,
				type: '',
				owner: '',
			}
		}
	},
	methods: {
		updateTitle(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.title = value
		},
		updateDescription(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.description = value
		},
		updateType(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.type = value
		},
		closeModal() {
			navigationStore.setModal(false)
			this.loading = false
			this.error = null
		},
		async saveConfiguration() {
			this.loading = true
			this.error = null

			try {
				await configurationStore.saveConfiguration(configurationStore.configurationItem)
				this.closeModal()
			} catch (error) {
				this.error = error.message || 'Failed to save configuration'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style>
.formContainer {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}
</style>
