<script setup>
import { configurationStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'importConfiguration'"
		name="importConfiguration"
		title="Import Configuration"
		size="large"
		:can-close="!loading">
		<NcNoteCard v-if="success" type="success">
			<p>{{ successMessage }}</p>
		</NcNoteCard>

		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<NcTabs v-model="activeTab">
			<!-- Discover Tab -->
			<NcTab name="discover" label="Discover">
				<div class="tabContent">
					<p class="tabDescription">
						Search GitHub and GitLab for OpenRegister configurations created by the community.
					</p>

					<div class="searchContainer">
						<NcTextField
							:value.sync="searchQuery"
							label="Search configurations"
							placeholder="Enter search terms or leave empty to browse all"
							@keyup.enter="searchConfigurations">
							<Magnify :size="20" />
						</NcTextField>

						<NcActions>
							<NcActionButton @click="searchSource = 'github'; searchConfigurations()">
								<template #icon>
									<Github :size="20" />
								</template>
								Search GitHub
							</NcActionButton>
							<NcActionButton @click="searchSource = 'gitlab'; searchConfigurations()">
								<template #icon>
									<Gitlab :size="20" />
								</template>
								Search GitLab
							</NcActionButton>
						</NcActions>
					</div>

					<NcLoadingIcon v-if="searchLoading" :size="64" />

					<div v-else-if="searchResults.length > 0" class="resultsGrid">
						<DiscoveredConfigurationCard
							v-for="(result, index) in searchResults"
							:key="index"
							:configuration="result"
							@import="importDiscoveredConfiguration(result)" />
					</div>

					<NcEmptyContent v-else-if="hasSearched"
						name="No configurations found"
						description="Try adjusting your search terms or browse a different source.">
						<template #icon>
							<Magnify :size="64" />
						</template>
					</NcEmptyContent>
				</div>
			</NcTab>

			<!-- GitHub/GitLab Tab -->
			<NcTab name="repository" label="GitHub / GitLab">
				<div class="tabContent">
					<p class="tabDescription">
						Import a configuration from a specific GitHub or GitLab repository and branch.
					</p>

					<NcSelect
						v-model="repoSource"
						:options="['GitHub', 'GitLab']"
						label="Source Platform" />

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
						label="Branch"
						@change="fetchConfigurationFiles" />

					<div v-if="configFiles.length > 0" class="filesGrid">
						<div
							v-for="file in configFiles"
							:key="file.path"
							class="fileCard"
							:class="{ selected: selectedFile === file }"
							@click="selectedFile = file">
							<h4>{{ file.config.title }}</h4>
							<p class="fileDescription">{{ file.config.description || 'No description' }}</p>
							<span class="filePath">{{ file.path }}</span>
							<span class="fileVersion">v{{ file.config.version }}</span>
						</div>
					</div>

					<div v-if="selectedFile" class="syncSettings">
						<h4>Synchronization Settings</h4>
						<NcCheckboxRadioSwitch
							:checked="syncEnabled"
							type="switch"
							@update:checked="syncEnabled = $event">
							Enable automatic synchronization
						</NcCheckboxRadioSwitch>

						<NcTextField
							v-if="syncEnabled"
							:value.sync="syncInterval"
							type="number"
							label="Sync Interval (hours)"
							:min="1"
							:max="168" />
					</div>
				</div>
			</NcTab>

			<!-- URL Tab -->
			<NcTab name="url" label="Import from URL">
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

					<div class="syncSettings">
						<h4>Synchronization Settings</h4>
						<NcCheckboxRadioSwitch
							:checked="syncEnabled"
							type="switch"
							@update:checked="syncEnabled = $event">
							Enable automatic synchronization
							<template #helper>
								Keep this configuration in sync with the source URL
							</template>
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
								How often to check for updates (1-168 hours)
							</template>
						</NcTextField>
					</div>
				</div>
			</NcTab>
		</NcTabs>

		<template #actions>
			<NcButton @click="closeModal" :disabled="loading">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Cancel
			</NcButton>
			<NcButton
				v-if="canImport"
				:disabled="loading"
				type="primary"
				@click="performImport">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Import v-else :size="20" />
				</template>
				Import
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
	NcTabs,
	NcTab,
	NcTextField,
	NcSelect,
	NcActions,
	NcActionButton,
	NcEmptyContent,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Import from 'vue-material-design-icons/Import.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Github from 'vue-material-design-icons/Github.vue'
import Gitlab from 'vue-material-design-icons/Gitlab.vue'
import SourceBranch from 'vue-material-design-icons/SourceBranch.vue'

import DiscoveredConfigurationCard from '../../components/DiscoveredConfigurationCard.vue'

export default {
	name: 'ImportConfiguration',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		NcTabs,
		NcTab,
		NcTextField,
		NcSelect,
		NcActions,
		NcActionButton,
		NcEmptyContent,
		DiscoveredConfigurationCard,
		// Icons
		Cancel,
		Import,
		Magnify,
		Github,
		Gitlab,
		SourceBranch,
	},
	data() {
		return {
			activeTab: 'discover',
			loading: false,
			success: false,
			successMessage: '',
			error: null,

			// Discover tab
			searchQuery: '',
			searchSource: 'github',
			searchLoading: false,
			searchResults: [],
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
		canFetchBranches() {
			if (this.repoSource === 'GitHub') {
				return this.repoOwner && this.repoName
			}
			return this.repoNamespace && this.repoName
		},
		canImport() {
			if (this.activeTab === 'repository') {
				return this.selectedFile !== null
			}
			if (this.activeTab === 'url') {
				return this.importUrl !== ''
			}
			return false
		},
	},
	methods: {
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

			try {
				this.searchResults = await configurationStore.discoverConfigurations(
					this.searchSource,
					this.searchQuery
				)
			} catch (error) {
				this.error = error.message || 'Failed to search configurations'
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
				await configurationStore.refreshConfigurationList()
				setTimeout(() => this.closeModal(), 1500)
			} catch (error) {
				this.error = error.message || 'Failed to import configuration'
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
					params
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
					params
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
				if (this.activeTab === 'repository') {
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
				} else if (this.activeTab === 'url') {
					// Validate URL
					try {
						const validUrl = new URL(this.importUrl)
						void validUrl // URL validation successful
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
				await configurationStore.refreshConfigurationList()
				setTimeout(() => this.closeModal(), 1500)
			} catch (error) {
				this.error = error.message || 'Failed to import configuration'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
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
	margin-top: 24px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background-color: var(--color-background-hover);
}

.syncSettings h4 {
	margin: 0 0 12px 0;
}
</style>
