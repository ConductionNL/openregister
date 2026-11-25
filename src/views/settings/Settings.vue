<template>
	<CnAdminSettingsShell
		appId="openregister"
		appName="Open Register"
		docUrl="https://docs.openregister.nl"
		:appVersion="settingsStore.versionInfo.appVersion || 'Unknown'"
		:isUpToDate="true"
		:showReimport="false">
		<!-- Clear App Store Cache action in the version card header -->
		<template #actions>
			<NcButton
				variant="secondary"
				:disabled="settingsStore.clearingAppStoreCache"
				@click="settingsStore.clearAppStoreCache('all')">
				<template #icon>
					<NcLoadingIcon
						v-if="settingsStore.clearingAppStoreCache"
						:size="20" />
					<Refresh v-else :size="20" />
				</template>
				{{
					settingsStore.clearingAppStoreCache
						? 'Clearing...'
						: 'Clear App Store Cache'
				}}
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
		<FlowConfiguration />

		<!-- Search: accent-insensitive object search -->
		<SearchConfiguration />

		<!-- Working calendars: the one clock every deadline is counted against -->
		<WorkingCalendars />

		<RetentionConfiguration />

		<!-- Who sees who read a record: read entries on the audit trail -->
		<ReadEventVisibilityConfiguration />

		<!-- Action rights: who may author flows and put powerful steps in them -->
		<ActionRights />

		<!-- Audit hash-chain health: seal coverage + on-demand verification -->
		<LogIntegrity />
		<RegisterDescriptors />

		<!-- Push Notifications Status Section -->
		<PushNotificationsConfiguration :pushStatus="pushStatus" />

		<!-- Notification rules that reached nobody -->
		<NotificationRuleReach />

		<!-- LLM Configuration Section -->
		<LlmConfiguration />

		<!-- AI translation through Nextcloud's Task Processing -->
		<TranslationConfiguration />

		<!-- Load shedding: dependency breakers and database pressure -->
		<LoadSheddingConfiguration />

		<!-- File Configuration Section -->
		<FileConfiguration />

		<!-- API Token Configuration Section -->
		<ApiTokenConfiguration />

		<EdepotHostKeyConfiguration />

		<!-- Federated configuration sharing: sources, trusted keys, publish/install groups -->
		<FederatedTrustConfiguration />

		<!-- Credential providers other apps declare: review, approve, reject, revoke -->
		<DeclaredCredentialProviders />

		<!-- Dialogs -->
		<Dialogs />
	</CnAdminSettingsShell>
</template>

<script>
import { CnAdminSettingsShell } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { mapStores } from 'pinia'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Dialogs from '../../dialogs/Dialogs.vue'
import ActionRights from './sections/ActionRights.vue'
import ApiTokenConfiguration from './sections/ApiTokenConfiguration.vue'
import CacheManagement from './sections/CacheManagement.vue'
import DeclaredCredentialProviders from './sections/DeclaredCredentialProviders.vue'
import EdepotHostKeyConfiguration from './sections/EdepotHostKeyConfiguration.vue'
import FederatedTrustConfiguration from './sections/FederatedTrustConfiguration.vue'
import FileConfiguration from './sections/FileConfiguration.vue'
import FlowConfiguration from './sections/FlowConfiguration.vue'
import LlmConfiguration from './sections/LlmConfiguration.vue'
import LoadSheddingConfiguration from './sections/LoadSheddingConfiguration.vue'
import LogIntegrity from './sections/LogIntegrity.vue'
import MultitenancyConfiguration from './sections/MultitenancyConfiguration.vue'
import NotificationRuleReach from './sections/NotificationRuleReach.vue'
import OrganisationConfiguration from './sections/OrganisationConfiguration.vue'
import PermissionMatrix from './sections/PermissionMatrix.vue'
import PushNotificationsConfiguration from './sections/PushNotificationsConfiguration.vue'
import RbacConfiguration from './sections/RbacConfiguration.vue'
import ReadEventVisibilityConfiguration from './sections/ReadEventVisibilityConfiguration.vue'
import RegisterDescriptors from './sections/RegisterDescriptors.vue'
import RetentionConfiguration from './sections/RetentionConfiguration.vue'
import SearchConfiguration from './sections/SearchConfiguration.vue'
import StatisticsOverview from './sections/StatisticsOverview.vue'
import TranslationConfiguration from './sections/TranslationConfiguration.vue'
import WorkingCalendars from './sections/WorkingCalendars.vue'
import { useSettingsStore } from '../../store/settings.js'

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
		SearchConfiguration,
		PermissionMatrix,
		OrganisationConfiguration,
		MultitenancyConfiguration,
		FlowConfiguration,
		WorkingCalendars,
		RegisterDescriptors,
		RetentionConfiguration,
		ReadEventVisibilityConfiguration,
		ActionRights,
		LogIntegrity,
		PushNotificationsConfiguration,
		NotificationRuleReach,
		LlmConfiguration,
		TranslationConfiguration,
		LoadSheddingConfiguration,
		FileConfiguration,
		ApiTokenConfiguration,
		EdepotHostKeyConfiguration,
		FederatedTrustConfiguration,
		DeclaredCredentialProviders,
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
	 *
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
