<script setup>
import { navigationStore, registerStore, schemaStore } from '../../store/store.js'
import { n, t } from './l10n.js'
</script>

<template>
	<NcDialog
		:name="
			n(
				'openregister',
				'Migrate {count} object',
				'Migrate {count} objects',
				selectedObjects.length,
				{ count: selectedObjects.length },
			)
		"
		size="large"
		noClose>
		<!-- Source and Target Information -->
		<div class="migration-overview">
			<div class="source-info">
				<h4>{{ t('openregister', 'Source') }}</h4>
				<div class="info-card">
					<div class="card-item">
						<div class="card-label-with-icon">
							<DatabaseOutline :size="16" />
							<span class="card-label">{{
								t('openregister', 'Register:')
							}}</span>
						</div>
						<span class="card-value">{{
							registerStore.registerItem?.title
							|| registerStore.registerItem?.id
						}}</span>
					</div>
					<div class="card-item">
						<div class="card-label-with-icon">
							<FileTreeOutline :size="16" />
							<span class="card-label">{{
								t('openregister', 'Schema:')
							}}</span>
						</div>
						<span class="card-value">{{
							schemaStore.schemaItem?.title
							|| schemaStore.schemaItem?.id
						}}</span>
					</div>
				</div>
			</div>

			<div class="migration-arrow">→</div>

			<div class="source-info">
				<h4>{{ t('openregister', 'Target') }}</h4>
				<div class="info-card">
					<div class="card-item">
						<div class="card-label-with-icon">
							<DatabaseOutline :size="16" />
							<span class="card-label">{{
								t('openregister', 'Register:')
							}}</span>
						</div>
						<span class="card-value">{{
							targetRegister?.title
							|| t('openregister', 'Not selected')
						}}</span>
					</div>
					<div class="card-item">
						<div class="card-label-with-icon">
							<FileTreeOutline :size="16" />
							<span class="card-label">{{
								t('openregister', 'Schema:')
							}}</span>
						</div>
						<span class="card-value">{{
							targetSchema?.title || t('openregister', 'Not selected')
						}}</span>
					</div>
				</div>
			</div>
		</div>

		<!-- Step 1: Confirm Selection -->
		<div v-if="step === 1" class="migration-step step-1">
			<h3 class="step-title">
				{{ t('openregister', 'Confirm the selection') }}
			</h3>

			<NcNoteCard type="info">
				{{
					t(
						'openregister',
						'Remove any object you do not want to migrate.',
					)
				}}
			</NcNoteCard>

			<SelectedObjectsList
				:objects="selectedObjects"
				:emptyDescription="
					t('openregister', 'Select objects in the list to migrate them.')
				"
				@remove="removeObject" />
		</div>

		<!-- Step 2: Select Target Register and Schema -->
		<div v-if="step === 2" class="migration-step">
			<h3>{{ t('openregister', 'Choose the target register and schema') }}</h3>
			<p>
				{{
					n(
						'openregister',
						'Choose where {count} selected object goes.',
						'Choose where {count} selected objects go.',
						selectedObjects.length,
						{ count: selectedObjects.length },
					)
				}}
			</p>

			<!-- Target Register Selection -->
			<div class="selection-section">
				<h4>{{ t('openregister', 'Target register') }}</h4>
				<NcSelect
					v-model="targetRegister"
					:inputLabel="t('openregister', 'Target register')"
					:options="availableRegisters"
					label="title"
					trackBy="id"
					:placeholder="t('openregister', 'Select a register...')"
					@update:modelValue="onRegisterChange" />
			</div>

			<!-- Target Schema Selection -->
			<div v-if="targetRegister" class="selection-section">
				<h4>{{ t('openregister', 'Target schema') }}</h4>
				<NcSelect
					v-model="targetSchema"
					:inputLabel="t('openregister', 'Target schema')"
					:options="availableSchemas"
					label="title"
					trackBy="id"
					:placeholder="t('openregister', 'Select a schema...')"
					@update:modelValue="onSchemaChange" />
			</div>
		</div>

		<!-- Step 3: Property Mapping -->
		<div v-if="step === 3" class="migration-step">
			<h3>{{ t('openregister', 'Property mapping') }}</h3>
			<p>
				{{
					t(
						'openregister',
						'Map each source property to a target property. We discard properties you leave unmapped.',
					)
				}}
			</p>

			<NcNoteCard type="info">
				{{
					t(
						'openregister',
						'You move objects from {source} to {target}.',
						{
							source: schemaStore.schemaItem?.title,
							target: targetSchema?.title,
						},
					)
				}}
			</NcNoteCard>

			<div class="mapping-container">
				<div class="mapping-header">
					<div class="source-header">
						<h4>{{ t('openregister', 'Source properties') }}</h4>
						<span class="schema-name">{{
							schemaStore.schemaItem?.title
						}}</span>
					</div>
					<div class="arrow-header">→</div>
					<div class="target-header">
						<h4>{{ t('openregister', 'Target properties') }}</h4>
						<span class="schema-name">{{ targetSchema?.title }}</span>
					</div>
				</div>

				<div class="mapping-list">
					<div
						v-for="sourceProperty in sourceProperties"
						:key="sourceProperty.name"
						class="mapping-row">
						<div class="source-property">
							<span class="property-name">{{
								sourceProperty.name
							}}</span>
							<span class="property-type">{{
								sourceProperty.type
							}}</span>
						</div>
						<div class="mapping-arrow">→</div>
						<div class="target-property">
							<NcSelect
								v-model="uiMappings[sourceProperty.name]"
								:inputLabel="
									t(
										'openregister',
										'Target property for {property}',
										{
											property: sourceProperty.name,
										},
									)
								"
								:options="targetPropertyOptions"
								label="label"
								trackBy="value"
								:placeholder="
									t('openregister', 'Map to target property…')
								"
								:clearable="true"
								@update:modelValue="
									updateMappingFromUI(sourceProperty.name)
								" />
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Step 4: Migration Report -->
		<div v-if="step === 4" class="migration-step">
			<h3 class="report-title">{{ t('openregister', 'Migration report') }}</h3>

			<NcNoteCard v-if="migrationResult?.success" type="success">
				<p>{{ t('openregister', 'Objects migrated') }}</p>
			</NcNoteCard>
			<NcNoteCard
				v-if="migrationResult && !migrationResult.success"
				type="error">
				<p>
					{{
						t(
							'openregister',
							'Migration failed. Check the details below.',
						)
					}}
				</p>
			</NcNoteCard>

			<div v-if="migrationResult" class="migration-report">
				<!-- Migration Summary -->
				<div class="report-section">
					<h4>{{ t('openregister', 'Migration summary') }}</h4>
					<div class="migration-info">
						<div class="migration-detail">
							<strong>{{ t('openregister', 'Source:') }}</strong>
							<div class="migration-meta">
								<span
									>{{ registerStore.registerItem?.title }} /
									{{ schemaStore.schemaItem?.title }}</span
								>
								<span class="object-count">{{
									n(
										'openregister',
										'{count} object',
										'{count} objects',
										selectedObjects.length,
										{ count: selectedObjects.length },
									)
								}}</span>
							</div>
						</div>
						<div class="migration-detail">
							<strong>{{ t('openregister', 'Target:') }}</strong>
							<div class="migration-meta">
								<span
									>{{ targetRegister?.title }} /
									{{ targetSchema?.title }}</span
								>
							</div>
						</div>
					</div>
				</div>

				<!-- Statistics -->
				<div class="report-section">
					<h4>{{ t('openregister', 'Statistics') }}</h4>
					<ul>
						<li>
							{{
								t('openregister', 'Objects migrated: {count}', {
									count:
										migrationResult.statistics?.objectsMigrated
										|| 0,
								})
							}}
						</li>
						<li>
							{{
								t('openregister', 'Objects failed: {count}', {
									count:
										migrationResult.statistics?.objectsFailed
										|| 0,
								})
							}}
						</li>
						<li>
							{{
								t('openregister', 'Properties mapped: {count}', {
									count:
										migrationResult.statistics?.propertiesMapped
										|| 0,
								})
							}}
						</li>
						<li>
							{{
								t('openregister', 'Properties discarded: {count}', {
									count:
										migrationResult.statistics
											?.propertiesDiscarded || 0,
								})
							}}
						</li>
					</ul>
				</div>

				<!-- Migration Details -->
				<div v-if="migrationResult.details?.length" class="report-section">
					<h4>{{ t('openregister', 'Migration details') }}</h4>
					<div class="migration-details">
						<div
							v-for="detail in migrationResult.details"
							:key="detail.objectId"
							class="migration-detail-item">
							<div class="detail-header">
								<strong>{{
									detail.objectTitle || detail.objectId
								}}</strong>
								<span
									class="status"
									:class="[detail.success ? 'success' : 'error']">
									{{
										detail.success
											? t('openregister', 'Success')
											: t('openregister', 'Failed')
									}}
								</span>
							</div>
							<div v-if="detail.error" class="detail-error">
								{{ detail.error }}
							</div>
						</div>
					</div>
				</div>

				<TransferMessages :result="migrationResult" />
			</div>
		</div>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{
					step === 4
						? t('openregister', 'Close')
						: t('openregister', 'Cancel')
				}}
			</NcButton>

			<NcButton
				v-if="step === 1"
				:disabled="selectedObjects.length === 0"
				variant="primary"
				@click="nextStep">
				<template #icon>
					<ArrowRight :size="20" />
				</template>
				{{ t('openregister', 'Next') }}
			</NcButton>

			<NcButton v-if="step === 2" variant="secondary" @click="previousStep">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('openregister', 'Back') }}
			</NcButton>

			<NcButton
				v-if="step === 2"
				:disabled="!targetRegister || !targetSchema"
				variant="primary"
				@click="nextStep">
				<template #icon>
					<ArrowRight :size="20" />
				</template>
				{{ t('openregister', 'Next') }}
			</NcButton>

			<NcButton v-if="step === 3" variant="secondary" @click="previousStep">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('openregister', 'Back') }}
			</NcButton>

			<NcButton
				v-if="step === 3"
				:disabled="loading || !canMigrate"
				variant="primary"
				@click="performMigration">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<DatabaseExport v-else :size="20" />
				</template>
				{{ t('openregister', 'Migrate objects') }}
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
} from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
// Icons
import Cancel from 'vue-material-design-icons/Cancel.vue'
import DatabaseExport from 'vue-material-design-icons/DatabaseExport.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import SelectedObjectsList from '../../components/shared/SelectedObjectsList.vue'
import TransferMessages from './TransferMessages.vue'
import { withRequestToken } from '../../services/requestToken.js'
import selectedObjectsDialog, {
	refreshObjectLists,
} from './selectedObjectsDialog.js'
import { failedTransfer } from './transferResult.js'

