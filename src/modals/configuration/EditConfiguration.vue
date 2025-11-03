<script setup>
import { configurationStore, navigationStore, registerStore, schemaStore, organisationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'editConfiguration'"
		:name="configurationStore.configurationItem?.id ? 'Edit Configuration' : 'New Configuration'"
		size="large"
		:can-close="true"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div class="formContainer">
			<NcTextField
				label="Title *"
				placeholder="Enter configuration title"
				:value="configurationStore.configurationItem?.title"
				:error="!configurationStore.configurationItem?.title?.trim?.()"
				@update:value="updateTitle" />

			<NcTextArea
				label="Description"
				placeholder="Enter configuration description (optional)"
				:value="configurationStore.configurationItem?.description"
				@update:value="updateDescription" />

			<NcTextField
				label="Application"
				placeholder="Enter application identifier (optional)"
				:value="configurationStore.configurationItem?.application"
				@update:value="updateApplication" />

			<div class="selectField">
				<label for="organisation-select">Organisation</label>
				<NcSelect
					id="organisation-select"
					v-model="selectedOrganisation"
					:options="organisationOptions"
					label="name"
					track-by="id"
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

			<div class="selectField">
				<label for="registers-select">Registers</label>
				<NcSelect
					id="registers-select"
					v-model="selectedRegisters"
					:options="registerOptions"
					:multiple="true"
					label="title"
					track-by="id"
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

import Cancel from 'vue-material-design-icons/Cancel.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'

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
		// Icons
		Cancel,
		ContentSave,
	},
	data() {
		return {
			loading: false,
			error: null,
			selectedRegisters: [],
			selectedSchemas: [],
			selectedOrganisation: null,
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
	},
	async created() {
		// Load registers, schemas, and organisations lists
		await Promise.all([
			registerStore.refreshRegisterList(),
			schemaStore.refreshSchemaList(),
			organisationStore.refreshOrganisationList(),
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
			}
			this.selectedRegisters = []
			this.selectedSchemas = []
			this.selectedOrganisation = null
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
			configurationStore.configurationItem.application = value
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
		loadExistingSelections() {
			const item = configurationStore.configurationItem
			if (item) {
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

<style>
.formContainer {
	display: flex;
	flex-direction: column;
	gap: 1rem;
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
