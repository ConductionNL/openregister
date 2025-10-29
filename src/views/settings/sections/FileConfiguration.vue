<template>
	<NcSettingsSection name="File Configuration"
		description="Configure file upload and text extraction settings">
		<div v-if="!settingsStore.loadingFileSettings" class="file-settings">
			<!-- Text Extraction Settings -->
			<div class="settings-card">
				<h4>📄 Text Extraction</h4>
				<div class="settings-group">
					<div class="setting-item">
						<label for="extraction-scope">Extract Text From</label>
						<NcSelect v-model="fileSettings.extractionScope"
							input-id="extraction-scope"
							:options="extractionScopes"
							@input="saveSettings">
							<template #option="{ label, description }">
								<div class="option-item">
									<span class="option-label">{{ label }}</span>
									<span class="option-description">{{ description }}</span>
								</div>
							</template>
						</NcSelect>
						<p class="setting-description">
							Choose which files should have text extracted for search and AI features.
						</p>
					</div>

					<div class="setting-item">
						<label for="text-extractor">Text Extractor</label>
						<NcSelect v-model="fileSettings.textExtractor"
							input-id="text-extractor"
							:disabled="fileSettings.extractionScope.id === 'none'"
							:options="textExtractors"
							@input="saveSettings">
							<template #option="{ label, description, icon }">
								<div class="option-item">
									<span class="option-icon">{{ icon }}</span>
									<span class="option-label">{{ label }}</span>
									<span class="option-description">{{ description }}</span>
								</div>
							</template>
						</NcSelect>
						<p class="setting-description">
							Choose the text extraction engine for processing documents.
						</p>
					</div>

					<!-- Dolphin API Configuration (only shown when Dolphin is selected) -->
					<div v-if="fileSettings.textExtractor.id === 'dolphin'" class="setting-item api-config">
						<div class="api-fields">
							<div class="field-group">
								<label for="dolphin-endpoint">Dolphin API Endpoint</label>
								<NcTextField id="dolphin-endpoint"
									v-model="fileSettings.dolphinApiEndpoint"
									placeholder="https://api.your-dolphin-instance.com"
									@update:value="saveSettings">
									<template #trailing-button-icon>
										<InformationIcon :size="20" />
									</template>
								</NcTextField>
								<p class="field-hint">URL to your Dolphin API instance</p>
							</div>

							<div class="field-group">
								<label for="dolphin-key">Dolphin API Key</label>
								<NcTextField id="dolphin-key"
									v-model="fileSettings.dolphinApiKey"
									type="password"
									placeholder="Enter your API key"
									@update:value="saveSettings">
									<template #trailing-button-icon>
										<KeyIcon :size="20" />
									</template>
								</NcTextField>
								<p class="field-hint">Your Dolphin API authentication key</p>
							</div>

							<NcButton type="secondary"
								@click="testDolphinConnection">
								<template #icon>
									<CheckIcon v-if="dolphinConnectionTested === 'success'" :size="20" />
									<AlertCircleIcon v-else-if="dolphinConnectionTested === 'error'" :size="20" />
									<RefreshIcon v-else :size="20" />
								</template>
								Test Connection
							</NcButton>
						</div>
					</div>

					<div class="setting-item">
						<label for="extraction-mode">Extraction Mode</label>
						<NcSelect v-model="fileSettings.extractionMode"
							input-id="extraction-mode"
							:disabled="fileSettings.extractionScope.id === 'none'"
							:options="extractionModes"
							@input="saveSettings">
							<template #option="{ label, description }">
								<div class="option-item">
									<span class="option-label">{{ label }}</span>
									<span class="option-description">{{ description }}</span>
								</div>
							</template>
						</NcSelect>
						<p class="setting-description">
							Control when extraction happens relative to file upload.
						</p>
					</div>
				</div>
			</div>

			<!-- Supported File Types -->
			<div class="settings-card">
				<h4>📎 Supported File Types</h4>
				
				<!-- Compatibility info based on selected extractor -->
				<div v-if="fileSettings.textExtractor.id === 'llphant'" class="compatibility-note info-note">
					<InformationIcon :size="20" />
					<div>
						<strong>LLPhant compatibility:</strong>
						<ul>
							<li>✓ Native: TXT, MD, HTML, JSON, XML, CSV</li>
							<li>○ Library: PDF, DOCX, DOC, XLSX, XLS (requires PhpOffice, PdfParser)</li>
							<li>⚠️ Limited: PPTX, ODT, RTF (consider using Dolphin)</li>
							<li>✗ No support: Image files (JPG, PNG, GIF, WebP) - Use Dolphin for OCR</li>
						</ul>
					</div>
				</div>
				<div v-else-if="fileSettings.textExtractor.id === 'dolphin'" class="compatibility-note success-note">
					<CheckIcon :size="20" />
					<div>
						<strong>Dolphin AI:</strong> All file types fully supported with advanced parsing for tables, formulas, and complex layouts.
						<strong>Includes OCR for scanned documents and images</strong> (JPG, PNG, GIF, WebP).
					</div>
				</div>
				
				<div class="settings-group">
					<div class="file-types-grid">
						<NcCheckboxRadioSwitch v-for="fileType in fileTypes"
							:key="fileType.extension"
							:checked.sync="fileType.enabled"
							type="checkbox"
							@update:checked="saveSettings">
							<span class="file-type-label">
								{{ fileType.icon }} {{ fileType.label }}
								<span class="file-type-extension">(.{{ fileType.extension }})</span>
								<span v-if="fileType.llphantSupport === 'none' && fileSettings.textExtractor.id === 'llphant'" 
									class="support-indicator error"
									title="No LLPhant support - requires Dolphin with OCR">
									✗ Dolphin only
								</span>
								<span v-else-if="fileType.llphantSupport === 'limited' && fileSettings.textExtractor.id === 'llphant'" 
									class="support-indicator warning"
									title="Limited support with LLPhant - consider using Dolphin">
									⚠️
								</span>
								<span v-else-if="fileType.llphantSupport === 'native' && fileSettings.textExtractor.id === 'llphant'" 
									class="support-indicator success"
									title="Native PHP support - works great!">
									✓
								</span>
								<span v-else-if="fileType.dolphinOcr && fileSettings.textExtractor.id === 'dolphin'"
									class="support-indicator ocr"
									title="Dolphin OCR enabled for scanned documents">
									📷 OCR
								</span>
							</span>
						</NcCheckboxRadioSwitch>
					</div>
				</div>
			</div>

			<!-- Processing Limits -->
			<div class="settings-card">
				<h4>⚙️ Processing Limits</h4>
				<div class="settings-group">
					<div class="setting-item">
						<label for="max-file-size">Maximum File Size (MB)</label>
						<input id="max-file-size"
							v-model.number="fileSettings.maxFileSize"
							type="number"
							min="1"
							max="500"
							@change="saveSettings">
						<p class="setting-description">
							Maximum file size for text extraction (1-500 MB)
						</p>
					</div>

					<div class="setting-item">
						<label for="batch-size">Batch Processing Size</label>
						<input id="batch-size"
							v-model.number="fileSettings.batchSize"
							type="number"
							min="1"
							max="100"
							@change="saveSettings">
						<p class="setting-description">
							Number of files to process in parallel background jobs
						</p>
					</div>
				</div>
			</div>

			<!-- Manual Actions -->
			<div class="settings-card">
				<h4>🔧 Manual Actions</h4>
				<div class="settings-group">
					<div class="action-buttons">
						<NcButton type="primary"
							:disabled="processingFiles"
							@click="extractAllPendingFiles">
							<template #icon>
								<NcLoadingIcon v-if="processingFiles" :size="20" />
								<FileDocumentIcon v-else :size="20" />
							</template>
							Extract Pending Files
						</NcButton>

						<NcButton type="secondary"
							:disabled="processingFiles"
							@click="reprocessFailedFiles">
							<template #icon>
								<RefreshIcon :size="20" />
							</template>
							Retry Failed Extractions
						</NcButton>

						<NcButton type="tertiary"
							@click="viewExtractionStatus">
							<template #icon>
								<InformationIcon :size="20" />
							</template>
							View Status
						</NcButton>
					</div>

					<div v-if="extractionStats" class="extraction-stats">
						<div class="stat-item">
							<span class="stat-label">Pending:</span>
							<span class="stat-value">{{ extractionStats.pending }}</span>
						</div>
						<div class="stat-item">
							<span class="stat-label">Completed:</span>
							<span class="stat-value success">{{ extractionStats.completed }}</span>
						</div>
						<div class="stat-item">
							<span class="stat-label">Failed:</span>
							<span class="stat-value error">{{ extractionStats.failed }}</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Save Status -->
			<div v-if="saveMessage" class="save-message" :class="saveMessageType">
				{{ saveMessage }}
			</div>
		</div>

		<NcLoadingIcon v-else
			class="loading-icon"
			:size="64"
			appearance="dark" />
	</NcSettingsSection>
