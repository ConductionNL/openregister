<template>
	<SettingsSection
		name="LLM Configuration"
		description="Configure Large Language Model settings for AI features"
		:loading="loadingStats"
		loading-message="Loading LLM configuration...">
		<template #actions>
			<!-- LLM Actions Menu -->
			<NcActions
				:aria-label="t('openregister', 'LLM actions menu')"
				:menu-name="t('openregister', 'Actions')">
				<template #icon>
					<DotsVertical :size="20" />
				</template>

				<!-- LLM Configuration -->
				<NcActionButton @click="showLLMConfigDialog = true">
					<template #icon>
						<Robot :size="20" />
					</template>
					{{ t('openregister', 'LLM Configuration') }}
				</NcActionButton>

				<!-- File Management -->
				<NcActionButton @click="showFileManagementDialog = true">
					<template #icon>
						<FileDocument :size="20" />
					</template>
					{{ t('openregister', 'File Management') }}
				</NcActionButton>

				<!-- Object Management -->
				<NcActionButton @click="showObjectManagementDialog = true">
					<template #icon>
						<CubeOutline :size="20" />
					</template>
					{{ t('openregister', 'Object Management') }}
				</NcActionButton>

				<!-- Separator -->
				<NcActionSeparator />

				<!-- Vectorize All Objects -->
				<NcActionButton
					:disabled="!llmSettings.enabled || vectorizing"
					@click="showVectorizeObjectsDialog">
					<template #icon>
						<VectorSquare :size="20" />
					</template>
					{{ t('openregister', 'Vectorize All Objects') }}
				</NcActionButton>

				<!-- Vectorize All Files -->
				<NcActionButton
					:disabled="!llmSettings.enabled || vectorizing"
					@click="showVectorizeFilesDialog">
					<template #icon>
						<FileVectorOutline :size="20" />
					</template>
					{{ t('openregister', 'Vectorize All Files') }}
				</NcActionButton>
			</NcActions>
		</template>

		<!-- Section Description -->
		<div class="section-description-full">
			<p class="main-description">
				Large Language Models (LLMs) power AI features including semantic search, text generation, document summarization,
				and content classification. When enabled, OpenRegister will automatically vectorize objects and files for semantic
				search capabilities and provide AI-powered content understanding. Choose from providers like OpenAI, Fireworks AI,
				Ollama (local), or Azure OpenAI.
			</p>
			<p class="main-description info-note">
				<strong>📝 Note:</strong> File vectorization requires text extraction to be enabled. Large files are split into smaller
				<strong>chunks</strong> (text portions), which are then converted into <strong>embeddings</strong> (vector representations)
				for semantic search. Without text extraction, files cannot be processed into chunks and embeddings.
			</p>
			<p class="toggle-status">
				<strong>Current Status:</strong>
				<span :class="llmSettings.enabled ? 'status-enabled' : 'status-disabled'">
					{{ llmSettings.enabled ? 'LLM features enabled' : 'LLM features disabled' }}
				</span>
			</p>
		</div>

		<!-- Enable LLM Toggle -->
		<div class="option-section">
			<NcCheckboxRadioSwitch
				v-model="llmSettings.enabled"
				:disabled="saving"
				type="switch"
				@update:checked="onLlmEnabledChange">
				{{ llmSettings.enabled ? t('openregister', 'LLM features enabled') : t('openregister', 'LLM features disabled') }}
			</NcCheckboxRadioSwitch>
			<p class="option-description">
				{{ t('openregister', 'Enable or disable LLM features. Configure providers and models using the LLM Configuration button above.') }}
				<span v-if="saving" class="saving-indicator">
					<NcLoadingIcon :size="14" /> {{ t('openregister', 'Saving...') }}
				</span>
			</p>
		</div>

		<!-- Provider Configuration Info -->
		<div class="provider-info-grid">
			<div class="provider-info-card">
				<h5>Embedding Provider</h5>
				<p v-if="providerConfig.embeddingProvider" class="provider-name">
					{{ getProviderDisplayName(providerConfig.embeddingProvider) }}
				</p>
				<p v-else class="not-configured">
					Not configured
				</p>
				<p v-if="providerConfig.embeddingModel" class="model-info">
					{{ providerConfig.embeddingModel }}
				</p>
			</div>

			<div class="provider-info-card">
				<h5>Chat Provider (RAG)</h5>
				<p v-if="providerConfig.chatProvider" class="provider-name">
					{{ getProviderDisplayName(providerConfig.chatProvider) }}
				</p>
				<p v-else class="not-configured">
					Not configured
				</p>
				<p v-if="providerConfig.chatModel" class="model-info">
					{{ providerConfig.chatModel }}
				</p>
			</div>
		</div>

		<!-- Chat Statistics -->
		<div class="stats-grid">
			<div class="stat-tile">
				<div class="stat-value">
					{{ formatNumber(chatStats.totalAgents) }}
				</div>
				<div class="stat-label">
					Agents
				</div>
			</div>
			<div class="stat-tile">
				<div class="stat-value">
					{{ formatNumber(chatStats.totalConversations) }}
				</div>
				<div class="stat-label">
					Conversations
				</div>
			</div>
			<div class="stat-tile">
				<div class="stat-value">
					{{ formatNumber(chatStats.totalMessages) }}
				</div>
				<div class="stat-label">
					Messages
				</div>
			</div>
			<div class="stat-tile">
				<div class="stat-value">
					{{ formatNumber(vectorStats.totalVectors) }}
				</div>
				<div class="stat-label">
					Total Vectors
				</div>
			</div>
			<div class="stat-tile">
				<div class="stat-value">
					{{ formatNumber(vectorStats.objectVectors) }}
				</div>
				<div class="stat-label">
					Object Embeddings
				</div>
			</div>
			<div class="stat-tile">
				<div class="stat-value">
					{{ formatNumber(vectorStats.fileVectors) }}
				</div>
				<div class="stat-label">
					File Embeddings
				</div>
			</div>
		</div>

		<!-- LLM Dashboard (when enabled) -->
		<div v-if="llmSettings.enabled" class="llm-management-section">
			<!-- Loading State -->
			<div v-if="loadingStats" class="loading-section">
				<NcLoadingIcon :size="32" />
				<p>Loading LLM statistics...</p>
			</div>

			<!-- Error State -->
			<div v-else-if="llmError" class="error-section">
				<p class="error-message">
					❌ {{ llmErrorMessage }}
				</p>
				<NcButton type="primary" @click="retryConnection">
					<template #icon>
						<Refresh :size="20" />
					</template>
					Retry Connection
				</NcButton>
			</div>

			<!-- Connection Success -->
			<div v-else class="dashboard-section">
				<div class="connection-success">
					<span class="success-icon">✅</span>
					<span>{{ llmConnectionStatus }}</span>
					<NcButton type="secondary" @click="retryConnection">
						<template #icon>
							<Refresh :size="16" />
						</template>
						Test Connection
					</NcButton>
				</div>
			</div>
		</div>

		<!-- LLM Configuration Modal -->
		<LLMConfigModal
			:show="showLLMConfigDialog"
			@closing="showLLMConfigDialog = false" />

		<!-- File Management Modal -->
		<FileManagementModal
			:show="showFileManagementDialog"
			@closing="showFileManagementDialog = false" />

		<!-- Object Management Modal -->
		<ObjectManagementModal
			:show="showObjectManagementDialog"
			@closing="showObjectManagementDialog = false" />

		<!-- Object Vectorization Modal -->
		<ObjectVectorizationModal
			:show="showObjectVectorizationModal"
			@closing="showObjectVectorizationModal = false"
			@completed="loadAllStats" />

		<!-- File Vectorization Modal -->
		<FileVectorizationModal
			:show="showFileVectorizationModal"
			:extraction-stats="settingsStore.extractionStats"
			:vector-stats="settingsStore.vectorStats"
			@closing="showFileVectorizationModal = false"
			@completed="loadAllStats" />
	</SettingsSection>
