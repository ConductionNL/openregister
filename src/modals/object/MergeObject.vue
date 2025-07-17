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

		<!-- Information about merge restrictions (only show if not completed) -->
		<NcNoteCard v-if="step !== 3" type="info">
			Objects can only be merged if they belong to the same register and schema.
			If you want to merge objects from different schemas or registers, you need to migrate them first.
		</NcNoteCard>

		<!-- Step 1: Select Target Object -->
		<div v-if="step === 1" class="merge-step step-1">
			<h3 class="step-title">
				Select Target Object
			</h3>
			<p>Select the object to merge <strong>{{ sourceObject?.['@self']?.name || sourceObject?.name || sourceObject?.['@self']?.title || sourceObject?.['@self']?.id }}</strong> into:</p>

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
					class="object-item table-row-selectable"
					:class="{ 'table-row-selected': selectedTargetObject?.['@self']?.id === obj['@self'].id }"
					@click="selectTargetObject(obj)">
					<div class="object-info">
						<strong>{{ obj['@self']?.name || obj.name || obj.title || obj['@self']?.title || obj['@self']?.id }}</strong>
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
				Merging <strong>{{ sourceObject?.['@self']?.name || sourceObject?.name || sourceObject?.['@self']?.title || sourceObject?.['@self']?.id }}</strong>
				into <strong>{{ selectedTargetObject?.['@self']?.name || selectedTargetObject?.name || selectedTargetObject?.['@self']?.title || selectedTargetObject?.['@self']?.id }}</strong>
			</p>

			<!-- Property Comparison Table -->
			<div class="merge-table-container">
				<table class="merge-table">
					<thead>
						<tr>
							<th>Property</th>
							<th>Source</th>
							<th>Target</th>
							<th>Result Value</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="property in mergeableProperties" :key="property">
							<td class="property-name">
								{{ property }}
							</td>
							<td class="source-value" :title="displayValue(sourceObject[property], 1000)">
								{{ displayValue(sourceObject[property], 40) }}
							</td>
							<td class="target-value" :title="displayValue(selectedTargetObject[property], 1000)">
								{{ displayValue(selectedTargetObject[property], 40) }}
							</td>
							<td class="merge-target">
								<template v-if="property === 'id'">
									<span class="fixed-value">{{ selectedTargetObject[property] }} (Target ID)</span>
								</template>
								<template v-else>
									<NcSelect
										v-model="propertySelections[property]"
										:options="getMergeOptions(property)"
										label="label"
										track-by="value"
										:placeholder="'Choose value for ' + property"
										@input="onPropertySelectionChange(property, $event)" />
									<NcTextField
										v-if="mergedData[property] === 'custom'"
										v-model="customValues[property]"
										:placeholder="'Enter custom value for ' + property"
										class="custom-input" />
								</template>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- File Handling Options -->
			<div class="options-section">
				<h4>Files attached to source object: ({{ sourceFiles.length }})</h4>

				<div class="radio-options">
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

				<div class="table-toggle">
					<NcButton type="tertiary" @click="toggleFileList">
						{{ showFileList ? 'Hide Files' : 'View Files' }}
						<template #icon>
							<ChevronUp v-if="showFileList" :size="20" />
							<ChevronDown v-else :size="20" />
						</template>
					</NcButton>
				</div>

				<div v-if="showFileList && sourceFiles.length" class="file-list">
					<table class="file-table">
						<thead>
							<tr>
								<th>Filename</th>
								<th>Size</th>
								<th>Type</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="file in sourceFiles" :key="file.name || file.filename">
								<td :title="file.name || file.filename">
									{{ truncateText(file.name || file.filename, 40) }}
								</td>
								<td>{{ formatFileSize(file.size) }}</td>
								<td>{{ getFileType(file.name || file.filename) }}</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div v-else-if="showFileList && !sourceFiles.length" class="no-files">
					<p>No files attached to source object</p>
				</div>
			</div>

			<!-- Relation Handling Options -->
			<div class="options-section">
				<h4>Relations to source object: ({{ sourceRelations.length }})</h4>

				<div class="radio-options">
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

				<div class="table-toggle">
					<NcButton type="tertiary" @click="toggleRelationList">
						{{ showRelationList ? 'Hide Relations' : 'View Relations' }}
						<template #icon>
							<ChevronUp v-if="showRelationList" :size="20" />
							<ChevronDown v-else :size="20" />
						</template>
					</NcButton>
				</div>

				<div v-if="showRelationList && sourceRelations.length" class="relation-list">
					<table class="relation-table">
						<thead>
							<tr>
								<th>Related Object</th>
								<th>Relation Type</th>
								<th>Register/Schema</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="relation in sourceRelations" :key="relation.id">
								<td :title="relation.title || relation.name || relation.id">
									{{ truncateText(relation.title || relation.name || relation.id, 40) }}
								</td>
								<td>{{ relation.relationType || 'Related' }}</td>
								<td :title="(relation.register || 'N/A') + ' / ' + (relation.schema || 'N/A')">
									{{ truncateText((relation.register || 'N/A') + ' / ' + (relation.schema || 'N/A'), 30) }}
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div v-else-if="showRelationList && !sourceRelations.length" class="no-relations">
					<p>No relations to source object</p>
				</div>
			</div>
		</div>

		<!-- Step 3: Merge Report -->
		<div v-if="step === 3" class="merge-step">
			<h3 class="report-title">
				Merge Report
			</h3>

			<NcNoteCard v-if="mergeResult?.success" type="success">
				<p>Objects successfully merged!</p>
			</NcNoteCard>
			<NcNoteCard v-if="mergeResult && !mergeResult.success" type="error">
				<p>Merge failed. Please check the details below.</p>
			</NcNoteCard>

			<div v-if="mergeResult" class="merge-report">
				<!-- Object Information -->
				<div class="report-section">
					<h4>Merge Summary</h4>
					<div class="object-info">
						<div class="object-detail">
							<strong>Target Object (Result):</strong>
							<div class="object-meta">
								<span class="object-id">ID: {{ selectedTargetObject?.['@self']?.id || selectedTargetObject?.id }}</span>
								<span class="object-title">{{ selectedTargetObject?.['@self']?.name || selectedTargetObject?.name || selectedTargetObject?.['@self']?.title || selectedTargetObject?.title || 'Untitled' }}</span>
							</div>
						</div>
						<div class="object-detail">
							<strong>Source Object:</strong>
							<div class="object-meta">
								<span class="object-id">ID: {{ sourceObject?.['@self']?.id || sourceObject?.id }}</span>
								<span class="object-title">{{ sourceObject?.['@self']?.name || sourceObject?.name || sourceObject?.['@self']?.title || sourceObject?.title || 'Untitled' }}</span>
								<span class="object-status deleted">Status: Deleted</span>
							</div>
						</div>
					</div>
				</div>
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
						<li v-for="fileActionItem in mergeResult.actions.files" :key="fileActionItem.name">
							{{ fileActionItem.name }}: {{ fileActionItem.action }}
							<span v-if="!fileActionItem.success" class="error-text"> (Failed: {{ fileActionItem.error }})</span>
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

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ step === 3 ? 'Close' : 'Cancel' }}
			</NcButton>

			<NcButton v-if="step === 3 && mergeResult?.success"
				type="secondary"
				@click="viewMergedObject">
				<template #icon>
					<Eye :size="20" />
				</template>
				View Object
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
import Eye from 'vue-material-design-icons/Eye.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'

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
		Eye,
		ChevronDown,
		ChevronUp,
	},

	data() {
		return {
			step: 1, // 1: select target, 2: configure merge, 3: report
			loading: false,
			searchTerm: '',
			availableObjects: [],
			selectedTargetObject: null,
			mergedData: {},
			customValues: {},
			propertySelections: {}, // Intermediate values for NcSelect v-model
			fileAction: 'transfer',
			relationAction: 'transfer',
			mergeResult: null,
			showFileList: true,
			showRelationList: true,
			sourceFiles: [],
			sourceRelations: [],
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
			this.loadSourceData()
			this.searchObjects()
		},
		async searchObjects() {
			if (!registerStore.registerItem || !schemaStore.schemaItem) {
				return
			}

			this.loading = true
			try {
				const response = await fetch(`/index.php/apps/openregister/api/objects/${registerStore.registerItem.id}/${schemaStore.schemaItem.id}?_search=${this.searchTerm}`)
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
			this.customValues = {}
			this.propertySelections = {}

			this.mergeableProperties.forEach(property => {
				if (property === 'id') {
					// ID always uses target value
					this.mergedData[property] = this.selectedTargetObject[property]
				} else {
					// Default selection logic:
					// 1. Target value if it exists
					// 2. Source value if target doesn't exist but source does
					// 3. 'custom' if neither exists
					const targetValue = this.selectedTargetObject[property]
					const sourceValue = this.sourceObject[property]

					let selectedValue
					// Always store the actual value, never the option object
					if (targetValue !== undefined && targetValue !== null && targetValue !== '') {
						selectedValue = targetValue
					} else if (sourceValue !== undefined && sourceValue !== null && sourceValue !== '') {
						selectedValue = sourceValue
					} else {
						selectedValue = 'custom'
						this.customValues[property] = ''
					}

					this.mergedData[property] = selectedValue

					// Set up the selection object for the dropdown
					const options = this.getMergeOptions(property)
					this.propertySelections[property] = options.find(opt => opt.value === selectedValue) || null
				}
			})

			// eslint-disable-next-line no-console
			console.log('Initial mergedData after setup:', this.mergedData)
			// eslint-disable-next-line no-console
			console.log('Initial propertySelections after setup:', this.propertySelections)
		},
		getMergeOptions(property) {
			const options = []

			if (this.sourceObject[property] !== undefined && this.sourceObject[property] !== null && this.sourceObject[property] !== '') {
				options.push({
					label: `From Source: ${this.displayValue(this.sourceObject[property])}`,
					value: this.sourceObject[property],
				})
			}

			if (this.selectedTargetObject[property] !== undefined && this.selectedTargetObject[property] !== null && this.selectedTargetObject[property] !== '') {
				options.push({
					label: `From Target: ${this.displayValue(this.selectedTargetObject[property])}`,
					value: this.selectedTargetObject[property],
				})
			}

			// Always add the custom option
			options.push({
				label: 'Other/Custom',
				value: 'custom',
			})

			return options
		},

		onPropertySelectionChange(property, selectedOption) {
			// eslint-disable-next-line no-console
			console.log('Property selection change:', property, selectedOption)
			if (selectedOption && selectedOption.value !== undefined) {
				// Always store the actual value, never the option object
				this.mergedData[property] = selectedOption.value
				this.propertySelections[property] = selectedOption
				// eslint-disable-next-line no-console
				console.log('Set mergedData[' + property + '] to:', selectedOption.value)

				// Clear custom value if switching away from custom
				if (selectedOption.value !== 'custom') {
					this.customValues[property] = ''
				}
			}
		},
		displayValue(value, maxLength = 100) {
			if (value === null || value === undefined) {
				return 'N/A'
			}

			let displayText = ''
			if (typeof value === 'object') {
				displayText = JSON.stringify(value, null, 2)
			} else {
				displayText = String(value)
			}

			// Truncate if too long
			if (displayText.length > maxLength) {
				return displayText.substring(0, maxLength) + '...'
			}

			return displayText
		},
		truncateText(text, maxLength) {
			if (!text) return ''
			if (text.length <= maxLength) return text
			return text.substring(0, maxLength) + '...'
		},
		async performMerge() {
			if (!this.canMerge) {
				return
			}

			this.loading = true
			try {
				// Prepare merged data with custom values resolved - ensure no ID is included
				const finalMergedData = {}
				// eslint-disable-next-line no-console
				console.log('Raw mergedData before processing:', this.mergedData)

				Object.keys(this.mergedData).forEach(property => {
					// Skip any ID-related properties
					if (property === 'id' || property === '@self') {
						return
					}

					if (this.mergedData[property] === 'custom') {
						finalMergedData[property] = this.customValues[property] || ''
					} else {
						// Ensure we extract the actual value if it's an object with label/value structure
						const value = this.mergedData[property]
						if (value && typeof value === 'object' && value.value !== undefined) {
							// eslint-disable-next-line no-console
							console.log('Extracting value from object for', property, ':', value.value)
							finalMergedData[property] = value.value
						} else {
							finalMergedData[property] = value
						}
					}
				})

				// eslint-disable-next-line no-console
				console.log('Final merged data to send:', finalMergedData)

				// Use the object store method for consistent API handling
				const result = await objectStore.mergeObjects({
					register: registerStore.registerItem.id,
					schema: schemaStore.schemaItem.id,
					sourceObjectId: this.sourceObject['@self'].id,
					target: this.selectedTargetObject['@self'].id,
					object: finalMergedData,
					fileAction: this.fileAction,
					relationAction: this.relationAction,
				})

				this.mergeResult = result.data
				this.step = 3

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
		viewMergedObject() {
			// Navigate to the merged object in view mode
			if (this.selectedTargetObject) {
				objectStore.setObjectItem(this.selectedTargetObject)
				navigationStore.setModal('viewObject')
			}
		},
		toggleFileList() {
			this.showFileList = !this.showFileList
		},
		toggleRelationList() {
			this.showRelationList = !this.showRelationList
		},
		formatFileSize(bytes) {
			if (!bytes) return 'N/A'
			const sizes = ['Bytes', 'KB', 'MB', 'GB']
			const i = Math.floor(Math.log(bytes) / Math.log(1024))
			return Math.round(bytes / Math.pow(1024, i) * 100) / 100 + ' ' + sizes[i]
		},
		getFileType(filename) {
			if (!filename) return 'Unknown'
			const ext = filename.split('.').pop()?.toLowerCase()
			const types = {
				pdf: 'PDF',
				doc: 'Word',
				docx: 'Word',
				xls: 'Excel',
				xlsx: 'Excel',
				ppt: 'PowerPoint',
				pptx: 'PowerPoint',
				txt: 'Text',
				jpg: 'Image',
				jpeg: 'Image',
				png: 'Image',
				gif: 'Image',
				zip: 'Archive',
				rar: 'Archive',
			}
			return types[ext] || ext?.toUpperCase() || 'Unknown'
		},
		async loadSourceData() {
			// Load files and relations for the source object
			if (!this.sourceObject) return

			try {
				// Load files - check if sourceObject has attachments property
				if (this.sourceObject.attachments && Array.isArray(this.sourceObject.attachments)) {
					this.sourceFiles = this.sourceObject.attachments
				} else {
					this.sourceFiles = []
				}

				// Load relations - this would need to be implemented based on your API
				// For now, we'll use a placeholder
				this.sourceRelations = []

			} catch (error) {
				console.error('Error loading source data:', error)
				this.sourceFiles = []
				this.sourceRelations = []
			}
		},
		closeModal() {
			navigationStore.setModal(false)
		},
	},
}
</script>

<style scoped>
.merge-step {
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
	table-layout: fixed;
}

.merge-table th,
.merge-table td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.merge-table th {
	background-color: var(--color-background-dark);
	font-weight: bold;
	color: var(--color-main-text);
}

.property-name {
	font-weight: bold;
	color: var(--color-main-text);
	width: 15%;
	min-width: 120px;
}

.source-value,
.target-value {
	background-color: var(--color-background-hover);
	font-family: monospace;
	font-size: 0.9em;
	width: 25%;
	max-width: 200px;
	cursor: help;
}

.merge-target {
	width: 35%;
	min-width: 200px;
}

.fixed-value {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.custom-input {
	margin-top: 8px;
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

.radio-options {
	margin-bottom: 16px;
}

.table-toggle {
	margin-bottom: 12px;
	display: flex;
	justify-content: flex-start;
}

.file-list, .relation-list {
	margin: 12px 0;
	max-height: 300px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.file-table, .relation-table {
	width: 100%;
	border-collapse: collapse;
	table-layout: fixed;
}

.file-table th, .file-table td,
.relation-table th, .relation-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.file-table th:nth-child(1), .file-table td:nth-child(1) {
	width: 60%;
}

.file-table th:nth-child(2), .file-table td:nth-child(2) {
	width: 20%;
}

.file-table th:nth-child(3), .file-table td:nth-child(3) {
	width: 20%;
}

.relation-table th:nth-child(1), .relation-table td:nth-child(1) {
	width: 50%;
}

.relation-table th:nth-child(2), .relation-table td:nth-child(2) {
	width: 25%;
}

.relation-table th:nth-child(3), .relation-table td:nth-child(3) {
	width: 25%;
}

.file-table th, .relation-table th {
	background-color: var(--color-background-dark);
	font-weight: bold;
	position: sticky;
	top: 0;
	z-index: 1;
}

.file-table tbody tr:hover,
.relation-table tbody tr:hover {
	background-color: var(--color-background-hover);
}

.no-files, .no-relations {
	padding: 20px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.merge-report {
	margin-top: 16px;
}

.report-section {
	margin-bottom: 20px;
	padding: 16px;
	background-color: var(--color-background-hover);
	border-radius: 4px;
}

.object-info {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.object-detail {
	padding: 12px;
	background-color: var(--color-background-dark);
	border-radius: 4px;
}

.object-meta {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 8px;
}

.object-id {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	font-family: monospace;
}

.object-title {
	color: var(--color-main-text);
	font-weight: 500;
}

.object-status {
	font-size: 0.9em;
	font-weight: bold;
}

.object-status.deleted {
	color: var(--color-error);
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
