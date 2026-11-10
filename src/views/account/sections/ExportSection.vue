<template>
	<div class="section">
		<h2>{{ t('openregister', 'Personal Data Export') }}</h2>
		<p>
			{{
				t(
					'openregister',
					'Download a copy of all your personal data stored in OpenRegister (GDPR Article 20).',
				)
			}}
		</p>
		<NcButton variant="primary" :disabled="loading" @click="exportData">
			<template v-if="loading">
				{{ t('openregister', 'Exporting...') }}
			</template>
			<template v-else>
				{{ t('openregister', 'Export my data') }}
			</template>
		</NcButton>
		<SectionStatus :message="message" :isError="isError" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import SectionStatus from '../../../components/shared/SectionStatus.vue'
import { sectionStatus } from '../../../composables/sectionStatus.js'

/**
 * Let the browser save the export as `openregister-export-<today>.json`.
 *
 * @param {BlobPart} data The export
 * @return {void}
 */
function saveExportFile(data) {
	const url = window.URL.createObjectURL(new Blob([data]))
	const link = document.createElement('a')
	link.href = url
	link.setAttribute(
		'download',
		`openregister-export-${new Date().toISOString().slice(0, 10)}.json`,
	)
	document.body.appendChild(link)
	link.click()
	link.remove()
	window.URL.revokeObjectURL(url)
}

export default {
	name: 'ExportSection',
	components: { NcButton, SectionStatus },

	mixins: [sectionStatus],

	data() {
		return {
			loading: false,
		}
	},

	methods: {
		t,
		/**
		 * Download the signed-in user's data export as a JSON file.
		 *
		 * @spec exclude UI plumbing — delegates to the account export API and
		 *   triggers a browser download
		 * @return {Promise<void>}
		 */
		async exportData() {
			this.loading = true
			this.message = ''
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/user/me/export'),
					{ responseType: 'blob' },
				)
				saveExportFile(response.data)
				this.setStatus(t('openregister', 'Export downloaded successfully'))
			} catch (e) {
				this.showExportFailure(e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Show why the export failed: a rate limit asks to try later.
		 *
		 * @spec exclude UI plumbing — sets the section status message
		 * @param {object} e The failed request
		 * @return {void}
		 */
		showExportFailure(e) {
			if (e.response?.status === 429) {
				this.message = t(
					'openregister',
					'Export is rate limited. Please try again later.',
				)
			} else {
				this.message = t('openregister', 'Failed to export data')
			}
			this.isError = true
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../styles/views.scss' as *;

@include section;

@include section-error-rules;
</style>