</template>

<script>
import { mapStores } from 'pinia'
import { useSettingsStore } from '../../../store/settings.js'
import SettingsSection from '../../../components/shared/SettingsSection.vue'

import {
	NcLoadingIcon,
	NcCheckboxRadioSwitch,
	NcActions,
	NcActionButton,
	NcActionSeparator,
	NcButton,
} from '@nextcloud/vue'

import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

import DotsVertical from 'vue-material-design-icons/DotsVertical.vue'
import Robot from 'vue-material-design-icons/Robot.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import VectorSquare from 'vue-material-design-icons/VectorSquare.vue'
import FileVectorOutline from 'vue-material-design-icons/FileDocumentCheckOutline.vue'

import LLMConfigModal from '../../../modals/settings/LLMConfigModal.vue'
import FileManagementModal from '../../../modals/settings/FileManagementModal.vue'
import ObjectManagementModal from '../../../modals/settings/ObjectManagementModal.vue'
import ObjectVectorizationModal from '../../../modals/settings/ObjectVectorizationModal.vue'
import FileVectorizationModal from '../../../modals/settings/FileVectorizationModal.vue'

/**
 * LLM configuration settings component for managing AI/LLM integration.
 * Allows users to configure LLM providers, models, and AI features.
 */
export default {
	name: 'LlmConfiguration',

	components: {
		SettingsSection,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcActions,
		NcActionButton,
		NcActionSeparator,
		NcButton,
		DotsVertical,
		Robot,
		FileDocument,
		CubeOutline,
		Refresh,
		VectorSquare,
		FileVectorOutline,
		LLMConfigModal,
		FileManagementModal,
		ObjectManagementModal,
		ObjectVectorizationModal,
		FileVectorizationModal,
	},

	data() {
		return {
			llmSettings: {
				enabled: false,
				provider: null,
				apiEndpoint: '',
				apiKey: '',
				model: null,
				temperature: 0.7,
				maxTokens: 2000,
			},
			saving: false,
			loadingStats: false,
			vectorizing: false,
			llmError: false,
			llmErrorMessage: '',
			llmConnectionStatus: 'Unknown',
			showLLMConfigDialog: false,
			showFileManagementDialog: false,
			showObjectManagementDialog: false,
			showObjectVectorizationModal: false,
			showFileVectorizationModal: false,
			providerConfig: {
				embeddingProvider: null,
				embeddingModel: null,
				chatProvider: null,
				chatModel: null,
			},
			chatStats: {
				totalAgents: 0,
				totalConversations: 0,
				totalMessages: 0,
			},
			vectorStats: {
				totalVectors: 0,
				objectVectors: 0,
				fileVectors: 0,
				storageMB: '0.0',
			},
			fileStats: {
				totalFiles: 0,
				vectorizedFiles: 0,
				filesExtracted: 0,
				pendingFiles: 0,
				totalChunks: 0,
			},
			objectStats: {
				totalObjects: 0,
				vectorizedObjects: 0,
				pendingObjects: 0,
				progressPercentage: 0,
			},
		}
	},

	computed: {
		...mapStores(useSettingsStore),

		/**
		 * Get connection status CSS class
		 */
		connectionStatusClass() {
			if (this.llmConnectionStatus === 'Connected' || this.llmConnectionStatus.includes('✓')) {
				return 'status-connected'
			}
			if (this.llmConnectionStatus === 'Disconnected' || this.llmConnectionStatus.includes('✗')) {
				return 'status-disconnected'
			}
			return 'status-unknown'
		},
	},

	async mounted() {
		await this.loadSettings()
		await this.loadAllStats()
	},

	methods: {
		/**
		 * Load LLM configuration settings
		 */
		async loadSettings() {
			try {
				const settings = await this.settingsStore.getLlmSettings()
				if (settings) {
					this.llmSettings = { ...this.llmSettings, ...settings }

					// Extract provider configuration
					this.providerConfig.embeddingProvider = settings.embeddingProvider || null
					this.providerConfig.chatProvider = settings.chatProvider || null

					// Extract model names based on provider
					if (settings.embeddingProvider === 'openai') {
						this.providerConfig.embeddingModel = settings.openaiConfig?.model || null
					} else if (settings.embeddingProvider === 'fireworks') {
						this.providerConfig.embeddingModel = settings.fireworksConfig?.embeddingModel || null
					} else if (settings.embeddingProvider === 'ollama') {
						this.providerConfig.embeddingModel = settings.ollamaConfig?.model || null
					}

					if (settings.chatProvider === 'openai') {
						this.providerConfig.chatModel = settings.openaiConfig?.chatModel || null
					} else if (settings.chatProvider === 'fireworks') {
						this.providerConfig.chatModel = settings.fireworksConfig?.chatModel || null
					} else if (settings.chatProvider === 'ollama') {
						this.providerConfig.chatModel = settings.ollamaConfig?.chatModel || null
					}
				}
			} catch (error) {
				console.error('Failed to load LLM settings:', error)
			}
		},

		/**
		 * Get display name for provider.
		 * @param {string} providerId - The ID of the provider.
		 * @return {string} The display name for the provider.
		 */
		getProviderDisplayName(providerId) {
			const providerNames = {
				openai: 'OpenAI',
				fireworks: 'Fireworks AI',
				ollama: 'Ollama',
			}
			return providerNames[providerId] || providerId
		},

		/**
		 * Load all statistics (chat, vector, etc.)
		 */
		async loadAllStats() {
			await Promise.all([
				this.loadChatStats(),
				this.loadVectorStats(),
				this.settingsStore.getExtractionStats(),
			])
		},

		/**
		 * Load chat and agent statistics
		 */
		async loadChatStats() {
			try {
				// TODO: Implement API endpoint to get chat stats
				// For now, use placeholder or existing API
				const response = await this.settingsStore.getChatStats()
				if (response) {
					this.chatStats.totalAgents = response.total_agents || 0
					this.chatStats.totalConversations = response.total_conversations || 0
					this.chatStats.totalMessages = response.total_messages || 0
				}
			} catch (error) {
				console.error('Failed to load chat stats:', error)
				// Don't throw error, just use zeros
			}
		},

		/**
		 * Retry connection - tests LLM connectivity
		 */
		async retryConnection() {
			this.loadingStats = true
			this.llmError = false

			try {
				// Reload all statistics and test connection
				await this.loadAllStats()
				this.llmConnectionStatus = 'Connected ✓'
			} catch (error) {
				this.llmError = true
				this.llmErrorMessage = error.message || 'Failed to connect to LLM service'
				this.llmConnectionStatus = 'Disconnected ✗'
			} finally {
				this.loadingStats = false
			}
		},

		/**
		 * Save LLM configuration settings
		 */
		async saveSettings() {
			this.saving = true
			try {
				await this.settingsStore.saveLlmSettings(this.llmSettings)
			} catch (error) {
				console.error('Failed to save LLM settings:', error)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Handle LLM enabled toggle change
		 */
		async onLlmEnabledChange() {
			// Only send the enabled field via PATCH
			await this.settingsStore.patchLlmSettings({ enabled: this.llmSettings.enabled })
			if (this.llmSettings.enabled) {
				await this.loadVectorStats()
			}
		},

		/**
		 * Load vector statistics
		 */
		async loadVectorStats() {
			if (!this.llmSettings.enabled) return

			this.loadingStats = true
			this.llmError = false
			this.llmErrorMessage = ''

			try {
				const response = await this.settingsStore.getVectorStats()
				if (response) {
					// Extract stats from response (data is in 'stats' object)
					const stats = response.stats || response

					// Update connection status
					this.llmConnectionStatus = response.success ? 'Connected' : 'Disconnected'

					// Update vector stats
					this.vectorStats.totalVectors = stats.total_vectors || 0
					this.vectorStats.objectVectors = stats.by_type?.object || stats.object_vectors || 0
					this.vectorStats.fileVectors = stats.by_type?.file || stats.file_vectors || 0
					this.vectorStats.storageMB = stats.storage?.total_mb?.toFixed(1) || '0.0'

					// Update file stats
					if (response.files) {
						this.fileStats.totalFiles = response.files.total_files || 0
						this.fileStats.filesExtracted = response.files.files_extracted || 0
						this.fileStats.vectorizedFiles = response.files.vectorized_files || 0
						this.fileStats.pendingFiles = response.files.pending_files || 0
						this.fileStats.totalChunks = response.files.total_chunks || 0
					}

					// Update object stats
					if (response.objects) {
						this.objectStats.totalObjects = response.objects.total_objects || 0
						this.objectStats.vectorizedObjects = response.objects.vectorized_objects || 0
						this.objectStats.pendingObjects = response.objects.pending_objects || 0
						this.objectStats.progressPercentage = response.objects.percentage_complete || 0
					}
				}
			} catch (error) {
				console.error('Failed to load vector stats:', error)
				this.llmError = true
				this.llmErrorMessage = error.message || 'Failed to connect to LLM service'
				this.llmConnectionStatus = 'Disconnected'
			} finally {
				this.loadingStats = false
			}
		},

		/**
		 * Format number with thousands separator
		 * @param {number} num - The number to format
		 */
		formatNumber(num) {
			return new Intl.NumberFormat().format(num)
		},

		/**
		 * Show object vectorization modal
		 */
		showVectorizeObjectsDialog() {
			this.showObjectVectorizationModal = true
		},

		/**
		 * Show dialog to vectorize all files
		 */
		showVectorizeFilesDialog() {
			this.showFileVectorizationModal = true
		},

		/**
		 * Vectorize all files
		 */
		async vectorizeAllFiles() {
			this.vectorizing = true

			try {
				// Start background job
				await axios.post(generateUrl('/apps/openregister/api/files/vectorize/batch'), {
					batchSize: 25,
				})

				showSuccess(this.t('openregister', 'File vectorization started. Check the statistics section for progress.'))
				await this.loadAllStats()
			} catch (error) {
				console.error('Failed to start file vectorization:', error)
				showError(this.t('openregister', 'Failed to start vectorization: {error}', {
					error: error.response?.data?.error || error.message,
				}))
			} finally {
				this.vectorizing = false
			}
		},
	},
}
</script>

<style scoped>
/* SettingsSection handles all action button positioning and spacing */

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

.toggle-status {
	margin: 0;
	font-size: 14px;
	line-height: 1.6;
}

.toggle-status strong {
	color: var(--color-text-light);
}

.status-enabled {
	color: var(--color-success);
	font-weight: 600;
}

.status-disabled {
	color: var(--color-text-maxcontrast);
}

.option-section {
	margin-bottom: 24px;
}

.option-description {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 8px 0 0 0;
	line-height: 1.4;
}

.saving-indicator {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	margin-left: 8px;
	color: var(--color-primary-element);
}

.llm-management-section {
	margin-top: 32px;
}

.loading-section {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px;
	gap: 16px;
}

.loading-section p {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.dashboard-section {
	margin-top: 20px;
}

.section-title {
	color: var(--color-text-light);
	font-size: 16px;
	font-weight: 600;
	margin: 24px 0 16px 0;
}

.section-title:first-child {
	margin-top: 0;
}

.dashboard-stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 16px;
	margin-bottom: 24px;
}

.stat-card {
	background: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	text-align: center;
	transition: all 0.2s ease;
}

.stat-card:hover {
	border-color: var(--color-primary-element);
	transform: translateY(-2px);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.stat-card h5 {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	font-weight: 500;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	margin: 0 0 12px 0;
}

.stat-card p {
	color: var(--color-primary-element);
	font-size: 32px;
	font-weight: 700;
	margin: 0;
	font-variant-numeric: tabular-nums;
}

.stat-card p.status-connected {
	color: var(--color-success);
}

.stat-card p.status-disconnected {
	color: var(--color-error);
}

.stat-card p.status-unknown {
	color: var(--color-text-maxcontrast);
}

.error-section {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px;
	gap: 16px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.error-message {
	color: var(--color-error);
	font-size: 16px;
	font-weight: 500;
	margin: 0;
}

/* Collapsible Sections (matching File Configuration) */
.collapsible-section {
	margin-bottom: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.collapsible-section summary {
	cursor: pointer;
	padding: 16px;
	font-size: 14px;
	font-weight: 600;
	display: flex;
	align-items: center;
	gap: 8px;
	user-select: none;
	transition: background-color 0.2s ease;
}

.collapsible-section summary:hover {
	background: var(--color-background-hover);
}

.collapsible-section summary .icon {
	font-size: 18px;
}

.collapsible-section[open] summary {
	border-bottom: 1px solid var(--color-border);
}

.section-content {
	padding: 20px;
}

/* Provider Info Cards */
.provider-info-grid {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 16px;
	margin-bottom: 24px;
}

.provider-info-card {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 16px;
}

.provider-info-card h5 {
	margin: 0 0 8px 0;
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
}

.provider-info-card .provider-name {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
}

.provider-info-card .not-configured {
	margin: 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.provider-info-card .model-info {
	margin: 8px 0 0 0;
	font-size: 12px;
	color: var(--color-text-lighter);
	font-family: monospace;
}

/* Statistics Grid (matching File Configuration tiles) */
.stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 16px;
	margin-bottom: 24px;
}

.stat-tile {
	background: var(--color-background-hover);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	text-align: center;
	transition: all 0.2s ease;
}

.stat-tile:hover {
	border-color: var(--color-primary-element);
	transform: translateY(-2px);
	box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.stat-value {
	font-size: 32px;
	font-weight: 700;
	color: var(--color-primary-element);
	line-height: 1;
	margin-bottom: 8px;
}

.stat-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

/* Connection Success */
.connection-success {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 16px;
	background: var(--color-success-background, #d4edda);
	border: 1px solid var(--color-success, #28a745);
	border-radius: var(--border-radius-large);
	color: var(--color-success-text, #155724);
	font-weight: 500;
}

.success-icon {
	font-size: 20px;
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

	.dashboard-stats-grid {
		grid-template-columns: repeat(2, 1fr);
	}

	.provider-info-grid {
		grid-template-columns: 1fr;
	}

	.stats-grid {
		grid-template-columns: repeat(2, 1fr);
	}
}
</style>
