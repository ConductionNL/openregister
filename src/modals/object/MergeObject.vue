<script setup>
import { objectStore, navigationStore, registerStore, schemaStore } from '../../store/store.js'
</script>

<template>
	<NcDialog name="Merge Objects"
		size="large"
		:can-close="false">
		<!-- Register and Schema Information -->
		<div class="detail-grid">
			<div class="detail-item">
				<span class="detail-label">Register:</span>
				<span class="detail-value">{{ registerStore.registerItem?.title || registerStore.registerItem?.id }}</span>
			</div>
			<div class="detail-item">
				<span class="detail-label">Schema:</span>
				<span class="detail-value">{{ schemaStore.schemaItem?.title || schemaStore.schemaItem?.id }}</span>
			</div>
		</div>

		<!-- Information about merge restrictions -->
		<NcNoteCard type="info">
			Objects can only be merged if they belong to the same register and schema.
			If you want to merge objects from different schemas or registers, you need to migrate them first.
		</NcNoteCard>

		<!-- Dialog Content Wrapper -->
		<div class="dialog-content">
			<!-- Step 1: Select Target Object -->
			<div v-if="step === 1" class="merge-step">
				<h3>Select Target Object</h3>
				<p>Select the object to merge <strong>{{ sourceObject?.['@self']?.title || sourceObject?.['@self']?.id }}</strong> into:</p>

				<div class="search-container">
					<NcTextField
						v-model="searchTerm"
						label="Search objects"
						placeholder="Type to search for objects..."
						@input="searchObjects" />
				</div>

				<div v-if="loading" class="loading-container">
					<NcLoadingIcon :size="32" />
					<p>Loading objects...</p>
				</div>

				<div v-else-if="availableObjects.length" class="object-list">
					<div v-for="obj in availableObjects"
						:key="obj['@self'].id"
						class="object-item"
						:class="{ selected: selectedTargetObject?.['@self']?.id === obj['@self'].id }"
						@click="selectTargetObject(obj)">
						<div class="object-info">
							<strong>{{ obj['@self'].title || obj.name || obj.title || obj['@self'].id }}</strong>
							<p class="object-id">
								ID: {{ obj['@self'].id }}
							</p>
						</div>
					</div>
				</div>

				<NcEmptyContent v-else-if="!loading" name="No objects found">
					<template #description>
						{{ searchTerm ? 'No objects match your search criteria' : 'No objects available for merging' }}
					</template>
				</NcEmptyContent>
			</div>

			<!-- Step 2: Merge Configuration -->
			<div v-if="step === 2" class="merge-step">
				<h3>Configure Merge</h3>
				<p>
					Merging <strong>{{ sourceObject?.['@self']?.title || sourceObject?.['@self']?.id }}</strong>
					into <strong>{{ selectedTargetObject?.['@self']?.title || selectedTargetObject?.['@self']?.id }}</strong>
				</p>

				<!-- Property Comparison Table -->
				<div class="merge-table-container">
					<table class="merge-table">
						<thead>
							<tr>
								<th>Property</th>
								<th>Source (A)</th>
								<th>Merge Target</th>
								<th>Target (B)</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="property in mergeableProperties" :key="property">
								<td class="property-name">
									{{ property }}
								</td>
								<td class="source-value">
									{{ displayValue(sourceObject[property]) }}
								</td>
								<td class="merge-target">
									<NcSelect
										v-model="mergedData[property]"
										:options="getMergeOptions(property)"
										label="label"
										track-by="value"
										:placeholder="'Choose value for ' + property" />
								</td>
								<td class="target-value">
									{{ displayValue(selectedTargetObject[property]) }}
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- File Handling Options -->
				<div class="options-section">
					<h4>Files attached to source object:</h4>
					<NcCheckboxRadioSwitch
						v-model="fileAction"
						value="transfer"
						name="fileAction"
						type="radio">
						Transfer to target object's folder
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="fileAction"
						value="delete"
						name="fileAction"
						type="radio">
						Delete files
					</NcCheckboxRadioSwitch>
				</div>

				<!-- Relation Handling Options -->
				<div class="options-section">
					<h4>Relations of source object:</h4>
					<NcCheckboxRadioSwitch
						v-model="relationAction"
						value="transfer"
						name="relationAction"
						type="radio">
						Transfer to target object
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="relationAction"
						value="drop"
						name="relationAction"
						type="radio">
						Drop relations
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<!-- Step 3: Merge Report -->
			<div v-if="step === 3" class="merge-step">
				<h3>Merge Report</h3>

				<NcNoteCard v-if="mergeResult?.success" type="success">
					<p>Objects successfully merged!</p>
				</NcNoteCard>
				<NcNoteCard v-if="mergeResult && !mergeResult.success" type="error">
					<p>Merge failed. Please check the details below.</p>
				</NcNoteCard>

				<div v-if="mergeResult" class="merge-report">
					<!-- Statistics -->
					<div class="report-section">
						<h4>Statistics</h4>
						<ul>
							<li>Properties changed: {{ mergeResult.statistics?.propertiesChanged || 0 }}</li>
							<li>Files transferred: {{ mergeResult.statistics?.filesTransferred || 0 }}</li>
							<li>Files deleted: {{ mergeResult.statistics?.filesDeleted || 0 }}</li>
							<li>Relations transferred: {{ mergeResult.statistics?.relationsTransferred || 0 }}</li>
							<li>Relations dropped: {{ mergeResult.statistics?.relationsDropped || 0 }}</li>
							<li>References updated: {{ mergeResult.statistics?.referencesUpdated || 0 }}</li>
						</ul>
					</div>

					<!-- Changed Properties -->
					<div v-if="mergeResult.actions?.properties?.length" class="report-section">
						<h4>Changed Properties</h4>
						<table class="report-table">
							<thead>
								<tr>
									<th>Property</th>
									<th>Old Value</th>
									<th>New Value</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="change in mergeResult.actions.properties" :key="change.property">
									<td>{{ change.property }}</td>
									<td>{{ displayValue(change.oldValue) }}</td>
									<td>{{ displayValue(change.newValue) }}</td>
								</tr>
							</tbody>
						</table>
					</div>

					<!-- File Actions -->
					<div v-if="mergeResult.actions?.files?.length" class="report-section">
						<h4>File Actions</h4>
						<ul>
							<li v-for="fileAction in mergeResult.actions.files" :key="fileAction.name">
								{{ fileAction.name }}: {{ fileAction.action }}
								<span v-if="!fileAction.success" class="error-text"> (Failed: {{ fileAction.error }})</span>
							</li>
						</ul>
					</div>

					<!-- Warnings -->
					<div v-if="mergeResult.warnings?.length" class="report-section">
						<h4>Warnings</h4>
						<ul>
							<li v-for="warning in mergeResult.warnings" :key="warning" class="warning-text">
								{{ warning }}
							</li>
						</ul>
					</div>

					<!-- Errors -->
					<div v-if="mergeResult.errors?.length" class="report-section">
						<h4>Errors</h4>
						<ul>
							<li v-for="error in mergeResult.errors" :key="error" class="error-text">
								{{ error }}
							</li>
						</ul>
					</div>
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ step === 3 ? 'Close' : 'Cancel' }}
			</NcButton>

			<NcButton v-if="step === 1"
				:disabled="!selectedTargetObject"
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
				:disabled="loading || !canMerge"
				type="primary"
				@click="performMerge">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Merge v-else :size="20" />
				</template>
				Merge Objects
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcTextField,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'

