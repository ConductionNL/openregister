<template>
	<section
		class="or-tab-objects"
		:aria-label="t('openregister', 'Connected to')">
		<h4 class="or-mail-panel__section-title">
			{{ t('openregister', 'Connected to') }}
		</h4>
		<div v-if="loading" class="or-tab-loading">
			<NcLoadingIcon :size="20" />
			<span>{{ t('openregister', 'Loading connections...') }}</span>
		</div>
		<p v-else-if="objects.length === 0" class="or-mail-object-empty">
			{{ t('openregister', 'Not connected to anything yet.') }}
		</p>
		<div v-else class="or-mail-object-list">
			<div
				v-for="obj in objects"
				:key="obj.uuid"
				class="or-mail-object-card"
				:class="{ 'or-mail-object-card--drop-target': dropTarget === obj.uuid }"
				@dragover.prevent="onAttachmentDragOver($event, obj)"
				@dragleave="onAttachmentDragLeave(obj)"
				@drop.prevent="onAttachmentDrop($event, obj)">
				<div class="or-mail-object-card__header">
					<span class="or-mail-object-card__kind">
						<span class="or-mail-object-card__schema">{{ obj.schema }}</span>
						<span v-if="objectIdentifier(obj)" class="or-mail-object-card__id">#{{ objectIdentifier(obj) }}</span>
					</span>
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
				<a
					class="or-mail-object-card__title"
					:href="objectUrl(obj)"
					target="_blank"
					:title="t('openregister', 'Open connected item')">
					{{ displayName(obj) }}
				</a>
				<p v-if="objectSubline(obj)" class="or-mail-object-card__sub">
					{{ objectSubline(obj) }}
				</p>
				<ObjectStatus
					class="or-mail-object-card__status"
					:status="objectStatus(obj)"
					:handler="nameOf(objectHandler(obj))" />
				<p v-if="dropTarget === obj.uuid" class="or-mail-object-card__drop">
					<Paperclip :size="16" />
					{{ t('openregister', 'Drop an attachment here to add it') }}
				</p>
			</div>
		</div>

		<RemoveConnectionDialog
			:show="removeTarget !== null"
			:name="removeTarget ? displayName(removeTarget) : ''"
			:removing="removing"
			@cancel="cancelUnlink"
			@confirm="confirmUnlink" />
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
/**
 * The items the open mail is connected to, at the top of the mail panel;
 * each card is also the drop target for Mail attachments (drops upload the file to the
 * linked OR object via /api/objects/{r}/{s}/{id}/files-multipart).
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 * @spec openspec/specs/mail-sidebar/spec.md
 */
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import RemoveConnectionDialog from '../../dialogs/mail/RemoveConnectionDialog.vue'
import ObjectStatus from './ObjectStatus.vue'
import { mailMessageTab } from '../composables/mailMessageTab.js'
import { ATTACHMENT_MIME } from '../composables/useAttachmentDrag.js'
import { displayNameOf } from '../displayNames.js'
import { objectHandler, objectIdentifier, objectStatus, objectSubline } from '../objectRow.js'

/**
 * The toast for an attachment added to an object.
 *
 * @param {string} name The object's display name
 * @return {string} The message
 */
function addedToText(name) {
	return t('openregister', 'Attachment added to {name}', { name })
}

/**
 * Where an attachment dropped on an object is uploaded to.
 *
 * @param {object} obj The linked object
 * @return {{register: *, schema: *, objectId: *}|null} The target, or null when the object lacks its register, schema or id
 */
function uploadTargetOf(obj) {
	const register = obj.register
	const schema = obj.schemaId || obj.schema
	const objectId = obj.id || obj.uuid
	if (!register || !schema || !objectId) {
		return null
	}
	return { register, schema, objectId }
}

