<script setup>
import { translate as t } from '@nextcloud/l10n'
import { registerStore, navigationStore, schemaStore } from '../../store/store.js'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'exportRegister'"
		name="export-register-dialog"
<<<<<<< HEAD
		title="Export Objects"
=======
		:title="t('openregister', 'Export Objects')"
>>>>>>> origin/development
		size="small"
		:can-close="false">
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div class="formContainer">
<<<<<<< HEAD
			<p>Export "{{ schemaTitle }}" objects from "{{ registerTitle }}"</p>
=======
			<p>{{ t('openregister', 'Export "{schema}" objects from "{register}"', { schema: schemaTitle, register: registerTitle }) }}</p>
>>>>>>> origin/development

			<div class="formGroup">
				<label>{{ t('openregister', 'Export Format:') }}</label>
				<NcSelect
						input-label="Export Format" v-model="exportFormat"
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
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				:disabled="loading"
				type="primary"
				@click="exportObjects">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Export v-else :size="20" />
				</template>
				{{ t('openregister', 'Export') }}
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
		/**
		 * @spec exclude Computed register title for display; UI presentation helper.
		 */
		registerTitle() {
			const item = registerStore.registerItem
			return item?.title || 'Unknown'
		},
<<<<<<< HEAD
=======
		/**
		 * @spec exclude Computed schema title for display; UI presentation helper.
		 */
>>>>>>> origin/development
		schemaTitle() {
			const item = schemaStore.schemaItem
			return item?.title || 'Unknown'
		},
	},
	methods: {
		/**
		 * @spec exclude Modal close handler resetting navigationStore.modal and form state; UI plumbing.
		 */
		closeModal() {
			navigationStore.setModal(false)
			this.loading = false
			this.error = null
			this.exportFormat = 'excel'
		},
<<<<<<< HEAD
=======
		/**
		 * @spec exclude Export handler triggering the objects export endpoint download; UI orchestration plumbing.
		 */
>>>>>>> origin/development
		async exportObjects() {
			const register = registerStore.registerItem
			const schema = schemaStore.schemaItem

			if (!register?.id || !schema?.id) {
<<<<<<< HEAD
				this.error = 'Register and schema are required'
=======
				this.error = t('openregister', 'Register and schema are required')
>>>>>>> origin/development
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
<<<<<<< HEAD
				this.error = error.response?.data?.error || error.message || 'Failed to export objects'
=======
				this.error = error.response?.data?.error || error.message || t('openregister', 'Failed to export objects')
>>>>>>> origin/development
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
