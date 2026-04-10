<template>
	<div class="section">
		<h2>{{ t('openregister', 'Personal Data Export') }}</h2>
		<p>{{ t('openregister', 'Download a copy of all your personal data stored in OpenRegister (GDPR Article 20).') }}</p>
		<NcButton type="primary"
			:disabled="loading"
			@click="exportData">
			<template v-if="loading">
				{{ t('openregister', 'Exporting...') }}
			</template>
			<template v-else>
				{{ t('openregister', 'Export my data') }}
			</template>
		</NcButton>
		<p v-if="message" :class="{ 'section__error': isError, 'section__success': !isError }">
			{{ message }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

export default {
	name: 'ExportSection',
	components: { NcButton },
	data() {
		return {
			loading: false,
			message: '',
			isError: false,
		}
	},
	methods: {
		t,
		async exportData() {
			this.loading = true
			this.message = ''
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/user/me/export'),
					{ responseType: 'blob' },
				)
				const url = window.URL.createObjectURL(new Blob([response.data]))
				const link = document.createElement('a')
				link.href = url
				link.setAttribute('download', `openregister-export-${new Date().toISOString().slice(0, 10)}.json`)
				document.body.appendChild(link)
				link.click()
				link.remove()
				window.URL.revokeObjectURL(url)
				this.message = t('openregister', 'Export downloaded successfully')
				this.isError = false
			} catch (e) {
				if (e.response?.status === 429) {
					this.message = t('openregister', 'Export is rate limited. Please try again later.')
				} else {
					this.message = t('openregister', 'Failed to export data')
				}
				this.isError = true
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.section { margin-bottom: 32px; padding: 16px; border-bottom: 1px solid var(--color-border); }
.section__error { color: var(--color-error); margin-top: 8px; }
.section__success { color: var(--color-success); margin-top: 8px; }
</style>
