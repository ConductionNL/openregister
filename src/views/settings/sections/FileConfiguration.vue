<template>
	<SettingsSection
		name="File Configuration"
		description="Configure file upload and text extraction settings"
		:loading="settingsStore.loadingFileSettings"
		loading-message="Loading file configuration...">
		<template #actions>
			<!-- File Actions Menu -->
			<NcActions
				:aria-label="t('openregister', 'File actions menu')"
				:menu-name="t('openregister', 'Actions')">
				<template #icon>
					<DotsVertical :size="20" />
				</template>

			<!-- Discover Files -->
			<NcActionButton
				:disabled="isProcessing"
				@click="discoverFiles">
				<template #icon>
					<span class="action-icon-wrapper">
						<NcLoadingIcon v-if="discoveringFiles" :size="20" />
						<MagnifyIcon v-else :size="20" />
					</span>
				</template>
				{{ t('openregister', 'Discover Files') }}
			</NcActionButton>

			<!-- Extract Pending Files -->
			<NcActionButton
				:disabled="isProcessing"
				@click="extractAllPendingFiles">
				<template #icon>
					<span class="action-icon-wrapper">
						<NcLoadingIcon v-if="extractingFiles" :size="20" />
						<FileDocumentIcon v-else :size="20" />
					</span>
				</template>
				{{ t('openregister', 'Extract Pending Files') }}
			</NcActionButton>

			<!-- Retry Failed Extractions -->
			<NcActionButton
				:disabled="isProcessing"
				@click="reprocessFailedFiles">
				<template #icon>
					<span class="action-icon-wrapper">
						<NcLoadingIcon v-if="retryingFiles" :size="20" />
						<RefreshIcon v-else :size="20" />
					</span>
				</template>
				{{ t('openregister', 'Retry Failed Extractions') }}
			</NcActionButton>
			</NcActions>
		</template>

		<!-- Section Description -->
		<div class="section-description-full">
			<p class="main-description">
				Text extraction converts files into searchable and AI-processable content. Choose from <strong>LLPhant</strong>
				(local PHP processing, best for simple files) or <strong>Dolphin AI</strong> (ByteDance API, best for complex
				documents, OCR, tables, and formulas). Extracted text is split into chunks for embeddings and semantic search.
			</p>
			<p class="main-description info-note">
				<strong>📝 Note:</strong> Text extraction is <strong>required</strong> before LLM vectorization. The process flow is:
				File Upload → Text Extraction → Chunking → Embedding Creation. Without text extraction enabled, files cannot be
				vectorized for semantic search.
			</p>
		</div>

		<!-- Text Extraction Settings -->
		<SettingsCard
			title="Text Extraction"
			icon="📄"
			:collapsible="true"
			:default-collapsed="true">
			<div class="settings-group compact">
				<div class="setting-item">
					<label for="extraction-scope">Extract Text From</label>
					<NcSelect v-model="fileSettings.extractionScope"
						input-id="extraction-scope"
						input-label="Extraction Scope"
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
						input-label="Text Extraction Engine"
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
							<p class="field-hint">
								URL to your Dolphin API instance
							</p>
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
							<p class="field-hint">
								Your Dolphin API authentication key
							</p>
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
						input-label="Extraction Mode"
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

			<!-- Processing Limits -->
			<div class="processing-limits-section">
				<h5>⚙️ Processing Limits</h5>

				<!-- First row: File size, chunking strategy, batch size -->
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
						<label for="chunking-strategy">Chunking Strategy</label>
						<select id="chunking-strategy"
							v-model="fileSettings.chunkingStrategy"
							@change="saveSettings">
							<option value="RECURSIVE_CHARACTER">
								Recursive Character Split
							</option>
							<option value="CHARACTER">
								Character Split
							</option>
							<option value="TOKEN">
								Token Split
							</option>
							<option value="SENTENCE">
								Sentence Split
							</option>
						</select>
						<p class="setting-description">
							How to split text into chunks for processing
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

				<!-- Second row: Chunk size and overlap -->
				<div class="settings-group" style="margin-top: 16px;">
					<div class="setting-item">
						<label for="chunk-size">Chunk Size (characters)</label>
						<input id="chunk-size"
							v-model.number="fileSettings.chunkSize"
							type="number"
							min="100"
							max="10000"
							@change="saveSettings">
						<p class="setting-description">
							Size of text chunks for processing and embeddings (100-10000 characters)
						</p>
					</div>

					<div class="setting-item">
						<label for="chunk-overlap">Chunk Overlap (characters)</label>
						<input id="chunk-overlap"
							v-model.number="fileSettings.chunkOverlap"
							type="number"
							min="0"
							max="1000"
							@change="saveSettings">
						<p class="setting-description">
							Overlap between consecutive chunks to maintain context (0-1000 characters)
						</p>
					</div>

					<div class="setting-item">
						<label>Search Integration</label>
						<NcCheckboxRadioSwitch
							v-model="fileSettings.includeInSearch"
							type="switch"
							@update:checked="saveSettings">
							Include in Search Results
						</NcCheckboxRadioSwitch>
						<p class="setting-description">
							Files appear in Nextcloud's global search
						</p>
					</div>
				</div>
			</div>
		</SettingsCard>

		<!-- Supported File Types -->
		<SettingsCard
			title="Supported File Types"
			icon="📎"
			:collapsible="true"
			:default-collapsed="true">
			<!-- Compatibility info based on selected extractor -->
			<div v-if="fileSettings.textExtractor.id === 'llphant'" class="compatibility-note info-note">
				<InformationIcon :size="20" />
				<div>
					<strong>LLPhant Extraction:</strong> Supports most document formats including PDF, DOCX, XLSX, TXT, MD, HTML, JSON, XML, and CSV.
					<br><strong>Note:</strong> Image files (JPG, PNG, GIF, WebP) require Dolphin AI for OCR text extraction.
				</div>
			</div>
			<div v-else-if="fileSettings.textExtractor.id === 'dolphin'" class="compatibility-note success-note">
				<CheckIcon :size="20" />
				<div>
					<strong>Dolphin AI:</strong> Supports all file types with advanced parsing for tables, formulas, and complex layouts.
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
							<span v-if="fileType.llphantSupport === 'none'"
								class="support-indicator dolphin-required"
								title="Requires Dolphin AI for OCR text extraction">
								(Dolphin required)
							</span>
							<span v-else-if="fileType.dolphinOcr && fileSettings.textExtractor.id === 'dolphin'"
								class="support-indicator ocr"
								title="Dolphin OCR enabled">
								📷 OCR
							</span>
						</span>
					</NcCheckboxRadioSwitch>
				</div>
			</div>
		</SettingsCard>

		<!-- File Processing Statistics -->
		<div class="stats-section">
			<h3 class="stats-title">📊 File Processing Statistics</h3>
			<div class="stats-grid stats-grid-6">
				<div class="stat-card">
					<div class="stat-value">
						{{ extractionStats.totalFiles || 0 }}
					</div>
					<div class="stat-label">
						Total Files
					</div>
				</div>
				<div class="stat-card">
					<div class="stat-value">
						{{ extractionStats.untrackedFiles || 0 }}
					</div>
					<div class="stat-label">
						Untracked
					</div>
				</div>
				<div class="stat-card">
					<div class="stat-value">
						{{ extractionStats.pendingFiles || 0 }}
					</div>
					<div class="stat-label">
						Pending
					</div>
				</div>
				<div class="stat-card highlight success">
					<div class="stat-value">
						{{ extractionStats.processedFiles || 0 }}
					</div>
					<div class="stat-label">
						Processed
					</div>
				</div>
				<div class="stat-card highlight error">
					<div class="stat-value">
						{{ extractionStats.failedFiles || 0 }}
					</div>
					<div class="stat-label">
						Failed
					</div>
				</div>
				<div class="stat-card highlight">
					<div class="stat-value">
						{{ extractionStats.totalChunks || 0 }}
					</div>
					<div class="stat-label">
						Chunks
					</div>
				</div>
			</div>
		</div>

		<!-- Save Status -->
		<div v-if="saveMessage" class="save-message" :class="saveMessageType">
			{{ saveMessage }}
		</div>
	</SettingsSection>
