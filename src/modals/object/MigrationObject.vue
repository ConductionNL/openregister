<script setup>
import { objectStore, navigationStore, registerStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="`Migrate ${selectedObjects.length} object${selectedObjects.length !== 1 ? 's' : ''}`"
		size="large"
		:can-close="false">
		<!-- Source and Target Information -->
		<div class="migration-overview">
			<div class="source-info">
				<h4>Source</h4>
				<div class="info-card">
					<div class="card-item">
						<div class="card-label-with-icon">
							<DatabaseOutline :size="16" />
							<span class="card-label">Register:</span>
						</div>
						<span class="card-value">{{ registerStore.registerItem?.title || registerStore.registerItem?.id }}</span>
					</div>
					<div class="card-item">
						<div class="card-label-with-icon">
							<FileTreeOutline :size="16" />
							<span class="card-label">Schema:</span>
						</div>
						<span class="card-value">{{ schemaStore.schemaItem?.title || schemaStore.schemaItem?.id }}</span>
					</div>
				</div>
			</div>

			<div class="migration-arrow">
				→
			</div>

			<div class="source-info">
				<h4>Target</h4>
				<div class="info-card">
					<div class="card-item">
						<div class="card-label-with-icon">
							<DatabaseOutline :size="16" />
							<span class="card-label">Register:</span>
						</div>
						<span class="card-value">{{ targetRegister?.title || 'Not selected' }}</span>
					</div>
					<div class="card-item">
						<div class="card-label-with-icon">
							<FileTreeOutline :size="16" />
							<span class="card-label">Schema:</span>
						</div>
						<span class="card-value">{{ targetSchema?.title || 'Not selected' }}</span>
					</div>
				</div>
			</div>
		</div>

		<!-- Step 1: Confirm Selection -->
		<div v-if="step === 1" class="migration-step step-1">
			<h3 class="step-title">
				Confirm Object Selection
			</h3>

			<NcNoteCard type="info">
				Review the selected objects below. You can remove any objects you don't want to migrate by clicking the remove button.
			</NcNoteCard>

			<div class="selected-objects-container">
				<h4>Selected Objects ({{ selectedObjects.length }})</h4>

				<div v-if="selectedObjects.length" class="selected-objects-list">
					<div v-for="obj in selectedObjects"
						:key="obj.id"
						class="selected-object-item">
						<div class="object-info">
							<strong>{{ obj['@self']?.name || obj.name || obj.title || obj['@self']?.title || 'Unnamed Object' }}</strong>
							<p class="object-id">
								ID: {{ obj.id || obj['@self']?.id }}
							</p>
						</div>
						<NcButton type="tertiary"
							:aria-label="`Remove ${obj['@self']?.name || obj.name || obj.title || obj['@self']?.title || obj.id}`"
							@click="removeObject(obj.id)">
							<template #icon>
								<Close :size="20" />
							</template>
						</NcButton>
					</div>
				</div>

				<NcEmptyContent v-else name="No objects selected">
					<template #description>
						No objects are currently selected for migration.
					</template>
				</NcEmptyContent>
			</div>
		</div>

		<!-- Step 2: Select Target Register and Schema -->
		<div v-if="step === 2" class="migration-step">
			<h3>Select Target Register and Schema</h3>
			<p>Choose the destination register and schema for the {{ selectedObjects.length }} selected object{{ selectedObjects.length > 1 ? 's' : '' }}:</p>

			<!-- Target Register Selection -->
			<div class="selection-section">
				<h4>Target Register</h4>
				<NcSelect
					v-model="targetRegister"
					:options="availableRegisters"
					label="title"
					track-by="id"
					placeholder="Select a register..."
					@update:model-value="onRegisterChange" />
			</div>

			<!-- Target Schema Selection -->
			<div v-if="targetRegister" class="selection-section">
				<h4>Target Schema</h4>
				<NcSelect
					v-model="targetSchema"
					:options="availableSchemas"
					label="title"
					track-by="id"
					placeholder="Select a schema..."
					@update:model-value="onSchemaChange" />
			</div>
		</div>

		<!-- Step 3: Property Mapping -->
		<div v-if="step === 3" class="migration-step">
			<h3>Property Mapping</h3>
			<p>Map properties from the source schema to the target schema. Properties not mapped will be discarded.</p>

			<NcNoteCard type="info">
				Configure how properties should be mapped when migrating from source schema
				<strong>{{ schemaStore.schemaItem?.title }}</strong> to target schema <strong>{{ targetSchema?.title }}</strong>
			</NcNoteCard>

			<div class="mapping-container">
				<div class="mapping-header">
					<div class="source-header">
						<h4>Source Properties</h4>
						<span class="schema-name">{{ schemaStore.schemaItem?.title }}</span>
					</div>
					<div class="arrow-header">
						→
					</div>
					<div class="target-header">
						<h4>Target Properties</h4>
						<span class="schema-name">{{ targetSchema?.title }}</span>
					</div>
				</div>

				<div class="mapping-list">
					<div v-for="sourceProperty in sourceProperties"
						:key="sourceProperty.name"
						class="mapping-row">
						<div class="source-property">
							<span class="property-name">{{ sourceProperty.name }}</span>
							<span class="property-type">{{ sourceProperty.type }}</span>
						</div>
						<div class="mapping-arrow">
							→
						</div>
						<div class="target-property">
							<NcSelect
								v-model="uiMappings[sourceProperty.name]"
								:options="targetPropertyOptions"
								label="label"
								track-by="value"
								:placeholder="'Map to target property...'"
								:clearable="true"
								@update:model-value="updateMappingFromUI(sourceProperty.name)" />
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Step 4: Migration Report -->
		<div v-if="step === 4" class="migration-step">
			<h3 class="report-title">
				Migration Report
			</h3>

			<NcNoteCard v-if="migrationResult?.success" type="success">
				<p>Objects successfully migrated!</p>
			</NcNoteCard>
			<NcNoteCard v-if="migrationResult && !migrationResult.success" type="error">
				<p>Migration failed. Please check the details below.</p>
			</NcNoteCard>

			<div v-if="migrationResult" class="migration-report">
				<!-- Migration Summary -->
				<div class="report-section">
					<h4>Migration Summary</h4>
					<div class="migration-info">
						<div class="migration-detail">
							<strong>Source:</strong>
							<div class="migration-meta">
								<span>{{ registerStore.registerItem?.title }} / {{ schemaStore.schemaItem?.title }}</span>
								<span class="object-count">{{ selectedObjects.length }} object{{ selectedObjects.length > 1 ? 's' : '' }}</span>
							</div>
						</div>
						<div class="migration-detail">
							<strong>Target:</strong>
							<div class="migration-meta">
								<span>{{ targetRegister?.title }} / {{ targetSchema?.title }}</span>
							</div>
						</div>
					</div>
				</div>

				<!-- Statistics -->
				<div class="report-section">
					<h4>Statistics</h4>
					<ul>
						<li>Objects migrated: {{ migrationResult.statistics?.objectsMigrated || 0 }}</li>
						<li>Objects failed: {{ migrationResult.statistics?.objectsFailed || 0 }}</li>
						<li>Properties mapped: {{ migrationResult.statistics?.propertiesMapped || 0 }}</li>
						<li>Properties discarded: {{ migrationResult.statistics?.propertiesDiscarded || 0 }}</li>
					</ul>
				</div>

				<!-- Migration Details -->
				<div v-if="migrationResult.details?.length" class="report-section">
					<h4>Migration Details</h4>
					<div class="migration-details">
						<div v-for="detail in migrationResult.details"
							:key="detail.objectId"
							class="migration-detail-item">
							<div class="detail-header">
								<strong>{{ detail.objectTitle || detail.objectId }}</strong>
								<span :class="['status', detail.success ? 'success' : 'error']">
									{{ detail.success ? 'Success' : 'Failed' }}
								</span>
							</div>
							<div v-if="detail.error" class="detail-error">
								{{ detail.error }}
							</div>
						</div>
					</div>
				</div>

				<!-- Warnings -->
				<div v-if="migrationResult.warnings?.length" class="report-section">
					<h4>Warnings</h4>
					<ul>
						<li v-for="warning in migrationResult.warnings" :key="warning" class="warning-text">
							{{ warning }}
						</li>
					</ul>
				</div>

				<!-- Errors -->
				<div v-if="migrationResult.errors?.length" class="report-section">
					<h4>Errors</h4>
					<ul>
						<li v-for="error in migrationResult.errors" :key="error" class="error-text">
							{{ error }}
						</li>
					</ul>
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ step === 4 ? 'Close' : 'Cancel' }}
			</NcButton>

			<NcButton v-if="step === 1"
				:disabled="selectedObjects.length === 0"
				type="primary"
				@click="nextStep">
				<template #icon>
					<ArrowRight :size="20" />
				</template>
				Next
			</NcButton>

			<NcButton v-if="step === 2"
				type="secondary"
				@click="previousStep">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				Back
			</NcButton>

			<NcButton v-if="step === 2"
				:disabled="!targetRegister || !targetSchema"
				type="primary"
				@click="nextStep">
				<template #icon>
					<ArrowRight :size="20" />
				</template>
				Next
			</NcButton>

			<NcButton v-if="step === 3"
				type="secondary"
				@click="previousStep">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				Back
			</NcButton>

			<NcButton v-if="step === 3"
				:disabled="loading || !canMigrate"
				type="primary"
				@click="performMigration">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<DatabaseExport v-else :size="20" />
				</template>
				Migrate Objects
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'

