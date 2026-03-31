<script setup>
import { translate as t } from '@nextcloud/l10n'
import { configurationStore, navigationStore, organisationStore, applicationStore } from '../../store/store.js'
</script>

<template>
	<CnTabbedFormDialog
		ref="dialog"
		:tabs="dialogTabs"
		:item="configurationStore.configurationItem?.id ? configurationStore.configurationItem : null"
		entity-name="Configuration"
		:show-create-another="true"
		:disable-save="!configurationItem.title.trim()"
		@confirm="saveConfiguration"
		@close="closeModal"
		@reset="resetForm">
		<!-- Settings Tab -->
		<template #tab-settings="{ loading: dialogLoading }">
			<NcTextField
				:disabled="dialogLoading"
				label="Title *"
				:value.sync="configurationItem.title"
				:error="!configurationItem.title.trim()"
				placeholder="Enter configuration title" />

			<NcTextArea
				:disabled="dialogLoading"
				label="Description"
				:value.sync="configurationItem.description"
				placeholder="Enter configuration description (optional)" />

			<div class="selectField">
				<label for="type-select">Type</label>
				<NcSelect
					id="type-select"
					v-model="selectedType"
					:disabled="dialogLoading"
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
				:disabled="dialogLoading"
				label="App ID"
				:value.sync="configurationItem.app"
				placeholder="myapp">
				<template #helper-text-message>
					<p>Application identifier for this configuration (optional)</p>
				</template>
			</NcTextField>
		</template>

		<!-- Configuration Tab -->
		<template #tab-configuration="{ loading: dialogLoading }">
			<div class="selectField">
				<label for="registers-select">Registers</label>
				<NcSelect
					id="registers-select"
					v-model="selectedRegisters"
					:disabled="dialogLoading"
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
					:disabled="dialogLoading"
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
					:disabled="dialogLoading || (selectedRegisters.length === 0 && selectedSchemas.length === 0)"
					:options="objectOptions"
					:loading="loadingObjects"
					:multiple="true"
					label="title"
					track-by="id"
					:label-outside="true"
					:filterable="true"
					placeholder="Search objects..."
					:close-on-select="false"
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
					:disabled="dialogLoading"
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
					:disabled="dialogLoading"
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
					:disabled="dialogLoading"
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
					:disabled="dialogLoading"
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
		</template>

		<!-- Management Tab -->
		<template #tab-management="{ loading: dialogLoading }">
			<div class="selectField">
				<label for="source-type-select">Source Type *</label>
				<NcSelect
					id="source-type-select"
					v-model="selectedSourceType"
					:disabled="dialogLoading"
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
				:disabled="dialogLoading"
				label="Source URL"
				:value.sync="configurationItem.sourceUrl"
				placeholder="https://raw.githubusercontent.com/...">
				<template #helper-text-message>
					<p>The URL to the remote configuration file (JSON or YAML)</p>
				</template>
			</NcTextField>

			<NcTextField
				:disabled="dialogLoading"
				label="Version"
				:value.sync="configurationItem.version"
				placeholder="1.0.0">
				<template #helper-text-message>
					<p>Semantic version (e.g., 1.0.0, 2.1.3)</p>
				</template>
			</NcTextField>

			<div class="selectField">
				<label for="application-select">Owner Application</label>
				<NcSelect
					id="application-select"
					v-model="selectedApplication"
					:disabled="dialogLoading"
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
				:disabled="dialogLoading"
				label="Local Version"
				:value.sync="configurationItem.localVersion"
				placeholder="1.0.0">
				<template #helper-text-message>
					<p>Current version installed locally (semantic versioning)</p>
				</template>
			</NcTextField>

			<NcTextField
				v-if="configurationItem.remoteVersion"
				label="Remote Version (Read-only)"
				:value="configurationItem.remoteVersion || '-'"
				:disabled="true">
				<template #helper-text-message>
					<p>Last checked version from remote source</p>
				</template>
			</NcTextField>

			<div class="checkboxField">
				<NcCheckboxRadioSwitch
					:disabled="dialogLoading"
					:checked.sync="configurationItem.autoUpdate">
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
					:disabled="dialogLoading"
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
				:disabled="dialogLoading"
				label="GitHub Repository"
				:value.sync="configurationItem.githubRepo"
				placeholder="owner/repository">
				<template #helper-text-message>
					<p>Repository in format: owner/repo</p>
				</template>
			</NcTextField>

			<NcTextField
				v-if="selectedSourceType && selectedSourceType.value === 'github'"
				:disabled="dialogLoading"
				label="GitHub Branch"
				:value.sync="configurationItem.githubBranch"
				placeholder="main">
				<template #helper-text-message>
					<p>Branch to push/pull configurations</p>
				</template>
			</NcTextField>

			<NcTextField
				v-if="selectedSourceType && selectedSourceType.value === 'github'"
				:disabled="dialogLoading"
				label="GitHub Path"
				:value.sync="configurationItem.githubPath"
				placeholder="configs/configuration.json">
				<template #helper-text-message>
					<p>Path within the repository for the configuration file</p>
				</template>
			</NcTextField>
		</template>
	</CnTabbedFormDialog>
