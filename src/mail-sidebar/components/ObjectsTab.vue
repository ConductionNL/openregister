<template>
	<div class="or-tab-objects">
		<div v-if="loading" class="or-tab-loading">
			<NcLoadingIcon :size="28" />
			<span>{{ t('openregister', 'Loading linked objects...') }}</span>
		</div>
		<NcEmptyContent
			v-else-if="objects.length === 0"
			:name="t('openregister', 'No linked objects')"
			:description="t('openregister', 'Link an object to see it here.')">
			<template #icon>
				<LinkVariant :size="48" />
			</template>
			<template #action>
				<NcButton type="primary" @click="$emit('switch-tab', 'actions')">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('openregister', 'Link to Object') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<template v-else>
			<div class="or-mail-object-list">
				<div
					v-for="obj in objects"
					:key="obj.uuid"
					class="or-mail-object-card">
					<div class="or-mail-object-card__header">
						<div class="or-mail-object-card__title">
							<a
								:href="objectUrl(obj)"
								target="_blank"
								:title="t('openregister', 'Open in OpenRegister')">
								{{ obj.name || obj.uuid }}
							</a>
						</div>
						<NcButton
							type="tertiary"
							:aria-label="t('openregister', 'Remove link to {name}', { name: obj.name || obj.uuid })"
							@click="unlinkObject(obj)">
							<template #icon>
								<Close :size="20" />
							</template>
						</NcButton>
					</div>
					<div class="or-mail-object-card__meta">
						<span class="or-mail-object-card__schema">{{ obj.schema }}</span>
						<span v-if="obj.register" class="or-mail-object-card__register">Register #{{ obj.register }}</span>
					</div>
				</div>
			</div>
			<div class="or-tab-objects__actions">
				<NcButton type="secondary" wide @click="$emit('switch-tab', 'actions')">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('openregister', 'Link another object') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import Plus from 'vue-material-design-icons/Plus.vue'
import Close from 'vue-material-design-icons/Close.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'

export default {
	name: 'ObjectsTab',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		Plus,
		Close,
		LinkVariant,
	},
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
		objectUrl(obj) {
			return generateUrl('/apps/openregister/registers/{register}/{schemaId}/{uuid}', {
				register: obj.register,
				schemaId: obj.schemaId,
				uuid: obj.uuid,
			})
		},
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

<style scoped>
.or-tab-objects__actions {
	margin-top: 12px;
	padding: 0 4px;
}

.or-tab-loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 24px 0;
	color: var(--color-text-maxcontrast);
}
</style>
