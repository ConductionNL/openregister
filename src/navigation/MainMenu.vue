<template>
	<NcAppNavigation>
		<NcAppNavigationNew
			:text="activeOrganisationName"
			@click="handleNavigate('/')">
			<template #icon>
				<Finance :size="20" />
			</template>
		</NcAppNavigationNew>

		<NcAppNavigationList>
			<NcAppNavigationItem :active="$route.path.startsWith('/chat')" :name="t('openregister', 'AI Chat')" @click="handleNavigate('/chat')">
				<template #icon>
					<MessageTextOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path.startsWith('/registers')" :name="t('openregister', 'Registers')" @click="handleNavigate('/registers')">
				<template #icon>
					<DatabaseOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path.startsWith('/schemas')" :name="t('openregister', 'Schemas')" @click="handleNavigate('/schemas')">
				<template #icon>
					<FileTreeOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path.startsWith('/entities')" :name="t('openregister', 'Entities')" @click="handleNavigate('/entities')">
				<template #icon>
					<AccountOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path.startsWith('/templates')" :name="t('openregister', 'Templates')" @click="handleNavigate('/templates')">
				<template #icon>
					<FileOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path.startsWith('/tables')" :name="t('openregister', 'Search / Views')" @click="handleNavigate('/tables')">
				<template #icon>
					<Magnify :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path.startsWith('/files')" :name="t('openregister', 'Files')" @click="handleNavigate('/files')">
				<template #icon>
					<FileDocumentMultipleOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path.startsWith('/agents')" :name="t('openregister', 'Agents')" @click="handleNavigate('/agents')">
				<template #icon>
					<RobotOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		<!-- <NcAppNavigationItem :active="$route.path.startsWith('/search')" name="Search" @click="handleNavigate('/search')">
			<template #icon>
				<LayersSearchOutline :size="20" />
			</template>
		</NcAppNavigationItem> -->
		</NcAppNavigationList>

		<NcAppNavigationSettings>
			<NcAppNavigationItem :active="$route.path.startsWith('/organisation')" :name="t('openregister', 'Organisations')" @click="handleNavigate('/organisation')">
				<template #icon>
					<OfficeBuildingOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path.startsWith('/applications')" :name="t('openregister', 'Applications')" @click="handleNavigate('/applications')">
				<template #icon>
					<ApplicationOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path.startsWith('/sources')" :name="t('openregister', 'Data sources')" @click="handleNavigate('/sources')">
				<template #icon>
					<DatabaseArrowRightOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path.startsWith('/configurations')" :name="t('openregister', 'Configurations')" @click="handleNavigate('/configurations')">
				<template #icon>
					<CogOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path.startsWith('/deleted')" :name="t('openregister', 'Deleted')" @click="handleNavigate('/deleted')">
				<template #icon>
					<DeleteRestore :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path.startsWith('/audit-trails')" :name="t('openregister', 'Audit Trails')" @click="handleNavigate('/audit-trails')">
				<template #icon>
					<TextBoxOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem :active="$route.path.startsWith('/search-trails')" :name="t('openregister', 'Search Trails')" @click="handleNavigate('/search-trails')">
				<template #icon>
					<MagnifyPlus :size="20" />
				</template>
			</NcAppNavigationItem>
		<NcAppNavigationItem :active="$route.path.startsWith('/webhooks')" :name="t('openregister', 'Webhooks')" @click="handleNavigate('/webhooks')">
			<template #icon>
				<Webhook :size="20" />
			</template>
		</NcAppNavigationItem>
		<NcAppNavigationItem :active="$route.path.startsWith('/endpoints')" :name="t('openregister', 'Endpoints')" @click="handleNavigate('/endpoints')">
			<template #icon>
				<Api :size="20" />
			</template>
		</NcAppNavigationItem>
		</NcAppNavigationSettings>
	</NcAppNavigation>
</template>
<script>
import { t } from '@nextcloud/l10n'

import {
	NcAppNavigation,
	NcAppNavigationList,
	NcAppNavigationSettings,
	NcAppNavigationItem,
	NcAppNavigationNew,
} from '@nextcloud/vue'

// Icons
import Finance from 'vue-material-design-icons/Finance.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import ApplicationOutline from 'vue-material-design-icons/ApplicationOutline.vue'
import DatabaseArrowRightOutline from 'vue-material-design-icons/DatabaseArrowRightOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import MessageTextOutline from 'vue-material-design-icons/MessageTextOutline.vue'
import RobotOutline from 'vue-material-design-icons/RobotOutline.vue'
import FileDocumentMultipleOutline from 'vue-material-design-icons/FileDocumentMultipleOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import DeleteRestore from 'vue-material-design-icons/DeleteRestore.vue'
import TextBoxOutline from 'vue-material-design-icons/TextBoxOutline.vue'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import Webhook from 'vue-material-design-icons/Webhook.vue'
import Api from 'vue-material-design-icons/Api.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'

// Store
import { organisationStore } from '../store/store.js'

export default {
	name: 'MainMenu',
	components: {
		// components
		NcAppNavigation,
		NcAppNavigationList,
		NcAppNavigationItem,
		NcAppNavigationSettings,
		NcAppNavigationNew,
		// icons
		Finance,
		DatabaseOutline,
		DatabaseArrowRightOutline,
		FileTreeOutline,
		OfficeBuildingOutline,
		ApplicationOutline,
		Magnify,
		MessageTextOutline,
		RobotOutline,
		FileDocumentMultipleOutline,
		CogOutline,
		DeleteRestore,
		TextBoxOutline,
		MagnifyPlus,
		Webhook,
		Api,
		AccountOutline,
		FileOutline,
	},
	computed: {
		/**
		 * Get the active organisation name to display in navigation.
		 * Shows the current organisational context for the user.
		 *
		 * @return {string} The active organisation name or a fallback
		 */
		activeOrganisationName() {
			const activeOrg = organisationStore.activeOrganisation
			if (activeOrg && activeOrg.name) {
				return activeOrg.name
			}
			// Fallback if no organisation is active
			return t('openregister', 'No Organisation')
		},
	},
	methods: {
		t,
		handleNavigate(path) {
			this.$router.push(path)
		},
		openLink(url, type = '') {
			window.open(url, type)
		},
	},
}
</script>

<style scoped>
/* Ensure navigation doesn't scroll and settings stay at bottom */
:deep(.app-navigation) {
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow: hidden;
}

:deep(.app-navigation__list) {
	flex: 1;
	overflow-y: auto;
	overflow-x: hidden;
	/* Hide scrollbar but keep scrolling functionality */
	scrollbar-width: none; /* Firefox */
	-ms-overflow-style: none; /* IE and Edge */
}

:deep(.app-navigation__list::-webkit-scrollbar) {
	display: none; /* Chrome, Safari, Opera */
}

:deep(.app-navigation__settings) {
	flex-shrink: 0;
	margin-top: auto;
	/* Hide scrollbar but keep scrolling functionality */
	scrollbar-width: none; /* Firefox */
	-ms-overflow-style: none; /* IE and Edge */
}

:deep(.app-navigation__settings::-webkit-scrollbar) {
	display: none; /* Chrome, Safari, Opera */
}
</style>
