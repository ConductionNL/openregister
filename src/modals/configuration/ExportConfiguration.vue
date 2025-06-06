<script setup>
import { configurationStore, navigationStore } from '../../store/store.js'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'exportConfiguration'"
		name="export-configuration-dialog"
		title="Export Configuration"
		size="small"
		:can-close="false">
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div class="formContainer">
			<p v-if="configTitle">
				Export configuration "{{ configTitle }}"?
			</p>

			<NcCheckboxRadioSwitch
				:checked="includeObjects"
				@update:checked="includeObjects = $event">
				Include related objects
			</NcCheckboxRadioSwitch>
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
	NcCheckboxRadioSwitch,
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
		NcCheckboxRadioSwitch,
		// Icons
		Cancel,
		Export,
	},
	data() {
		return {
			loading: false,
			error: null,
			includeObjects: false,
		}
	},
	computed: {
		configTitle() {
			const item = configurationStore.configurationItem
			return item?.title || ''
		},
		isValid() {
			const item = configurationStore.configurationItem
			return Boolean(item?.id)
		},
	},
	created() {
		// Check if we have a configuration when component is created
		if (!configurationStore.configurationItem?.id) {
			this.error = 'No configuration selected for export'
		}
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
			this.loading = false
			this.error = null
			this.includeObjects = false
		},
		async exportConfiguration() {
			const item = configurationStore.configurationItem
			if (!item?.id) {
				this.error = 'Invalid configuration selected'
				return
			}

			this.loading = true
			this.error = null

			try {
				// Generate the export URL with query parameters
				const url = generateUrl(`/apps/openregister/api/configurations/${item.id}/export`)
				const params = { includeObjects: this.includeObjects }

				// Make the API call
				const response = await axios({
					url,
					method: 'GET',
					params,
					responseType: 'blob', // Important for file download
				})

				// Create a download link
				const blob = new Blob([response.data], { type: 'application/json' })
				const downloadUrl = window.URL.createObjectURL(blob)
				const link = document.createElement('a')

				// Get filename from response headers or generate a default one
				const contentDisposition = response.headers['content-disposition']
				const filename = contentDisposition
					? contentDisposition.split('filename=')[1].replace(/"/g, '')
					: `configuration_${item.id}_${new Date().toISOString().split('T')[0]}.json`

				link.href = downloadUrl
				link.download = filename
				document.body.appendChild(link)
				link.click()
				document.body.removeChild(link)
				window.URL.revokeObjectURL(downloadUrl)

				this.closeModal()
			} catch (error) {
				this.error = error.response?.data?.error || error.message || 'Failed to export configuration'
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
