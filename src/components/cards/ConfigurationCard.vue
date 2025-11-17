<template>
	<div class="configurationCard" :class="{ 'configurationCard--imported': isImported, 'configurationCard--external': isExternal }">
		<div class="cardHeader">
			<h2 v-tooltip.bottom="configuration.description || configuration.config?.description">
				<CogOutline :size="20" />
				{{ configuration.title || configuration.config?.title }}
				<!-- Status Badges -->
				<template v-if="isImported">
					<template v-if="configuration.isLocal">
						<span class="configBadge configBadge--local">
							<CheckCircle :size="16" />
							Local
						</span>
						<span v-if="configuration.app" class="configBadge configBadge--app">
							<ApplicationCog :size="16" />
							{{ configuration.app }}
						</span>
					</template>
					<span v-else class="configBadge configBadge--external">
						<Cloud :size="16" />
						External
					</span>
					<span v-if="!configuration.isLocal && configuration.syncEnabled" class="configBadge" :class="'configBadge--sync-' + configuration.syncStatus">
						<Sync v-if="configuration.syncStatus === 'success'" :size="16" />
						<AlertCircle v-else-if="configuration.syncStatus === 'failed'" :size="16" />
						<ClockOutline v-else :size="16" />
						{{ getSyncStatusText(configuration) }}
					</span>
					<span v-if="hasUpdateAvailable" class="configBadge configBadge--update">
						<Update :size="16" />
						Update Available
					</span>
				</template>
				<template v-else>
					<!-- For discovered/external configurations -->
					<span v-if="configuration.config?.app" class="configBadge configBadge--app">
						{{ configuration.config.app }}
					</span>
					<span class="configBadge configBadge--discovered">
						<Magnify :size="16" />
						Discovered
					</span>
				</template>
			</h2>
			<NcActions :primary="true" menu-name="Actions">
				<template #icon>
					<DotsHorizontal :size="20" />
				</template>
				<!-- Actions for imported configurations -->
				<template v-if="isImported">
					<NcActionButton close-after-click @click="handleView">
						<template #icon>
							<Eye :size="20" />
						</template>
						View
					</NcActionButton>
					<NcActionButton v-if="hasUpdateAvailable" close-after-click @click="handlePreviewUpdate">
						<template #icon>
							<EyeOutline :size="20" />
						</template>
						Preview Update
					</NcActionButton>
					<NcActionButton v-if="isRemoteConfiguration" close-after-click @click="handleCheckVersion">
						<template #icon>
							<Sync :size="20" />
						</template>
						Check Version
					</NcActionButton>
					<NcActionButton close-after-click @click="handleEdit">
						<template #icon>
							<Pencil :size="20" />
						</template>
						Edit
					</NcActionButton>
					<NcActionButton close-after-click @click="handleExport">
						<template #icon>
							<Download :size="20" />
						</template>
						Export
					</NcActionButton>
					<NcActionButton close-after-click @click="handleDelete">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						Delete
					</NcActionButton>
				</template>
				<!-- Actions for discovered/external configurations -->
				<template v-else>
					<NcActionButton type="primary" close-after-click @click="$emit('import', configuration)">
						<template #icon>
							<CloudUpload :size="20" />
						</template>
						Import
					</NcActionButton>
					<NcActionButton v-if="viewSourceUrl" close-after-click @click="openInNewTab(viewSourceUrl)">
						<template #icon>
							<OpenInNew :size="20" />
						</template>
						View Source
					</NcActionButton>
				</template>
			</NcActions>
		</div>
		
		<!-- Configuration Details -->
		<div class="configurationDetails">
			<p v-if="description" class="configurationDescription">
				{{ description }}
			</p>
			
			<!-- Meta information for discovered configs -->
			<div v-if="!isImported && (configuration.repository || configuration.organization)" class="cardMeta">
				<a
					v-if="configuration.repository"
					:href="`https://github.com/${configuration.repository}`"
					target="_blank"
					rel="noopener noreferrer"
					class="metaItem metaLink">
					<SourceBranch :size="16" />
					{{ configuration.repository }}
				</a>
				<a
					v-if="configuration.organization && configuration.organization.url"
					:href="configuration.organization.url"
					target="_blank"
					rel="noopener noreferrer"
					class="metaItem metaLink">
					<OfficeBuilding :size="16" />
					{{ configuration.organization.name }}
				</a>
				<span v-if="configuration.stars" class="metaItem">
					<Star :size="16" />
					{{ configuration.stars }}
				</span>
			</div>
			
			<!-- Configuration info for imported configs -->
			<div v-if="isImported" class="configurationInfo">
				<div class="configurationInfoItem">
					<strong>Source:</strong>
					<span>{{ getSourceTypeLabel(configuration.sourceType) }}</span>
				</div>
				<div v-if="configuration.sourceUrl" class="configurationInfoItem">
					<strong>URL:</strong>
					<span class="urlText">{{ configuration.sourceUrl }}</span>
				</div>
				<div v-if="configuration.localVersion" class="configurationInfoItem">
					<strong>Local Version:</strong>
					<span>{{ configuration.localVersion }}</span>
				</div>
				<div v-if="configuration.remoteVersion" class="configurationInfoItem">
					<strong>Remote Version:</strong>
					<span>{{ configuration.remoteVersion }}</span>
				</div>
				<div v-if="configuration.autoUpdate" class="configurationInfoItem">
					<strong>Auto-Update:</strong>
					<span class="badge-success">Enabled</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcActions, NcActionButton } from '@nextcloud/vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import CloudUpload from 'vue-material-design-icons/CloudUpload.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Update from 'vue-material-design-icons/Update.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Cloud from 'vue-material-design-icons/Cloud.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import ApplicationCog from 'vue-material-design-icons/ApplicationCog.vue'