// Icons
import Cancel from 'vue-material-design-icons/Cancel.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Merge from 'vue-material-design-icons/Merge.vue'

export default {
	name: 'MergeObject',
	components: {
		NcButton,
		NcDialog,
		NcTextField,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		Cancel,
		ArrowRight,
		ArrowLeft,
		Merge,
	},

	data() {
		return {
			step: 1, // 1: select target, 2: configure merge, 3: report
			loading: false,
			searchTerm: '',
			availableObjects: [],
			selectedTargetObject: null,
			mergedData: {},
			fileAction: 'transfer',
			relationAction: 'transfer',
			mergeResult: null,
		}
	},
	computed: {
		sourceObject() {
			return objectStore.objectItem
		},
		mergeableProperties() {
			if (!this.sourceObject || !this.selectedTargetObject) {
				return []
			}

			const sourceProps = Object.keys(this.sourceObject).filter(key => !key.startsWith('@') && !key.startsWith('_'))
			const targetProps = Object.keys(this.selectedTargetObject).filter(key => !key.startsWith('@') && !key.startsWith('_'))

			return [...new Set([...sourceProps, ...targetProps])]
		},
		canMerge() {
			return Object.keys(this.mergedData).length > 0
		},
	},
	mounted() {
		this.initializeMerge()
	},
	methods: {
		initializeMerge() {
			if (!this.sourceObject) {
				this.closeModal()
				return
			}
			this.searchObjects()
		},
		async searchObjects() {
			if (!registerStore.registerItem || !schemaStore.schemaItem) {
				return
			}

			this.loading = true
			try {
				const response = await fetch(`/apps/openregister/api/objects/${registerStore.registerItem.id}/${schemaStore.schemaItem.id}?_search=${this.searchTerm}`)
				const data = await response.json()

				// Filter out the source object
				this.availableObjects = data.results.filter(obj => obj['@self'].id !== this.sourceObject['@self'].id)
			} catch (error) {
				console.error('Error searching objects:', error)
				this.availableObjects = []
			} finally {
				this.loading = false
			}
		},
		selectTargetObject(obj) {
			this.selectedTargetObject = obj
		},
		nextStep() {
			if (this.step === 1 && this.selectedTargetObject) {
				this.step = 2
				this.initializeMergeData()
			}
		},
		previousStep() {
			if (this.step === 2) {
				this.step = 1
			}
		},
		initializeMergeData() {
			// Initialize merge data with default values
			this.mergedData = {}
			this.mergeableProperties.forEach(property => {
				// Default to target object value if it exists, otherwise source value
				this.mergedData[property] = this.selectedTargetObject[property] ?? this.sourceObject[property]
			})
		},
		getMergeOptions(property) {
			const options = []

			if (this.sourceObject[property] !== undefined) {
				options.push({
					label: `From Source: ${this.displayValue(this.sourceObject[property])}`,
					value: this.sourceObject[property],
				})
			}

			if (this.selectedTargetObject[property] !== undefined) {
				options.push({
					label: `From Target: ${this.displayValue(this.selectedTargetObject[property])}`,
					value: this.selectedTargetObject[property],
				})
			}

			return options
		},
		displayValue(value) {
			if (value === null || value === undefined) {
				return 'N/A'
			}

			if (typeof value === 'object') {
				return JSON.stringify(value, null, 2)
			}

			return String(value)
		},
		async performMerge() {
			if (!this.canMerge) {
				return
			}

			this.loading = true
			try {
				const response = await fetch(`/apps/openregister/api/objects/${registerStore.registerItem.id}/${schemaStore.schemaItem.id}/${this.sourceObject['@self'].id}/merge`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						targetObjectId: this.selectedTargetObject['@self'].id,
						mergedData: this.mergedData,
						fileAction: this.fileAction,
						relationAction: this.relationAction,
					}),
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				this.mergeResult = await response.json()
				this.step = 3

				// Refresh the object list
				objectStore.refreshObjectList()

			} catch (error) {
				console.error('Error performing merge:', error)
				this.mergeResult = {
					success: false,
					errors: [error.message || 'An error occurred during merge'],
				}
				this.step = 3
			} finally {
				this.loading = false
			}
		},
		closeModal() {
			navigationStore.setModal(false)
		},
	},
}
</script>

