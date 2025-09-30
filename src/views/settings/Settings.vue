<template>
	<div id="openregister_settings" class="section">
		<h2>{{ t('openregister', 'OpenRegister Settings') }}</h2>
		
		<!-- Version Information Section -->
		<NcSettingsSection name="Version Information"
			description="Information about the current OpenRegister installation">
			<div v-if="!settingsStore.loadingVersionInfo" class="version-info">
				<div class="version-card">
					<h4>📦 Application Information</h4>
					<div class="version-details">
						<div class="version-item">
							<span class="version-label">Application Name:</span>
							<span class="version-value">{{ settingsStore.versionInfo.appName }}</span>
						</div>
						<div class="version-item">
							<span class="version-label">Version:</span>
							<span class="version-value">{{ settingsStore.versionInfo.appVersion }}</span>
						</div>
					</div>
				</div>
			</div>
			<NcLoadingIcon v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>


		<!-- System Statistics Section -->
		<StatisticsOverview />

		<!-- Cache Management Section -->
		<CacheManagement />

		<!-- RBAC Configuration Section -->
		<RbacConfiguration />

		<!-- Multitenancy Configuration Section -->
		<MultitenancyConfiguration />

		<!-- Retention Configuration Section -->
		<RetentionConfiguration />

		<!-- SOLR Configuration Section -->
		<SolrConfiguration />

		<!-- AI Configuration Section -->
		<AiConfiguration />
	</div>
</template>

<script>
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../store/settings.js'

import { NcSettingsSection, NcLoadingIcon } from '@nextcloud/vue'

import SolrConfiguration from './sections/SolrConfiguration.vue'
import StatisticsOverview from './sections/StatisticsOverview.vue'
import CacheManagement from './sections/CacheManagement.vue'
import RbacConfiguration from './sections/RbacConfiguration.vue'
import MultitenancyConfiguration from './sections/MultitenancyConfiguration.vue'
import RetentionConfiguration from './sections/RetentionConfiguration.vue'
import AiConfiguration from './sections/AiConfiguration.vue'

/**
 * @class Settings
 * @module Components
 * @package OpenRegister
 * 
 * Main settings component that orchestrates all settings sections using Pinia store.
 * This component serves as a container and delegates all data management to the settings store.
 * 
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.nl
 */
export default {
	name: 'Settings',
	
	components: {
		NcSettingsSection,
		NcLoadingIcon,
		SolrConfiguration,
		StatisticsOverview,
		CacheManagement,
		RbacConfiguration,
		MultitenancyConfiguration,
		RetentionConfiguration,
		AiConfiguration,
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
#openregister_settings {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
}

.version-info {
	margin-top: 20px;
}

.version-card {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 20px;
	margin-bottom: 20px;
}

.version-card h4 {
	color: var(--color-text-light);
	margin: 0 0 16px 0;
	font-size: 16px;
}

.version-details {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.version-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.version-item:last-child {
	border-bottom: none;
}

.version-label {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.version-value {
	color: var(--color-text-light);
	font-family: monospace;
	font-size: 14px;
}

.loading-icon {
	margin: 40px auto;
	display: block;
}

@media (max-width: 768px) {
	#openregister_settings {
		padding: 10px;
	}
	
	.version-item {
		flex-direction: column;
		align-items: flex-start;
		gap: 4px;
	}
}
</style>