</template>

<script>
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../../store/settings.js'

import {
	NcSettingsSection,
	NcLoadingIcon,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcButton,
	NcTextField,
} from '@nextcloud/vue'

import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import InformationIcon from 'vue-material-design-icons/Information.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import AlertCircleIcon from 'vue-material-design-icons/AlertCircle.vue'

/**
 * @class FileConfiguration
 * @module Components
 * @package Settings
 * 
 * File configuration settings component for managing file upload and text extraction.
 * Allows users to control when and how text extraction occurs.
 * 
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.nl
 */
export default {
	name: 'FileConfiguration',

	components: {
		NcSettingsSection,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcButton,
		NcTextField,
		FileDocumentIcon,
		RefreshIcon,
		InformationIcon,
		KeyIcon,
		CheckIcon,
		AlertCircleIcon,
	},

	data() {
		return {
			fileSettings: {
				extractionScope: { id: 'objects', label: 'Files Attached to Objects' },
				textExtractor: { id: 'llphant', label: 'LLPhant' },
				extractionMode: { id: 'background', label: 'Background Job' },
				maxFileSize: 100,
				batchSize: 10,
				dolphinApiEndpoint: '',
				dolphinApiKey: '',
			},
			fileTypes: [
				// Native PHP support (LLPhant friendly)
				{ extension: 'txt', label: 'Text Files', icon: '📝', enabled: true, llphantSupport: 'native', dolphinOcr: false },
				{ extension: 'md', label: 'Markdown', icon: '📋', enabled: true, llphantSupport: 'native', dolphinOcr: false },
				{ extension: 'html', label: 'HTML Files', icon: '🌐', enabled: true, llphantSupport: 'native', dolphinOcr: false },
				{ extension: 'json', label: 'JSON Files', icon: '📦', enabled: true, llphantSupport: 'native', dolphinOcr: false },
				{ extension: 'xml', label: 'XML Files', icon: '📰', enabled: true, llphantSupport: 'native', dolphinOcr: false },
				{ extension: 'csv', label: 'CSV Files', icon: '📊', enabled: true, llphantSupport: 'native', dolphinOcr: false },
				
				// Requires PHP libraries (LLPhant with dependencies)
				{ extension: 'pdf', label: 'PDF Documents', icon: '📄', enabled: true, llphantSupport: 'library', dolphinOcr: true },
				{ extension: 'docx', label: 'Word Documents', icon: '📘', enabled: true, llphantSupport: 'library', dolphinOcr: false },
				{ extension: 'doc', label: 'Word (Legacy)', icon: '📘', enabled: true, llphantSupport: 'library', dolphinOcr: false },
				{ extension: 'xlsx', label: 'Excel Spreadsheets', icon: '📊', enabled: true, llphantSupport: 'library', dolphinOcr: false },
				{ extension: 'xls', label: 'Excel (Legacy)', icon: '📊', enabled: true, llphantSupport: 'library', dolphinOcr: false },
				
				// Image formats (Dolphin OCR)
				{ extension: 'jpg', label: 'JPEG Images', icon: '🖼️', enabled: false, llphantSupport: 'none', dolphinOcr: true },
				{ extension: 'jpeg', label: 'JPEG Images', icon: '🖼️', enabled: false, llphantSupport: 'none', dolphinOcr: true },
				{ extension: 'png', label: 'PNG Images', icon: '🖼️', enabled: false, llphantSupport: 'none', dolphinOcr: true },
				{ extension: 'gif', label: 'GIF Images', icon: '🖼️', enabled: false, llphantSupport: 'none', dolphinOcr: true },
				{ extension: 'webp', label: 'WebP Images', icon: '🖼️', enabled: false, llphantSupport: 'none', dolphinOcr: true },
				
				// Limited support (better with Dolphin)
				{ extension: 'pptx', label: 'PowerPoint', icon: '📽️', enabled: false, llphantSupport: 'limited', dolphinOcr: false },
				{ extension: 'odt', label: 'OpenDocument Text', icon: '📄', enabled: false, llphantSupport: 'limited', dolphinOcr: false },
				{ extension: 'rtf', label: 'Rich Text Format', icon: '📝', enabled: false, llphantSupport: 'limited', dolphinOcr: false },
			],
			textExtractors: [
				{
					id: 'llphant',
					label: 'LLPhant',
					icon: '🐘',
					description: 'Local PHP library - Best for: TXT, MD, HTML, JSON, XML, CSV, simple PDFs',
				},
				{
					id: 'dolphin',
					label: 'Dolphin',
					icon: '🐬',
					description: 'ByteDance AI - Best for: Complex PDFs, tables, formulas, DOCX, XLSX, PPT',
				},
			],
			extractionScopes: [
				{
					id: 'none',
					label: 'None (Disabled)',
					description: 'Do not extract text automatically',
				},
				{
					id: 'all',
					label: 'All Files',
					description: 'Extract text from all uploaded files in Nextcloud',
				},
				{
					id: 'folders',
					label: 'Files in Specific Folders',
					description: 'Extract text from files in designated folders only',
				},
				{
					id: 'objects',
					label: 'Files Attached to Objects',
					description: 'Extract text only from files attached to OpenRegister objects (recommended)',
				},
			],
			extractionModes: [
				{
					id: 'background',
					label: 'Background Job',
					description: 'Process files asynchronously (recommended)',
				},
				{
					id: 'immediate',
					label: 'Immediate',
					description: 'Process during upload (may be slower)',
				},
				{
					id: 'manual',
					label: 'Manual Only',
					description: 'Only extract when manually triggered',
				},
			],
			extractionStats: null,
			processingFiles: false,
			saveMessage: '',
			saveMessageType: 'success',
			dolphinConnectionTested: null, // null, 'success', 'error'
		}
	},

	computed: {
		...mapStores(useSettingsStore),
	},

	async mounted() {
		await this.loadSettings()
		await this.loadExtractionStats()
	},

	methods: {
		/**
		 * Load file configuration settings
		 */
		async loadSettings() {
			try {
				const settings = await this.settingsStore.getFileSettings()
				if (settings) {
					// Convert scope and mode IDs to objects for NcSelect
					if (settings.extractionScope) {
						const scopeId = typeof settings.extractionScope === 'string' 
							? settings.extractionScope 
							: settings.extractionScope.id
						this.fileSettings.extractionScope = this.extractionScopes.find(s => s.id === scopeId) 
							|| this.extractionScopes[3] // default to 'objects'
					}
					
					// Load text extractor
					if (settings.textExtractor) {
						const extractorId = typeof settings.textExtractor === 'string'
							? settings.textExtractor
							: settings.textExtractor.id
						this.fileSettings.textExtractor = this.textExtractors.find(e => e.id === extractorId)
							|| this.textExtractors[0] // default to 'llphant'
					}
					
					if (settings.extractionMode) {
						const modeId = typeof settings.extractionMode === 'string'
							? settings.extractionMode
							: settings.extractionMode.id
						this.fileSettings.extractionMode = this.extractionModes.find(m => m.id === modeId)
							|| this.extractionModes[0] // default to 'background'
					}
					
					// Load other settings
					this.fileSettings.maxFileSize = settings.maxFileSize || 100
					this.fileSettings.batchSize = settings.batchSize || 10
					this.fileSettings.dolphinApiEndpoint = settings.dolphinApiEndpoint || ''
					this.fileSettings.dolphinApiKey = settings.dolphinApiKey || ''
					
					// Load file types
					if (settings.enabledFileTypes) {
						this.fileTypes.forEach(ft => {
							ft.enabled = settings.enabledFileTypes.includes(ft.extension)
						})
					}
				}
			} catch (error) {
				console.error('Failed to load file settings:', error)
			}
		},

		/**
		 * Save file configuration settings
		 */
		async saveSettings() {
			try {
				await this.settingsStore.saveFileSettings({
					extractionScope: this.fileSettings.extractionScope?.id || 'objects',
					textExtractor: this.fileSettings.textExtractor?.id || 'llphant',
					extractionMode: this.fileSettings.extractionMode?.id || 'background',
					maxFileSize: this.fileSettings.maxFileSize,
					batchSize: this.fileSettings.batchSize,
					dolphinApiEndpoint: this.fileSettings.dolphinApiEndpoint || '',
					dolphinApiKey: this.fileSettings.dolphinApiKey || '',
					enabledFileTypes: this.fileTypes
						.filter(ft => ft.enabled)
						.map(ft => ft.extension),
				})
				
				this.showSaveMessage('Settings saved successfully', 'success')
			} catch (error) {
				console.error('Failed to save file settings:', error)
				this.showSaveMessage('Failed to save settings', 'error')
			}
		},

		/**
		 * Test Dolphin API connection
		 */
		async testDolphinConnection() {
			try {
				this.dolphinConnectionTested = null
				
				if (!this.fileSettings.dolphinApiEndpoint || !this.fileSettings.dolphinApiKey) {
					this.showSaveMessage('Please provide both API endpoint and API key', 'error')
					this.dolphinConnectionTested = 'error'
					return
				}
				
				// Test connection via backend
				const response = await this.settingsStore.testDolphinConnection({
					apiEndpoint: this.fileSettings.dolphinApiEndpoint,
					apiKey: this.fileSettings.dolphinApiKey,
				})
				
				if (response.success) {
					this.showSaveMessage('Dolphin connection successful!', 'success')
					this.dolphinConnectionTested = 'success'
				} else {
					this.showSaveMessage(`Connection failed: ${response.error}`, 'error')
					this.dolphinConnectionTested = 'error'
				}
			} catch (error) {
				console.error('Failed to test Dolphin connection:', error)
				this.showSaveMessage('Connection test failed', 'error')
				this.dolphinConnectionTested = 'error'
			}
		},

		/**
		 * Load extraction statistics
		 */
		async loadExtractionStats() {
			try {
				this.extractionStats = await this.settingsStore.getExtractionStats()
			} catch (error) {
				console.error('Failed to load extraction stats:', error)
			}
		},

		/**
		 * Extract all pending files
		 */
		async extractAllPendingFiles() {
			this.processingFiles = true
			try {
				await this.settingsStore.triggerFileExtraction('pending')
				this.showSaveMessage('Started processing pending files', 'success')
				await this.loadExtractionStats()
			} catch (error) {
				console.error('Failed to process files:', error)
				this.showSaveMessage('Failed to start processing', 'error')
			} finally {
				this.processingFiles = false
			}
		},

		/**
		 * Retry failed file extractions
		 */
		async reprocessFailedFiles() {
			this.processingFiles = true
			try {
				await this.settingsStore.triggerFileExtraction('failed')
				this.showSaveMessage('Started reprocessing failed files', 'success')
				await this.loadExtractionStats()
			} catch (error) {
				console.error('Failed to reprocess files:', error)
				this.showSaveMessage('Failed to start reprocessing', 'error')
			} finally {
				this.processingFiles = false
			}
		},

		/**
		 * View extraction status
		 */
		viewExtractionStatus() {
			// Navigate to a detailed status view or open a dialog
			this.$router.push({ name: 'file-extraction-status' })
		},

		/**
		 * Show save message
		 */
		showSaveMessage(message, type = 'success') {
			this.saveMessage = message
			this.saveMessageType = type
			setTimeout(() => {
				this.saveMessage = ''
			}, 3000)
		},
	},
}
</script>

