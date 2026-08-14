<template>
	<div class="or-tab-objects">
		<div v-if="loading" class="or-tab-loading">
			<NcLoadingIcon :size="28" />
			<span>{{ t('openregister', 'Loading connections...') }}</span>
		</div>
		<NcEmptyContent
			v-else-if="objects.length === 0"
			:name="t('openregister', 'No connections yet')"
			:description="
				t(
					'openregister',
					'Connect this email to a case, lead, invoice and more.',
				)
			">
			<template #icon>
				<LinkVariant :size="48" />
			</template>
			<template #action>
				<NcButton variant="primary" @click="$emit('switch-tab', 'actions')">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('openregister', 'Add a connection') }}
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
							<component
								:is="schemaIconComponent(obj.schemaIcon)"
								:size="20"
								class="or-mail-object-card__icon" />
							<a
								:href="objectUrl(obj)"
								target="_blank"
								:title="t('openregister', 'Open connected item')">
								{{ displayName(obj) }}
							</a>
						</div>
						<NcButton
							variant="tertiary"
							:aria-label="
								t('openregister', 'Remove connection to {name}', {
									name: displayName(obj),
								})
							"
							@click="promptUnlink(obj)">
							<template #icon>
								<Close :size="20" />
							</template>
						</NcButton>
					</div>
					<div class="or-mail-object-card__meta">
						<span class="or-mail-object-card__schema">{{
							obj.schema
						}}</span>
					</div>
				</div>
			</div>
			<div class="or-tab-objects__actions">
				<NcButton
					variant="secondary"
					wide
					@click="$emit('switch-tab', 'actions')">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('openregister', 'Add another connection') }}
				</NcButton>
			</div>
		</template>

		<RemoveConnectionDialog
			:show="removeTarget !== null"
			:name="removeTarget ? displayName(removeTarget) : ''"
			:removing="removing"
			@cancel="cancelUnlink"
			@confirm="confirmUnlink" />
	</div>
</template>

<script>
/**
 * Objects tab — linked-objects list inside the three-tab sidebar; also acts
 * as the drop target for Mail attachments (drops upload the file to the
 * linked OR object via /api/objects/{r}/{s}/{id}/filesMultipart).
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 * @spec openspec/specs/mail-sidebar/spec.md
 */
import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'

import Plus from 'vue-material-design-icons/Plus.vue'
import Close from 'vue-material-design-icons/Close.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import { ATTACHMENT_MIME } from '../composables/useAttachmentDrag.js'
import RemoveConnectionDialog from '../../dialogs/mail/RemoveConnectionDialog.vue'
import { schemaIconComponent } from '../icons.js'

export default {
	name: 'ObjectsTab',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		Plus,
		Close,
		LinkVariant,
		RemoveConnectionDialog,
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
			// Connection pending removal (null = dialog closed) + in-flight flag.
			removeTarget: null,
			removing: false,
		}
	},
	watch: {
		/**
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		messageId() {
			this.loadObjects()
		},
	},
	created() {
		this.loadObjects()
	},
	methods: {
		t,
		schemaIconComponent,
		/**
		 * OR sometimes derives object names as a JSON-encoded locale map
		 * (e.g. `{"nl":"…"}`); unwrap it so the card shows the readable name.
		 *
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		displayName(obj) {
			const raw = obj.name || obj.uuid || ''
			if (typeof raw === 'string' && raw.startsWith('{')) {
				try {
					const values = Object.values(JSON.parse(raw)).filter(
						(v) => typeof v === 'string',
					)
					if (values.length > 0) return values[0]
				} catch (e) {
					// not JSON — fall through to the raw value
				}
			}
			return raw
		},
		/**
		 * Link to the owning app's detail page when the backend resolved a
		 * deep link for this object's schema (registered by leaf apps via the
		 * deep-link registry); otherwise fall back to OpenRegister's own
		 * object page.
		 *
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		objectUrl(obj) {
			if (obj.url) {
				return obj.url
			}
			return generateUrl(
				'/apps/openregister/registers/{register}/{schemaId}/{uuid}',
				{
					register: obj.register,
					schemaId: obj.schemaId,
					uuid: obj.uuid,
				},
			)
		},
		/**
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		async loadObjects() {
			if (!this.accountId || !this.messageId) {
				this.objects = []
				this.$emit('count', 0)
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
				this.$emit('count', this.objects.length)
			}
		},
		/**
		 * Open the confirmation dialog for removing a connection.
		 *
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		promptUnlink(obj) {
			this.removeTarget = obj
		},
		/**
		 * Dismiss the confirmation dialog without removing.
		 */
		cancelUnlink() {
			if (this.removing) {
				return
			}
			this.removeTarget = null
		},
		/**
		 * Confirmed removal of the pending connection.
		 *
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		async confirmUnlink() {
			const obj = this.removeTarget
			if (!obj) {
				return
			}
			this.removing = true
			try {
				const base = generateUrl(
					'/apps/openregister/api/objects/{uuid}/_linked/mail',
					{
						uuid: obj.uuid,
					},
				)
				const url = `${base}/${this.accountId}/${this.messageId}`
				await axios.delete(url)
				showSuccess(t('openregister', 'Connection removed'))
				this.removeTarget = null
				this.loadObjects()
			} catch (err) {
				showError(t('openregister', 'Failed to remove connection'))
				console.error('[ObjectsTab] Unlink failed:', err)
			} finally {
				this.removing = false
			}
		},
		/**
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		onAttachmentDragOver(event) {
			if (event.dataTransfer) {
				event.dataTransfer.dropEffect = 'copy'
			}
		},
		/**
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		async onAttachmentDrop(event, obj) {
			const raw = event.dataTransfer?.getData(ATTACHMENT_MIME)
			if (!raw) {
				return
			}
			const register = obj.register
			const schema = obj.schemaId || obj.schema
			const objectId = obj.id || obj.uuid
			if (!register || !schema || !objectId) {
				showError(
					t('openregister', 'Object metadata incomplete for file upload'),
				)
				return
			}
			try {
				const attachment = JSON.parse(raw)
				this.uploadingObjectUuid = obj.uuid
				await this.uploadAttachmentToObject(attachment, {
					register,
					schema,
					objectId,
				})
				showSuccess(
					t('openregister', 'Attachment added to {name}', {
						name: this.displayName(obj),
					}),
				)
			} catch (err) {
				showError(t('openregister', 'Failed to add attachment'))
				console.error('[ObjectsTab] Attachment drop upload failed:', err)
			} finally {
				this.uploadingObjectUuid = null
			}
		},
		/**
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		async uploadAttachmentToObject(attachment, target) {
			const response = await fetch(attachment.downloadUrl, {
				credentials: 'same-origin',
			})
			if (!response.ok) {
				throw new Error(
					`Attachment download failed with status ${response.status}`,
				)
			}
			const blob = await response.blob()
			const fileName =
				attachment.fileName || `attachment-${attachment.attachmentId}`
			const file = new File([blob], fileName, {
				type: attachment.mime || blob.type || 'application/octet-stream',
			})
			const formData = new FormData()
			formData.append('files[]', file)
			const uploadUrl = generateUrl(
				'/apps/openregister/api/objects/{register}/{schema}/{id}/filesMultipart',
				{
					register: target.register,
					schema: target.schema,
					id: target.objectId,
				},
			)
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
