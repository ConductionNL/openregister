<template>
	<div
		class="or-mail-sidebar"
		:class="{ 'or-mail-sidebar--collapsed': collapsed }"
		role="complementary"
		:aria-label="t('openregister', 'OpenRegister: Linked Objects sidebar')">
		<!-- Collapse toggle tab -->
		<button
			class="or-mail-sidebar__toggle"
			:aria-label="collapsed ? t('openregister', 'Expand sidebar') : t('openregister', 'Collapse sidebar')"
			:title="collapsed ? t('openregister', 'Expand sidebar') : t('openregister', 'Collapse sidebar')"
			@click="toggleCollapsed">
			<span class="or-mail-sidebar__toggle-icon">OR</span>
		</button>

		<div v-show="!collapsed" class="or-mail-sidebar__content">
			<div class="or-mail-sidebar__header">
				<h2 class="or-mail-sidebar__title">
					{{ t('openregister', 'OpenRegister') }}
				</h2>
			</div>

			<!-- Placeholder when no email is selected -->
			<div v-if="!isMessageView" class="or-mail-empty or-mail-sidebar__placeholder">
				{{ t('openregister', 'Select an email to see linked objects') }}
			</div>

			<!-- Error state -->
			<div v-else-if="error" class="or-mail-error">
				<p v-if="error === 'server'">
					{{ t('openregister', 'Could not load linked objects. Try again later.') }}
				</p>
				<p v-else-if="error === 'timeout'">
					{{ t('openregister', 'Request timed out. Please try again.') }}
				</p>
				<p v-else>
					{{ t('openregister', 'An error occurred.') }}
				</p>
				<button class="or-mail-btn or-mail-btn--secondary" @click="retry">
					{{ t('openregister', 'Retry') }}
				</button>
			</div>

			<!-- Content when email is selected -->
			<template v-else>
				<LinkedObjectsList
					:objects="linkedObjects"
					:loading="loading"
					@unlink="handleUnlink" />

				<SuggestedObjectsList
					:objects="suggestedObjects"
					:loading="loading" />

				<!-- Link action button -->
				<div class="or-mail-sidebar__actions">
					<button
						class="or-mail-btn or-mail-btn--primary or-mail-sidebar__link-btn"
						@click="showLinkDialog = true">
						{{ t('openregister', 'Link to Object') }}
					</button>
				</div>
			</template>
		</div>

		<!-- Link dialog -->
		<LinkObjectDialog
			:visible="showLinkDialog"
			:linked-object-uuids="linkedObjectUuids"
			@link="handleLink"
			@close="showLinkDialog = false" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import LinkedObjectsList from './components/LinkedObjectsList.vue'
import SuggestedObjectsList from './components/SuggestedObjectsList.vue'
import LinkObjectDialog from './components/LinkObjectDialog.vue'
import { useMailObserver } from './composables/useMailObserver.js'
import { useEmailLinks } from './composables/useEmailLinks.js'

const COLLAPSED_STORAGE_KEY = 'openregister-mail-sidebar-collapsed'

export default {
	name: 'MailSidebar',
	components: {
		LinkedObjectsList,
		SuggestedObjectsList,
		LinkObjectDialog,
	},
	setup() {
		const emailLinks = useEmailLinks()
		const mailObserver = useMailObserver({
			debounceMs: 300,
			onChange: (parsed) => {
				if (parsed.messageId !== null) {
					// We pass sender as null; it will be extracted from linked results
					emailLinks.loadForMessage(parsed.accountId, parsed.messageId, null)
				} else {
					emailLinks.clear()
				}
			},
		})

		return {
			...emailLinks,
			...mailObserver,
		}
	},
	data() {
		return {
			collapsed: false,
			showLinkDialog: false,
			currentSender: null,
		}
	},
	computed: {
		linkedObjectUuids() {
			return (this.linkedObjects || []).map((obj) => obj.objectUuid)
		},
	},
	created() {
		// Restore collapsed state from localStorage
		const stored = localStorage.getItem(COLLAPSED_STORAGE_KEY)
		if (stored === 'true') {
			this.collapsed = true
		}
	},
	methods: {
		t,
		toggleCollapsed() {
			this.collapsed = !this.collapsed
			localStorage.setItem(COLLAPSED_STORAGE_KEY, String(this.collapsed))
		},
		retry() {
			if (this.accountId && this.messageId) {
				this.loadForMessage(this.accountId, this.messageId, this.currentSender)
			}
		},
		async handleLink(selectedObject) {
			try {
				await this.linkObject({
					mailAccountId: this.accountId,
					mailMessageId: this.messageId,
					objectUuid: selectedObject.uuid,
					registerId: selectedObject.registerId,
					schemaId: selectedObject.schemaId,
				})
				showSuccess(t('openregister', 'Object linked successfully'))
				// Refresh sidebar
				this.loadForMessage(this.accountId, this.messageId, this.currentSender, false)
			} catch (err) {
				const msg = err.response?.data?.error || t('openregister', 'Failed to link object')
				showError(msg)
			}
		},
		async handleUnlink(object) {
			if (!confirm(t('openregister', 'Remove link between this email and {title}?', {
				title: object.objectTitle || object.objectUuid,
			}))) {
				return
			}
			try {
				await this.unlinkObject(object.linkId, this.accountId, this.messageId)
				showSuccess(t('openregister', 'Link removed'))
				this.loadForMessage(this.accountId, this.messageId, this.currentSender, false)
			} catch {
				showError(t('openregister', 'Failed to remove link'))
			}
		},
	},
}
</script>