</template>

<script>
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../../store/settings.js'
import SettingsSection from '../../../components/shared/SettingsSection.vue'
import SettingsCard from '../../../components/shared/SettingsCard.vue'

import {
	NcLoadingIcon,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcButton,
	NcTextField,
	NcActions,
	NcActionButton,
} from '@nextcloud/vue'

import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import InformationIcon from 'vue-material-design-icons/Information.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import AlertCircleIcon from 'vue-material-design-icons/AlertCircle.vue'
import DotsVertical from 'vue-material-design-icons/DotsVertical.vue'

/**
 * File configuration settings component for managing file upload and text extraction.
 * Allows users to control when and how text extraction occurs.
 */
export default {
	name: 'FileConfiguration',

	components: {
		SettingsSection,
		SettingsCard,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcButton,
		NcTextField,
		NcActions,
		NcActionButton,
		FileDocumentIcon,
		RefreshIcon,
		MagnifyIcon,
		InformationIcon,
		KeyIcon,
		CheckIcon,
		AlertCircleIcon,
		DotsVertical,
	},

	data() {
		return {
			fileSettings: {
				extractionScope: { id: 'objects', label: 'Files Attached to Objects' },
				textExtractor: { id: 'llphant', label: 'LLPhant' },
				extractionMode: { id: 'background', label: 'Background Job' },
				includeInSearch: true,
				maxFileSize: 100,
				chunkSize: 1000,
				chunkOverlap: 200,
				chunkingStrategy: 'RECURSIVE_CHARACTER',
				batchSize: 10,
				dolphinApiEndpoint: '',
				dolphinApiKey: '',
			},
			fileTypes: [
				// Text formats (LLPhant supported)
				{ extension: 'txt', label: 'Text Files', icon: '📝', enabled: true, llphantSupport: 'yes', dolphinOcr: false },
				{ extension: 'md', label: 'Markdown', icon: '📋', enabled: true, llphantSupport: 'yes', dolphinOcr: false },
				{ extension: 'html', label: 'HTML Files', icon: '🌐', enabled: true, llphantSupport: 'yes', dolphinOcr: false },
				{ extension: 'json', label: 'JSON Files', icon: '📦', enabled: true, llphantSupport: 'yes', dolphinOcr: false },
				{ extension: 'xml', label: 'XML Files', icon: '📰', enabled: true, llphantSupport: 'yes', dolphinOcr: false },
				{ extension: 'csv', label: 'CSV Files', icon: '📊', enabled: true, llphantSupport: 'yes', dolphinOcr: false },

				// Document formats (LLPhant supported)
				{ extension: 'pdf', label: 'PDF Documents', icon: '📄', enabled: true, llphantSupport: 'yes', dolphinOcr: true },
				{ extension: 'docx', label: 'Word Documents', icon: '📘', enabled: true, llphantSupport: 'yes', dolphinOcr: false },
				{ extension: 'doc', label: 'Word (Legacy)', icon: '📘', enabled: true, llphantSupport: 'yes', dolphinOcr: false },
				{ extension: 'xlsx', label: 'Excel Spreadsheets', icon: '📊', enabled: true, llphantSupport: 'yes', dolphinOcr: false },
				{ extension: 'xls', label: 'Excel (Legacy)', icon: '📊', enabled: true, llphantSupport: 'yes', dolphinOcr: false },
				{ extension: 'pptx', label: 'PowerPoint', icon: '📽️', enabled: false, llphantSupport: 'yes', dolphinOcr: false },

				// Image formats (Dolphin required for OCR)
				{ extension: 'jpg', label: 'JPEG Images', icon: '🖼️', enabled: false, llphantSupport: 'none', dolphinOcr: true },
				{ extension: 'jpeg', label: 'JPEG Images', icon: '🖼️', enabled: false, llphantSupport: 'none', dolphinOcr: true },
				{ extension: 'png', label: 'PNG Images', icon: '🖼️', enabled: false, llphantSupport: 'none', dolphinOcr: true },
				{ extension: 'gif', label: 'GIF Images', icon: '🖼️', enabled: false, llphantSupport: 'none', dolphinOcr: true },
				{ extension: 'webp', label: 'WebP Images', icon: '🖼️', enabled: false, llphantSupport: 'none', dolphinOcr: true },

				// Other formats
				{ extension: 'odt', label: 'OpenDocument Text', icon: '📄', enabled: false, llphantSupport: 'yes', dolphinOcr: false },
				{ extension: 'rtf', label: 'Rich Text Format', icon: '📝', enabled: false, llphantSupport: 'yes', dolphinOcr: false },
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
			extractionStats: {
				totalFiles: 0,
				untrackedFiles: 0,
				pendingFiles: 0,
				processedFiles: 0,
				failedFiles: 0,
				totalChunks: 0,
			},
			discoveringFiles: false,
			extractingFiles: false,
			retryingFiles: false,
			saveMessage: '',
			saveMessageType: 'success',
			dolphinConnectionTested: null, // null, 'success', 'error'
		}
	},

	computed: {
		...mapStores(useSettingsStore),

		/**
		 * Check if any file operation is currently running
		 */
		isProcessing() {
			return this.discoveringFiles || this.extractingFiles || this.retryingFiles
		},
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
					this.fileSettings.includeInSearch = settings.includeInSearch !== undefined ? settings.includeInSearch : true
					this.fileSettings.maxFileSize = settings.maxFileSize || 100
					this.fileSettings.chunkSize = settings.chunkSize || 1000
					this.fileSettings.chunkOverlap = settings.chunkOverlap || 200
					this.fileSettings.chunkingStrategy = settings.chunkingStrategy || 'RECURSIVE_CHARACTER'
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
					includeInSearch: this.fileSettings.includeInSearch,
					maxFileSize: this.fileSettings.maxFileSize,
					chunkSize: this.fileSettings.chunkSize,
					chunkOverlap: this.fileSettings.chunkOverlap,
					chunkingStrategy: this.fileSettings.chunkingStrategy,
					batchSize: this.fileSettings.batchSize,
					dolphinApiEndpoint: this.fileSettings.dolphinApiEndpoint || '',
					dolphinApiKey: this.fileSettings.dolphinApiKey || '',
					enabledFileTypes: this.fileTypes
						.filter(ft => ft.enabled)
						.map(ft => ft.extension),
				})

				// Settings saved silently - no success message
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
				const stats = await this.settingsStore.getExtractionStats()
				if (stats) {
					this.extractionStats = {
						totalFiles: stats.totalFiles || 0,
						untrackedFiles: stats.untrackedFiles || 0,
						pendingFiles: stats.pendingFiles || 0,
						processedFiles: stats.processedFiles || 0,
						failedFiles: stats.failedFiles || 0,
						totalChunks: stats.totalChunks || 0,
					}
				}
			} catch (error) {
				console.error('Failed to load extraction stats:', error)
			}
		},

		/**
		 * Discover files in Nextcloud that aren't tracked yet
		 */
		async discoverFiles() {
			this.discoveringFiles = true
			try {
				const result = await this.settingsStore.discoverFiles()

				// Show detailed feedback about what happened
				const data = result?.data || {}
				const discovered = data.discovered || 0
				const failed = data.failed || 0

				let message = `Discovered ${discovered} new files`
				if (failed > 0) {
					message += `, ${failed} failed to stage`
				}

				this.showSaveMessage(message, failed > 0 ? 'error' : 'success')
				await this.loadExtractionStats()
			} catch (error) {
				console.error('Failed to discover files:', error)
				this.showSaveMessage('Failed to discover files', 'error')
			} finally {
				this.discoveringFiles = false
			}
		},

		/**
		 * Extract pending files (files already staged with status='pending')
		 */
		async extractAllPendingFiles() {
			this.extractingFiles = true
			try {
				const result = await this.settingsStore.triggerFileExtraction('pending')

				// Show detailed feedback about what happened
				const data = result?.data || {}
				const processed = data.processed || 0
				const failed = data.failed || 0

				let message = `Processed ${processed} pending files`
				if (failed > 0) {
					message += `, ${failed} failed`
				}

				this.showSaveMessage(message, failed > 0 ? 'error' : 'success')
				await this.loadExtractionStats()
			} catch (error) {
				console.error('Failed to process files:', error)
				this.showSaveMessage('Failed to extract files', 'error')
			} finally {
				this.extractingFiles = false
			}
		},

		/**
		 * Retry failed file extractions
		 */
		async reprocessFailedFiles() {
			this.retryingFiles = true
			try {
				const result = await this.settingsStore.triggerFileExtraction('failed')

				// Show detailed feedback about what happened
				const data = result?.data || {}
				const retried = data.retried || 0
				const failed = data.failed || 0

				let message = `Retried ${retried} failed extractions`
				if (failed > 0) {
					message += `, ${failed} failed again`
				}

				this.showSaveMessage(message, failed > 0 ? 'error' : 'success')
				await this.loadExtractionStats()
			} catch (error) {
				console.error('Failed to reprocess files:', error)
				this.showSaveMessage('Failed to retry extractions', 'error')
			} finally {
				this.retryingFiles = false
			}
		},

		/**
		 * Show save message
		 * @param {string} message - The message to show
		 * @param {string} type - The type of message to show
		 */
		showSaveMessage(message, type = 'success') {
			this.saveMessage = message
			this.saveMessageType = type
			setTimeout(() => {
				this.saveMessage = ''
			}, 3000)
		},

		/**
		 * Format number with thousands separator
		 * @param {number} num - The number to format
		 */
		formatNumber(num) {
			return new Intl.NumberFormat().format(num || 0)
		},
	},
}
</script>