import SourceBranch from 'vue-material-design-icons/SourceBranch.vue'
import Star from 'vue-material-design-icons/Star.vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'

import { configurationStore, navigationStore } from '../../store/store.js'

/**
 * Universal Configuration Card Component
 * 
 * Works for ALL configuration types:
 * - Local/imported configurations (from ConfigurationsIndex)
 * - Discovered configurations (from ImportConfiguration modal)
 * - Automatically detects if a discovered config is already imported
 * 
 * @emits view - View configuration details
 * @emits edit - Edit configuration
 * @emits export - Export configuration
 * @emits delete - Delete configuration
 * @emits import - Import discovered configuration
 * @emits check-version - Check for updates
 * @emits preview-update - Preview available updates
 */
export default {
	name: 'ConfigurationCard',
	components: {
		NcActions,
		NcActionButton,
		CogOutline,
		DotsHorizontal,
		Eye,
		EyeOutline,
		Pencil,
		TrashCanOutline,
		Download,
		CloudUpload,
		OpenInNew,
		Update,
		Sync,
		CheckCircle,
		Cloud,
		AlertCircle,
		ClockOutline,
		ApplicationCog,
		SourceBranch,
		Star,
		OfficeBuilding,
		Magnify,
	},
	props: {
		/**
		 * Configuration object
		 * Can be either an imported configuration or a discovered configuration
		 */
		configuration: {
			type: Object,
			required: true,
		},
	},
	emits: ['view', 'edit', 'export', 'delete', 'import', 'check-version', 'preview-update'],
	computed: {
		/**
		 * Check if this is a discovered configuration (has config.app structure)
		 *
		 * @return {boolean}
		 */
		isDiscovered() {
			return !!this.configuration.config?.app
		},
		/**
		 * Get the app ID from configuration
		 *
		 * @return {string|null}
		 */
		appId() {
			return this.configuration.app || this.configuration.config?.app || null
		},
		/**
		 * Check if this discovered configuration is already imported locally
		 *
		 * @return {boolean}
		 */
		isAlreadyImported() {
			if (!this.isDiscovered) return false
			if (!this.appId) return false

			return !!configurationStore.configurationList.find(
				config => config.app === this.appId
			)
		},
		/**
		 * Get the existing local configuration if it's already imported
		 *
		 * @return {object|null}
		 */
		existingConfiguration() {
			if (!this.appId) return null

			return configurationStore.configurationList.find(
				config => config.app === this.appId
			) || null
		},
		/**
		 * Get the configuration to display
		 * If discovered and already imported, merge with local config
		 *
		 * @return {object}
		 */
		displayConfiguration() {
			if (this.isDiscovered && this.existingConfiguration) {
				// Merge discovered metadata with local configuration
				return {
					...this.existingConfiguration,
					organization: this.configuration.organization,
					stars: this.configuration.stars,
					url: this.configuration.url,
				}
			}
			return this.configuration
		},
		/**
		 * Check if this is an imported configuration
		 *
		 * @return {boolean}
		 */
		isImported() {
			// If it's a discovered config, check if it exists locally
			if (this.isDiscovered) {
				return this.isAlreadyImported
			}
			// Otherwise, it's imported if it has an ID (local database entity)
			return !!this.configuration.id
		},
		/**
		 * Get description from either format
		 *
		 * @return {string}
		 */
		description() {
			const config = this.displayConfiguration
			return config.description || config.config?.description || ''
		},
		/**
		 * Check if configuration is external (not local)
		 *
		 * @return {boolean}
		 */
		isExternal() {
			return !this.isImported || (this.displayConfiguration.sourceType && this.displayConfiguration.sourceType !== 'local')
		},
		/**
		 * Check if configuration is remote
		 *
		 * @return {boolean}
		 */
		isRemoteConfiguration() {
			return this.isImported && this.displayConfiguration.sourceType && this.displayConfiguration.sourceType !== 'local'
		},
		/**
		 * Check if update is available
		 *
		 * @return {boolean}
		 */
		hasUpdateAvailable() {
			const config = this.displayConfiguration
			if (!this.isImported || !config.localVersion || !config.remoteVersion) {
				return false
			}
			return config.remoteVersion !== config.localVersion
		},
		/**
		 * Get view source URL
		 *
		 * @return {string|null}
		 */
		viewSourceUrl() {
			const config = this.displayConfiguration
			return config.url || config.sourceUrl || null
		},
	},
	methods: {
		/**
		 * Get source type label
		 *
		 * @param {string} sourceType Source type
		 * @return {string}
		 */
		getSourceTypeLabel(sourceType) {
			const labels = {
				local: 'Local',
				github: 'GitHub',
				gitlab: 'GitLab',
				url: 'URL',
			}
			return labels[sourceType] || 'Unknown'
		},
		/**
		 * Get sync status text
		 *
		 * @param {object} configuration Configuration object
		 * @return {string}
		 */
		getSyncStatusText(configuration) {
			if (configuration.syncStatus === 'success' && configuration.lastSyncDate) {
				const now = new Date()
				const lastSync = new Date(configuration.lastSyncDate)
				const diffInHours = Math.floor((now - lastSync) / (1000 * 60 * 60))
				
				if (diffInHours < 1) {
					return 'Synced just now'
				} else if (diffInHours < 24) {
					return `Synced ${diffInHours}h ago`
				} else {
					const diffInDays = Math.floor(diffInHours / 24)
					return `Synced ${diffInDays}d ago`
				}
			} else if (configuration.syncStatus === 'failed') {
				return 'Sync failed'
			} else if (configuration.syncStatus === 'pending') {
				return 'Sync pending'
			} else {
				return 'Never synced'
			}
		},
		/**
		 * Open URL in new tab
		 *
		 * @param {string} url URL to open
		 */
		openInNewTab(url) {
			window.open(url, '_blank')
		},
		/**
		 * Handle view action
		 */
		handleView() {
			const config = this.existingConfiguration || this.displayConfiguration
			if (this.isDiscovered && this.existingConfiguration) {
				// For discovered configs that are imported, open the local one
				configurationStore.setConfigurationItem(this.existingConfiguration)
				navigationStore.setModal('viewConfiguration')
			} else {
				this.$emit('view', config)
			}
		},
		/**
		 * Handle edit action
		 */
		handleEdit() {
			const config = this.existingConfiguration || this.displayConfiguration
			if (this.isDiscovered && this.existingConfiguration) {
				configurationStore.setConfigurationItem(this.existingConfiguration)
				navigationStore.setModal('editConfiguration')
			} else {
				this.$emit('edit', config)
			}
		},
		/**
		 * Handle export action
		 */
		handleExport() {
			const config = this.existingConfiguration || this.displayConfiguration
			if (this.isDiscovered && this.existingConfiguration) {
				configurationStore.setConfigurationItem(this.existingConfiguration)
				navigationStore.setModal('exportConfiguration')
			} else {
				this.$emit('export', config)
			}
		},
		/**
		 * Handle delete action
		 */
		handleDelete() {
			const config = this.existingConfiguration || this.displayConfiguration
			if (this.isDiscovered && this.existingConfiguration) {
				configurationStore.setConfigurationItem(this.existingConfiguration)
				navigationStore.setDialog('deleteConfiguration')
			} else {
				this.$emit('delete', config)
			}
		},
		/**
		 * Handle check version action
		 */
		handleCheckVersion() {
			const config = this.existingConfiguration || this.displayConfiguration
			this.$emit('check-version', config)
		},
		/**
		 * Handle preview update action
		 */
		handlePreviewUpdate() {
			const config = this.existingConfiguration || this.displayConfiguration
			if (this.isDiscovered && this.existingConfiguration) {
				configurationStore.setConfigurationItem(this.existingConfiguration)
				navigationStore.setModal('previewConfiguration')
			} else {
				this.$emit('preview-update', config)
			}
		},
	},
}
</script>

