<template>
<<<<<<< HEAD
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
=======
	<div
		class="or-mail-sidebar"
		:class="{ 'or-mail-sidebar--collapsed': collapsed }">
		<!-- Collapse toggle tab -->
		<button
			class="or-mail-sidebar__toggle"
			:aria-label="collapsed ? t('openregister', 'Expand sidebar') : t('openregister', 'Collapse sidebar')"
			:title="collapsed ? t('openregister', 'Expand sidebar') : t('openregister', 'Collapse sidebar')"
			@click="toggleCollapsed">
			<span class="or-mail-sidebar__toggle-icon">OR</span>
		</button>

		<div v-show="!collapsed" class="or-mail-sidebar__inner">
			<NcAppSidebar
				:title="t('openregister', 'OpenRegister')"
				:subtitle="isMessageView ? '' : t('openregister', 'Select an email')"
				:compact="true"
				:active.sync="activeTab"
				@close="toggleCollapsed">
				<NcAppSidebarTab
					id="actions"
					:name="t('openregister', 'Actions')"
					icon="icon-add">
					<ActionsTab
						:account-id="accountId"
						:message-id="messageId"
						@linked="onLinked" />
				</NcAppSidebarTab>

				<NcAppSidebarTab
					id="objects"
					:name="t('openregister', 'Objects')"
					icon="icon-link">
					<ObjectsTab
						ref="objectsTab"
						:account-id="accountId"
						:message-id="messageId" />
				</NcAppSidebarTab>

				<NcAppSidebarTab
					id="entities"
					:name="t('openregister', 'Entities')"
					icon="icon-user">
					<EntitiesTab
						:account-id="accountId"
						:message-id="messageId" />
				</NcAppSidebarTab>
			</NcAppSidebar>
		</div>
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
	</div>
</template>

<script>
/**
<<<<<<< HEAD
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-49
 * @spec openspec/changes/retrofit-2026-05-24-mail-sidebar/tasks.md#task-1
=======
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-49
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 */
import { translate as t } from '@nextcloud/l10n'
import NcAppSidebar from '@nextcloud/vue/dist/Components/NcAppSidebar.js'
import NcAppSidebarTab from '@nextcloud/vue/dist/Components/NcAppSidebarTab.js'
import ActionsTab from './components/ActionsTab.vue'
import ObjectsTab from './components/ObjectsTab.vue'
import EntitiesTab from './components/EntitiesTab.vue'
import { useMailObserver } from './composables/useMailObserver.js'
<<<<<<< HEAD
import { useAttachmentDrag } from './composables/useAttachmentDrag.js'

import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

const COLLAPSED_STORAGE_KEY = 'openregister-mail-sidebar-collapsed'

export default {
	name: 'MailSidebar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		ActionsTab,
		ObjectsTab,
		EntitiesTab,
<<<<<<< HEAD
		LinkVariant,
		Plus,
		AccountMultiple,
	},
	/**
	 * @spec openspec/changes/retrofit-2026-05-25-fe-misc/tasks.md#task-1
	 */
	setup() {
		const mailObserver = useMailObserver({ debounceMs: 300 })
		useAttachmentDrag()
=======
	},
	setup() {
		const mailObserver = useMailObserver({ debounceMs: 300 })
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
		return { ...mailObserver }
	},
	data() {
		return {
			collapsed: false,
<<<<<<< HEAD
			activeTab: 'objects',
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/retrofit-2026-05-25-fe-misc/tasks.md#task-1
		 */
		sidebarTitle() {
			return t('openregister', 'OpenRegister')
		},
		/**
		 * @spec openspec/changes/retrofit-2026-05-25-fe-misc/tasks.md#task-4
		 */
		sidebarSubname() {
			if (!this.isMessageView) {
				return ''
			}
			return t('openregister', 'Mail Integration')
		},
	},
	/**
	 * @spec openspec/changes/retrofit-2026-05-25-fe-misc/tasks.md#task-1
	 */
=======
			activeTab: 'actions',
		}
	},
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
	created() {
		const stored = localStorage.getItem(COLLAPSED_STORAGE_KEY)
		if (stored === 'true') {
			this.collapsed = true
		}
	},
	methods: {
		t,
<<<<<<< HEAD
		/**
		 * @spec openspec/changes/retrofit-2026-05-24-mail-sidebar/tasks.md#task-1
		 */
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
		toggleCollapsed() {
			this.collapsed = !this.collapsed
			localStorage.setItem(COLLAPSED_STORAGE_KEY, String(this.collapsed))
		},
		/**
<<<<<<< HEAD
		 * @spec openspec/changes/retrofit-2026-05-25-fe-misc/tasks.md#task-1
		 */
		switchTab(tabId) {
			this.activeTab = tabId
		},
		/**
		 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-49
=======
		 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-49
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
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
<<<<<<< HEAD
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
=======
.or-mail-sidebar__inner {
	height: 100%;
	display: flex;
	flex-direction: column;
}

/* Override NcAppSidebar positioning since we manage our own fixed container */
.or-mail-sidebar__inner :deep(.app-sidebar) {
	position: relative;
	height: 100%;
	width: 100%;
	z-index: auto;
	top: auto;
	right: auto;
}

/* Hide the default close button since we have our own collapse toggle */
.or-mail-sidebar__inner :deep(.app-sidebar__close) {
	display: none;
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
}
</style>