export default {
	name: 'ObjectsTab',
	components: {
		NcButton,
		NcLoadingIcon,
		Close,
		ObjectStatus,
		Paperclip,
		RemoveConnectionDialog,
	},

	mixins: [mailMessageTab('loadObjects')],

	data() {
		return {
			objects: [],
			loading: false,
			uploadingObjectUuid: null,
			// Connection pending removal (null = dialog closed) + in-flight flag.
			removeTarget: null,
			removing: false,
			// The card an attachment is being dragged over, which says it takes the drop.
			dropTarget: null,
			// Display names of handlers by user id, '' while being read.
			names: {},
		}
	},

	methods: {
		t,
		objectIdentifier,
		objectSubline,
		objectStatus,
		objectHandler,

		/**
		 * A handler's display name once read, else nothing yet.
		 *
		 * @param {string} uid The handler's user id.
		 * @return {string}
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		nameOf(uid) {
			return uid ? (this.names[uid] || '') : ''
		},

		/**
		 * Add a Mail attachment to a connected item, as a drop on its card does.
		 * Without a uuid the first connected item takes it.
		 *
		 * @param {object} attachment The attachment, as the drag payload carries it.
		 * @param {string} [uuid] The connected item.
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		async addAttachment(attachment, uuid) {
			const obj = this.objects.find((candidate) => !uuid || candidate.uuid === uuid)
			if (!obj) return
			await this.uploadDroppedAttachment(JSON.stringify(attachment), obj)
		},

		/**
		 * Remove a connection at once, for Undo right after it was made.
		 *
		 * @param {string} uuid The connected item.
		 * @return {Promise<boolean>} Whether it was removed.
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		async unlinkNow(uuid) {
			try {
				const base = generateUrl('/apps/openregister/api/objects/{uuid}/_linked/mail', { uuid })
				await axios.delete(`${base}/${this.accountId}/${this.messageId}`)
				this.loadObjects()
				return true
			} catch {
				showError(t('openregister', 'Failed to remove connection'))
				return false
			}
		},

		/**
		 * OR sometimes derives object names as a JSON-encoded locale map
		 * (e.g. `{"nl":"…"}`); unwrap it so the card shows the readable name.
		 *
		 * @param {object} obj
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
				} catch {
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
		 * @param {object} obj
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
				this.$emit('objects', [])
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
				this.$emit('objects', this.objects)
				this.resolveNames()
			}
		},

		/**
		 * Read the display names of the connected items' handlers.
		 *
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		resolveNames() {
			for (const uid of new Set(this.objects.map(objectHandler).filter(Boolean))) {
				if (this.names[uid] !== undefined) continue
				this.names[uid] = ''
				displayNameOf(uid).then((name) => {
					this.names[uid] = name
				})
			}
		},

		/**
		 * Open the confirmation dialog for removing a connection.
		 *
		 * @param {object} obj
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
		 * A drag over a card: it takes a copy, and the card says it is the drop target.
		 *
		 * @param {DragEvent} event The drag event.
		 * @param {object} [obj] The card's object.
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		onAttachmentDragOver(event, obj) {
			if (event.dataTransfer) {
				event.dataTransfer.dropEffect = 'copy'
			}
			if (obj) {
				this.dropTarget = obj.uuid
			}
		},

		/**
		 * The pointer left a card while dragging: it is no longer the drop target.
		 *
		 * @param {object} obj The card's object.
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		onAttachmentDragLeave(obj) {
			if (this.dropTarget === obj.uuid) {
				this.dropTarget = null
			}
		},

		/**
		 * @param {DragEvent} event
		 * @param {object} obj
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		async onAttachmentDrop(event, obj) {
			this.dropTarget = null
			const raw = event.dataTransfer?.getData(ATTACHMENT_MIME)
			if (!raw) {
				return
			}
			const target = uploadTargetOf(obj)
			if (!target) {
				showError(
					t('openregister', 'Object metadata incomplete for file upload'),
				)
				return
			}
			await this.uploadDroppedAttachment(raw, obj)
		},

		/**
		 * Upload a dropped attachment to an object, marking the object as
		 * uploading meanwhile, and say how it went.
		 *
		 * @param {string} raw The dropped attachment, as JSON
		 * @param {object} obj The object it was dropped on
		 * @return {Promise<void>}
		 */
		async uploadDroppedAttachment(raw, obj) {
			try {
				const attachment = JSON.parse(raw)
				this.uploadingObjectUuid = obj.uuid
				await this.uploadAttachmentToObject(attachment, uploadTargetOf(obj))
				showSuccess(addedToText(this.displayName(obj)))
			} catch (err) {
				showError(t('openregister', 'Failed to add attachment'))
				console.error('[ObjectsTab] Attachment drop upload failed:', err)
			} finally {
				this.uploadingObjectUuid = null
			}
		},

		/**
		 * @param {object} attachment
		 * @param {object} target
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
				'/apps/openregister/api/objects/{register}/{schema}/{id}/files-multipart',
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