<style scoped>
.file-settings {
	margin-top: 20px;
}

.settings-card {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 20px;
	margin-bottom: 20px;
}

.settings-card h4 {
	color: var(--color-text-light);
	margin: 0 0 16px 0;
	font-size: 16px;
}

.settings-group {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.setting-item {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.setting-item label {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
	font-size: 14px;
}

.setting-item input[type="number"] {
	max-width: 200px;
	padding: 8px 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-text-light);
}

.setting-description {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0;
	line-height: 1.4;
}

.file-types-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 12px;
}

.file-type-label {
	display: flex;
	align-items: center;
	gap: 8px;
}

.file-type-extension {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.support-indicator {
	margin-left: 8px;
	font-size: 14px;
	cursor: help;
}

.support-indicator.success {
	color: var(--color-success);
}

.support-indicator.warning {
	color: var(--color-warning);
}

.support-indicator.error {
	color: var(--color-error);
	font-size: 11px;
	font-weight: 600;
}

.support-indicator.ocr {
	color: var(--color-primary);
	font-size: 11px;
	font-weight: 600;
	background: var(--color-primary-element-light);
	padding: 2px 6px;
	border-radius: 3px;
}

.compatibility-note {
	display: flex;
	gap: 12px;
	padding: 12px 16px;
	border-radius: var(--border-radius);
	margin-bottom: 16px;
	font-size: 13px;
	line-height: 1.5;
}

.compatibility-note.info-note {
	background: var(--color-primary-element-light);
	border-left: 3px solid var(--color-primary-element);
}

.compatibility-note.success-note {
	background: rgba(70, 180, 130, 0.1);
	border-left: 3px solid var(--color-success);
}

.compatibility-note ul {
	margin: 8px 0 0 0;
	padding-left: 20px;
}

.compatibility-note li {
	margin: 4px 0;
}

.option-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.option-icon {
	font-size: 18px;
	margin-right: 8px;
}

.option-label {
	font-weight: 500;
}

.option-description {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.api-config {
	background: var(--color-background-dark);
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	padding: 16px;
	margin-top: 8px;
}

.api-fields {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.field-group {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.field-hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.action-buttons {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
}

.extraction-stats {
	display: flex;
	gap: 24px;
	padding: 16px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	margin-top: 16px;
}

.stat-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.stat-label {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	text-transform: uppercase;
}

.stat-value {
	color: var(--color-text-light);
	font-size: 24px;
	font-weight: bold;
}

.stat-value.success {
	color: var(--color-success);
}

.stat-value.error {
	color: var(--color-error);
}

.save-message {
	padding: 12px 16px;
	border-radius: var(--border-radius);
	margin-top: 16px;
	text-align: center;
	font-weight: 500;
}

.save-message.success {
	background: var(--color-success);
	color: white;
}

.save-message.error {
	background: var(--color-error);
	color: white;
}

.loading-icon {
	margin: 40px auto;
	display: block;
}

@media (max-width: 768px) {
	.file-types-grid {
		grid-template-columns: 1fr;
	}

	.action-buttons {
		flex-direction: column;
	}

	.extraction-stats {
		flex-direction: column;
		gap: 12px;
	}
}
</style>

