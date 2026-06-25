<template>
	<CnAdminSettingsShell
		app-id="openregister"
		app-name="Open Register"
		doc-url="https://docs.openregister.nl"
		:app-version="settingsStore.versionInfo.appVersion || 'Unknown'"
		:is-up-to-date="true"
		:show-reimport="false">
		<!-- Clear App Store Cache action in the version card header -->
		<template #actions>
			<NcButton
				type="secondary"
				:disabled="settingsStore.clearingAppStoreCache"
				@click="settingsStore.clearAppStoreCache('all')">
				<template #icon>
					<NcLoadingIcon v-if="settingsStore.clearingAppStoreCache" :size="20" />
					<Refresh v-else :size="20" />
				</template>
				{{ settingsStore.clearingAppStoreCache ? 'Clearing...' : 'Clear App Store Cache' }}
			</NcButton>
		</template>

		<!-- System Statistics Section -->
		<StatisticsOverview />

		<!-- Cache Management Section -->
		<CacheManagement />

		<!-- RBAC Configuration Section -->
		<RbacConfiguration />

		<!-- Permission Matrix Section -->
		<PermissionMatrix />

		<!-- Organisation Configuration Section -->
		<OrganisationConfiguration />

		<!-- Multitenancy Configuration Section -->
		<MultitenancyConfiguration />

		<!-- Retention Configuration Section -->
		<RetentionConfiguration />

		<!-- Push Notifications Status Section -->
		<PushNotificationsConfiguration :push-status="pushStatus" />

		<!-- n8n Workflow Configuration Section -->
		<N8nConfiguration />

		<!-- LLM Configuration Section -->
		<LlmConfiguration />

		<!-- File Configuration Section -->
		<FileConfiguration />

		<!-- API Token Configuration Section -->
		<ApiTokenConfiguration />

		<!-- Dialogs -->
		<Dialogs />
	</CnAdminSettingsShell>
</template>

<script>
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../store/settings.js'

import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { CnAdminSettingsShell } from '@conduction/nextcloud-vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import StatisticsOverview from './sections/StatisticsOverview.vue'
import CacheManagement from './sections/CacheManagement.vue'
import RbacConfiguration from './sections/RbacConfiguration.vue'
import PermissionMatrix from './sections/PermissionMatrix.vue'
import OrganisationConfiguration from './sections/OrganisationConfiguration.vue'
import MultitenancyConfiguration from './sections/MultitenancyConfiguration.vue'
import RetentionConfiguration from './sections/RetentionConfiguration.vue'
import PushNotificationsConfiguration from './sections/PushNotificationsConfiguration.vue'
import N8nConfiguration from './sections/N8nConfiguration.vue'
import LlmConfiguration from './sections/LlmConfiguration.vue'
import FileConfiguration from './sections/FileConfiguration.vue'
import ApiTokenConfiguration from './sections/ApiTokenConfiguration.vue'
import Dialogs from '../../dialogs/Dialogs.vue'

/**
 * Main settings component that orchestrates all settings sections using Pinia store.
 * This component serves as a container and delegates all data management to the settings store.
 */
export default {
	name: 'Settings',

	components: {
		CnAdminSettingsShell,
		NcButton,
		NcLoadingIcon,
		Refresh,
		StatisticsOverview,
		CacheManagement,
		RbacConfiguration,
		PermissionMatrix,
		OrganisationConfiguration,
		MultitenancyConfiguration,
		RetentionConfiguration,
		PushNotificationsConfiguration,
		N8nConfiguration,
		LlmConfiguration,
		FileConfiguration,
		ApiTokenConfiguration,
		Dialogs,
	},

	props: {
		/**
		 * Push notification status from PHP initial state.
		 * One of: 'not_installed' | 'unreachable' | 'active'
		 */
		pushStatus: {
			type: String,
			default: 'not_installed',
		},
	},

	computed: {
		...mapStores(useSettingsStore),
	},

	/**
	 * Component created lifecycle hook
	 * Initializes the settings store and loads all data
	 * @spec exclude UI plumbing — view-creation data fetch for display only
	 * @return {Promise<void>}
	 */
	async created() {
		try {
			// Load all settings data through the store
			await this.settingsStore.loadSettings()

			// Load additional data that might be needed
			await Promise.allSettled([
				this.settingsStore.loadStats(),
				this.settingsStore.loadCacheStats(),
			])
		} catch (error) {
			console.error('❌ Failed to load settings data:', error)
		}
	},
}
</script>

<style scoped>
/* Minimal styling - let Nextcloud handle the layout */
</style>
