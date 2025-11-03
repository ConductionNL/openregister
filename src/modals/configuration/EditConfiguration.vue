<script setup>
import { configurationStore, navigationStore, registerStore, schemaStore, organisationStore, applicationStore, sourceStore, viewsStore, agentStore, objectStore } from '../../store/store.js'
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
						<label for="application-select">Application</label>
						<NcSelect
							id="application-select"
							v-model="selectedApplication"
							:options="applicationOptions"
							label="name"
							track-by="id"
							:label-outside="true"
							placeholder="Select application (optional)..."
							@input="updateApplication">
							<template #option="{ name, description }">
								<div class="option-content">
									<span class="option-title">{{ name }}</span>
									<span v-if="description" class="option-description">{{ description }}</span>
								</div>
							</template>
						</NcSelect>
						<p v-if="selectedApplication" class="field-hint">
							Application: {{ selectedApplication.name }}
						</p>
					</div>

					<div class="selectField">
						<label for="organisation-select">Organisation</label>
						<NcSelect
							id="organisation-select"
							v-model="selectedOrganisation"
							:options="organisationOptions"
							label="name"
							track-by="id"
							:label-outside="true"
							placeholder="Select organisation (optional)..."
							@input="updateOrganisation">
							<template #option="{ name, description }">
								<div class="option-content">
									<span class="option-title">{{ name }}</span>
									<span v-if="description" class="option-description">{{ description }}</span>
								</div>
							</template>
						</NcSelect>
						<p v-if="selectedOrganisation" class="field-hint">
							Organisation: {{ selectedOrganisation.name }}
						</p>
					</div>
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
							:filterable="false"
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
							:filterable="false"
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
							:filterable="false"
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
							label="name"
							track-by="id"
							:label-outside="true"
							:filterable="false"
							placeholder="Search data sources..."
							:close-on-select="false"
							@search-change="searchSources"
							@input="updateSources">
							<template #option="{ name, description }">
								<div class="option-content">
									<span class="option-title">{{ name }}</span>
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
							:filterable="false"
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
							:filterable="false"
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

export default {
	name: 'EditConfiguration',
	components: {
		NcDialog,
		NcButton,
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
			selectedOrganisation: null,
			selectedApplication: null,
			// Loading states for searches
			loadingRegisters: false,
			loadingSchemas: false,
			loadingObjects: false,
			loadingSources: false,
			loadingAgents: false,
			loadingViews: false,
			// Search results
			registerOptions: [],
			schemaOptions: [],
			objectOptions: [],
			sourceOptions: [],
			agentOptions: [],
			viewOptions: [],
			// Debounce timers
			registerSearchDebounce: null,
			schemaSearchDebounce: null,
			objectSearchDebounce: null,
			sourceSearchDebounce: null,
			agentSearchDebounce: null,
			viewSearchDebounce: null,
		}
	},
	computed: {
		isValid() {
			const item = configurationStore.configurationItem
			return Boolean(item?.title?.trim())
		},
		organisationOptions() {
			return organisationStore.organisationList || []
		},
		applicationOptions() {
			return applicationStore.applicationList || []
		},
	},
	async created() {
		// Load organisations and applications for Settings tab
		await Promise.all([
			organisationStore.refreshOrganisationList(),
			applicationStore.refreshApplicationList(),
		])
		
		// Perform initial searches for Configuration tab entities (load top 10)
		this.searchRegisters('')
		this.searchSchemas('')
		this.searchSources('')
		this.searchAgents('')
		this.searchViews('')

		// Initialize configurationItem if it doesn't exist
		if (!configurationStore.configurationItem) {
			configurationStore.configurationItem = {
				title: '',
				description: null,
				application: '',
				owner: '',
				organisation: null,
				registers: [],
				schemas: [],
				objects: [],
				sources: [],
				agents: [],
				views: [],
			}
			this.selectedRegisters = []
			this.selectedSchemas = []
			this.selectedObjects = []
			this.selectedSources = []
			this.selectedAgents = []
			this.selectedViews = []
			this.selectedOrganisation = null
			this.selectedApplication = null
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
		updateApplication(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Store the application UUID
			configurationStore.configurationItem.application = value ? value.uuid : ''
			this.selectedApplication = value
		},
		updateOrganisation(value) {
			if (!configurationStore.configurationItem) {
				configurationStore.configurationItem = {}
			}
			// Store the organisation ID
			configurationStore.configurationItem.organisation = value ? parseInt(value.id) : null
			this.selectedOrganisation = value
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
		async loadExistingSelections() {
			const item = configurationStore.configurationItem
			if (item) {
				// Load selected application (from already loaded list)
				if (item.application) {
					this.selectedApplication = applicationStore.applicationList.find(
						a => a.uuid === item.application,
					) || null
				}
				// Load selected organisation (from already loaded list)
				if (item.organisation) {
					this.selectedOrganisation = organisationStore.organisationList.find(
						o => parseInt(o.id) === parseInt(item.organisation),
					) || null
				}
				
				// Load selected registers by fetching them individually
				if (item.registers && Array.isArray(item.registers) && item.registers.length > 0) {
					try {
						const promises = item.registers.map(id => 
							fetch(`/index.php/apps/openregister/api/registers/${id}`).then(r => r.json())
						)
						this.selectedRegisters = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected registers:', error)
					}
				}
				
				// Load selected schemas by fetching them individually
				if (item.schemas && Array.isArray(item.schemas) && item.schemas.length > 0) {
					try {
						const promises = item.schemas.map(id => 
							fetch(`/index.php/apps/openregister/api/schemas/${id}`).then(r => r.json())
						)
						this.selectedSchemas = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected schemas:', error)
					}
				}
				
				// Load selected objects by fetching them individually
				if (item.objects && Array.isArray(item.objects) && item.objects.length > 0) {
					try {
						const promises = item.objects.map(id => 
							fetch(`/index.php/apps/openregister/api/objects/${id}`).then(r => r.json())
						)
						this.selectedObjects = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected objects:', error)
					}
				}
				
				// Load selected sources by fetching them individually
				if (item.sources && Array.isArray(item.sources) && item.sources.length > 0) {
					try {
						const promises = item.sources.map(id => 
							fetch(`/index.php/apps/openregister/api/sources/${id}`).then(r => r.json())
						)
						this.selectedSources = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected sources:', error)
					}
				}
				
				// Load selected agents by fetching them individually
				if (item.agents && Array.isArray(item.agents) && item.agents.length > 0) {
					try {
						const promises = item.agents.map(id => 
							fetch(`/index.php/apps/openregister/api/agents/${id}`).then(r => r.json())
						)
						this.selectedAgents = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected agents:', error)
					}
				}
				
				// Load selected views by fetching them individually
				if (item.views && Array.isArray(item.views) && item.views.length > 0) {
					try {
						const promises = item.views.map(id => 
							fetch(`/index.php/apps/openregister/api/views/${id}`).then(r => r.json())
						)
						this.selectedViews = await Promise.all(promises)
					} catch (error) {
						console.error('Error loading selected views:', error)
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
					const selectedIds = this.selectedRegisters.map(r => r.id)
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
					const selectedIds = this.selectedSchemas.map(s => s.id)
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
					const selectedIds = this.selectedObjects.map(o => o.id)
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
					const selectedIds = this.selectedSources.map(s => s.id)
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
					const selectedIds = this.selectedAgents.map(a => a.id)
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
					const selectedIds = this.selectedViews.map(v => v.id)
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
			this.selectedOrganisation = null
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
