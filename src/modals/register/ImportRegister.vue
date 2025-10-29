<script setup>
import { registerStore, schemaStore, navigationStore, objectStore, dashboardStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'importRegister'"
		name="import"
		title="Import Data into Register"
		size="large"
		:can-close="true"
		@update:open="handleDialogClose">
		<NcNoteCard v-if="success && importSummary && !hasImportErrors" type="success">
			<p>Register imported successfully!</p>
			<p><small>The register list is being refreshed in the background.</small></p>
		</NcNoteCard>

		<NcNoteCard v-if="success && importSummary && hasImportErrors" type="warning">
			<p>Import completed with errors!</p>
			<p><small>Some objects could not be imported. Check the details below.</small></p>
		</NcNoteCard>

		<div v-if="importResults" class="importResults">
			<!-- Summary Table -->
			<div class="summaryTable">
				<h3>Import Summary</h3>
				<table class="sheetSummaryTable">
					<thead>
						<tr>
							<th>Sheet</th>
							<th>Found</th>
							<th>Created</th>
							<th>Updated</th>
							<th>Unchanged</th>
							<th>Invalid</th>
							<th>Errors</th>
							<th>Total</th>
						</tr>
					</thead>
					<tbody>
						<template v-for="(sheetSummary, sheetKey) in importResults">
							<tr :key="sheetKey">
								<td class="sheetName">
									{{ sheetKey }}
									<div v-if="sheetSummary.schema" class="schemaInfo">
										<small>Schema: {{ sheetSummary.schema.title }}</small>
									</div>
									<div v-if="sheetSummary.errors && sheetSummary.errors.some(error => error.type === 'MissingIdColumnException')" class="errorInfo">
										<small class="errorText">Missing required "id" column</small>
									</div>
									<div v-else-if="sheetSummary.errors && sheetSummary.errors.some(error => error.type === 'InvalidUuidException')" class="errorInfo">
										<small class="errorText">Invalid UUID format in ID column</small>
									</div>
									<div v-else-if="sheetSummary.errors && sheetSummary.errors.some(error => error.type === 'ValidationException')" class="errorInfo">
										<small class="errorText">{{ getInvalidCount(sheetSummary) }} objects failed validation</small>
									</div>
									<div v-else-if="sheetSummary.found === 0 && sheetSummary.errors && sheetSummary.errors.length > 0" class="errorInfo">
										<small class="errorText">No data found - check schema matching or cache issues</small>
									</div>
									<div v-if="isCacheRelatedError(sheetSummary)" class="cacheWarning">
										<small class="warningText">⚠️ Cache-related error detected. Try refreshing the page or contact support.</small>
									</div>
									<div v-if="sheetSummary.found === 0 && sheetSummary.errors && sheetSummary.errors.length === 0" class="infoInfo">
										<small class="infoText">Sheet appears empty or has no matching data</small>
									</div>
									<div v-if="sheetSummary.found === 0 && sheetSummary.debug && sheetSummary.debug.headers && Array.isArray(sheetSummary.debug.headers)" class="debugInfo">
										<small class="debugText">
											Headers: {{ sheetSummary.debug.headers.join(', ') }}<br>
											Schema properties: {{ Array.isArray(sheetSummary.debug.schemaProperties) ? sheetSummary.debug.schemaProperties.join(', ') : 'N/A' }}
										</small>
									</div>
								</td>
								<td class="statCell found">
									{{ sheetSummary.found || 0 }}
								</td>
								<td class="statCell created">
									{{ (sheetSummary.created && sheetSummary.created.length) || 0 }}
								</td>
								<td class="statCell updated">
									{{ (sheetSummary.updated && sheetSummary.updated.length) || 0 }}
								</td>
								<td class="statCell unchanged">
									{{ (sheetSummary.unchanged && sheetSummary.unchanged.length) || 0 }}
								</td>
								<td class="statCell invalid">
									{{ getInvalidCount(sheetSummary) }}
								</td>
								<td class="statCell errors">
									<div class="errorCell">
										<span>{{ (sheetSummary.errors && sheetSummary.errors.length) || 0 }}</span>
										<button
											v-if="sheetSummary.errors && sheetSummary.errors.length > 0"
											class="expandButton"
											:class="{ expanded: expandedErrors[sheetKey] }"
											@click="toggleErrorDetails(sheetKey)">
											<ChevronDown :size="16" />
										</button>
									</div>
								</td>
								<td class="statCell total">
									{{
										((sheetSummary.created && sheetSummary.created.length) || 0) +
											((sheetSummary.updated && sheetSummary.updated.length) || 0) +
											((sheetSummary.unchanged && sheetSummary.unchanged.length) || 0) +
											getInvalidCount(sheetSummary) +
											((sheetSummary.errors && sheetSummary.errors.length) || 0)
									}}
								</td>
							</tr>
							<!-- Error Details Row -->
							<tr v-if="expandedErrors[sheetKey] && sheetSummary.errors && sheetSummary.errors.length > 0" :key="`${sheetKey}-errors`" class="errorDetailsRow">
								<td colspan="8" class="errorDetailsCell">
									<div class="errorDetailsTable">
										<table class="errorTable">
											<thead>
												<tr>
													<th>Row</th>
													<th>Error Type</th>
													<th>Error Message</th>
													<th>Data</th>
												</tr>
											</thead>
											<tbody>
												<tr v-for="(importError, index) in sheetSummary.errors" :key="index" class="errorRow">
													<td class="errorRowNumber">
														{{ importError.row }}
													</td>
													<td class="errorType">
														{{ importError.type || 'Unknown' }}
													</td>
													<td class="errorMessage">
														{{ importError.error }}
														<div v-if="importError.debug" class="errorDebugInfo">
															<small>File: {{ importError.debug.file }}</small><br>
															<small>Line: {{ importError.debug.line }}</small><br>
															<small>Class: {{ importError.debug.class }}</small>
														</div>
													</td>
													<td class="errorData">
														<pre v-if="importError.data && Object.keys(importError.data).length > 0">{{ JSON.stringify(importError.data, null, 2) }}</pre>
														<span v-else class="noData">No data</span>
													</td>
												</tr>
											</tbody>
										</table>
									</div>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>

			<NcButton type="secondary"
				style="margin-top: 1rem;"
				@click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Close
			</NcButton>
		</div>

		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<!-- Heartbeat indicator for long imports -->
		<NcNoteCard v-if="loading && isHeartbeatActive" type="info">
			<p>🔄 Processing large file - keeping connection alive...</p>
			<p><small>This may take several minutes for large datasets. The system is actively preventing timeouts.</small></p>
			<div v-if="heartbeatStatus && heartbeatStatus.count > 0" class="heartbeatStatus">
				<small>
					<span v-if="heartbeatStatus.healthy" class="heartbeatHealthy">✓ Connection stable</span>
					<span v-else class="heartbeatUnhealthy">⚠ Connection issues detected</span>
					({{ heartbeatStatus.count }} heartbeats sent)
				</small>
			</div>
		</NcNoteCard>

		<div v-if="!success && !importResults" class="formContainer">
			<input
				ref="fileInput"
				type="file"
				accept=".json,.xlsx,.xls,.csv"
				style="display: none"
				@change="handleFileUpload">

			<div class="fileSelection">
				<NcButton @click="$refs.fileInput.click()">
					<template #icon>
						<Upload :size="20" />
					</template>
					Select File
				</NcButton>
				<div v-if="selectedFile" class="selectedFile">
					<div class="fileInfo">
						<span class="fileName">{{ selectedFile.name }}</span>
						<span class="fileType">({{ getFileType(selectedFile.name) }})</span>
					</div>
					<div class="fileSize">
						{{ formatFileSize(selectedFile.size) }}
					</div>
				</div>
			</div>

			<NcSelect v-if="selectedFile && (getFileExtension(selectedFile?.name) === 'xlsx' || getFileExtension(selectedFile.name) === 'xls' || getFileExtension(selectedFile.name) === 'csv')"
				v-bind="registerOptions"
				:model-value="selectedRegisterValue"
				:loading="registerLoading"
				:disabled="registerLoading"
				aria-label-combobox="Select a register"
				placeholder="Select a register"
				@update:model-value="handleRegisterChange" />

			<NcSelect v-if="selectedFile && (getFileExtension(selectedFile?.name) === 'csv')"
				v-bind="schemaOptions"
				:model-value="selectedSchemaValue"
				:loading="schemaLoading"
				:disabled="!registerStore.registerItem || schemaLoading"
				aria-label-combobox="Select a schema"
				placeholder="Select a schema"
				@update:model-value="handleSchemaChange" />

			<div class="fileTypes">
				<p class="fileTypesTitle">
					Supported file types:
				</p>
				<ul class="fileTypesList">
					<li>
						<strong>JSON</strong> - Register configuration and objects.<br>
						<em>You can create or update objects for multiple schemas at once.</em>
					</li>
					<li>
						<strong>Excel</strong> (.xlsx, .xls) - Objects data.<br>
						<em>You can create or update objects for multiple schemas at once.</em>
					</li>
					<li>
						<strong>CSV</strong> - Objects data.<br>
						<em>You can only update one schema within a register.</em>
					</li>
				</ul>
				<div class="importRequirements">
					<p class="requirementsTitle">
						<strong>Import Requirements:</strong>
					</p>
					<ul class="requirementsList">
						<li>Every sheet must have an <strong>"id"</strong> column for object identification</li>
						<li>Empty "id" values create new objects, existing "id" values update objects</li>
						<li>ID values must be valid UUID format (e.g., 123e4567-e89b-12d3-a456-426614174000)</li>
						<li>Metadata columns (starting with "_") are automatically ignored</li>
					</ul>
				</div>
			</div>

			<div class="importOptions">
				<NcCheckboxRadioSwitch
					:checked="includeObjects"
					type="switch"
					@update:checked="includeObjects = $event">
					Include objects in the import
					<template #helper>
						This will create or update objects on the register
					</template>
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch
					:checked="validation"
					type="switch"
					@update:checked="validation = $event">
					Enable validation
					<template #helper>
						Validate objects against schema definitions before saving. Invalid objects will be excluded from import.
					</template>
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch
					:checked="events"
					type="switch"
					@update:checked="events = $event">
					Enable events (experimental)
					<template #helper>
						Dispatch object lifecycle events during bulk operations. May impact performance.
					</template>
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch
					:checked="rbac"
					type="switch"
					@update:checked="rbac = $event">
					Enable RBAC (Role-Based Access Control)
					<template #helper>
						Apply role-based access control checks during import. Recommended for production environments.
					</template>
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch
					:checked="multi"
					type="switch"
					@update:checked="multi = $event">
					Enable Multi-tenancy
					<template #helper>
						Apply multi-tenancy filtering during import. Recommended for multi-organization setups.
					</template>
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch
					:checked="publish"
					type="switch"
					@update:checked="publish = $event">
					Auto-publish imported objects
					<template #helper>
						Automatically set the published date for all created and updated objects to the current timestamp.
					</template>
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<template v-if="!success && !importResults" #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Cancel
			</NcButton>
			<NcButton
				:disabled="loading || !selectedFile || !isValidFileType || !checkDataCompleted()"
				type="primary"
				@click="importRegister">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Import v-else :size="20" />
				</template>
				Import
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
	NcCheckboxRadioSwitch,
	NcSelect,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Import from 'vue-material-design-icons/Import.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'

export default {
	name: 'ImportRegister',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		NcSelect,
		// Icons
		Cancel,
		Import,
		Upload,
		ChevronDown,
	},
	/**
	 * Component data properties
	 * @return {object}
	 */
	data() {
		return {
			selectedFile: null, // The file selected for import
			loading: false, // Loading state
			success: false, // Success state
			error: null, // Error message
			includeObjects: true, // Whether to include objects (default: true)
			validation: true, // Whether to enable validation (default: true)
			events: false, // Whether to enable events (default: false)
			rbac: true, // Whether to enable RBAC (default: true)
			multi: true, // Whether to enable multi-tenancy (default: true)
			publish: false, // Whether to auto-publish imported objects (default: false)
			allowedFileTypes: ['json', 'xlsx', 'xls', 'csv'], // Allowed file types
			importSummary: null, // The import summary from the backend
			importResults: null, // The import results for display
			registerLoading: false,
			schemaLoading: false,
			expandedSheets: {}, // To track expanded details for each sheet
			expandedErrors: {}, // To track expanded error details for each sheet
			sheetName: null, // Current sheet name for error details
			isHeartbeatActive: false, // Whether heartbeat is currently running for timeout prevention
			heartbeatStatus: null, // Current heartbeat status (healthy, failure count, etc.)
		}
	},
	computed: {
		/**
		 * Check if the selected file type is valid
		 * @return {boolean}
		 */
		isValidFileType() {
			if (!this.selectedFile) return false
			const extension = this.getFileExtension(this.selectedFile.name)
			return this.allowedFileTypes.includes(extension)
		},
		registerOptions() {
			return {
				options: registerStore.registerList.map(register => ({
					value: register.id,
					label: register.title,
					title: register.title,
					register,
				})),
				reduce: option => option.register,
				label: 'title',
				getOptionLabel: option => {
					return option.title || (option.register && option.register.title) || option.label || ''
				},
			}
		},
		schemaOptions() {
			if (!registerStore.registerItem) return { options: [] }

			return {
				options: schemaStore.schemaList
					.filter(schema => registerStore.registerItem.schemas.includes(schema.id))
					.map(schema => ({
						value: schema.id,
						label: schema.title,
						title: schema.title,
						schema,
					})),
				reduce: option => option.schema,
				label: 'title',
				getOptionLabel: option => {
					return option.title || (option.schema && option.schema.title) || option.label || ''
				},
			}
		},
		selectedRegisterValue() {
			if (!registerStore.registerItem) return null
			const register = registerStore.registerItem
			return {
				value: register.id,
				label: register.title,
				title: register.title,
				register,
			}
		},
		selectedSchemaValue() {
			if (!schemaStore.schemaItem) return null
			const schema = schemaStore.schemaItem
			return {
				value: schema.id,
				label: schema.title,
				title: schema.title,
				schema,
			}
		},
		/**
		 * Check if there are any errors in the import results
		 * @return {boolean} - Whether there are import errors
		 */
		hasImportErrors() {
			if (!this.importResults) return false

			// Check if any sheet has errors
			return Object.values(this.importResults).some(sheetSummary =>
				sheetSummary.errors && sheetSummary.errors.length > 0,
			)
		},
	},
	mounted() {
		dashboardStore.preload()
		this.registerLoading = true
		this.schemaLoading = true

		// Only load lists if they're empty
		if (!registerStore.registerList.length) {
			registerStore.refreshRegisterList()
				.finally(() => (this.registerLoading = false))
		} else {
			this.registerLoading = false
		}

		if (!schemaStore.schemaList.length) {
			schemaStore.refreshSchemaList()
				.finally(() => (this.schemaLoading = false))
		} else {
			this.schemaLoading = false
		}

		// Load objects if register and schema are already selected
		if (registerStore.registerItem && schemaStore.schemaItem) {
			objectStore.refreshObjectList()
		}
	},
	methods: {
		/**
		 * Get the file extension from a filename
		 * @param {string} filename - The name of the file to get extension from
		 * @return {string}
		 */
		getFileExtension(filename) {
			return filename.split('.').pop().toLowerCase()
		},
		/**
		 * Get the file type label for display
		 * @param {string} filename - The name of the file to get type label from
		 * @return {string}
		 */
		getFileType(filename) {
			const extension = this.getFileExtension(filename)
			switch (extension) {
			case 'json':
				return 'JSON Configuration'
			case 'xlsx':
			case 'xls':
				return 'Excel Spreadsheet'
			case 'csv':
				return 'CSV Data'
			default:
				return 'Unknown'
			}
		},
		/**
		 * Handle file input change event
		 * @param {Event} event - The file input change event
		 */
		handleFileUpload(event) {
			const file = event.target.files[0]
			if (!file) {
				this.selectedFile = null
				this.error = null
				return
			}

			const extension = this.getFileExtension(file.name)
			if (!this.allowedFileTypes.includes(extension)) {
				this.error = `Invalid file type: ${file.name}. Please select a ${this.allowedFileTypes.map(e => '.' + e).join(', ')} file.`
				this.selectedFile = null
				return
			}

			this.selectedFile = file
			this.error = null
		},
		/**
		 * Close the import modal and reset state
		 */
		closeModal() {
			navigationStore.setModal(false)
			this.selectedFile = null
			this.loading = false
			this.success = false
			this.error = null
			this.includeObjects = true // Reset to default
			this.validation = true // Reset to default
			this.events = false // Reset to default
			this.rbac = true // Reset to default
			this.multi = true // Reset to default
			this.publish = false // Reset to default
			this.importSummary = null
			this.importResults = null
			this.expandedSheets = {} // Reset expanded state
			this.expandedErrors = {} // Reset expanded errors state
			this.isHeartbeatActive = false // Reset heartbeat state
			this.heartbeatStatus = null // Reset heartbeat status
		},
		/**
		 * Handle dialog close event from X button
		 * @param {boolean} isOpen - Whether the dialog is open
		 */
		handleDialogClose(isOpen) {
			if (!isOpen) {
				this.closeModal()
			}
		},
		/**
		 * Import the selected register file and handle the summary
		 * @return {Promise<void>}
		 */
		async importRegister() {
			if (!this.selectedFile || !this.isValidFileType) {
				this.error = 'Please select a valid file to import'
				return
			}

			console.info('ImportRegister: Starting import, setting loading to true')
			this.loading = true
			this.error = null

			// Activate heartbeat indicator for large files (>500KB) to show timeout prevention
			const isLargeFile = this.selectedFile.size > 500 * 1024 // 500KB threshold
			if (isLargeFile) {
				console.info('ImportRegister: Large file detected, activating heartbeat indicator')
				this.isHeartbeatActive = true
			}

			try {
				console.info('ImportRegister: Calling registerStore.importRegister')
				// Call importRegister with heartbeat status monitoring
				const result = await registerStore.importRegister(
					this.selectedFile,
					// Heartbeat status callback
					(status) => {
						this.heartbeatStatus = status
						console.info('ImportRegister: Heartbeat status updated:', status)
					},
				)

				console.info('ImportRegister: Import completed, setting success state')
				// Store the import summary from the backend response
				this.importSummary = result?.responseData?.summary || result?.summary || null
				this.importResults = result?.responseData?.summary || result?.summary || null
				this.success = true

				console.info('ImportRegister: Setting loading to false')
				// Turn off loading immediately after import completes
				// The register refresh will happen in the background
				this.loading = false

				console.info('ImportRegister: Loading state set to false, success:', this.success)
				// Do not auto-close; let user review the summary and close manually
			} catch (error) {
				console.error('ImportRegister: Import failed:', error)
				this.error = error.message || 'Failed to import register'
				this.loading = false
			} finally {
				// Always disable heartbeat indicator when import ends
				this.isHeartbeatActive = false
				console.info('ImportRegister: Heartbeat indicator deactivated')
			}
		},
		/**
		 * Format file size for display
		 * @param {number} bytes - The size in bytes
		 * @return {string}
		 */
		formatFileSize(bytes) {
			if (bytes === 0) return '0 Bytes'
			const k = 1024
			const sizes = ['Bytes', 'KB', 'MB', 'GB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))
			return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
		},
		handleRegisterChange(option) {
			registerStore.setRegisterItem(option)
			schemaStore.setSchemaItem(null)
		},
		async handleSchemaChange(option) {
			schemaStore.setSchemaItem(option)
			if (option) {
				objectStore.initializeProperties(option)
				objectStore.refreshObjectList()
			}
		},
		/**
		 * Check based on the selected file type if all the required data is selected
		 * @return {boolean}
		 */
		checkDataCompleted() {
			if (!this.selectedFile) return false
			if (this.getFileExtension(this.selectedFile?.name) === 'json') {
				return true
			}
			if (this.getFileExtension(this.selectedFile?.name) === 'xlsx' || this.getFileExtension(this.selectedFile?.name) === 'xls') {

				return !!this.selectedRegisterValue
			}
			if (this.getFileExtension(this.selectedFile?.name) === 'csv') {
				return !!this.selectedRegisterValue && !!this.selectedSchemaValue
			}
			return false
		},
		/**
		 * Toggle the expanded state for a sheet's details
		 * @param {string} sheetName - The name of the sheet to toggle
		 */
		toggleDetails(sheetName) {
			// Use Vue.set to ensure reactivity for dynamic object properties
			this.$set(this.expandedSheets, sheetName, !this.expandedSheets[sheetName])
		},
		/**
		 * Toggle the expanded state for a sheet's error details
		 * @param {string} sheetName - The name of the sheet to toggle
		 */
		toggleErrorDetails(sheetName) {
			this.$set(this.expandedErrors, sheetName, !this.expandedErrors[sheetName])
		},
		/**
		 * Get the count of invalid objects from validation errors
		 * @param {object} sheetSummary - The sheet summary object
		 * @return {number} - The number of invalid objects
		 */
		getInvalidCount(sheetSummary) {
			if (!sheetSummary.errors || !Array.isArray(sheetSummary.errors)) {
				return 0
			}
			// Count validation errors (objects that failed validation)
			// Now that invalid objects contain their error info directly, we still count ValidationException types
			return sheetSummary.errors.filter(error => error.type === 'ValidationException').length
		},
		/**
		 * Check if the error might be cache-related
		 * @param {object} sheetSummary - The sheet summary object
		 * @return {boolean} - Whether cache issues might be causing problems
		 */
		isCacheRelatedError(sheetSummary) {
			if (!sheetSummary.errors || !Array.isArray(sheetSummary.errors)) {
				return false
			}

			// Check for error messages that suggest cache issues
			const cacheIndicators = [
				'data is not a valid attribute',
				'not a valid attribute',
				'ProcessingException',
				'Invalid property',
			]

			return sheetSummary.errors.some(error =>
				cacheIndicators.some(indicator =>
					error.error && error.error.includes(indicator),
				),
			)
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

.fileSelection {
	display: flex;
	align-items: center;
	gap: 1rem;
}

.selectedFile {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.fileInfo {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.fileName {
	font-weight: 500;
}

.fileType {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.fileSize {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.fileTypes {
	margin-top: 1rem;
	padding: 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.fileTypesTitle {
	margin: 0 0 0.5rem 0;
	font-weight: bold;
}

.fileTypesList {
	margin: 0;
	padding-left: 1.5rem;
}

.importRequirements {
	margin-top: 1rem;
	padding-top: 1rem;
	border-top: 1px solid var(--color-border);
}

.requirementsTitle {
	margin: 0 0 0.5rem 0;
	font-weight: bold;
	color: var(--color-text-light);
}

.requirementsList {
	margin: 0;
	padding-left: 1.5rem;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.requirementsList li {
	margin-bottom: 0.25rem;
}

.importOptions {
	margin-top: 1rem;
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

/* Summary Table Styles */
.summaryTable {
	margin-bottom: 1.5rem;
}

.summaryTable h3 {
	margin: 0 0 1rem 0;
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-text-light);
}

.sheetSummaryTable {
	width: 100%;
	border-collapse: collapse;
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.sheetSummaryTable th,
.sheetSummaryTable td {
	padding: 0.75rem 1rem;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.sheetSummaryTable th {
	background: var(--color-background-dark);
	font-weight: 600;
	color: var(--color-text-light);
	font-size: 0.9rem;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.sheetSummaryTable tbody tr:hover {
	background: var(--color-background-hover);
}

.sheetSummaryTable tbody tr:last-child td {
	border-bottom: none;
}

.sheetName {
	font-weight: 600;
	color: var(--color-text-light);
	min-width: 150px;
}

.schemaInfo {
	margin-top: 0.25rem;
}

.schemaInfo small {
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
	font-weight: normal;
}

.errorInfo {
	margin-top: 0.25rem;
}

.errorInfo .errorText {
	color: var(--color-error);
	font-size: 0.8rem;
	font-weight: normal;
}

.infoInfo {
	margin-top: 0.25rem;
}

.infoInfo .infoText {
	color: var(--color-text-maxcontrast);
	font-size: 0.8rem;
	font-weight: normal;
}

.debugInfo {
	margin-top: 0.25rem;
}

.debugInfo .debugText {
	color: var(--color-text-maxcontrast);
	font-size: 0.75rem;
	font-weight: normal;
	opacity: 0.8;
}

.errorText {
	color: var(--color-error);
	font-size: 0.8rem;
	font-weight: normal;
}

.statCell {
	text-align: center;
	font-weight: 600;
	min-width: 80px;
}

.statCell.found {
	color: var(--color-primary);
}

.statCell.created {
	color: var(--color-success);
}

.statCell.updated {
	color: var(--color-warning);
}

.statCell.unchanged {
	color: var(--color-text-maxcontrast);
}

.statCell.invalid {
	color: var(--color-warning);
	font-weight: 600;
}

.statCell.errors {
	color: var(--color-error);
}

.errorCell {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.expandButton {
	background: none;
	border: none;
	cursor: pointer;
	padding: 0.25rem;
	border-radius: var(--border-radius-small);
	color: var(--color-error);
	transition: all 0.2s ease;
}

.expandButton:hover {
	background: var(--color-background-hover);
}

.expandButton.expanded {
	transform: rotate(180deg);
}

.statCell.total {
	color: var(--color-text-light);
	font-weight: 700;
	border-left: 1px solid var(--color-border);
}

.cumulativeTable {
	margin-top: 1rem;
	border-radius: var(--border-radius);
	overflow: hidden;
	border: 1px solid var(--color-border);
}

.tableHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.75rem 1rem;
	background: var(--color-background-dark);
	border-bottom: 1px solid var(--color-border);
}

.tableHeader h3 {
	margin: 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-text-light);
}

.columnHeader {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.columnHeader span {
	font-weight: 600;
	color: var(--color-text-light);
}

.columnFilter {
	flex-grow: 1;
	padding: 0.375rem 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-small);
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
}

.columnFilter:focus {
	outline: none;
	border-color: var(--color-primary);
	box-shadow: 0 0 0 2px var(--color-primary);
}

.objectTable {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.875rem;
}

.objectTable th {
	background: var(--color-background-dark);
	padding: 0.5rem;
	text-align: left;
	font-weight: 600;
	color: var(--color-text-light);
	border-bottom: 1px solid var(--color-border);
}

.objectTable td {
	padding: 0.5rem;
	border-bottom: 1px solid var(--color-border-dark);
	vertical-align: middle;
}

.objectTable tr:last-child td {
	border-bottom: none;
}

.successRow {
	background: var(--color-success-background);
}

.updateRow {
	background: var(--color-warning-background);
}

.unchangedRow {
	background: var(--color-background-hover);
}

.errorRow {
	background: var(--color-error-background);
}

.actionBadge {
	display: inline-block;
	padding: 0.25rem 0.5rem;
	border-radius: var(--border-radius-pill);
	font-size: 0.75rem;
	font-weight: 600;
	text-transform: uppercase;
}

.actionBadge.created {
	background: var(--color-success);
	color: white;
}

.actionBadge.updated {
	background: var(--color-warning);
	color: white;
}

.actionBadge.unchanged {
	background: var(--color-text-maxcontrast);
	color: white;
}

.actionBadge.error {
	background: var(--color-error);
	color: white;
}

.resultBadge {
	display: inline-block;
	padding: 0.25rem 0.5rem;
	border-radius: var(--border-radius-pill);
	font-size: 0.75rem;
	font-weight: 600;
}

.resultBadge.success {
	background: var(--color-success-background);
	color: var(--color-success-text);
	border: 1px solid var(--color-success);
}

.resultBadge.info {
	background: var(--color-info-background);
	color: var(--color-info-text);
	border: 1px solid var(--color-info);
}

.resultBadge.error {
	background: var(--color-error-background);
	color: var(--color-error-text);
	border: 1px solid var(--color-error);
	cursor: help;
}

/* Error Details Styles */
.errorDetailsRow {
	background: var(--color-background-hover);
}

.errorDetailsCell {
	padding: 0.75rem 1rem;
}

.errorDetailsTable {
	width: 100%;
	border-collapse: collapse;
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.errorTable {
	width: 100%;
	border-collapse: collapse;
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.errorTable th,
.errorTable td {
	padding: 0.75rem 1rem;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.errorTable th {
	background: var(--color-background-dark);
	font-weight: 600;
	color: var(--color-text-light);
	font-size: 0.9rem;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.errorTable tbody tr:hover {
	background: var(--color-background-hover);
}

.errorTable tbody tr:last-child td {
	border-bottom: none;
}

.errorRowNumber {
	font-weight: 600;
	color: var(--color-text-light);
	min-width: 50px;
}

.errorType {
	font-weight: 600;
	color: var(--color-warning);
}

.errorMessage {
	color: var(--color-error);
	font-size: 0.9em;
	font-weight: normal;
}

.errorData {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	white-space: pre-wrap; /* Preserve whitespace and break lines */
	word-break: break-all; /* Break long words */
}

.errorData pre {
	background: var(--color-background-dark);
	padding: 0.5rem;
	border-radius: var(--border-radius-small);
	border: 1px solid var(--color-border);
	overflow-x: auto; /* Enable horizontal scrolling for pre */
}

.errorData .noData {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.errorDebugInfo {
	margin-top: 0.5rem;
	padding: 0.5rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-small);
	border: 1px solid var(--color-border);
	font-family: monospace;
}

.errorDebugInfo small {
	color: var(--color-text-maxcontrast);
	font-size: 0.75rem;
}

.cacheWarning {
	margin-top: 0.25rem;
	padding: 0.5rem;
	background: var(--color-warning-background);
	border-radius: var(--border-radius-small);
	border: 1px solid var(--color-warning);
}

.cacheWarning .warningText {
	color: var(--color-warning-text);
	font-size: 0.8rem;
	font-weight: 600;
}

/* Heartbeat Status Styles */
.heartbeatStatus {
	margin-top: 0.5rem;
	padding: 0.25rem;
}

.heartbeatHealthy {
	color: var(--color-success);
	font-weight: 600;
}

.heartbeatUnhealthy {
	color: var(--color-warning);
	font-weight: 600;
}

/* Responsive adjustments */
@media (max-width: 768px) {
	.sheetSummaryTable {
		font-size: 0.875rem;
	}

	.sheetSummaryTable th,
	.sheetSummaryTable td {
		padding: 0.5rem;
	}

	.sheetName {
		min-width: 120px;
	}

	.statCell {
		min-width: 60px;
	}

	.objectTable {
		font-size: 0.75rem;
	}

	.objectTable th,
	.objectTable td {
		padding: 0.375rem;
	}

	.columnFilter {
		font-size: 0.75rem;
		padding: 0.25rem 0.5rem;
	}

	.tableHeader {
		flex-direction: column;
		gap: 0.5rem;
		align-items: flex-start;
	}
}
</style>
