<script setup>
import { configurationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'deleteConfiguration'"
		title="Delete Configuration"
		size="small"
		:can-close="false">
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div class="formContainer">
			<p>Are you sure you want to delete the configuration "{{ configurationStore.configurationItem?.title }}"?</p>
			<p>This action cannot be undone.</p>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Cancel
			</NcButton>
			<NcButton
				:disabled="loading"
				type="error"
				@click="deleteConfiguration">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Delete v-else :size="20" />
				</template>
				Delete
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
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'DeleteConfiguration',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		Cancel,
		Delete,
	},
	data() {
		return {
			loading: false,
			error: null,
		}
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
			this.loading = false
			this.error = null
		},
		async deleteConfiguration() {
			this.loading = true
			this.error = null

			try {
				await configurationStore.deleteConfiguration(configurationStore.configurationItem)
				this.closeModal()
			} catch (error) {
				this.error = error.message || 'Failed to delete configuration'
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