<style scoped>
/* SettingsSection handles all action button positioning and spacing */

/* Fix for action menu icon wrapper to prevent layout shifts when showing loading icons */
.action-icon-wrapper {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 20px;
	height: 20px;
}

.section-description-full {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	margin-bottom: 20px;
}

.main-description {
	color: var(--color-text-light);
	font-size: 14px;
	line-height: 1.6;
	margin: 0 0 16px 0;
}

.main-description.info-note {
	background: var(--color-background-dark);
	border-left: 4px solid var(--color-primary-element);
	padding: 12px 16px;
	border-radius: var(--border-radius);
	margin-top: 16px;
}

.main-description.info-note strong {
	color: var(--color-primary-element);
}

/* Compact Layout */
.settings-group.compact {
	gap: 12px;
}

.settings-group.compact .setting-item {
	gap: 6px;
}

.settings-group.compact .setting-description {
	font-size: 12px;
	margin-top: 4px;
}

.processing-limits-section {
	margin-top: 24px;
	padding-top: 24px;
	border-top: 1px solid var(--color-border);
}

.processing-limits-section h5 {
	color: var(--color-text-light);
	margin: 0 0 16px 0;
	font-size: 15px;
	font-weight: 500;
}

.processing-limits-section .settings-group {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 16px;
}

