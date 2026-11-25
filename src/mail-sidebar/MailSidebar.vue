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
					:messageId="messageId"
					@count="objectsCount = $event"
					@objects="connected = $event" />

				<p v-if="justConnected" class="or-mail-panel__connected" role="status">
					<Check :size="18" class="or-mail-panel__connected-icon" />
					<span>{{ justConnectedText }}</span>
					<button type="button" class="or-mail-panel__undo" @click="undoConnect">
						{{ t('openregister', 'Undo') }}
					</button>
				</p>

				<ActionsTab
					ref="actionsTab"
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
						<ChevronUp v-if="foundOpen" :size="20" />
						<ChevronDown v-else :size="20" />
					</button>
					<div v-if="foundOpen" id="or-mail-panel-found">
						<EntitiesTab
							:accountId="accountId"
							:messageId="messageId"
							@search="searchFound" />
					</div>
				</div>
			</div>
		</aside>

		<button
			type="button"
			class="or-mail-connections-button"
			:class="{ 'or-mail-connections-button--panel-open': !collapsed }"
			:aria-pressed="String(!collapsed)"
			@click="toggleCollapsed">
			<LinkVariant :size="18" />
			<span>{{ t('openregister', 'Connections') }}</span>
			<span
				v-if="objectsCount"
				class="or-mail-connections-button__count"
				:aria-label="n('openregister', '%n connection', '%n connections', objectsCount)">{{ objectsCount }}</span>
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
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import { computed, ref } from 'vue'
import Check from 'vue-material-design-icons/Check.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import Close from 'vue-material-design-icons/Close.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import TextSearch from 'vue-material-design-icons/TextSearch.vue'
import ActionsTab from './components/ActionsTab.vue'
import EntitiesTab from './components/EntitiesTab.vue'
import ObjectsTab from './components/ObjectsTab.vue'
import { useAddToObject } from './composables/useAddToObject.js'
import { useAttachmentDrag } from './composables/useAttachmentDrag.js'
import { useMailObserver } from './composables/useMailObserver.js'
import { useSubjectLine } from './composables/useSubjectLine.js'
import { objectIdentifier } from './objectRow.js'
import { sentenceName } from './sentenceName.js'

const COLLAPSED_STORAGE_KEY = 'openregister-mail-sidebar-collapsed'

export default {
	name: 'MailSidebar',
	components: {
		NcButton,
		ActionsTab,
		ObjectsTab,
		EntitiesTab,
		Check,
		ChevronDown,
		ChevronUp,
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
		// The items the open mail is connected to, as the card list reports them.
		const connected = ref([])
		const objectsTab = ref(null)
		const addLabel = computed(() => (connected.value.length > 0
			? t('openregister', 'Add to {name}', { name: sentenceName(connected.value[0].schema) })
			: ''))
		useAddToObject(addLabel, (attachment) => objectsTab.value?.addAttachment(attachment))
		useSubjectLine(connected)
		return { ...mailObserver, connected, objectsTab }
	},

	data() {
		return {
			collapsed: false,
			foundOpen: false,
			subject: '',
			// How many items the open mail is connected to; null until known.
			objectsCount: null,
			// The connection just made, for the "Connected to" line with Undo.
			justConnected: null,
		}
	},

	computed: {
		/**
		 * "Connected to case #2026-4833", for the line under the cards.
		 *
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		justConnectedText() {
			const link = this.justConnected
			if (!link) return ''
			const name = sentenceName(link.schema?.title || '')
			const number = objectIdentifier(link.object)
			return number
				? t('openregister', 'Connected to {name} #{number}', { name, number })
				: t('openregister', 'Connected to {name}', { name })
		},
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
				this.objectsCount = null
				this.justConnected = null
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
		n,
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
		 * A connection was made: reload the cards and say so, with Undo.
		 *
		 * @param {{schema: object, object: object}} [link] The schema and the item connected.
		 * @spec openspec/specs/mail-sidebar/spec.md#requirement-sidebar-panel-ui-with-linked-objects-display
		 */
		onLinked(link) {
			if (this.$refs.objectsTab) {
				this.$refs.objectsTab.loadObjects()
			}
			this.justConnected = link?.object ? link : null
		},

		/**
		 * Take back the connection just made.
		 *
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		async undoConnect() {
			const uuid = this.justConnected?.object?.id || this.justConnected?.object?.uuid
			if (!uuid || !this.$refs.objectsTab) return
			if (await this.$refs.objectsTab.unlinkNow(uuid)) {
				this.justConnected = null
			}
		},

		/**
		 * Search the first kind of item for a value found in the mail.
		 *
		 * @param {string} value The found value.
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		searchFound(value) {
			this.$refs.actionsTab?.searchFor(value)
		},
	},
}
</script>
