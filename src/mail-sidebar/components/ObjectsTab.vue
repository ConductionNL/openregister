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
					class="or-mail-object-card"
					@dragover.prevent="onAttachmentDragOver"
					@drop.prevent="onAttachmentDrop($event, obj)">
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
						<span v-if="obj.register" class="or-mail-object-card__register">{{ t('openregister', 'Register #{id}', { id: obj.register }) }}</span>
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
import { ATTACHMENT_MIME } from '../composables/useAttachmentDrag.js'

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
			uploadingObjectUuid: null,
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
		onAttachmentDragOver(event) {
			if (event.dataTransfer) {
				event.dataTransfer.dropEffect = 'copy'
			}
		},
		async onAttachmentDrop(event, obj) {
			const raw = event.dataTransfer?.getData(ATTACHMENT_MIME)
			if (!raw) {
				return
			}
			const register = obj.register
			const schema = obj.schemaId || obj.schema
			const objectId = obj.id || obj.uuid
			if (!register || !schema || !objectId) {
				showError(t('openregister', 'Object metadata incomplete for file upload'))
				return
			}
			try {
				const attachment = JSON.parse(raw)
				this.uploadingObjectUuid = obj.uuid
				await this.uploadAttachmentToObject(attachment, { register, schema, objectId })
				showSuccess(t('openregister', 'Attachment added to {name}', { name: obj.name || obj.uuid }))
			} catch (err) {
				showError(t('openregister', 'Failed to add attachment to object'))
				console.error('[ObjectsTab] Attachment drop upload failed:', err)
			} finally {
				this.uploadingObjectUuid = null
			}
		},
		async uploadAttachmentToObject(attachment, target) {
			const response = await fetch(attachment.downloadUrl, { credentials: 'same-origin' })
			if (!response.ok) {
				throw new Error(`Attachment download failed with status ${response.status}`)
			}
			const blob = await response.blob()
			const fileName = attachment.fileName || `attachment-${attachment.attachmentId}`
			const file = new File([blob], fileName, { type: attachment.mime || blob.type || 'application/octet-stream' })
			const formData = new FormData()
			formData.append('files[]', file)
			const uploadUrl = generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}/filesMultipart', {
				register: target.register,
				schema: target.schema,
				id: target.objectId,
			})
			await axios.post(uploadUrl, formData, {
				headers: { 'Content-Type': 'multipart/form-data' },
				timeout: 20000,
			})
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