// Icons
import Cancel from 'vue-material-design-icons/Cancel.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Close from 'vue-material-design-icons/Close.vue'
import DatabaseExport from 'vue-material-design-icons/DatabaseExport.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'

export default {
	name: 'MigrationObject',
	components: {
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		Cancel,
		ArrowRight,
		ArrowLeft,
		Close,
		DatabaseExport,
		DatabaseOutline,
		FileTreeOutline,
	},

	data() {
		return {
			step: 1, // 1: confirm selection, 2: select target register/schema, 3: property mapping, 4: report
			loading: false,
			selectedObjects: [],
			availableRegisters: [],
			availableSchemas: [],
			targetRegister: null,
			targetSchema: null,
			sourceProperties: [],
			targetProperties: [],
			mapping: {},
			// Go-between variable for UI binding - maps source properties to selected target options
			uiMappings: {},
			migrationResult: null,
		}
	},
	computed: {
		targetPropertyOptions() {
			const options = this.targetProperties.map(prop => ({
				label: `${prop.name} (${prop.type})`,
				value: prop.name,
			}))

			options.push({
				label: '(Do not map)',
				value: null,
			})

			return options
		},
		canMigrate() {
			// Check if we have target register/schema and at least one property mapping
			const hasValidMappings = Object.values(this.uiMappings).some(option => option && option.value)
			return this.targetRegister && this.targetSchema && hasValidMappings
		},
	},
	mounted() {
		this.initializeMigration()
	},
	methods: {
		initializeMigration() {
			// Get selected objects from the store or navigation context
			this.selectedObjects = objectStore.selectedObjects || []
			if (this.selectedObjects.length === 0) {
				this.closeModal()
				return
			}
			this.loadAvailableRegisters()
		},
		async loadAvailableRegisters() {
			this.loading = true
			try {
				const response = await fetch('/index.php/apps/openregister/api/registers')
				const data = await response.json()
				this.availableRegisters = data.results || []
			} catch (error) {
				console.error('Error loading registers:', error)
				this.availableRegisters = []
			} finally {
				this.loading = false
			}
		},
		async onRegisterChange() {
			if (!this.targetRegister) {
				this.availableSchemas = []
				this.targetSchema = null
				return
			}

			this.loading = true
			try {
				const response = await fetch(`/index.php/apps/openregister/api/schemas?register=${this.targetRegister.id}`)
				const data = await response.json()
				this.availableSchemas = data.results || []
			} catch (error) {
				console.error('Error loading schemas:', error)
				this.availableSchemas = []
			} finally {
				this.loading = false
			}
		},
		async onSchemaChange() {
			if (!this.targetSchema) {
				return
			}
			await this.loadSchemaProperties()
		},
		removeObject(objectId) {
			this.selectedObjects = this.selectedObjects.filter(obj => obj.id !== objectId)
			if (this.selectedObjects.length === 0) {
				this.closeModal()
			}
		},
		nextStep() {
			if (this.step === 1 && this.selectedObjects.length > 0) {
				this.step = 2
			} else if (this.step === 2 && this.targetRegister && this.targetSchema) {
				this.step = 3
			}
		},
		previousStep() {
			if (this.step > 1) {
				this.step--
			}
		},
		async loadSchemaProperties() {
			if (!schemaStore.schemaItem || !this.targetSchema) {
				return
			}

			try {
				// Load source schema properties
				this.sourceProperties = this.extractSchemaProperties(schemaStore.schemaItem)

				// Load target schema properties
				const response = await fetch(`/index.php/apps/openregister/api/schemas/${this.targetSchema.id}`)
				const targetSchemaData = await response.json()
				this.targetProperties = this.extractSchemaProperties(targetSchemaData)

				// Initialize property mappings
				this.initializePropertyMappings()

				// Sync UI mappings
				this.convertMappingToUI()
			} catch (error) {
				console.error('Error loading schema properties:', error)
			}
		},
		extractSchemaProperties(schema) {
			// Extract properties from schema definition
			const properties = []
			if (schema.properties) {
				Object.keys(schema.properties).forEach(key => {
					properties.push({
						name: key,
						type: schema.properties[key].type || 'string',
						required: schema.required?.includes(key) || false,
					})
				})
			}
			return properties
		},
		initializePropertyMappings() {
			this.mapping = {}
			this.uiMappings = {}

			// Auto-map properties with same names
			this.sourceProperties.forEach(sourceProp => {
				const matchingTarget = this.targetProperties.find(
					targetProp => targetProp.name === sourceProp.name,
				)
				if (matchingTarget) {
					// Simple mapping: target property as key, source property as value
					this.mapping[matchingTarget.name] = sourceProp.name

					// Set up UI mapping
					const targetOption = this.targetPropertyOptions.find(option => option.value === matchingTarget.name)
					if (targetOption) {
						this.uiMappings[sourceProp.name] = targetOption
					}
				}
			})
		},
		async performMigration() {
			if (!this.canMigrate) {
				return
			}

			// Make sure our mapping is up to date before sending
			this.convertUIToMapping()

			this.loading = true
			try {
				const response = await fetch('/index.php/apps/openregister/api/migrate', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						sourceRegister: registerStore.registerItem.id,
						sourceSchema: schemaStore.schemaItem.id,
						targetRegister: this.targetRegister.id,
						targetSchema: this.targetSchema.id,
						objects: this.selectedObjects.map(obj => obj.id),
						mapping: this.mapping,
					}),
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				this.migrationResult = await response.json()
				this.step = 4

				// Refresh the object list
				objectStore.refreshObjectList()

			} catch (error) {
				console.error('Error performing migration:', error)
				this.migrationResult = {
					success: false,
					errors: [error.message || 'An error occurred during migration'],
				}
				this.step = 4
			} finally {
				this.loading = false
			}
		},
		closeModal() {
			navigationStore.setModal(false)
		},
		updateMappingFromUI(sourceProperty) {
			// Convert UI mappings to our simple mapping format
			this.convertUIToMapping()
		},
		convertUIToMapping() {
			// Convert from UI format (source -> target option) to our format (target -> source)
			this.mapping = {}

			for (const [sourceProp, targetOption] of Object.entries(this.uiMappings)) {
				if (targetOption && targetOption.value) {
					this.mapping[targetOption.value] = sourceProp
				}
			}
		},
		convertMappingToUI() {
			// Convert from our format (target -> source) to UI format (source -> target option)
			this.uiMappings = {}

			for (const [targetProp, sourceProp] of Object.entries(this.mapping)) {
				const targetOption = this.targetPropertyOptions.find(option => option.value === targetProp)
				if (targetOption) {
					this.uiMappings[sourceProp] = targetOption
				}
			}
		},
	},
}
</script>

