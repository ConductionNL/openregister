<script setup>
import { configurationStore, navigationStore, organisationStore, applicationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'editConfiguration'"
		:name="configurationStore.configurationItem?.id ? 'Edit Configuration' : 'New Configuration'"
		size="large"
		:can-close="true"
		:open="true"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div class="tabContainer">
			<BTabs v-model="activeTab" content-class="mt-3" justified>
				<!-- Settings Tab -->
				<BTab active>
					<template #title>
						<Cog :size="16" />
						<span>Settings</span>
					</template>

					<div class="form-editor">
						<NcTextField
							label="Title *"
							placeholder="Enter configuration title"
							:value="configurationStore.configurationItem?.title || ''"
							:error="!configurationStore.configurationItem?.title?.trim?.()"
							@update:value="updateTitle" />

						<NcTextArea
							label="Description"
							placeholder="Enter configuration description (optional)"
							:value="configurationStore.configurationItem?.description || ''"
							@update:value="updateDescription" />

						<div class="selectField">
							<label for="type-select">Type</label>
							<NcSelect
								id="type-select"
								v-model="selectedType"
								:options="typeOptions"
								label="label"
								track-by="value"
								:label-outside="true"
								placeholder="Select configuration type..."
								@input="updateType">
								<template #option="{ label, description }">
									<div class="option-content">
										<span class="option-title">{{ label }}</span>
										<span v-if="description" class="option-description">{{ description }}</span>
									</div>
								</template>
							</NcSelect>
							<p class="field-hint">
								Configuration type (default, application, etc.)
							</p>
						</div>

						<NcTextField
							label="App ID"
							placeholder="myapp"
							:value="configurationStore.configurationItem?.app || ''"
							@update:value="updateApp">
							<template #helper-text-message>
								<p>Application identifier for this configuration (optional)</p>
							</template>
						</NcTextField>

						<!-- Organisation is automatically set to active organisation by backend -->
					</div>
				</BTab>

				<!-- Configuration Tab -->
				<BTab>
					<template #title>
						<Database :size="16" />
						<span>Configuration</span>
					</template>

					<div class="form-editor">
						<div class="selectField">
							<label for="registers-select">Registers</label>
							<NcSelect
								id="registers-select"
								v-model="selectedRegisters"
								:options="registerOptions"
								:loading="loadingRegisters"
								:multiple="true"
								label="title"
								track-by="id"
								:label-outside="true"
								:filterable="true"
								placeholder="Search registers..."
								:close-on-select="false"
								@search-change="searchRegisters"
								@input="updateRegisters">
								<template #option="{ title, description }">
									<div class="option-content">
										<span class="option-title">{{ title }}</span>
										<span v-if="description" class="option-description">{{ description }}</span>
									</div>
								</template>
								<template #no-options>
									<span v-if="loadingRegisters">Searching...</span>
									<span v-else>No registers found</span>
								</template>
							</NcSelect>
							<p class="field-hint">
								{{ selectedRegisters.length }} register(s) selected
							</p>
						</div>

						<div class="selectField">
							<label for="schemas-select">Schemas</label>
							<NcSelect
								id="schemas-select"
								v-model="selectedSchemas"
								:options="schemaOptions"
								:loading="loadingSchemas"
								:multiple="true"
								label="title"
								track-by="id"
								:label-outside="true"
								:filterable="true"
								placeholder="Search schemas..."
								:close-on-select="false"
								@search-change="searchSchemas"
								@input="updateSchemas">
								<template #option="{ title, description }">
									<div class="option-content">
										<span class="option-title">{{ title }}</span>
										<span v-if="description" class="option-description">{{ description }}</span>
									</div>
								</template>
								<template #no-options>
									<span v-if="loadingSchemas">Searching...</span>
									<span v-else>No schemas found</span>
								</template>
							</NcSelect>
							<p class="field-hint">
								{{ selectedSchemas.length }} schema(s) selected
							</p>
						</div>

						<div class="selectField">
							<label for="objects-select">Objects</label>
							<NcSelect
								id="objects-select"
								v-model="selectedObjects"
								:options="objectOptions"
								:loading="loadingObjects"
								:multiple="true"
								label="title"
								track-by="id"
								:label-outside="true"
								:filterable="true"
								placeholder="Search objects..."
								:close-on-select="false"
								:disabled="selectedRegisters.length === 0 && selectedSchemas.length === 0"
								@search-change="searchObjects"
								@input="updateObjects">
								<template #option="{ title, description }">
									<div class="option-content">
										<span class="option-title">{{ title }}</span>
										<span v-if="description" class="option-description">{{ description }}</span>
									</div>
								</template>
								<template #no-options>
									<span v-if="loadingObjects">Searching...</span>
									<span v-else-if="selectedRegisters.length === 0 && selectedSchemas.length === 0">Please select registers or schemas first</span>
									<span v-else>No objects found</span>
								</template>
							</NcSelect>
							<p class="field-hint">
								{{ selectedObjects.length }} object(s) selected
								<span v-if="selectedRegisters.length === 0 && selectedSchemas.length === 0"> - filtered by selected registers/schemas</span>
							</p>
						</div>

						<div class="selectField">
							<label for="sources-select">Data Sources</label>
							<NcSelect
								id="sources-select"
								v-model="selectedSources"
								:options="sourceOptions"
								:loading="loadingSources"
								:multiple="true"
								label="title"
								track-by="id"
								:label-outside="true"
								:filterable="true"
								placeholder="Search data sources..."
								:close-on-select="false"
								@search-change="searchSources"
								@input="updateSources">
								<template #option="{ title, description }">
									<div class="option-content">
										<span class="option-title">{{ title }}</span>
										<span v-if="description" class="option-description">{{ description }}</span>
									</div>
								</template>
								<template #no-options>
									<span v-if="loadingSources">Searching...</span>
									<span v-else>No sources found</span>
								</template>
							</NcSelect>
							<p class="field-hint">
								{{ selectedSources.length }} source(s) selected
							</p>
						</div>

						<div class="selectField">
							<label for="agents-select">Agents</label>
							<NcSelect
								id="agents-select"
								v-model="selectedAgents"
								:options="agentOptions"
								:loading="loadingAgents"
								:multiple="true"
								label="name"
								track-by="id"
								:label-outside="true"
								:filterable="true"
								placeholder="Search agents..."
								:close-on-select="false"
								@search-change="searchAgents"
								@input="updateAgents">
								<template #option="{ name, description }">
									<div class="option-content">
										<span class="option-title">{{ name }}</span>
										<span v-if="description" class="option-description">{{ description }}</span>
									</div>
								</template>
								<template #no-options>
									<span v-if="loadingAgents">Searching...</span>
									<span v-else>No agents found</span>
								</template>
							</NcSelect>
							<p class="field-hint">
								{{ selectedAgents.length }} agent(s) selected
							</p>
						</div>

						<div class="selectField">
							<label for="views-select">Views</label>
							<NcSelect
								id="views-select"
								v-model="selectedViews"
								:options="viewOptions"
								:loading="loadingViews"
								:multiple="true"
								label="name"
								track-by="id"
								:label-outside="true"
								:filterable="true"
								placeholder="Search views..."
								:close-on-select="false"
								@search-change="searchViews"
								@input="updateViews">
								<template #option="{ name, description }">
									<div class="option-content">
										<span class="option-title">{{ name }}</span>
										<span v-if="description" class="option-description">{{ description }}</span>
									</div>
								</template>
								<template #no-options>
									<span v-if="loadingViews">Searching...</span>
									<span v-else>No views found</span>
								</template>
							</NcSelect>
							<p class="field-hint">
								{{ selectedViews.length }} view(s) selected
							</p>
						</div>

						<div class="selectField">
							<label for="managed-applications-select">Applications</label>
							<NcSelect
								id="managed-applications-select"
								v-model="selectedManagedApplications"
								:options="applicationOptions"
								:loading="loadingApplications"
								:multiple="true"
								label="name"
								track-by="id"
								:label-outside="true"
								:filterable="true"
								placeholder="Search applications..."
								:close-on-select="false"
								@search-change="searchApplications"
								@input="updateManagedApplications">
								<template #option="{ name, description }">
									<div class="option-content">
										<span class="option-title">{{ name }}</span>
										<span v-if="description" class="option-description">{{ description }}</span>
									</div>
								</template>
								<template #no-options>
									<span v-if="loadingApplications">Searching...</span>
									<span v-else>No applications found</span>
								</template>
							</NcSelect>
							<p class="field-hint">
								{{ selectedManagedApplications.length }} application(s) selected
							</p>
						</div>
					</div>
				</BTab>

				<!-- Management Tab -->
				<BTab>
					<template #title>
						<CloudSync :size="16" />
						<span>Management</span>
					</template>

					<div class="form-editor">
						<div class="selectField">
							<label for="source-type-select">Source Type *</label>
							<NcSelect
								id="source-type-select"
								v-model="selectedSourceType"
								:options="sourceTypeOptions"
								label="label"
								track-by="value"
								:label-outside="true"
								placeholder="Select source type..."
								@input="updateSourceType">
								<template #option="{ label, description }">
									<div class="option-content">
										<span class="option-title">{{ label }}</span>
										<span v-if="description" class="option-description">{{ description }}</span>
									</div>
								</template>
							</NcSelect>
							<p class="field-hint">
								Determines where the configuration is managed
							</p>
						</div>

						<NcTextField
							v-if="selectedSourceType && selectedSourceType.value !== 'local'"
							label="Source URL"
							placeholder="https://raw.githubusercontent.com/..."
							:value="configurationStore.configurationItem?.sourceUrl || ''"
							@update:value="updateSourceUrl">
							<template #helper-text-message>
								<p>The URL to the remote configuration file (JSON or YAML)</p>
							</template>
						</NcTextField>

						<NcTextField
							label="Version"
							placeholder="1.0.0"
							:value="configurationStore.configurationItem?.version || ''"
							@update:value="updateVersion">
							<template #helper-text-message>
								<p>Semantic version (e.g., 1.0.0, 2.1.3)</p>
							</template>
						</NcTextField>

						<div class="selectField">
							<label for="application-select">Owner Application</label>
							<NcSelect
								id="application-select"
								v-model="selectedApplication"
								:options="applicationOptions"
								label="name"
								track-by="id"
								:label-outside="true"
								placeholder="Select owner application (optional)..."
								@input="updateApplication">
								<template #option="{ name, description }">
									<div class="option-content">
										<span class="option-title">{{ name }}</span>
										<span v-if="description" class="option-description">{{ description }}</span>
									</div>
								</template>
							</NcSelect>
							<p v-if="selectedApplication" class="field-hint">
								Owner: {{ selectedApplication.name }}
							</p>
							<p v-else class="field-hint">
								The application that owns this configuration (optional)
							</p>
						</div>

						<NcTextField
							label="Local Version"
							placeholder="1.0.0"
							:value="configurationStore.configurationItem?.localVersion || ''"
							@update:value="updateLocalVersion">
							<template #helper-text-message>
								<p>Current version installed locally (semantic versioning)</p>
							</template>
						</NcTextField>

						<NcTextField
							v-if="configurationStore.configurationItem?.remoteVersion"
							label="Remote Version (Read-only)"
							:value="configurationStore.configurationItem?.remoteVersion || '-'"
							:disabled="true">
							<template #helper-text-message>
								<p>Last checked version from remote source</p>
							</template>
						</NcTextField>

						<div class="checkboxField">
							<NcCheckboxRadioSwitch
								:checked="configurationStore.configurationItem?.autoUpdate || false"
								@update:checked="updateAutoUpdate">
								Enable Auto-Update
							</NcCheckboxRadioSwitch>
							<p class="field-hint">
								Automatically import updates when a new version is detected
							</p>
						</div>

						<div class="selectField">
							<label for="notification-groups-select">Notification Groups</label>
							<NcSelect
								id="notification-groups-select"
								v-model="selectedNotificationGroups"
								:options="notificationGroupOptions"
								:multiple="true"
								label="label"
								track-by="value"
								:label-outside="true"
								placeholder="Select groups to notify..."
								:close-on-select="false"
								@input="updateNotificationGroups">
								<template #option="{ label }">
									<span>{{ label }}</span>
								</template>
							</NcSelect>
							<p class="field-hint">
								Groups that will receive notifications when updates are available (admin is always included)
							</p>
						</div>

						<NcNoteCard v-if="selectedSourceType && selectedSourceType.value === 'github'" type="info">
							<p>GitHub Integration Settings</p>
						</NcNoteCard>

						<NcTextField
							v-if="selectedSourceType && selectedSourceType.value === 'github'"
							label="GitHub Repository"
							placeholder="owner/repository"
							:value="configurationStore.configurationItem?.githubRepo || ''"
							@update:value="updateGithubRepo">
							<template #helper-text-message>
								<p>Repository in format: owner/repo</p>
							</template>
						</NcTextField>

						<NcTextField
							v-if="selectedSourceType && selectedSourceType.value === 'github'"
							label="GitHub Branch"
							placeholder="main"
							:value="configurationStore.configurationItem?.githubBranch || 'main'"
							@update:value="updateGithubBranch">
							<template #helper-text-message>
								<p>Branch to push/pull configurations</p>
							</template>
						</NcTextField>

						<NcTextField
							v-if="selectedSourceType && selectedSourceType.value === 'github'"
							label="GitHub Path"
							placeholder="configs/configuration.json"
							:value="configurationStore.configurationItem?.githubPath || ''"
							@update:value="updateGithubPath">
							<template #helper-text-message>
								<p>Path within the repository for the configuration file</p>
							</template>
						</NcTextField>
					</div>
				</BTab>
			</BTabs>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Cancel
			</NcButton>
			<NcButton
				:disabled="loading || !isValid"
				type="primary"
				@click="saveConfiguration">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSave v-else :size="20" />
				</template>
				Save
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
	NcTextArea,
} from '@nextcloud/vue'

