<template>
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
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcAppSidebar from '@nextcloud/vue/dist/Components/NcAppSidebar.js'
import NcAppSidebarTab from '@nextcloud/vue/dist/Components/NcAppSidebarTab.js'
import ActionsTab from './components/ActionsTab.vue'
import ObjectsTab from './components/ObjectsTab.vue'
import EntitiesTab from './components/EntitiesTab.vue'
import { useMailObserver } from './composables/useMailObserver.js'

const COLLAPSED_STORAGE_KEY = 'openregister-mail-sidebar-collapsed'

export default {
	name: 'MailSidebar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		ActionsTab,
		ObjectsTab,
		EntitiesTab,
	},
	setup() {
		const mailObserver = useMailObserver({ debounceMs: 300 })
		return { ...mailObserver }
	},
	data() {
		return {
			collapsed: false,
			activeTab: 'actions',
		}
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
		onLinked() {
			if (this.$refs.objectsTab) {
				this.$refs.objectsTab.loadObjects()
			}
		},
	},
}
</script>

<style scoped>
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
}
</style>