/**
 * Post a migration and read its result.
 *
 * @param {object} request The migration request body
 * @return {Promise<object>} The migration result
 * @throws {Error} With the server's error when the migration is refused
 */
async function postMigration(request) {
	const response = await fetch('/index.php/apps/openregister/api/migrate', withRequestToken({
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
		},
		body: JSON.stringify(request),
	}))

	const result = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error(result.error)
	}
	return result
}

export default {
	name: 'MigrationObject',
	components: {
		SelectedObjectsList,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		Cancel,
		ArrowRight,
		ArrowLeft,
		DatabaseExport,
		DatabaseOutline,
		FileTreeOutline,
		TransferMessages,
	},

	mixins: [selectedObjectsDialog],

	data() {
		return {
			step: 1, // 1: confirm selection, 2: select target register/schema, 3: property mapping, 4: report
			loading: false,
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
		/**
		 * @spec exclude Computed dropdown options from target-schema properties; UI presentation helper.
		 */
		targetPropertyOptions() {
			const options = this.targetProperties.map((prop) => ({
				label: `${prop.name} (${prop.type})`,
				value: prop.name,
			}))

			options.push({
				label: t('openregister', '(Do not map)'),
				value: null,
			})

			return options
		},

		/**
		 * @spec exclude Computed enablement guard for the migrate button; UI validation helper.
		 */
		canMigrate() {
			// Check if we have target register/schema and at least one property mapping
			const hasValidMappings = Object.values(this.uiMappings).some(
				(option) => option && option.value,
			)
			return this.targetRegister && this.targetSchema && hasValidMappings
		},
	},

	mounted() {
		this.initializeMigration()
	},

	methods: {
		/**
		 * @spec openspec/specs/entity-management-modals/spec.md
		 */
		initializeMigration() {
			this.initializeSelection()
			if (this.selectedObjects.length > 0) {
				this.loadAvailableRegisters()
			}
		},

		/**
		 * @spec exclude Fetches registers to populate the target-register select; UI form-loading plumbing.
		 */
		async loadAvailableRegisters() {
			this.loading = true
			try {
				const response = await fetch(
					'/index.php/apps/openregister/api/registers?_extend[]=schemas',
				)
				const data = await response.json()
				this.availableRegisters = data.results || []
			} catch (error) {
				console.error('Error loading registers:', error)
				this.availableRegisters = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @spec exclude Offers the schemas of the chosen register; UI reactivity plumbing.
		 */
		onRegisterChange() {
			this.availableSchemas = this.targetRegister?.schemas || []
			this.targetSchema = null
		},

		/**
		 * @spec exclude Loads schema properties when the target-schema select changes; UI reactivity plumbing.
		 */
		async onSchemaChange() {
			if (!this.targetSchema) {
				return
			}
			await this.loadSchemaProperties()
		},

		/**
		 * @spec exclude Wizard forward-navigation guard across migration steps; UI step plumbing.
		 */
		nextStep() {
			if (this.step === 1 && this.selectedObjects.length > 0) {
				this.step = 2
			} else if (this.step === 2 && this.targetRegister && this.targetSchema) {
				this.step = 3
			}
		},

		/**
		 * @spec exclude Wizard back-navigation across migration steps; UI step plumbing.
		 */
		previousStep() {
			if (this.step > 1) {
				this.step--
			}
		},

		/**
		 * @spec exclude Loads source+target schema properties and seeds mappings; UI form-loading plumbing.
		 */
		async loadSchemaProperties() {
			if (!schemaStore.schemaItem || !this.targetSchema) {
				return
			}

			try {
				// Load source schema properties
				this.sourceProperties = this.extractSchemaProperties(
					schemaStore.schemaItem,
				)

				// Load target schema properties
				await this.loadTargetProperties()

				// Initialize property mappings
				this.initializePropertyMappings()

				// Sync UI mappings
				this.convertMappingToUI()
			} catch (error) {
				console.error('Error loading schema properties:', error)
			}
		},

		/**
		 * Load the properties of the target schema.
		 *
		 * @return {Promise<void>}
		 */
		async loadTargetProperties() {
			const targetSchemaData = await fetchSchema(this.targetSchema.id)
			this.targetProperties = this.extractSchemaProperties(targetSchemaData)
		},

		/**
		 * @param {object} schema
		 * @spec exclude Flattens a schema definition into a name/type/required list; UI presentation helper.
		 */
		extractSchemaProperties(schema) {
			// Extract properties from schema definition
			const properties = []
			if (schema.properties) {
				Object.keys(schema.properties).forEach((key) => {
					properties.push({
						name: key,
						type: schema.properties[key].type || 'string',
						required: schema.required?.includes(key) || false,
					})
				})
			}
			return properties
		},

		/**
		 * @spec exclude Auto-maps same-named source/target properties on load; UI form-init plumbing.
		 */
		initializePropertyMappings() {
			this.mapping = {}
			this.uiMappings = {}

			// Auto-map properties with same names
			this.sourceProperties.forEach((sourceProp) => {
				const matchingTarget = this.targetProperties.find(
					(targetProp) => targetProp.name === sourceProp.name,
				)
				if (matchingTarget) {
					// Simple mapping: target property as key, source property as value
					this.mapping[matchingTarget.name] = sourceProp.name

					// Set up UI mapping
					const targetOption = this.targetPropertyOptions.find(
						(option) => option.value === matchingTarget.name,
					)
					if (targetOption) {
						this.uiMappings[sourceProp.name] = targetOption
					}
				}
			})
		},

		/**
		 * @spec exclude Migrate-confirm handler posting the mapping to the /migrate endpoint and refreshing the list; UI orchestration plumbing.
		 */
		async performMigration() {
			if (!this.canMigrate) {
				return
			}

			// Make sure our mapping is up to date before sending
			this.convertUIToMapping()

			this.loading = true
			try {
				this.migrationResult = await postMigration(this.migrationRequest())
				this.step = 4
				refreshObjectLists()
			} catch (error) {
				this.showMigrationFailure(error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Log a failed migration and show it on the report step.
		 *
		 * @param {Error} error What went wrong
		 * @return {void}
		 */
		showMigrationFailure(error) {
			console.error('Error performing migration:', error)
			this.migrationResult = failedTransfer(
				error,
				t('openregister', 'An error occurred during migration'),
			)
			this.step = 4
		},

		/**
		 * The migration to ask for: from the current register and schema to
		 * the chosen ones, for the selected objects, with the property mapping.
		 *
		 * @return {object} The migration request body
		 */
		migrationRequest() {
			return {
				sourceRegister: registerStore.registerItem.id,
				sourceSchema: schemaStore.schemaItem.id,
				targetRegister: this.targetRegister.id,
				targetSchema: this.targetSchema.id,
				objects: this.selectedObjects.map((obj) => obj.id),
				mapping: this.mapping,
			}
		},

		/**
		 * @spec exclude Modal close handler resetting navigationStore.modal; UI plumbing.
		 */
		closeDialog() {
			navigationStore.setModal(false)
		},

		/**
		 * @param {string} _sourceProperty
		 * @spec exclude UI-change handler re-syncing the mapping object; UI reactivity plumbing.
		 */
		updateMappingFromUI(_sourceProperty) {
			// Convert UI mappings to our simple mapping format
			this.convertUIToMapping()
		},

		/**
		 * @spec exclude Converts the UI mapping model to the API mapping shape; UI data-shape helper.
		 */
		convertUIToMapping() {
			// Convert from UI format (source -> target option) to our format (target -> source)
			this.mapping = {}

			for (const [sourceProp, targetOption] of Object.entries(
				this.uiMappings,
			)) {
				if (targetOption && targetOption.value) {
					this.mapping[targetOption.value] = sourceProp
				}
			}
		},

		/**
		 * @spec exclude Converts the API mapping shape back to the UI mapping model; UI data-shape helper.
		 */
		convertMappingToUI() {
			// Convert from our format (target -> source) to UI format (source -> target option)
			this.uiMappings = {}

			for (const [targetProp, sourceProp] of Object.entries(this.mapping)) {
				const targetOption = this.targetPropertyOptions.find(
					(option) => option.value === targetProp,
				)
				if (targetOption) {
					this.uiMappings[sourceProp] = targetOption
				}
			}
		},
	},
}

/**
 * Fetch a schema by id.
 *
 * @param {number|string} id The schema id
 * @return {Promise<object>} The schema
 */
async function fetchSchema(id) {
	const response = await fetch(`/index.php/apps/openregister/api/schemas/${id}`)
	return response.json()
}
</script>

<style scoped lang="scss">
@use '../../styles/modals.scss' as *;

.migration-step {
	padding: 0;
}

.step-1 {
	padding-top: 0 !important;
}

@include step-title-rules;

.migration-step h3 {
	margin-bottom: 16px;
	color: var(--color-main-text);
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

@include loading-container;

@include report-section;

@include report-section-h4-rules;

.report-section li {
	margin-bottom: 4px;
}

@include error-text-rules;

.detail-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 16px;
	margin: 16px 0;
}

@include detail-item;

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
</style>
