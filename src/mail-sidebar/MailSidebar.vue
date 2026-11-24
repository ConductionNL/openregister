<template>
	<div
		class="or-mail-sidebar-root"
		:class="{
			'or-mail-sidebar-root--idle': !isMessageView,
		}">
		<aside
			v-if="!collapsed"
			class="or-mail-sidebar"
			:aria-label="t('openregister', 'Connections')">
			<div class="or-mail-panel__head">
				<h2 class="or-mail-panel__caption">
					{{ t('openregister', 'Connections') }}
				</h2>
				<NcButton
					variant="tertiary"
					:aria-label="t('openregister', 'Close connections')"
					@click="toggleCollapsed">
					<template #icon>
						<Close :size="20" />
					</template>
				</NcButton>
			</div>

			<div class="or-mail-panel__body">
				<h3 v-if="subject" class="or-mail-panel__subject">
					{{ subject }}
				</h3>

				<ObjectsTab
					ref="objectsTab"
					:accountId="accountId"
					:messageId="messageId" />

				<ActionsTab
					:accountId="accountId"
					:messageId="messageId"
					@linked="onLinked" />

				<div class="or-mail-panel__found">
					<button
						type="button"
						class="or-mail-panel__found-toggle"
						:aria-expanded="String(foundOpen)"
						aria-controls="or-mail-panel-found"
						@click="foundOpen = !foundOpen">
						<TextSearch :size="20" class="or-mail-panel__found-icon" />
						<span class="or-mail-panel__found-label">{{
							t('openregister', 'Found in this mail')
						}}</span>
						<span class="or-mail-panel__found-hint">{{
							t('openregister', 'Names, addresses and numbers')
						}}</span>
						<ChevronDown v-if="foundOpen" :size="20" />
						<ChevronRight v-else :size="20" />
					</button>
					<div v-if="foundOpen" id="or-mail-panel-found">
						<EntitiesTab :accountId="accountId" :messageId="messageId" />
					</div>
				</div>
			</div>
		</aside>

		<button
			v-else
			class="or-mail-sidebar__collapsed-toggle"
			:aria-label="t('openregister', 'Open connections sidebar')"
			:title="t('openregister', 'Open connections sidebar')"
			@click="toggleCollapsed">
			<LinkVariant :size="16" />
			<span class="or-mail-sidebar__collapsed-label">{{
				t('openregister', 'Connections')
			}}</span>
		</button>
	</div>
</template>

<script>
/**
 * The connections panel beside an open mail: a caption head with a close
 * button, the mail's subject, the items it is connected to (drop targets for
 * attachments), a search and a "New ... from this mail" row per kind of item,
 * and a fold-out of what was found in the mail.
 *
 * @spec openspec/specs/mail-sidebar/spec.md#requirement-sidebar-panel-ui-with-linked-objects-display
 * @spec openspec/specs/mail-sidebar/spec.md
 */
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import Close from 'vue-material-design-icons/Close.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import TextSearch from 'vue-material-design-icons/TextSearch.vue'
import ActionsTab from './components/ActionsTab.vue'
import EntitiesTab from './components/EntitiesTab.vue'
import ObjectsTab from './components/ObjectsTab.vue'
import { useAttachmentDrag } from './composables/useAttachmentDrag.js'
import { useMailObserver } from './composables/useMailObserver.js'

const COLLAPSED_STORAGE_KEY = 'openregister-mail-sidebar-collapsed'

export default {
	name: 'MailSidebar',
	components: {
		NcButton,
		ActionsTab,
		ObjectsTab,
		EntitiesTab,
		ChevronDown,
		ChevronRight,
		Close,
		LinkVariant,
		TextSearch,
	},

	/**
	 * @spec openspec/specs/mail-sidebar/spec.md
	 */
	setup() {
		const mailObserver = useMailObserver({ debounceMs: 300 })
		useAttachmentDrag()
		return { ...mailObserver }
	},

	data() {
		return {
			collapsed: false,
			foundOpen: false,
			subject: '',
		}
	},

	watch: {
		messageId: {
			immediate: true,
			/**
			 * A new email: fold in what was found and read its subject.
			 *
			 * @spec openspec/specs/mail-sidebar/spec.md
			 */
			handler() {
				this.foundOpen = false
				this.loadSubject()
			},
		},
	},

	/**
	 * @spec openspec/specs/mail-sidebar/spec.md
	 */
	created() {
		const stored = localStorage.getItem(COLLAPSED_STORAGE_KEY)
		if (stored === 'true') {
			this.collapsed = true
		}
	},

	methods: {
		t,
		/**
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		toggleCollapsed() {
			this.collapsed = !this.collapsed
			localStorage.setItem(COLLAPSED_STORAGE_KEY, String(this.collapsed))
		},

		/**
		 * Read the open mail's subject from Mail, for the panel's title. A
		 * failed read leaves the title out.
		 *
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		async loadSubject() {
			const messageId = this.messageId
			this.subject = ''
			if (!messageId) {
				return
			}
			try {
				const response = await axios.get(
					generateUrl('/apps/mail/api/messages/{id}', { id: messageId }),
					{ timeout: 10000 },
				)
				if (this.messageId === messageId) {
					this.subject = response.data?.subject || ''
				}
			} catch {
				// The title is left out; the rest of the panel does not need it.
			}
		},

		/**
		 * @spec openspec/specs/mail-sidebar/spec.md#requirement-sidebar-panel-ui-with-linked-objects-display
		 */
		onLinked() {
			if (this.$refs.objectsTab) {
				this.$refs.objectsTab.loadObjects()
			}
		},
	},
}
</script>
