<script setup>
import { registerStore, schemaStore, navigationStore, objectStore, dashboardStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'importRegister'"
		name="import"
		title="Import Data into Register"
		size="large"
		:can-close="false">
		<NcNoteCard v-if="success && importSummary" type="success">
			<p>Register imported successfully!</p>
			<div class="importSummary">
				<p><strong>Import Summary:</strong></p>
				<ul>
					<li><strong>Created:</strong> {{ importSummary.created.length }} <span v-if="importSummary.created.length">({{ importSummary.created.join(', ') }})</span></li>
					<li><strong>Updated:</strong> {{ importSummary.updated.length }} <span v-if="importSummary.updated.length">({{ importSummary.updated.join(', ') }})</span></li>
					<li><strong>Unchanged:</strong> {{ importSummary.unchanged.length }} <span v-if="importSummary.unchanged.length">({{ importSummary.unchanged.join(', ') }})</span></li>
				</ul>
				<NcButton type="secondary" style="margin-top: 1rem;" @click="closeModal">
					Close
				</NcButton>
			</div>
		</NcNoteCard>

		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<div v-if="!success" class="formContainer">
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
				placeholder="Select a register"
				@update:model-value="handleRegisterChange" />

			<NcSelect v-if="selectedFile && (getFileExtension(selectedFile?.name) === 'csv')"
				v-bind="schemaOptions"
				:model-value="selectedSchemaValue"
				:loading="schemaLoading"
				:disabled="!registerStore.registerItem || schemaLoading"
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
			</div>

			<div class="includeObjects">
				<NcCheckboxRadioSwitch
					:checked="includeObjects"
					type="switch"
					@update:checked="includeObjects = $event">
					Include objects in the import
					<template #helper>
						This will create or update objects on the register
					</template>
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<template v-if="!success" #actions>
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
			includeObjects: false, // Whether to include objects
			allowedFileTypes: ['json', 'xlsx', 'xls', 'csv'], // Allowed file types
			importSummary: null, // The import summary from the backend
			registerLoading: false,
			schemaLoading: false,
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
			this.includeObjects = false
			this.importSummary = null
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

			this.loading = true
			this.error = null

			try {
				const result = await registerStore.importRegister(this.selectedFile, this.includeObjects)
				// Store the import summary from the backend response
				this.importSummary = result?.responseData?.summary || null
				this.success = true
				this.loading = false
				// Do not auto-close; let user review the summary and close manually
			} catch (error) {
				this.error = error.message || 'Failed to import register'
				this.loading = false
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

.fileTypesList li {
	margin-bottom: 0.25rem;
}

.includeObjects {
	margin-top: 1rem;
}

.importSummary {
	margin-top: 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 1rem;
}
</style>
