<script setup>
import { schemaStore, navigationStore, registerStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'validateSchema'"
		name="Validate Schema Objects"
		size="large"
		:can-close="false">
		
		<!-- Loading State -->
		<div v-if="loading" class="loading-container">
			<NcLoadingIcon :size="40" />
			<p>Validating objects against schema '{{ schemaStore.schemaItem?.title }}'...</p>
			<p class="loading-subtitle">This may take a moment for large datasets.</p>
		</div>

		<!-- Confirmation State -->
		<div v-else-if="!validationResults && !error" class="confirmation-container">
			<NcNoteCard type="info">
				<h4>Validate Schema Objects</h4>
				<p>This validation will check all objects belonging to this schema against their schema definition. The process involves examining each object's data structure and identifying any validation errors.</p>
			</NcNoteCard>
			
			<div class="object-count-section">
				<h4>Objects to be validated</h4>
				<div v-if="objectCount > 0" class="object-count-centered">
					<span class="count-value">{{ objectCount }}</span>
					<span class="count-label">objects</span>
				</div>
				<div v-else class="loading-count">
					<NcLoadingIcon :size="16" />
					Counting objects...
				</div>
			</div>

			<div class="steps-section">
				<h4>Validation steps:</h4>
				<ol class="steps-list">
					<li>Retrieve all objects for this schema</li>
					<li>Validate each object against the schema definition</li>
					<li>Check required fields and data types</li>
					<li>Verify format constraints and patterns</li>
					<li>Generate detailed validation results</li>
				</ol>
			</div>
		</div>

		<!-- Results State -->
		<div v-else-if="validationResults" class="results-container">
			<!-- Summary -->
			<div class="summary-section">
				<NcNoteCard :type="validationResults.invalid_count > 0 ? 'warning' : 'success'">
					<h3>Validation Summary</h3>
					<p><strong>Total Objects:</strong> {{ validationResults.valid_count + validationResults.invalid_count }}</p>
					<p><strong>Valid Objects:</strong> {{ validationResults.valid_count }}</p>
					<p><strong>Invalid Objects:</strong> {{ validationResults.invalid_count }}</p>
					<p v-if="validationResults.invalid_count > 0" class="warning-text">
						⚠️ {{ validationResults.invalid_count }} object(s) have validation errors that need attention.
					</p>
					<p v-else class="success-text">
						✅ All objects are valid according to the schema definition.
					</p>
				</NcNoteCard>
			</div>

			<!-- Filter Controls -->
			<div v-if="validationResults.invalid_count > 0" class="filter-section">
				<NcCheckboxRadioSwitch
					v-model="showOnlyInvalid"
					:name="'showOnlyInvalid'"
					:label="'Show only invalid objects'"
					type="switch" />
			</div>

			<!-- Results Table -->
			<div v-if="filteredResults.length > 0" class="results-table-section">
				<h3>{{ showOnlyInvalid ? 'Invalid Objects' : 'All Objects' }}</h3>
				<div class="table-container">
					<table class="validation-table">
						<thead>
							<tr>
								<th>Status</th>
								<th>ID</th>
								<th>Name</th>
								<th>UUID</th>
								<th>Errors</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="object in filteredResults" :key="object.uuid" 
								:class="{ 'invalid-row': object.errors && object.errors.length > 0 }">
								<td class="status-cell">
									<CheckCircle v-if="!object.errors || object.errors.length === 0" 
										:size="20" class="valid-icon" />
									<AlertCircle v-else :size="20" class="invalid-icon" />
								</td>
								<td>{{ object.id }}</td>
								<td>{{ object.name || 'Unnamed' }}</td>
								<td class="uuid-cell">{{ object.uuid }}</td>
								<td class="errors-cell">
									<div v-if="object.errors && object.errors.length > 0" class="errors-list">
										<div v-for="error in object.errors" :key="error.path" class="error-item">
											<strong>{{ error.path }}:</strong> {{ error.message }}
											<span class="error-keyword">({{ error.keyword }})</span>
										</div>
									</div>
									<span v-else class="no-errors">No errors</span>
								</td>
								<td class="actions-cell">
									<NcButton v-if="object.errors && object.errors.length > 0"
										size="small"
										@click="viewObjectDetails(object)">
										<template #icon>
											<Eye :size="16" />
										</template>
										View Details
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- No Results -->
			<div v-else class="no-results">
				<NcEmptyContent
					:title="showOnlyInvalid ? 'No invalid objects found' : 'No objects found'"
					:description="showOnlyInvalid ? 'All objects are valid!' : 'This schema has no objects to validate.'">
					<template #icon>
						<CheckCircle v-if="showOnlyInvalid" :size="40" />
						<DatabaseOutline v-else :size="40" />
					</template>
				</NcEmptyContent>
			</div>
		</div>

		<!-- Error State -->
		<NcNoteCard v-if="error" type="error">
			<h3>Validation Failed</h3>
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ validationResults ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton v-if="!validationResults && !loading && !error"
				:disabled="loading"
				type="primary"
				@click="startValidation()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<CheckCircle v-if="!loading" :size="20" />
				</template>
				Start Validation
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
	NcEmptyContent,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'

import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'

export default {
	name: 'ValidateSchema',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcEmptyContent,
		NcCheckboxRadioSwitch,
		CheckCircle,
		AlertCircle,
		Cancel,
		Eye,
		DatabaseOutline,
	},
	data() {
		return {
			loading: false,
			error: false,
			validationResults: null,
			showOnlyInvalid: false,
			objectCount: 0,
		}
	},
	async mounted() {
		await this.loadObjectCount()
	},
	computed: {
		filteredResults() {
			if (!this.validationResults) return []
			
			const allResults = [
				...this.validationResults.valid_objects.map(obj => ({ ...obj, errors: [] })),
				...this.validationResults.invalid_objects
			]
			
			if (this.showOnlyInvalid) {
				return allResults.filter(obj => obj.errors && obj.errors.length > 0)
			}
			
			return allResults
		},
	},
	methods: {
		async loadObjectCount() {
			try {
				if (schemaStore.schemaItem?.id) {
					// First try to use existing stats if available
					if (schemaStore.schemaItem.stats?.objects?.total !== undefined) {
						this.objectCount = schemaStore.schemaItem.stats.objects.total
						console.log('Using schema item stats:', this.objectCount)
					} else {
						// Fallback to API call
						this.objectCount = await schemaStore.getObjectCount(schemaStore.schemaItem.id)
					}
				}
			} catch (err) {
				console.warn('Could not load object count:', err)
				this.objectCount = 0
			}
		},
		
		async startValidation() {
			this.loading = true
			this.error = false
			
			try {
				// Call the new direct validation API
				const response = await fetch(
					`/index.php/apps/openregister/api/bulk/schema/${schemaStore.schemaItem.id}/validate`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
						},
					}
				)
				
				if (!response.ok) {
					throw new Error(`Validation failed: ${response.statusText}`)
				}
				
				const data = await response.json()
				
				if (data.error) {
					throw new Error(data.error)
				}
				
				this.validationResults = data
				
			} catch (err) {
				this.error = err.message || 'An error occurred during validation'
				console.error('Validation error:', err)
			} finally {
				this.loading = false
			}
		},
		
		viewObjectDetails(object) {
			// Navigate to object details view
			// This would need to be implemented based on your navigation structure
			console.log('View object details:', object)
		},
		
		closeDialog() {
			navigationStore.setDialog(false)
			this.loading = false
			this.error = false
			this.validationResults = null
			this.showOnlyInvalid = false
			this.objectCount = 0
		},
	},
}
</script>