@media (max-width: 768px) {
	.processing-limits-section .settings-group {
		grid-template-columns: 1fr;
	}
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

.setting-item input[type="number"],
.setting-item select {
	max-width: 200px;
	padding: 8px 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-text-light);
}

.setting-item select {
	min-width: 200px;
	cursor: pointer;
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
	font-size: 11px;
	cursor: help;
}

.support-indicator.dolphin-required {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.support-indicator.ocr {
	color: var(--color-primary);
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
	.section-header-inline {
		position: static;
		margin-bottom: 1rem;
		flex-direction: column;
		align-items: stretch;
	}

	.button-group {
		justify-content: center;
	}

	.file-types-grid {
		grid-template-columns: 1fr;
	}

	.stats-grid {
		grid-template-columns: repeat(2, 1fr);
	}
}

/* Statistics Section */
.stats-section {
	margin-bottom: 20px;
}

.stats-title {
	margin: 0 0 16px 0;
	color: var(--color-main-text);
	font-size: 16px;
	font-weight: 600;
}

/* Vectorization Statistics */
.stats-grid {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 16px;
}

.stats-grid-5 {
	grid-template-columns: repeat(5, 1fr);
}

.stats-grid-6 {
	grid-template-columns: repeat(6, 1fr);
}

.stat-card {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	text-align: center;
	transition: all 0.2s ease;
}

.stat-card:hover {
	border-color: var(--color-primary-element);
	transform: translateY(-2px);
	box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.stat-card.highlight {
	background: var(--color-primary-light);
	border: 2px solid var(--color-primary-element);
	box-shadow: 0 2px 8px rgba(0, 123, 255, 0.15);
}

.stat-card.success {
	background: var(--color-success-light);
	border-color: var(--color-success);
}

.stat-card.error {
	background: var(--color-error-light);
	border-color: var(--color-error);
}

.stat-card.info {
	background: var(--color-primary-element-light);
	border-color: var(--color-primary-element);
}

/* Highlight with success keeps green background but adds prominent border */
.stat-card.highlight.success {
	background: var(--color-success-light);
	border: 2px solid var(--color-primary-element);
	box-shadow: 0 2px 8px rgba(0, 123, 255, 0.15);
}

/* Highlight with error keeps red background but adds prominent border */
.stat-card.highlight.error {
	background: var(--color-error-light);
	border: 2px solid var(--color-primary-element);
	box-shadow: 0 2px 8px rgba(0, 123, 255, 0.15);
}

.stat-value {
	font-size: 32px;
	font-weight: bold;
	color: var(--color-primary-element);
	margin-bottom: 8px;
}

.stat-card.highlight .stat-value {
	color: var(--color-primary-element);
}

.stat-card.success .stat-value {
	color: var(--color-success);
}

.stat-card.error .stat-value {
	color: var(--color-error);
}

.stat-card.info .stat-value {
	color: var(--color-primary-element);
}

.stat-label {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}
</style>