<style scoped>
.configurationCard {
	border: 2px solid var(--color-border);
	border-radius: 8px;
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	transition: all 0.2s;
	background-color: var(--color-main-background);
}

.configurationCard:hover {
	border-color: var(--color-primary);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.configurationCard--imported {
	border-color: var(--color-success);
	background-color: rgba(var(--color-success-rgb), 0.05);
}

.configurationCard--imported:hover {
	border-color: var(--color-success);
	box-shadow: 0 2px 8px rgba(var(--color-success-rgb), 0.2);
}

.configurationCard--external {
	border-color: var(--color-primary-element-light);
}

.cardHeader {
	display: flex;
	align-items: center;
	gap: 12px;
	justify-content: space-between;
}

.cardHeader h2 {
	margin: 0;
	font-size: 1.1em;
	flex: 1;
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.configurationDetails {
	margin-top: 0.5rem;
}

.configurationDescription {
	color: var(--color-text-lighter);
	font-size: 0.9em;
	margin: 0 0 1rem 0;
	line-height: 1.5;
}

.cardMeta {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.metaItem {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.metaLink {
	color: var(--color-primary);
	text-decoration: none;
	transition: all 0.2s;
}

.metaLink:hover {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.configurationInfo {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.configurationInfoItem {
	display: flex;
	gap: 0.5rem;
}

.configurationInfoItem strong {
	min-width: 120px;
}

.urlText {
	font-family: monospace;
	font-size: 0.9em;
	color: var(--color-primary);
	word-break: break-all;
}

.badge-success {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	background-color: var(--color-success);
	color: white;
	font-size: 0.85em;
	font-weight: 500;
}

/* Configuration Badges */
.configBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.75em;
	font-weight: 600;
	vertical-align: middle;
}

/* Local configuration badge (green/success) */
.configBadge--local {
	background-color: var(--color-success-light);
	color: var(--color-success-dark);
}

/* External configuration badge (blue/primary) */
.configBadge--external {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
}

/* App source badge (gray/secondary) */
.configBadge--app {
	background-color: var(--color-background-dark);
	color: var(--color-text-lighter);
	text-transform: uppercase;
}

/* Discovered badge */
.configBadge--discovered {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
}

/* Sync status badges */
.configBadge--sync-success {
	background-color: var(--color-success-light);
	color: var(--color-success-dark);
}

.configBadge--sync-failed {
	background-color: var(--color-error-light);
	color: var(--color-error-dark);
}

.configBadge--sync-pending,
.configBadge--sync-never {
	background-color: var(--color-background-dark);
	color: var(--color-text-lighter);
}

/* Update available badge */
.configBadge--update {
	background-color: var(--color-warning);
	color: var(--color-main-text);
}
</style>

