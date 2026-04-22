<template>
	<div class="or-tab-objects">
		<div v-if="loading" class="or-tab-loading">
			{{ t('openregister', 'Loading linked objects...') }}
		</div>
		<div v-else-if="objects.length === 0" class="or-tab-empty">
			{{ t('openregister', 'No objects linked to this email.') }}
		</div>
		<ul v-else class="or-objects-list">
			<li
				v-for="obj in objects"
				:key="obj.uuid"
				class="or-object-item">
				<div class="or-object-info">
					<span class="or-object-name">{{ obj.name || obj.uuid }}</span>
					<span class="or-object-schema">{{ obj.schema }}</span>
				</div>
				<button
					class="or-object-unlink"
					:title="t('openregister', 'Unlink')"
					@click="unlinkObject(obj)">
					✕
				</button>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'ObjectsTab',
	props: {
		accountId: { type: Number, default: null },
		messageId: { type: Number, default: null },
	},
	data() {
		return {
			objects: [],
			loading: false,
		}
	},
	watch: {
		messageId() {
			this.loadObjects()
		},
	},
	created() {
		this.loadObjects()
	},
	methods: {
		t,
		async loadObjects() {
			if (!this.accountId || !this.messageId) {
				this.objects = []
				return
			}

			this.loading = true
			try {
				const base = generateUrl('/apps/openregister/api/linked/mail')
				const url = `${base}/${this.accountId}/${this.messageId}`
				const response = await axios.get(url, { timeout: 10000 })
				this.objects = response.data?.results || []
			} catch (err) {
				console.error('[ObjectsTab] Load failed:', err)
				this.objects = []
			} finally {
				this.loading = false
			}
		},
		async unlinkObject(obj) {
			if (!confirm(t('openregister', 'Remove link to {name}?', { name: obj.name || obj.uuid }))) {
				return
			}

			try {
				const base = generateUrl('/apps/openregister/api/objects/{uuid}/_linked/mail', {
					uuid: obj.uuid,
				})
				const url = `${base}/${this.accountId}/${this.messageId}`
				await axios.delete(url)
				showSuccess(t('openregister', 'Link removed'))
				this.loadObjects()
			} catch (err) {
				showError(t('openregister', 'Failed to remove link'))
				console.error('[ObjectsTab] Unlink failed:', err)
			}
		},
	},
}
</script>