<style scoped>
.migration-step {
	padding: 0;
}

.step-1 {
	padding-top: 0 !important;
}

.step-title {
	margin-top: 0 !important;
	margin-bottom: 16px;
	color: var(--color-main-text);
}

.report-title {
	margin-top: 0 !important;
	margin-bottom: 16px;
	color: var(--color-main-text);
}

.migration-step h3 {
	margin-bottom: 16px;
	color: var(--color-main-text);
}

.selected-objects-container {
	margin: 20px 0;
}

.selected-objects-list {
	max-height: 400px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.selected-object-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
}

.selected-object-item:last-child {
	border-bottom: none;
}

.selection-section {
	margin: 20px 0;
}

.selection-section h4 {
	margin-bottom: 8px;
	color: var(--color-main-text);
}

.mapping-container {
	margin: 20px 0;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	overflow: hidden;
}

.mapping-header {
	display: grid;
	grid-template-columns: 1fr auto 1fr;
	gap: 16px;
	padding: 16px;
	background-color: var(--color-background-dark);
	align-items: center;
}

.source-header,
.target-header {
	text-align: center;
}

.source-header h4,
.target-header h4 {
	margin: 0 0 4px 0;
	color: var(--color-main-text);
}

.schema-name {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.arrow-header {
	font-size: 1.5em;
	font-weight: bold;
	color: var(--color-main-text);
	text-align: center;
}

.mapping-list {
	max-height: 400px;
	overflow-y: auto;
}

.mapping-row {
	display: grid;
	grid-template-columns: 1fr auto 1fr;
	gap: 16px;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
	align-items: center;
}

.mapping-row:last-child {
	border-bottom: none;
}

.source-property {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.property-name {
	font-weight: bold;
	color: var(--color-main-text);
}

.property-type {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	font-family: monospace;
}

.mapping-arrow {
	font-size: 1.2em;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.target-property {
	min-width: 200px;
}

.migration-report {
	margin-top: 16px;
}

.migration-info {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.migration-detail {
	padding: 12px;
	background-color: var(--color-background-dark);
	border-radius: 4px;
}

.migration-meta {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 8px;
}

.object-count {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.migration-details {
	max-height: 300px;
	overflow-y: auto;
}

.migration-detail-item {
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-dark);
	border-radius: 4px;
	margin-bottom: 8px;
}

.detail-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.status {
	padding: 4px 8px;
	border-radius: 4px;
	font-size: 0.8em;
	font-weight: bold;
}

.status.success {
	background-color: var(--color-success);
	color: white;
}

.status.error {
	background-color: var(--color-error);
	color: white;
}

.detail-error {
	color: var(--color-error);
	font-size: 0.9em;
	font-style: italic;
}

.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 16px;
	padding: 40px;
}

.object-info strong {
	display: block;
	margin-bottom: 4px;
	color: var(--color-main-text);
}

.object-id {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.report-section {
	margin-bottom: 20px;
	padding: 16px;
	background-color: var(--color-background-hover);
	border-radius: 4px;
}

.report-section h4 {
	margin-bottom: 12px;
	color: var(--color-main-text);
}

.report-section ul {
	margin: 0;
	padding-left: 20px;
}

.report-section li {
	margin-bottom: 4px;
}

.error-text {
	color: var(--color-error);
}

.warning-text {
	color: var(--color-warning);
}

.detail-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 16px;
	margin: 16px 0;
}

.detail-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.detail-label {
	font-weight: bold;
	color: var(--color-main-text);
	font-size: 0.9em;
}

.detail-value {
	color: var(--color-text-maxcontrast);
}

.migration-overview {
	display: grid;
	grid-template-columns: 1fr auto 1fr;
	gap: 24px;
	margin: 20px 0;
	align-items: start;
}

.source-info,
.target-info {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.source-info h4,
.target-info h4 {
	margin: 0;
	color: var(--color-main-text);
	text-align: center;
	font-size: 1.1em;
}

.info-card {
	padding: 16px;
	background-color: var(--color-background-hover);
	border-radius: 8px;
	border: 1px solid var(--color-border);
}

.card-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.card-item:last-child {
	border-bottom: none;
}

.card-label-with-icon {
	display: flex;
	align-items: center;
	gap: 8px;
}

.card-label {
	font-weight: bold;
	color: var(--color-main-text);
	font-size: 0.9em;
}

.card-value {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.migration-overview .migration-arrow {
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 2em;
	font-weight: bold;
	color: var(--color-primary);
	margin-top: 40px;
}

/* number */
.codeMirrorContainer.light :deep(.ͼd) {
	color: #d19a66;
}
.codeMirrorContainer.dark :deep(.ͼd) {
	color: #9d6c3a;
}

/* text cursor */
.codeMirrorContainer :deep(.cm-content) * {
	cursor: text !important;
}

/* selection color */
.codeMirrorContainer.light :deep(.cm-line)::selection,
.codeMirrorContainer.light :deep(.cm-line) ::selection {
	background-color: #d7eaff !important;
    color: black;
}
.codeMirrorContainer.dark :deep(.cm-line)::selection,
.codeMirrorContainer.dark :deep(.cm-line) ::selection {
	background-color: #8fb3e6 !important;
    color: black;
}

/* string */
.codeMirrorContainer.light :deep(.cm-line .ͼe)::selection {
    color: #2d770f;
}
.codeMirrorContainer.dark :deep(.cm-line .ͼe)::selection {
    color: #104e0c;
}

/* boolean */
.codeMirrorContainer.light :deep(.cm-line .ͼc)::selection {
 color: #221199;
}
.codeMirrorContainer.dark :deep(.cm-line .ͼc)::selection {
 color: #4026af;
}

/* null */
.codeMirrorContainer.light :deep(.cm-line .ͼb)::selection {
 color: #770088;
}
.codeMirrorContainer.dark :deep(.cm-line .ͼb)::selection {
 color: #770088;
}

/* number */
.codeMirrorContainer.light :deep(.cm-line .ͼd)::selection {
 color: #8c5c2c;
}
.codeMirrorContainer.dark :deep(.cm-line .ͼd)::selection {
 color: #623907;
}
</style>
