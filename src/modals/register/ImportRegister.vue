<script setup>
import { translate as t } from '@nextcloud/l10n'
import {
	dashboardStore,
	navigationStore,
	objectStore,
	registerStore,
	schemaStore,
} from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.modal === 'importRegister'"
		name="import"
		:title="t('openregister', 'Import Data into Register')"
		size="large"
		@update:open="handleDialogClose">
		<NcNoteCard
			v-if="success && importSummary && !hasImportErrors"
			type="success">
			<p>Register imported successfully!</p>
			<p>
				<small
					>The register list is being refreshed in the background.</small
				>
			</p>
		</NcNoteCard>

		<NcNoteCard
			v-if="success && importSummary && hasImportErrors"
			type="warning">
			<p>Import completed with errors!</p>
			<p>
				<small
					>Some objects could not be imported. Check the details
					below.</small
				>
			</p>
		</NcNoteCard>

		<div v-if="importResults" class="importResults">
			<!-- Summary Table -->
			<div class="summaryTable">
				<h3>Import Summary</h3>
				<table class="sheetSummaryTable">
					<thead>
						<tr>
							<th scope="col">Sheet</th>
							<th scope="col">Found</th>
							<th scope="col">
								{{ t('openregister', 'Created') }}
							</th>
							<th scope="col">
								{{ t('openregister', 'Updated') }}
							</th>
							<th scope="col">
								{{ t('openregister', 'Unchanged') }}
							</th>
							<th scope="col">
								{{ t('openregister', 'Invalid') }}
							</th>
							<th scope="col">Errors</th>
							<th scope="col">{{ t('openregister', 'Total') }}</th>
						</tr>
					</thead>
					<tbody>
						<template
							v-for="(sheetSummary, sheetKey) in importResults"
							:key="sheetKey">
							<tr>
								<td class="sheetName">
									{{ sheetKey }}
									<div
										v-if="sheetSummary.schema"
										class="schemaInfo">
										<small
											>Schema:
											{{ sheetSummary.schema.title }}</small
										>
									</div>
									<div
										v-if="hasErrorType(sheetSummary, 'MissingIdColumnException')"
										class="errorInfo">
										<small class="errorText"
											>Missing required "id" column</small
										>
									</div>
									<div
										v-else-if="hasErrorType(sheetSummary, 'InvalidUuidException')"
										class="errorInfo">
										<small class="errorText"
											>Invalid UUID format in ID column</small
										>
									</div>
									<div
										v-else-if="hasErrorType(sheetSummary, 'ValidationException')"
										class="errorInfo">
										<small class="errorText"
											>{{
												getInvalidCount(sheetSummary)
											}}
											objects failed validation</small
										>
									</div>
									<div
										v-else-if="
											sheetSummary.found === 0
											&& sheetSummary.errors
											&& sheetSummary.errors.length > 0
										"
										class="errorInfo">
										<small class="errorText"
											>No data found - check schema matching or
											cache issues</small
										>
									</div>
									<div
										v-if="isCacheRelatedError(sheetSummary)"
										class="cacheWarning">
										<small class="warningText"
											>⚠️ Cache-related error detected. Try
											refreshing the page or contact
											support.</small
										>
									</div>
									<div
										v-if="
											sheetSummary.found === 0
											&& sheetSummary.errors
											&& sheetSummary.errors.length === 0
										"
										class="infoInfo">
										<small class="infoText"
											>Sheet appears empty or has no matching
											data</small
										>
									</div>
									<div
										v-if="
											sheetSummary.found === 0
											&& sheetSummary.debug
											&& sheetSummary.debug.headers
											&& Array.isArray(
												sheetSummary.debug.headers,
											)
										"
										class="debugInfo">
										<small class="debugText">
											Headers:
											{{ sheetSummary.debug.headers.join(', ')
											}}<br />
											Schema properties:
											{{
												Array.isArray(
													sheetSummary.debug
														.schemaProperties,
												)
													? sheetSummary.debug.schemaProperties.join(
															', ',
														)
													: 'N/A'
											}}
										</small>
									</div>
								</td>
								<td class="statCell found">
									{{ sheetSummary.found || 0 }}
								</td>
								<td class="statCell created">
									{{ countOf(sheetSummary, 'created') }}
								</td>
								<td class="statCell updated">
									{{ countOf(sheetSummary, 'updated') }}
								</td>
								<td class="statCell unchanged">
									{{ countOf(sheetSummary, 'unchanged') }}
								</td>
								<td class="statCell invalid">
									{{ getInvalidCount(sheetSummary) }}
								</td>
								<td class="statCell errors">
									<div class="errorCell">
										<span>{{ countOf(sheetSummary, 'errors') }}</span>
										<button
											v-if="
												sheetSummary.errors
												&& sheetSummary.errors.length > 0
											"
											type="button"
											class="expandButton"
											:class="{
												expanded: expandedErrors[sheetKey],
											}"
											:aria-expanded="
												!!expandedErrors[sheetKey]
											"
											:aria-label="
												t('openregister', 'Error Details')
											"
											@click="toggleErrorDetails(sheetKey)">
											<ChevronDown :size="16" />
										</button>
									</div>
								</td>
								<td class="statCell total">
									{{
										countOf(sheetSummary, 'created')
										+ countOf(sheetSummary, 'updated')
										+ countOf(sheetSummary, 'unchanged')
										+ getInvalidCount(sheetSummary)
										+ countOf(sheetSummary, 'errors')
									}}
								</td>
							</tr>
							<!-- Error Details Row -->
							<tr
								v-if="
									expandedErrors[sheetKey]
									&& sheetSummary.errors
									&& sheetSummary.errors.length > 0
								"
								class="errorDetailsRow">
								<td colspan="8" class="errorDetailsCell">
									<div class="errorDetailsTable">
										<table class="errorTable">
											<thead>
												<tr>
													<th scope="col">Row</th>
													<th scope="col">Error Type</th>
													<th scope="col">
														{{
															t(
																'openregister',
																'Error Message',
															)
														}}
													</th>
													<th scope="col">
														{{
															t('openregister', 'Data')
														}}
													</th>
												</tr>
											</thead>
											<tbody>
												<tr
													v-for="(
														importError, index
													) in sheetSummary.errors"
													:key="index"
													class="errorRow">
													<td class="errorRowNumber">
														{{ importError.row }}
													</td>
													<td class="errorType">
														{{
															importError.type
															|| 'Unknown'
														}}
													</td>
													<td class="errorMessage">
														{{ importError.error }}
														<div
															v-if="importError.debug"
															class="errorDebugInfo">
															<small
																>File:
																{{
																	importError.debug
																		.file
																}}</small
															><br />
															<small
																>Line:
																{{
																	importError.debug
																		.line
																}}</small
															><br />
															<small
																>Class:
																{{
																	importError.debug
																		.class
																}}</small
															>
														</div>
													</td>
													<td class="errorData">
														<pre
															v-if="
																importError.data
																&& Object.keys(
																	importError.data,
																).length > 0
															"
															>{{
																JSON.stringify(
																	importError.data,
																	null,
																	2,
																)
															}}</pre>
														<span v-else class="noData"
															>No data</span
														>
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

			<CloseCancelButton
				label="Close"
				variant="secondary"
				style="margin-top: 1rem"
				@click="closeModal" />
		</div>

		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<!-- Heartbeat indicator for long imports -->
		<NcNoteCard v-if="loading && isHeartbeatActive" type="info">
			<p>🔄 Processing large file - keeping connection alive...</p>
			<p>
				<small
					>This may take several minutes for large datasets. The system is
					actively preventing timeouts.</small
				>
			</p>
			<div
				v-if="heartbeatStatus && heartbeatStatus.count > 0"
				class="heartbeatStatus">
				<small>
					<span v-if="heartbeatStatus.healthy" class="heartbeatHealthy"
						>✓ Connection stable</span
					>
					<span v-else class="heartbeatUnhealthy"
						>⚠ Connection issues detected</span
					>
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
				@change="handleFileUpload" />

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
						<span class="fileType"
							>({{ getFileType(selectedFile.name) }})</span
						>
					</div>
					<div class="fileSize">
						{{ formatFileSize(selectedFile.size) }}
					</div>
				</div>
			</div>

			<NcSelect
				v-if="
					selectedFile
					&& (getFileExtension(selectedFile?.name) === 'xlsx'
						|| getFileExtension(selectedFile.name) === 'xls'
						|| getFileExtension(selectedFile.name) === 'csv')
				"
				v-bind="registerOptions"
				:modelValue="selectedRegisterValue"
				:loading="registerLoading"
				:disabled="registerLoading"
				:aria-label-combobox="t('openregister', 'Select a register')"
				:placeholder="t('openregister', 'Select a register')"
				@update:modelValue="handleRegisterChange" />

			<NcSelect
				v-if="selectedFile && getFileExtension(selectedFile?.name) === 'csv'"
				v-bind="schemaOptions"
				:modelValue="selectedSchemaValue"
				:loading="schemaLoading"
				:disabled="!registerStore.registerItem || schemaLoading"
				:aria-label-combobox="t('openregister', 'Select a schema')"
				:placeholder="t('openregister', 'Select a schema')"
				@update:modelValue="handleSchemaChange" />

			<div class="fileTypes">
				<p class="fileTypesTitle">Supported file types:</p>
				<ul class="fileTypesList">
					<li v-for="fileType in fileTypesHelp" :key="fileType.name">
						<strong>{{ fileType.name }}</strong>{{ fileType.text }}<br />
						<em>{{ fileType.note }}</em>
					</li>
				</ul>
				<div class="importRequirements">
					<p class="requirementsTitle">
						<strong>Import Requirements:</strong>
					</p>
					<ul class="requirementsList">
						<li>
							Every sheet must have an <strong>"id"</strong> column for
							object identification
						</li>
						<li>
							Empty "id" values create new objects, existing "id"
							values update objects
						</li>
						<li>
							ID values must be valid UUID format (e.g.,
							123e4567-e89b-12d3-a456-426614174000)
						</li>
						<li>
							Metadata columns (starting with "_") are automatically
							ignored
						</li>
					</ul>
				</div>
			</div>

			<div class="importOptions">
				<NcCheckboxRadioSwitch
					:modelValue="includeObjects"
					type="switch"
					@update:modelValue="includeObjects = $event">
					Include objects in the import
					<template #helper>
						This will create or update objects on the register
					</template>
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch
					:modelValue="validation"
					type="switch"
					@update:modelValue="validation = $event">
					Enable validation
					<template #helper>
						Validate objects against schema definitions before saving.
						Invalid objects will be excluded from import.
					</template>
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch
					:modelValue="events"
					type="switch"
					@update:modelValue="events = $event">
					Enable events (experimental)
					<template #helper>
						Dispatch object lifecycle events during bulk operations. May
						impact performance.
					</template>
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch
					:modelValue="rbac"
					type="switch"
					@update:modelValue="rbac = $event">
					Enable RBAC (Role-Based Access Control)
					<template #helper>
						Apply role-based access control checks during import.
						Recommended for production environments.
					</template>
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch
					:modelValue="multi"
					type="switch"
					@update:modelValue="multi = $event">
					Enable Multi-tenancy
					<template #helper>
						Apply multi-tenancy filtering during import. Recommended for
						multi-organization setups.
					</template>
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<template v-if="!success && !importResults" #actions>
			<CloseCancelButton label="Cancel" @click="closeModal" />
			<BusyButton
				:busy="loading"
				:disabled="
					loading
					|| !selectedFile
					|| !isValidFileType
					|| !checkDataCompleted()
				"
				variant="primary"
				@click="importRegister">
				<template #icon>
					<Import :size="20" />
				</template>
				Import
			</BusyButton>
		</template>
	</NcDialog>
