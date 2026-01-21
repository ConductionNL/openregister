<template>
	<div>
		<!-- Page Title with Documentation Link -->
		<NcSettingsSection
			name="OpenRegister Settings"
			description="Configure your OpenRegister installation"
			doc-url="https://docs.openregister.nl" />

		<!-- Version Information Section -->
		<VersionInfoCard
			:app-name="settingsStore.versionInfo.appName || 'Open Register'"
			:app-version="settingsStore.versionInfo.appVersion || 'Unknown'"
			:loading="settingsStore.loadingVersionInfo"
			:is-up-to-date="true"
			:show-update-button="true"
			title="Version Information"
			description="Information about the current OpenRegister installation">
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
		</VersionInfoCard>

		<!-- System Statistics Section -->
		<StatisticsOverview />

		<!-- Cache Management Section -->
		<CacheManagement />

		<!-- RBAC Configuration Section -->
		<RbacConfiguration />

		<!-- Organisation Configuration Section -->
		<OrganisationConfiguration />

		<!-- Multitenancy Configuration Section -->
		<MultitenancyConfiguration />

		<!-- Retention Configuration Section -->
		<RetentionConfiguration />

	<!-- SOLR Configuration Section -->
	<SolrConfiguration />

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
	</div>
</template>

<script>
/* eslint-disable no-console */
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../store/settings.js'

import { NcSettingsSection, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import VersionInfoCard from '../../components/shared/VersionInfoCard.vue'
import SolrConfiguration from './sections/SolrConfiguration.vue'
import StatisticsOverview from './sections/StatisticsOverview.vue'
import CacheManagement from './sections/CacheManagement.vue'
import RbacConfiguration from './sections/RbacConfiguration.vue'
import OrganisationConfiguration from './sections/OrganisationConfiguration.vue'
import MultitenancyConfiguration from './sections/MultitenancyConfiguration.vue'
import RetentionConfiguration from './sections/RetentionConfiguration.vue'
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
		NcSettingsSection,
		NcButton,
		NcLoadingIcon,
		Refresh,
		VersionInfoCard,
		SolrConfiguration,
		StatisticsOverview,
		CacheManagement,
		RbacConfiguration,
		OrganisationConfiguration,
		MultitenancyConfiguration,
		RetentionConfiguration,
		N8nConfiguration,
		LlmConfiguration,
		FileConfiguration,
		ApiTokenConfiguration,
		Dialogs,
	},

	computed: {
		...mapStores(useSettingsStore),
	},

	/**
	 * Component created lifecycle hook
	 * Initializes the settings store and loads all data
	 */
	async created() {
		console.log('🔧 Settings component created - loading data from store')

		try {
			// Load all settings data through the store
			await this.settingsStore.loadSettings()

			// Load additional data that might be needed
			await Promise.allSettled([
				this.settingsStore.loadStats(),
				this.settingsStore.loadCacheStats(),
			])

			console.log('✅ Settings data loaded successfully')
		} catch (error) {
			console.error('❌ Failed to load settings data:', error)
		}
	},
}
</script>

<style scoped>
/* Minimal styling - let Nextcloud handle the layout */
</style>
