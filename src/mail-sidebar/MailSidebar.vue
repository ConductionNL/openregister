<template>
	<div class="or-mail-sidebar-root">
		<NcAppSidebar
			v-if="!collapsed"
			:name="sidebarTitle"
			:subname="sidebarSubname"
			:compact="true"
			:active.sync="activeTab"
			class="or-mail-sidebar"
			@close="toggleCollapsed">
		<template #description>
			<div v-if="!isMessageView" class="or-mail-sidebar__hint">
				{{ t('openregister', 'Select an email to see linked objects') }}
			</div>
		</template>

		<NcAppSidebarTab
			id="objects"
			:name="t('openregister', 'Objects')"
			:order="1">
			<template #icon>
				<LinkVariant :size="20" />
			</template>
			<ObjectsTab
				ref="objectsTab"
				:account-id="accountId"
				:message-id="messageId"
				@switch-tab="switchTab" />
		</NcAppSidebarTab>

		<NcAppSidebarTab
			id="actions"
			:name="t('openregister', 'Link')"
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
			<EntitiesTab
				:account-id="accountId"
				:message-id="messageId" />
		</NcAppSidebarTab>
		</NcAppSidebar>

		<button
			v-else
			class="or-mail-sidebar__collapsed-toggle"
			:aria-label="t('openregister', 'Open OpenRegister sidebar')"
			:title="t('openregister', 'Open OpenRegister sidebar')"
			@click="toggleCollapsed">
			<LinkVariant :size="16" />
			<span class="or-mail-sidebar__collapsed-label">OR</span>
		</button>
	</div>
</template>

<script>
/**
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-49
 */
import { translate as t } from '@nextcloud/l10n'
import NcAppSidebar from '@nextcloud/vue/dist/Components/NcAppSidebar.js'
import NcAppSidebarTab from '@nextcloud/vue/dist/Components/NcAppSidebarTab.js'
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
	setup() {
		const mailObserver = useMailObserver({ debounceMs: 300 })
		useAttachmentDrag()
		return { ...mailObserver }
	},
	data() {
		return {
			collapsed: false,
			activeTab: 'objects',
		}
	},
	computed: {
		sidebarTitle() {
			return t('openregister', 'OpenRegister')
		},
		sidebarSubname() {
			if (!this.isMessageView) {
				return ''
			}
			return t('openregister', 'Mail Integration')
		},
	},
	created() {
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
		switchTab(tabId) {
			this.activeTab = tabId
		},
		/**
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-49
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
}

/* Hint text in description slot */
.or-mail-sidebar__hint {
	padding: 0 16px 8px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