</template>

<script>
/**
 * @spec openspec/specs/data-import-export/spec.md
 */
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import Import from 'vue-material-design-icons/Import.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import BusyButton from '../../components/shared/BusyButton.vue'
import CloseCancelButton from '../../components/shared/CloseCancelButton.vue'
import { freshState } from '../../services/initialState.js'
import {
	registerSelect,
	schemaPicker,
	scopeOption,
} from '../../services/scopeSelect.js'

const FILE_TYPE_LABELS = {
	json: 'JSON Configuration',
	xlsx: 'Excel Spreadsheet',
	xls: 'Excel Spreadsheet',
	csv: 'CSV Data',
}

const MANY_SCHEMAS = 'You can create or update objects for multiple schemas at once.'

/**
 * The supported file types the import form lists, with what each can do.
 */
const FILE_TYPES_HELP = [
	{ name: 'JSON', text: ' - Register configuration and objects.', note: MANY_SCHEMAS },
	{ name: 'Excel', text: ' (.xlsx, .xls) - Objects data.', note: MANY_SCHEMAS },
	{ name: 'CSV', text: ' - Objects data.', note: 'You can only update one schema within a register.' },
]

const LARGE_FILE = 500 * 1024

/**
 * Whether the selections a file type needs before import are made.
 */