<style scoped>
.dialog-content {
	padding: 0 16px;
}

.merge-step {
	padding: 20px 0;
}

.merge-step h3 {
	margin-bottom: 16px;
	color: var(--color-main-text);
}

.search-container {
	margin-bottom: 20px;
}

.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 16px;
	padding: 40px;
}

.object-list {
	max-height: 400px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.object-item {
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
	transition: background-color 0.2s;
}

.object-item:hover {
	background-color: var(--color-background-hover);
}

.object-item.selected {
	background-color: var(--color-primary-light);
	border-left: 3px solid var(--color-primary);
}

.object-item:last-child {
	border-bottom: none;
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

.merge-table-container {
	margin: 20px 0;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	overflow-x: auto;
}

.merge-table {
	width: 100%;
	border-collapse: collapse;
}

.merge-table th,
.merge-table td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.merge-table th {
	background-color: var(--color-background-dark);
	font-weight: bold;
	color: var(--color-main-text);
}

.property-name {
	font-weight: bold;
	color: var(--color-main-text);
	min-width: 120px;
}

.source-value,
.target-value {
	background-color: var(--color-background-hover);
	font-family: monospace;
	font-size: 0.9em;
	max-width: 200px;
	word-break: break-word;
}

.merge-target {
	min-width: 200px;
}

.options-section {
	margin: 20px 0;
	padding: 16px;
	background-color: var(--color-background-hover);
	border-radius: 4px;
}

.options-section h4 {
	margin-bottom: 12px;
	color: var(--color-main-text);
}

.merge-report {
	margin-top: 20px;
}

.report-section {
	margin-bottom: 24px;
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

.report-table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 8px;
}

.report-table th,
.report-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.report-table th {
	background-color: var(--color-background-dark);
	font-weight: bold;
}

.error-text {
	color: var(--color-error);
}

.warning-text {
	color: var(--color-warning);
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