<style scoped>
.loading-container {
	text-align: center;
	padding: 2rem;
}

.loading-subtitle {
	color: var(--color-text-lighter);
	font-size: 0.9rem;
	margin-top: 0.5rem;
}

.confirmation-container {
	padding: 1rem 0;
}

.object-count-section {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 1rem;
	margin-bottom: 2rem;
}

.object-count-section h4 {
	margin-top: 0;
	margin-bottom: 1rem;
	color: var(--color-text);
}

.object-count-centered {
	display: flex;
	align-items: baseline;
	justify-content: center;
	gap: 0.5rem;
	font-size: 1.2rem;
}

.count-value {
	font-size: 2rem;
	font-weight: bold;
	color: var(--color-primary-element);
}

.count-label {
	color: var(--color-text-lighter);
}

.loading-count {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 0.5rem;
	color: var(--color-text-lighter);
}

.steps-section {
	margin-bottom: 2rem;
}

.steps-section h4 {
	margin-bottom: 1rem;
	color: var(--color-text);
}

.steps-list {
	margin: 0;
	padding-left: 1.5rem;
	color: var(--color-text);
}

.steps-list li {
	margin-bottom: 0.5rem;
	line-height: 1.5;
}

.results-container {
	padding: 1rem 0;
}

.summary-section {
	margin-bottom: 1.5rem;
}

.filter-section {
	margin-bottom: 1rem;
	padding: 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.results-table-section h3 {
	margin-bottom: 1rem;
}

.table-container {
	max-height: 400px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.validation-table {
	width: 100%;
	border-collapse: collapse;
}

.validation-table th,
.validation-table td {
	padding: 0.75rem;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.validation-table th {
	background: var(--color-background-hover);
	font-weight: 600;
	position: sticky;
	top: 0;
	z-index: 1;
}

.invalid-row {
	background: var(--color-warning-light);
}

.status-cell {
	text-align: center;
}

.valid-icon {
	color: var(--color-success);
}

.invalid-icon {
	color: var(--color-warning);
}

.uuid-cell {
	font-family: monospace;
	font-size: 0.85rem;
}

.errors-cell {
	max-width: 300px;
}

.errors-list {
	max-height: 100px;
	overflow-y: auto;
}

.error-item {
	margin-bottom: 0.5rem;
	font-size: 0.9rem;
}

.error-keyword {
	color: var(--color-text-lighter);
	font-style: italic;
}

.no-errors {
	color: var(--color-success);
	font-style: italic;
}

.actions-cell {
	text-align: center;
}

.no-results {
	padding: 2rem;
	text-align: center;
}

.warning-text {
	color: var(--color-warning);
	font-weight: 600;
}

.success-text {
	color: var(--color-success);
	font-weight: 600;
}
</style>
