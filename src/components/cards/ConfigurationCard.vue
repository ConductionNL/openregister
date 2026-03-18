<template>
	<CnCard
		:title="cardTitle"
		:description="description"
		:active="isImported"
		:active-variant="isLocalConfiguration ? 'warning' : 'success'">
		<template #icon>
			<CogOutline :size="20" />
		</template>

		<!-- Status Badges -->
		<template #labels>
			<span class="cn-card__labels">
				<template v-if="isImported">
					<template v-if="isLocalConfiguration">
						<CnStatusBadge
							label="Local"
							variant="warning"
							:solid="true">
							<template #icon>
								<CheckCircle :size="16" />
							</template>
						</CnStatusBadge>
						<CnStatusBadge
							v-if="displayConfiguration.app"
							:label="displayConfiguration.app"
							variant="default"
							:solid="true">
							<template #icon>
								<ApplicationCog :size="16" />
							</template>
						</CnStatusBadge>
					</template>
					<CnStatusBadge
						v-else
						label="External"
						variant="success"
						:solid="true">
						<template #icon>
							<Cloud :size="16" />
						</template>
					</CnStatusBadge>
					<CnStatusBadge
						v-if="!isLocalConfiguration && displayConfiguration.syncEnabled"
						:label="getSyncStatusText(displayConfiguration)"
						:variant="syncBadgeVariant"
						:solid="true">
						<template #icon>
							<Sync v-if="displayConfiguration.syncStatus === 'success'" :size="16" />
							<AlertCircle v-else-if="displayConfiguration.syncStatus === 'failed'" :size="16" />
							<ClockOutline v-else :size="16" />
						</template>
					</CnStatusBadge>
					<CnStatusBadge
						v-if="hasUpdateAvailable"
						label="Update Available"
						variant="warning"
						:solid="true">
						<template #icon>
							<Update :size="16" />
						</template>
					</CnStatusBadge>
					<CnStatusBadge
						v-if="isPublished"
						label="Published"
						variant="error"
						:solid="true">
						<template #icon>
							<CloudUploadOutline :size="16" />
						</template>
					</CnStatusBadge>
				</template>
				<template v-else>
					<CnStatusBadge
						v-if="configuration.config?.app"
						:label="configuration.config.app"
						variant="default"
						:solid="true" />
					<CnStatusBadge
						label="Discovered"
						variant="primary"
						:solid="true">
						<template #icon>
							<Magnify :size="16" />
						</template>
					</CnStatusBadge>
				</template>
			</span>
		</template>

		<!-- Actions -->
		<template #actions>
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
					<NcActionButton v-if="isLocalConfiguration && !isPublished" close-after-click @click="handlePublish">
						<template #icon>
							<CloudUploadOutline :size="20" />
						</template>
						Publish
					</NcActionButton>
					<NcActionButton v-if="isPublished" close-after-click @click="handlePublish">
						<template #icon>
							<CloudUploadOutline :size="20" />
						</template>
						Update Published
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
		</template>

		<!-- Meta information footer -->
		<template v-if="hasMetadata" #footer>
			<div class="cn-card__footer">
				<!-- Repository link -->
				<a
					v-if="repositoryFullName"
					:href="repositoryUrl"
					target="_blank"
					rel="noopener noreferrer"
					class="cn-card__footer-link">
					<SourceBranch :size="16" />
					{{ repositoryFullName }}
				</a>

				<!-- Organization link -->
				<a
					v-if="displayConfiguration.organization && displayConfiguration.organization.url"
					:href="displayConfiguration.organization.url"
					target="_blank"
					rel="noopener noreferrer"
					class="cn-card__footer-link">
					<OfficeBuilding :size="16" />
					{{ displayConfiguration.organization.name }}
				</a>

				<!-- Stars count -->
				<span v-if="displayConfiguration.stars" class="configurationCard__meta-item">
					<Star :size="16" />
					{{ displayConfiguration.stars }}
				</span>

				<!-- App ID badge (fallback if no repo info) -->
				<span v-if="displayConfiguration.app && !repositoryFullName" class="configurationCard__meta-item">
					<ApplicationCog :size="16" />
					{{ displayConfiguration.app }}
				</span>

				<!-- Source type (fallback if no repo info) -->
				<span v-if="displayConfiguration.sourceType && !repositoryFullName" class="configurationCard__meta-item">
					<Cloud :size="16" />
					{{ getSourceTypeLabel(displayConfiguration.sourceType) }}
				</span>

				<!-- Version info -->
				<span v-if="displayConfiguration.version || displayConfiguration.localVersion" class="configurationCard__meta-item">
					<Tag :size="16" />
					v{{ displayConfiguration.version || displayConfiguration.localVersion }}
				</span>

				<span v-if="displayConfiguration.remoteVersion && displayConfiguration.remoteVersion !== (displayConfiguration.version || displayConfiguration.localVersion)" class="configurationCard__meta-item">
					<Update :size="16" />
					v{{ displayConfiguration.remoteVersion }} available
				</span>
			</div>
		</template>
	</CnCard>