</template>

<script>
import {
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcSelect,
	NcTextField,
	NcTextArea,
} from '@nextcloud/vue'
import { CnTabbedFormDialog } from '@conduction/nextcloud-vue'

import Cog from 'vue-material-design-icons/Cog.vue'
import Database from 'vue-material-design-icons/Database.vue'
import CloudSync from 'vue-material-design-icons/CloudSync.vue'

export default {
	name: 'EditConfiguration',
	components: {
		CnTabbedFormDialog,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
		NcTextField,
		NcTextArea,
	},
	data() {
		return {
			configurationItem: {
				title: '',
				description: null,
				type: 'default',
				app: '',
				version: '1.0.0',
				application: '',
				owner: '',
				organisation: null,
				registers: [],
				schemas: [],
				objects: [],
				sources: [],
				agents: [],
				views: [],
				applications: [],
				sourceType: 'local',
				isLocal: true,
				sourceUrl: null,
				localVersion: '1.0.0',
				remoteVersion: null,
				autoUpdate: false,
				notificationGroups: [],
				githubRepo: null,
				githubBranch: 'main',
				githubPath: null,
			},
			selectedRegisters: [],
			selectedSchemas: [],
			selectedObjects: [],
			selectedSources: [],
			selectedAgents: [],
			selectedViews: [],
			selectedManagedApplications: [],
			selectedApplication: null,
			selectedType: null,
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
		dialogTabs() {
			return [
				{ id: 'settings', title: 'Settings', icon: Cog },
				{ id: 'configuration', title: 'Configuration', icon: Database },
				{ id: 'management', title: 'Management', icon: CloudSync },
			]
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
			return [
				{ value: 'admin', label: 'Administrators' },
				{ value: 'users', label: 'All Users' },
			]
		},
	},
	mounted() {
		// Refresh stores if empty
		if (!organisationStore.organisationList || organisationStore.organisationList.length === 0) {
			organisationStore.refreshOrganisationList()
		}
		if (!applicationStore.applicationList || applicationStore.applicationList.length === 0) {
			applicationStore.refreshApplicationList()
		}

		// Perform initial searches for Configuration tab entities
		this.searchRegisters('')
		this.searchSchemas('')
		this.searchSources('')
		this.searchAgents('')
		this.searchViews('')
		this.searchApplications('')

		// Initialize from store
		this.initializeConfigurationItem()
	},
	methods: {
		initializeConfigurationItem() {
			if (configurationStore.configurationItem?.id) {
				this.configurationItem = {
					...this.configurationItem,
					...configurationStore.configurationItem,
				}

				// Load Settings tab selections
				if (this.configurationItem.type) {
					this.selectedType = this.typeOptions.find(
						t => t.value === this.configurationItem.type,
					) || this.typeOptions[0]
				} else {
					this.selectedType = this.typeOptions[0]
				}

				// Load Management tab selections
				if (this.configurationItem.sourceType) {
					this.selectedSourceType = this.sourceTypeOptions.find(
						st => st.value === this.configurationItem.sourceType,
					) || null
				}
				if (this.configurationItem.notificationGroups && Array.isArray(this.configurationItem.notificationGroups)) {
					this.selectedNotificationGroups = this.configurationItem.notificationGroups.map(groupValue =>
						this.notificationGroupOptions.find(g => g.value === groupValue),
					).filter(Boolean)
				}

				// Load selected application
				if (this.configurationItem.application) {
					this.selectedApplication = applicationStore.applicationList.find(
						a => a.uuid === this.configurationItem.application,
					) || null
				}

				// Load existing entity selections
				this.loadExistingSelections()
			} else {
				// New configuration defaults
				this.selectedType = this.typeOptions[0]
				this.selectedSourceType = this.sourceTypeOptions[0]
			}
		},
		async loadExistingSelections() {
			const item = this.configurationItem

			// Load selected registers
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

			// Load selected schemas
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

			// Load selected objects
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

			// Load selected sources
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

			// Load selected agents
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

			// Load selected views
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

			// Load selected managed applications
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
		},
		resetForm() {
			this.configurationItem = {
				title: '',
				description: null,
				type: 'default',
				app: '',
				version: '1.0.0',
				application: '',
				owner: '',
				organisation: null,
				registers: [],
				schemas: [],
				objects: [],
				sources: [],
				agents: [],
				views: [],
				applications: [],
				sourceType: 'local',
				isLocal: true,
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
			this.selectedManagedApplications = []
			this.selectedApplication = null
			this.selectedType = this.typeOptions[0]
			this.selectedSourceType = this.sourceTypeOptions[0]
			this.selectedNotificationGroups = []
		},
		// Update methods for select fields
		updateType(value) {
			this.configurationItem.type = value ? value.value : 'default'
			this.selectedType = value
		},
		updateApplication(value) {
			this.configurationItem.application = value ? value.uuid : ''
			this.selectedApplication = value
		},
		updateRegisters(value) {
			this.configurationItem.registers = value.map(r => parseInt(r.id))
			this.selectedRegisters = value
		},
		updateSchemas(value) {
			this.configurationItem.schemas = value.map(s => parseInt(s.id))
			this.selectedSchemas = value
		},
		updateObjects(value) {
			this.configurationItem.objects = value.map(o => parseInt(o.id))
			this.selectedObjects = value
		},
		updateSources(value) {
			this.configurationItem.sources = value.map(s => parseInt(s.id))
			this.selectedSources = value
		},
		updateAgents(value) {
			this.configurationItem.agents = value.map(a => parseInt(a.id))
			this.selectedAgents = value
		},
		updateViews(value) {
			this.configurationItem.views = value.map(v => parseInt(v.id))
			this.selectedViews = value
		},
		updateManagedApplications(value) {
			this.configurationItem.applications = value.map(a => parseInt(a.id))
			this.selectedManagedApplications = value
		},
		updateSourceType(value) {
			this.configurationItem.sourceType = value ? value.value : 'local'
			this.selectedSourceType = value
		},
		updateNotificationGroups(value) {
			this.configurationItem.notificationGroups = value.map(g => g.value)
			this.selectedNotificationGroups = value
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
					const params = new URLSearchParams()
					params.append('_search', query)
					params.append('_limit', '10')
					if (this.selectedRegisters.length > 0) {
						this.selectedRegisters.forEach(register => {
							params.append('_register[]', register.id)
						})
					}
					if (this.selectedSchemas.length > 0) {
						this.selectedSchemas.forEach(schema => {
							params.append('_schema[]', schema.id)
						})
					}
					const response = await fetch(`/index.php/apps/openregister/api/objects?${params.toString()}`)
					const data = await response.json()
					this.objectOptions = data.results || data || []
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
		closeModal() {
			this.selectedRegisters = []
			this.selectedSchemas = []
			this.selectedObjects = []
			this.selectedSources = []
			this.selectedAgents = []
			this.selectedViews = []
			this.selectedApplication = null
			navigationStore.setModal(false)
		},
		async saveConfiguration() {
			if (!this.configurationItem.title.trim()) {
				this.$refs.dialog.setResult({ error: 'Configuration title is required' })
				return
			}

			try {
				const { response } = await configurationStore.saveConfiguration({
					...this.configurationItem,
				})

				if (response.ok) {
					this.$refs.dialog.setResult({ success: true })
				}
			} catch (error) {
				console.error('Error saving configuration:', error)
				this.$refs.dialog.setResult({
					error: error.message || 'An error occurred while saving the configuration',
				})
			}
		},
	},
}
</script>

<style scoped>
.field-hint {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.selectField,
.checkboxField {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.selectField label {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

/* Dropdown option styles */
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

.option-meta {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
