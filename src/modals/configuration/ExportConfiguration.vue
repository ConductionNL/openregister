<script setup>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { configurationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.modal === 'exportConfiguration'"
		name="export-configuration-dialog"
		:title="t('openregister', 'Export Configuration')"
		size="small"
		noClose>
		<NcNoteCard v-if="errorMessage" type="error">
			<p>{{ errorMessage }}</p>
		</NcNoteCard>

		<div class="formContainer">
			<p v-if="configTitle">Export configuration "{{ configTitle }}"?</p>

			<NcCheckboxRadioSwitch
				:modelValue="includeObjects"
				@update:modelValue="includeObjects = $event">
				Include related objects
			</NcCheckboxRadioSwitch>
		</div>

		<template #actions>
			<CloseCancelButton label="Cancel" @click="closeModal" />
			<BusyButton
				:busy="loading"
				:disabled="loading || !isValid"
				variant="primary"
				@click="exportConfiguration">
				<template #icon>
					<Export :size="20" />
				</template>
				Export
			</BusyButton>
		</template>
	</NcDialog>
</template>

<script>
/**
 * The file name of an export: the one the server names, or a dated default.
 *
 * @param {object} response The export response
 * @param {string|number} id The configuration id
 * @return {string} The file name
 */
function exportFilename(response, id) {
	const contentDisposition = response.headers['content-disposition']
	return contentDisposition
		? contentDisposition.split('filename=')[1].replace(/"/g, '')
		: `configuration_${id}_${new Date().toISOString().split('T')[0]}.json`
}

/**
 * Hand an export to the browser as a JSON file download.
 *
 * @param {object} response The export response
 * @param {string|number} id The configuration id
 * @return {void}
 */
function downloadExport(response, id) {
	const blob = new Blob([response.data], { type: 'application/json' })
	const downloadUrl = window.URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = downloadUrl
	link.download = exportFilename(response, id)
	document.body.appendChild(link)
	link.click()
	document.body.removeChild(link)
	window.URL.revokeObjectURL(downloadUrl)
}

import {
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
} from '@nextcloud/vue'
import Export from 'vue-material-design-icons/Export.vue'
import BusyButton from '../../components/shared/BusyButton.vue'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'

/**
 * Download the export of a configuration, with or without its objects.
 *
 * @param {number|string} id The configuration id
 * @param {boolean} includeObjects Whether the objects are exported too
 * @return {Promise<object>} The blob response
 */
function requestExport(id, includeObjects) {
	// Generate the export URL with query parameters
	const url = generateUrl(`/apps/openregister/api/configurations/${id}/export`)
	const params = { includeObjects }

	// Make the API call
	return axios({
		url,
		method: 'GET',
		params,
		responseType: 'blob', // Important for file download
	})
}

/**
 * Why the export failed: the server's error, the error's message, or a
 * generic one.
 *
 * @param {Error} error What went wrong
 * @return {string} The message
 */
function exportFailureText(error) {
	return (
		error.response?.data?.error
		|| error.message
		|| 'Failed to export configuration'
	)
}

export default {
	name: 'ExportConfiguration',
	components: {
		NcDialog,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		// Icons
		BusyButton,
		CloseCancelButton,
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
		/**
		 * @spec exclude UI display helper — returns the configuration title for the modal heading.
		 */
		configTitle() {
			const item = configurationStore.configurationItem
			return item?.title || ''
		},

		/**
		 * @spec exclude UI state helper — enables the export button when a configuration is selected.
		 */
		isValid() {
			const item = configurationStore.configurationItem
			return Boolean(item?.id)
		},

		/**
		 * @spec exclude UI display helper — derives the reactive error message for the modal.
		 */
		errorMessage() {
			// Computed error message that updates reactively
			if (!configurationStore.configurationItem?.id) {
				return 'No configuration selected for export'
			}
			return this.error
		},
	},

	methods: {
		/**
		 * @spec exclude Modal close plumbing — clears modal state.
		 */
		closeModal() {
			navigationStore.setModal(false)
			this.loading = false
			this.error = null
			this.includeObjects = false
		},

		/**
		 * @spec exclude Modal action plumbing — triggers configuration export download.
		 */
		async exportConfiguration() {
			if (!configurationStore.configurationItem?.id) {
				this.error = 'Invalid configuration selected'
				return
			}

			this.loading = true
			this.error = null

			try {
				await this.downloadConfiguration()
			} catch (error) {
				this.error = exportFailureText(error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Download the export of the current configuration, save it and close.
		 *
		 * @return {Promise<void>}
		 */
		async downloadConfiguration() {
			const id = configurationStore.configurationItem.id
			const response = await requestExport(id, this.includeObjects)
			downloadExport(response, id)
			this.closeModal()
		},
	},
}
</script>

<style lang="scss">
@use '../../styles/modals.scss' as *;

@include form-container;
</style>