</template>

<script>
import { NcActions, NcActionButton } from '@nextcloud/vue'
import { CnCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import CloudUpload from 'vue-material-design-icons/CloudUpload.vue'
import CloudUploadOutline from 'vue-material-design-icons/CloudUploadOutline.vue'
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
import Tag from 'vue-material-design-icons/Tag.vue'

import { configurationStore, navigationStore } from '../../store/store.js'

/**
 * Universal Configuration Card Component
 *
 * Works for ALL configuration types:
 * - Local/imported configurations (from ConfigurationsIndex)
 * - Discovered configurations (from ImportConfiguration modal)
 * - Automatically detects if a discovered config is already imported
 *
 * Events emitted by this component:
 * - view: View configuration details
 * - edit: Edit configuration
 * - export: Export configuration
 * - delete: Delete configuration
 * - import: Import discovered configuration
 * - check-version: Check for updates
 * - preview-update: Preview available updates
 */
export default {
	name: 'ConfigurationCard',
	components: {
		CnCard,
		CnStatusBadge,
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
		CloudUploadOutline,
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
		Tag,
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
	data() {
		return {
			// Track if this discovered config is already imported (fetched from backend)
			importedConfigId: null,
			checkingImportStatus: false,
		}
	},
	computed: {
		/**
		 * Card title from either format
		 *
		 * @return {string}
		 */
		cardTitle() {
			return this.configuration.title || this.configuration.config?.title || ''
		},
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
		 * Check if this discovered configuration is already imported
		 * Uses backend-fetched data, not frontend store (which may be paginated)
		 *
		 * @return {boolean}
		 */
		isAlreadyImported() {
			if (!this.isDiscovered) return false
			// Check if we've fetched and found an imported config ID
			return this.importedConfigId !== null
		},
		/**
		 * Get the existing local configuration if it's already imported
		 *
		 * @return {object|null}
		 */
		existingConfiguration() {
			if (!this.importedConfigId) return null

			// Try to find in store first (fast)
			const found = configurationStore.configurationList.find(
				config => config.id === this.importedConfigId,
			)

			if (found) return found

			// Not in store, create a minimal config object for display
			// with properties needed for version checking
			return {
				id: this.importedConfigId,
				app: this.appId,
				title: this.configuration.config?.title || this.appId,
				description: this.configuration.config?.description || '',
				sourceType: 'github', // Assume github for discovered configs
				isLocal: false,
				// Version info from discovered config
				localVersion: this.configuration.config?.version || null,
				remoteVersion: null, // Will be fetched on check
				// GitHub sync info
				githubRepo: this.configuration.repository,
				githubBranch: this.configuration.branch || 'main',
				githubPath: this.configuration.path,
				syncEnabled: true,
			}
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
					repository: this.configuration.repository, // Full repo name (owner/repo)
					organization: this.configuration.organization,
					stars: this.configuration.stars,
					url: this.configuration.url,
					owner: this.configuration.owner, // Repository owner
					repo: this.configuration.repo, // Repository name
					// Ensure isLocal and sourceType are preserved
					isLocal: this.existingConfiguration.isLocal ?? this.configuration.isLocal,
					sourceType: this.existingConfiguration.sourceType ?? this.configuration.sourceType,
				}
			}
			// For imported configurations, ensure we have the properties
			const config = this.configuration || {}
			return {
				...config,
				// Ensure isLocal and sourceType are explicitly included
				isLocal: config.isLocal,
				sourceType: config.sourceType,
			}
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
		 * Check if configuration is local
		 *
		 * @return {boolean}
		 */
		isLocalConfiguration() {
			// Check both displayConfiguration and original configuration prop
			const displayConfig = this.displayConfiguration
			const originalConfig = this.configuration

			// Check isLocal property from either source (boolean true or string 'true')
			const isLocal = displayConfig.isLocal ?? originalConfig.isLocal
			if (isLocal === true || isLocal === 'true') {
				return true
			}

			// Fallback: check sourceType from either source
			const sourceType = displayConfig.sourceType ?? originalConfig.sourceType
			return sourceType === 'local' || sourceType === 'manual'
		},
		/**
		 * Check if configuration is external (not local)
		 *
		 * @return {boolean}
		 */
		isExternal() {
			return !this.isImported || !this.isLocalConfiguration
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
			const currentVersion = config.version || config.localVersion
			if (!this.isImported || !currentVersion || !config.remoteVersion) {
				return false
			}
			return config.remoteVersion !== currentVersion
		},
		/**
		 * Check if configuration is published (local and has GitHub repo info)
		 *
		 * @return {boolean}
		 */
		isPublished() {
			const config = this.displayConfiguration
			// Published if: isLocal=true AND has githubRepo (or sourceType is github/gitlab with repo info)
			return this.isLocalConfiguration && (config.githubRepo || (config.sourceType === 'github' && config.githubRepo))
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
		/**
		 * Get the sync badge variant based on sync status
		 *
		 * @return {string}
		 */
		syncBadgeVariant() {
			const status = this.displayConfiguration.syncStatus
			if (status === 'success') return 'success'
			if (status === 'failed') return 'error'
			return 'default'
		},
		/**
		 * Check if configuration has any metadata to display in footer
		 *
		 * @return {boolean}
		 */
		hasMetadata() {
			const config = this.displayConfiguration
			return !!(
				this.repositoryFullName
				|| config.organization
				|| config.stars
				|| config.app
				|| config.sourceType
				|| config.localVersion
				|| config.remoteVersion
			)
		},
		/**
		 * Get repository full name (owner/repo)
		 *
		 * @return {string|null}
		 */
		repositoryFullName() {
			const config = this.displayConfiguration

			// From discovered configs
			if (config.repository) {
				return config.repository
			}

			// From imported configs with github info
			if (config.githubRepo) {
				return config.githubRepo
			}

			// Try to extract from sourceUrl
			if (config.sourceUrl) {
				const githubMatch = config.sourceUrl.match(/github\.com\/([^/]+\/[^/]+)/)
				if (githubMatch) {
					return githubMatch[1].replace(/\/blob\/.*$/, '')
				}
			}

			return null
		},
		/**
		 * Get repository URL
		 *
		 * @return {string|null}
		 */
		repositoryUrl() {
			if (!this.repositoryFullName) return null

			const config = this.displayConfiguration

			// Check if it's a GitLab repo
			if (config.sourceType === 'gitlab' || config.sourceUrl?.includes('gitlab')) {
				return `https://gitlab.com/${this.repositoryFullName}`
			}

			// Default to GitHub
			return `https://github.com/${this.repositoryFullName}`
		},
	},
	watch: {
		'configuration.config.app'() {
			// Re-check if app ID changes
			if (this.isDiscovered && this.appId) {
				this.checkIfImported()
			}
		},
	},
	mounted() {
		// For discovered configs, check backend to see if already imported
		if (this.isDiscovered && this.appId) {
			this.checkIfImported()
		}
	},
	methods: {
		/**
		 * Check if this discovered configuration is already imported in the backend
		 * Makes an API call to check by appId and stores the full config
		 */
		async checkIfImported() {
			if (!this.appId || this.checkingImportStatus) return

			this.checkingImportStatus = true

			try {
				const response = await fetch(
					`/index.php/apps/openregister/api/configurations?app=${encodeURIComponent(this.appId)}`,
					{
						method: 'GET',
						headers: {
							'Content-Type': 'application/json',
							Accept: 'application/json',
						},
					},
				)

				if (response.ok) {
					const data = await response.json()

					// Check if we got results
					if (data.results && data.results.length > 0) {
						const importedConfig = data.results[0]

						// Configuration exists! Store the ID and add to store if not present
						this.importedConfigId = importedConfig.id

						// Add to store if not already there (for pagination support)
						const existsInStore = configurationStore.configurationList.find(
							c => c.id === importedConfig.id,
						)
						if (!existsInStore) {
							configurationStore.configurationList.push(importedConfig)
						}
					} else {
						// Not imported
						this.importedConfigId = null
					}
				}
			} catch (error) {
				// On error, assume not imported
				this.importedConfigId = null
			} finally {
				this.checkingImportStatus = false
			}
		},
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
			if (this.isDiscovered && this.existingConfiguration) {
				// For discovered configs that are imported, open the local one
				configurationStore.setConfigurationItem(this.existingConfiguration)
				navigationStore.setModal('viewConfiguration')
			} else {
				this.$emit('view', this.existingConfiguration || this.displayConfiguration)
			}
		},
		/**
		 * Handle edit action
		 */
		handleEdit() {
			if (this.isDiscovered && this.existingConfiguration) {
				configurationStore.setConfigurationItem(this.existingConfiguration)
				navigationStore.setModal('editConfiguration')
			} else {
				this.$emit('edit', this.existingConfiguration || this.displayConfiguration)
			}
		},
		/**
		 * Handle export action
		 */
		handleExport() {
			if (this.isDiscovered && this.existingConfiguration) {
				configurationStore.setConfigurationItem(this.existingConfiguration)
				navigationStore.setModal('exportConfiguration')
			} else {
				this.$emit('export', this.existingConfiguration || this.displayConfiguration)
			}
		},
		/**
		 * Handle publish action
		 */
		handlePublish() {
			const config = this.existingConfiguration || this.displayConfiguration
			configurationStore.setConfigurationItem(config)
			navigationStore.setModal('publishConfiguration')
		},
		/**
		 * Handle delete action
		 */
		handleDelete() {
			if (this.isDiscovered && this.existingConfiguration) {
				configurationStore.setConfigurationItem(this.existingConfiguration)
				navigationStore.setDialog('deleteConfiguration')
			} else {
				this.$emit('delete', this.existingConfiguration || this.displayConfiguration)
			}
		},
		/**
		 * Handle check version action
		 */
		handleCheckVersion() {
			this.$emit('check-version', this.existingConfiguration || this.displayConfiguration)
		},
		/**
		 * Handle preview update action
		 */
		handlePreviewUpdate() {
			if (this.isDiscovered && this.existingConfiguration) {
				configurationStore.setConfigurationItem(this.existingConfiguration)
				navigationStore.setModal('previewConfiguration')
			} else {
				this.$emit('preview-update', this.existingConfiguration || this.displayConfiguration)
			}
		},
	},
}
</script>

<style scoped>
.configurationCard__meta-item {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
</style>
