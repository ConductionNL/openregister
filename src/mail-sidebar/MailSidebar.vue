<template>
	<div
		class="or-mail-sidebar-root"
		:class="{
			'or-mail-sidebar-root--connections-disabled': connectionsDisabled,
		}">
		<NcAppSidebar
			v-if="!collapsed"
			:name="sidebarTitle"
			:subname="sidebarSubname"
			:compact="true"
			v-model:active="activeTab"
			class="or-mail-sidebar"
			@close="toggleCollapsed">
			<template #description>
				<div v-if="!isMessageView" class="or-mail-sidebar__hint">
					{{ t('openregister', 'Select an email to see its connections') }}
				</div>
			</template>

			<NcAppSidebarTab
				id="objects"
				:name="t('openregister', 'Connections')"
				:order="1">
				<template #icon>
					<LinkVariant :size="20" />
				</template>
				<ObjectsTab
					ref="objectsTab"
					:account-id="accountId"
					:message-id="messageId"
					@count="onObjectsCount"
					@switch-tab="switchTab" />
			</NcAppSidebarTab>

			<NcAppSidebarTab
				id="actions"
				:name="t('openregister', 'Connect')"
				:order="2">
				<template #icon>
					<Plus :size="20" />
				</template>
				<ActionsTab
					:account-id="accountId"
					:message-id="messageId"
					@linked="onLinked" />
			</NcAppSidebarTab>

			<NcAppSidebarTab
				id="entities"
				:name="t('openregister', 'Entities')"
				:order="3">
				<template #icon>
					<AccountMultiple :size="20" />
				</template>
				<EntitiesTab :account-id="accountId" :message-id="messageId" />
			</NcAppSidebarTab>
		</NcAppSidebar>

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
 * @spec openspec/specs/mail-sidebar/spec.md#requirement-sidebar-panel-ui-with-linked-objects-display
 * @spec openspec/specs/mail-sidebar/spec.md
 */
import { translate as t } from '@nextcloud/l10n'
import { NcAppSidebar, NcAppSidebarTab } from '@nextcloud/vue'
import ActionsTab from './components/ActionsTab.vue'
import ObjectsTab from './components/ObjectsTab.vue'
import EntitiesTab from './components/EntitiesTab.vue'
import { useMailObserver } from './composables/useMailObserver.js'
import { useAttachmentDrag } from './composables/useAttachmentDrag.js'

import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'

const COLLAPSED_STORAGE_KEY = 'openregister-mail-sidebar-collapsed'

export default {
	name: 'MailSidebar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		ActionsTab,
		ObjectsTab,
		EntitiesTab,
		LinkVariant,
		Plus,
		AccountMultiple,
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
			activeTab: 'objects',
			// null until the Connections tab reports its first count for the
			// current message; drives the empty-state auto-select + disabling.
			objectsCount: null,
			// true while we still owe an auto-select decision for the current
			// message (set on every message change, cleared once acted on).
			autoSelectPending: true,
		}
	},
	computed: {
		/**
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		sidebarTitle() {
			return t('openregister', 'Connections')
		},
		/**
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		sidebarSubname() {
			return ''
		},
		/**
		 * The Connections tab is disabled once we know this email has no
		 * connections yet — there is nothing to show, so we steer the user
		 * to the Connect tab instead.
		 */
		connectionsDisabled() {
			return this.objectsCount === 0
		},
	},
	watch: {
		/**
		 * A new email is selected: forget the previous count and re-arm the
		 * auto-select so the next count decides which tab to open.
		 */
		messageId() {
			this.objectsCount = null
			this.autoSelectPending = true
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
		 * @param tabId
		 * @spec openspec/specs/mail-sidebar/spec.md
		 */
		switchTab(tabId) {
			this.activeTab = tabId
		},
		/**
		 * The Connections tab reports how many connections the current email
		 * has. On a freshly-selected email we auto-open the Connect tab when
		 * there are none (and the Connections tab is disabled), or the
		 * Connections tab when there are some. After that first decision we
		 * leave the user's tab choice alone — only the enabled/disabled state
		 * keeps tracking the count.
		 */
		onObjectsCount(count) {
			this.objectsCount = count
			if (this.autoSelectPending) {
				this.activeTab = count > 0 ? 'objects' : 'actions'
				this.autoSelectPending = false
			} else if (count === 0 && this.activeTab === 'objects') {
				// Connections were all removed while viewing them.
				this.activeTab = 'actions'
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

<style scoped>
/* Collapsed state toggle button */
.or-mail-sidebar__collapsed-toggle {
	position: fixed;
	right: 0;
	top: 50%;
	transform: translateY(-50%);
	z-index: 1500;
	width: 32px;
	padding: 12px 4px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-right: none;
	border-radius: var(--border-radius) 0 0 var(--border-radius);
	cursor: pointer;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
	color: var(--color-main-text);
	box-shadow: -2px 0 4px rgba(0, 0, 0, 0.05);
}

.or-mail-sidebar__collapsed-toggle:hover {
	background: var(--color-background-hover);
}

.or-mail-sidebar__collapsed-toggle:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
}

.or-mail-sidebar__collapsed-label {
	font-size: 10px;
	font-weight: 700;
	writing-mode: vertical-rl;
	text-orientation: mixed;
	letter-spacing: 0.5px;
}

/* Hint text in description slot */
.or-mail-sidebar__hint {
	padding: 0 16px 8px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

/*
 * When the current email has no connections yet, dim and disable the
 * Connections tab header so the user is steered to the Connect tab. The
 * tab button is rendered by NcAppSidebar as a NcCheckboxRadioSwitch whose
 * wrapper carries the id `tab-button-<tabId>`.
 */
.or-mail-sidebar-root--connections-disabled :deep(#tab-button-objects) {
	opacity: 0.4;
	pointer-events: none;
	cursor: not-allowed;
}
</style>