import { BTabs, BTab } from 'bootstrap-vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Database from 'vue-material-design-icons/Database.vue'
import CloudSync from 'vue-material-design-icons/CloudSync.vue'

export default {
	name: 'EditConfiguration',
	components: {
		NcDialog,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		NcTextArea,
		BTabs,
		BTab,
		// Icons
		Cancel,
		ContentSave,
		Cog,
		Database,
		CloudSync,
	},
	data() {
		return {
			loading: false,
			error: null,
			activeTab: 0,
			selectedRegisters: [],
			selectedSchemas: [],
			selectedObjects: [],
			selectedSources: [],
			selectedAgents: [],
			selectedViews: [],
			selectedManagedApplications: [],
			selectedApplication: null,
			selectedType: null,
			// Management tab selections
			selectedSourceType: null,
			selectedNotificationGroups: [],
			// Loading states for searches
			loadingRegisters: false,
			loadingSchemas: false,
			loadingObjects: false,
			loadingSources: false,
			loadingAgents: false,
			loadingViews: false,
			loadingApplications: false,
			// Search results
			registerOptions: [],
			schemaOptions: [],
			objectOptions: [],
			sourceOptions: [],
			agentOptions: [],
			viewOptions: [],
			applicationOptions: [],
			// Debounce timers
			registerSearchDebounce: null,
			schemaSearchDebounce: null,
			objectSearchDebounce: null,
			sourceSearchDebounce: null,
			agentSearchDebounce: null,
			viewSearchDebounce: null,
			applicationSearchDebounce: null,
		}
	},
	computed: {
		isValid() {
			const item = configurationStore.configurationItem
			return Boolean(item?.title?.trim())
		},
		sourceTypeOptions() {
			return [
				{ value: 'local', label: 'Local', description: 'Manually managed configuration' },
				{ value: 'github', label: 'GitHub', description: 'Configuration from GitHub repository' },
				{ value: 'gitlab', label: 'GitLab', description: 'Configuration from GitLab repository' },
				{ value: 'url', label: 'URL', description: 'Configuration from any URL' },
			]
		},
		typeOptions() {
			return [
				{ value: 'default', label: 'Default', description: 'Standard configuration type' },
				{ value: 'application', label: 'Application', description: 'Application-specific configuration' },
				{ value: 'manual', label: 'Manual', description: 'Manually created configuration' },
			]
		},
		notificationGroupOptions() {
			// In a real implementation, this would fetch from Nextcloud groups API
			// For now, return common groups
			return [
				{ value: 'admin', label: 'Administrators' },
				{ value: 'users', label: 'All Users' },
			]
		},
	},
	async created() {
		// Organisations and applications are now hot-loaded at app startup
		// Only refresh if somehow they're empty (shouldn't happen in normal flow)
		if (!organisationStore.organisationList || organisationStore.organisationList.length === 0) {
			organisationStore.refreshOrganisationList()
		}
		if (!applicationStore.applicationList || applicationStore.applicationList.length === 0) {
			applicationStore.refreshApplicationList()
		}

		// Perform initial searches for Configuration tab entities (load top 10)
		this.searchRegisters('')
		this.searchSchemas('')
		this.searchSources('')
		this.searchAgents('')
		this.searchViews('')
		this.searchApplications('')

		// Initialize configurationItem if it doesn't exist
		if (!configurationStore.configurationItem) {
			configurationStore.configurationItem = {
				title: '',
				description: null,
				type: 'default',
				app: '',
				version: '1.0.0', // Default semantic version
				application: '',
				owner: '',
				organisation: null,
				registers: [],
				schemas: [],
				objects: [],
				sources: [],
				agents: [],
				views: [],
				// Management tab defaults
				sourceType: 'local',
				isLocal: true, // New configurations are local by default
				sourceUrl: null,
				localVersion: '1.0.0',
				remoteVersion: null,
				autoUpdate: false,
				notificationGroups: [],
				githubRepo: null,
				githubBranch: 'main',
				githubPath: null,
			}
			this.selectedRegisters = []
			this.selectedSchemas = []
			this.selectedObjects = []
			this.selectedSources = []
			this.selectedAgents = []
			this.selectedViews = []
			this.selectedApplication = null
			this.selectedType = this.typeOptions[0] // 'default'
			// Management tab defaults
			this.selectedSourceType = this.sourceTypeOptions[0] // 'local'
			this.selectedNotificationGroups = []
		} else {
			// Load existing selections
			this.loadExistingSelections()
		}
	},
	methods: {
		updateTitle(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.title = value
		},
		updateDescription(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.description = value
		},
		updateVersion(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.version = value
		},
		updateType(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.type = value ? value.value : 'default'
			this.selectedType = value
		},
		updateApp(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.app = value || ''
		},
		updateApplication(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Store the application UUID
			configurationStore.configurationItem.application = value ? value.uuid : ''
			this.selectedApplication = value
		},
		updateRegisters(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Extract IDs from selected register objects
			configurationStore.configurationItem.registers = value.map(r => parseInt(r.id))
			this.selectedRegisters = value
		},
		updateSchemas(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Extract IDs from selected schema objects
			configurationStore.configurationItem.schemas = value.map(s => parseInt(s.id))
			this.selectedSchemas = value
		},
		updateObjects(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Extract IDs from selected object objects
			configurationStore.configurationItem.objects = value.map(o => parseInt(o.id))
			this.selectedObjects = value
		},
		updateSources(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Extract IDs from selected source objects
			configurationStore.configurationItem.sources = value.map(s => parseInt(s.id))
			this.selectedSources = value
		},
		updateAgents(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Extract IDs from selected agent objects
			configurationStore.configurationItem.agents = value.map(a => parseInt(a.id))
			this.selectedAgents = value
		},
		updateViews(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Extract IDs from selected view objects
			configurationStore.configurationItem.views = value.map(v => parseInt(v.id))
			this.selectedViews = value
		},
		updateManagedApplications(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Extract IDs from selected application objects
			configurationStore.configurationItem.applications = value.map(a => parseInt(a.id))
			this.selectedManagedApplications = value
		},
		// Management tab update methods
		updateSourceType(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.sourceType = value ? value.value : 'local'
			this.selectedSourceType = value
		},
		updateSourceUrl(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.sourceUrl = value
		},
		updateLocalVersion(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.localVersion = value
		},
		updateAutoUpdate(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.autoUpdate = value
		},
		updateNotificationGroups(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.notificationGroups = value.map(g => g.value)
			this.selectedNotificationGroups = value
		},
		updateGithubRepo(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.githubRepo = value
		},
		updateGithubBranch(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.githubBranch = value
		},
		updateGithubPath(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.githubPath = value
		},
		async loadExistingSelections() {
			const item = configurationStore.configurationItem
			if (item) {
				// Load selected application (from already loaded list)
				if (item.application) {
					this.selectedApplication = applicationStore.applicationList.find(
						a => a.uuid === item.application,
					) || null
				}
				// Organisation is automatically set by backend based on active organisation

				// Load Settings tab selections
				if (item.type) {
					this.selectedType = this.typeOptions.find(
						t => t.value === item.type,
					) || this.typeOptions[0] // Default to 'default'
				} else {
					this.selectedType = this.typeOptions[0] // Default to 'default'
				}

				// Load Management tab selections
				if (item.sourceType) {
					this.selectedSourceType = this.sourceTypeOptions.find(
						st => st.value === item.sourceType,
					) || null
				}
				if (item.notificationGroups && Array.isArray(item.notificationGroups)) {
					this.selectedNotificationGroups = item.notificationGroups.map(groupValue =>
						this.notificationGroupOptions.find(g => g.value === groupValue),
					).filter(Boolean)
				}

				// Load selected registers by fetching them individually
				if (item.registers && Array.isArray(item.registers) && item.registers.length > 0) {
					this.loadingRegisters = true
					try {
						const promises = item.registers.map(id =>
							fetch(`/index.php/apps/openregister/api/registers/${id}`).then(r => r.json()),
						)
						this.selectedRegisters = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected registers:', error)
					} finally {
						this.loadingRegisters = false
					}
				}

				// Load selected schemas by fetching them individually
				if (item.schemas && Array.isArray(item.schemas) && item.schemas.length > 0) {
					this.loadingSchemas = true
					try {
						const promises = item.schemas.map(id =>
							fetch(`/index.php/apps/openregister/api/schemas/${id}`).then(r => r.json()),
						)
						this.selectedSchemas = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected schemas:', error)
					} finally {
						this.loadingSchemas = false
					}
				}

				// Load selected objects by fetching them individually
				if (item.objects && Array.isArray(item.objects) && item.objects.length > 0) {
					this.loadingObjects = true
					try {
						const promises = item.objects.map(id =>
							fetch(`/index.php/apps/openregister/api/objects/${id}`).then(r => r.json()),
						)
						this.selectedObjects = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected objects:', error)
					} finally {
						this.loadingObjects = false
					}
				}

				// Load selected sources by fetching them individually
				if (item.sources && Array.isArray(item.sources) && item.sources.length > 0) {
					this.loadingSources = true
					try {
						const promises = item.sources.map(id =>
							fetch(`/index.php/apps/openregister/api/sources/${id}`).then(r => r.json()),
						)
						this.selectedSources = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected sources:', error)
					} finally {
						this.loadingSources = false
					}
				}

				// Load selected agents by fetching them individually
				if (item.agents && Array.isArray(item.agents) && item.agents.length > 0) {
					this.loadingAgents = true
					try {
						const promises = item.agents.map(id =>
							fetch(`/index.php/apps/openregister/api/agents/${id}`).then(r => r.json()),
						)
						this.selectedAgents = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected agents:', error)
					} finally {
						this.loadingAgents = false
					}
				}

				// Load selected views by fetching them individually
				if (item.views && Array.isArray(item.views) && item.views.length > 0) {
					this.loadingViews = true
					try {
						const promises = item.views.map(id =>
							fetch(`/index.php/apps/openregister/api/views/${id}`).then(r => r.json()),
						)
						this.selectedViews = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected views:', error)
					} finally {
						this.loadingViews = false
					}
				}

				// Load selected managed applications by fetching them individually
				if (item.applications && Array.isArray(item.applications) && item.applications.length > 0) {
					this.loadingApplications = true
					try {
						const promises = item.applications.map(id =>
							fetch(`/index.php/apps/openregister/api/applications/${id}`).then(r => r.json()),
						)
						this.selectedManagedApplications = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected applications:', error)
					} finally {
						this.loadingApplications = false
					}
				}
			}
		},
		// Search methods with debouncing
		searchRegisters(query) {
			clearTimeout(this.registerSearchDebounce)
			this.registerSearchDebounce = setTimeout(async () => {
				this.loadingRegisters = true
				try {
					const response = await fetch(`/index.php/apps/openregister/api/registers?_search=${encodeURIComponent(query)}&_limit=10`)
					const data = await response.json()
					this.registerOptions = data.results || data || []
					// Include already selected items
					this.selectedRegisters.forEach(selected => {
						if (!this.registerOptions.find(r => r.id === selected.id)) {
							this.registerOptions.unshift(selected)
						}
					})
				} catch (error) {
					console.error('Error searching registers:', error)
					this.registerOptions = []
				} finally {
					this.loadingRegisters = false
				}
			}, 300)
		},
		searchSchemas(query) {
			clearTimeout(this.schemaSearchDebounce)
			this.schemaSearchDebounce = setTimeout(async () => {
				this.loadingSchemas = true
				try {
					const response = await fetch(`/index.php/apps/openregister/api/schemas?_search=${encodeURIComponent(query)}&_limit=10`)
					const data = await response.json()
					this.schemaOptions = data.results || data || []
					// Include already selected items
					this.selectedSchemas.forEach(selected => {
						if (!this.schemaOptions.find(s => s.id === selected.id)) {
							this.schemaOptions.unshift(selected)
						}
					})
				} catch (error) {
					console.error('Error searching schemas:', error)
					this.schemaOptions = []
				} finally {
					this.loadingSchemas = false
				}
			}, 300)
		},
		searchObjects(query) {
			clearTimeout(this.objectSearchDebounce)
			this.objectSearchDebounce = setTimeout(async () => {
				this.loadingObjects = true
				try {
					// Build filter params based on selected registers and schemas
					const params = new URLSearchParams()
					params.append('_search', query)
					params.append('_limit', '10')

					// Filter by selected registers
					if (this.selectedRegisters.length > 0) {
						this.selectedRegisters.forEach(register => {
							params.append('_register[]', register.id)
						})
					}

					// Filter by selected schemas
					if (this.selectedSchemas.length > 0) {
						this.selectedSchemas.forEach(schema => {
							params.append('_schema[]', schema.id)
						})
					}

					const response = await fetch(`/index.php/apps/openregister/api/objects?${params.toString()}`)
					const data = await response.json()
					this.objectOptions = data.results || data || []
					// Include already selected items
					this.selectedObjects.forEach(selected => {
						if (!this.objectOptions.find(o => o.id === selected.id)) {
							this.objectOptions.unshift(selected)
						}
					})
				} catch (error) {
					console.error('Error searching objects:', error)
					this.objectOptions = []
				} finally {
					this.loadingObjects = false
				}
			}, 300)
		},
		searchSources(query) {
			clearTimeout(this.sourceSearchDebounce)
			this.sourceSearchDebounce = setTimeout(async () => {
				this.loadingSources = true
				try {
					const response = await fetch(`/index.php/apps/openregister/api/sources?_search=${encodeURIComponent(query)}&_limit=10`)
					const data = await response.json()
					this.sourceOptions = data.results || data || []
					// Include already selected items
					this.selectedSources.forEach(selected => {
						if (!this.sourceOptions.find(s => s.id === selected.id)) {
							this.sourceOptions.unshift(selected)
						}
					})
				} catch (error) {
					console.error('Error searching sources:', error)
					this.sourceOptions = []
				} finally {
					this.loadingSources = false
				}
			}, 300)
		},
		searchAgents(query) {
			clearTimeout(this.agentSearchDebounce)
			this.agentSearchDebounce = setTimeout(async () => {
				this.loadingAgents = true
				try {
					const response = await fetch(`/index.php/apps/openregister/api/agents?_search=${encodeURIComponent(query)}&_limit=10`)
					const data = await response.json()
					this.agentOptions = data.results || data || []
					// Include already selected items
					this.selectedAgents.forEach(selected => {
						if (!this.agentOptions.find(a => a.id === selected.id)) {
							this.agentOptions.unshift(selected)
						}
					})
				} catch (error) {
					console.error('Error searching agents:', error)
					this.agentOptions = []
				} finally {
					this.loadingAgents = false
				}
			}, 300)
		},
		searchViews(query) {
			clearTimeout(this.viewSearchDebounce)
			this.viewSearchDebounce = setTimeout(async () => {
				this.loadingViews = true
				try {
					const response = await fetch(`/index.php/apps/openregister/api/views?_search=${encodeURIComponent(query)}&_limit=10`)
					const data = await response.json()
					this.viewOptions = data.results || data || []
					// Include already selected items
					this.selectedViews.forEach(selected => {
						if (!this.viewOptions.find(v => v.id === selected.id)) {
							this.viewOptions.unshift(selected)
						}
					})
				} catch (error) {
					console.error('Error searching views:', error)
					this.viewOptions = []
				} finally {
					this.loadingViews = false
				}
			}, 300)
		},
		searchApplications(query) {
			clearTimeout(this.applicationSearchDebounce)
			this.applicationSearchDebounce = setTimeout(async () => {
				this.loadingApplications = true
				try {
					const response = await fetch(`/index.php/apps/openregister/api/applications?_search=${encodeURIComponent(query)}&_limit=10`)
					const data = await response.json()
					this.applicationOptions = data.results || data || []
					// Include already selected items
					this.selectedManagedApplications.forEach(selected => {
						if (!this.applicationOptions.find(a => a.id === selected.id)) {
							this.applicationOptions.unshift(selected)
						}
					})
				} catch (error) {
					console.error('Error searching applications:', error)
					this.applicationOptions = []
				} finally {
					this.loadingApplications = false
				}
			}, 300)
		},
		handleDialogClose() {
			this.closeModal()
		},
		closeModal() {
			navigationStore.setModal(false)
			this.loading = false
			this.error = null
			this.selectedRegisters = []
			this.selectedSchemas = []
			this.selectedObjects = []
			this.selectedSources = []
			this.selectedAgents = []
			this.selectedViews = []
			this.selectedApplication = null
		},
		async saveConfiguration() {
			this.loading = true
			this.error = null

			try {
				await configurationStore.saveConfiguration(configurationStore.configurationItem)
				this.closeModal()
			} catch (error) {
				this.error = error.message || 'Failed to save configuration'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.tabContainer {
	width: 100%;
}

.form-editor {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	padding: 1rem 0;
}

.selectField {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.selectField label {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.checkboxField {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.field-hint {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.option-content {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.option-title {
	font-weight: 500;
}

.option-description {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	max-width: 100%;
	white-space: normal;
	word-break: break-word;
}
</style>

<style>
/* Tab styling - must be unscoped to affect Bootstrap Vue components */
.nav-tabs .nav-link {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 0.5rem;
}

.nav-tabs .nav-link span {
	display: inline-flex;
	align-items: center;
}
</style>
