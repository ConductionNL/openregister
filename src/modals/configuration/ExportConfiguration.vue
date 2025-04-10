<script setup>
import { configurationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'exportConfiguration'"
		title="Export Configuration"
		size="small"
		:can-close="false">
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div class="formContainer">
			<p>Export configuration "{{ configurationStore.configurationItem?.title }}" as JSON file?</p>
			<NcNoteCard type="info">
				<p>The configuration will be exported as a JSON file that can be imported later.</p>
			</NcNoteCard>
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
				type="primary"
				@click="exportConfiguration">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Export v-else :size="20" />
				</template>
				Export
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
import Export from 'vue-material-design-icons/Export.vue'

export default {
	name: 'ExportConfiguration',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		Cancel,
		Export,
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
		async exportConfiguration() {
			this.loading = true
			this.error = null

			try {
				const configuration = configurationStore.configurationItem
				const blob = new Blob([JSON.stringify(configuration, null, 2)], { type: 'application/json' })
				const url = window.URL.createObjectURL(blob)
				const link = document.createElement('a')
				link.href = url
				link.download = `configuration_${configuration.id}_${new Date().toISOString().split('T')[0]}.json`
				document.body.appendChild(link)
				link.click()
				document.body.removeChild(link)
				window.URL.revokeObjectURL(url)
				this.closeModal()
			} catch (error) {
				this.error = error.message || 'Failed to export configuration'
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