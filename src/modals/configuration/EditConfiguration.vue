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
		:canClose="true"
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

						<div class="selectField">
							<label for="type-select">{{
								t('openregister', 'Type')
							}}</label>
							<NcSelect
								id="type-select"
								v-model="selectedType"
								inputLabel="Selected Type"
								:options="typeOptions"
								label="label"
								trackBy="value"
								:labelOutside="true"
								:placeholder="
									t('openregister', 'Select configuration type...')
								"
								@update:modelValue="updateType">
								<template #option="{ label, description }">
									<div class="option-content">
										<span class="option-title">{{ label }}</span>
										<span
											v-if="description"
											class="option-description"
											>{{ description }}</span
										>
									</div>
								</template>
							</NcSelect>
							<p class="field-hint">
								{{
									t(
										'openregister',
										'Configuration type (default, application, etc.)',
									)
								}}
							</p>
						</div>

						<NcTextField
							:label="t('openregister', 'App ID')"
							placeholder="myapp"
							:model-value="
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
						<div class="selectField">
							<label for="registers-select">{{
								t('openregister', 'Registers')
							}}</label>
							<NcSelect
								id="registers-select"
								v-model="selectedRegisters"
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
								<template #option="{ title, description }">
									<div class="option-content">
										<span class="option-title">{{ title }}</span>
										<span
											v-if="description"
											class="option-description"
											>{{ description }}</span
										>
									</div>
								</template>
								<template #no-options>
									<span v-if="loadingRegisters">{{
										t('openregister', 'Searching...')
									}}</span>
									<span v-else>{{
										t('openregister', 'No registers found')
									}}</span>
								</template>
							</NcSelect>
							<p class="field-hint">
								{{ selectedRegisters.length }} register(s) selected
							</p>
						</div>

						<div class="selectField">
							<label for="schemas-select">{{
								t('openregister', 'Schemas')
							}}</label>
							<NcSelect
								id="schemas-select"
								v-model="selectedSchemas"
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
								<template #option="{ title, description }">
									<div class="option-content">
										<span class="option-title">{{ title }}</span>
										<span
											v-if="description"
											class="option-description"
											>{{ description }}</span
										>
									</div>
								</template>
								<template #no-options>
									<span v-if="loadingSchemas">{{
										t('openregister', 'Searching...')
									}}</span>
									<span v-else>{{
										t('openregister', 'No schemas found')
									}}</span>
								</template>
							</NcSelect>
							<p class="field-hint">
								{{ selectedSchemas.length }} schema(s) selected
							</p>
						</div>

						<div class="selectField">
							<label for="objects-select">{{
								t('openregister', 'Objects')
							}}</label>
							<NcSelect
								id="objects-select"
								v-model="selectedObjects"
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
								<template #option="{ title, description }">
									<div class="option-content">
										<span class="option-title">{{ title }}</span>
										<span
											v-if="description"
											class="option-description"
											>{{ description }}</span
										>
									</div>
								</template>
								<template #no-options>
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
							</NcSelect>
							<p class="field-hint">
								{{ selectedObjects.length }} object(s) selected
								<span
									v-if="
										selectedRegisters.length === 0
										&& selectedSchemas.length === 0
									">
									- filtered by selected registers/schemas</span
								>
							</p>
						</div>

						<div class="selectField">
							<label for="sources-select">{{
								t('openregister', 'Data Sources')
							}}</label>
							<NcSelect
								id="sources-select"
								v-model="selectedSources"
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
								<template #option="{ title, description }">
									<div class="option-content">
										<span class="option-title">{{ title }}</span>
										<span
											v-if="description"
											class="option-description"
											>{{ description }}</span
										>
									</div>
								</template>
								<template #no-options>
									<span v-if="loadingSources">{{
										t('openregister', 'Searching...')
									}}</span>
									<span v-else>{{
										t('openregister', 'No sources found')
									}}</span>
								</template>
							</NcSelect>
							<p class="field-hint">
								{{ selectedSources.length }} source(s) selected
							</p>
						</div>

						<div class="selectField">
							<label for="agents-select">{{
								t('openregister', 'Agents')
							}}</label>
							<NcSelect
								id="agents-select"
								v-model="selectedAgents"
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
								<template #option="{ name, description }">
									<div class="option-content">
										<span class="option-title">{{ name }}</span>
										<span
											v-if="description"
											class="option-description"
											>{{ description }}</span
										>
									</div>
								</template>
								<template #no-options>
									<span v-if="loadingAgents">{{
										t('openregister', 'Searching...')
									}}</span>
									<span v-else>{{
										t('openregister', 'No agents found')
									}}</span>
								</template>
							</NcSelect>
							<p class="field-hint">
								{{ selectedAgents.length }} agent(s) selected
							</p>
						</div>

						<div class="selectField">
							<label for="views-select">{{
								t('openregister', 'Views')
							}}</label>
							<NcSelect
								id="views-select"
								v-model="selectedViews"
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
								<template #option="{ name, description }">
									<div class="option-content">
										<span class="option-title">{{ name }}</span>
										<span
											v-if="description"
											class="option-description"
											>{{ description }}</span
										>
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
							<label for="managed-applications-select"
								>Applications</label
							>
							<NcSelect
								id="managed-applications-select"
								v-model="selectedManagedApplications"
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
								<template #option="{ name, description }">
									<div class="option-content">
										<span class="option-title">{{ name }}</span>
										<span
											v-if="description"
											class="option-description"
											>{{ description }}</span
										>
									</div>
								</template>
								<template #no-options>
									<span v-if="loadingApplications"
										>Searching...</span
									>
									<span v-else>No applications found</span>
								</template>
							</NcSelect>
							<p class="field-hint">
								{{ selectedManagedApplications.length }}
								application(s) selected
							</p>
						</div>
					</div>
				</AppTab>

				<!-- Management Tab -->
				<AppTab>
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
								inputLabel="Selected Source Type"
								:options="sourceTypeOptions"
								label="label"
								trackBy="value"
								:labelOutside="true"
								:placeholder="
									t('openregister', 'Select source type...')
								"
								@update:modelValue="updateSourceType">
								<template #option="{ label, description }">
									<div class="option-content">
										<span class="option-title">{{ label }}</span>
										<span
											v-if="description"
											class="option-description"
											>{{ description }}</span
										>
									</div>
								</template>
							</NcSelect>
							<p class="field-hint">
								Determines where the configuration is managed
							</p>
						</div>

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

						<div class="selectField">
							<label for="application-select">Owner Application</label>
							<NcSelect
								id="application-select"
								v-model="selectedApplication"
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
								<template #option="{ name, description }">
									<div class="option-content">
										<span class="option-title">{{ name }}</span>
										<span
											v-if="description"
											class="option-description"
											>{{ description }}</span
										>
									</div>
								</template>
							</NcSelect>
							<p v-if="selectedApplication" class="field-hint">
								Owner: {{ selectedApplication.name }}
							</p>
							<p v-else class="field-hint">
								The application that owns this configuration
								(optional)
							</p>
						</div>

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

						<div class="checkboxField">
							<NcCheckboxRadioSwitch
								:modelValue="
									configurationStore.configurationItem?.autoUpdate
									|| false
								"
								@update:modelValue="updateAutoUpdate">
								Enable Auto-Update
							</NcCheckboxRadioSwitch>
							<p class="field-hint">
								Automatically import updates when a new version is
								detected
							</p>
						</div>

						<div class="selectField">
							<label for="notification-groups-select"
								>Notification Groups</label
							>
							<NcSelect
								id="notification-groups-select"
								v-model="selectedNotificationGroups"
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
							</NcSelect>
							<p class="field-hint">
								Groups that will receive notifications when updates
								are available (admin is always included)
							</p>
						</div>

						<NcNoteCard
							v-if="
								selectedSourceType
								&& selectedSourceType.value === 'github'
							"
							type="info">
							<p>GitHub Integration Settings</p>
						</NcNoteCard>

						<NcTextField
							v-if="
								selectedSourceType
								&& selectedSourceType.value === 'github'
							"
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
							v-if="
								selectedSourceType
								&& selectedSourceType.value === 'github'
							"
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
							v-if="
								selectedSourceType
								&& selectedSourceType.value === 'github'
							"
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
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import CloudSync from 'vue-material-design-icons/CloudSync.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Database from 'vue-material-design-icons/Database.vue'
import AppTab from '../../components/tabs/AppTab.vue'
import AppTabs from '../../components/tabs/AppTabs.vue'

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
		AppTabs,
		AppTab,
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
		if (
			!organisationStore.organisationList
			|| organisationStore.organisationList.length === 0
		) {
			organisationStore.refreshOrganisationList()
		}
		if (
			!applicationStore.applicationList
			|| applicationStore.applicationList.length === 0
		) {
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
		/**
		 * @param value
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		updateTitle(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.title = value
		},

		/**
		 * @param value
		 * @spec exclude Reactive form-field setter binding input to configurationStore.configurationItem; UI plumbing.
		 */
		updateDescription(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.description = value
		},

		/**
		 * @param value
		 * @spec exclude Reactive form-field setter binding input to configurationStore.configurationItem; UI plumbing.
		 */
		updateVersion(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.version = value
		},

		/**
		 * @param value
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
		 * @param value
		 * @spec exclude Reactive form-field setter binding input to configurationStore.configurationItem; UI plumbing.
		 */
		updateApp(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.app = value || ''
		},

		/**
		 * @param value
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
		 * @param value
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
		 * @param value
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
		 * @param value
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
		 * @param value
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
		 * @param value
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
		 * @param value
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
		 * @param value
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
		 * @param value
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
		 * @param value
		 * @spec exclude Reactive form-field setter binding input to configurationItem; UI plumbing.
		 */
		updateSourceUrl(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.sourceUrl = value
		},

		/**
		 * @param value
		 * @spec exclude Reactive form-field setter binding input to configurationItem; UI plumbing.
		 */
		updateLocalVersion(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.localVersion = value
		},

		/**
		 * @param value
		 * @spec exclude Reactive form-field setter binding toggle to configurationItem; UI plumbing.
		 */
		updateAutoUpdate(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.autoUpdate = value
		},

		/**
		 * @param value
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
		 * @param value
		 * @spec exclude Reactive form-field setter binding input to configurationItem; UI plumbing.
		 */
		updateGithubRepo(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.githubRepo = value
		},

		/**
		 * @param value
		 * @spec exclude Reactive form-field setter binding input to configurationItem; UI plumbing.
		 */
		updateGithubBranch(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.githubBranch = value
		},

		/**
		 * @param value
		 * @spec exclude Reactive form-field setter binding input to configurationItem; UI plumbing.
		 */
		updateGithubPath(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			configurationStore.configurationItem.githubPath = value
		},

		/**
		 * @spec exclude Hydrates local select-model refs from an existing configurationItem on edit-open; modal init plumbing.
		 */
		async loadExistingSelections() {
			const item = configurationStore.configurationItem
			if (item) {
				// Load selected application (from already loaded list)
				if (item.application) {
					this.selectedApplication =
						applicationStore.applicationList.find(
							(a) => a.uuid === item.application,
						) || null
				}
				// Organisation is automatically set by backend based on active organisation

				// Load Settings tab selections
				if (item.type) {
					this.selectedType =
						this.typeOptions.find((t) => t.value === item.type)
						|| this.typeOptions[0] // Default to 'default'
				} else {
					this.selectedType = this.typeOptions[0] // Default to 'default'
				}

				// Load Management tab selections
				if (item.sourceType) {
					this.selectedSourceType =
						this.sourceTypeOptions.find(
							(st) => st.value === item.sourceType,
						) || null
				}
				if (
					item.notificationGroups
					&& Array.isArray(item.notificationGroups)
				) {
					this.selectedNotificationGroups = item.notificationGroups
						.map((groupValue) =>
							this.notificationGroupOptions.find(
								(g) => g.value === groupValue,
							),
						)
						.filter(Boolean)
				}

				// Load selected registers by fetching them individually
				if (
					item.registers
					&& Array.isArray(item.registers)
					&& item.registers.length > 0
				) {
					this.loadingRegisters = true
					try {
						const promises = item.registers.map((id) =>
							fetch(
								`/index.php/apps/openregister/api/registers/${id}`,
							).then((r) => r.json()),
						)
						this.selectedRegisters = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected registers:', error)
					} finally {
						this.loadingRegisters = false
					}
				}

				// Load selected schemas by fetching them individually
				if (
					item.schemas
					&& Array.isArray(item.schemas)
					&& item.schemas.length > 0
				) {
					this.loadingSchemas = true
					try {
						const promises = item.schemas.map((id) =>
							fetch(
								`/index.php/apps/openregister/api/schemas/${id}`,
							).then((r) => r.json()),
						)
						this.selectedSchemas = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected schemas:', error)
					} finally {
						this.loadingSchemas = false
					}
				}

				// Load selected objects by fetching them individually
				if (
					item.objects
					&& Array.isArray(item.objects)
					&& item.objects.length > 0
				) {
					this.loadingObjects = true
					try {
						const promises = item.objects.map((id) =>
							fetch(
								`/index.php/apps/openregister/api/objects/${id}`,
							).then((r) => r.json()),
						)
						this.selectedObjects = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected objects:', error)
					} finally {
						this.loadingObjects = false
					}
				}

				// Load selected sources by fetching them individually
				if (
					item.sources
					&& Array.isArray(item.sources)
					&& item.sources.length > 0
				) {
					this.loadingSources = true
					try {
						const promises = item.sources.map((id) =>
							fetch(
								`/index.php/apps/openregister/api/sources/${id}`,
							).then((r) => r.json()),
						)
						this.selectedSources = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected sources:', error)
					} finally {
						this.loadingSources = false
					}
				}

				// Load selected agents by fetching them individually
				if (
					item.agents
					&& Array.isArray(item.agents)
					&& item.agents.length > 0
				) {
					this.loadingAgents = true
					try {
						const promises = item.agents.map((id) =>
							fetch(
								`/index.php/apps/openregister/api/agents/${id}`,
							).then((r) => r.json()),
						)
						this.selectedAgents = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected agents:', error)
					} finally {
						this.loadingAgents = false
					}
				}

				// Load selected views by fetching them individually
				if (
					item.views
					&& Array.isArray(item.views)
					&& item.views.length > 0
				) {
					this.loadingViews = true
					try {
						const promises = item.views.map((id) =>
							fetch(
								`/index.php/apps/openregister/api/views/${id}`,
							).then((r) => r.json()),
						)
						this.selectedViews = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected views:', error)
					} finally {
						this.loadingViews = false
					}
				}

				// Load selected managed applications by fetching them individually
				if (
					item.applications
					&& Array.isArray(item.applications)
					&& item.applications.length > 0
				) {
					this.loadingApplications = true
					try {
						const promises = item.applications.map((id) =>
							fetch(
								`/index.php/apps/openregister/api/applications/${id}`,
							).then((r) => r.json()),
						)
						this.selectedManagedApplications =
							await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected applications:', error)
					} finally {
						this.loadingApplications = false
					}
				}
			}
		},

		// Search methods with debouncing
		/**
		 * @param query
		 * @spec exclude Debounced autocomplete fetch populating select options; UI search plumbing.
		 */
		searchRegisters(query) {
			clearTimeout(this.registerSearchDebounce)
			this.registerSearchDebounce = setTimeout(async () => {
				this.loadingRegisters = true
				try {
					const response = await fetch(
						`/index.php/apps/openregister/api/registers?_search=${encodeURIComponent(query)}&_limit=10`,
					)
					const data = await response.json()
					this.registerOptions = data.results || data || []
					// Include already selected items
					this.selectedRegisters.forEach((selected) => {
						if (
							!this.registerOptions.find((r) => r.id === selected.id)
						) {
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

		/**
		 * @param query
		 * @spec exclude Debounced autocomplete fetch populating select options; UI search plumbing.
		 */
		searchSchemas(query) {
			clearTimeout(this.schemaSearchDebounce)
			this.schemaSearchDebounce = setTimeout(async () => {
				this.loadingSchemas = true
				try {
					const response = await fetch(
						`/index.php/apps/openregister/api/schemas?_search=${encodeURIComponent(query)}&_limit=10`,
					)
					const data = await response.json()
					this.schemaOptions = data.results || data || []
					// Include already selected items
					this.selectedSchemas.forEach((selected) => {
						if (!this.schemaOptions.find((s) => s.id === selected.id)) {
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

		/**
		 * @param query
		 * @spec exclude Debounced autocomplete fetch (register/schema-filtered) populating select options; UI search plumbing.
		 */
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
						this.selectedRegisters.forEach((register) => {
							params.append('_register[]', register.id)
						})
					}

					// Filter by selected schemas
					if (this.selectedSchemas.length > 0) {
						this.selectedSchemas.forEach((schema) => {
							params.append('_schema[]', schema.id)
						})
					}

					const response = await fetch(
						`/index.php/apps/openregister/api/objects?${params.toString()}`,
					)
					const data = await response.json()
					this.objectOptions = data.results || data || []
					// Include already selected items
					this.selectedObjects.forEach((selected) => {
						if (!this.objectOptions.find((o) => o.id === selected.id)) {
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

		/**
		 * @param query
		 * @spec exclude Debounced autocomplete fetch populating select options; UI search plumbing.
		 */
		searchSources(query) {
			clearTimeout(this.sourceSearchDebounce)
			this.sourceSearchDebounce = setTimeout(async () => {
				this.loadingSources = true
				try {
					const response = await fetch(
						`/index.php/apps/openregister/api/sources?_search=${encodeURIComponent(query)}&_limit=10`,
					)
					const data = await response.json()
					this.sourceOptions = data.results || data || []
					// Include already selected items
					this.selectedSources.forEach((selected) => {
						if (!this.sourceOptions.find((s) => s.id === selected.id)) {
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

		/**
		 * @param query
		 * @spec exclude Debounced autocomplete fetch populating select options; UI search plumbing.
		 */
		searchAgents(query) {
			clearTimeout(this.agentSearchDebounce)
			this.agentSearchDebounce = setTimeout(async () => {
				this.loadingAgents = true
				try {
					const response = await fetch(
						`/index.php/apps/openregister/api/agents?_search=${encodeURIComponent(query)}&_limit=10`,
					)
					const data = await response.json()
					this.agentOptions = data.results || data || []
					// Include already selected items
					this.selectedAgents.forEach((selected) => {
						if (!this.agentOptions.find((a) => a.id === selected.id)) {
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

		/**
		 * @param query
		 * @spec exclude Debounced autocomplete fetch populating select options; UI search plumbing.
		 */
		searchViews(query) {
			clearTimeout(this.viewSearchDebounce)
			this.viewSearchDebounce = setTimeout(async () => {
				this.loadingViews = true
				try {
					const response = await fetch(
						`/index.php/apps/openregister/api/views?_search=${encodeURIComponent(query)}&_limit=10`,
					)
					const data = await response.json()
					this.viewOptions = data.results || data || []
					// Include already selected items
					this.selectedViews.forEach((selected) => {
						if (!this.viewOptions.find((v) => v.id === selected.id)) {
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

		/**
		 * @param query
		 * @spec exclude Debounced autocomplete fetch populating select options; UI search plumbing.
		 */
		searchApplications(query) {
			clearTimeout(this.applicationSearchDebounce)
			this.applicationSearchDebounce = setTimeout(async () => {
				this.loadingApplications = true
				try {
					const response = await fetch(
						`/index.php/apps/openregister/api/applications?_search=${encodeURIComponent(query)}&_limit=10`,
					)
					const data = await response.json()
					this.applicationOptions = data.results || data || []
					// Include already selected items
					this.selectedManagedApplications.forEach((selected) => {
						if (
							!this.applicationOptions.find(
								(a) => a.id === selected.id,
							)
						) {
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
