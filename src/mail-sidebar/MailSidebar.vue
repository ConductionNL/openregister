<template>
	<div
		class="or-mail-sidebar"
		:class="{ 'or-mail-sidebar--collapsed': collapsed }"
		role="complementary"
		:aria-label="t('openregister', 'OpenRegister sidebar')">
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
			<div v-if="!isMessageView" class="or-tab-empty">
				{{ t('openregister', 'Select an email to see linked data') }}
			</div>

			<!-- Tabs when email is selected -->
			<template v-else>
				<div class="or-mail-tabs">
					<button
						v-for="tab in tabs"
						:key="tab.id"
						class="or-mail-tab"
						:class="{ 'or-mail-tab--active': activeTab === tab.id }"
						@click="activeTab = tab.id">
						{{ tab.label }}
					</button>
				</div>

				<div class="or-mail-tab-content">
					<ActionsTab
						v-if="activeTab === 'actions'"
						:account-id="accountId"
						:message-id="messageId"
						@linked="onLinked" />
					<ObjectsTab
						v-if="activeTab === 'objects'"
						ref="objectsTab"
						:account-id="accountId"
						:message-id="messageId" />
					<EntitiesTab
						v-if="activeTab === 'entities'"
						:account-id="accountId"
						:message-id="messageId" />
				</div>
			</template>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import ActionsTab from './components/ActionsTab.vue'
import ObjectsTab from './components/ObjectsTab.vue'
import EntitiesTab from './components/EntitiesTab.vue'
import { useMailObserver } from './composables/useMailObserver.js'

const COLLAPSED_STORAGE_KEY = 'openregister-mail-sidebar-collapsed'
const TAB_STORAGE_KEY = 'openregister-mail-sidebar-tab'

export default {
	name: 'MailSidebar',
	components: {
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
			tabs: [
				{ id: 'actions', label: t('openregister', 'Actions') },
				{ id: 'objects', label: t('openregister', 'Objects') },
				{ id: 'entities', label: t('openregister', 'Entities') },
			],
		}
	},
	created() {
		const stored = localStorage.getItem(COLLAPSED_STORAGE_KEY)
		if (stored === 'true') {
			this.collapsed = true
		}

		const storedTab = localStorage.getItem(TAB_STORAGE_KEY)
		if (storedTab && ['actions', 'objects', 'entities'].includes(storedTab)) {
			this.activeTab = storedTab
		}
	},
	watch: {
		activeTab(val) {
			localStorage.setItem(TAB_STORAGE_KEY, val)
		},
	},
	methods: {
		t,
		toggleCollapsed() {
			this.collapsed = !this.collapsed
			localStorage.setItem(COLLAPSED_STORAGE_KEY, String(this.collapsed))
		},
		onLinked() {
			// Refresh objects tab when a new link is created
			if (this.$refs.objectsTab) {
				this.$refs.objectsTab.loadObjects()
			}
		},
	},
}
</script>
