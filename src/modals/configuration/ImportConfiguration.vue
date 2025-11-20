<script setup>
import { configurationStore, navigationStore, registerStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'importConfiguration'"
		name="importConfiguration"
		title="Import Configuration"
		size="large"
		:can-close="!loading"
		@update:open="closeModal">
		<NcNoteCard v-if="success" type="success">
			<p>{{ successMessage }}</p>
		</NcNoteCard>

		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div class="tabContainer">
			<BTabs v-model="activeTab" content-class="mt-3" justified>
				<!-- Discover Tab -->
				<BTab active>
					<template #title>
						<Magnify :size="16" />
						<span>Discover</span>
					</template>
					<div class="tabContent">
						<p class="tabDescription">
							Search GitHub and GitLab for OpenRegister configurations created by the community.
						</p>

						<!-- Token Warning -->
						<NcNoteCard v-if="!hasGithubToken || !hasGitlabToken" type="warning">
							<p>
								<strong>{{ getTokenWarningTitle() }}</strong><br>
								{{ getTokenWarningMessage() }}
							</p>
							<p style="margin-top: 8px;">
								<a :href="settingsUrl" target="_blank" style="color: var(--color-primary-element); font-weight: 600;">
									→ Configure API Tokens in Settings
								</a>
							</p>
						</NcNoteCard>

						<div class="searchContainer">
							<NcTextField
								:value.sync="searchQuery"
								label="Search configurations"
								placeholder="Enter search terms or leave empty to browse all"
								@keyup.enter="searchConfigurations">
								<Magnify :size="20" />
							</NcTextField>

							<NcButton
								class="github-button"
								:disabled="!hasGithubToken || searchLoading"
								@click="searchSource = 'github'; searchConfigurations()">
								<template #icon>
									<Github :size="20" />
								</template>
								{{ t('openregister', 'Search GitHub') }}
							</NcButton>

							<NcButton
								class="gitlab-button"
								:disabled="!hasGitlabToken || searchLoading"
								@click="searchSource = 'gitlab'; searchConfigurations()">
								<template #icon>
									<Gitlab :size="20" />
								</template>
								{{ t('openregister', 'Search GitLab') }}
							</NcButton>
						</div>

						<!-- Individual Token Warnings -->
						<div v-if="!hasGithubToken || !hasGitlabToken" class="token-warnings">
							<div v-if="!hasGithubToken" class="token-warning-item">
								<Github :size="16" />
								<span>GitHub token not configured - Search disabled</span>
							</div>
							<div v-if="!hasGitlabToken" class="token-warning-item">
								<Gitlab :size="16" />
								<span>GitLab token not configured - Search disabled</span>
							</div>
						</div>

						<NcLoadingIcon v-if="searchLoading" :size="64" />

						<!-- Search Error -->
						<NcNoteCard v-else-if="searchError" type="error">
							<p><strong>Search Failed</strong></p>
							<p>{{ searchError }}</p>
						</NcNoteCard>

						<div v-else-if="searchResults.length > 0" class="resultsGrid">
							<ConfigurationCard
								v-for="(result, index) in searchResults"
								:key="index"
								:configuration="result"
								@import="importDiscoveredConfiguration(result)"
								@check-version="handleCheckVersion" />
						</div>

						<NcEmptyContent v-else-if="hasSearched"
							name="No configurations found"
							description="Try adjusting your search terms or browse a different source.">
							<template #icon>
								<Magnify :size="64" />
							</template>
						</NcEmptyContent>
					</div>
				</BTab>

				<!-- GitHub/GitLab Tab -->
				<BTab>
					<template #title>
						<Github :size="16" />
						<span>GitHub / GitLab</span>
					</template>
					<div class="tabContent">
						<p class="tabDescription">
							Import a configuration from a specific GitHub or GitLab repository and branch.
						</p>

						<NcSelect
							v-model="repoSource"
							:options="['GitHub', 'GitLab']"
							input-label="Source Platform" />

						<NcTextField
							v-if="repoSource === 'GitHub'"
							:value.sync="repoOwner"
							label="Repository Owner"
							placeholder="e.g., ConductionNL" />

						<NcTextField
							v-if="repoSource === 'GitLab'"
							:value.sync="repoNamespace"
							label="Namespace"
							placeholder="e.g., conduction" />

						<NcTextField
							:value.sync="repoName"
							:label="repoSource === 'GitHub' ? 'Repository Name' : 'Project Name'"
							placeholder="e.g., openregister" />

						<NcButton
							:disabled="!canFetchBranches"
							@click="fetchBranches">
							<template #icon>
								<SourceBranch :size="20" />
							</template>
							Load Branches
						</NcButton>

						<NcSelect
							v-if="branches.length > 0"
							v-model="selectedBranch"
							:options="branches"
							input-label="Branch"
							@change="fetchConfigurationFiles" />

						<div v-if="configFiles.length > 0" class="filesGrid">
							<div
								v-for="file in configFiles"
								:key="file.path"
								class="fileCard"
								:class="{ selected: selectedFile === file }"
								@click="selectedFile = file">
								<h4>{{ file.config.title }}</h4>
								<p class="fileDescription">
									{{ file.config.description || 'No description' }}
								</p>
								<span class="filePath">{{ file.path }}</span>
								<span class="fileVersion">v{{ file.config.version }}</span>
							</div>
						</div>
					</div>
				</BTab>

				<!-- URL Tab -->
				<BTab>
					<template #title>
						<LinkVariant :size="16" />
						<span>Import from URL</span>
					</template>
					<div class="tabContent">
						<p class="tabDescription">
							Import a configuration from a direct URL. The URL must point to a valid OpenRegister JSON file.
						</p>

						<NcTextField
							:value.sync="importUrl"
							label="Configuration URL"
							placeholder="https://example.com/config.json"
							@input="urlError = null" />

						<NcNoteCard v-if="urlError" type="warning">
							<p>{{ urlError }}</p>
						</NcNoteCard>
					</div>
				</BTab>
			</BTabs>
		</div>

		<!-- Synchronization Settings - Always Visible -->
		<div v-if="!success" class="syncSettings">
			<h4>{{ t('openregister', 'Synchronization Settings') }}</h4>
			<NcCheckboxRadioSwitch
				:checked="syncEnabled"
				type="switch"
				@update:checked="syncEnabled = $event">
				{{ t('openregister', 'Enable automatic synchronization') }}
			</NcCheckboxRadioSwitch>

			<NcTextField
				v-if="syncEnabled"
				:value.sync="syncInterval"
				type="number"
				label="Sync Interval (hours)"
				placeholder="24"
				:min="1"
				:max="168">
				<template #helper>
					{{ t('openregister', 'How often to check for updates (1-168 hours)') }}
				</template>
			</NcTextField>
		</div>

		<template #actions>
			<NcButton :disabled="loading" @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ t('openregister', 'Cancel') }}
			</NcButton>
			<NcButton
				:disabled="loading || !canImport"
				type="primary"
				@click="performImport">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Import v-else :size="20" />
				</template>
				{{ t('openregister', 'Import') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcCheckboxRadioSwitch,
	NcTextField,
	NcSelect,
	NcEmptyContent,
} from '@nextcloud/vue'

import { BTabs, BTab } from 'bootstrap-vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Import from 'vue-material-design-icons/Import.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Github from 'vue-material-design-icons/Github.vue'
import Gitlab from 'vue-material-design-icons/Gitlab.vue'
import SourceBranch from 'vue-material-design-icons/SourceBranch.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'

import ConfigurationCard from '../../components/cards/ConfigurationCard.vue'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'ImportConfiguration',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		BTabs,
		BTab,
		NcTextField,
		NcSelect,
		NcEmptyContent,
		ConfigurationCard,
		// Icons
		Cancel,
		Import,
		Magnify,
		Github,
		Gitlab,
		SourceBranch,
		LinkVariant,
	},
	data() {
		return {
			activeTab: 0,
			loading: false,
			success: false,
			successMessage: '',
			error: null,

			// Token availability
			hasGithubToken: false,
			hasGitlabToken: false,

			// Discover tab
			searchQuery: '',
			searchSource: 'github',
			searchLoading: false,
			searchResults: [],
			searchError: null,
			hasSearched: false,

			// Repository tab
			repoSource: 'GitHub',
			repoOwner: '',
			repoNamespace: '',
			repoName: '',
			branches: [],
			selectedBranch: null,
			configFiles: [],
			selectedFile: null,

			// URL tab
			importUrl: '',
			urlError: null,

			// Sync settings (shared)
			syncEnabled: true,
			syncInterval: 24,
		}
	},

	computed: {
		settingsUrl() {
			return window.location.origin + '/index.php/settings/admin/openregister#api-tokens'
		},
		canFetchBranches() {
			if (this.repoSource === 'GitHub') {
				return this.repoOwner && this.repoName
			}
			return this.repoNamespace && this.repoName
		},
		canImport() {
		// Tab 0: Discover (imports are handled per-card, not via main button)
		// Tab 1: GitHub/GitLab
			if (this.activeTab === 1) {
				return this.selectedFile !== null
			}
			// Tab 2: URL
			if (this.activeTab === 2) {
				return this.importUrl !== ''
			}
			return false
		},
	},

	async mounted() {
		await this.checkTokenAvailability()
	},
	methods: {
		/**
		 * Check if API tokens are configured
		 */
		async checkTokenAvailability() {
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/settings/api-tokens'))
				// Check if tokens exist and are not empty strings
				// The backend returns masked tokens, so we just check if they exist
				this.hasGithubToken = !!(response.data.github_token && response.data.github_token.length > 0)
				this.hasGitlabToken = !!(response.data.gitlab_token && response.data.gitlab_token.length > 0)

				// Debug logging removed for production
			} catch (error) {
				// Debug logging removed for production
				// Assume no tokens if check fails
				this.hasGithubToken = false
				this.hasGitlabToken = false
			}
		},

		/**
		 * Get token warning title based on which tokens are missing
		 *
		 * @return {string}
		 */
		getTokenWarningTitle() {
			if (!this.hasGithubToken && !this.hasGitlabToken) {
				return 'API Tokens Not Configured'
			} else if (!this.hasGithubToken) {
				return 'GitHub Token Not Configured'
			} else {
				return 'GitLab Token Not Configured'
			}
		},

		/**
		 * Get token warning message based on which tokens are missing
		 *
		 * @return {string}
		 */
		getTokenWarningMessage() {
			if (!this.hasGithubToken && !this.hasGitlabToken) {
				return 'Discovery requires API tokens for GitHub and/or GitLab. Configure your tokens in the settings to enable search.'
			} else if (!this.hasGithubToken) {
				return 'GitHub token is not configured. Configure your GitHub token in the settings to enable GitHub search.'
			} else {
				return 'GitLab token is not configured. Configure your GitLab token in the settings to enable GitLab search.'
			}
		},

		closeModal() {
			navigationStore.setModal(false)
			this.resetForm()
		},
		resetForm() {
			this.loading = false
			this.success = false
			this.successMessage = ''
			this.error = null
			this.searchQuery = ''
			this.searchResults = []
			this.searchError = null
			this.hasSearched = false
			this.repoOwner = ''
			this.repoNamespace = ''
			this.repoName = ''
			this.branches = []
			this.selectedBranch = null
			this.configFiles = []
			this.selectedFile = null
			this.importUrl = ''
			this.urlError = null
			this.syncEnabled = true
			this.syncInterval = 24
		},
		async searchConfigurations() {
			this.searchLoading = true
			this.hasSearched = true
			this.error = null
			this.searchError = null
			this.searchResults = []

			try {
				this.searchResults = await configurationStore.discoverConfigurations(
					this.searchSource,
					this.searchQuery,
				)
			} catch (error) {
				// Set search-specific error for contextual display in the Discover tab
				this.searchError = error.message || 'Failed to search configurations'
				// Also set general error for top-level display
				this.error = error.message || 'Failed to search configurations'
				console.error('Search error:', error)
			} finally {
				this.searchLoading = false
			}
		},
		async importDiscoveredConfiguration(result) {
			this.loading = true
			this.error = null

			try {
				// Determine import method based on source
				if (result.repository) {
					// GitHub result
					const [owner, repo] = result.repository.split('/')
					await configurationStore.importFromGitHub({
						owner,
						repo,
						path: result.path,
						branch: result.ref || 'main',
						syncEnabled: true,
						syncInterval: 24,
					})
				} else {
					// GitLab result
					await configurationStore.importFromGitLab({
						namespace: result.namespace,
						project: result.project,
						path: result.path,
						ref: result.ref || 'main',
						syncEnabled: true,
						syncInterval: 24,
					})
				}

				this.successMessage = 'Configuration imported successfully!'
				this.success = true
				// Refresh all lists to show newly imported entities
				await Promise.all([
					configurationStore.refreshConfigurationList(),
					registerStore.refreshRegisterList(),
					schemaStore.refreshSchemaList(),
				])
				setTimeout(() => this.closeModal(), 1500)
			} catch (error) {
				// Don't show error if configuration already exists (UI shows this visually)
				const errorMessage = error.message || 'Failed to import configuration'
				if (!errorMessage.includes('already exists')) {
					this.error = errorMessage
				}
				// Always refresh all lists to update UI
				await Promise.all([
					configurationStore.refreshConfigurationList(),
					registerStore.refreshRegisterList(),
					schemaStore.refreshSchemaList(),
				])
			} finally {
				this.loading = false
			}
		},
		async fetchBranches() {
			this.loading = true
			this.error = null
			this.branches = []
			this.selectedBranch = null
			this.configFiles = []
			this.selectedFile = null

			try {
				const params = this.repoSource === 'GitHub'
					? { owner: this.repoOwner, repo: this.repoName }
					: { namespace: this.repoNamespace, project: this.repoName }

				const branches = await configurationStore.getBranches(
					this.repoSource.toLowerCase(),
					params,
				)

				this.branches = branches.map(b => ({ id: b.name, label: b.name }))
				// Pre-select default/main branch if available
				const defaultBranch = branches.find(b => b.default || b.name === 'main' || b.name === 'master')
				if (defaultBranch) {
					this.selectedBranch = { id: defaultBranch.name, label: defaultBranch.name }
					await this.fetchConfigurationFiles()
				}
			} catch (error) {
				this.error = error.message || 'Failed to fetch branches'
			} finally {
				this.loading = false
			}
		},
		async fetchConfigurationFiles() {
			if (!this.selectedBranch) return

			this.loading = true
			this.error = null
			this.configFiles = []
			this.selectedFile = null

			try {
				const params = this.repoSource === 'GitHub'
					? { owner: this.repoOwner, repo: this.repoName, branch: this.selectedBranch.id }
					: { namespace: this.repoNamespace, project: this.repoName, ref: this.selectedBranch.id }

				this.configFiles = await configurationStore.getConfigurationFiles(
					this.repoSource.toLowerCase(),
					params,
				)
			} catch (error) {
				this.error = error.message || 'Failed to fetch configuration files'
			} finally {
				this.loading = false
			}
		},
		async performImport() {
			this.loading = true
			this.error = null

			try {
			// Tab 1: GitHub/GitLab
				if (this.activeTab === 1) {
					const params = {
						path: this.selectedFile.path,
						syncEnabled: this.syncEnabled,
						syncInterval: parseInt(this.syncInterval),
					}

					if (this.repoSource === 'GitHub') {
						await configurationStore.importFromGitHub({
							owner: this.repoOwner,
							repo: this.repoName,
							branch: this.selectedBranch.id,
							...params,
						})
					} else {
						await configurationStore.importFromGitLab({
							namespace: this.repoNamespace,
							project: this.repoName,
							ref: this.selectedBranch.id,
							...params,
						})
					}

					this.successMessage = `Configuration imported from ${this.repoSource}!`
				} else if (this.activeTab === 2) {
					// Tab 2: URL
					// Validate URL
					try {
						// eslint-disable-next-line no-new
						const validUrl = new URL(this.importUrl) // URL validation successful
						// Use validUrl.href to avoid no-new and no-void rule violations
						if (!validUrl.href) {
							throw new Error('Invalid URL')
						}
					} catch {
						this.urlError = 'Please enter a valid URL'
						this.loading = false
						return
					}

					await configurationStore.importFromUrl({
						url: this.importUrl,
						syncEnabled: this.syncEnabled,
						syncInterval: parseInt(this.syncInterval),
					})

					this.successMessage = 'Configuration imported from URL!'
				}

				this.success = true
				// Refresh all lists to show newly imported entities
				await Promise.all([
					configurationStore.refreshConfigurationList(),
					registerStore.refreshRegisterList(),
					schemaStore.refreshSchemaList(),
				])
				setTimeout(() => this.closeModal(), 1500)
			} catch (error) {
				this.error = error.message || 'Failed to import configuration'
			} finally {
				this.loading = false
			}
		},
		async handleCheckVersion(configuration) {
			// Handle check version for already imported configurations
			try {
				const response = await axios.post(
					generateUrl(`/apps/openregister/api/configurations/${configuration.id}/check-version`),
				)

				if (response.data.hasUpdate) {
					showSuccess(
						`Update available: ${response.data.localVersion} → ${response.data.remoteVersion}`,
					)
				} else {
					showSuccess('Configuration is up to date')
				}

				// Refresh the list to show updated version info
				await configurationStore.refreshConfigurationList()
			} catch (error) {
				console.error('Failed to check version:', error)
				showError('Failed to check version: ' + (error.response?.data?.error || error.message))
			}
		},
	},
}
</script>

