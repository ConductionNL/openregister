<script setup>
import { translate as t } from '@nextcloud/l10n'
import {
	applicationStore,
	configurationStore,
	navigationStore,
	organisationStore,
} from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.modal === 'editConfiguration'"
		:name="
			configurationStore.configurationItem?.id
				? t('openregister', 'Edit Configuration')
				: t('openregister', 'New Configuration')
		"
		size="large"
		:open="true"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div class="tabContainer">
			<AppTabs v-model="activeTab" contentClass="mt-3" justified>
				<!-- Settings Tab -->
				<AppTab active>
					<template #title>
						<Cog :size="16" />
						<span>{{ t('openregister', 'Settings') }}</span>
					</template>

					<div class="form-editor">
						<NcTextField
							:label="t('openregister', 'Title *')"
							:placeholder="
								t('openregister', 'Enter configuration title')
							"
							:modelValue="
								configurationStore.configurationItem?.title || ''
							"
							:error="
								!configurationStore.configurationItem?.title?.trim?.()
							"
							@update:modelValue="updateTitle" />

						<NcTextArea
							:label="t('openregister', 'Description')"
							:placeholder="
								t(
									'openregister',
									'Enter configuration description (optional)',
								)
							"
							:modelValue="
								configurationStore.configurationItem?.description
								|| ''
							"
							@update:modelValue="updateDescription" />

						<FormSelectField
							id="type-select"
							v-model="selectedType"
							:title="t('openregister', 'Type')"
							:hint="t('openregister', 'Configuration type (default, application, etc.)')"
							inputLabel="Selected Type"
							:options="typeOptions"
							label="label"
							trackBy="value"
							:labelOutside="true"
							:placeholder="
								t('openregister', 'Select configuration type...')
							"
							@update:modelValue="updateType" />

						<NcTextField
							:label="t('openregister', 'App ID')"
							placeholder="myapp"
							:modelValue="
								configurationStore.configurationItem?.app || ''
							"
							@update:modelValue="updateApp">
							<template #helper-text-message>
								<p>
									{{
										t(
											'openregister',
											'Application identifier for this configuration (optional)',
										)
									}}
								</p>
							</template>
						</NcTextField>

						<!-- Organisation is automatically set to active organisation by backend -->
					</div>
				</AppTab>

				<!-- Configuration Tab -->
				<AppTab>
					<template #title>
						<Database :size="16" />
						<span>{{ t('openregister', 'Configuration') }}</span>
					</template>

					<div class="form-editor">
						<FormSelectField
							id="registers-select"
							v-model="selectedRegisters"
							:title="t('openregister', 'Registers')"
							inputLabel="Selected Registers"
							:options="registerOptions"
							:loading="loadingRegisters"
							:multiple="true"
							label="title"
							trackBy="id"
							:labelOutside="true"
							:filterable="true"
							:placeholder="
								t('openregister', 'Search registers...')
							"
							:closeOnSelect="false"
							@searchChange="searchRegisters"
							@update:modelValue="updateRegisters">
							<template #noOptions>
								<span v-if="loadingRegisters">{{
									t('openregister', 'Searching...')
								}}</span>
								<span v-else>{{
									t('openregister', 'No registers found')
								}}</span>
							</template>
							<template #hint>
								{{ selectedRegisters.length }} register(s) selected
							</template>
						</FormSelectField>

						<FormSelectField
							id="schemas-select"
							v-model="selectedSchemas"
							:title="t('openregister', 'Schemas')"
							inputLabel="Selected Schemas"
							:options="schemaOptions"
							:loading="loadingSchemas"
							:multiple="true"
							label="title"
							trackBy="id"
							:labelOutside="true"
							:filterable="true"
							:placeholder="t('openregister', 'Search schemas...')"
							:closeOnSelect="false"
							@searchChange="searchSchemas"
							@update:modelValue="updateSchemas">
							<template #noOptions>
								<span v-if="loadingSchemas">{{
									t('openregister', 'Searching...')
								}}</span>
								<span v-else>{{
									t('openregister', 'No schemas found')
								}}</span>
							</template>
							<template #hint>
								{{ selectedSchemas.length }} schema(s) selected
							</template>
						</FormSelectField>

						<FormSelectField
							id="objects-select"
							v-model="selectedObjects"
							:title="t('openregister', 'Objects')"
							inputLabel="Selected Objects"
							:options="objectOptions"
							:loading="loadingObjects"
							:multiple="true"
							label="title"
							trackBy="id"
							:labelOutside="true"
							:filterable="true"
							:placeholder="t('openregister', 'Search objects...')"
							:closeOnSelect="false"
							:disabled="
								selectedRegisters.length === 0
								&& selectedSchemas.length === 0
							"
							@searchChange="searchObjects"
							@update:modelValue="updateObjects">
							<template #noOptions>
								<span v-if="loadingObjects">{{
									t('openregister', 'Searching...')
								}}</span>
								<span
									v-else-if="
										selectedRegisters.length === 0
										&& selectedSchemas.length === 0
									"
									>{{
										t(
											'openregister',
											'Please select registers or schemas first',
										)
									}}</span
								>
								<span v-else>{{
									t('openregister', 'No objects found')
								}}</span>
							</template>
							<template #hint>
								{{ selectedObjects.length }} object(s) selected
								<span
									v-if="
										selectedRegisters.length === 0
										&& selectedSchemas.length === 0
									">
									- filtered by selected registers/schemas</span
								>
							</template>
						</FormSelectField>

						<FormSelectField
							id="sources-select"
							v-model="selectedSources"
							:title="t('openregister', 'Data Sources')"
							inputLabel="Selected Sources"
							:options="sourceOptions"
							:loading="loadingSources"
							:multiple="true"
							label="title"
							trackBy="id"
							:labelOutside="true"
							:filterable="true"
							:placeholder="
								t('openregister', 'Search data sources...')
							"
							:closeOnSelect="false"
							@searchChange="searchSources"
							@update:modelValue="updateSources">
							<template #noOptions>
								<span v-if="loadingSources">{{
									t('openregister', 'Searching...')
								}}</span>
								<span v-else>{{
									t('openregister', 'No sources found')
								}}</span>
							</template>
							<template #hint>
								{{ selectedSources.length }} source(s) selected
							</template>
						</FormSelectField>

						<FormSelectField
							id="agents-select"
							v-model="selectedAgents"
							:title="t('openregister', 'Agents')"
							inputLabel="Selected Agents"
							:options="agentOptions"
							:loading="loadingAgents"
							:multiple="true"
							label="name"
							trackBy="id"
							:labelOutside="true"
							:filterable="true"
							:placeholder="t('openregister', 'Search agents...')"
							:closeOnSelect="false"
							@searchChange="searchAgents"
							@update:modelValue="updateAgents">
							<template #noOptions>
								<span v-if="loadingAgents">{{
									t('openregister', 'Searching...')
								}}</span>
								<span v-else>{{
									t('openregister', 'No agents found')
								}}</span>
							</template>
							<template #hint>
								{{ selectedAgents.length }} agent(s) selected
							</template>
						</FormSelectField>

						<FormSelectField
							id="views-select"
							v-model="selectedViews"
							:title="t('openregister', 'Views')"
							inputLabel="Selected Views"
							:options="viewOptions"
							:loading="loadingViews"
							:multiple="true"
							label="name"
							trackBy="id"
							:labelOutside="true"
							:filterable="true"
							:placeholder="t('openregister', 'Search views...')"
							:closeOnSelect="false"
							@searchChange="searchViews"
							@update:modelValue="updateViews">
							<template #noOptions>
								<span v-if="loadingViews">Searching...</span>
								<span v-else>No views found</span>
							</template>
							<template #hint>
								{{ selectedViews.length }} view(s) selected
							</template>
						</FormSelectField>

						<FormSelectField
							id="managed-applications-select"
							v-model="selectedManagedApplications"
							title="Applications"
							inputLabel="Selected Managed Applications"
							:options="applicationOptions"
							:loading="loadingApplications"
							:multiple="true"
							label="name"
							trackBy="id"
							:labelOutside="true"
							:filterable="true"
							:placeholder="
								t('openregister', 'Search applications...')
							"
							:closeOnSelect="false"
							@searchChange="searchApplications"
							@update:modelValue="updateManagedApplications">
							<template #noOptions>
								<span v-if="loadingApplications"
									>Searching...</span
								>
								<span v-else>No applications found</span>
							</template>
							<template #hint>
								{{ selectedManagedApplications.length }}
								application(s) selected
							</template>
						</FormSelectField>
					</div>
				</AppTab>

				<!-- Management Tab -->
				<AppTab>
					<template #title>
						<CloudSync :size="16" />
						<span>Management</span>
					</template>

					<div class="form-editor">
						<FormSelectField
							id="source-type-select"
							v-model="selectedSourceType"
							title="Source Type *"
							inputLabel="Selected Source Type"
							:options="sourceTypeOptions"
							label="label"
							trackBy="value"
							:labelOutside="true"
							:placeholder="
								t('openregister', 'Select source type...')
							"
							@update:modelValue="updateSourceType">
							<template #hint>
								Determines where the configuration is managed
							</template>
						</FormSelectField>

						<NcTextField
							v-if="
								selectedSourceType
								&& selectedSourceType.value !== 'local'
							"
							label="Source URL"
							placeholder="https://raw.githubusercontent.com/..."
							:modelValue="
								configurationStore.configurationItem?.sourceUrl || ''
							"
							@update:modelValue="updateSourceUrl">
							<template #helper-text-message>
								<p>
									The URL to the remote configuration file (JSON or
									YAML)
								</p>
							</template>
						</NcTextField>

						<NcTextField
							label="Version"
							placeholder="1.0.0"
							:modelValue="
								configurationStore.configurationItem?.version || ''
							"
							@update:modelValue="updateVersion">
							<template #helper-text-message>
								<p>Semantic version (e.g., 1.0.0, 2.1.3)</p>
							</template>
						</NcTextField>

						<FormSelectField
							id="application-select"
							v-model="selectedApplication"
							title="Owner Application"
							inputLabel="Selected Application"
							:options="applicationOptions"
							label="name"
							trackBy="id"
							:labelOutside="true"
							:placeholder="
								t(
									'openregister',
									'Select owner application (optional)...',
								)
							"
							@update:modelValue="updateApplication">
							<template #hint>
								<template v-if="selectedApplication">
									Owner: {{ selectedApplication.name }}
							</template>
							<template v-else>
								The application that owns this configuration
								(optional)
							</template>
							</template>
						</FormSelectField>

						<NcTextField
							label="Local Version"
							placeholder="1.0.0"
							:modelValue="
								configurationStore.configurationItem?.localVersion
								|| ''
							"
							@update:modelValue="updateLocalVersion">
							<template #helper-text-message>
								<p>
									Current version installed locally (semantic
									versioning)
								</p>
							</template>
						</NcTextField>

						<NcTextField
							v-if="
								configurationStore.configurationItem?.remoteVersion
							"
							label="Remote Version (Read-only)"
							:modelValue="
								configurationStore.configurationItem?.remoteVersion
								|| '-'
							"
							:disabled="true">
							<template #helper-text-message>
								<p>Last checked version from remote source</p>
							</template>
						</NcTextField>

						<FormCheckboxField
							:modelValue="
								configurationStore.configurationItem?.autoUpdate
								|| false
							"
							hint="Automatically import updates when a new version is detected"
							@update:modelValue="updateAutoUpdate">
							Enable Auto-Update
						</FormCheckboxField>

						<FormSelectField
							id="notification-groups-select"
							v-model="selectedNotificationGroups"
							title="Notification Groups"
							inputLabel="Selected Notification Groups"
							:options="notificationGroupOptions"
							:multiple="true"
							label="label"
							trackBy="value"
							:labelOutside="true"
							:placeholder="
								t('openregister', 'Select groups to notify...')
							"
							:closeOnSelect="false"
							@update:modelValue="updateNotificationGroups">
							<template #option="{ label }">
								<span>{{ label }}</span>
							</template>
							<template #hint>
								Groups that will receive notifications when updates
								are available (admin is always included)
							</template>
						</FormSelectField>

						<template
							v-if="
								selectedSourceType
								&& selectedSourceType.value === 'github'
							">
							<NcNoteCard
								type="info">
								<p>GitHub Integration Settings</p>
							</NcNoteCard>

							<NcTextField
								label="GitHub Repository"
								placeholder="owner/repository"
								:modelValue="
									configurationStore.configurationItem?.githubRepo
									|| ''
								"
								@update:modelValue="updateGithubRepo">
								<template #helper-text-message>
									<p>Repository in format: owner/repo</p>
								</template>
							</NcTextField>

							<NcTextField
								label="GitHub Branch"
								placeholder="main"
								:modelValue="
									configurationStore.configurationItem?.githubBranch
									|| 'main'
								"
								@update:modelValue="updateGithubBranch">
								<template #helper-text-message>
									<p>Branch to push/pull configurations</p>
								</template>
							</NcTextField>

							<NcTextField
								label="GitHub Path"
								placeholder="configs/configuration.json"
								:modelValue="
									configurationStore.configurationItem?.githubPath
									|| ''
								"
								@update:modelValue="updateGithubPath">
								<template #helper-text-message>
									<p>
										Path within the repository for the configuration
										file
									</p>
								</template>
							</NcTextField>
						</template>
					</div>
				</AppTab>
			</AppTabs>
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
				variant="primary"
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
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import CloudSync from 'vue-material-design-icons/CloudSync.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Database from 'vue-material-design-icons/Database.vue'
import FormCheckboxField from '../../components/shared/FormCheckboxField.vue'
import FormSelectField from '../../components/shared/FormSelectField.vue'
import AppTab from '../../components/tabs/AppTab.vue'
import AppTabs from '../../components/tabs/AppTabs.vue'
import { freshState } from '../../services/initialState.js'
import { optionSearchMethods, searchUrl } from './optionSearch.js'

