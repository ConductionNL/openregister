<script setup>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { navigationStore, registerStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.modal === 'exportRegister'"
		name="export-register-dialog"
		:title="t('openregister', 'Export Objects')"
		size="small"
		noClose>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div class="formContainer">
			<p>
				{{
					t(
						'openregister',
						'Export "{schema}" objects from "{register}"',
						{ schema: schemaTitle, register: registerTitle },
					)
				}}
			</p>

			<div class="formGroup">
				<label>{{ t('openregister', 'Export Format:') }}</label>
				<NcSelect
					v-model="exportFormat"
					inputLabel="Export Format"
					:options="exportFormats"
					optionLabel="label"
					optionValue="value"
					:reduce="(option) => option.value" />
			</div>
		</div>

		<template #actions>
			<CloseCancelButton :label="t('openregister', 'Cancel')" @click="closeModal" />
			<BusyButton
				:busy="loading"
				:disabled="loading"
				variant="primary"
				@click="exportObjects">
				<template #icon>
					<Export :size="20" />
				</template>
				{{ t('openregister', 'Export') }}
			</BusyButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcDialog,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import Export from 'vue-material-design-icons/Export.vue'
import BusyButton from '../../components/shared/BusyButton.vue'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'

/**
 * Whether both the register and the schema have an id.
 *
 * @param {object|null} register The selected register
 * @param {object|null} schema The selected schema
 * @return {boolean} True when both have one
 */
function hasIds(register, schema) {
	return Boolean(register?.id && schema?.id)
}

/**
 * The register and schema as they appear in the export URL: slug, else id.
 *
 * @param {object} register The selected register
 * @param {object} schema The selected schema
 * @return {Array<string>} The register and schema slugs
 */
function exportSlugs(register, schema) {
	return [register.slug || register.id, schema.slug || schema.id]
}

export default {
	name: 'ExportRegister',
	components: {
		NcDialog,
		NcNoteCard,
		NcSelect,
		// Icons
		Export,
		BusyButton,
		CloseCancelButton,
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

		/**
		 * @spec exclude Computed schema title for display; UI presentation helper.
		 */
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

		/**
		 * @spec exclude Export handler triggering the objects export endpoint download; UI orchestration plumbing.
		 */
		async exportObjects() {
			const register = registerStore.registerItem
			const schema = schemaStore.schemaItem

			if (!hasIds(register, schema)) {
				this.error = t('openregister', 'Register and schema are required')
				return
			}

			this.loading = true
			this.error = null

			try {
				const slugs = exportSlugs(register, schema)
				const url = generateUrl(
					`/apps/openregister/api/objects/${slugs[0]}/${slugs[1]}/export`,
				)
				const params = {
					type: this.exportFormat,
				}

				const response = await axios({
					url,
					method: 'GET',
					params,
					responseType: 'blob',
				})

				this.saveExport(response, slugs)
				this.closeModal()
			} catch (error) {
				this.error =
					error.response?.data?.error
					|| error.message
					|| t('openregister', 'Failed to export objects')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Hand the exported file to the browser as a download.
		 *
		 * @param {object} response The export response
		 * @param {Array<string>} slugs The register and schema slugs
		 * @spec exclude UI plumbing — turns the export response into a browser download
		 * @return {void}
		 */
		saveExport(response, slugs) {
			const blob = new Blob([response.data], {
				type: response.headers['content-type'],
			})
			const downloadUrl = window.URL.createObjectURL(blob)
			const link = document.createElement('a')

			link.href = downloadUrl
			link.download = this.exportFileName(response, slugs)
			document.body.appendChild(link)
			link.click()
			document.body.removeChild(link)
			window.URL.revokeObjectURL(downloadUrl)
		},

		/**
		 * The file name the server gives, else one from the slugs, the date and the format.
		 *
		 * @param {object} response The export response
		 * @param {Array<string>} slugs The register and schema slugs
		 * @spec exclude UI plumbing — names the downloaded export file
		 * @return {string} The file name
		 */
		exportFileName(response, slugs) {
			const contentDisposition = response.headers['content-disposition']
			if (contentDisposition) {
				return contentDisposition.split('filename=')[1].replace(/"/g, '')
			}
			const extension = this.exportFormat === 'excel' ? 'xlsx' : 'csv'
			return `${slugs[0]}_${slugs[1]}_${new Date().toISOString().split('T')[0]}.${extension}`
		},
	},
}
</script>

<style lang="scss">
@use '../../styles/modals.scss' as *;

@include form-container;

.formGroup {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.formGroup label {
	font-weight: bold;
}
</style>
