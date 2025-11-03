<script setup>
import { configurationStore, navigationStore, registerStore, schemaStore, organisationStore, applicationStore, sourceStore, viewsStore, agentStore } from '../../store/store.js'
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
							:multiple="true"
							label="title"
							track-by="id"
							:label-outside="true"
							placeholder="Select registers..."
							:close-on-select="false"
							@input="updateRegisters">
							<template #option="{ title, description }">
								<div class="option-content">
									<span class="option-title">{{ title }}</span>
									<span v-if="description" class="option-description">{{ description }}</span>
								</div>
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
							:multiple="true"
							label="title"
							track-by="id"
							:label-outside="true"
							placeholder="Select schemas..."
							:close-on-select="false"
							@input="updateSchemas">
							<template #option="{ title, description }">
								<div class="option-content">
									<span class="option-title">{{ title }}</span>
									<span v-if="description" class="option-description">{{ description }}</span>
								</div>
							</template>
						</NcSelect>
						<p class="field-hint">
							{{ selectedSchemas.length }} schema(s) selected
						</p>
					</div>

					<div class="selectField">
						<label for="sources-select">Data Sources</label>
						<NcSelect
							id="sources-select"
							v-model="selectedSources"
							:options="sourceOptions"
							:multiple="true"
							label="name"
							track-by="id"
							:label-outside="true"
							placeholder="Select data sources..."
							:close-on-select="false"
							@input="updateSources">
							<template #option="{ name, description }">
								<div class="option-content">
									<span class="option-title">{{ name }}</span>
									<span v-if="description" class="option-description">{{ description }}</span>
								</div>
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
							:multiple="true"
							label="name"
							track-by="id"
							:label-outside="true"
							placeholder="Select agents..."
							:close-on-select="false"
							@input="updateAgents">
							<template #option="{ name, description }">
								<div class="option-content">
									<span class="option-title">{{ name }}</span>
									<span v-if="description" class="option-description">{{ description }}</span>
								</div>
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
							:multiple="true"
							label="name"
							track-by="id"
							:label-outside="true"
							placeholder="Select views..."
							:close-on-select="false"
							@input="updateViews">
							<template #option="{ name, description }">
								<div class="option-content">
									<span class="option-title">{{ name }}</span>
									<span v-if="description" class="option-description">{{ description }}</span>
								</div>
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
			selectedSources: [],
			selectedAgents: [],
			selectedViews: [],
			selectedOrganisation: null,
			selectedApplication: null,
		}
	},
	computed: {
		isValid() {
			const item = configurationStore.configurationItem
			return Boolean(item?.title?.trim())
		},
		registerOptions() {
			const selectedIds = this.selectedRegisters.map(r => r.id)
			return (registerStore.registerList || []).filter(
				register => !selectedIds.includes(register.id),
			)
		},
		schemaOptions() {
			const selectedIds = this.selectedSchemas.map(s => s.id)
			return (schemaStore.schemaList || []).filter(
				schema => !selectedIds.includes(schema.id),
			)
		},
	organisationOptions() {
		return organisationStore.organisationList || []
	},
	applicationOptions() {
		return applicationStore.applicationList || []
	},
	sourceOptions() {
		return sourceStore.sourceList || []
	},
	agentOptions() {
		return agentStore.agentList || []
	},
	viewOptions() {
		return viewsStore.viewsList || []
	},
	},
	async created() {
		// Load all required lists
		await Promise.all([
			registerStore.refreshRegisterList(),
			schemaStore.refreshSchemaList(),
			organisationStore.refreshOrganisationList(),
			applicationStore.refreshApplicationList(),
			sourceStore.refreshSourceList(),
			agentStore.refreshAgentList(),
			viewsStore.refreshViewsList(),
		])

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
				sources: [],
				agents: [],
				views: [],
			}
			this.selectedRegisters = []
			this.selectedSchemas = []
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
		loadExistingSelections() {
			const item = configurationStore.configurationItem
			if (item) {
				// Load selected application
				if (item.application) {
					this.selectedApplication = applicationStore.applicationList.find(
						a => a.uuid === item.application,
					) || null
				}
				// Load selected organisation
				if (item.organisation) {
					this.selectedOrganisation = organisationStore.organisationList.find(
						o => parseInt(o.id) === parseInt(item.organisation),
					) || null
				}
				// Load selected registers
				if (item.registers && Array.isArray(item.registers)) {
					this.selectedRegisters = registerStore.registerList.filter(
						r => item.registers.includes(parseInt(r.id)),
					)
				}
			// Load selected schemas
			if (item.schemas && Array.isArray(item.schemas)) {
				this.selectedSchemas = schemaStore.schemaList.filter(
					s => item.schemas.includes(parseInt(s.id)),
				)
			}
			// Load selected sources
			if (item.sources && Array.isArray(item.sources)) {
				this.selectedSources = sourceStore.sourceList.filter(
					s => item.sources.includes(parseInt(s.id)),
				)
			}
			// Load selected agents
			if (item.agents && Array.isArray(item.agents)) {
				this.selectedAgents = agentStore.agentList.filter(
					a => item.agents.includes(parseInt(a.id)),
				)
			}
			// Load selected views
			if (item.views && Array.isArray(item.views)) {
				this.selectedViews = viewsStore.viewsList.filter(
					v => item.views.includes(parseInt(v.id)),
				)
			}
		}
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
			this.selectedOrganisation = null
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