<style scoped>
.tabContainer {
	width: 100%;
}

.tabContent {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.tabDescription {
	color: var(--color-text-lighter);
	margin-bottom: 8px;
}

.searchContainer {
	display: flex;
	gap: 12px;
	align-items: flex-end;
}

.resultsGrid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 16px;
	margin-top: 16px;
}

.filesGrid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
	gap: 12px;
	margin-top: 16px;
}

.fileCard {
	border: 2px solid var(--color-border);
	border-radius: 8px;
	padding: 16px;
	cursor: pointer;
	transition: all 0.2s;
}

.fileCard:hover {
	border-color: var(--color-primary);
	background-color: var(--color-background-hover);
}

.fileCard.selected {
	border-color: var(--color-primary);
	background-color: var(--color-primary-light);
}

.fileCard h4 {
	margin: 0 0 8px 0;
	font-size: 1.1em;
}

.fileDescription {
	color: var(--color-text-lighter);
	font-size: 0.9em;
	margin: 8px 0;
}

.filePath {
	font-family: monospace;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
	display: block;
	margin-top: 8px;
}

.fileVersion {
	display: inline-block;
	margin-top: 8px;
	padding: 2px 8px;
	background-color: var(--color-primary);
	color: white;
	border-radius: 10px;
	font-size: 0.8em;
}

.syncSettings {
	margin: 24px 0;
	padding: 20px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background-color: var(--color-background-hover);
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.syncSettings h4 {
	margin: 0 0 8px 0;
	font-weight: 600;
	color: var(--color-main-text);
}

/* GitHub button styling */
.github-button {
	background-color: #24292e !important;
	color: white !important;
	border-color: #24292e !important;
}

.github-button:hover {
	background-color: #1b1f23 !important;
	border-color: #1b1f23 !important;
}

/* GitLab button styling */
.gitlab-button {
	background-color: #fc6d26 !important;
	color: white !important;
	border-color: #fc6d26 !important;
}

.gitlab-button:hover {
	background-color: #e24329 !important;
	border-color: #e24329 !important;
}

/* Disabled button state */
.github-button:disabled,
.gitlab-button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.github-button:disabled:hover,
.gitlab-button:disabled:hover {
	background-color: inherit !important;
	border-color: inherit !important;
}

/* Token warning styles */
.token-warnings {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 12px;
	padding: 12px;
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.token-warning-item {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.token-warning-item span {
	font-style: italic;
}
</style>