const SCOPE_NEEDED = {
	json: () => true,
	xlsx: (picked) => !!picked.selectedRegisterValue,
	xls: (picked) => !!picked.selectedRegisterValue,
	csv: (picked) => !!picked.selectedRegisterValue && !!picked.selectedSchemaValue,
}

/**
 * The state the import modal returns to when it closes.
 */
const CLOSED_STATE = {
	selectedFile: null,
	loading: false,
	success: false,
	error: null,
	includeObjects: true,
	validation: true,
	events: false,
	rbac: true,
	multi: true,
	importSummary: null,
	importResults: null,
	expandedSheets: {},
	expandedErrors: {},
	isHeartbeatActive: false,
	heartbeatStatus: null,
}

/**
 * The ids of a register's schemas, as strings; a schema may be an id or an
 * object with an id.
 *
 * @param {object} register The register
 * @return {Array<string>} The ids
 */
function registerSchemaIds(register) {
	return (register.schemas || []).map((schema) =>
		String(typeof schema === 'object' && schema !== null ? schema.id : schema),
	)
}

export default {
	name: 'ImportRegister',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		NcSelect,
		// Icons
		BusyButton,
		CloseCancelButton,
		Import,
		Upload,
		ChevronDown,
	},

	/**
	 * Component data properties
	 *
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
		 * The supported file types the form lists.
		 *
		 * @return {Array<object>} Name, text and note of each type
		 *
		 * @spec exclude computed display helper for the supported file types list
		 */
		fileTypesHelp() {
			return FILE_TYPES_HELP
		},

		/**
		 * Check if the selected file type is valid
		 *
		 * @return {boolean}
		 * @spec exclude computed client-side file-type validation
		 */
		isValidFileType() {
			if (!this.selectedFile) return false
			const extension = this.getFileExtension(this.selectedFile.name)
			return this.allowedFileTypes.includes(extension)
		},

		/**
		 * @spec exclude computed display helper building register select options
		 */
		registerOptions() {
			return registerSelect(registerStore.registerList)
		},

		/**
		 * @spec openspec/specs/data-import-export/spec.md
		 */
		schemaOptions() {
			if (!registerStore.registerItem) return { options: [] }
			// Schema IDs can be either numbers or objects with an id property.
			const ids = registerSchemaIds(registerStore.registerItem)
			return schemaPicker(
				schemaStore.schemaList.filter((schema) =>
					ids.includes(String(schema.id)),
				),
			)
		},

		/**
		 * @spec exclude computed display helper for selected register value
		 */
		selectedRegisterValue() {
			return scopeOption(registerStore.registerItem, 'register')
		},

		/**
		 * @spec openspec/specs/data-import-export/spec.md
		 */
		selectedSchemaValue() {
			return scopeOption(schemaStore.schemaItem, 'schema')
		},

		/**
		 * Check if there are any errors in the import results
		 *
		 * @return {boolean} - Whether there are import errors
		 * @spec exclude computed flag for import errors
		 */
		hasImportErrors() {
			if (!this.importResults) return false

			// Check if any sheet has errors
			return Object.values(this.importResults).some(
				(sheetSummary) =>
					sheetSummary.errors && sheetSummary.errors.length > 0,
			)
		},
	},

	/**
	 * @spec exclude Vue lifecycle hook preloading registers/schemas
	 */
	mounted() {
		dashboardStore.preload()
		this.registerLoading = true
		this.schemaLoading = true

		// Always load lists to ensure fresh data.
		registerStore
			.refreshRegisterList()
			.finally(() => (this.registerLoading = false))

		schemaStore.refreshSchemaList().finally(() => (this.schemaLoading = false))

		// Load objects if register and schema are already selected.
		if (registerStore.registerItem && schemaStore.schemaItem) {
			objectStore.refreshObjectList()
		}
	},

	methods: {
		/**
		 * Whether a sheet's import reported an error of the given type.
		 *
		 * @param {object} sheetSummary The sheet's import summary
		 * @param {string} type The error type
		 * @return {boolean} Whether such an error was reported
		 */
		hasErrorType(sheetSummary, type) {
			return !!sheetSummary.errors && sheetSummary.errors.some((error) => error.type === type)
		},

		/**
		 * How many entries a sheet's import summary lists under a key.
		 *
		 * @param {object} sheetSummary The sheet's import summary
		 * @param {string} key The list, such as created or errors
		 * @return {number} The number of entries, 0 when absent
		 */
		countOf(sheetSummary, key) {
			return (sheetSummary[key] && sheetSummary[key].length) || 0
		},

		/**
		 * Get the file extension from a filename
		 *
		 * @param {string} filename - The name of the file to get extension from
		 * @return {string}
		 */
		getFileExtension(filename) {
			return filename.split('.').pop().toLowerCase()
		},

		/**
		 * Get the file type label for display
		 *
		 * @param {string} filename - The name of the file to get type label from
		 * @return {string}
		 * @spec exclude display helper mapping extension to label
		 */
		getFileType(filename) {
			const extension = this.getFileExtension(filename)
			return Object.hasOwn(FILE_TYPE_LABELS, extension)
				? FILE_TYPE_LABELS[extension]
				: 'Unknown'
		},

		/**
		 * Handle file input change event
		 *
		 * @param {Event} event - The file input change event
		 * @spec exclude file-input UI handler with client-side validation
		 */
		handleFileUpload(event) {
			const file = event.target.files[0]
			this.error = file ? this.fileTypeError(file) : null
			this.selectedFile = file && !this.error ? file : null
		},

		/**
		 * Why a file cannot be imported, or null when its type is allowed.
		 *
		 * @param {File} file The chosen file
		 * @return {string|null} The error
		 * @spec exclude file-input UI handler with client-side validation
		 */
		fileTypeError(file) {
			const extension = this.getFileExtension(file.name)
			if (this.allowedFileTypes.includes(extension)) {
				return null
			}
			const types = this.allowedFileTypes.map((e) => '.' + e).join(', ')
			return `Invalid file type: ${file.name}. Please select a ${types} file.`
		},

		/**
		 * Close the import modal and reset state
		 *
		 * @spec exclude modal close + form-state reset handler
		 */
		closeModal() {
			navigationStore.setModal(false)
			Object.assign(this, freshState(CLOSED_STATE))
		},

		/**
		 * Handle dialog close event from X button
		 *
		 * @param {boolean} isOpen - Whether the dialog is open
		 * @spec exclude modal open/close UI handler
		 */
		handleDialogClose(isOpen) {
			if (!isOpen) {
				this.closeModal()
			}
		},

		/**
		 * Import the selected register file and handle the summary
		 *
		 * @return {Promise<void>}
		 * @spec exclude modal submit handler delegating to registerStore.importRegister
		 */
		async importRegister() {
			if (!this.selectedFile || !this.isValidFileType) {
				this.error = 'Please select a valid file to import'
				return
			}
			this.loading = true
			this.error = null
			// Activate heartbeat indicator for large files (>500KB) to show timeout prevention
			if (this.selectedFile.size > LARGE_FILE) {
				this.isHeartbeatActive = true
			}
			try {
				await this.runImport()
			} catch (error) {
				this.importFailed(error)
			} finally {
				// Always disable heartbeat indicator when import ends
				this.isHeartbeatActive = false
			}
		},

		/**
		 * Show why the import failed.
		 *
		 * @param {Error} error The failure
		 * @spec exclude modal submit handler delegating to registerStore.importRegister
		 */
		importFailed(error) {
			console.error('ImportRegister: Import failed:', error)
			this.error = error.message || 'Failed to import register'
			this.loading = false
		},

		/**
		 * Import the file and keep the backend's summary for review; the modal
		 * stays open until the user closes it.
		 *
		 * @return {Promise<void>}
		 * @spec exclude modal submit handler delegating to registerStore.importRegister
		 */
		async runImport() {
			const result = await registerStore.importRegister(
				this.selectedFile,
				(status) => {
					this.heartbeatStatus = status
				},
			)
			const summary = result?.responseData?.summary || result?.summary || null
			this.importSummary = summary
			this.importResults = summary
			this.success = true
			// The register refresh happens in the background
			this.loading = false
		},

		/**
		 * Format file size for display
		 *
		 * @param {number} bytes - The size in bytes
		 * @return {string}
		 * @spec exclude display helper formatting file size
		 */
		formatFileSize(bytes) {
			if (bytes === 0) return '0 Bytes'
			const k = 1024
			const sizes = ['Bytes', 'KB', 'MB', 'GB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))
			return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
		},

		/**
		 * @param {object|null} option
		 * @spec exclude select-change UI handler updating register/schema stores
		 */
		async handleRegisterChange(option) {
			registerStore.setRegisterItem(option || null)
			schemaStore.setSchemaItem(null)
			// Always refresh the schema list when the register has schemas: they
			// might not be loaded yet or might have changed.
			if (option && (option.schemas || []).length > 0) {
				await this.refreshSchemas()
			}
		},

		/**
		 * Reload the schema list, showing the schema picker as loading meanwhile.
		 *
		 * @return {Promise<void>}
		 * @spec exclude select-change UI handler updating register/schema stores
		 */
		async refreshSchemas() {
			this.schemaLoading = true
			try {
				await schemaStore.refreshSchemaList()
			} catch (error) {
				console.error('Error loading schemas:', error)
			} finally {
				this.schemaLoading = false
			}
		},

		/**
		 * @param {object|null} option
		 * @spec openspec/specs/data-import-export/spec.md
		 */
		async handleSchemaChange(option) {
			schemaStore.setSchemaItem(option)
			if (option) {
				objectStore.initializeProperties(option)
				objectStore.refreshObjectList()
			}
		},

		/**
		 * Check based on the selected file type if all the required data is selected
		 *
		 * @return {boolean}
		 * @spec exclude computed form-completion gate
		 */
		checkDataCompleted() {
			if (!this.selectedFile) return false
			const extension = this.getFileExtension(this.selectedFile.name)
			if (!Object.hasOwn(SCOPE_NEEDED, extension)) return false
			const isComplete = SCOPE_NEEDED[extension]
			return isComplete(this)
		},

		/**
		 * Toggle the expanded state for a sheet's details
		 *
		 * @param {string} sheetName - The name of the sheet to toggle
		 * @spec exclude UI toggle for sheet details
		 */
		toggleDetails(sheetName) {
			// Use Vue.set to ensure reactivity for dynamic object properties
			this.expandedSheets[sheetName] = !this.expandedSheets[sheetName]
		},

		/**
		 * Toggle the expanded state for a sheet's error details
		 *
		 * @param {string} sheetName - The name of the sheet to toggle
		 * @spec exclude UI toggle for sheet error details
		 */
		toggleErrorDetails(sheetName) {
			this.expandedErrors[sheetName] = !this.expandedErrors[sheetName]
		},

		/**
		 * Get the count of invalid objects from validation errors
		 *
		 * @param {object} sheetSummary - The sheet summary object
		 * @return {number} - The number of invalid objects
		 * @spec exclude display helper counting validation errors
		 */
		getInvalidCount(sheetSummary) {
			if (!sheetSummary.errors || !Array.isArray(sheetSummary.errors)) {
				return 0
			}
			// Count validation errors (objects that failed validation)
			// Now that invalid objects contain their error info directly, we still count ValidationException types
			return sheetSummary.errors.filter(
				(error) => error.type === 'ValidationException',
			).length
		},

		/**
		 * Check if the error might be cache-related
		 *
		 * @param {object} sheetSummary - The sheet summary object
		 * @return {boolean} - Whether cache issues might be causing problems
		 * @spec exclude display helper detecting cache-related errors
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

			return sheetSummary.errors.some((error) =>
				cacheIndicators.some(
					(indicator) => error.error && error.error.includes(indicator),
				),
			)
		},
	},
}
</script>

<style lang="scss">
@use '../../styles/modals.scss' as *;

@include form-container;

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

.sheetSummaryTable,
.errorDetailsTable,
.errorTable {
	width: 100%;
	border-collapse: collapse;
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.sheetSummaryTable th,
.sheetSummaryTable td,
.errorTable th,
.errorTable td {
	padding: 0.75rem 1rem;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.sheetSummaryTable th,
.errorTable th {
	background: var(--color-background-dark);
	font-weight: 600;
	color: var(--color-text-light);
	font-size: 0.9rem;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.sheetSummaryTable tbody tr:hover,
.errorTable tbody tr:hover {
	background: var(--color-background-hover);
}

.sheetSummaryTable tbody tr:last-child td,
.errorTable tbody tr:last-child td {
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

@media (prefers-reduced-motion: reduce) {
	.expandButton {
		transition: none;
	}
}
</style>
