<script setup>
import { registerStore, navigationStore } from '../../store/store.js'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'exportRegister'"
		name="export-register-dialog"
		title="Export Register"
		size="small"
		:can-close="false">
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div class="formContainer">
			<p>Export register "{{ registerTitle }}"?</p>

			<div class="form-group">
				<label>Export Format:</label>
				<NcSelect v-model="exportFormat"
					:options="exportFormats"
					option-label="label"
					option-value="value"
					:reduce="option => option.value" />
			</div>

			<NcCheckboxRadioSwitch
				:checked="includeObjects"
				type="switch"
				@update:checked="includeObjects = $event">
				Include objects
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
				:disabled="loading"
				type="primary"
				@click="exportRegister">
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
	NcSelect,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Export from 'vue-material-design-icons/Export.vue'

export default {
	name: 'ExportRegister',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		NcSelect,
		// Icons
		Export,
		Cancel,
	},
	data() {
		return {
			loading: false,
			error: null,
			includeObjects: true,
			exportFormat: 'configuration',
			exportFormats: [
				{ label: 'Configuration (JSON)', value: 'configuration' },
				{ label: 'Excel', value: 'excel' },
				{ label: 'CSV', value: 'csv' },
			],
		}
	},
	computed: {
		registerTitle() {
			const item = registerStore.registerItem
			return item?.title || 'Unknown'
		},
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
			this.loading = false
			this.error = null
			this.includeObjects = true
			this.exportFormat = 'configuration'
		},
		async exportRegister() {
			const item = registerStore.registerItem
			if (!item?.id) {
				this.error = 'Invalid register selected'
				return
			}

			this.loading = true
			this.error = null

			try {
				// Generate the export URL with query parameters
				const url = generateUrl(`/apps/openregister/api/registers/${item.id}/export`)
				const params = {
					format: this.exportFormat,
					includeObjects: this.includeObjects,
				}

				// Make the API call
				const response = await axios({
					url,
					method: 'GET',
					params,
					responseType: 'blob', // Important for file download
				})

				// Create a download link
				const blob = new Blob([response.data], { type: response.headers['content-type'] })
				const downloadUrl = window.URL.createObjectURL(blob)
				const link = document.createElement('a')

				// Get filename from response headers or generate a default one
				const contentDisposition = response.headers['content-disposition']
				const filename = contentDisposition
					? contentDisposition.split('filename=')[1].replace(/"/g, '')
					: `register_${item.id}_${new Date().toISOString().split('T')[0]}.${this.exportFormat === 'excel' ? 'xlsx' : this.exportFormat === 'csv' ? 'csv' : 'json'}`

				link.href = downloadUrl
				link.download = filename
				document.body.appendChild(link)
				link.click()
				document.body.removeChild(link)
				window.URL.revokeObjectURL(downloadUrl)

				this.closeModal()
			} catch (error) {
				this.error = error.response?.data?.error || error.message || 'Failed to export register'
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

.form-group {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.form-group label {
	font-weight: bold;
}
</style>
