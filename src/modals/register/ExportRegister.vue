<script setup>
import { registerStore, navigationStore, schemaStore } from '../../store/store.js'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'exportRegister'"
		name="export-register-dialog"
		title="Export Objects"
		size="small"
		:can-close="false">
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div class="formContainer">
			<p>Export "{{ schemaTitle }}" objects from "{{ registerTitle }}"</p>

			<div class="formGroup">
				<label>Export Format:</label>
				<NcSelect v-model="exportFormat"
					:options="exportFormats"
					option-label="label"
					option-value="value"
					:reduce="option => option.value" />
			</div>
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
				@click="exportObjects">
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
		NcSelect,
		// Icons
		Export,
		Cancel,
	},
	data() {
		return {
			loading: false,
			error: null,
			exportFormat: 'excel',
			exportFormats: [
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
		schemaTitle() {
			const item = schemaStore.schemaItem
			return item?.title || 'Unknown'
		},
	},
	methods: {
		closeModal() {
			navigationStore.setModal(false)
			this.loading = false
			this.error = null
			this.exportFormat = 'excel'
		},
		async exportObjects() {
			const register = registerStore.registerItem
			const schema = schemaStore.schemaItem

			if (!register?.id || !schema?.id) {
				this.error = 'Register and schema are required'
				return
			}

			this.loading = true
			this.error = null

			try {
				const registerSlug = register.slug || register.id
				const schemaSlug = schema.slug || schema.id
				const url = generateUrl(`/apps/openregister/api/objects/${registerSlug}/${schemaSlug}/export`)
				const params = {
					type: this.exportFormat,
				}

				const response = await axios({
					url,
					method: 'GET',
					params,
					responseType: 'blob',
				})

				const blob = new Blob([response.data], { type: response.headers['content-type'] })
				const downloadUrl = window.URL.createObjectURL(blob)
				const link = document.createElement('a')

				const contentDisposition = response.headers['content-disposition']
				const filename = contentDisposition
					? contentDisposition.split('filename=')[1].replace(/"/g, '')
					: `${registerSlug}_${schemaSlug}_${new Date().toISOString().split('T')[0]}.${this.exportFormat === 'excel' ? 'xlsx' : 'csv'}`

				link.href = downloadUrl
				link.download = filename
				document.body.appendChild(link)
				link.click()
				document.body.removeChild(link)
				window.URL.revokeObjectURL(downloadUrl)

				this.closeModal()
			} catch (error) {
				this.error = error.response?.data?.error || error.message || 'Failed to export objects'
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

.formGroup {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.formGroup label {
	font-weight: bold;
}
</style>