/**
 * The entities a configuration links to: its key and API path, the selection
 * it fills and the loading flag shown while it is fetched.
 */
const LINKED_ENTITY_SELECTIONS = [
	{ key: 'registers', target: 'selectedRegisters', loading: 'loadingRegisters' },
	{ key: 'schemas', target: 'selectedSchemas', loading: 'loadingSchemas' },
	{ key: 'objects', target: 'selectedObjects', loading: 'loadingObjects' },
	{ key: 'sources', target: 'selectedSources', loading: 'loadingSources' },
	{ key: 'agents', target: 'selectedAgents', loading: 'loadingAgents' },
	{ key: 'views', target: 'selectedViews', loading: 'loadingViews' },
	{
		key: 'applications',
		target: 'selectedManagedApplications',
		loading: 'loadingApplications',
	},
]

/**
 * Whether a store list is missing or empty.
 *
 * @param {Array|null} list The list
 * @return {boolean} True when there is nothing in it
 */
function isEmptyList(list) {
	return !list || list.length === 0
}

/**
 * A blank configuration with the defaults a new one starts from.
 *
 * @return {object} The configuration
 */
function newConfiguration() {
	return {
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
}

/**
 * The state every instance of this component starts from.
 */
const INITIAL_STATE = {
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

/**
 * The debounced option searches of the entity selects: the fields each one
 * fills and the URL it asks. Objects are narrowed to the selected registers
 * and schemas.
 *
 * @spec exclude Debounced autocomplete fetch populating select options; UI search plumbing.
 */
const OPTION_SEARCHES = {
	searchRegisters: {
		timer: 'registerSearchDebounce',
		loading: 'loadingRegisters',
		options: 'registerOptions',
		selected: 'selectedRegisters',
		label: 'registers',
		url: (query) => searchUrl('registers', query),
	},
	searchSchemas: {
		timer: 'schemaSearchDebounce',
		loading: 'loadingSchemas',
		options: 'schemaOptions',
		selected: 'selectedSchemas',
		label: 'schemas',
		url: (query) => searchUrl('schemas', query),
	},
	searchObjects: {
		timer: 'objectSearchDebounce',
		loading: 'loadingObjects',
		options: 'objectOptions',
		selected: 'selectedObjects',
		label: 'objects',
		url: (query, vm) => {
			const params = new URLSearchParams()
			params.append('_search', query)
			params.append('_limit', '10')
			vm.selectedRegisters.forEach((register) => {
				params.append('_register[]', register.id)
			})
			vm.selectedSchemas.forEach((schema) => {
				params.append('_schema[]', schema.id)
			})
			return `/index.php/apps/openregister/api/objects?${params.toString()}`
		},
	},
	searchSources: {
		timer: 'sourceSearchDebounce',
		loading: 'loadingSources',
		options: 'sourceOptions',
		selected: 'selectedSources',
		label: 'sources',
		url: (query) => searchUrl('sources', query),
	},
	searchAgents: {
		timer: 'agentSearchDebounce',
		loading: 'loadingAgents',
		options: 'agentOptions',
		selected: 'selectedAgents',
		label: 'agents',
		url: (query) => searchUrl('agents', query),
	},
	searchViews: {
		timer: 'viewSearchDebounce',
		loading: 'loadingViews',
		options: 'viewOptions',
		selected: 'selectedViews',
		label: 'views',
		url: (query) => searchUrl('views', query),
	},
	searchApplications: {
		timer: 'applicationSearchDebounce',
		loading: 'loadingApplications',
		options: 'applicationOptions',
		selected: 'selectedManagedApplications',
		label: 'applications',
		url: (query) => searchUrl('applications', query),
	},
}

export default {
	name: 'EditConfiguration',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		NcTextArea,
		AppTabs,
		FormCheckboxField,
		FormSelectField,
		AppTab,
		// Icons
		Cancel,
		ContentSave,
		Cog,
		Database,
		CloudSync,
	},

	data() {
		return freshState(INITIAL_STATE)
	},

	computed: {
		/**
		 * @spec exclude Computed form-validity guard (title non-empty); UI validation helper.
		 */
		isValid() {
			const item = configurationStore.configurationItem
			return Boolean(item?.title?.trim())
		},

		/**
		 * @spec exclude Computed static select-option list; UI presentation data.
		 */
		sourceTypeOptions() {
			return [
				{
					value: 'local',
					label: 'Local',
					description: 'Manually managed configuration',
				},
				{
					value: 'github',
					label: 'GitHub',
					description: 'Configuration from GitHub repository',
				},
				{
					value: 'gitlab',
					label: 'GitLab',
					description: 'Configuration from GitLab repository',
				},
				{
					value: 'url',
					label: 'URL',
					description: 'Configuration from any URL',
				},
			]
		},

		/**
		 * @spec exclude Computed static select-option list; UI presentation data.
		 */
		typeOptions() {
			return [
				{
					value: 'default',
					label: 'Default',
					description: 'Standard configuration type',
				},
				{
					value: 'application',
					label: 'Application',
					description: 'Application-specific configuration',
				},
				{
					value: 'manual',
					label: 'Manual',
					description: 'Manually created configuration',
				},
			]
		},

		/**
		 * @spec exclude Computed static select-option list (placeholder groups); UI presentation data.
		 */
		notificationGroupOptions() {
			// In a real implementation, this would fetch from Nextcloud groups API
			// For now, return common groups
			return [
				{ value: 'admin', label: 'Administrators' },
				{ value: 'users', label: 'All Users' },
			]
		},
	},

	/**
	 * @spec exclude Vue created() lifecycle hook — hydrates store lists and seeds a blank configurationItem; modal init plumbing.
	 */
	async created() {
		// Organisations and applications are now hot-loaded at app startup
		// Only refresh if somehow they're empty (shouldn't happen in normal flow)
		if (isEmptyList(organisationStore.organisationList)) {
			organisationStore.refreshOrganisationList()
		}
		if (isEmptyList(applicationStore.applicationList)) {
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
			configurationStore.configurationItem = newConfiguration()
			this.resetSelections()
		} else {
			// Load existing selections
			this.loadExistingSelections()
		}
	},

	methods: {
		/**
		 * @param {string} value
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		updateTitle(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.title = value
		},

		/**
		 * @param {string} value
		 * @spec exclude Reactive form-field setter binding input to configurationStore.configurationItem; UI plumbing.
		 */
		updateDescription(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.description = value
		},

		/**
		 * @param {string} value
		 * @spec exclude Reactive form-field setter binding input to configurationStore.configurationItem; UI plumbing.
		 */
		updateVersion(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.version = value
		},

		/**
		 * @param {object|null} value
		 * @spec exclude Reactive form-field setter binding select value to configurationStore.configurationItem; UI plumbing.
		 */
		updateType(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.type = value
				? value.value
				: 'default'
			this.selectedType = value
		},

		/**
		 * @param {string} value
		 * @spec exclude Reactive form-field setter binding input to configurationStore.configurationItem; UI plumbing.
		 */
		updateApp(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.app = value || ''
		},

		/**
		 * @param {object|null} value
		 * @spec exclude Reactive form-field setter binding selected application to configurationStore.configurationItem; UI plumbing.
		 */
		updateApplication(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Store the application UUID
			configurationStore.configurationItem.application = value
				? value.uuid
				: ''
			this.selectedApplication = value
		},

		/**
		 * @param {Array<object>} value
		 * @spec exclude Reactive multi-select setter mapping selected registers to IDs on configurationItem; UI plumbing.
		 */
		updateRegisters(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Extract IDs from selected register objects
			configurationStore.configurationItem.registers = value.map((r) =>
				parseInt(r.id),
			)
			this.selectedRegisters = value
		},

		/**
		 * @param {Array<object>} value
		 * @spec exclude Reactive multi-select setter mapping selected schemas to IDs on configurationItem; UI plumbing.
		 */
		updateSchemas(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Extract IDs from selected schema objects
			configurationStore.configurationItem.schemas = value.map((s) =>
				parseInt(s.id),
			)
			this.selectedSchemas = value
		},

		/**
		 * @param {Array<object>} value
		 * @spec exclude Reactive multi-select setter mapping selected objects to IDs on configurationItem; UI plumbing.
		 */
		updateObjects(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Extract IDs from selected object objects
			configurationStore.configurationItem.objects = value.map((o) =>
				parseInt(o.id),
			)
			this.selectedObjects = value
		},

		/**
		 * @param {Array<object>} value
		 * @spec exclude Reactive multi-select setter mapping selected sources to IDs on configurationItem; UI plumbing.
		 */
		updateSources(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Extract IDs from selected source objects
			configurationStore.configurationItem.sources = value.map((s) =>
				parseInt(s.id),
			)
			this.selectedSources = value
		},

		/**
		 * @param {Array<object>} value
		 * @spec exclude Reactive multi-select setter mapping selected agents to IDs on configurationItem; UI plumbing.
		 */
		updateAgents(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Extract IDs from selected agent objects
			configurationStore.configurationItem.agents = value.map((a) =>
				parseInt(a.id),
			)
			this.selectedAgents = value
		},

		/**
		 * @param {Array<object>} value
		 * @spec exclude Reactive multi-select setter mapping selected views to IDs on configurationItem; UI plumbing.
		 */
		updateViews(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Extract IDs from selected view objects
			configurationStore.configurationItem.views = value.map((v) =>
				parseInt(v.id),
			)
			this.selectedViews = value
		},

		/**
		 * @param {Array<object>} value
		 * @spec exclude Reactive multi-select setter mapping managed applications to IDs on configurationItem; UI plumbing.
		 */
		updateManagedApplications(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Extract IDs from selected application objects
			configurationStore.configurationItem.applications = value.map((a) =>
				parseInt(a.id),
			)
			this.selectedManagedApplications = value
		},

		// Management tab update methods
		/**
		 * @param {object|null} value
		 * @spec exclude Reactive form-field setter binding source-type select to configurationItem; UI plumbing.
		 */
		updateSourceType(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.sourceType = value
				? value.value
				: 'local'
			this.selectedSourceType = value
		},

		/**
		 * @param {string} value
		 * @spec exclude Reactive form-field setter binding input to configurationItem; UI plumbing.
		 */
		updateSourceUrl(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.sourceUrl = value
		},

		/**
		 * @param {string} value
		 * @spec exclude Reactive form-field setter binding input to configurationItem; UI plumbing.
		 */
		updateLocalVersion(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.localVersion = value
		},

		/**
		 * @param {boolean} value
		 * @spec exclude Reactive form-field setter binding toggle to configurationItem; UI plumbing.
		 */
		updateAutoUpdate(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.autoUpdate = value
		},

		/**
		 * @param {Array<object>} value
		 * @spec exclude Reactive multi-select setter mapping notification groups to values on configurationItem; UI plumbing.
		 */
		updateNotificationGroups(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.notificationGroups = value.map(
				(g) => g.value,
			)
			this.selectedNotificationGroups = value
		},

		/**
		 * @param {string} value
		 * @spec exclude Reactive form-field setter binding input to configurationItem; UI plumbing.
		 */
		updateGithubRepo(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.githubRepo = value
		},

		/**
		 * @param {string} value
		 * @spec exclude Reactive form-field setter binding input to configurationItem; UI plumbing.
		 */
		updateGithubBranch(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.githubBranch = value
		},

		/**
		 * @param {string} value
		 * @spec exclude Reactive form-field setter binding input to configurationItem; UI plumbing.
		 */
		updateGithubPath(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.githubPath = value
		},

		/**
		 * Empty every selection for a new configuration.
		 *
		 * @spec exclude Resets local select-model refs when the modal opens on a new configuration; modal init plumbing.
		 * @return {void}
		 */
		resetSelections() {
			this.clearSelections()
			this.selectedType = this.typeOptions[0] // 'default'
			// Management tab defaults
			this.selectedSourceType = this.sourceTypeOptions[0] // 'local'
			this.selectedNotificationGroups = []
		},

		/**
		 * @spec exclude Hydrates local select-model refs from an existing configurationItem on edit-open; modal init plumbing.
		 */
		async loadExistingSelections() {
			const item = configurationStore.configurationItem
			if (!item) {
				return
			}
			this.loadSettingsSelections(item)
			for (const link of LINKED_ENTITY_SELECTIONS) {
				await this.loadLinkedSelection(item, link)
			}
		},

		/**
		 * @param {object} item The configuration being edited.
		 * @spec exclude Hydrates the settings and management select-models from a configurationItem; modal init plumbing.
		 */
		loadSettingsSelections(item) {
			if (item.application) {
				this.selectedApplication =
					applicationStore.applicationList.find(
						(a) => a.uuid === item.application,
					) || null
			}
			// Organisation is automatically set by backend based on active organisation

			this.selectedType =
				(item.type && this.typeOptions.find((t) => t.value === item.type))
				|| this.typeOptions[0] // Default to 'default'

			if (item.sourceType) {
				this.selectedSourceType =
					this.sourceTypeOptions.find((st) => st.value === item.sourceType)
					|| null
			}
			if (Array.isArray(item.notificationGroups)) {
				this.selectedNotificationGroups = item.notificationGroups
					.map((groupValue) =>
						this.notificationGroupOptions.find(
							(g) => g.value === groupValue,
						),
					)
					.filter(Boolean)
			}
		},

		/**
		 * Fetch the entities a configuration links to, one by one, into their selection.
		 *
		 * @param {object} item The configuration being edited.
		 * @param {object} link An entry of LINKED_ENTITY_SELECTIONS.
		 * @spec exclude Hydrates one linked-entity select-model from a configurationItem; modal init plumbing.
		 */
		async loadLinkedSelection(item, link) {
			const ids = item[link.key]
			if (!Array.isArray(ids) || ids.length === 0) {
				return
			}
			this[link.loading] = true
			try {
				const promises = ids.map((id) =>
					fetch(`/index.php/apps/openregister/api/${link.key}/${id}`).then(
						(r) => r.json(),
					),
				)
				this[link.target] = await Promise.all(promises)
			} catch (error) {
				console.error(`Error loading selected ${link.key}:`, error)
			} finally {
				this[link.loading] = false
			}
		},

		// Search methods with debouncing
		...optionSearchMethods(OPTION_SEARCHES),

		/**
		 * @spec exclude Dialog close-event passthrough to closeModal; UI plumbing.
		 */
		handleDialogClose() {
			this.closeModal()
		},

		/**
		 * @spec exclude Modal close handler resetting navigationStore.modal and local select-model refs; UI plumbing.
		 */
		closeModal() {
			navigationStore.setModal(false)
			this.loading = false
			this.error = null
			this.clearSelections()
		},

		/**
		 * Empty the settings selections both a close and a reset clear.
		 *
		 * @spec exclude Resets local select-model refs; modal plumbing.
		 * @return {void}
		 */
		clearSelections() {
			this.selectedRegisters = []
			this.selectedSchemas = []
			this.selectedObjects = []
			this.selectedSources = []
			this.selectedAgents = []
			this.selectedViews = []
			this.selectedApplication = null
		},

		/**
		 * @spec exclude Save-button handler delegating to configurationStore.saveConfiguration; entity persistence lives in the store, this is modal orchestration plumbing.
		 */
		async saveConfiguration() {
			this.loading = true
			this.error = null

			try {
				await configurationStore.saveConfiguration(
					configurationStore.configurationItem,
				)
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

<style lang="scss" scoped>
@use '../../styles/modals.scss' as *;
@include tab-container-column;
</style>

<style lang="scss">
@use '../../styles/modals.scss' as *;

/* Tab styling - must be unscoped to affect Bootstrap Vue components */
@include nav-tabs-link;
</style>
